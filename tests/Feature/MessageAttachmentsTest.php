<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Location;
use App\Models\User;
use App\Support\MessageAttachments;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessageAttachmentsTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => DatabaseSeeder::class])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            DB::table('message_attachments')->delete();
            DB::table('business_inquiry_settings')->delete();
            Conversation::whereNull('listing_id')->delete();
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_private_photos_and_owner_inquiry_preferences(): void
    {
        Storage::fake(MessageAttachments::DISK);
        config(['network.extended_messaging' => true, 'network.business_inquiry_controls' => true]);
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $stranger = User::factory()->create(['role' => 'network_admin']);
        $business = Business::create(['slug' => 'photo-inquiry', 'name' => 'Photo Inquiry', 'category' => 'services', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '5 Photo Road']);
        $base = 'https://brandonmb.ca';
        $start = $base.'/businesses/'.$business->id.'/message';
        $settings = $base.'/account/businesses/'.$business->id.'/inquiry-settings';
        $preferences = ['business_inquiries' => '1', 'job_inquiries' => '0'];
        $this->actingAs($member)->post($start, ['body' => 'Question'])->assertNotFound();
        $this->post($settings, $preferences)->assertNotFound();
        $this->actingAs($owner)->post($settings, $preferences)->assertRedirect();
        $export = $base.'/account/data-export';
        $input = ['current_password' => 'password', 'section' => 'business-inquiry-preferences'];
        $this->get($export)->assertOk()->assertSee('Business inquiry preferences (1)');
        $this->post($export, $input)->assertOk()->assertJsonCount(1, 'records')->assertJsonPath('records.0.export_id', $business->id)
            ->assertJsonMissingPath('records.0.owner_id');
        $this->actingAs($member)->post($export, $input)->assertOk()->assertJsonCount(0, 'records');
        $this->actingAs($member)->post($start, ['body' => 'Question'])->assertRedirect();
        $thread = Conversation::sole();
        $url = $base.'/account/messages/'.$thread->id;
        $this->post($url, ['body' => 'Photo', 'photo' => UploadedFile::fake()->image('photo.png')])->assertSessionHasErrors('photo');
        config(['network.message_attachments' => true]);
        $this->post($url, ['body' => 'Invalid', 'photo' => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
        $this->post($url, ['body' => 'Photo', 'photo' => UploadedFile::fake()->image('photo.png', 640, 480)])->assertRedirect()->assertSessionHasNoErrors();
        $attachment = DB::table('message_attachments')->firstOrFail();
        Storage::disk(MessageAttachments::DISK)->assertExists($attachment->image_path);
        $download = $url.'/attachments/'.$attachment->message_id;
        $this->get($download)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $photoExport = ['current_password' => 'password', 'section' => 'sent-photo-metadata'];
        $this->post($export, $photoExport)->assertOk()->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.message_id', $attachment->message_id)->assertJsonMissingPath('records.0.image_path')->assertJsonMissingPath('records.0.thumbnail_path');
        $this->actingAs($stranger)->get($download)->assertNotFound();
        $photoExport['section'] = 'received-photo-metadata';
        $this->post($export, $photoExport)->assertOk()->assertJsonCount(0, 'records');
        $this->actingAs($owner)->post($export, $photoExport)->assertOk()->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.message_id', $attachment->message_id)->assertJsonMissingPath('records.0.image_path')->assertJsonMissingPath('records.0.thumbnail_path');
        $this->get($url)->assertSee('Download photo attachment');
        $this->get($download)->assertOk();
        config(['network.content_reports' => true]);
        $reportUrl = $base.'/account/report/message-photo/'.$attachment->message_id;
        $this->post($reportUrl, ['reason' => 'privacy', 'explanation' => 'This photo contains private information.'])->assertRedirect()->assertSessionHasNoErrors();
        $report = DB::table('content_reports')->value('id');
        $review = 'https://canadamb.ca/admin/content-reports/'.$report;
        $this->actingAs($stranger)->get($review.'/photo')->assertMethodNotAllowed();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'content.report-photo-accessed']);
        $disk = Storage::disk(MessageAttachments::DISK);
        $directory = $disk->path('attachments');
        $moved = $disk->path('attachments-original');
        rename($directory, $moved);
        symlink($moved, $directory);
        try {
            $this->post($review.'/photo')->assertNotFound();
            $this->assertDatabaseMissing('audit_logs', ['action' => 'content.report-photo-accessed']);
            $this->actingAs($owner)->get($download)->assertNotFound();
            $this->actingAs($member)->post($url, ['body' => 'Unsafe storage', 'photo' => UploadedFile::fake()->image('photo.png')])->assertSessionHasErrors('photo');
        } finally {
            unlink($directory);
            rename($moved, $directory);
        }
        $this->actingAs($stranger)->post($review.'/photo')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->actingAs($owner)->post($review.'/photo')->assertForbidden();
        $this->actingAs($stranger);
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.report-photo-accessed', 'actor_id' => $stranger->id]);
        $this->post($review, ['note' => 'Photo review completed independently.'])->assertRedirect();
        $this->post($review.'/photo')->assertNotFound();
        $this->actingAs($owner);
        $this->post($url.'/sync', ['after' => 0])->assertJsonPath('messages.1.has_attachment', true);
        $this->post($url.'/block')->assertRedirect();
        $this->actingAs($member)->post($url, ['body' => 'Blocked', 'photo' => UploadedFile::fake()->image('blocked.png')])->assertForbidden();
        $this->assertDatabaseCount('message_attachments', 1);
        $business->members()->detach($owner->id);
        $business->members()->attach($stranger->id, ['role' => 'owner']);
        $this->post($start, ['body' => 'New owner question'])->assertNotFound();
        $this->actingAs($stranger)->get($download)->assertNotFound();
    }
}

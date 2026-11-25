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
        $this->actingAs($stranger)->get($download)->assertNotFound();
        $this->actingAs($owner)->get($url)->assertSee('Download photo attachment');
        $this->get($download)->assertOk();
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

<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

class ExtendedMessagingTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => DatabaseSeeder::class])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            Conversation::whereNull('listing_id')->delete();
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_inquiries_archive_privately_and_preserve_participant_boundaries(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $other = User::factory()->create();
        $business = Business::create(['slug' => 'inquiry-test', 'name' => 'Inquiry Test', 'category' => 'services', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $branch = $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Inquiry Road']);
        $job = JobPosting::create(['business_location_id' => $branch->id, 'title' => 'Local helper', 'description' => 'A local employment opportunity.', 'employment_type' => 'full-time', 'workplace' => 'on-site', 'apply_url' => 'https://example.test/apply', 'closing_on' => today()->addWeek()->toDateString()]);
        $job->forceFill(['status' => 'approved', 'published_at' => now()])->save();
        $base = 'https://brandonmb.ca';
        $this->actingAs($member)->post($base.'/businesses/'.$business->id.'/message', ['body' => 'Business question'])->assertNotFound();
        config(['network.extended_messaging' => true]);
        $this->post($base.'/businesses/'.$business->id.'/message', ['body' => 'Business question'])->assertRedirect();
        $this->post($base.'/jobs/'.$job->id.'/message', ['body' => 'Job question'])->assertRedirect();
        $this->assertDatabaseCount('conversations', 2);
        $this->get($base.'/account/messages?kind=job')->assertSee('Local helper')->assertDontSee('Business question');
        $this->get($base.'/account/messages?kind=business')->assertSee('Business question')->assertDontSee('Local helper');
        $this->get($base.'/account/messages?kind=marketplace')->assertSee('No conversations match your search.');
        $this->getJson($base.'/account/messages?kind=invalid')->assertUnprocessable();
        $thread = Conversation::whereNotNull('inquiry_job_id')->sole();
        $url = $base.'/account/messages/'.$thread->id;
        $this->get($url)->assertOk()->assertSee('Job question');
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->post($url.'/archive')->assertNotFound();
        $thread->forceFill(['updated_at' => now()->subDay()])->save();
        $activity = $thread->fresh()->updated_at->toDateTimeString();
        $this->actingAs($owner)->post($url.'/archive')->assertRedirect();
        $this->assertSame($activity, $thread->fresh()->updated_at->toDateTimeString());
        $this->get($base.'/account/messages')->assertDontSee('Local helper');
        $this->get($base.'/account/messages?view=archived')->assertSee('Local helper');
        $this->actingAs($member)->get($base.'/account/messages')->assertSee('Local helper');
        $this->post($url, ['body' => 'Another question'])->assertRedirect();
        $this->actingAs($owner)->get($base.'/account/messages')->assertSee('Local helper');
        $this->post($url.'/block')->assertRedirect();
        $this->actingAs($member)->post($url, ['body' => 'Blocked question'])->assertForbidden();
        $this->actingAs($owner)->delete($url.'/block')->assertRedirect();
        $job->forceFill(['status' => 'withdrawn'])->save();
        $this->actingAs($member)->post($url, ['body' => 'Withdrawn job question'])->assertForbidden();
        $job->forceFill(['status' => 'expired'])->save();
        $this->post($url, ['body' => 'Existing question after expiry'])->assertRedirect();
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->post($url, ['body' => 'Unverified owner'])->assertForbidden();
        $owner->forceFill(['email_verified_at' => now()])->save();
        $business->members()->attach($other->id, ['role' => 'owner']);
        $this->post($url, ['body' => 'Ambiguous ownership'])->assertForbidden();
        $business->members()->detach($other->id);
        $business->members()->detach($owner->id);
        $business->members()->attach($other->id, ['role' => 'owner']);
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->actingAs($member)->post($url, ['body' => 'Old owner question'])->assertForbidden();
        $this->get($url)->assertSee('Job question');
    }
}

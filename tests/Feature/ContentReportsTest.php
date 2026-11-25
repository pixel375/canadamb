<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentReportsTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => DatabaseSeeder::class])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_event_links_respect_independent_approval_and_cancellation(): void
    {
        config(['network.event_discussions' => true]);
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $this->actingAs($owner)->post('https://brandonmb.ca/events', [
            'title' => 'Linked local gathering', 'body' => 'A local gathering for our neighbours.',
            'category' => 'community', 'venue' => 'Community hall', 'organizer' => 'Local club',
            'starts_at' => now('America/Winnipeg')->addDays(3)->format('Y-m-d\TH:i'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $event = CommunityEvent::sole();
        $thread = CommunityThread::sole();
        $this->assertDatabaseHas('community_event_discussions', ['event_id' => $event->id, 'thread_id' => $thread->id]);
        $eventUrl = 'https://brandonmb.ca/events/'.$event->id;
        $threadUrl = 'https://brandonmb.ca/community/'.$thread->id;
        $this->get($eventUrl)->assertNotFound();
        $this->get($threadUrl)->assertNotFound();
        $this->actingAs($admin)->post('https://canadamb.ca/admin/community/events/'.$event->id, ['action' => 'approve'])->assertRedirect();
        $this->get($eventUrl)->assertOk()->assertDontSee('Join event discussion');
        $this->post('https://canadamb.ca/admin/community/threads/'.$thread->id, ['action' => 'approve'])->assertRedirect();
        $this->get($eventUrl)->assertSee('Join event discussion');
        $this->get($threadUrl)->assertOk()->assertSee('Official event details');
        $this->get('https://brandonmb.ca/events')->assertSee('href="/community/'.$thread->id.'"', false);
        $this->actingAs($owner)->post('https://brandonmb.ca/account/events/'.$event->id.'/cancel')->assertRedirect();
        $this->get($threadUrl)->assertSee('This event has been cancelled.');
        $this->post('https://brandonmb.ca/account/discussions/'.$thread->id.'/withdraw')->assertRedirect();
        $this->get($eventUrl)->assertOk()->assertDontSee('Join event discussion');
    }

    public function test_business_and_job_reports_reject_conflicted_reviewers(): void
    {
        config(['network.content_reports' => true]);
        $owner = User::factory()->create(['role' => 'network_admin']);
        $reporter = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $business = Business::create(['slug' => 'report-business', 'name' => 'Report Business', 'category' => 'services', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $branch = $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Test Road']);
        $job = JobPosting::create(['business_location_id' => $branch->id, 'author_id' => $owner->id, 'title' => 'Reported job', 'description' => 'A sample job description.', 'employment_type' => 'full-time', 'workplace' => 'on-site', 'apply_url' => 'https://example.test/apply', 'closing_on' => today()->addWeek()->toDateString()]);
        $base = 'https://brandonmb.ca/account/report/';
        $data = ['reason' => 'misleading', 'explanation' => 'A concern about the public information.'];
        $this->actingAs($reporter)->post($base.'job/'.$job->id, $data)->assertNotFound();
        $job->forceFill(['status' => 'approved', 'published_at' => now()])->save();
        $this->post($base.'business/'.$business->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($base.'job/'.$job->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('content_reports', 2);
        $report = DB::table('content_reports')->where('target_type', 'business')->value('id');
        $review = 'https://canadamb.ca/admin/content-reports/'.$report;
        $this->actingAs($owner)->post($review, ['note' => 'Conflicted review attempt.'])->assertForbidden();
        $this->actingAs($admin)->post($review, ['note' => 'Independent review completed.'])->assertRedirect();
        $this->assertDatabaseHas('content_reports', ['id' => $report, 'status' => 'resolved']);
    }

    public function test_reports_are_scoped_private_deduplicated_and_independently_resolved(): void
    {
        $author = User::factory()->create();
        $reporter = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $thread = CommunityThread::create([
            'author_id' => $author->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'category' => 'general', 'title' => 'Reported public discussion', 'body' => 'A public discussion body.',
        ]);
        $thread->forceFill(['status' => 'approved', 'last_activity_at' => now()])->save();
        $reply = $thread->replies()->create(['author_id' => $author->id, 'body' => 'A pending reply.']);
        $other = CommunityThread::create([
            'author_id' => $author->id, 'location_id' => Location::where('slug', 'elkhorn')->sole()->id,
            'category' => 'general', 'title' => 'Other community thread', 'body' => 'A different community body.',
        ]);
        $other->forceFill(['status' => 'approved', 'last_activity_at' => now()])->save();
        $url = 'https://brandonmb.ca/account/report/discussion/'.$thread->id;
        $data = ['reason' => 'spam', 'explanation' => 'This is a private report explanation.'];
        $this->actingAs($reporter)->get($url)->assertNotFound();
        config(['network.content_reports' => true]);
        $this->get($url)->assertOk()->assertSee('Reported public discussion');
        $this->post('https://brandonmb.ca/account/report/discussion/'.$other->id, $data)->assertNotFound();
        $this->get('https://brandonmb.ca/account/report/reply/'.$reply->id)->assertNotFound();
        $this->post($url, $data)->assertRedirect('/account/content-reports')->assertSessionHasNoErrors();
        $this->post($url, $data)->assertRedirect();
        $this->assertDatabaseCount('content_reports', 1);
        $this->get($url)->assertOk()->assertSee('already reported');
        $history = 'https://brandonmb.ca/account/content-reports';
        $this->get($history)->assertOk()->assertSee($data['explanation']);
        $this->actingAs($author)->get($history)->assertOk()->assertDontSee($data['explanation']);
        $this->get('https://canadamb.ca/admin/content-reports')->assertForbidden();
        $this->actingAs($admin)->get('https://canadamb.ca/admin/content-reports')->assertOk()->assertSee($data['explanation'])->assertSee($thread->title);
        $id = DB::table('content_reports')->value('id');
        $this->post('https://canadamb.ca/admin/content-reports/'.$id, ['note' => 'Internal review completed.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $thread->fresh()->status);
        $this->actingAs($reporter)->get($history)->assertOk()->assertSee('Review completed')->assertDontSee('Internal review completed.');
    }
}

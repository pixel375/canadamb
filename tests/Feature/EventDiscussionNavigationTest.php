<?php

namespace Tests\Feature;

use App\Models\CommunityEvent;
use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\EventDiscussions;
use App\Support\NetworkSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

class EventDiscussionNavigationTest extends TestCase
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

    public function test_event_and_discussion_approval_policies_are_independent_and_locally_restricted(): void
    {
        $this->travelTo('2026-11-26 12:00:00');
        config(['network.event_discussions' => true]);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $member = User::factory()->create();
        $settings = app(NetworkSettings::class);
        $site = Site::where('code', 'brandon')->sole();
        $policy = function (string $events, string $discussions) use ($settings, $admin): void {
            $settings->saveDraft($admin->id, fn (array $current) => array_replace($current, [
                'publication_policy' => ['events' => $events, 'discussions' => $discussions],
            ]));
            $settings->publish($admin->id);
        };
        $policy('manual', 'verified');
        $input = ['title' => 'Discussion first event', 'body' => 'An upcoming community event with independent review.',
            'category' => 'community', 'organizer' => 'Local club', 'venue' => 'Community hall',
            'starts_at' => '2026-12-02T10:00', 'location_id' => $site->location_id];
        $this->actingAs($member)->post('https://canadamb.ca/events', $input)->assertRedirect()->assertSessionHasNoErrors();
        $event = CommunityEvent::sole();
        $this->assertSame('pending', $event->status);
        $this->assertSame('approved', EventDiscussions::linkedThread($event)->status);
        $policy('verified', 'manual');
        $this->post('https://brandonmb.ca/events', array_replace($input, ['title' => 'Calendar first event']))->assertRedirect()->assertSessionHasNoErrors();
        $event = CommunityEvent::where('title', 'Calendar first event')->sole();
        $this->assertSame('approved', $event->status);
        $this->assertSame('pending', EventDiscussions::linkedThread($event)->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'community.event.auto-approved']);
        $site->update(['overrides' => array_replace($site->overrides ?? [], ['manual_publication' => ['events' => true]])]);
        $this->post('https://canadamb.ca/events', array_replace($input, ['title' => 'Restricted local event']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending', CommunityEvent::where('title', 'Restricted local event')->sole()->status);
    }

    public function test_approved_event_comments_notify_organizer_once_and_hide_event_classification_after_rejection(): void
    {
        config(['network.event_discussions' => true, 'network.notification_preferences' => true]);
        $organizer = User::factory()->create();
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $event = CommunityEvent::create(['author_id' => $organizer->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Event comment notification fixture', 'body' => 'An upcoming event for our local community.', 'category' => 'community',
            'organizer' => 'Local club', 'venue' => 'Community hall', 'starts_at' => now()->addWeek()->format('Y-m-d H:i:s')]);
        $event->forceFill(['status' => 'approved'])->save();
        EventDiscussions::create($event);
        $thread = CommunityThread::sole();
        $thread->forceFill(['status' => 'approved'])->save();
        $url = 'https://brandonmb.ca/community/'.$thread->id;
        $this->actingAs($member)->post($url.'/replies', ['body' => 'Is this event open to everyone?'])->assertRedirect();
        $reply = CommunityReply::sole();
        $this->assertDatabaseMissing('community_notifications', ['user_id' => $organizer->id, 'reply_id' => $reply->id]);
        $this->actingAs($admin)->post('https://canadamb.ca/admin/community/replies/'.$reply->id, ['action' => 'approve', 'version' => $reply->contentVersion()])->assertRedirect();
        $this->assertDatabaseHas('community_notifications', ['user_id' => $organizer->id, 'reply_id' => $reply->id]);
        $this->actingAs($organizer)->get('https://canadamb.ca/account/notifications')->assertOk()->assertSee('commented on this event.');
        $this->get('https://brandonmb.ca/account/community-notifications')->assertOk()->assertSee('commented on this event.');
        $event->forceFill(['status' => 'rejected'])->save();
        $this->get('https://canadamb.ca/account/notifications')->assertOk()->assertDontSee('commented on this event.');
    }

    public function test_linked_event_review_status_and_calendar_navigation_preserve_both_hosts_and_public_scope(): void
    {
        $this->travelTo('2026-11-26 12:00:00');
        config(['network.event_discussions' => true]);
        $author = User::factory()->create();
        $event = CommunityEvent::create(['author_id' => $author->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Linked event fixture', 'body' => 'A public discussion about the community event.', 'category' => 'community',
            'organizer' => 'Local club', 'venue' => 'Private unpublished venue fixture', 'starts_at' => '2026-12-02 16:00:00']);
        EventDiscussions::create($event);
        $thread = CommunityThread::sole();
        $thread->forceFill(['status' => 'approved'])->save();
        foreach (['https://canadamb.ca', 'https://brandonmb.ca'] as $host) {
            $this->get($host.'/community/'.$thread->id)->assertOk()->assertSee('Review pending')
                ->assertDontSee('Private unpublished venue fixture')->assertDontSee('href="/events/'.$event->id.'"', false);
            $this->get($host.'/events/'.$event->id)->assertNotFound();
            $this->assertSame([], $this->get($host.'/community')->assertOk()->viewData('eventDiscussionIds'));
        }
        $event->forceFill(['status' => 'approved'])->save();
        foreach (['https://canadamb.ca', 'https://brandonmb.ca'] as $host) {
            $this->get($host.'/community/'.$thread->id)->assertOk()->assertSee('Go to event')
                ->assertSee('href="/events/'.$event->id.'"', false)->assertDontSee('Review pending');
            $this->get($host.'/events?month=2026-12')->assertOk()->assertSee('href="/events/'.$event->id.'"', false)
                ->assertSee('href="/community/'.$thread->id.'"', false);
            $hub = $this->get($host.'/community')->assertOk();
            $this->assertEquals([$event->id => $thread->id], $hub->viewData('eventDiscussionIds'));
            $this->get($host.'/events/'.$event->id)->assertOk()->assertSee('Join event discussion')
                ->assertSee('href="/community/'.$thread->id.'"', false);
        }
        $thread->forceFill(['author_id' => User::factory()->create()->id])->save();
        foreach (['https://canadamb.ca', 'https://brandonmb.ca'] as $host) {
            $this->get($host.'/events?month=2026-12')->assertOk()->assertDontSee('href="/community/'.$thread->id.'"', false);
            $this->get($host.'/events/'.$event->id)->assertOk()->assertDontSee('Join event discussion');
            $this->assertSame([], $this->get($host.'/community')->assertOk()->viewData('eventDiscussionIds'));
            $this->get($host.'/community/'.$thread->id)->assertOk()->assertDontSee('Go to event');
        }
        $thread->forceFill(['author_id' => $author->id])->save();
        $this->assertSame([], $this->get('https://canadamb.ca/community?location=winnipeg')->assertOk()->viewData('eventDiscussionIds'));
        $thread->forceFill(['status' => 'pending'])->save();
        $this->assertSame([], $this->get('https://brandonmb.ca/community')->assertOk()->viewData('eventDiscussionIds'));
        $thread->forceFill(['status' => 'approved'])->save();
        $event->forceFill(['status' => 'rejected'])->save();
        $this->get('https://canadamb.ca/community/'.$thread->id)->assertOk()->assertDontSee('Review pending')
            ->assertDontSee('Go to event')->assertDontSee('Private unpublished venue fixture');
        $event->forceFill(['status' => 'cancelled'])->save();
        $this->get('https://canadamb.ca/community/'.$thread->id)->assertOk()->assertSee('This event has been cancelled.');
        config(['network.event_discussions' => false]);
        $this->get('https://canadamb.ca/community/'.$thread->id)->assertOk()->assertDontSee('Go to event')->assertDontSee('Review pending');
    }
}

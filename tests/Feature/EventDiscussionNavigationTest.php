<?php

namespace Tests\Feature;

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use App\Support\EventDiscussions;
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
        }
        $event->forceFill(['status' => 'approved'])->save();
        foreach (['https://canadamb.ca', 'https://brandonmb.ca'] as $host) {
            $this->get($host.'/community/'.$thread->id)->assertOk()->assertSee('Go to event')
                ->assertSee('href="/events/'.$event->id.'"', false)->assertDontSee('Review pending');
            $this->get($host.'/events?month=2026-12')->assertOk()->assertSee('href="/events/'.$event->id.'"', false)
                ->assertSee('href="/community/'.$thread->id.'"', false);
            $this->get($host.'/events/'.$event->id)->assertOk()->assertSee('Join event discussion')
                ->assertSee('href="/community/'.$thread->id.'"', false);
        }
        $event->forceFill(['status' => 'rejected'])->save();
        $this->get('https://canadamb.ca/community/'.$thread->id)->assertOk()->assertDontSee('Review pending')
            ->assertDontSee('Go to event')->assertDontSee('Private unpublished venue fixture');
        $event->forceFill(['status' => 'cancelled'])->save();
        $this->get('https://canadamb.ca/community/'.$thread->id)->assertOk()->assertSee('This event has been cancelled.');
        config(['network.event_discussions' => false]);
        $this->get('https://canadamb.ca/community/'.$thread->id)->assertOk()->assertDontSee('Go to event')->assertDontSee('Review pending');
    }
}

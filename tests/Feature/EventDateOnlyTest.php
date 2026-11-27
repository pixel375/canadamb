<?php

namespace Tests\Feature;

use App\Models\CommunityEvent;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

class EventDateOnlyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => DatabaseSeeder::class])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            CommunityEvent::where('date_only', true)->delete();
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_date_only_events_keep_calendar_dates_across_dst_and_mirrored_domains(): void
    {
        $this->travelTo('2026-03-01 12:00:00');
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $base = 'https://brandonmb.ca';
        $data = ['title' => 'Date-only community fixture', 'body' => 'A community event without a known start time.',
            'category' => 'community', 'venue' => 'Community hall', 'organizer' => 'Local club', 'starts_on' => '2026-03-08'];
        $this->actingAs($member)->post($base.'/events', $data)->assertNotFound();
        config(['network.event_date_only' => true, 'network.recurring_events' => true]);
        $this->get($base.'/community/new?type=event')->assertOk()->assertSee('Start time (optional)');
        $this->post($base.'/events', $data + ['end_time' => '12:00'])->assertSessionHasErrors('ends_at');
        $this->post($base.'/events', $data + ['ends_on' => '2026-03-07'])->assertSessionHasErrors('ends_at');
        $this->post($base.'/events', $data + ['repeat' => 'weekly', 'occurrences' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $events = CommunityEvent::orderBy('starts_at')->get();
        $this->assertCount(2, $events);
        $event = $events[0];
        $this->assertTrue($event->isDateOnly());
        $this->assertSame('2026-03-08 06:00:00', $event->starts_at);
        $this->assertSame('2026-03-09 05:00:00', $event->ends_at);
        $this->assertSame('2026-03-15', $events[1]->startInput());
        $this->assertSame('2026-03-15', $events[1]->endInput());
        $path = '/events/'.$event->id;
        $this->get($base.$path)->assertNotFound();
        $this->get($base.'/account/events/'.$event->id.'/edit')->assertOk()->assertSee('Start time (optional)');
        $this->actingAs($admin)->post('https://canadamb.ca/admin/community/events/'.$event->id, ['action' => 'approve'])->assertRedirect();
        foreach ([$base, 'https://canadamb.ca'] as $host) {
            $this->get($host.$path)->assertOk()->assertSee('Time not specified')->assertDontSee('12:00 am');
            $this->get($host.$path.'/calendar')->assertOk()->assertSee('DTSTART;VALUE=DATE:20260308')->assertSee('DTEND;VALUE=DATE:20260309')->assertDontSee('DTSTART:');
        }
        $this->get($base.'/events?day=2026-03-08')->assertOk()->assertSee($event->title);
        $this->get($base.'/events?day=2026-03-09')->assertOk()->assertDontSee($event->title);
        $this->travelTo('2026-03-08 20:00:00');
        $this->get($base.'/events')->assertOk()->assertSee($event->title);
        $this->travelTo('2026-03-09 05:00:01');
        $this->get($base.'/events')->assertOk()->assertDontSee($event->title);
        $this->actingAs($member)->post($base.'/account/events/shift-selected', [
            'events' => [$events[1]->id], 'versions' => [$events[1]->id => $events[1]->contentVersion()], 'days' => 1, 'confirm_shift' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-03-16', $events[1]->fresh()->startInput());
        config(['network.event_date_only' => false]);
        $this->get($base.$path)->assertOk()->assertSee('Time not specified');
        $this->get($base.$path.'/calendar')->assertOk()->assertSee('DTSTART;VALUE=DATE:20260308');
        $this->get($base.'/account/events/'.$events[1]->id.'/edit')->assertConflict();
    }
}

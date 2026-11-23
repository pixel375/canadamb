<?php

namespace Tests\Feature;

use App\Models\CommunityEvent;
use App\Models\Location;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventAgendaTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_the_agenda_uses_winnipeg_today_and_keeps_legacy_event_links(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 02:00:00', 'UTC'));
        $event = $this->event('Late November gathering', '2026-12-01 03:00:00');
        $this->event('Previous day gathering', '2026-11-30 02:00:00');
        $this->event('Next month gathering', '2026-12-02 03:00:00');

        $this->get('https://brandonmb.ca/discussions')
            ->assertSee('Events calendar');
        $this->get('https://brandonmb.ca/events')
            ->assertOk()
            ->assertSee('November 2026')
            ->assertSee('Monday, November 30')
            ->assertSee('aria-current="date"', false)
            ->assertSee('<summary', false)
            ->assertSee('1 event')
            ->assertSee('href="/events/'.$event->id.'"', false)
            ->assertSee('Late November gathering')
            ->assertDontSee('Previous day gathering')
            ->assertDontSee('Next month gathering');
        $this->get('https://brandonmb.ca/events?month=2026-11')
            ->assertOk()->assertSee('Previous day gathering');
    }

    public function test_the_agenda_clips_overlaps_using_local_midnight_and_only_counts_public_matches(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'UTC'));
        $this->event('Clock change workshop', '2026-11-01 03:00:00', '2026-11-02 06:00:00');
        $this->event('November midnight gathering', '2026-11-02 06:00:00');
        $this->event('Cancelled legacy workshop', '2026-11-01 12:00:00', status: 'cancelled');
        $this->event('Pending legacy workshop', '2026-11-01 12:00:00', status: 'pending');
        $this->event('Winnipeg workshop', '2026-11-01 12:00:00', place: 'winnipeg');

        $this->get('https://brandonmb.ca/events?month=2026-11&day=2026-11-01')
            ->assertOk()->assertSee('1 event')->assertSee('Clock change workshop')
            ->assertDontSee('November midnight gathering')
            ->assertDontSee('Cancelled legacy workshop')
            ->assertDontSee('Pending legacy workshop')
            ->assertDontSee('Winnipeg workshop');
        $this->get('https://brandonmb.ca/events?day=2026-11-02')
            ->assertOk()->assertSee('1 event')->assertSee('November midnight gathering')
            ->assertDontSee('Clock change workshop');

        $winnipeg = Location::where('slug', 'winnipeg')->sole()->id;
        $this->get("https://canadamb.ca/events?month=2026-11&location=$winnipeg&category=community&q=workshop")
            ->assertOk()->assertSee('Winnipeg workshop')->assertSee('1 event')
            ->assertDontSee('Clock change workshop')
            ->assertSee("q=workshop&amp;category=community&amp;location=$winnipeg", false);
    }

    public function test_busy_days_show_exact_counts_and_offer_a_paginated_view(): void
    {
        for ($number = 1; $number <= 26; $number++) {
            $this->event(sprintf('Harvest gathering %02d', $number), '2026-11-18 14:30:00');
        }

        $this->get('https://brandonmb.ca/events?month=2026-11&q=Harvest')
            ->assertOk()->assertSee('26 events')->assertSee('Harvest gathering 08')
            ->assertDontSee('Harvest gathering 09')->assertSee('View all 26 events')
            ->assertSee('day=2026-11-18', false);
        $this->get('https://brandonmb.ca/events?day=2026-11-18&q=Harvest')
            ->assertOk()->assertSee('26 events')->assertSee('Harvest gathering 24')
            ->assertDontSee('Harvest gathering 25')->assertSee('Page 1 of 2');
        $this->get('https://brandonmb.ca/events?day=2026-11-18&q=Harvest&page=2')
            ->assertOk()->assertSee('26 events')->assertDontSee('Harvest gathering 24')
            ->assertSee('Harvest gathering 25')->assertSee('Harvest gathering 26')
            ->assertSee('Page 2 of 2');
    }

    public function test_the_agenda_rejects_invalid_days_months_and_city_filters(): void
    {
        $winnipeg = Location::where('slug', 'winnipeg')->sole()->id;
        $this->getJson('https://brandonmb.ca/events?month=2026-00')->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->getJson('https://brandonmb.ca/events?month=0001-01')->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->getJson('https://brandonmb.ca/events?month=2026-11&day=2026-12-01')->assertUnprocessable();
        $this->getJson('https://brandonmb.ca/events?day=2026-02-29')->assertUnprocessable()->assertJsonValidationErrors('day');
        $this->getJson("https://brandonmb.ca/events?location=$winnipeg")->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->getJson('https://canadamb.ca/events?location=999999')->assertUnprocessable()->assertJsonValidationErrors('location');
    }

    public function test_cancellation_during_the_agenda_read_cannot_split_counts_from_cards(): void
    {
        $event = $this->event('Snapshot archive tour', '2026-11-18 14:30:00');
        $reads = 0;
        DB::listen(function ($query) use (&$reads, $event): void {
            if (str_contains($query->sql, 'place_name') && ++$reads === 1) {
                CommunityEvent::whereKey($event->id)->update(['status' => 'cancelled']);
            }
        });
        $page = $this->get('https://brandonmb.ca/events?day=2026-11-18')->assertOk();
        $this->assertSame(1, $reads);
        $this->assertSame($page->viewData('calendar') !== [], str_contains($page->getContent(), 'Snapshot archive tour'));
        foreach ($page->viewData('calendar') as $group) {
            $this->assertSame($group['count'], count($group['events']));
        }
        $this->get('https://brandonmb.ca/events?day=2026-11-18')->assertOk()->assertDontSee('Snapshot archive tour');
    }

    private function event(string $title, string $start, ?string $end = null, string $place = 'brandon', string $status = 'approved'): CommunityEvent
    {
        return CommunityEvent::query()->forceCreate([
            'author_id' => User::factory()->create()->id,
            'location_id' => Location::where('slug', $place)->sole()->id,
            'title' => $title,
            'body' => 'A local event for community members.',
            'category' => 'community',
            'venue' => 'Community hall',
            'organizer' => 'Local club',
            'starts_at' => $start,
            'ends_at' => $end,
            'status' => $status,
        ]);
    }
}

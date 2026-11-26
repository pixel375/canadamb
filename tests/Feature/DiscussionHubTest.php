<?php

namespace Tests\Feature;

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscussionHubTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_discussions_show_only_approved_local_upcoming_events_grouped_by_day(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        $brandon = Location::where('slug', 'brandon')->sole()->id;
        $winnipeg = Location::where('slug', 'winnipeg')->sole()->id;

        $first = $this->event($brandon, 'Brandon winter market', '2026-12-04 19:00:00');
        $this->event($brandon, 'Brandon evening concert', '2026-12-04 22:00:00');
        $this->event($winnipeg, 'Winnipeg music night', '2026-12-05 19:00:00');
        $this->event($brandon, 'Event awaiting moderation', '2026-12-04 20:00:00', 'pending');
        $this->event($brandon, 'Brandon event outside 60-day window', '2027-03-01 18:00:00');
        $this->event($brandon, 'Brandon past event', '2026-11-20 18:00:00');

        $this->get('https://canadamb.ca/community')
            ->assertOk()
            ->assertSee('Browse discussions')
            ->assertSee('Upcoming events')
            ->assertSee('2 events')
            ->assertSee('Brandon winter market')
            ->assertSee('Winnipeg music night')
            ->assertSee('href="/events/'.$first->id.'"', false)
            ->assertDontSee('Event awaiting moderation')
            ->assertDontSee('Brandon past event')
            ->assertDontSee('Brandon event outside 60-day window');

        $this->get('https://brandonmb.ca/community')
            ->assertOk()
            ->assertSee('Brandon winter market')
            ->assertSee('Brandon evening concert')
            ->assertDontSee('Winnipeg music night')
            ->assertSee('href="/community/new?type=event"', false);
    }

    public function test_event_date_shortcuts_and_calendar_exports_share_local_time_and_public_scope(): void
    {
        $this->travelTo('2026-11-26 12:00:00');
        $brandon = Location::where('slug', 'brandon')->sole()->id;
        $winnipeg = Location::where('slug', 'winnipeg')->sole()->id;
        $today = $this->event($brandon, 'Thursday date fixture', '2026-11-26 20:00:00');
        $weekend = $this->event($brandon, 'Weekend date fixture', '2026-11-28 20:00:00');
        $this->event($brandon, 'Next week fixture', '2026-12-03 20:00:00');
        $this->event($brandon, 'Hidden weekend fixture', '2026-11-28 20:00:00', 'pending');
        $this->event($winnipeg, 'Remote weekend fixture', '2026-11-28 20:00:00');
        $base = 'https://brandonmb.ca/events';
        $this->get($base.'?period=today')->assertOk()->assertSee('Thursday date fixture')->assertDontSee('Weekend date fixture');
        $this->get($base.'?period=weekend')->assertOk()->assertSee('Weekend date fixture')->assertDontSee('Thursday date fixture')
            ->assertDontSee('Remote weekend fixture')->assertDontSee('Hidden weekend fixture');
        $this->get($base.'/calendar?period=weekend')->assertOk()->assertSee('Weekend date fixture')->assertDontSee('Thursday date fixture');
        $this->get($base.'?period=week')->assertOk()->assertSee('Thursday date fixture')->assertSee('Weekend date fixture')->assertDontSee('Next week fixture');
        $this->get($base.'/'.$today->id)->assertOk()->assertSee('More upcoming events in Brandon')->assertSee('Weekend date fixture')->assertDontSee('Hidden weekend fixture');
        $this->travelTo(CarbonImmutable::parse('2026-11-29 16:00:00', 'UTC'));
        $this->event($brandon, 'Sunday date fixture', '2026-11-29 20:00:00');
        $this->get($base.'?period=weekend')->assertOk()->assertSee('Sunday date fixture')->assertDontSee('Next week fixture');
        $this->getJson($base.'?period=invalid')->assertUnprocessable();
    }

    public function test_discussion_filter_links_and_empty_calendar_remain_usable(): void
    {
        $this->get('https://canadamb.ca/community?category=help')
            ->assertOk()
            ->assertSee('aria-label="Discussion categories"', false)
            ->assertSee('href="/community?category=help" aria-current="page"', false)
            ->assertSee('No upcoming events posted yet.')
            ->assertSee('class="search-disclosure" open', false);

        $this->get('https://brandonmb.ca/community')
            ->assertOk()
            ->assertSee('Full events calendar')
            ->assertSee('Start the conversation')
            ->assertSee('class="discussion-empty-actions"', false);
    }

    public function test_provincial_discussion_search_preserves_location_in_filters_and_never_leaks_other_towns(): void
    {
        $author = User::factory()->create();
        $brandon = Location::where('slug', 'brandon')->sole();
        $winnipeg = Location::where('slug', 'winnipeg')->sole();
        foreach ([$brandon, $winnipeg] as $place) {
            $thread = CommunityThread::create([
                'author_id' => $author->id,
                'location_id' => $place->id,
                'title' => 'Community search cleanup in '.$place->name,
                'body' => 'Local community discussion search results.',
                'category' => 'general',
            ]);
            $thread->forceFill(['status' => 'approved'])->save();
        }
        $this->get('https://canadamb.ca/community?q=cleanup&location=brandon')
            ->assertOk()
            ->assertSee('Community search cleanup in Brandon')
            ->assertDontSee('Community search cleanup in Winnipeg')
            ->assertSee('name="location"', false)
            ->assertSee('value="brandon" selected', false)
            ->assertSee('href="/community?category=help&amp;q=cleanup&amp;location=brandon"', false);

        $this->get('https://canadamb.ca/community?location=winnipeg')
            ->assertOk()->assertSee('Community search cleanup in Winnipeg')
            ->assertDontSee('Community search cleanup in Brandon');

        $this->get('https://canadamb.ca/community?location=not-a-real-place')
            ->assertSessionHasErrors('location');

        $this->get('https://brandonmb.ca/community?location=winnipeg')
            ->assertOk()->assertSee('Community search cleanup in Brandon')
            ->assertDontSee('Community search cleanup in Winnipeg');
    }

    public function test_topic_status_and_following_filters_preserve_public_scope_and_approved_reply_counts(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $brandon = Location::where('slug', 'brandon')->sole();
        $winnipeg = Location::where('slug', 'winnipeg')->sole();
        $threads = collect();
        foreach ([[$brandon, 'Open unanswered fixture', 'approved', false], [$brandon, 'Answered fixture', 'approved', false],
            [$brandon, 'Locked fixture', 'approved', true], [$brandon, 'Private followed fixture', 'pending', false],
            [$winnipeg, 'Remote followed fixture', 'approved', false]] as [$place, $title, $status, $locked]) {
            $thread = CommunityThread::create(['author_id' => $other->id, 'location_id' => $place->id, 'title' => $title,
                'body' => 'A discussion fixture.', 'category' => 'help']);
            $thread->forceFill(['status' => $status, 'locked' => $locked, 'last_activity_at' => now()->subDays(2)])->save();
            $threads->push($thread);
        }
        $threads[0]->replies()->create(['author_id' => $other->id, 'body' => 'Unapproved response']);
        $threads[1]->replies()->create(['author_id' => $other->id, 'body' => 'Approved response'])->forceFill(['status' => 'approved'])->save();
        $threads[1]->forceFill(['last_activity_at' => now()->subDays(10)])->save();
        foreach ([0, 3, 4] as $index) {
            DB::table('community_thread_follows')->insert(['user_id' => $member->id, 'thread_id' => $threads[$index]->id, 'created_at' => now()]);
        }
        $base = 'https://brandonmb.ca/community';
        $this->get($base.'?topics=unanswered')->assertOk()->assertSee('Open unanswered fixture')->assertDontSee('Answered fixture')->assertDontSee('Locked fixture');
        $this->get($base.'?topics=open&activity=7')->assertOk()->assertSee('Open unanswered fixture')->assertDontSee('Answered fixture');
        $this->get($base.'?topics=following')->assertForbidden();
        $this->actingAs($member)->get($base.'?topics=following')->assertOk()->assertSee('Open unanswered fixture')->assertDontSee('Private followed fixture')->assertDontSee('Remote followed fixture');
        $this->actingAs($other)->get($base.'?topics=following')->assertOk()->assertDontSee('Open unanswered fixture');
    }

    public function test_shared_post_composer_uses_existing_moderated_discussion_and_event_endpoints(): void
    {
        $this->get('https://canadamb.ca/community/new?type=event')->assertRedirect();
        $this->actingAs(User::factory()->create());
        $this->get('https://canadamb.ca/community/new')
            ->assertOk()
            ->assertSee('Choose post type')
            ->assertSee('action="/community"', false)
            ->assertDontSee('name="starts_at"', false);
        $this->get('https://canadamb.ca/community/new?type=event')
            ->assertOk()
            ->assertSee('Submit an event')
            ->assertSee('action="/events"', false)
            ->assertSee('name="starts_at"', false)
            ->assertSee('name="organizer"', false)
            ->assertSee('name="event_url"', false);
        $this->get('https://brandonmb.ca/community/new?type=event')
            ->assertOk()->assertSee('Event request');
        $this->get('https://canadamb.ca/events/new')->assertOk()->assertSee('Submit an event');
        $this->get('https://canadamb.ca/events')->assertOk()
            ->assertSee('href="/community"', false)
            ->assertSee('href="/community/new?type=event"', false);
    }

    public function test_discussion_owner_dashboard_is_private_and_composer_preserves_scope(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $place = Location::where('slug', 'brandon')->sole();
        foreach ([$member, $other] as $author) {
            CommunityThread::create(['author_id' => $author->id, 'location_id' => $place->id,
                'title' => $author->is($member) ? 'My pending topic' : 'Other private topic',
                'category' => 'help', 'body' => 'A useful local community question.']);
        }
        $this->get('https://canadamb.ca/account/discussions')->assertRedirect();
        $this->actingAs($member)->get('https://canadamb.ca/account/discussions?status=pending')
            ->assertOk()->assertSee('My pending topic')->assertDontSee('Other private topic');
        $this->get('https://canadamb.ca/community?location=brandon&sort=replies')->assertOk()
            ->assertDontSee('My pending topic')->assertDontSee('Other private topic');
        $this->get('https://canadamb.ca/community/new?location=brandon&category=help')->assertOk()
            ->assertSee('value="'.$place->id.'" selected', false)->assertSee('value="help" selected', false);
    }

    public function test_event_owner_dashboard_and_scope_respect_private_submissions(): void
    {
        $member = User::factory()->create();
        $place = Location::where('slug', 'brandon')->sole();
        $event = $this->event($place->id, 'My private event', now()->addWeek()->format('Y-m-d H:i:s'), 'pending');
        $event->forceFill(['author_id' => $member->id])->save();
        $this->event($place->id, 'Other private event', now()->addWeek()->format('Y-m-d H:i:s'), 'pending');
        $this->actingAs($member)->get('https://canadamb.ca/account/events?status=pending&q=private')
            ->assertOk()->assertSee('My private event')->assertDontSee('Other private event');
        $this->get('https://canadamb.ca/events/new?location=brandon')->assertOk()
            ->assertSee('value="'.$place->id.'" selected', false);
        $this->get('https://canadamb.ca/events?location=brandon')->assertOk()->assertDontSee('My private event')
            ->assertSee('href="/community/new?type=event&amp;location=brandon"', false);
    }

    private function event(int $locationId, string $title, string $startsAt, string $status = 'approved'): CommunityEvent
    {
        $event = CommunityEvent::create([
            'author_id' => User::factory()->create()->id,
            'location_id' => $locationId,
            'title' => $title,
            'body' => 'All community members are welcome to attend this local event.',
            'category' => 'community',
            'organizer' => 'Community volunteers',
            'venue' => 'Local community hall',
            'starts_at' => $startsAt,
        ]);
        $event->forceFill(['status' => $status])->save();

        return $event;
    }
}

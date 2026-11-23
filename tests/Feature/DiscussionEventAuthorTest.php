<?php

namespace Tests\Feature;

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use App\Support\ModerationDecision;
use App\Support\NetworkSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscussionEventAuthorTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const MAIN = 'https://canadamb.ca';

    private const CITY = 'https://brandonmb.ca';

    public function test_only_verified_owner_can_request_one_event_from_the_current_approved_discussion(): void
    {
        $this->travelTo('2026-03-01 12:00:00');
        $owner = User::factory()->create();
        $thread = $this->thread($owner);
        $page = self::CITY.'/discussions/'.$thread->id;
        $url = $page.'/event';
        $version = ModerationDecision::version('thread', $thread->fresh());

        $this->get($page)->assertOk()->assertDontSee('Request event listing');
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->postJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson($url)->assertNotFound();
        $this->actingAs($owner)->get($page)->assertSee('Request event listing');
        $this->get($url)->assertOk()->assertSee($thread->title)->assertSee($version)
            ->assertDontSee('name="title"', false)->assertDontSee('name="body"', false);
        $this->postJson($url, $this->metadata())->assertUnprocessable()->assertJsonValidationErrors('thread_version');
        $this->postJson($url, $this->metadata() + ['thread_version' => str_repeat('0', 64)])->assertConflict();
        $this->postJson($url, $this->metadata() + ['thread_version' => $version, 'location_id' => $this->location('winnipeg')])
            ->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->post($url, $this->metadata() + ['thread_version' => $version, 'title' => 'Forged title', 'body' => 'Forged body', 'author_id' => 123, 'thread_id' => 123])
            ->assertRedirect('/account/events')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('community_events', [
            'thread_id' => $thread->id, 'author_id' => $owner->id, 'location_id' => $thread->location_id,
            'title' => $thread->title, 'body' => $thread->body, 'status' => 'pending', 'starts_at' => '2026-03-08 08:30:00',
        ]);
        $this->postJson($url, $this->metadata() + ['thread_version' => $version])->assertConflict();
        $this->assertDatabaseCount('community_events', 1);
        $this->assertDatabaseCount('moderation_decisions', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->get($page)->assertSee('Pending review')->assertDontSee('Request event listing');
        $this->actingAs(User::factory()->create())->get($page)->assertDontSee('Pending review')->assertDontSee('Local garden club');
    }

    public function test_provincial_source_requires_a_manitoba_location_and_stays_off_city_hosts(): void
    {
        $this->travelTo('2026-03-01 12:00:00');
        $owner = User::factory()->create();
        $thread = $this->thread($owner, ['location_id' => null]);
        $path = '/discussions/'.$thread->id.'/event';
        $details = $this->metadata() + ['thread_version' => ModerationDecision::version('thread', $thread->fresh())];

        $this->actingAs($owner)->get(self::MAIN.$path)->assertOk()->assertSee('Choose a Manitoba community');
        $this->get(self::CITY.$path)->assertNotFound();
        $this->postJson(self::MAIN.$path, $details)->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $brandon = $this->location('brandon');
        $this->post(self::MAIN.$path, $details + ['location_id' => $brandon])->assertRedirect('/account/events')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('community_events', ['thread_id' => $thread->id, 'location_id' => $brandon]);
        $event = CommunityEvent::sole();
        $this->get(self::CITY.'/account/events')->assertOk()->assertDontSee($thread->title);
        $this->get(self::CITY.'/account/events/'.$event->id.'/edit')->assertNotFound();
        $this->get(self::MAIN.'/account/events')->assertSee($thread->title);
    }

    public function test_owner_resubmission_stays_manual_under_automatic_policy_and_cannot_replay_stale_cancellation(): void
    {
        $this->travelTo('2026-03-01 12:00:00');
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $settings = app(NetworkSettings::class);
        $settings->saveDraft($admin->id, function (array $draft): array {
            $draft['approval_policies']['event'] = 'auto_approve';

            return $draft;
        });
        $settings->publish($admin->id, expectedRevision: (int) $settings->state()->draft_id);

        $thread = $this->thread($owner);
        $parentVersion = ['thread_version' => ModerationDecision::version('thread', $thread->fresh())];
        $this->actingAs($owner)->post(self::CITY.'/discussions/'.$thread->id.'/event', $this->metadata() + $parentVersion)->assertRedirect();
        $event = CommunityEvent::sole();
        $this->assertSame('approved', $event->status);
        $url = self::CITY.'/account/events/'.$event->id;
        $version = $parentVersion + ['version' => ModerationDecision::version('event', $event)];

        $this->get($url.'/edit')->assertOk()->assertSee($version['version']);
        $this->put($url, $this->metadata() + $version)->assertRedirect('/account/events');
        $this->assertSame('approved', $event->fresh()->status);
        $this->post($url.'/cancel', $version + ['cancellation_note' => 'Owner-only reason'])->assertRedirect('/account/events');
        $this->assertSame('cancelled', $event->fresh()->status);
        $this->postJson($url.'/cancel', $version)->assertConflict();

        $updated = $parentVersion + ['version' => ModerationDecision::version('event', $event->fresh())];
        $this->put($url, $this->metadata(['venue' => 'New garden hall']) + $updated)->assertSessionHasNoErrors()->assertRedirect('/account/events');
        $this->assertDatabaseHas('community_events', ['id' => $event->id, 'status' => 'pending', 'venue' => 'New garden hall', 'cancelled_at' => null]);
        $this->assertDatabaseCount('community_events', 1);
        $this->assertDatabaseCount('moderation_decisions', 3);
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_event_time_validation_rejects_gaps_folds_and_invalid_ranges(): void
    {
        $this->travelTo('2025-11-01 12:00:00');
        $owner = User::factory()->create();
        $thread = $this->thread($owner);
        $url = self::CITY.'/discussions/'.$thread->id.'/event';
        $version = ['thread_version' => ModerationDecision::version('thread', $thread->fresh())];

        $this->actingAs($owner);
        foreach ([
            [['starts_at' => '2026-03-08T02:30'], 'starts_at'],
            [['starts_at' => '2025-11-02T01:30'], 'starts_at'],
            [['starts_at' => '2025-11-01T06:59'], 'starts_at'],
            [['starts_at' => '2026-11-02T07:00', 'ends_at' => null], 'starts_at'],
            [['ends_at' => '2026-03-08T03:30'], 'ends_at'],
            [['ends_at' => '2026-03-16T03:30'], 'ends_at'],
            [['event_url' => 'http://example.com/garden'], 'event_url'],
        ] as [$overrides, $field]) {
            $this->postJson($url, $this->metadata($overrides) + $version)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('community_events', 0);
        $this->post($url, $this->metadata(['ends_at' => null]) + $version)->assertRedirect('/account/events')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('community_events', ['thread_id' => $thread->id, 'ends_at' => null]);
    }

    private function thread(User $owner, array $attributes = []): CommunityThread
    {
        return CommunityThread::forceCreate($attributes + [
            'author_id' => $owner->id, 'location_id' => $this->location('brandon'),
            'title' => 'Neighbourhood garden swap', 'body' => 'Bring spare seeds to our neighbourhood garden swap.',
            'category' => 'general', 'last_activity_at' => now(), 'status' => 'approved',
        ]);
    }

    private function location(string $slug): int
    {
        return Location::where('slug', $slug)->sole()->id;
    }

    private function metadata(array $attributes = []): array
    {
        return $attributes + [
            'starts_at' => '2026-03-08T03:30', 'ends_at' => '2026-03-08T04:30',
            'category' => 'community', 'venue' => 'Garden hall', 'organizer' => 'Local garden club',
            'event_url' => 'https://example.com/garden',
        ];
    }
}

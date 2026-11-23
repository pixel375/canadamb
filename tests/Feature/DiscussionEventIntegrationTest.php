<?php

namespace Tests\Feature;

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use App\Support\ModerationDecision;
use App\Support\NetworkSettings;
use App\Support\NotificationInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscussionEventIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const MAIN = 'https://canadamb.ca';

    private const CITY = 'https://brandonmb.ca';

    public function test_legacy_create_routes_explain_discussion_requests_without_creating_events(): void
    {
        $this->get(self::CITY.'/events/new')->assertRedirect('/auth/start');
        $this->actingAs(User::factory()->create())->get(self::CITY.'/events/new')->assertOk()
            ->assertSee('Request from a discussion')->assertSee('/discussions/new')->assertSee('/account/discussions');
        $this->postJson(self::CITY.'/events', [
            'title' => 'An old standalone form', 'body' => 'Trying to submit an old standalone event form.',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('community_events', 0);
    }

    public function test_approved_local_event_opens_the_discussion_on_each_host_and_uses_one_structured_url(): void
    {
        $owner = User::factory()->create();
        $thread = $this->thread($owner, 'brandon');
        $event = $this->event($thread, 'approved');
        $path = "/discussions/$thread->id";
        $this->get(self::MAIN."/events/$event->id")->assertRedirect(self::MAIN.$path);
        $this->get(self::CITY."/events/$event->id")->assertRedirect(self::CITY.$path);
        $this->get(self::MAIN.$path)->assertOk()->assertSee('Hosted at the archive')->assertDontSee('"@type":"Event"', false)
            ->assertSee(self::CITY.$path);
        $this->get(self::CITY.$path)->assertOk()->assertSee('Hosted at the archive')->assertSee('"@type":"Event"', false);
        $this->get(self::CITY.'/sitemap.xml')->assertOk()->assertSee(self::CITY.$path)->assertDontSee("/events/$event->id");

        $provincial = $this->thread($owner, null, 'Manitoba-wide discussion');
        $networkEvent = $this->event($provincial, 'approved');
        $this->get(self::CITY."/events/$networkEvent->id")->assertNotFound();
        $this->get(self::MAIN."/events/$networkEvent->id")->assertRedirect(self::MAIN."/discussions/$provincial->id");
        $this->get(self::CITY.'/events?month='.now('America/Winnipeg')->format('Y-m'))->assertOk()->assertDontSee($provincial->title);
        $this->get(self::CITY."/discussions/$provincial->id")->assertNotFound();
        $reporter = User::factory()->create();
        $this->actingAs($reporter)->post(self::CITY."/reports/event/$networkEvent->id", [
            'reason' => 'This discussion cannot be reported from this portal.',
        ])->assertNotFound();
        $this->post(self::MAIN."/reports/event/$networkEvent->id", [
            'reason' => 'Please review this event listing.',
        ])->assertRedirect(self::MAIN."/discussions/$provincial->id");
        $this->assertDatabaseHas('content_reports', ['source_type' => 'event', 'source_id' => $networkEvent->id, 'reporter_id' => $reporter->id]);
    }

    public function test_thread_edit_hides_the_event_and_parent_reapproval_needs_a_new_event_review(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $thread = $this->thread($owner, 'brandon');
        $event = $this->event($thread);
        $review = self::MAIN."/admin/community/events/$event->id";
        $initial = $this->versions($event);
        $approval = ['action' => 'approve', 'reason' => 'Confirmed the schedule and venue.'];
        $this->actingAs($admin)->postJson($review, $approval + ['version' => $initial['version']])->assertUnprocessable();
        $this->post($review, $approval + $initial)->assertRedirect();
        $this->assertSame('approved', $event->fresh()->status);
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'event' => 'event.approved', 'resource_id' => $event->id]);

        $old = $this->versions($event);
        $this->actingAs($owner)->put(self::CITY."/account/discussions/$thread->id", [
            'version' => ModerationDecision::version('thread', $thread->fresh()), 'title' => 'Changed public event topic',
            'body' => 'A material change to the event details.', 'category' => 'general',
        ])->assertRedirect();
        $this->assertSame('pending', $event->fresh()->status);
        $this->get(self::CITY."/events/$event->id")->assertNotFound();
        $this->get(self::CITY.'/account/notifications')->assertOk()->assertSee('Event approved')->assertDontSee($thread->title)
            ->assertSee('Event awaiting review');
        $this->actingAs($admin)->postJson($review, $approval + $old)->assertConflict();
        $this->postJson($review, $approval + $this->versions($event))->assertConflict();
        $this->post(self::MAIN."/admin/community/threads/$thread->id", [
            'action' => 'approve', 'version' => ModerationDecision::version('thread', $thread->fresh()),
        ])->assertRedirect();
        $this->get(self::CITY."/events/$event->id")->assertNotFound();
        $this->postJson($review, $approval + $this->versions($event))->assertRedirect();
        $this->assertSame('Changed public event topic', $event->fresh()->title);
        $this->get(self::CITY."/events/$event->id")->assertRedirect(self::CITY."/discussions/$thread->id");
        $this->assertSame(2, DB::table('notifications')->where(['event' => 'event.approved', 'resource_id' => $event->id])->count());
        $this->post(self::MAIN."/admin/community/threads/$thread->id", [
            'action' => 'lock', 'version' => ModerationDecision::version('thread', $thread->fresh()),
        ])->assertRedirect();
        $this->assertSame('pending', $event->fresh()->status);
        $this->get(self::CITY."/discussions/$thread->id")->assertOk()->assertDontSee('Hosted at the archive');
        $this->postJson($review, $approval + $this->versions($event))->assertConflict();
    }

    public function test_linked_approval_requires_a_reviewer_reason_on_both_endpoints(): void
    {
        $event = $this->event($this->thread(User::factory()->create(), 'brandon'));
        $versioned = ['action' => 'approve'] + $this->versions($event);
        $workbench = self::MAIN."/admin/moderation/items/event/$event->id";
        $legacy = self::MAIN."/admin/community/events/$event->id";
        $this->actingAs(User::factory()->create(['role' => 'network_admin']));
        $this->postJson($workbench, $versioned)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($legacy, $versioned + ['reason' => '   '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertDatabaseCount('moderation_decisions', 0);
        $this->assertSame('pending', $event->fresh()->status);
        $this->post($workbench, $versioned + ['reason' => 'Confirmed the event information.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('moderation_decisions', ['type' => 'event', 'resource_id' => $event->id,
            'action' => 'approve', 'reason' => 'Confirmed the event information.']);
    }

    public function test_event_notices_link_only_to_eligible_discussions_or_public_legacy_details(): void
    {
        $owner = User::factory()->create();
        $thread = $this->thread($owner, 'brandon');
        $event = $this->event($thread, 'rejected');
        $legacy = $event->replicate()->forceFill(['thread_id' => null, 'status' => 'cancelled']);
        $legacy->save();
        NotificationInbox::record($owner->id, 'event.rejected', 8001, $event->id, [$event->location_id]);
        NotificationInbox::record($owner->id, 'event.cancelled', 8002, $legacy->id, [$legacy->location_id]);
        $notice = fn (int $source) => route('account.notifications.open', [
            'storage' => 'unified', 'notification' => DB::table('notifications')->where('source_id', $source)->value('id'),
        ], false);

        $this->actingAs($owner)->get(self::CITY.$notice(8001))->assertRedirect(self::CITY."/discussions/$thread->id");
        $this->get(self::CITY.$notice(8002))->assertRedirect(self::CITY."/events/$legacy->id");
        $this->actingAs(User::factory()->create())->get(self::CITY.$notice(8001))->assertNotFound();
        $this->actingAs($owner);
        $thread->forceFill(['status' => 'withdrawn'])->save();
        $this->get(self::CITY.$notice(8001))->assertNotFound();
        $this->get(self::CITY.'/account/notifications')->assertOk()->assertSee('Event rejected')->assertDontSee($thread->title);

        $thread->forceFill(['status' => 'approved'])->save();
        $settings = app(NetworkSettings::class);
        $settings->saveDraft($owner->id, function (array $draft): array {
            $draft['modules']['community'] = false;

            return $draft;
        });
        $settings->publish($owner->id);
        $this->get(self::CITY.$notice(8001))->assertNotFound();
        $this->get(self::CITY.$notice(8002))->assertRedirect(self::CITY."/events/$legacy->id");
        $this->get(self::CITY.'/events/new')->assertOk()->assertSee('Event requests are unavailable on this site.')
            ->assertDontSee('href="/discussions/new"', false);
        $this->postJson(self::CITY.'/events')->assertUnprocessable()
            ->assertJsonPath('errors.event.0', 'Event requests are unavailable on this site.');
    }

    public function test_workbench_edits_require_current_tokens_and_preserve_reported_details(): void
    {
        $event = $this->event($this->thread(User::factory()->create(), 'brandon'), 'approved');
        $event->update(['event_url' => 'https://tickets.example.test/reported']);
        $reporter = User::factory()->create();
        $this->actingAs($reporter)->post(self::CITY."/reports/event/$event->id", ['reason' => 'Please check the venue.'])->assertRedirect();
        $report = DB::table('content_reports')->sole();
        $reportUrl = self::MAIN."/admin/moderation/reports/event/$report->id";
        $this->get($reportUrl)->assertForbidden();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $path = self::MAIN."/admin/moderation/items/event/$event->id";
        $this->actingAs($admin)->get($path)->assertOk()->assertSee('Review source discussion')->assertSee('Edit event metadata');
        $input = ['action' => 'edit', 'reason' => 'The organizer moved the event.', 'venue' => 'The new hall',
            'organizer' => 'Archive volunteers', 'category' => 'community', 'event_url' => 'https://tickets.example.test/replacement',
            'starts_at' => now('America/Winnipeg')->addDay()->format('Y-m-d\TH:i')];
        $versions = $this->versions($event);
        $this->postJson($path, $input + ['version' => $versions['version']])->assertUnprocessable();
        $this->post($path, $input + $versions)->assertSessionHasNoErrors()->assertRedirect();
        $this->postJson($path, $input + $versions)->assertConflict();
        $this->assertDatabaseHas('community_events', ['id' => $event->id, 'venue' => 'The new hall', 'status' => 'pending']);
        $this->get($reportUrl)->assertOk()->assertSee('Reported event details')
            ->assertSee('Hosted at the archive')->assertDontSee('The new hall')
            ->assertSee('https://tickets.example.test/reported')->assertDontSee('https://tickets.example.test/replacement')
            ->assertDontSee('href="https://tickets.example.test/reported"', false);
        $this->assertDatabaseCount('content_reports', 1);
        $this->assertSame(1, DB::table('moderation_decisions')->where('action', 'edit')->count());
    }

    public function test_legacy_details_and_cancellation_keep_original_id_and_canonical(): void
    {
        $owner = User::factory()->create();
        $event = CommunityEvent::forceCreate([
            'author_id' => $owner->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Legacy independent event', 'body' => 'Original event details stay at their original address.',
            'status' => 'approved', 'category' => 'community', 'organizer' => 'Local club', 'venue' => 'The hall',
            'starts_at' => now('UTC')->addDay(), 'ends_at' => now('UTC')->addDay()->addHour(),
        ]);
        $path = "/events/$event->id";
        $this->get(self::MAIN.$path)->assertStatus(301)->assertRedirect(self::CITY.$path);
        $this->get(self::CITY.$path)->assertOk()->assertSee($event->body)->assertSee('"@type":"Event"', false);
        $this->actingAs($owner)->post(self::CITY."/account/events/$event->id/cancel", [
            'cancellation_note' => 'Specific private legacy note.',
        ])->assertRedirect('/account/events');
        $this->get(self::CITY.$path)->assertOk()->assertSee('Cancelled')->assertDontSee('"@type":"Event"', false)
            ->assertDontSee('Specific private legacy note.');
        $this->assertDatabaseHas('community_events', ['id' => $event->id, 'thread_id' => null, 'status' => 'cancelled', 'body' => $event->body]);
    }

    private function thread(User $owner, ?string $location, string $title = 'Local event discussion'): CommunityThread
    {
        return CommunityThread::forceCreate([
            'author_id' => $owner->id, 'location_id' => $location === null ? null : Location::where('slug', $location)->sole()->id,
            'title' => $title, 'body' => 'All event details are in this discussion.', 'category' => 'general', 'status' => 'approved',
        ]);
    }

    private function event(CommunityThread $thread, string $status = 'pending'): CommunityEvent
    {
        return CommunityEvent::forceCreate([
            'thread_id' => $thread->id, 'author_id' => $thread->author_id,
            'location_id' => $thread->location_id ?? Location::where('slug', 'brandon')->sole()->id,
            'title' => $thread->title, 'body' => $thread->body, 'status' => $status,
            'category' => 'community', 'organizer' => 'Archive volunteers', 'venue' => 'Hosted at the archive',
            'starts_at' => now('UTC')->addMinutes(20), 'ends_at' => now('UTC')->addHours(2),
        ]);
    }

    private function versions(CommunityEvent $event): array
    {
        $item = $event->fresh();

        return [
            'version' => ModerationDecision::version('event', $item),
            'thread_version' => ModerationDecision::version('thread', $item->thread),
        ];
    }
}

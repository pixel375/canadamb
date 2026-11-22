<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\Audit;
use App\Support\ModerationDecision;
use App\Support\NetworkSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OwnedDiscussionsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const MAIN = 'https://canadamb.ca';

    private const CITY = 'https://brandonmb.ca';

    private const OWN = '/account/discussions';

    public function test_owner_preview_and_listing_enforce_verification_and_location(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $local = $this->thread($owner, ['title' => 'Brandon garden draft']);
        $provincial = $this->thread($owner, ['location_id' => null, 'title' => 'Province-wide draft']);
        $outOfTown = $this->thread($owner, ['location_id' => $this->location('winnipeg'), 'title' => 'Winnipeg garden draft']);
        $this->thread($other, ['title' => 'Another member’s draft']);
        $preview = self::OWN."/$local->id/preview";

        $this->getJson(self::CITY.$preview)->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->getJson(self::CITY.$preview)->assertForbidden();
        $this->actingAs($other)->get(self::CITY.$preview)->assertNotFound();
        $this->actingAs($owner)->get(self::CITY.$preview)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee($local->title)->assertSee($local->body)
            ->assertDontSee('property="og:title" content="'.$local->title.'"', false)
            ->assertDontSee('rel="canonical"', false);
        $this->get(self::MAIN.$preview)->assertOk()->assertSee($local->title);
        $this->get(self::CITY.self::OWN."/$provincial->id/preview")->assertNotFound();
        $this->get(self::CITY.self::OWN."/$outOfTown->id/preview")->assertNotFound();
        $this->get(self::CITY.self::OWN)->assertOk()
            ->assertSee($local->title)->assertDontSee($provincial->title)->assertDontSee($outOfTown->title)
            ->assertDontSee('Another member’s draft')->assertDontSee($local->body);
        $this->get(self::MAIN.self::OWN)->assertOk()->assertSee($local->title)
            ->assertSee($provincial->title)->assertSee($outOfTown->title);
        $this->get(self::CITY."/discussions/$local->id")->assertNotFound();
    }

    public function test_owner_edit_and_moderator_share_the_required_fresh_revision(): void
    {
        $owner = User::factory()->create();
        $moderator = User::factory()->create(['role' => 'network_admin']);
        $item = $this->thread($owner, ['pinned' => true]);
        $path = self::CITY.self::OWN."/$item->id";
        $version = $this->version($item);
        $details = $this->postDetails($item, ['body' => 'An updated plan for the Saturday garden swap.']);
        $this->actingAs($owner)->get($path.'/edit')->assertOk()->assertSee($version);
        $this->put($path, $details)->assertSessionHasErrors('version');
        $this->moderate($moderator, $item, 'approve', $version)->assertRedirect();
        $this->actingAs($owner)->put($path, $details + ['version' => $version])->assertConflict();
        $this->assertSame('approved', $item->fresh()->status);

        $approvedVersion = $this->version($item);
        $this->put($path, $details + ['version' => $approvedVersion, 'location_id' => $this->location('winnipeg')])
            ->assertSessionHasErrors('location_id');
        $this->put($path, $details + ['version' => $approvedVersion])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertSame($details['body'], $item->fresh()->body);
        $this->assertTrue($item->fresh()->pinned);
        $this->get(self::CITY."/discussions/$item->id")->assertNotFound();
        $this->moderate($moderator, $item, 'approve', $approvedVersion)->assertConflict();

        $decision = DB::table('moderation_decisions')->where('type', 'thread')->where('resource_id', $item->id)
            ->where('action', 'author-edit')->sole();
        $audit = DB::table('audit_logs')->find($decision->audit_id);
        $this->assertSame($owner->id, (int) $audit->actor_id);
        $this->assertSame(['thread' => $item->id, 'previous_status' => 'approved', 'status' => 'pending'],
            json_decode($audit->context, true, flags: JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'event' => 'thread.pending', 'resource_id' => $item->id, 'source_id' => $audit->id]);
        $this->get(self::MAIN."/admin/moderation/items/thread/$item->id")->assertOk()->assertSee('Approved → Pending');
    }

    public function test_no_op_keeps_an_approved_thread_and_its_revision(): void
    {
        $owner = User::factory()->create();
        $item = $this->thread($owner, ['status' => 'approved', 'body' => "Bring spare seeds.\nWe will meet outside."]);
        $version = $this->version($item);
        $details = $this->postDetails($item, ['body' => "Bring spare seeds.\r\nWe will meet outside."]);
        $this->actingAs($owner)->put(self::MAIN.self::OWN."/$item->id", $details + ['version' => $version])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('approved', $item->fresh()->status);
        $this->assertSame($version, $this->version($item));
        $this->assertDatabaseCount('moderation_decisions', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_returning_a_draft_to_its_original_text_does_not_reuse_the_old_version(): void
    {
        $this->freezeSecond();
        $owner = User::factory()->create();
        $item = $this->thread($owner)->fresh();
        $url = self::CITY.self::OWN."/$item->id";
        $original = $this->version($item);
        $this->actingAs($owner)->put($url, $this->postDetails($item, ['title' => 'A revised garden swap']) + ['version' => $original])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->put($url, $this->postDetails($item) + ['version' => $this->version($item)])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($item->getRawOriginal(), $item->fresh()->getRawOriginal());
        $this->put($url, $this->postDetails($item) + ['version' => $original])->assertConflict();
        $this->assertDatabaseCount('moderation_decisions', 2);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_validation_redirect_only_refills_values_from_the_current_form_revision(): void
    {
        $owner = User::factory()->create();
        $item = $this->thread($owner, ['title' => 'Current garden discussion']);
        $path = self::CITY.self::OWN."/$item->id";
        $details = $this->postDetails($item, [
            'title' => 'Garden title from the previous form',
            'category' => 'incomplete-category',
        ]);
        $this->actingAs($owner)->from($path.'/edit')->put($path, $details + ['version' => str_repeat('0', 64)])
            ->assertSessionHasErrors('category');
        $this->get($path.'/edit')->assertOk()->assertSee($this->version($item))
            ->assertSee('value="Current garden discussion"', false)->assertDontSee($details['title']);

        $this->from($path.'/edit')->put($path, $details + ['version' => $this->version($item)])
            ->assertSessionHasErrors('category');
        $this->get($path.'/edit')->assertOk()->assertSee($details['title']);
    }

    public function test_rejected_threads_show_only_the_short_reason_and_require_a_material_revision(): void
    {
        $owner = User::factory()->create();
        $moderator = User::factory()->create(['role' => 'network_admin']);
        $item = $this->thread($owner, ['title' => 'Seed exchange under review']);
        $legacy = $this->thread($owner, ['status' => 'rejected', 'title' => 'Older greenway discussion']);
        Audit::record('community.thread.reject', $moderator->id, context: [
            'thread' => $legacy->id, 'reason' => 'Describe the route in a little more detail.',
        ]);
        $this->moderate($moderator, $item, 'reject', $this->version($item), [
            'reason' => 'Please add a meeting place.',
            'notes' => 'Internal moderation note.',
        ])->assertRedirect();
        $this->publishAutomaticThreadPolicy(User::factory()->create(['role' => 'super_admin']));

        $url = self::CITY.self::OWN;
        $this->actingAs($owner)->get($url)->assertOk()->assertSee('Rejected')->assertSee('Please add a meeting place.')
            ->assertSee('Describe the route in a little more detail.')->assertDontSee('Internal moderation note.');
        $this->get($url."/$item->id/preview")->assertOk()->assertSee('Please add a meeting place.')
            ->assertDontSee('Internal moderation note.');
        $this->get($url."/$legacy->id/preview")->assertOk()->assertSee('Describe the route in a little more detail.');
        $version = $this->version($item);
        $this->put($url."/$item->id", $this->postDetails($item) + ['version' => $version])->assertRedirect();
        $this->assertSame('rejected', $item->fresh()->status);
        $this->put($url."/$item->id", $this->postDetails($item, ['body' => 'We will meet outside the library.']) + ['version' => $version])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('pending', $item->fresh()->status);
        $this->get($url."/$item->id/preview")->assertOk()->assertDontSee('Please add a meeting place.');
    }

    public function test_approved_edits_can_publish_under_the_current_policy_with_their_pin(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $item = $this->thread($owner, ['status' => 'approved', 'pinned' => true]);
        $this->publishAutomaticThreadPolicy($admin);
        $this->actingAs($owner)->put(self::MAIN.self::OWN."/$item->id", $this->postDetails($item, ['title' => 'Updated garden swap'])
            + ['version' => $this->version($item)])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('approved', $item->fresh()->status);
        $this->assertTrue($item->fresh()->pinned);
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'event' => 'thread.approved', 'resource_id' => $item->id]);
        $this->assertDatabaseCount('notifications', 1);

        Site::where('code', 'brandon')->sole()->update(['overrides' => ['approval_policies' => ['thread' => 'manual_review']]]);
        $this->put(self::MAIN.self::OWN."/$item->id", $this->postDetails($item, ['title' => 'Garden swap moved to Sunday'])
            + ['version' => $this->version($item)])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('pending', $item->fresh()->status);
    }

    public function test_owner_withdraw_and_restore_preserve_evidence_and_require_review(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $moderator = User::factory()->create(['role' => 'network_admin']);
        $item = $this->thread($owner, ['status' => 'approved', 'pinned' => true]);
        $reply = $item->replies()->forceCreate(['author_id' => $reader->id, 'body' => 'I can bring more seeds.', 'status' => 'approved']);
        DB::table('community_thread_follows')->insert(['thread_id' => $item->id, 'user_id' => $reader->id]);
        DB::table('community_notifications')->insert(['user_id' => $reader->id, 'reply_id' => $reply->id, 'created_at' => now()]);
        $report = [
            'source_type' => 'thread', 'source_id' => $item->id, 'reporter_id' => $reader->id,
            'location_id' => $item->location_id, 'reason' => 'Please check the posted date.',
        ];
        DB::table('content_reports')->insert($report);
        $this->publishAutomaticThreadPolicy(User::factory()->create(['role' => 'super_admin']));

        $path = self::CITY.self::OWN."/$item->id";
        $version = $this->version($item);
        $this->actingAs($owner)->get(self::CITY.self::OWN)->assertOk()->assertSee('1 reply');
        $this->post($path.'/withdraw')->assertSessionHasErrors('version');
        $this->post($path.'/withdraw', ['version' => $version])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('withdrawn', $item->fresh()->status);
        $this->get(self::CITY.self::OWN)->assertOk()->assertSee('Withdrawn');
        $this->get(self::CITY."/discussions/$item->id")->assertNotFound();
        $this->get(self::CITY."/discussions/replies/$reply->id")->assertNotFound();
        $this->post($path.'/restore', ['version' => $version])->assertConflict();
        $this->post($path.'/restore', ['version' => $this->version($item)])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertTrue($item->fresh()->pinned);
        $this->assertDatabaseHas('content_reports', $report);
        $this->assertDatabaseHas('community_replies', ['id' => $reply->id, 'status' => 'approved']);
        $this->assertDatabaseHas('community_thread_follows', ['thread_id' => $item->id, 'user_id' => $reader->id]);
        $this->assertDatabaseHas('community_notifications', ['reply_id' => $reply->id, 'user_id' => $reader->id]);
        $this->assertSame(['thread.withdrawn', 'thread.pending'], DB::table('notifications')->orderBy('id')->pluck('event')->all());
        $this->moderate($moderator, $item, 'approve', $this->version($item))->assertRedirect();
        $this->get(self::CITY."/discussions/$item->id")->assertOk()->assertSee($reply->body);
    }

    public function test_locked_threads_cannot_be_changed_or_withdrawn_by_the_owner(): void
    {
        $owner = User::factory()->create();
        $item = $this->thread($owner, ['status' => 'approved', 'locked' => true, 'pinned' => true]);
        $path = self::CITY.self::OWN."/$item->id";
        $version = $this->version($item);
        $this->actingAs($owner)->get(self::CITY.self::OWN)->assertOk()->assertSee('Locked');
        $this->get($path.'/edit')->assertConflict();
        $this->put($path, $this->postDetails($item, ['title' => 'Updated title']) + ['version' => $version])->assertConflict();
        $this->post($path.'/withdraw', ['version' => $version])->assertConflict();
        $this->assertSame($version, $this->version($item));
    }

    public function test_moderation_preserves_the_text_behind_reports_when_a_thread_is_edited(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $moderator = User::factory()->create(['role' => 'network_admin']);
        $thread = $this->thread($owner, ['status' => 'approved', 'body' => 'Original public text that was reported.']);
        $report = [
            'source_type' => 'thread', 'source_id' => $thread->id, 'reporter_id' => $reader->id,
            'location_id' => $thread->location_id, 'reason' => 'Please review the original details.',
        ];
        // Simulate a report that already existed at the Pass 05 baseline.
        $first = DB::table('content_reports')->insertGetId($report);
        $url = self::CITY.self::OWN.'/'.$thread->id;
        $this->actingAs($owner)->put($url, $this->postDetails($thread, ['title' => 'New title', 'body' => 'Revised text for another review.'])
            + ['version' => $this->version($thread)])->assertRedirect();
        $this->moderate($moderator, $thread, 'approve', $this->version($thread))->assertRedirect();
        $this->actingAs(User::factory()->create())->post(self::CITY."/reports/thread/$thread->id", [
            'reason' => 'Please review these revised details.',
        ])->assertRedirect();
        $second = DB::table('content_reports')->max('id');
        $this->actingAs($owner)->put($url, $this->postDetails($thread->fresh(), ['body' => 'Third revision not submitted as evidence.'])
            + ['version' => $this->version($thread)])->assertRedirect();

        $prefix = self::MAIN.'/admin/moderation/reports/thread/';
        $this->get($prefix.$first)->assertForbidden();
        $this->actingAs($moderator)->get($prefix.$first)->assertOk()->assertSee($thread->title)->assertSee($thread->body)
            ->assertDontSee('Revised text for another review.')->assertDontSee('Third revision not submitted as evidence.');
        $this->get($prefix.$second)->assertOk()->assertSee('Revised text for another review.')->assertDontSee($thread->body);
        $this->assertDatabaseHas('content_reports', ['id' => $first] + $report);
        $this->assertDatabaseCount('content_reports', 2);
        $this->assertFalse(DB::table('audit_logs')->where('context', 'like', '%'.$thread->body.'%')->exists());
    }

    public function test_bulk_preview_label_and_version_use_the_same_resource_snapshot(): void
    {
        $thread = $this->thread(User::factory()->create(), ['title' => 'Title before concurrent editing']);
        $interleaved = false;
        DB::listen(function ($query) use ($thread, &$interleaved): void {
            if (! $interleaved && str_contains($query->sql, 'moderation_queue')) {
                $interleaved = true;
                CommunityThread::whereKey($thread->id)->update(['title' => 'Title after concurrent editing']);
            }
        });
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))
            ->post(self::MAIN.'/admin/moderation/bulk/preview', ['type' => 'thread', 'ids' => [$thread->id]])
            ->assertOk()->assertSee('Title after concurrent editing')->assertDontSee('Title before concurrent editing')
            ->assertSee($this->version($thread));
        $this->assertTrue($interleaved);
    }

    public function test_reply_review_token_changes_after_a_parent_edit_and_reapproval(): void
    {
        $owner = User::factory()->create();
        $moderator = User::factory()->create(['role' => 'network_admin']);
        $thread = $this->thread($owner, ['status' => 'approved']);
        $reply = $thread->replies()->create(['author_id' => User::factory()->create()->id, 'body' => 'A reply to the initial discussion.']);
        $stale = ModerationDecision::version('reply', $reply->fresh());
        $this->actingAs($owner)->put(self::MAIN.self::OWN.'/'.$thread->id,
            $this->postDetails($thread, ['body' => 'The discussion has been materially revised.']) + ['version' => $this->version($thread)])
            ->assertRedirect();
        $this->moderate($moderator, $thread, 'approve', $this->version($thread))->assertRedirect();
        $path = self::MAIN."/admin/community/replies/$reply->id";
        $this->post($path, ['action' => 'approve', 'version' => $stale])->assertConflict();
        $this->post($path, ['action' => 'approve', 'version' => ModerationDecision::version('reply', $reply->fresh())])->assertRedirect();
    }

    public function test_individual_review_uses_one_revision_for_heading_body_and_token(): void
    {
        $thread = $this->thread(User::factory()->create(), ['title' => 'Earlier review title', 'body' => 'Earlier reviewed discussion body.']);
        $oldVersion = $this->version($thread);
        $interleaved = false;
        DB::listen(function ($query) use ($thread, &$interleaved): void {
            if (! $interleaved && str_contains($query->sql, 'community_threads') && ! str_contains($query->sql, 'moderation_queue')) {
                $interleaved = true;
                CommunityThread::whereKey($thread->id)->update(['title' => 'Later review title', 'body' => 'Later reviewed discussion body.']);
            }
        });
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))
            ->get(self::MAIN.'/admin/moderation/items/thread/'.$thread->id)->assertOk()
            ->assertSee('Earlier review title')->assertSee('Earlier reviewed discussion body.')->assertSee($oldVersion)
            ->assertDontSee('Later review title')->assertDontSee('Later reviewed discussion body.');
        $this->assertTrue($interleaved);
    }

    public function test_approval_notices_survive_parent_withdrawal_without_a_public_link(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $moderator = User::factory()->create(['role' => 'network_admin']);
        $thread = $this->thread($owner);
        $this->moderate($moderator, $thread, 'approve', $this->version($thread))->assertRedirect();
        $reply = $thread->replies()->create(['author_id' => $reader->id, 'body' => 'A reply for the neighbourhood.']);
        $this->post(self::MAIN."/admin/community/replies/$reply->id", [
            'action' => 'approve', 'version' => ModerationDecision::version('reply', $reply->fresh()),
        ])->assertRedirect();
        $this->actingAs($owner)->post(self::CITY.self::OWN."/$thread->id/withdraw", ['version' => $this->version($thread)])
            ->assertRedirect();
        $settings = app(NetworkSettings::class);
        $settings->saveDraft($moderator->id, function (array $draft): array {
            $draft['modules']['community'] = false;

            return $draft;
        });
        $settings->publish($moderator->id);
        $notices = '/account/notifications?category=discussions&status=unread';
        $this->get(self::CITY.$notices)->assertOk()->assertSee('Discussion approved')->assertSee('Discussion withdrawn')->assertDontSee('View item');
        $this->actingAs($reader)->get(self::CITY.$notices)->assertOk()->assertSee('Discussion reply approved')->assertDontSee('View item');
    }

    private function thread(User $author, array $attributes = []): CommunityThread
    {
        return CommunityThread::forceCreate($attributes + [
            'author_id' => $author->id, 'location_id' => $this->location('brandon'),
            'title' => 'Neighbourhood garden swap', 'body' => 'Bring spare seeds to our neighbourhood garden swap.',
            'category' => 'general', 'last_activity_at' => now(),
        ]);
    }

    private function location(string $slug): int
    {
        return Location::where('slug', $slug)->sole()->id;
    }

    private function version(CommunityThread $item): string
    {
        return ModerationDecision::version('thread', $item->fresh());
    }

    private function postDetails(CommunityThread $item, array $changes = []): array
    {
        return $changes + $item->only('title', 'body', 'category');
    }

    private function moderate(User $moderator, CommunityThread $item, string $action, string $version, array $additional = []): TestResponse
    {
        return $this->actingAs($moderator)->post(self::MAIN."/admin/community/threads/$item->id", $additional + compact('action', 'version'));
    }

    private function publishAutomaticThreadPolicy(User $admin): void
    {
        $settings = app(NetworkSettings::class);
        $settings->saveDraft($admin->id, function (array $draft): array {
            $draft['approval_policies']['thread'] = 'auto_approve';

            return $draft;
        });
        $settings->publish($admin->id, expectedRevision: (int) $settings->state()->draft_id);
    }
}

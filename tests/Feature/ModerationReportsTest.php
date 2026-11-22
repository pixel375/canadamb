<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessUpdate;
use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Conversation;
use App\Models\JobPosting;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use App\Support\Audit;
use App\Support\NetworkSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModerationReportsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const MAIN = 'https://canadamb.ca';

    private const CITY = 'https://brandonmb.ca';

    private const REPORTS = self::MAIN.'/admin/moderation/reports';

    public function test_verified_visitors_can_report_each_public_source_once_without_hiding_it(): void
    {
        $author = User::factory()->create();
        $reader = User::factory()->create();
        [$business, $branch] = $this->business($author);
        $job = JobPosting::forceCreate([
            'business_location_id' => $branch->id, 'author_id' => $author->id, 'title' => 'Part-time assistant',
            'description' => 'Help the local team.', 'employment_type' => 'part-time', 'workplace' => 'on-site',
            'apply_url' => 'https://example.test/jobs', 'closing_on' => now()->addWeek()->toDateString(),
            'status' => 'approved', 'published_at' => now(),
        ]);
        $update = BusinessUpdate::create([
            'business_location_id' => $branch->id, 'author_id' => $author->id, 'title' => 'Extended opening hours',
            'body' => 'We are open later this week.', 'type' => 'announcement', 'status' => 'approved', 'published_at' => now(),
        ]);
        $thread = $this->thread($author);
        $reply = $thread->replies()->forceCreate(['author_id' => $author->id, 'body' => 'A public reply about the garden.', 'status' => 'approved']);
        $event = CommunityEvent::forceCreate([
            'author_id' => $author->id, 'location_id' => $branch->location_id, 'title' => 'Garden club meeting',
            'body' => 'Join the neighbourhood garden group.', 'category' => 'community', 'organizer' => 'Garden club',
            'venue' => 'Community hall', 'starts_at' => now()->addWeek(), 'status' => 'approved',
        ]);
        $sources = [
            'business' => [$business, '/businesses/'.$business->slug, 'active'],
            'business-update' => [$update, '/business-updates/'.$update->id, 'approved'],
            'job' => [$job, '/jobs/'.$job->id, 'approved'],
            'thread' => [$thread, '/community/'.$thread->id, 'approved'],
            'reply' => [$reply, '/community/'.$thread->id, 'approved'],
            'event' => [$event, '/events/'.$event->id, 'approved'],
        ];
        $reason = 'The information in this submission needs review.';

        $this->actingAs($reader);
        foreach ($sources as $type => [$source, $path, $status]) {
            $action = "/reports/$type/$source->id";
            $this->get(self::CITY.$path)->assertOk()->assertSee($action);
            $submitted = $this->post(self::CITY.$action, ['reason' => $reason])->assertRedirect()->assertSessionHasNoErrors();
            if ($type === 'reply') {
                $submitted->assertRedirect(self::CITY."/community/$thread->id?page=1#reply-$reply->id");
            }
            $this->post(self::CITY.$action, ['reason' => 'This duplicate should leave the original alone.'])
                ->assertRedirect()->assertSessionHas('status', 'You have already reported this content.');
            $this->assertDatabaseHas('content_reports', [
                'source_type' => $type, 'source_id' => $source->id, 'reporter_id' => $reader->id, 'reason' => $reason,
            ]);
            $this->assertSame($status, $source->fresh()->status);
            // The throttle is shared across report targets. Each loop represents a new time window.
            $this->travel(61)->seconds();
        }
        $this->assertDatabaseCount('content_reports', 6);
        $this->assertDatabaseCount('notifications', 0);
        $this->get(self::CITY.'/community/'.$thread->id)->assertDontSee($reason);
    }

    public function test_provincial_business_report_covers_all_its_locations_and_city_report_stays_local(): void
    {
        [$business, $brandon] = $this->business(User::factory()->create());
        $winnipeg = Location::where('slug', 'winnipeg')->sole()->id;
        $elkhorn = Location::where('slug', 'elkhorn')->sole()->id;
        $business->locations()->create(['location_id' => $winnipeg, 'address' => '2 Fixture Road']);
        $provincial = User::factory()->create();
        $local = User::factory()->create();
        $path = "/reports/business/$business->id";
        $data = ['reason' => 'Please review this business profile.'];
        $this->actingAs($provincial)->post(self::MAIN.$path, $data)->assertRedirect();
        $this->post(self::CITY.$path, $data)->assertRedirect()->assertSessionHas('status', 'You have already reported this content.');
        $this->actingAs($local)->post(self::CITY.$path, $data)->assertRedirect();
        $reports = DB::table('content_reports')->pluck('location_id', 'reporter_id');
        $this->assertNull($reports[$provincial->id]);
        $this->assertSame($brandon->location_id, $reports[$local->id]);
        $this->actingAs(User::factory()->create(['role' => 'network_admin']));
        foreach ([$brandon->location_id => 2, $winnipeg => 1, $elkhorn => 0] as $location => $total) {
            $view = $this->get(self::REPORTS."?type=business&location=$location")->assertOk();
            $this->assertSame($total, $view->viewData('queue')->total());
        }
        $this->assertDatabaseCount('content_reports', 2);
    }

    public function test_public_reports_obey_location_verification_and_visibility(): void
    {
        $author = User::factory()->create();
        $reader = User::factory()->create();
        $thread = $this->thread($author);
        $outside = $this->thread($author, 'winnipeg');
        $path = self::CITY."/reports/thread/$thread->id";
        $input = ['reason' => 'Please review this discussion.'];

        $this->actingAs(User::factory()->unverified()->create())->post($path, $input)->assertRedirect(self::MAIN.'/email/verify');
        $this->actingAs($reader)->post(self::CITY."/reports/thread/$outside->id", $input)->assertNotFound();
        $this->actingAs($author)->post($path, $input)->assertForbidden();
        $thread->forceFill(['status' => 'pending'])->save();
        $this->actingAs($reader)->post($path, $input)->assertNotFound();
        $thread->forceFill(['status' => 'approved'])->save();

        $settings = app(NetworkSettings::class);
        $settings->saveDraft($author->id, function (array $draft): array {
            $draft['modules']['community'] = false;

            return $draft;
        });
        $settings->publish($author->id);
        $this->post($path, $input)->assertNotFound();
        $this->assertDatabaseCount('content_reports', 0);
    }

    public function test_public_reporting_is_rate_limited_across_target_ids(): void
    {
        $author = User::factory()->create();
        $this->actingAs(User::factory()->create());
        for ($index = 0; $index < 9; $index++) {
            $thread = $this->thread($author);
            $response = $this->post(self::CITY."/reports/thread/$thread->id", ['reason' => 'Please review this discussion.']);
            if ($index === 8) {
                $response->assertTooManyRequests();
            } else {
                $response->assertRedirect();
            }
        }
        $this->assertDatabaseCount('content_reports', 8);
    }

    public function test_report_queue_limits_conversation_evidence_to_the_exact_audited_message(): void
    {
        $reporter = User::factory()->create(['name' => 'Masked Reporter Name']);
        $sender = User::factory()->create(['role' => 'network_admin']);
        $admin = User::factory()->create(['role' => 'network_admin']);
        $listing = $this->listing($sender);
        $conversation = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $reporter->id, 'seller_id' => $sender->id]);
        $conversation->messages()->create(['sender_id' => $sender->id, 'body' => 'Unrelated private message']);
        $reported = $conversation->messages()->create(['sender_id' => $sender->id, 'body' => 'Quoted message <example>']);
        DB::table('message_attachments')->insert([
            'message_id' => $reported->id, 'path' => 'inert-private-fixture.pdf', 'original_name' => 'private-document.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 1,
        ]);
        $this->actingAs($reporter)->post(self::CITY."/account/messages/$conversation->id/report", [
            'message_id' => $reported->id, 'reason' => 'Please privately review this reported message.',
        ])->assertRedirect();
        $report = DB::table('conversation_reports')->sole();
        $path = self::REPORTS."/conversation/$report->id";
        $evidence = $path.'/evidence';

        $this->get($path)->assertForbidden();
        $this->post($evidence)->assertForbidden();
        $this->actingAs(User::factory()->unverified()->create(['role' => 'network_admin']))->post($evidence)->assertRedirect(self::MAIN.'/email/verify');
        $this->actingAs($sender)->get(self::MAIN.'/admin/audit')->assertOk()->assertDontSee($reporter->name);
        $this->post($evidence)->assertForbidden();
        $this->actingAs($admin)->post(self::CITY."/admin/moderation/reports/conversation/$report->id/evidence")->assertNotFound();
        $this->get($evidence)->assertStatus(405);
        $this->get(self::REPORTS)->assertOk()->assertSee('Conversation')->assertDontSee('Quoted message')->assertDontSee($report->reason);
        $this->get($path)->assertOk()->assertDontSee($reporter->name)->assertDontSee('Quoted message')->assertDontSee($report->reason);
        $this->post($evidence)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee($reported->body)->assertDontSee($reported->body, escape: false)
            ->assertDontSee('Unrelated private message')->assertDontSee('private-document.pdf')->assertDontSee($reporter->name);
        $this->get(self::MAIN."/admin/conversation/$conversation->id")->assertNotFound();
        $this->get(self::MAIN."/account/messages/$conversation->id/attachments/1")->assertNotFound();
        $entry = DB::table('audit_logs')->where('action', 'moderation.report-evidence-viewed')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame(['type' => 'conversation', 'report' => $report->id, 'message' => $reported->id], json_decode($entry->context, true));

        $reported->delete();
        $this->post($evidence)->assertOk()->assertDontSee($reported->body)->assertDontSee('Unrelated private message');
        $this->assertDatabaseCount('conversation_reports', 1);
    }

    public function test_evidence_from_a_revoked_business_representative_cannot_be_revealed(): void
    {
        $visitor = User::factory()->create();
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        [$business, $branch] = $this->business($owner);
        $thread = Conversation::create([
            'context_type' => 'business', 'business_location_id' => $branch->id, 'location_id' => $branch->location_id,
            'buyer_id' => $visitor->id, 'seller_id' => $owner->id,
        ]);
        $message = $thread->messages()->create(['sender_id' => $visitor->id, 'body' => 'Private initial business message.']);
        $public = self::CITY."/account/messages/$thread->id/report";
        $data = ['message_id' => $message->id, 'reason' => 'Review this business message privately.'];
        $this->actingAs($owner)->post($public, $data)->assertRedirect();
        $report = DB::table('conversation_reports')->sole();
        $business->members()->detach($owner->id);
        $this->post($public, $data)->assertNotFound();

        $path = self::REPORTS."/conversation/$report->id";
        $this->actingAs($admin)->get($path)->assertOk()->assertDontSee($message->body);
        $this->post($path.'/evidence')->assertNotFound();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'moderation.report-evidence-viewed']);
    }

    public function test_legacy_listing_report_uses_the_same_versioned_decision_as_the_queue(): void
    {
        $listing = $this->listing(User::factory()->create());
        $reporter = User::factory()->create(['name' => 'Confidential Reporter']);
        $admin = User::factory()->create(['role' => 'network_admin']);
        $id = DB::table('listing_reports')->insertGetId([
            'listing_id' => $listing->id, 'reporter_id' => $reporter->id, 'reason' => 'A listing concern needing review.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $path = self::REPORTS."/listing/$id";
        $page = $this->actingAs($admin)->get($path)->assertOk()->assertDontSee($reporter->name);
        $version = $this->versionFrom($page->getContent());
        $legacy = self::MAIN."/admin/moderation/reports/$id";
        $this->postJson($legacy, ['note' => 'Review completed.'])->assertUnprocessable();
        $this->post($legacy, ['version' => str_repeat('a', 64), 'note' => 'Review completed.'])->assertConflict();
        $this->post($path.'/decision', ['version' => $version, 'decision' => 'dismissed', 'note' => 'Duplicate of a resolved case.'])->assertRedirect($path);
        $this->post($legacy, ['version' => $version, 'note' => 'This must not overwrite a decision.'])->assertConflict();
        $this->get($path)->assertSee('Dismissed')->assertSee('Duplicate of a resolved case.');
        $this->get(self::REPORTS.'?status=dismissed&type=listing')->assertSee($listing->title);
        $this->get(self::REPORTS)->assertDontSee($listing->title);
        $this->assertNotNull(DB::table('listing_reports')->where('id', $id)->value('resolved_at'));
        $this->assertDatabaseCount('moderation_report_decisions', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame('active', $listing->fresh()->status);
    }

    public function test_preexisting_listing_resolution_keeps_the_original_note_and_reviewer(): void
    {
        $listing = $this->listing(User::factory()->create());
        $admin = User::factory()->create(['role' => 'network_admin', 'name' => 'Original Reviewer']);
        $id = DB::table('listing_reports')->insertGetId([
            'listing_id' => $listing->id, 'reason' => 'Historic listing concern.',
            'created_at' => now()->subMonth(), 'updated_at' => now(), 'resolved_at' => now(),
        ]);
        Audit::record('listing.report-resolved', $admin->id, context: ['report' => $id, 'note' => 'Original review note.']);

        $this->actingAs($admin)->get(self::REPORTS."/listing/$id")->assertOk()
            ->assertSee('Resolved')->assertSee('Original review note.')->assertSee($admin->name)->assertDontSee('Resolve report');
        $this->assertDatabaseCount('moderation_report_decisions', 0);
    }

    public function test_report_queue_paginates_and_filters_using_safe_metadata(): void
    {
        $author = User::factory()->create();
        $brandon = $this->thread($author);
        $winnipeg = $this->thread($author, 'winnipeg');
        $items = [];
        for ($index = 0; $index < 27; $index++) {
            $items[] = [
                'source_type' => 'thread', 'source_id' => $brandon->id, 'location_id' => $brandon->location_id,
                'reason' => 'Private reason used only in review.', 'created_at' => $index === 26 ? '2026-02-01 12:00:00' : '2026-01-01 12:00:00',
            ];
        }
        $items[] = [
            'source_type' => 'thread', 'source_id' => $winnipeg->id, 'location_id' => $winnipeg->location_id,
            'reason' => 'Private reason used only in review.', 'created_at' => '2026-01-01 12:00:00',
        ];
        DB::table('content_reports')->insert($items);
        $path = self::REPORTS.'?type=thread&location='.$brandon->location_id.'&from=2026-01-01&until=2026-01-01&q=Neighbourhood';
        $this->actingAs(User::factory()->create(['role' => 'network_admin']));
        $page = $this->get($path)->assertOk()->assertDontSee('Private reason used only in review.')->viewData('queue');
        $this->assertSame(26, $page->total());
        $this->assertCount(25, $page->items());
        $this->assertStringContainsString('until=2026-01-01', $page->nextPageUrl());
        $this->assertCount(1, $this->get($path.'&page=2')->assertOk()->viewData('queue')->items());
        $this->get(self::REPORTS.'?q=Private+reason')->assertOk()->assertSee('No reports match these filters.');
    }

    public function test_batch_resolves_only_eligible_previewed_reports_and_reports_partial_results(): void
    {
        $author = User::factory()->create();
        $reader = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $this->actingAs($reader);
        for ($index = 0; $index < 3; $index++) {
            $thread = $this->thread($author);
            $this->post(self::CITY."/reports/thread/$thread->id", ['reason' => 'Please review this discussion.'])->assertRedirect();
        }
        [$first, $second, $third] = DB::table('content_reports')->orderBy('id')->pluck('id')->all();
        $previewPath = self::REPORTS.'/bulk/preview';
        $resolvePath = self::REPORTS.'/bulk/resolve';
        $preview = $this->actingAs($admin)->post($previewPath, ['type' => 'thread', 'items' => [$first, $second, $third]])
            ->assertOk()->assertSee('Report #'.$first)->assertSee('Report #'.$second)->assertSee('Report #'.$third)
            ->viewData('preview');
        $previewed = array_map(static fn (array $row): array => [
            'id' => $row['id'], 'version' => $row['version'], 'proof' => $row['proof'] ?? str_repeat('0', 64),
        ], $preview);
        $decisionPath = self::REPORTS."/thread/$second/decision";
        $this->post($decisionPath, ['decision' => 'dismissed', 'note' => 'Reviewed by another reviewer.', 'version' => $previewed[1]['version']])
            ->assertRedirect();
        $previewed[2]['proof'] = str_repeat('0', 64);

        $this->post($resolvePath, ['type' => 'thread', 'note' => 'Reviewed together.', 'items' => [...$previewed, ['id' => 'bad']]])
            ->assertOk()->assertSee('1 of 4 reports resolved')->assertSee('already has a decision')
            ->assertSee('Preview this report again')->assertSee('Invalid report ID or version');
        $this->post($resolvePath, ['type' => 'thread', 'note' => 'Replayed batch.', 'items' => [$previewed[0]]])
            ->assertOk()->assertSee('0 of 1 reports resolved');
        $this->assertDatabaseHas('moderation_report_decisions', ['report_id' => $first, 'decision' => 'resolved']);
        $this->assertDatabaseHas('moderation_report_decisions', ['report_id' => $second, 'decision' => 'dismissed']);
        $this->assertDatabaseMissing('moderation_report_decisions', ['report_id' => $third]);
        $this->assertDatabaseCount('moderation_report_decisions', 2);
    }

    private function versionFrom(string $html): string
    {
        $this->assertSame(1, preg_match('/name="version" value="([a-f0-9]{64})"/', $html, $parts));

        return $parts[1];
    }

    private function thread(User $author, string $location = 'brandon'): CommunityThread
    {
        return CommunityThread::forceCreate([
            'author_id' => $author->id, 'location_id' => Location::where('slug', $location)->sole()->id,
            'title' => 'Neighbourhood planting project', 'body' => 'We are sharing garden plans.', 'category' => 'general',
            'status' => 'approved',
        ]);
    }

    private function listing(User $seller): Listing
    {
        return Listing::forceCreate([
            'user_id' => $seller->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Light fixture for sale', 'description' => 'Previously posted in Manitoba.',
            'category' => 'home', 'condition' => 'good', 'price_cents' => 1200, 'status' => 'active',
            'published_at' => now()->subWeek(), 'expires_at' => now()->addWeek(),
        ]);
    }

    private function business(User $owner): array
    {
        $business = Business::create(['slug' => 'neighbourhood-shop', 'name' => 'Neighbourhood shop', 'category' => 'retail', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $branch = $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Test Avenue']);

        return [$business, $branch];
    }
}

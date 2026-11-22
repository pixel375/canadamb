<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessUpdate;
use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\JobPosting;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use App\Support\ModerationDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModerationWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const ROOT = 'https://canadamb.ca/admin/moderation';

    public function test_queue_and_versions_require_central_verified_administration(): void
    {
        $member = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $thread = $this->thread($member, 'brandon', 'Local candidate');
        $this->thread($member, 'elkhorn', 'Rural candidate');
        $url = self::ROOT."/items/thread/$thread->id";
        $this->actingAs($member)->get(self::ROOT)->assertForbidden();
        $this->postJson($url, ['action' => 'approve'])->assertForbidden();
        $this->actingAs($reviewer)->get('https://brandonmb.ca/admin/moderation')->assertNotFound();
        $this->get(self::ROOT.'?type=thread&location='.$thread->location_id)->assertOk()
            ->assertSee('Local candidate')->assertDontSee('Rural candidate');
        $version = ModerationDecision::version('thread', $thread->fresh());
        $this->get($url)->assertOk()->assertSee($version)->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($url, ['action' => 'approve'])->assertUnprocessable();
        $this->postJson($url, ['action' => 'approve', 'version' => ['invalid']])->assertUnprocessable();
        $this->postJson($url, ['action' => 'approve', 'version' => str_repeat('a', 64)])->assertConflict();
        $this->assertDatabaseCount('moderation_decisions', 0);
        $this->post($url, ['action' => 'approve', 'version' => $version, 'notes' => 'Reviewer-only note'])->assertRedirect($url);
        $this->postJson($url, ['action' => 'approve', 'version' => $version])->assertConflict();
        $this->get($url)->assertOk()->assertSee('Reviewer-only note')->assertSee($reviewer->name);
        $this->assertDatabaseCount('moderation_decisions', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->actingAs($member)->get('https://canadamb.ca/account/notifications')->assertOk()->assertDontSee('Reviewer-only note');
    }

    public function test_bulk_preview_preserves_versions_and_applies_only_eligible_items(): void
    {
        $member = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'super_admin']);
        $first = $this->thread($member, 'brandon', 'First entry');
        $second = $this->thread($member, 'elkhorn', 'Second entry');
        $items = $this->actingAs($reviewer)->post(self::ROOT.'/bulk/preview', ['type' => 'thread', 'ids' => [$first->id, $second->id]])
            ->assertOk()->assertSee('First entry')->assertSee('Second entry')
            ->assertSee(ModerationDecision::version('thread', $first->fresh()))->viewData('items');
        $second->forceFill(['body' => 'Modified since the preview.'])->save();
        $items[] = ['id' => 999999, 'version' => 'bad token'];
        $this->post(self::ROOT.'/bulk', ['type' => 'thread', 'items' => $items, 'action' => 'reject',
            'reason' => 'Please revise your submission.', 'notes' => 'Sensitive internal reasoning.'])
            ->assertOk()->assertSee('1 decision saved')->assertSee('Changed or no longer eligible')->assertSee('Invalid review version');
        $this->assertSame('rejected', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertDatabaseCount('notifications', 1);
        $this->actingAs($member)->get('https://canadamb.ca/account/notifications')->assertOk()
            ->assertSee('Please revise your submission.')->assertDontSee('Sensitive internal reasoning.');
    }

    public function test_legacy_listing_action_and_preview_use_the_original_photo_version(): void
    {
        $seller = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $listing = Listing::forceCreate([
            'user_id' => $seller->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'An older listing', 'description' => 'This listing uses the original workflow.',
            'category' => 'home', 'condition' => 'good', 'price_cents' => 100, 'status' => 'pending',
        ]);
        $this->actingAs($reviewer)->get(self::ROOT.'/marketplace')->assertOk()->assertSee($listing->title);
        $this->get(self::ROOT."/items/listing/$listing->id")->assertOk()->assertSee($listing->title);
        $this->postJson(self::ROOT.'/'.$listing->id, ['status' => 'active'])->assertUnprocessable();
        $this->post(self::ROOT.'/'.$listing->id, ['status' => 'active', 'version' => ModerationDecision::version('listing', $listing->fresh())])->assertRedirect();
        $this->assertDatabaseCount('moderation_decisions', 1);
        $this->assertDatabaseCount('listing_status_history', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_all_submission_types_have_working_details_and_source_scope(): void
    {
        $owner = User::factory()->create();
        $claimant = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $location = Location::where('slug', 'brandon')->sole()->id;
        $rural = Location::where('slug', 'elkhorn')->sole()->id;
        $business = Business::create(['name' => 'Test supplier', 'slug' => 'test-supplier', 'category' => 'retail', 'status' => 'active']);
        $branch = $business->locations()->create(['location_id' => $location, 'address' => '1 Test Street']);
        $business->locations()->create(['location_id' => $rural, 'address' => '2 Test Road']);
        $claim = DB::table('business_claims')->insertGetId([
            'business_id' => $business->id, 'user_id' => $claimant->id, 'contact_email' => 'claim@example.test',
            'evidence' => 'Private ownership evidence fixture.', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $update = BusinessUpdate::create(['business_location_id' => $branch->id, 'author_id' => $owner->id,
            'type' => 'announcement', 'title' => 'Supplier update', 'body' => 'Expanded hours.']);
        $job = JobPosting::create(['business_location_id' => $branch->id, 'author_id' => $owner->id,
            'title' => 'Hiring clerk', 'description' => 'Work with the team.', 'employment_type' => 'full-time',
            'workplace' => 'on-site', 'apply_url' => 'https://example.test/apply', 'closing_on' => today()->addWeek()]);
        $thread = $this->thread($owner, 'brandon', 'Local thread');
        $reply = $thread->replies()->create(['author_id' => $owner->id, 'body' => 'A proposed reply.']);
        $event = CommunityEvent::create(['author_id' => $owner->id, 'location_id' => $rural, 'title' => 'Rural event',
            'body' => 'A proposed gathering.', 'venue' => 'Town hall', 'organizer' => 'Community volunteers',
            'category' => 'community', 'starts_at' => now()->addWeek()]);
        $this->actingAs($reviewer)->get(self::ROOT."?status=all&location=$location")->assertOk()
            ->assertSee('Test supplier')->assertSee('Hiring clerk')->assertSee('Supplier update')->assertDontSee('Rural event')
            ->assertDontSee($claimant->email)->assertDontSee('Private ownership evidence fixture.');
        $this->get(self::ROOT."?status=all&location=$rural")->assertOk()
            ->assertSee('Test supplier')->assertSee('Rural event')->assertDontSee('Hiring clerk');
        foreach (['business' => $business->id, 'claim' => $claim, 'business-update' => $update->id,
            'job' => $job->id, 'thread' => $thread->id, 'reply' => $reply->id, 'event' => $event->id] as $type => $id) {
            $this->get(self::ROOT."/items/$type/$id")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $this->postJson(self::ROOT."/items/$type/$id", ['action' => 'approve'])->assertUnprocessable();
        }
        $this->get(self::ROOT."/items/claim/$claim")->assertSee('Private ownership evidence fixture.');
        $this->postJson(self::ROOT."/items/reply/$reply->id", ['action' => 'approve',
            'version' => ModerationDecision::version('reply', $reply->fresh())])->assertConflict();
        $this->postJson(self::ROOT."/items/business-update/$update->id", [
            'action' => 'approve', 'notes' => 'Checked the update.', 'version' => ModerationDecision::version('business-update', $update->fresh()),
        ])->assertConflict();
        $this->postJson(self::ROOT.'/bulk/preview', ['type' => 'claim', 'ids' => [$claim]])->assertUnprocessable();
        $this->assertDatabaseCount('moderation_decisions', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_filtered_pagination_preserves_parameters_and_csrf_remains_required(): void
    {
        $member = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $this->travelTo(now()->subDays(2));
        $place = $this->thread($member, 'brandon', 'Older discussion');
        $this->travelBack();
        for ($i = 0; $i < 21; $i++) {
            $thread = $this->thread($member, 'brandon', "New discussion $i");
        }
        $filter = '?type=thread&location='.$place->location_id.'&q=discussion&priority=newest';
        $this->actingAs($reviewer)->get(self::ROOT.$filter)->assertOk()
            ->assertSee('New discussion 20')->assertDontSee('Older discussion')->assertSee('priority=newest');
        $this->get(self::ROOT.$filter.'&page=2')->assertOk()->assertSee('Older discussion');
        $this->get(self::ROOT.$filter.'&from='.today('America/Winnipeg')->toDateString().'&page=2')->assertOk()
            ->assertDontSee('Older discussion');

        $this->app->instance('env', 'production');
        $this->post(self::ROOT."/items/thread/$thread->id", [
            'action' => 'approve', 'version' => ModerationDecision::version('thread', $thread->fresh()), '_token' => 'invalid',
        ])->assertStatus(419);
        $this->assertDatabaseCount('moderation_decisions', 0);
    }

    private function thread(User $member, string $location, string $title): CommunityThread
    {
        return CommunityThread::create([
            'author_id' => $member->id, 'location_id' => Location::where('slug', $location)->sole()->id,
            'title' => $title, 'body' => 'A submitted discussion.', 'category' => 'general',
        ]);
    }
}

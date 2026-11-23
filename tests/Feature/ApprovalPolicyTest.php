<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CommunityThread;
use App\Models\Listing;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\ListingPhotoOrder;
use App\Support\ModerationDecision;
use App\Support\NetworkSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApprovalPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const MAIN = 'https://canadamb.ca';

    private const CITY = 'https://brandonmb.ca';

    private const SETTINGS = self::MAIN.'/admin/settings';

    public function test_only_a_previewed_super_admin_revision_changes_new_approvals(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $moderator = User::factory()->create(['role' => 'network_admin']);
        $original = app(NetworkSettings::class)->state()->published_id;

        $this->actingAs($owner)->post(self::CITY.'/community', $this->thread('Default'))->assertRedirect();
        $this->actingAs($moderator)->post(self::SETTINGS, ['approval_policies' => ['thread' => 'auto_approve']])->assertForbidden();
        $this->post(self::SETTINGS.'/publish', ['revision' => $original])->assertForbidden();
        $this->post(self::SETTINGS.'/rollback', ['revision' => $original])->assertForbidden();
        $this->actingAs($admin)->post(self::CITY.'/admin/settings', ['approval_policies' => ['thread' => 'auto_approve']])->assertNotFound();

        $draft = $this->savePolicy($admin, ['thread' => 'auto_approve']);
        $this->get(self::SETTINGS)->assertOk()->assertSee('Draft auto-approval preview')->assertSee('Discussion posts');
        $this->actingAs($owner)->post(self::CITY.'/community', $this->thread('Draft'))->assertRedirect();
        $this->assertDatabaseHas('community_threads', ['title' => 'Draft', 'status' => 'pending']);
        $this->actingAs($admin)->post(self::SETTINGS.'/publish', ['revision' => $original])->assertConflict();
        $this->post(self::MAIN.'/admin/theme/publish')->assertSessionHasErrors('revision');
        $this->post(self::SETTINGS.'/publish', ['revision' => $draft])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($owner)->post(self::CITY.'/community', $this->thread('Published'))->assertRedirect();
        $thread = CommunityThread::where('title', 'Published')->sole();
        $this->assertSame('approved', $thread->status);
        $this->assertNotNull($thread->last_activity_at);
        $audit = DB::table('audit_logs')->where('action', 'community.thread.approve')->sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame($draft, json_decode($audit->context, true)['approval_policy_revision']);
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'event' => 'thread.approved', 'resource_id' => $thread->id, 'source_id' => $audit->id]);

        $this->actingAs($admin)->post(self::SETTINGS.'/rollback', ['revision' => $original])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($owner)->post(self::CITY.'/community', $this->thread('Rolled back'))->assertRedirect();
        foreach (['Default', 'Draft', 'Rolled back'] as $title) {
            $this->assertDatabaseHas('community_threads', ['title' => $title, 'status' => 'pending']);
        }
        $this->assertDatabaseHas('community_threads', ['title' => 'Published', 'status' => 'approved']);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'configuration.rollback', 'actor_id' => $admin->id]);
    }

    public function test_local_policy_narrows_the_network_even_on_central_submissions(): void
    {
        $site = Site::where('code', 'brandon')->sole();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $moderator = User::factory()->create(['role' => 'network_admin']);
        $owner = User::factory()->create();
        $url = self::MAIN.'/admin/communities/'.$site->id;
        $portal = [
            'name' => $site->name, 'description' => $site->description, 'accent_type' => 'solid',
            'accent_colors' => '#22A06B', 'accent_thickness' => 4, 'accent_direction' => 90,
        ];
        $this->publishPolicy($admin, ['thread' => 'auto_approve']);
        $this->put($url, $portal + ['approval_policies' => ['thread' => 'auto_approve']])->assertSessionHasErrors('approval_policies.thread');
        $this->put($url, $portal + ['approval_policies' => ['thread' => 'manual_review']])->assertSessionHasNoErrors()->assertRedirect();
        $this->put($url, $portal + ['approval_policies' => ['reply' => 'manual_review']])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($moderator)->put($url, $portal + ['approval_policies' => ['thread' => 'inherit']])->assertForbidden();
        $this->put($url, $portal)->assertRedirect();

        $this->actingAs($owner)->post(self::CITY.'/community', $this->thread('Local review'))->assertSessionHasNoErrors();
        $this->post(self::MAIN.'/community', $this->thread('Central scoped review') + ['location_id' => $site->location_id])->assertSessionHasNoErrors();
        $this->post(self::MAIN.'/community', $this->thread('Network-wide policy'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('community_threads', ['title' => 'Local review', 'status' => 'pending']);
        $this->assertDatabaseHas('community_threads', ['title' => 'Central scoped review', 'status' => 'pending']);
        $this->assertDatabaseHas('community_threads', ['title' => 'Network-wide policy', 'status' => 'approved']);

        $this->publishPolicy($admin, ['thread' => 'manual_review']);
        $site->refresh()->update(['overrides' => ['approval_policies' => ['thread' => 'auto_approve']]]);
        $this->actingAs($owner)->post(self::CITY.'/community', $this->thread('Invalid local value'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('community_threads', ['title' => 'Invalid local value', 'status' => 'pending']);
    }

    public function test_listing_policy_excludes_photos_and_keeps_edits_in_review(): void
    {
        Storage::fake('approval-policy-photos');
        config(['network.media_disk' => 'approval-policy-photos']);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $seller = User::factory()->create();
        $this->publishPolicy($admin, ['listing' => 'auto_approve']);
        $this->actingAs($seller)->postJson(self::CITY.'/api/v1/marketplace', $this->listing('Text listing'))
            ->assertCreated()->assertJsonPath('data.status', 'active');
        $listing = Listing::where('title', 'Text listing')->sole();
        $this->assertNotNull($listing->expires_at);
        $this->assertDatabaseHas('listing_status_history', ['listing_id' => $listing->id, 'status' => 'active', 'actor_id' => null]);
        $this->assertDatabaseHas('notifications', ['user_id' => $seller->id, 'event' => 'listing.approved', 'resource_id' => $listing->id]);
        $this->actingAs($admin)->post(self::MAIN.'/admin/moderation/'.$listing->id, [
            'status' => 'active', 'version' => ListingPhotoOrder::version($listing),
        ])->assertConflict();
        $this->assertDatabaseCount('listing_status_history', 2);
        $this->actingAs($seller);
        $this->post(self::CITY.'/marketplace', $this->listing('Photo listing') + ['photos' => [UploadedFile::fake()->image('items.png')]])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('listings', ['title' => 'Photo listing', 'status' => 'pending']);
        $this->put(self::CITY.'/account/listings/'.$listing->id, $this->listing('Edited text listing') + ['photo_version' => ListingPhotoOrder::version($listing)])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => 'pending']);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_every_other_selected_type_preserves_its_publication_and_notification_effects(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $owner = User::factory()->create();
        $follower = User::factory()->create();
        $city = Site::where('code', 'brandon')->sole();
        $revision = $this->publishPolicy($admin, array_fill_keys(['thread', 'reply', 'event', 'business-update', 'job'], 'auto_approve'));
        $this->actingAs($follower)->post(self::CITY.'/community', $this->thread('Thread to follow'))->assertSessionHasNoErrors();
        $thread = CommunityThread::sole();
        $this->post(self::CITY.'/community/'.$thread->id.'/follow')->assertRedirect();
        $this->travel(2)->minutes();
        $this->actingAs($owner)->post(self::CITY.'/community/'.$thread->id.'/replies', ['body' => 'Approved reply text.'])->assertSessionHasNoErrors();
        $this->assertTrue($thread->fresh()->last_activity_at->gt($thread->last_activity_at));
        $this->get(self::CITY.'/discussions/'.$thread->id)->assertOk()->assertSee('Approved reply text.');
        $eventSource = CommunityThread::forceCreate([
            'title' => 'A city event', 'body' => 'A community event with plenty of details.', 'category' => 'general',
            'location_id' => $city->location_id, 'author_id' => $owner->id, 'status' => 'approved',
        ])->fresh();
        $this->post(self::CITY."/discussions/$eventSource->id/event", $this->event() + [
            'thread_version' => ModerationDecision::version('thread', $eventSource),
        ])->assertSessionHasNoErrors();

        $business = Business::create(['name' => 'Approved employer', 'slug' => 'approved-employer', 'category' => 'retail', 'status' => 'active']);
        $branch = $business->locations()->create(['location_id' => $city->location_id, 'address' => '1 Main Street']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $url = self::CITY.'/account/businesses/'.$business->id;
        $this->post($url.'/updates', ['business_location_id' => $branch->id, 'type' => 'update', 'title' => 'New service', 'body' => 'The service is now available.'])
            ->assertSessionHasNoErrors();
        $this->post($url.'/jobs', [
            'business_location_id' => $branch->id, 'title' => 'Store supervisor', 'description' => 'Support the store and the local team.',
            'employment_type' => 'full-time', 'workplace' => 'on-site', 'apply_url' => 'https://example.test/jobs',
            'closing_on' => today()->addWeek()->toDateString(),
        ])->assertSessionHasNoErrors();

        foreach (['reply' => 'community_replies', 'event' => 'community_events', 'business-update' => 'business_updates', 'job' => 'job_postings'] as $type => $table) {
            $item = DB::table($table)->sole();
            $this->assertSame('approved', $item->status);
            $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'event' => "$type.approved", 'resource_id' => $item->id]);
        }
        $this->assertNotNull(DB::table('business_updates')->value('published_at'));
        $this->assertNotNull(DB::table('job_postings')->value('published_at'));
        $this->assertDatabaseHas('notifications', ['user_id' => $follower->id, 'event' => 'reply.followed']);
        $this->assertDatabaseCount('notifications', 6);
        $audit = DB::table('audit_logs')->where('action', 'business.update-approved')->sole();
        $this->assertSame($revision, json_decode($audit->context, true)['approval_policy_revision']);
    }

    public function test_auto_approval_requires_existing_auth_scope_and_module_checks_and_excludes_identity(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $owner = User::factory()->create();
        $this->actingAs($admin)->post(self::SETTINGS, ['approval_policies' => ['claim' => 'auto_approve']])->assertSessionHasErrors('approval_policies');
        $this->publishPolicy($admin, ['thread' => 'auto_approve', 'event' => 'auto_approve', 'business-update' => 'auto_approve']);
        $this->actingAs(User::factory()->unverified()->create())->postJson(self::CITY.'/community', $this->thread('Unverified'))->assertForbidden();
        $winnipeg = Location::where('slug', 'winnipeg')->sole();
        $this->actingAs($owner)->post(self::CITY.'/community', $this->thread('Out of scope') + ['location_id' => $winnipeg->id])->assertSessionHasErrors('location_id');
        $this->assertDatabaseCount('community_threads', 0);
        $this->post(self::CITY.'/account/businesses', ['name' => 'New business', 'category' => 'retail', 'address' => '2 Main Street'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('businesses', ['name' => 'New business', 'status' => 'pending']);

        $business = Business::create(['name' => 'Unclaimed', 'slug' => 'unclaimed', 'category' => 'retail', 'status' => 'active']);
        $branch = $business->locations()->create(['location_id' => Site::where('code', 'brandon')->value('location_id'), 'address' => '3 Main Street']);
        $this->post(self::CITY.'/businesses/unclaimed/claim', [
            'contact_email' => 'owner@example.test', 'evidence' => 'Evidence of the business registration is available.',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('business_claims', ['business_id' => $business->id, 'status' => 'pending']);
        $this->post(self::CITY.'/account/businesses/'.$business->id.'/updates', [
            'business_location_id' => $branch->id, 'type' => 'update', 'title' => 'Revoked owner update', 'body' => 'This should never publish.',
        ])->assertNotFound();

        $this->actingAs($admin)->post(self::SETTINGS, ['modules' => ['community' => 1]])->assertRedirect();
        $this->post(self::SETTINGS.'/publish', ['revision' => app(NetworkSettings::class)->state()->draft_id])->assertRedirect();
        $this->actingAs($owner)->post(self::CITY.'/events', $this->event())->assertNotFound();
        $this->assertDatabaseCount('community_events', 0);
    }

    private function savePolicy(User $admin, array $modes): int
    {
        $this->actingAs($admin)->post(self::SETTINGS, ['approval_policies' => $modes])->assertSessionHasNoErrors()->assertRedirect();

        return app(NetworkSettings::class)->state()->draft_id;
    }

    private function publishPolicy(User $admin, array $modes): int
    {
        $revision = $this->savePolicy($admin, $modes);
        $this->post(self::SETTINGS.'/publish', ['revision' => $revision])->assertSessionHasNoErrors()->assertRedirect();

        return $revision;
    }

    private function thread(string $title): array
    {
        return ['title' => $title, 'body' => 'A new discussion with adequate details.', 'category' => 'general'];
    }

    private function listing(string $title): array
    {
        return ['title' => $title, 'description' => 'A table in good condition.', 'price' => 50, 'category' => 'furniture', 'condition' => 'good'];
    }

    private function event(): array
    {
        return [
            'title' => 'A city event', 'body' => 'A community event with plenty of details.', 'category' => 'community',
            'starts_at' => now('America/Winnipeg')->addWeek()->format('Y-m-d\TH:i'), 'venue' => 'Town hall', 'organizer' => 'Gardening club',
        ];
    }
}

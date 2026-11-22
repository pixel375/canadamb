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
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_listing_review_and_expiration_notify_once_per_status_history_row(): void
    {
        $seller = User::factory()->create();
        $listing = $this->listing($seller);
        $url = "https://canadamb.ca/admin/moderation/$listing->id";
        $this->actingAs($this->moderator())->post($url, ['status' => 'active'])->assertRedirect();
        $this->post($url, ['status' => 'active'])->assertConflict();
        $approval = $this->assertNotice($seller, 'listing.approved', $listing->id, [$listing->location_id]);
        $this->assertDatabaseHas('listing_status_history', ['id' => $approval->source_id, 'listing_id' => $listing->id, 'status' => 'active']);

        $this->travel(31)->days();
        $this->artisan('marketplace:expire')->assertSuccessful();
        $this->artisan('marketplace:expire')->assertSuccessful();
        $expiry = $this->assertNotice($seller, 'listing.expired', $listing->id, [$listing->location_id]);
        $this->assertDatabaseHas('listing_status_history', ['id' => $expiry->source_id, 'listing_id' => $listing->id, 'status' => 'expired']);
        $this->assertDatabaseCount('notifications', 2);

        $this->actingAs($seller)->post("https://brandonmb.ca/account/listings/$listing->id/status", ['status' => 'pending'])->assertRedirect();
        $this->actingAs($this->moderator())->post($url, ['status' => 'active'])->assertRedirect();
        $this->assertSame(2, DB::table('notifications')->where([
            'user_id' => $seller->id, 'event' => 'listing.approved', 'resource_id' => $listing->id,
        ])->count());
    }

    public function test_self_removal_does_not_issue_a_moderation_notice(): void
    {
        $seller = User::factory()->create();
        $listing = $this->listing($seller);
        $this->actingAs($seller)->post("https://brandonmb.ca/account/listings/$listing->id/status", ['status' => 'removed'])->assertRedirect();
        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => 'removed']);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notification_and_listing_moderation_roll_back_together(): void
    {
        $seller = User::factory()->create();
        $listing = $this->listing($seller);
        DB::beginTransaction();
        try {
            $this->actingAs($this->moderator())->post("https://canadamb.ca/admin/moderation/$listing->id", [
                'status' => 'removed', 'reason' => 'Listing did not meet marketplace requirements.',
            ])->assertRedirect();
            $this->assertNotice($seller, 'listing.removed', $listing->id, [$listing->location_id]);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => 'pending']);
        $this->assertDatabaseCount('listing_status_history', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_business_review_notifies_only_the_known_profile_owner_claimant_and_update_author(): void
    {
        $owner = User::factory()->create();
        $claimant = User::factory()->create();
        $writer = User::factory()->create();
        $business = Business::create(['slug' => 'local-repair', 'name' => 'Local Repair', 'category' => 'services', 'status' => 'pending']);
        $brandon = $business->locations()->create(['location_id' => $this->location('brandon'), 'address' => '1 Test Road']);
        $winnipeg = $business->locations()->create(['location_id' => $this->location('winnipeg'), 'address' => '2 Test Road']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $business->members()->attach($writer->id, ['role' => 'editor']);
        $claim = DB::table('business_claims')->insertGetId([
            'business_id' => $business->id, 'user_id' => $claimant->id,
            'contact_email' => 'claimant@example.test', 'evidence' => 'Evidence for private review.',
        ]);
        $update = BusinessUpdate::create([
            'business_location_id' => $brandon->id, 'author_id' => $writer->id, 'type' => 'announcement',
            'title' => 'Local workshop', 'body' => 'An announcement for local customers.',
        ]);
        $this->actingAs($this->moderator())->post("https://canadamb.ca/admin/business-review/$business->id", [
            'decision' => 'approved', 'notes' => 'Checked the profile.',
        ])->assertRedirect();
        $this->post("https://canadamb.ca/admin/business-claims/$claim", [
            'decision' => 'rejected', 'notes' => 'Reviewed the claim.',
        ])->assertRedirect();
        $this->post("https://canadamb.ca/admin/business-updates/$update->id", [
            'decision' => 'approved', 'notes' => 'Checked the update.',
        ])->assertRedirect();

        $this->assertNotice($owner, 'business.approved', $business->id, [$brandon->location_id, $winnipeg->location_id]);
        $this->assertNotice($claimant, 'claim.rejected', $claim, [$brandon->location_id, $winnipeg->location_id]);
        $this->assertNotice($writer, 'business-update.approved', $update->id, [$brandon->location_id]);
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_job_review_notifies_the_author_only_after_a_current_review_is_written(): void
    {
        $writer = User::factory()->create();
        $business = Business::create(['slug' => 'career-store', 'name' => 'Career Store', 'category' => 'retail', 'status' => 'active']);
        $branch = $business->locations()->create(['location_id' => $this->location('brandon'), 'address' => '1 Main St']);
        $job = JobPosting::create([
            'business_location_id' => $branch->id, 'author_id' => $writer->id, 'title' => 'Store supervisor',
            'description' => 'Support the local team.', 'employment_type' => 'full-time', 'workplace' => 'on-site',
            'apply_url' => 'https://example.test/careers', 'closing_on' => today()->addWeek()->toDateString(),
        ]);
        $url = "https://canadamb.ca/admin/jobs/$job->id";
        $originalVersion = $job->fresh()->reviewVersion();
        $this->actingAs($this->moderator())->post($url, ['action' => 'approve', 'version' => str_repeat('a', 64)])->assertConflict();
        $this->post($url, ['action' => 'approve', 'version' => $originalVersion])->assertRedirect();
        $this->actingAs($this->moderator())->post($url, [
            'action' => 'reject', 'reason' => 'A second editor had the previous review open.', 'version' => $originalVersion,
        ])->assertConflict();
        $this->post($url, [
            'action' => 'reject', 'reason' => 'Changed hiring requirements.', 'version' => $job->fresh()->reviewVersion(),
        ])->assertRedirect();

        $this->assertNotice($writer, 'job.approved', $job->id, [$branch->location_id]);
        $this->assertNotice($writer, 'job.rejected', $job->id, [$branch->location_id]);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_community_review_and_follow_notices_exclude_the_author_and_legacy_duplicates(): void
    {
        $author = User::factory()->create();
        $replyAuthor = User::factory()->create();
        $follower = User::factory()->create();
        $legacyFollower = User::factory()->create();
        $optedOut = User::factory()->create();
        $location = $this->location('brandon');
        $thread = CommunityThread::create([
            'author_id' => $author->id, 'title' => 'Community garden', 'body' => 'A province-wide discussion.',
            'category' => 'general', 'location_id' => $location,
        ]);
        $reply = $thread->replies()->create(['author_id' => $replyAuthor->id, 'body' => 'I can help with seeds.']);
        $event = CommunityEvent::create([
            'author_id' => $author->id, 'location_id' => $this->location('brandon'), 'title' => 'Seed exchange',
            'body' => 'Bring seeds to trade.', 'category' => 'community', 'organizer' => 'Garden club',
            'venue' => 'Community hall', 'starts_at' => now()->addWeek(),
        ]);
        DB::table('community_thread_follows')->insert([
            ['thread_id' => $thread->id, 'user_id' => $replyAuthor->id],
            ['thread_id' => $thread->id, 'user_id' => $follower->id],
            ['thread_id' => $thread->id, 'user_id' => $legacyFollower->id],
            ['thread_id' => $thread->id, 'user_id' => $optedOut->id],
        ]);
        DB::table('community_notifications')->insert(['user_id' => $legacyFollower->id, 'reply_id' => $reply->id, 'created_at' => now()]);
        DB::table('member_settings')->insert(['user_id' => $optedOut->id, 'notify_discussion_replies' => false]);
        $url = 'https://canadamb.ca/admin/community';
        $this->actingAs($this->moderator())->post("$url/threads/$thread->id", ['action' => 'approve'])->assertRedirect();
        $this->post("$url/threads/$thread->id", ['action' => 'pin'])->assertRedirect();
        $this->post("$url/replies/$reply->id", ['action' => 'approve'])->assertRedirect();
        $this->post("$url/replies/$reply->id", ['action' => 'approve'])->assertConflict();
        $this->post("$url/events/$event->id", ['action' => 'reject', 'reason' => 'The organizer cancelled.'])->assertRedirect();

        $this->assertNotice($author, 'thread.approved', $thread->id, [$location]);
        $this->assertNotice($replyAuthor, 'reply.approved', $reply->id, [$location]);
        $follow = $this->assertNotice($follower, 'reply.followed', $reply->id, [$location]);
        $this->assertSame($reply->id, $follow->source_id);
        $this->assertNotice($author, 'event.rejected', $event->id, [$event->location_id]);
        $this->assertDatabaseCount('notifications', 4);
        $this->assertDatabaseCount('community_notifications', 1);
    }

    public function test_messages_notify_only_the_recipient_and_never_on_a_blocked_send(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $listing = $this->listing($seller, 'active');
        $this->actingAs($buyer)->post("https://brandonmb.ca/marketplace/$listing->id/message", ['body' => 'Are the chairs included?'])->assertRedirect();
        $thread = Conversation::sole();
        $initial = $thread->messages()->sole();
        $url = "https://brandonmb.ca/account/messages/$thread->id";
        $this->actingAs($seller)->post($url, ['body' => 'Yes, they are included.'])->assertRedirect();
        $response = $thread->messages()->latest('id')->firstOrFail();

        DB::table('member_settings')->insert(['user_id' => $buyer->id, 'notify_marketplace_messages' => false]);
        $this->post($url, ['body' => 'I can meet you on Saturday.'])->assertRedirect();
        $this->actingAs($buyer)->post("$url/block")->assertRedirect();
        $this->actingAs($seller)->post($url, ['body' => 'Blocked test message'])->assertForbidden();
        $this->assertSame($initial->id, $this->assertNotice($seller, 'message.received', $initial->id, [$listing->location_id])->source_id);
        $this->assertSame($response->id, $this->assertNotice($buyer, 'message.received', $response->id, [$listing->location_id])->source_id);
        $this->assertDatabaseCount('messages', 3);
        $this->assertDatabaseCount('notifications', 2);
    }

    private function assertNotice(User $recipient, string $event, int $resource, array $locations): object
    {
        $match = ['user_id' => $recipient->id, 'event' => $event, 'resource_id' => $resource];
        $this->assertDatabaseHas('notifications', $match);
        $notice = DB::table('notifications')->where($match)->sole();
        $this->assertSame($locations,
            DB::table('notification_locations')->where('notification_id', $notice->id)->orderBy('location_id')->pluck('location_id')->all());

        return $notice;
    }

    private function listing(User $seller, string $status = 'pending'): Listing
    {
        $listing = Listing::create([
            'user_id' => $seller->id, 'location_id' => $this->location('brandon'), 'title' => 'Patio set',
            'description' => 'Comes with four chairs.', 'category' => 'furniture', 'condition' => 'good', 'price_cents' => 2500,
            'status' => $status,
        ]);
        if ($status === 'active') {
            $listing->forceFill(['published_at' => now(), 'expires_at' => now()->addDays(30)])->save();
        }

        return $listing;
    }

    private function location(string $slug): int
    {
        return Location::where('slug', $slug)->sole()->id;
    }

    private function moderator(): User
    {
        return User::factory()->create(['role' => 'network_admin']);
    }
}

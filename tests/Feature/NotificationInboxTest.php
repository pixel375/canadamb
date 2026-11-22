<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessUpdate;
use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use App\Support\NetworkSettings;
use App\Support\NotificationInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_legacy_inbox_shares_read_state_and_obeys_portal_and_recipient_scope(): void
    {
        $recipient = User::factory()->create();
        $other = User::factory()->create();
        $brandon = $this->legacyReply('brandon', 'Brandon discussion', $recipient, $other);
        $rural = $this->legacyReply('elkhorn', 'Rural discussion', $recipient, $other);
        $this->actingAs($recipient)->get('https://brandonmb.ca/account/notifications')->assertOk()
            ->assertSee('Brandon discussion')->assertDontSee('Rural discussion')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->post("https://brandonmb.ca/account/notifications/legacy/$rural/read")->assertNotFound();
        $this->post("https://brandonmb.ca/account/community-notifications/$brandon/read")->assertRedirect();
        $this->assertNotNull(DB::table('community_notifications')->find($brandon)->read_at);
        $this->get('https://canadamb.ca/account/notifications?status=unread')->assertOk()
            ->assertSee('Rural discussion')->assertDontSee('Brandon discussion');
        $this->actingAs($other)->post("https://canadamb.ca/account/notifications/legacy/$rural/read")->assertNotFound();
        $this->assertNull(DB::table('community_notifications')->find($rural)->read_at);
    }

    public function test_idempotent_recording_and_mark_all_respect_category_and_portal_scope(): void
    {
        $recipient = User::factory()->create();
        $author = User::factory()->create();
        $brandon = $this->listing('brandon', $recipient);
        $rural = $this->listing('elkhorn', $recipient);
        $legacy = $this->legacyReply('brandon', 'Discussion to keep unread', $recipient, $author);
        $local = $this->notice($recipient, 'listing.approved', $brandon->id, $brandon->location_id);
        $other = $this->notice($recipient, 'listing.approved', $rural->id, $rural->location_id);
        $this->actingAs($recipient)->get('https://brandonmb.ca/account/notifications')->assertOk()
            ->assertSee('2 unread notifications')->assertSeeInOrder(['System &amp; approvals', 'Social &amp; activity'], false)
            ->assertDontSee("/account/notifications/unified/$other/open");
        $this->get("https://brandonmb.ca/account/notifications/unified/$other/open")->assertNotFound();
        $this->get("https://brandonmb.ca/account/notifications/unified/$local/open?next=https://example.test")->assertRedirect("https://brandonmb.ca/marketplace/$brandon->id");
        $this->post('https://brandonmb.ca/account/notifications/read-all', ['category' => 'marketplace'])->assertRedirect();
        $read = DB::table('notifications')->find($local)->read_at;
        $this->assertNotNull($read);
        $this->assertNull(DB::table('notifications')->find($other)->read_at);
        $this->assertNull(DB::table('community_notifications')->find($legacy)->read_at);

        $this->travel(1)->minutes();
        $this->assertSame($local, $this->notice($recipient, 'listing.approved', $brandon->id, $rural->location_id));
        $this->assertSame($read, DB::table('notifications')->find($local)->read_at);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('notification_locations', 2);
        $this->get('https://canadamb.ca/account/notifications?status=unread')->assertOk()
            ->assertSee('2 unread notifications')->assertSee("/account/notifications/unified/$other/open");
        $this->post('https://canadamb.ca/account/notifications/read-all')->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->find($other)->read_at);
        $this->assertNotNull(DB::table('community_notifications')->find($legacy)->read_at);
        $this->get('https://brandonmb.ca/account/notifications?status=unread')->assertOk()
            ->assertSee('No unread notifications in this view.');
    }

    public function test_message_notice_never_renders_body_and_honors_reader_marker_and_block(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $other = User::factory()->create();
        $listing = $this->listing('brandon', $seller);
        $body = 'Private purchase and pickup details.';
        $this->actingAs($buyer)->post("https://brandonmb.ca/marketplace/$listing->id/message", ['body' => $body])->assertRedirect();
        $conversation = Conversation::sole();
        $notice = DB::table('notifications')->sole();
        $inbox = 'https://brandonmb.ca/account/notifications';
        $open = "$inbox/unified/$notice->id/open";
        $this->get($inbox)->assertOk()->assertDontSee('New Marketplace message');
        $this->actingAs($other)->get($open)->assertNotFound();
        $this->actingAs($seller)->get($inbox)->assertOk()->assertSee('New Marketplace message')
            ->assertSee('1 unread notifications')->assertDontSee($body)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get($open)->assertRedirect("https://brandonmb.ca/account/messages/$conversation->id");
        $this->get("https://brandonmb.ca/account/messages/$conversation->id")->assertOk()->assertSee($body);
        $this->get('https://canadamb.ca/account/notifications?status=unread&category=messages')->assertOk()
            ->assertSee('No unread notifications in this view.');
        $this->assertNull(DB::table('notifications')->find($notice->id)->read_at);

        $listing->forceFill(['status' => 'sold'])->save();
        $followUp = 'Private pickup confirmation after the sale.';
        $this->actingAs($buyer)->post("https://brandonmb.ca/account/messages/$conversation->id", ['body' => $followUp])->assertRedirect();
        $newNotice = DB::table('notifications')->latest('id')->firstOrFail();
        $this->actingAs($seller)->get("$inbox?status=unread")->assertOk()->assertSee('1 unread notifications')
            ->assertSee('New Marketplace message')->assertDontSee($followUp);
        $this->get("$inbox/unified/$newNotice->id/open")->assertRedirect("https://brandonmb.ca/account/messages/$conversation->id");
        $this->post("https://brandonmb.ca/account/messages/$conversation->id/block")->assertRedirect();
        $this->get($inbox)->assertOk()->assertDontSee('New Marketplace message')->assertDontSee($body);
        $this->get($open)->assertNotFound();
    }

    public function test_disabled_unpublished_and_revoked_targets_disappear_from_badges_and_links(): void
    {
        $member = User::factory()->create();
        $author = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $legacy = $this->legacyReply('brandon', 'Thread that will be unpublished', $member, $author);
        $listing = $this->listing('brandon', $member);
        $business = Business::create(['slug' => 'shared-shop', 'name' => 'Shared Shop', 'category' => 'retail', 'status' => 'active']);
        $business->locations()->create(['location_id' => $listing->location_id, 'address' => '1 Main Street']);
        $business->members()->attach($member->id, ['role' => 'owner']);
        $marketplace = $this->notice($member, 'listing.approved', $listing->id, $listing->location_id);
        $profile = $this->notice($member, 'business.approved', $business->id, $listing->location_id);
        $inbox = 'https://brandonmb.ca/account/notifications';
        $this->actingAs($member)->get($inbox)->assertOk()->assertSee('3 unread notifications');
        $this->get("$inbox/unified/$profile/open")->assertRedirect('https://brandonmb.ca/businesses/shared-shop');
        $business->members()->detach($member->id);
        CommunityThread::sole()->forceFill(['status' => 'rejected'])->save();
        app(NetworkSettings::class)->saveDraft($admin->id, function (array $settings): array {
            $settings['modules']['marketplace'] = false;

            return $settings;
        });
        app(NetworkSettings::class)->publish($admin->id);
        $this->get($inbox)->assertOk()->assertSee('This activity is no longer available.')
            ->assertDontSee('Thread that will be unpublished')->assertDontSee('3 unread notifications')
            ->assertDontSee('Marketplace listing approved')->assertDontSee('Business profile approved');
        $this->get("$inbox?status=unread")->assertOk()->assertSee('No unread notifications in this view.');
        $this->get("$inbox/legacy/$legacy/open")->assertNotFound();
        $this->get("$inbox/unified/$marketplace/open")->assertNotFound();
        $this->get("$inbox/unified/$profile/open")->assertNotFound();
        $listing->delete();
        $this->post("$inbox/unified/$marketplace/read")->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->find($marketplace)->read_at);
    }

    public function test_legacy_duplicate_is_counted_once_and_views_are_paginated(): void
    {
        $member = User::factory()->create();
        $author = User::factory()->create();
        $legacy = $this->legacyReply('brandon', 'One historical notification', $member, $author);
        $reply = DB::table('community_notifications')->find($legacy)->reply_id;
        $location = Location::where('slug', 'brandon')->sole()->id;
        $this->notice($member, 'reply.followed', $reply, $location);
        $this->assertDatabaseCount('notifications', 0);
        // Simulate a historical overlap from a release rollback.
        $copy = DB::table('notifications')->insertGetId(['user_id' => $member->id, 'event' => 'reply.followed', 'source_id' => $reply, 'resource_id' => $reply]);
        DB::table('notification_locations')->insert(['notification_id' => $copy, 'location_id' => $location]);
        $this->actingAs($member)->get('https://canadamb.ca/account/notifications')->assertOk()
            ->assertSee('1 unread notifications')->assertDontSee("/account/notifications/unified/$copy/open");
        for ($i = 0; $i < 21; $i++) {
            $this->legacyReply('brandon', "Paginated discussion $i", $member, $author);
        }
        $this->get('https://brandonmb.ca/account/notifications?category=discussions')->assertOk()
            ->assertSee('22 unread notifications')->assertDontSee('One historical notification');
        $this->get('https://brandonmb.ca/account/notifications?category=discussions&page=2')->assertOk()
            ->assertSee('One historical notification');
    }

    public function test_followed_reply_deep_link_opens_the_exact_reply_on_the_right_page(): void
    {
        $member = User::factory()->create();
        $author = User::factory()->create();
        $legacy = $this->legacyReply('brandon', 'Paged discussion', $member, $author);
        $thread = CommunityThread::sole();
        for ($i = 0; $i < 25; $i++) {
            $reply = $thread->replies()->create(['author_id' => $author->id, 'body' => "Reply on this thread $i"]);
            $reply->forceFill(['status' => 'approved'])->save();
        }
        $notice = $this->notice($member, 'reply.followed', $reply->id, $thread->location_id);
        $this->actingAs($member)->get("https://canadamb.ca/account/notifications/unified/$notice/open")
            ->assertRedirect("https://canadamb.ca/community/replies/$reply->id");
        $this->get("https://canadamb.ca/account/notifications/legacy/$legacy/open")->assertRedirect();
        $this->get("https://canadamb.ca/community/replies/$reply->id")
            ->assertRedirect("https://brandonmb.ca/community/$thread->id?page=2#reply-$reply->id");
        $this->get("https://brandonmb.ca/community/$thread->id?page=2")->assertOk()->assertSee("id=\"reply-$reply->id\"", false);
        $reply->forceFill(['status' => 'rejected'])->save();
        $this->get("https://canadamb.ca/community/replies/$reply->id")->assertNotFound();
    }

    public function test_business_update_deep_link_uses_the_updates_branch_instead_of_the_business_primary_branch(): void
    {
        $member = User::factory()->create();
        $business = Business::create(['slug' => 'another-shop', 'name' => 'Another Shop', 'category' => 'retail', 'status' => 'active']);
        foreach (['brandon', 'elkhorn'] as $slug) {
            $branch = $business->locations()->create([
                'location_id' => Location::where('slug', $slug)->sole()->id, 'address' => '1 Main Street',
            ]);
        }
        $business->members()->attach($member->id, ['role' => 'owner']);
        $update = BusinessUpdate::forceCreate([
            'author_id' => $member->id, 'business_location_id' => $branch->id, 'type' => 'announcement',
            'title' => 'Elkhorn workshop', 'body' => 'Workshop details for Elkhorn residents.', 'status' => 'approved', 'published_at' => now(),
        ]);
        $notice = $this->notice($member, 'business-update.approved', $update->id, $branch->location_id);
        $this->actingAs($member)->get("https://canadamb.ca/account/notifications/unified/$notice/open")
            ->assertRedirect("https://canadamb.ca/business-updates/$update->id");
        $this->get("https://canadamb.ca/business-updates/$update->id")->assertOk()->assertSee('Elkhorn workshop');
        $this->get("https://brandonmb.ca/account/notifications/unified/$notice/open")->assertNotFound();
        $this->get("https://brandonmb.ca/business-updates/$update->id")->assertNotFound();
        $update->forceFill(['status' => 'rejected'])->save();
        $this->get("https://canadamb.ca/business-updates/$update->id")->assertNotFound();
    }

    public function test_inbox_mutations_require_verified_account_csrf_and_prelaunch_ip(): void
    {
        $member = User::factory()->unverified()->create();
        $author = User::factory()->create();
        $notice = $this->legacyReply('brandon', 'Discussion subject', $member, $author);
        $inbox = 'https://brandonmb.ca/account/notifications';
        $this->actingAs($member)->get($inbox)->assertRedirect();
        $member->markEmailAsVerified();
        config(['network.prelaunch' => true, 'network.prelaunch_ips' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])->withHeader('X-Forwarded-For', '192.0.2.10')
            ->get($inbox)->assertForbidden();
        $this->get('https://brandonmb.ca/app.webmanifest')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->get($inbox)->assertOk();
        $this->app->instance('env', 'production');
        $this->post("$inbox/read-all")->assertStatus(419);
        $this->post("$inbox/legacy/$notice/read", ['_token' => 'forged'])->assertStatus(419);
        $this->assertNull(DB::table('community_notifications')->find($notice)->read_at);
    }

    private function listing(string $slug, User $seller): Listing
    {
        return Listing::forceCreate([
            'user_id' => $seller->id, 'location_id' => Location::where('slug', $slug)->sole()->id,
            'title' => 'Local listing', 'description' => 'Listing description', 'category' => 'furniture',
            'condition' => 'good', 'price_cents' => 2500, 'status' => 'active',
            'published_at' => now(), 'expires_at' => now()->addMonth(),
        ]);
    }

    private function notice(User $user, string $event, int $resource, int $location): ?int
    {
        DB::transaction(fn () => NotificationInbox::record($user->id, $event, $resource, $resource, [$location]));

        return DB::table('notifications')->where(['user_id' => $user->id, 'event' => $event, 'source_id' => $resource])->value('id');
    }

    private function legacyReply(string $slug, string $title, User $recipient, User $author): int
    {
        $thread = CommunityThread::forceCreate(['title' => $title, 'body' => 'Example thread', 'category' => 'general',
            'location_id' => Location::where('slug', $slug)->sole()->id, 'author_id' => $author->id, 'status' => 'approved']);
        $reply = CommunityReply::forceCreate(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'Example reply', 'status' => 'approved']);

        return DB::table('community_notifications')->insertGetId(['user_id' => $recipient->id, 'reply_id' => $reply->id, 'created_at' => now()]);
    }
}

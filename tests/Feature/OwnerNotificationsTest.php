<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CommunityThread;
use App\Models\JobPosting;
use App\Models\Listing;
use App\Models\Location;
use App\Models\PropertyListing;
use App\Models\User;
use App\Support\OwnerNotifications;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OwnerNotificationsTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => DatabaseSeeder::class])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_listing_expiry_is_recorded_once_and_available_to_the_owner_across_mirrored_portals(): void
    {
        config(['network.owner_notifications' => true]);
        $member = User::factory()->create();
        $listing = Listing::create(['user_id' => $member->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Expired table fixture', 'description' => 'An expired listing for notification tests.', 'category' => 'furniture',
            'condition' => 'good', 'price_cents' => 1000, 'status' => 'active']);
        $listing->forceFill(['published_at' => now()->subMonth(), 'expires_at' => now()->subMinute()])->save();
        $this->artisan('marketplace:expire')->assertSuccessful();
        $this->artisan('marketplace:expire')->assertSuccessful();
        $this->assertDatabaseCount('owner_notifications', 1);
        $this->assertDatabaseHas('owner_notifications', ['user_id' => $member->id, 'target_id' => $listing->id, 'kind' => 'listing', 'decision' => 'expired']);
        foreach (['https://canadamb.ca', 'https://brandonmb.ca'] as $host) {
            $this->actingAs($member)->get($host.'/account/notifications')->assertOk()->assertSee('Your Marketplace listing expired.')
                ->assertSee('href="/account/listings/'.$listing->id.'/history"', false);
            $this->get($host.'/account/listings/'.$listing->id.'/history')->assertOk()->assertSee($listing->title);
        }
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->get('https://canadamb.ca/admin/notifications')->assertOk()->assertSee('Expired: 1')->assertDontSee($listing->title);
    }

    public function test_owner_updates_are_private_scoped_and_created_only_on_review(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $thread = CommunityThread::create(['author_id' => $member->id, 'location_id' => Location::where('slug', 'winnipeg')->sole()->id,
            'title' => 'Private review fixture', 'body' => 'This is a submitted discussion.', 'category' => 'general']);
        OwnerNotifications::record($member->id, 'discussion', $thread->id, 'approved');
        $this->assertDatabaseCount('owner_notifications', 0);
        config(['network.owner_notifications' => true]);
        $url = 'https://canadamb.ca/admin/community/threads/'.$thread->id;
        $this->actingAs($admin)->post($url, ['action' => 'reject', 'reason' => 'Internal moderation note', 'version' => $thread->contentVersion()])->assertRedirect();
        $alert = DB::table('owner_notifications')->sole();
        $this->assertSame('rejected', $alert->decision);
        $this->assertEquals($member->id, $alert->user_id);
        $read = '/account/notifications/updates/'.$alert->id.'/read';
        $this->actingAs($other)->post('https://canadamb.ca'.$read)->assertNotFound();
        $this->actingAs($member)->get('https://brandonmb.ca/account/notifications')->assertOk()->assertDontSee('Your discussion was not approved.');
        $this->post('https://brandonmb.ca'.$read)->assertNotFound();
        $this->get('https://canadamb.ca/account/notifications')->assertOk()->assertSee('Your discussion was not approved.')
            ->assertSee('Contact support about this decision')->assertDontSee('Internal moderation note')
            ->assertSee('href="/account/discussions?reference='.$thread->id.'"', false);
        $this->get('https://canadamb.ca/account/discussions?reference='.$thread->id)->assertOk()->assertSee($thread->title);
        $this->get('https://brandonmb.ca/account/discussions?reference='.$thread->id)->assertOk()->assertDontSee($thread->title);
        $this->actingAs($other)->get('https://canadamb.ca/account/discussions?reference='.$thread->id)->assertOk()->assertDontSee($thread->title);
        $this->actingAs($member);
        $this->post('https://canadamb.ca'.$read, ['submission_view' => 'https://example.test'])->assertSessionHasErrors('submission_view');
        $this->assertNull(DB::table('owner_notifications')->where('id', $alert->id)->value('read_at'));
        $this->post('https://canadamb.ca'.$read, ['submission_view' => 'unread', 'announcement_view' => 'all', 'announcements' => 2])
            ->assertRedirect('/account/notifications?submission_view=unread&announcement_view=all&announcements=2#submission-updates');
        $this->assertNotNull(DB::table('owner_notifications')->where('id', $alert->id)->value('read_at'));
        $this->get('https://canadamb.ca/account/notifications?submission_view=unread')->assertOk()
            ->assertSee('No unread submission updates on this portal.')->assertDontSee('Your discussion was not approved.');
        $this->get('https://canadamb.ca/account/notifications?submission_view=all')->assertOk()->assertSee('Your discussion was not approved.');
        $thread->delete();
        $this->get('https://canadamb.ca/account/notifications')->assertOk()->assertDontSee('Your discussion was not approved.');
    }

    public function test_marking_the_last_unread_update_on_a_page_returns_to_the_previous_page(): void
    {
        config(['network.owner_notifications' => true]);
        $member = User::factory()->create();
        $place = Location::where('slug', 'brandon')->sole();
        for ($i = 1; $i <= 16; $i++) {
            $thread = CommunityThread::create(['author_id' => $member->id, 'location_id' => $place->id,
                'title' => 'Pagination fixture '.$i, 'body' => 'A submitted discussion.', 'category' => 'general']);
            OwnerNotifications::record($member->id, 'discussion', $thread->id, 'approved');
        }
        $alert = DB::table('owner_notifications')->oldest('id')->first();
        $url = 'https://brandonmb.ca/account/notifications';
        $page = $this->actingAs($member)->get($url.'?submission_view=unread&updates=2')->assertOk();
        $this->assertCount(1, $page->viewData('ownerAlerts')->items());
        $response = $this->post($url.'/updates/'.$alert->id.'/read', ['submission_view' => 'unread', 'updates' => 2, 'announcements' => 3]);
        $response->assertRedirect('/account/notifications?submission_view=unread&announcements=3#submission-updates');
        $page = $this->get($url.'?submission_view=unread')->assertOk();
        $this->assertCount(15, $page->viewData('ownerAlerts')->items());
    }

    public function test_job_review_and_expiry_alerts_require_current_business_membership(): void
    {
        config(['network.owner_notifications' => true]);
        $member = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $business = Business::create(['slug' => 'job-notification-fixture', 'name' => 'Job notification fixture', 'category' => 'services', 'status' => 'active']);
        $business->members()->attach($member->id, ['role' => 'manager']);
        $branch = $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Review Road']);
        $job = JobPosting::create(['business_location_id' => $branch->id, 'author_id' => $member->id, 'title' => 'Job review fixture',
            'description' => 'A local job for notification tests.', 'employment_type' => 'full-time', 'workplace' => 'on-site',
            'apply_url' => 'https://example.test/apply', 'closing_on' => today('America/Winnipeg')->addWeek()->toDateString()]);
        $url = 'https://canadamb.ca/admin/jobs/'.$job->id;
        $this->actingAs($admin)->post($url, ['action' => 'approve', 'version' => $job->fresh()->reviewVersion()])->assertRedirect();
        $this->assertDatabaseHas('owner_notifications', ['user_id' => $member->id, 'kind' => 'job', 'decision' => 'approved']);
        $this->actingAs($member)->get('https://brandonmb.ca/account/notifications')->assertOk()->assertSee('Your job posting was approved.')
            ->assertSee('href="/account/businesses/'.$business->id.'/jobs?reference='.$job->id.'"', false);
        $this->get('https://brandonmb.ca/account/businesses/'.$business->id.'/jobs?reference='.$job->id)->assertOk()->assertSee($job->title);
        $this->actingAs($other)->get('https://canadamb.ca/account/notifications')->assertOk()->assertDontSee('Your job posting was approved.');
        $job->forceFill(['closing_on' => today('America/Winnipeg')->subDay()->toDateString()])->save();
        $this->artisan('job-postings:expire')->assertSuccessful();
        $this->artisan('job-postings:expire')->assertSuccessful();
        $this->assertDatabaseCount('owner_notifications', 2);
        $this->actingAs($member)->get('https://canadamb.ca/account/notifications')->assertOk()->assertSee('Your job posting expired.');
        $business->members()->detach($member->id);
        $this->get('https://canadamb.ca/account/notifications')->assertOk()->assertDontSee('Your job posting expired.');
    }

    public function test_property_alerts_link_to_current_draft_or_archived_history(): void
    {
        config(['network.owner_notifications' => true, 'network.property_drafts' => true]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $item = PropertyListing::create(['owner_id' => $owner->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Private property review fixture', 'description' => 'Private property details for review.', 'transaction' => 'sale',
            'property_type' => 'house', 'seller_type' => 'owner', 'price_cents' => 20000000]);
        $item->forceFill(['status' => 'reviewed'])->save();
        OwnerNotifications::record($owner->id, 'property', $item->id, 'reviewed');
        $path = '/account/properties/'.$item->id;
        foreach (['https://canadamb.ca', 'https://brandonmb.ca'] as $host) {
            $this->actingAs($owner)->get($host.'/account/notifications')->assertOk()->assertSee('href="'.$path.'"', false);
            $this->get($host.$path)->assertOk()->assertSee($item->title);
        }
        $this->actingAs($other)->get('https://brandonmb.ca'.$path)->assertNotFound();
        $item->forceFill(['status' => 'archived'])->save();
        $history = '/account/properties/history?reference='.$item->id;
        $this->actingAs($owner)->get('https://brandonmb.ca/account/notifications')->assertOk()->assertSee('href="'.$history.'"', false);
        $this->get('https://brandonmb.ca'.$history)->assertOk()->assertSee($item->title)->assertDontSee($item->description);
        $this->actingAs($other)->get('https://brandonmb.ca'.$history)->assertOk()->assertDontSee($item->title);
    }

    public function test_business_profile_review_alerts_are_visible_only_to_current_owners_in_scope(): void
    {
        config(['network.owner_notifications' => true]);
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $business = Business::create(['slug' => 'profile-alert-fixture', 'name' => 'Private profile alert fixture', 'category' => 'services', 'status' => 'pending']);
        $business->locations()->create(['location_id' => Location::where('slug', 'winnipeg')->sole()->id, 'address' => '1 Review Road']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $business->members()->attach($manager->id, ['role' => 'manager']);
        $url = 'https://canadamb.ca/admin/business-review/'.$business->id;
        $this->actingAs($admin)->post($url, ['decision' => 'rejected', 'notes' => 'Private review detail.', 'version' => $business->fresh()->profileVersion()])->assertRedirect();
        $this->assertDatabaseCount('owner_notifications', 1);
        $this->assertDatabaseHas('owner_notifications', ['kind' => 'business-profile', 'user_id' => $owner->id, 'target_id' => $business->id, 'decision' => 'rejected']);
        $this->actingAs($owner)->get('https://canadamb.ca/account/notifications')->assertOk()
            ->assertSee('Your business profile was not approved.')->assertDontSee('Private review detail.')
            ->assertSee('href="/account/businesses/'.$business->id.'"', false);
        $this->get('https://brandonmb.ca/account/notifications')->assertOk()->assertDontSee('Your business profile was not approved.');
        $this->actingAs($manager)->get('https://canadamb.ca/account/notifications')->assertOk()->assertDontSee('Your business profile was not approved.');
        $business->members()->detach($owner->id);
        $this->actingAs($owner)->get('https://canadamb.ca/account/notifications')->assertOk()->assertDontSee('Your business profile was not approved.');
    }
}

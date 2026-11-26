<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\Listing;
use App\Models\Location;
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
            $this->actingAs($member)->get($host.'/account/notifications')->assertOk()->assertSee('Your Marketplace listing expired.');
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
            ->assertSee('Contact support about this decision')->assertDontSee('Internal moderation note');
        $this->post('https://canadamb.ca'.$read)->assertRedirect('/account/notifications');
        $this->assertNotNull(DB::table('owner_notifications')->where('id', $alert->id)->value('read_at'));
        $thread->delete();
        $this->get('https://canadamb.ca/account/notifications')->assertOk()->assertDontSee('Your discussion was not approved.');
    }
}

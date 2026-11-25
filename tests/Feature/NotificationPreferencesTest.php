<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
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

    public function test_account_preferences_preserve_follows_and_apply_across_portals(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $author = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $thread = CommunityThread::create(['author_id' => $author->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id, 'title' => 'Preference thread', 'body' => 'Community information.', 'category' => 'general']);
        $thread->forceFill(['status' => 'approved'])->save();
        foreach ([$member, $other] as $follower) {
            DB::table('community_thread_follows')->insert(['thread_id' => $thread->id, 'user_id' => $follower->id]);
        }
        $path = 'https://brandonmb.ca/account/notifications/preferences';
        $settings = ['discussion_replies' => '0', 'job_summary' => '0'];
        $this->actingAs($member)->post($path, $settings)->assertNotFound();
        config(['network.notification_preferences' => true]);
        $this->post($path, $settings + ['user_id' => $other->id])->assertRedirect();
        $this->get('https://canadamb.ca/account/notifications')->assertOk()->assertSee('Pause new notifications');
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $member->id, 'discussion_replies' => false]);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $other->id]);
        $reply = $thread->replies()->create(['author_id' => $author->id, 'body' => 'First approved reply.']);
        $review = 'https://canadamb.ca/admin/community/replies/';
        $this->actingAs($admin)->post($review.$reply->id, ['action' => 'approve', 'version' => $reply->contentVersion()])->assertRedirect();
        $this->assertDatabaseMissing('community_notifications', ['user_id' => $member->id, 'reply_id' => $reply->id]);
        $this->assertDatabaseHas('community_notifications', ['user_id' => $other->id, 'reply_id' => $reply->id]);
        $this->actingAs($member)->post($path, ['discussion_replies' => '1', 'job_summary' => '1'])->assertRedirect();
        $next = $thread->replies()->create(['author_id' => $author->id, 'body' => 'Second approved reply.']);
        $this->actingAs($admin)->post($review.$next->id, ['action' => 'approve', 'version' => $next->contentVersion()])->assertRedirect();
        $this->assertDatabaseHas('community_notifications', ['user_id' => $member->id, 'reply_id' => $next->id]);
        $this->assertDatabaseCount('community_thread_follows', 2);
        $this->assertDatabaseMissing('community_notifications', ['user_id' => $member->id, 'reply_id' => $reply->id]);
    }
}

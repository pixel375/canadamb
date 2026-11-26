<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BroadcastPreviewTest extends TestCase
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

    public function test_broadcast_preview_respects_separate_consent_and_does_not_send(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $consent = 'https://canadamb.ca/account/notifications/announcement-consent';
        $url = 'https://canadamb.ca/admin/notifications/preview';
        $draft = ['title' => 'Community announcement', 'body' => 'An example announcement.', 'audience' => 'all'];
        $this->actingAs($member)->post($consent, ['opted_in' => 1])->assertNotFound();
        config(['network.broadcast_previews' => true]);
        $this->post($consent, ['opted_in' => 1])->assertRedirect('/account/notifications');
        $this->post($url, $draft)->assertForbidden();
        $this->actingAs($admin)->post($url, $draft)->assertOk()->assertViewHas('count', 1)->assertSee('Nothing was sent')->assertDontSee($member->email);
        $this->post($url, array_replace($draft, ['audience' => 'selected', 'member_ids' => $other->id]))->assertOk()->assertViewHas('count', 0);
        $this->post($url, array_replace($draft, ['audience' => 'selected', 'member_ids' => $member->id.','.$member->id]))->assertOk()->assertViewHas('count', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'broadcast.previewed', 'actor_id' => $admin->id]);
        $this->actingAs($member)->post($consent, ['opted_in' => 0])->assertRedirect();
        $this->actingAs($admin)->post($url, $draft)->assertOk()->assertViewHas('count', 0);
        $this->assertDatabaseCount('community_notifications', 0);
        $this->assertDatabaseCount('owner_notifications', 0);
        $this->assertDatabaseCount('broadcast_consents', 1);
        $this->assertSame(0, (int) DB::table('broadcast_consents')->value('opted_in'));
    }
}

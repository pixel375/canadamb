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

    public function test_delivery_requires_fresh_confirmation_and_exact_consented_audience(): void
    {
        config(['network.broadcast_previews' => true, 'network.announcement_delivery' => true, 'network.prelaunch' => false]);
        $member = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $base = 'https://canadamb.ca';
        $draft = ['title' => 'Official community fixture', 'body' => 'A non-marketing community announcement.', 'audience' => 'all'];
        $this->actingAs($member)->post($base.'/account/notifications/announcement-consent', ['opted_in' => 1])->assertRedirect();
        $preview = $this->actingAs($admin)->post($base.'/admin/notifications/preview', $draft)->assertOk();
        $token = $preview->viewData('confirmation');
        $this->assertIsString($token);
        $this->assertDatabaseCount('announcements', 0);
        $this->post($base.'/admin/notifications/send', ['confirmation' => $token])->assertSessionHasErrors('confirm_send');
        DB::table('broadcast_consents')->where('user_id', $member->id)->update(['opted_in' => false]);
        $this->post($base.'/admin/notifications/send', ['confirmation' => $token, 'confirm_send' => 1])->assertConflict();
        $this->assertDatabaseCount('announcements', 0);
        DB::table('broadcast_consents')->where('user_id', $member->id)->update(['opted_in' => true]);
        $token = $this->post($base.'/admin/notifications/preview', $draft)->assertOk()->viewData('confirmation');
        $this->post($base.'/admin/notifications/send', ['confirmation' => $token, 'confirm_send' => 1])->assertRedirect('/admin/notifications');
        $this->post($base.'/admin/notifications/send', ['confirmation' => $token, 'confirm_send' => 1])->assertConflict();
        $this->assertDatabaseCount('announcements', 1);
        $receipt = DB::table('announcement_receipts')->sole();
        $read = '/account/notifications/announcements/'.$receipt->id.'/read';
        $this->actingAs($other)->get($base.'/account/notifications')->assertOk()->assertDontSee($draft['title']);
        $this->post($base.$read)->assertNotFound();
        $this->actingAs($member)->get('https://brandonmb.ca/account/notifications')->assertOk()->assertSee($draft['title']);
        $this->post('https://brandonmb.ca'.$read)->assertRedirect('/account/notifications');
        $this->assertNotNull(DB::table('announcement_receipts')->where('id', $receipt->id)->value('read_at'));
        config(['network.prelaunch' => true, 'network.prelaunch_ips' => ['127.0.0.1']]);
        $this->actingAs($admin)->post($base.'/admin/notifications/send', ['confirmation' => $token, 'confirm_send' => 1])->assertNotFound();
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

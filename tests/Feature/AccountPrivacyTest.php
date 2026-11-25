<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountPrivacyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => DatabaseSeeder::class])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            DB::table('account_privacy_requests')->delete();
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_privacy_intake_is_password_confirmed_private_and_never_deletes(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $url = 'https://canadamb.ca/account/privacy-requests';
        $queue = 'https://canadamb.ca/admin/privacy-requests';
        $data = ['kind' => 'deletion', 'confirm_request' => '1', 'current_password' => 'password'];
        $this->actingAs($member)->get($url)->assertNotFound();
        config(['network.privacy_requests' => true]);
        $this->get($url)->assertOk();
        $this->post($url, array_replace($data, ['current_password' => 'wrong']))->assertSessionHasErrors('current_password');
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasErrors('kind');
        $id = DB::table('account_privacy_requests')->value('id');
        $this->get($url)->assertSee('Reference #'.$id);
        $this->actingAs($other)->get($url)->assertDontSee('Reference #'.$id);
        $this->post($url.'/'.$id.'/withdraw', ['current_password' => 'password'])->assertNotFound();
        $this->get($queue)->assertForbidden();
        $this->actingAs($admin)->get($queue)->assertSee('Reference #'.$id);
        $this->get($queue.'?inspect='.$id)->assertOk()->assertSee('Record impact')->assertSee('Business ownership memberships')->assertSee('Private property photos')->assertSee('Saved listings')->assertDontSee($member->email);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.privacy-impact-inspected', 'actor_id' => $admin->id]);
        $note = 'Check shared records before arranging a response.';
        $this->post($queue.'/'.$id, ['note' => $note, 'current_password' => 'password'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($member)->get($url)->assertSee('In review')->assertDontSee($note);
        $this->assertDatabaseHas('users', ['id' => $member->id]);
        $this->post($url.'/'.$id.'/withdraw', ['current_password' => 'password'])->assertRedirect();
        $this->assertDatabaseHas('account_privacy_requests', ['id' => $id, 'status' => 'withdrawn']);
        $this->actingAs($admin)->get($queue.'?inspect='.$id)->assertNotFound();
        $this->get($queue.'?status=withdrawn&reference='.$id)->assertOk()->assertSee('Withdrawn by the member');
        $this->post($queue.'/'.$id, ['note' => $note, 'current_password' => 'password'])->assertConflict();
    }
}

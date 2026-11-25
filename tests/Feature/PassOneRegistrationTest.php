<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\NetworkSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PassOneRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_prelaunch_registration_uses_published_settings_and_manual_verification(): void
    {
        config(['network.prelaunch' => true, 'network.prelaunch_ips' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->post('https://canadamb.ca/admin/settings', ['prelaunch_test_registration_enabled' => 1])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'configuration.draft', 'actor_id' => $admin->id]);
        $this->assertFalse(app(NetworkSettings::class)->registrationAllowed());
        $this->post('https://canadamb.ca/admin/settings/publish')->assertRedirect();
        $this->post('https://canadamb.ca/account/logout')->assertRedirect();
        $this->get('https://canadamb.ca/register')->assertOk();
        Notification::fake();
        $this->post('https://canadamb.ca/register', [
            'name' => 'Taylor Tester', 'email' => ' TAYLOR@example.test ',
            'password' => 'Long-test-password-42', 'password_confirmation' => 'Long-test-password-42',
        ])->assertRedirect('/email/verify');
        $tester = User::where('email', 'taylor@example.test')->sole();
        $this->assertFalse($tester->hasVerifiedEmail());
        $this->assertSame('member', $tester->role);
        Notification::assertNothingSent();
        $this->get('https://canadamb.ca/email/verify')->assertOk()->assertSee('Prelaunch does not send verification email');
        $this->postJson("https://canadamb.ca/admin/test-accounts/$tester->id/verify")->assertForbidden();
        $this->flushSession()->withSession(['session_version' => $admin->fresh()->session_version])->actingAs($admin->fresh());
        $this->get('https://canadamb.ca/admin/test-accounts')->assertOk()->assertSee('taylor@example.test');
        $legacy = User::factory()->unverified()->create();
        $this->get('https://canadamb.ca/admin/test-accounts')->assertOk()->assertDontSee($legacy->email);
        $this->post("https://canadamb.ca/admin/test-accounts/$legacy->id/verify")->assertNotFound();
        $this->post("https://canadamb.ca/admin/test-accounts/$tester->id/verify")->assertRedirect('/admin/test-accounts');
        $this->post("https://canadamb.ca/admin/test-accounts/$tester->id/verify")->assertRedirect('/admin/test-accounts');
        $this->assertTrue($tester->fresh()->hasVerifiedEmail());
        $this->get('https://canadamb.ca/admin/test-accounts?status=verified&q=TAYLOR')->assertOk()->assertSee($tester->email)
            ->assertDontSee('action="/admin/test-accounts/'.$tester->id.'/verify"', false);
        $this->get('https://canadamb.ca/admin/test-accounts?status=pending')->assertOk()->assertDontSee($tester->email);
        $this->get('https://canadamb.ca/admin/test-accounts?status=verified&q=missing')->assertOk()->assertDontSee($tester->email);
        $this->assertDatabaseHas('audit_logs', ['action' => 'identity.prelaunch.verified', 'actor_id' => $admin->id]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'identity.prelaunch.verified')->count());
        $this->post("https://canadamb.ca/admin/test-accounts/$admin->id/verify")->assertForbidden();
        $this->post('https://canadamb.ca/admin/theme/rollback')->assertRedirect();
        $this->post('https://canadamb.ca/account/logout')->assertRedirect();
        $this->get('https://canadamb.ca/register')->assertNotFound();
        $this->post('https://canadamb.ca/register', [])->assertNotFound();
        $this->post('https://canadamb.ca/login', ['email' => $tester->email, 'password' => 'Long-test-password-42'])->assertRedirect();
        $this->get('https://canadamb.ca/marketplace/new')->assertOk();
    }

    public function test_published_switch_does_not_override_hosts_or_ip_gate_and_only_super_admin_can_change_it(): void
    {
        config(['network.prelaunch' => true, 'network.prelaunch_ips' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        $this->actingAs(User::factory()->create(['role' => 'network_admin']));
        $this->post('https://canadamb.ca/admin/settings', ['prelaunch_test_registration_enabled' => 1])->assertForbidden();
        $this->post('https://canadamb.ca/admin/settings/publish')->assertForbidden();
        $this->get('https://canadamb.ca/admin/test-accounts')->assertForbidden();
        $account = User::factory()->unverified()->create();
        $this->post("https://canadamb.ca/admin/test-accounts/$account->id/verify")->assertForbidden();
        $admin = User::factory()->create(['role' => 'super_admin']);
        app(NetworkSettings::class)->saveDraft($admin->id, fn ($settings) => ['prelaunch_test_registration_enabled' => true] + $settings);
        app(NetworkSettings::class)->publish($admin->id);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11']);
        $this->get('https://canadamb.ca/register', ['X-Forwarded-For' => '192.0.2.10'])->assertForbidden();
        $this->post('https://canadamb.ca/register', [], ['X-Forwarded-For' => '192.0.2.10'])->assertForbidden();
        $this->post("https://canadamb.ca/admin/test-accounts/$account->id/verify")->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        $this->get('https://brandonmb.ca/register')->assertNotFound();
        $this->post('https://brandonmb.ca/register', [])->assertNotFound();
        $this->get('https://unknown.example/register')->assertNotFound();
        $this->actingAs($admin)->post("https://brandonmb.ca/admin/test-accounts/$account->id/verify")->assertNotFound();
        config(['network.prelaunch' => false]);
        $this->post("https://canadamb.ca/admin/test-accounts/$account->id/verify")->assertNotFound();
        $this->assertFalse($account->fresh()->hasVerifiedEmail());
    }

    public function test_registration_validates_and_throttles_failed_attempts(): void
    {
        User::factory()->create(['email' => 'Existing@example.test']);
        $origin = 'https://canadamb.ca/register';
        $this->post($origin, [])->assertSessionHasErrors(['name', 'email', 'password']);
        $this->from($origin)->post($origin, ['email' => ['invalid']])->assertSessionHasErrors('email');
        $this->withCookie(config('session.cookie'), session()->getId())
            ->get($origin)->assertOk()->assertSee('The email field must be a string.');
        $this->post($origin, ['name' => 'Tester', 'email' => 'valid@example.test', 'password' => 'justlettersandmore', 'password_confirmation' => 'justlettersandmore'])->assertSessionHasErrors('password');
        $valid = ['name' => 'Tester', 'email' => ' EXISTING@example.test ', 'password' => 'Long-test-password-42', 'password_confirmation' => 'Long-test-password-42'];
        $this->post($origin, $valid)->assertSessionHasErrors('email');
        $this->post($origin, ['password_confirmation' => 'mismatch'] + $valid)->assertSessionHasErrors('password');
        $this->post($origin, [])->assertSessionHasErrors('name');
        $this->post('https://canadamb.ca/%72egister', $valid)->assertTooManyRequests();
        $this->assertDatabaseCount('users', 1);
    }
}

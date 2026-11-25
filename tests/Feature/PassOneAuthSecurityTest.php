<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\NetworkSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PassOneAuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_prelaunch_password_recovery_discloses_no_delivery_and_creates_no_tokens(): void
    {
        Notification::fake();
        config(['network.prelaunch' => true, 'network.prelaunch_ips' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        $member = User::factory()->create();
        $url = 'https://canadamb.ca/forgot-password';
        $this->get($url)->assertOk()->assertSee('Password reset email is unavailable during prelaunch')->assertDontSee('Send reset link');
        $this->post($url, ['email' => $member->email])->assertRedirect('/forgot-password')->assertSessionMissing('status');
        $this->post($url, ['email' => 'absent@example.test'])->assertRedirect('/forgot-password')->assertSessionMissing('status');
        $this->get('https://canadamb.ca/reset-password/synthetic-token')->assertRedirect('/forgot-password');
        $this->post('https://canadamb.ca/reset-password', [])->assertRedirect('/forgot-password');
        $this->post('https://brandonmb.ca/forgot-password', [])->assertNotFound();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_mail_enabled_reset_requests_and_invalid_tokens_do_not_disclose_account_existence(): void
    {
        Notification::fake();
        $member = User::factory()->create();
        $absent = 'absent@example.test';
        $origin = 'https://canadamb.ca';
        $known = $this->postJson($origin.'/forgot-password', ['email' => $member->email])->assertOk()->json();
        $this->postJson($origin.'/forgot-password', ['email' => $absent])->assertOk()->assertExactJson($known);
        $password = ['password' => 'Replacement-test-42', 'password_confirmation' => 'Replacement-test-42'];
        $knownFailure = $this->postJson($origin.'/reset-password', ['email' => $member->email, 'token' => 'invalid'] + $password)->assertUnprocessable()->json();
        $this->postJson($origin.'/reset-password', ['email' => $absent, 'token' => 'invalid'] + $password)->assertUnprocessable()->assertExactJson($knownFailure);
        Notification::assertSentTo($member, ResetPassword::class, function (ResetPassword $notice) use ($origin, $member, $password) {
            $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
            $this->postJson($origin.'/reset-password', ['email' => $member->email, 'token' => $notice->token] + $password)->assertUnprocessable();

            return true;
        });
        $this->assertTrue(Hash::check('password', $member->fresh()->password));
    }

    public function test_registration_and_manual_verification_require_csrf(): void
    {
        config(['network.prelaunch' => true, 'network.prelaunch_ips' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $account = User::factory()->unverified()->create();
        app(NetworkSettings::class)->saveDraft($admin->id, fn ($settings) => ['prelaunch_test_registration_enabled' => true] + $settings);
        app(NetworkSettings::class)->publish($admin->id);

        // Laravel skips CSRF while its application environment is testing.
        $this->app->instance('env', 'production');
        $this->post('https://canadamb.ca/register', [])->assertStatus(419);
        $this->actingAs($admin)->post("https://canadamb.ca/admin/test-accounts/$account->id/verify", ['_token' => 'forged'])->assertStatus(419);
        $this->assertFalse($account->fresh()->hasVerifiedEmail());
        $this->assertDatabaseCount('users', 2);
    }

    public function test_authenticated_password_change_requires_current_password_and_revokes_network_sessions(): void
    {
        $user = User::factory()->create();
        $url = 'https://canadamb.ca/account/settings/password';
        $replacement = ['password' => 'Replacement-test-42', 'password_confirmation' => 'Replacement-test-42'];
        $this->post($url, $replacement)->assertRedirect('/login');
        $this->actingAs($user)->post($url, ['current_password' => 'incorrect'] + $replacement)->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->post($url, ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post($url, ['current_password' => 'password'] + $replacement)->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertTrue(Hash::check('Replacement-test-42', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password-changed', 'actor_id' => $user->id]);
        $this->actingAs($user->fresh())->withSession(['session_version' => 0, 'session_host' => 'brandonmb.ca'])
            ->get('https://brandonmb.ca/account/profile')->assertRedirect('/auth/start');
    }

    public function test_profile_name_changes_are_versioned_and_unchanged_saves_are_not_audited(): void
    {
        $user = User::factory()->create(['name' => 'Original Name']);
        $path = 'https://canadamb.ca/account/settings';
        $version = $user->profileVersion();
        $this->actingAs($user)->get($path)->assertOk()->assertSee($version);
        $this->post($path, ['name' => 'Original Name', 'version' => $version])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.profile-updated']);
        $this->post('https://brandonmb.ca/account/settings', ['name' => 'Changed Name', 'version' => $version])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($path, ['name' => 'Stale Name', 'version' => $version])->assertSessionHasErrors('version');
        $this->assertSame('Changed Name', $user->fresh()->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.profile-updated', 'actor_id' => $user->id]);
    }

    public function test_malformed_credentials_render_validation_and_login_is_throttled(): void
    {
        $url = 'https://canadamb.ca/login';
        $this->post($url, ['email' => ['invalid'], 'password' => 'example'])->assertSessionHasErrors('email');
        $this->get($url.'?email%5B%5D=ignored')->assertOk();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post($url, ['email' => 'guest@example.test', 'password' => 'invalid'])->assertSessionHasErrors('email');
        }
        $this->post($url, ['email' => 'GUEST@example.test', 'password' => 'invalid'])->assertTooManyRequests();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Support\Identity;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IdentityTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_unverified_community_user_is_sent_to_central_verification(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get('https://brandonmb.ca/account/listings')
            ->assertRedirect('https://canadamb.ca/email/verify');
        $this->postJson('https://brandonmb.ca/api/v1/marketplace', [])->assertForbidden();
    }

    public function test_registration_verification_and_password_reset_use_the_central_identity(): void
    {
        Notification::fake();
        $this->post('https://canadamb.ca/register', ['name' => 'Short', 'email' => 'short@example.test',
            'password' => 'Test123', 'password_confirmation' => 'Test123'])
            ->assertSessionHasErrors('password');
        $this->post('https://canadamb.ca/register', ['name' => 'Taylor', 'email' => 'Taylor@example.test',
            'password' => 'Test4567', 'password_confirmation' => 'Test4567'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $user = User::sole();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->get('https://canadamb.ca/marketplace/new')->assertRedirect();
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $email) use ($user) {
            $link = $email->toMail($user)->actionUrl;
            $this->assertStringStartsWith('https://canadamb.ca/email/verify/', $link);
            $this->get($link)->assertRedirect();

            return true;
        });
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->post('https://canadamb.ca/account/logout')->assertRedirect();
        $this->post('https://canadamb.ca/forgot-password', ['email' => $user->email])->assertRedirect();
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $email) use ($user) {
            $this->post('https://canadamb.ca/reset-password', [
                'email' => $user->email, 'token' => $email->token, 'password' => 'Reset567',
                'password_confirmation' => 'Reset567',
            ])->assertSessionHasNoErrors()->assertRedirect();

            return true;
        });
        $this->assertTrue(Hash::check('Reset567', $user->fresh()->password));
        $this->assertSame(2, $user->fresh()->session_version);
    }

    public function test_local_login_starts_pkce_at_central_host_and_rejects_a_forged_state(): void
    {
        Identity::provision(Site::with('domain')->where('code', 'brandon')->sole());
        $start = $this->get('https://brandonmb.ca/auth/start')->assertRedirect();
        $url = $start->headers->get('Location');
        $this->assertStringStartsWith('https://canadamb.ca/oauth/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $flow);
        $this->assertSame('S256', $flow['code_challenge_method']);
        $this->assertSame('https://brandonmb.ca/auth/callback', $flow['redirect_uri']);
        $this->assertStringNotContainsString('code_verifier', $url);
        $this->get('https://brandonmb.ca/auth/callback?code=fake&state='.str_repeat('x', 64))->assertForbidden();
        $this->assertGuest();
    }
}

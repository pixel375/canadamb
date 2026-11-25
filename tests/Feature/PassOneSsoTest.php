<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Support\Identity;
use App\Support\NetworkUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PassOneSsoTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_central_password_login_and_real_pkce_exchange_rotate_sessions_and_revoke_tokens(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'canadamb-openssl-');
        try {
            file_put_contents($configPath, "[req]\ndistinguished_name=dn\n[dn]\n");
            $options = ['config' => $configPath, 'private_key_bits' => 2048];
            $key = openssl_pkey_new($options);
            $this->assertNotFalse($key);
            $this->assertTrue(openssl_pkey_export($key, $private, null, $options));
            config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
        } finally {
            unlink($configPath);
        }
        Identity::provision(Site::with('domain')->where('code', 'brandon')->sole());
        $user = User::factory()->create();
        $path = 'https://brandonmb.ca/account/listings?tab=active';

        $authorize = $this->withSession(['url.intended' => $path])->get('https://brandonmb.ca/auth/start')->assertRedirect()->headers->get('Location');
        $flows = session('community_oauth');
        $this->flushSession();
        $login = $this->get($authorize)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://canadamb.ca/login?continuation=', $login);
        parse_str(parse_url($login, PHP_URL_QUERY), $firstLogin);
        $this->get($login)->assertOk()->assertSee('name="continuation" value="'.$firstLogin['continuation'].'"', false);
        $this->get('https://canadamb.ca/register?continuation='.$firstLogin['continuation'])->assertOk()
            ->assertSee('href="/login?continuation='.$firstLogin['continuation'].'">Back to sign in', false);
        // A second flow may reach the login page before the first tab submits.
        $secondAuthorize = preg_replace('/([?&]state=)[a-zA-Z0-9]{64}/', '${1}'.str_repeat('z', 64), $authorize);
        $second = $this->get($secondAuthorize)->assertRedirect()->headers->get('Location');
        $this->assertNotSame($login, $second);
        $centralSessionId = session()->getId();
        $this->post('https://canadamb.ca/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $destination = $this->post('https://canadamb.ca/login', ['email' => strtoupper($user->email), 'password' => 'password'] + $firstLogin)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://canadamb.ca/oauth/authorize?', $destination);
        parse_str(parse_url($authorize, PHP_URL_QUERY), $originalParameters);
        parse_str(parse_url($destination, PHP_URL_QUERY), $returnedParameters);
        $this->assertEquals($originalParameters, $returnedParameters);
        $this->assertNotSame($centralSessionId, session()->getId());
        $this->assertSame('canadamb.ca', session('session_host'));
        $secondDestination = $this->get($second)->assertRedirect()->headers->get('Location');
        parse_str(parse_url($secondDestination, PHP_URL_QUERY), $secondParameters);
        $this->assertSame(str_repeat('z', 64), $secondParameters['state']);
        $callback = $this->get($destination)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://brandonmb.ca/auth/callback?', $callback);
        $this->assertStringNotContainsString('access_token', $callback);

        Auth::forgetGuards();
        $this->flushSession()->withSession(['community_oauth' => $flows]);
        $localSessionId = session()->getId();
        $this->get($callback)->assertRedirect($path)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($localSessionId, session()->getId());
        $this->assertSame('brandonmb.ca', session('session_host'));
        $this->assertDatabaseHas('oauth_auth_codes', ['user_id' => $user->id, 'revoked' => 1]);
        $this->assertDatabaseHas('oauth_access_tokens', ['user_id' => $user->id, 'revoked' => 1]);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['revoked' => 1]);
        $this->get($callback)->assertForbidden()->assertSee('Try again');
        Auth::forgetGuards();
        $this->flushSession()->withSession(['community_oauth' => $flows]);
        $this->get($callback)->assertForbidden()->assertSee('Try again');
        $this->actingAs($user)->withSession(['session_host' => 'brandonmb.ca', 'session_version' => 0]);

        $this->post('https://brandonmb.ca/account/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->assertSame(1, $user->fresh()->session_version);
        $this->actingAs($user->fresh())->withSession(['session_host' => 'canadamb.ca', 'session_version' => 0]);
        $this->get('https://canadamb.ca/account/listings')->assertRedirect('/login');
    }

    public function test_state_is_per_tab_and_rejects_expiry_cancellation_client_changes_and_missing_sessions(): void
    {
        $site = Site::with('domain')->where('code', 'brandon')->sole();
        $this->get('https://brandonmb.ca/auth/start')->assertStatus(503);
        Identity::provision($site);
        $local = 'https://brandonmb.ca';
        $start = $this->withSession(['url.intended' => 'https://brandonmb.ca/%2f%2fevil.example'])->get($local.'/auth/start')->assertRedirect();
        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY), $first);
        $this->assertSame('/account/listings', session('community_oauth.'.$first['state'].'.path'));
        $start = $this->get($local.'/auth/start')->assertRedirect();
        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY), $second);
        $this->get($local.'/auth/callback?error=access_denied&state='.$first['state'])->assertForbidden();
        $this->assertNull(session('community_oauth.'.$first['state']));
        $this->assertNotNull(session('community_oauth.'.$second['state']));
        $this->travel(11)->minutes();
        $this->get($local.'/auth/callback?code=fake&state='.$second['state'])->assertForbidden();
        $this->travelBack();
        $start = $this->get($local.'/auth/start')->assertRedirect();
        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY), $third);
        $flow = session('community_oauth.'.$third['state']);
        $flow['client'] = 'another-client';
        $this->withSession(['community_oauth' => [$third['state'] => $flow]])
            ->get($local.'/auth/callback?code=fake&state='.$third['state'])->assertForbidden();
        $this->flushSession()->get($local.'/auth/callback?code=fake&state='.$third['state'])->assertForbidden();
        DB::table('oauth_clients')->update(['revoked' => true]);
        $this->get($local.'/auth/start')->assertStatus(503);
        $this->assertGuest();
    }

    public function test_registration_started_from_brandon_uses_only_a_configured_community(): void
    {
        $site = Site::with('domain')->where('code', 'brandon')->sole();
        Identity::provision($site);
        $central = 'https://canadamb.ca';
        $this->get('https://brandonmb.ca/')->assertSee($central.'/register?community='.$site->id, false);
        $this->get($central.'/register?community=https%3A%2F%2Fevil.example')->assertOk()->assertDontSee('name="community"', false);
        $this->get($central.'/register?community='.$site->id)->assertOk()->assertSee('name="community" value="'.$site->id.'"', false);
        $this->post($central.'/register', [
            'name' => 'Brandon Tester', 'email' => 'brandon@example.test', 'community' => $site->id,
            'password' => 'A-long-test-password-44', 'password_confirmation' => 'A-long-test-password-44',
        ])->assertRedirect('/email/verify');
        $this->assertSame('https://brandonmb.ca/auth/start', session('url.intended'));
        $this->get($central.'/email/verify')->assertOk();
        $account = User::sole();
        $account->markEmailAsVerified();
        $this->actingAs($account->fresh())->get($central.'/email/verify')->assertRedirect('https://brandonmb.ca/auth/start');
    }

    public function test_cancelled_valid_community_sign_in_can_retry_the_original_destination(): void
    {
        $site = Site::with('domain')->where('code', 'brandon')->sole();
        Identity::provision($site);
        $target = 'https://brandonmb.ca/account/settings';
        $start = $this->withSession(['url.intended' => $target])->get('https://brandonmb.ca/auth/start')->assertRedirect();
        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY), $first);
        $this->get('https://brandonmb.ca/auth/callback?error=access_denied&state='.$first['state'])
            ->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($target, session('url.intended'));
        $retry = $this->get('https://brandonmb.ca/auth/start')->assertRedirect();
        parse_str(parse_url($retry->headers->get('Location'), PHP_URL_QUERY), $second);
        $this->assertNotSame($first['state'], $second['state']);
        $this->assertSame('/account/settings', session('community_oauth.'.$second['state'].'.path'));
        $this->assertNull(session('community_oauth.'.$first['state']));
    }

    public function test_return_urls_retain_safe_queries_and_reject_host_and_path_confusion(): void
    {
        $site = Site::with('domain')->where('code', 'brandon')->sole();
        foreach (['//evil.example/', 'http://brandonmb.ca/account', 'https://brandonmb.ca.evil.example/account',
            'https://brandonmb.ca@evil.example/account', 'https://brandonmb.ca/%5cevil.example/',
            'https://brandonmb.ca/%252f%252fevil.example/', 'https://brandonmb.ca/a/../auth/start',
            'https://brandonmb.ca/account#https://evil.example'] as $url) {
            $this->assertNull(NetworkUrl::pathOn($site, $url), $url);
        }
        $this->assertSame('/marketplace?search=a%20b&sort=recent', NetworkUrl::pathOn($site, 'https://brandonmb.ca/marketplace?search=a%20b&sort=recent'));
    }

    public function test_a_tabs_continuation_takes_priority_over_another_tabs_validation_input(): void
    {
        $current = str_repeat('a', 40);
        $other = str_repeat('b', 40);
        $this->withSession(['_old_input' => ['continuation' => $other]])
            ->withCookie(config('session.cookie'), session()->getId())
            ->get('https://canadamb.ca/login?continuation='.$current)
            ->assertOk()->assertSee('name="continuation" value="'.$current.'"', false)
            ->assertDontSee('name="continuation" value="'.$other.'"', false);
    }
}

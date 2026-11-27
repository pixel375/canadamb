<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\Identity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrelaunchTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_only_allowed_peer_ips_can_reach_both_prelaunch_portals(): void
    {
        config(['network.prelaunch' => true, 'network.prelaunch_ips' => ['192.0.2.10']]);
        Identity::provision(Site::with('domain')->where('code', 'brandon')->sole());

        foreach (['canadamb.ca', 'brandonmb.ca'] as $host) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
                ->get("https://$host/", ['X-Forwarded-For' => '192.0.2.10'])
                ->assertForbidden()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            foreach (['/safety', '/privacy-overview', '/contact', '/admin/readiness'] as $path) {
                $this->get("https://$host".$path)->assertForbidden()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            }
            $this->get("https://$host/robots.txt")
                ->assertOk()->assertSee('Disallow: /', false)->assertDontSee('Sitemap:', false);
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
                ->get("https://$host/")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $this->get("https://$host/safety")->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $url = $this->get('https://brandonmb.ca/auth/start')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://canadamb.ca/oauth/authorize?', $url);
        $this->get('https://canadamb.ca/register')->assertNotFound();
        $this->post('https://canadamb.ca/register', [])->assertNotFound();

        config(['network.prelaunch' => false]);
        $this->get('https://canadamb.ca/register')->assertOk();
    }

    public function test_password_gate_replaces_ip_access_and_uses_host_bound_secure_cookie(): void
    {
        config(['network.prelaunch' => true, 'network.prelaunch_ips' => ['192.0.2.10'],
            'network.prelaunch_password_hash' => password_hash('preview-fixture', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        foreach (['canadamb.ca', 'brandonmb.ca'] as $host) {
            $this->get("https://$host/marketplace?view=all")->assertForbidden()->assertSee('Prelaunch access')
                ->assertSee('value="/marketplace?view=all"', false)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->get("https://$host/robots.txt")->assertOk()->assertSee('Disallow: /');
            $this->getJson("https://$host/api/v1/marketplace")->assertForbidden();
        }
        $this->post('https://canadamb.ca/prelaunch-access', ['preview_password' => 'incorrect'])->assertForbidden()->assertSee('Incorrect password');
        $response = $this->post('https://canadamb.ca/prelaunch-access', ['preview_password' => 'preview-fixture', 'return_to' => '/marketplace?view=all']);
        $response->assertStatus(303)->assertRedirect('/marketplace?view=all');
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === '__Host-canadamb-preview');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('', (string) $cookie->getDomain());
        $this->withUnencryptedCookie('__Host-canadamb-preview', $cookie->getValue())
            ->get('https://canadamb.ca/')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('https://canadamb.ca/register')->assertNotFound();
        $this->get('https://brandonmb.ca/')->assertForbidden();
        $this->withUnencryptedCookie('__Host-canadamb-preview', $cookie->getValue().'x')->get('https://canadamb.ca/')->assertForbidden();
        $this->post('https://brandonmb.ca/prelaunch-access', ['preview_password' => 'preview-fixture', 'return_to' => '//external.example/'])
            ->assertStatus(303)->assertRedirect('/');
        config(['network.prelaunch_password_hash' => 'invalid']);
        $this->get('https://canadamb.ca/')->assertForbidden();
    }

    public function test_news_polling_is_disabled_in_prelaunch_even_if_enabled_in_environment(): void
    {
        config(['network.prelaunch' => true, 'network.news_polling' => true]);

        $this->artisan('news:poll')->expectsOutput('News feed polling is disabled.')->assertSuccessful();
    }
}

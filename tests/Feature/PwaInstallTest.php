<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\NetworkSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaInstallTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_manifests_and_branded_icons_are_independent_on_each_domain(): void
    {
        foreach ([
            'canadamb.ca' => ['CanadaMB', '#E85D75'],
            'brandonmb.ca' => ['BrandonMB', '#22A06B'],
        ] as $host => [$name, $colour]) {
            $manifest = $this->get("https://$host/app.webmanifest");
            $manifest->assertOk()
                ->assertHeader('Content-Type', 'application/manifest+json; charset=utf-8')
                ->assertJsonPath('name', $name)
                ->assertJsonPath('short_name', $name)
                ->assertJsonPath('id', '/')
                ->assertJsonPath('scope', '/')
                ->assertJsonPath('display', 'standalone')
                ->assertJsonPath('theme_color', $colour)
                ->assertJsonPath('icons.0.src', '/pwa/icon/192.png')
                ->assertJsonPath('icons.1.src', '/pwa/icon/512.png');

            $this->get("https://$host/")
                ->assertOk()
                ->assertSee('<link rel="manifest" href="/app.webmanifest">', false)
                ->assertSee('href="/pwa/icon/180.png"', false)
                ->assertSee('id="pwa-install-button"', false)
                ->assertSee('Install App');

            foreach ([180, 192, 512] as $size) {
                $icon = $this->get("https://$host/pwa/icon/$size.png");
                $icon->assertOk()->assertHeader('Content-Type', 'image/png');
                $dimensions = getimagesizefromstring($icon->getContent());
                $this->assertNotFalse($dimensions);
                $this->assertSame([$size, $size], array_slice($dimensions, 0, 2));
                $this->assertSame('image/png', $dimensions['mime']);
            }
            $this->get("https://$host/pwa/icon/1024.png")->assertNotFound();
        }
    }

    public function test_admin_can_disable_installation_after_publishing_settings(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $modules = array_fill_keys(array_keys(NetworkSettings::OPERATIONAL_MODULES), 1);
        $this->actingAs($admin)->post('https://canadamb.ca/admin/settings', [
            'modules' => $modules,
            'overrides' => $modules,
        ])->assertRedirect();
        $this->post('https://canadamb.ca/admin/settings/publish')->assertRedirect();

        foreach (['canadamb.ca', 'brandonmb.ca'] as $host) {
            $this->get("https://$host/app.webmanifest")->assertNotFound();
            $this->get("https://$host/pwa/icon/192.png")->assertNotFound();
            $this->get("https://$host/")
                ->assertOk()
                ->assertDontSee('rel="manifest"', false)
                ->assertDontSee('id="pwa-install-button"', false);
        }

        $this->post('https://canadamb.ca/admin/settings', [
            'modules' => $modules,
            'overrides' => $modules,
            'pwa_install_enabled' => 1,
        ])->assertRedirect();
        $this->post('https://canadamb.ca/admin/settings/publish')->assertRedirect();

        $this->get('https://canadamb.ca/app.webmanifest')->assertOk();
        $this->get('https://brandonmb.ca/app.webmanifest')->assertOk();
    }

    public function test_prelaunch_guard_also_protects_manifest_and_icons(): void
    {
        config(['network.prelaunch' => true, 'network.prelaunch_ips' => ['192.0.2.10']]);

        foreach (['canadamb.ca', 'brandonmb.ca'] as $host) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
                ->get("https://$host/app.webmanifest")
                ->assertForbidden()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
                ->get("https://$host/pwa/icon/512.png")
                ->assertForbidden();

            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
                ->get("https://$host/app.webmanifest")
                ->assertOk()->assertHeader('Cache-Control', 'private, no-store');
        }
    }
}

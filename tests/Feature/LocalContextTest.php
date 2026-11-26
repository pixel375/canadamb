<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\NetworkSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocalContextTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_weather_is_opt_in_encrypted_scoped_cached_and_never_exposes_credentials(): void
    {
        Http::preventStrayRequests();
        $place = Location::where('slug', 'brandon')->sole();
        $place->update(['latitude' => 49.84, 'longitude' => -99.95]);
        $path = '/local-weather/'.$place->id;
        $base = 'https://canadamb.ca';
        $key = 'fixture-weather-key-123456789';
        $this->getJson('https://brandonmb.ca'.$path)->assertNotFound();
        $settings = app(NetworkSettings::class);
        $data = ['configuration_version' => NetworkSettings::stateVersion($settings->state()), 'local_clock_enabled' => '1',
            'clock_format' => '24', 'weather_enabled' => '1', 'credential_action' => 'replace', 'weather_api_key' => $key];
        $this->actingAs(User::factory()->create())->post($base.'/admin/local-context', $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->post($base.'/admin/local-context', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->get($base.'/admin/local-context')->assertOk()->assertSee('Configured (hidden)')->assertDontSee($key);
        $revision = DB::table('configuration_revisions')->where('id', $settings->state()->draft_id)->value('settings');
        $this->assertStringNotContainsString($key, $revision);
        $this->assertArrayHasKey('weather_key_encrypted', json_decode($revision, true));
        $this->post($base.'/admin/local-context', array_replace($data, ['weather_api_key' => 'invalid']))->assertSessionHasErrors('weather_api_key');
        $this->assertNull(session()->getOldInput('weather_api_key'));
        $this->getJson('https://brandonmb.ca'.$path)->assertNotFound();
        $this->post($base.'/admin/settings/publish', ['configuration_version' => NetworkSettings::stateVersion($settings->state())])->assertRedirect();
        $this->get('https://brandonmb.ca/')->assertOk()->assertSee($path)->assertDontSee($key);
        $other = Location::where('slug', 'winnipeg')->sole();
        $this->getJson('https://brandonmb.ca/local-weather/'.$other->id)->assertNotFound();
        Http::fakeSequence('https://api.weatherapi.com/v1/current.json*')
            ->push(['current' => ['temp_c' => -12.3, 'condition' => ['text' => 'Cloudy'], 'last_updated_epoch' => now()->timestamp]])
            ->push(['error' => ['message' => 'Provider unavailable']], 503);
        $this->getJson('https://brandonmb.ca'.$path)->assertOk()->assertJsonPath('weather.temperature_c', -12)->assertDontSee($key);
        $this->getJson($base.$path)->assertOk()->assertJsonPath('weather.condition', 'Cloudy');
        Http::assertSentCount(1);
        $this->travel(46)->minutes();
        $this->getJson($base.$path)->assertStatus(503)->assertJsonPath('weather', null)->assertDontSee($key);
        $this->getJson($base.$path)->assertStatus(503)->assertJsonPath('weather', null);
        Http::assertSentCount(2);
    }

    public function test_weather_cache_is_coordinate_bound_and_expires_with_observation_age(): void
    {
        Http::preventStrayRequests();
        $place = Location::where('slug', 'brandon')->sole();
        $place->update(['latitude' => 49.84, 'longitude' => -99.95]);
        $settings = app(NetworkSettings::class);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $settings->saveDraft($admin->id, fn (array $current) => array_replace($current, [
            'weather_enabled' => true, 'weather_key_encrypted' => Crypt::encryptString('fixture-weather-key-123456789'),
        ]));
        $settings->publish($admin->id);
        Http::fakeSequence('https://api.weatherapi.com/v1/current.json*')
            ->push(['current' => ['temp_c' => -10, 'condition' => ['text' => 'Cloudy'], 'last_updated_epoch' => now()->subMinutes(115)->timestamp]])
            ->push(['current' => ['temp_c' => -5, 'condition' => ['text' => 'Clear'], 'last_updated_epoch' => now()->timestamp]])
            ->push(['current' => ['temp_c' => 2, 'condition' => ['text' => 'Sunny'], 'last_updated_epoch' => now()->timestamp]]);
        $url = 'https://brandonmb.ca/local-weather/'.$place->id;
        $this->getJson($url)->assertOk()->assertJsonPath('weather.temperature_c', -10);
        $this->getJson($url)->assertOk()->assertJsonPath('weather.temperature_c', -10);
        Http::assertSentCount(1);
        $this->travel(6)->minutes();
        $this->getJson($url)->assertOk()->assertJsonPath('weather.temperature_c', -5);
        $place->update(['latitude' => 49.86]);
        $this->getJson($url)->assertOk()->assertJsonPath('weather.temperature_c', 2);
        Http::assertSentCount(3);
    }

    public function test_community_experience_overrides_are_role_protected_local_and_cannot_enable_disabled_utilities(): void
    {
        Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $operator = User::factory()->create(['role' => 'network_admin']);
        $site = Site::where('code', 'brandon')->sole();
        $site->location->update(['latitude' => 49.84, 'longitude' => -99.95]);
        $settings = app(NetworkSettings::class);
        $settings->saveDraft($admin->id, fn (array $current) => array_replace($current, [
            'weather_enabled' => true, 'weather_key_encrypted' => Crypt::encryptString('fixture-weather-key-123456789'),
        ]));
        $settings->publish($admin->id);
        $base = 'https://canadamb.ca/admin/communities/'.$site->id;
        $data = ['version' => $site->configurationVersion(), 'preset' => 'forest', 'clock' => 'inherit', 'clock_format' => '24', 'weather' => 'hide'];
        $this->actingAs($operator)->post($base.'/experience', $data)->assertForbidden();
        $this->actingAs($admin)->get($base)->assertOk()->assertSee('Community appearance and utilities');
        $this->post($base.'/experience', $data)->assertRedirect($base)->assertSessionHasNoErrors();
        $this->post($base.'/experience', $data)->assertSessionHasErrors('version');
        $this->get('https://brandonmb.ca/')->assertOk()->assertSee('#166534')->assertSee('data-clock-format="24"', false)->assertDontSee('data-local-weather=', false);
        $this->get('https://canadamb.ca/?location=brandon')->assertOk()->assertDontSee('#166534')->assertSee('data-clock-format="12"', false)->assertSee('data-local-weather=', false);
        $this->getJson('https://brandonmb.ca/local-weather/'.$site->location_id)->assertNotFound();
        Http::assertNothingSent();
        $this->post($base.'/experience', array_replace($data, ['version' => $site->fresh()->configurationVersion(), 'weather' => 'show']))->assertSessionHasErrors('weather');
        $this->actingAs($operator)->put($base, [
            'version' => $site->fresh()->configurationVersion(), 'name' => $site->name, 'description' => $site->description,
            'accent_type' => 'solid', 'accent_colors' => '#228855', 'accent_thickness' => 4, 'accent_direction' => 90,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('forest', $site->fresh()->overrides['experience']['preset']);
        $this->actingAs($admin)->post($base.'/experience', array_replace($data, [
            'version' => $site->fresh()->configurationVersion(), 'preset' => 'inherit', 'clock' => 'inherit', 'clock_format' => 'inherit', 'weather' => 'inherit',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $settings->saveDraft($admin->id, fn (array $current) => array_replace($current, ['local_clock_enabled' => false, 'weather_enabled' => false]));
        $settings->publish($admin->id);
        $this->get('https://brandonmb.ca/')->assertOk()->assertDontSee('#166534')->assertDontSee('data-manitoba-clock', false)->assertDontSee('data-local-weather=', false);
        $this->getJson('https://brandonmb.ca/local-weather/'.$site->location_id)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.experience', 'site_id' => $site->id]);
    }
}

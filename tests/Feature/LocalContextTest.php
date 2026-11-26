<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use App\Support\NetworkSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

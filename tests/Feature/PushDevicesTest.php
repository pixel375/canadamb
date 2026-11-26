<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Identity;
use App\Support\PushDevices;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PushDevicesTest extends TestCase
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

    public function test_explicit_device_registration_is_encrypted_scoped_and_revocable(): void
    {
        $key = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $point = $key(chr(4).str_repeat('a', 64));
        $data = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/test-fixture', 'keys' => ['p256dh' => $point, 'auth' => $key(str_repeat('b', 16))]];
        $url = 'https://canadamb.ca/account/notifications/devices';
        $member = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($member)->postJson($url, $data)->assertNotFound();
        config(['network.push_devices' => true, 'network.push_public_key' => $point]);
        $this->get('https://canadamb.ca/account/notifications')->assertOk()->assertSee('Register this device')->assertSee('Delivery is not available yet');
        foreach (['http://fcm.googleapis.com/test', 'https://fcm.googleapis.com.evil.test/test', 'https://127.0.0.1/test', 'https://fcm.googleapis.com:443/test'] as $endpoint) {
            $this->postJson($url, array_replace($data, ['endpoint' => $endpoint]))->assertUnprocessable();
        }
        $this->postJson($url, $data)->assertOk()->assertJsonPath('registered', true);
        $this->postJson($url, $data)->assertOk();
        $this->assertDatabaseCount('push_devices', 1);
        $device = DB::table('push_devices')->sole();
        $this->assertStringNotContainsString($data['endpoint'], $device->subscription);
        $this->assertSame($data, json_decode(Crypt::decryptString($device->subscription), true));
        $this->actingAs($other)->postJson($url, $data)->assertConflict();
        $this->delete($url.'/'.$device->id)->assertNotFound();
        $this->actingAs($member)->postJson('https://brandonmb.ca/account/notifications/devices', $data)->assertConflict();
        $this->get('https://canadamb.ca/account/notifications')->assertOk()->assertDontSee($data['endpoint'])->assertDontSee($data['keys']['auth']);
        $this->postJson($url.'/unsubscribe', ['endpoint' => $data['endpoint']])->assertOk();
        $this->assertDatabaseCount('push_devices', 0);
        $this->assertFalse(PushDevices::endpointValid('https://fcm.googleapis.com@evil.test/test'));
        $this->postJson($url, $data)->assertOk();
        $otherData = array_replace($data, ['endpoint' => 'https://fcm.googleapis.com/fcm/send/other-test-fixture']);
        $this->actingAs($other)->postJson($url, $otherData)->assertOk();
        config(['network.push_devices' => false]);
        Identity::revoke($member);
        $this->assertDatabaseMissing('push_devices', ['user_id' => $member->id]);
        $this->assertDatabaseHas('push_devices', ['user_id' => $other->id]);
    }
}

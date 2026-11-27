<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use App\Support\Identity;
use App\Support\PushDelivery;
use App\Support\VapidPush;
use Database\Seeders\DatabaseSeeder;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class PushDeliveryTest extends TestCase
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

    public function test_generic_delivery_is_opt_in_bounded_and_revocable_without_external_requests(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $this->assertTrue(openssl_pkey_export($key, $pem));
        $details = openssl_pkey_get_details($key);
        $base64url = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $point = $base64url(chr(4).str_pad($details['ec']['x'], 32, chr(0), STR_PAD_LEFT).str_pad($details['ec']['y'], 32, chr(0), STR_PAD_LEFT));
        config(['network.push_devices' => true, 'network.push_public_key' => $point, 'network.push_delivery' => true,
            'network.push_private_key' => base64_encode($pem), 'network.push_subject' => 'mailto:operator@example.test']);
        $signing = VapidPush::signingKey();
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $signing);
        $endpoint = 'https://fcm.googleapis.com/fcm/send/delivery-fixture';
        $headers = (new VapidPush)->headers($endpoint, $signing);
        $this->assertStringStartsWith('Authorization: vapid t=', $headers[0]);
        $token = explode(', k=', substr($headers[0], strlen('Authorization: vapid t=')))[0];
        $claims = JWT::decode($token, new Key($details['key'], 'ES256'));
        $this->assertSame('https://fcm.googleapis.com', $claims->aud);
        $this->assertSame('mailto:operator@example.test', $claims->sub);
        $this->assertContains('Content-Length: 0', $headers);
        $transport = new class extends VapidPush
        {
            public array $endpoints = [];

            public int $status = 201;

            public function send(string $endpoint, OpenSSLAsymmetricKey $key): int
            {
                $this->endpoints[] = $endpoint;

                return $this->status;
            }
        };
        $member = User::factory()->create();
        $sender = User::factory()->create();
        $listing = Listing::create(['user_id' => $sender->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Push fixture', 'description' => 'Private listing message fixture.', 'category' => 'furniture',
            'condition' => 'good', 'price_cents' => 100, 'status' => 'active']);
        $thread = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $member->id, 'seller_id' => $sender->id]);
        $thread->messages()->create(['sender_id' => $sender->id, 'body' => 'Old activity']);
        $url = 'https://canadamb.ca/account/notifications/devices';
        $subscription = ['endpoint' => $endpoint, 'keys' => ['p256dh' => $point, 'auth' => $base64url(str_repeat('b', 16))]];
        $this->actingAs($member)->postJson($url, $subscription)->assertOk();
        $this->get('https://canadamb.ca/sw.js')->assertOk()->assertSee('showNotification', false)->assertDontSee('event.data', false)->assertDontSee($pem, false);
        $delivery = app(PushDelivery::class);
        $this->assertSame(1, $delivery->run($transport)['baseline']);
        $this->assertCount(0, $transport->endpoints);
        $thread->messages()->create(['sender_id' => $sender->id, 'body' => 'New private activity']);
        $this->travel(6)->minutes();
        config(['network.prelaunch' => true]);
        $this->assertSame(0, $delivery->run($transport)['examined']);
        $this->assertCount(0, $transport->endpoints);
        config(['network.prelaunch' => false]);
        $this->assertSame(1, $delivery->run($transport)['accepted']);
        $this->assertSame([$endpoint], $transport->endpoints);
        $this->travel(6)->minutes();
        $this->assertSame(1, $delivery->run($transport)['idle']);
        $this->assertCount(1, $transport->endpoints);
        $thread->messages()->create(['sender_id' => $sender->id, 'body' => 'More activity']);
        $transport->status = 503;
        $this->travel(6)->minutes();
        $this->assertSame(1, $delivery->run($transport)['retry']);
        $this->assertSame(1, (int) DB::table('push_delivery_states')->value('attempts'));
        $this->assertSame(0, $delivery->run($transport)['examined']);
        $this->travel(6)->minutes();
        $transport->status = 410;
        $this->assertSame(1, $delivery->run($transport)['removed']);
        $this->assertDatabaseCount('push_devices', 0);
        $this->assertDatabaseCount('push_delivery_states', 0);
        $this->postJson($url, $subscription)->assertOk();
        $this->assertSame(1, $delivery->run($transport)['baseline']);
        $thread->messages()->create(['sender_id' => $sender->id, 'body' => 'Terminal failure fixture']);
        $transport->status = 403;
        $this->travel(6)->minutes();
        $this->assertSame(1, $delivery->run($transport)['failed']);
        $this->assertDatabaseHas('push_delivery_states', ['last_result' => 'failed', 'attempts' => 0]);
        $this->assertSame(0, $delivery->run($transport)['examined']);
        $this->travel(6)->hours();
        $this->assertSame(1, $delivery->run($transport)['idle']);
        Identity::revoke($member);
        $this->assertDatabaseCount('push_devices', 0);
        $this->assertDatabaseCount('push_delivery_states', 0);
        config(['network.push_private_key' => base64_encode('invalid')]);
        $this->assertNull(VapidPush::signingKey());
        $this->assertSame(0, $delivery->run($transport)['examined']);
    }
}

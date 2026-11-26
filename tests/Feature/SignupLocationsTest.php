<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SignupLocationsTest extends TestCase
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

    public function test_provincial_signup_requires_selectable_community_and_public_acknowledgment(): void
    {
        Notification::fake();
        config(['network.signup_locations' => true]);
        $url = 'https://canadamb.ca/register';
        $brandon = Location::where('slug', 'brandon')->sole();
        $this->get($url)->assertOk()->assertSee('Select a Manitoba community')->assertSee('will be shown on your public profile');
        $data = ['name' => 'New member', 'email' => 'new@example.test', 'password' => 'Test4567', 'password_confirmation' => 'Test4567'];
        $this->post($url, $data)->assertSessionHasErrors(['location_id', 'location_public']);
        $this->post($url, $data + ['location_id' => 999999, 'location_public' => '1'])->assertSessionHasErrors('location_id');
        $this->post($url, $data + ['location_id' => $brandon->id])->assertSessionHasErrors('location_public');
        $this->post($url, $data + ['location_id' => $brandon->id, 'location_public' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        $user = User::where('email', 'new@example.test')->sole();
        $this->assertDatabaseHas('user_signup_locations', ['user_id' => $user->id, 'location_id' => $brandon->id]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user)->post('https://canadamb.ca/account/data-export', ['section' => 'profile', 'current_password' => 'Test4567'])
            ->assertOk()->assertJsonPath('records.0.signup_location_id', $brandon->id);
        $this->post('https://canadamb.ca/account/logout')->assertRedirect();
        $this->get('https://canadamb.ca/users/'.$user->id)->assertOk()->assertSee('Brandon, Manitoba')->assertSee('Signup community')->assertDontSee($user->email);
    }

    public function test_local_signup_cannot_change_its_location_and_uses_central_verification_link(): void
    {
        Notification::fake();
        $url = 'https://brandonmb.ca/register';
        $this->get($url)->assertNotFound();
        config(['network.signup_locations' => true]);
        $brandon = Location::where('slug', 'brandon')->sole();
        $winnipeg = Location::where('slug', 'winnipeg')->sole();
        $this->get($url)->assertOk()->assertSee('This signup is for Brandon')->assertDontSee('name="location_id"', false);
        $data = ['name' => 'Brandon member', 'email' => 'local@example.test', 'password' => 'Test4567', 'password_confirmation' => 'Test4567', 'location_public' => '1'];
        $this->post($url, $data + ['location_id' => $winnipeg->id])->assertSessionHasErrors('location_id');
        $this->post($url, $data)->assertSessionHasNoErrors()->assertRedirect('https://canadamb.ca/email/verify');
        $user = User::where('email', 'local@example.test')->sole();
        $this->assertDatabaseHas('user_signup_locations', ['user_id' => $user->id, 'location_id' => $brandon->id]);
        $link = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $mail) use ($user, &$link) {
            $link = $mail->toMail($user)->actionUrl;
            $this->assertStringStartsWith('https://canadamb.ca/email/verify/', $link);

            return true;
        });
        $this->post('https://brandonmb.ca/account/logout')->assertRedirect();
        $this->post('https://canadamb.ca/login', ['email' => $user->email, 'password' => 'Test4567'])->assertRedirect();
        $this->get($link)->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get('https://canadamb.ca/users/'.$user->id)->assertOk()->assertSee('Signup community');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_prelaunch_gate_and_legacy_profile_remain_safe(): void
    {
        config(['network.signup_locations' => true, 'network.prelaunch' => true, 'network.prelaunch_ips' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        $legacy = User::factory()->create();
        $this->get('https://canadamb.ca/users/'.$legacy->id)->assertOk()->assertDontSee('Signup community');
        foreach (['https://canadamb.ca/register', 'https://brandonmb.ca/register'] as $url) {
            $this->get($url)->assertNotFound();
            $this->post($url, [])->assertNotFound();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11']);
        $this->get('https://brandonmb.ca/register')->assertForbidden();
        $this->assertDatabaseCount('user_signup_locations', 0);
    }
}

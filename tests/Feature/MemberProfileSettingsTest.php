<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_profile_only_publishes_an_opted_in_bio_and_city_on_both_hosts(): void
    {
        $member = User::factory()->unverified()->create(['name' => 'Public member', 'email' => 'private@example.test']);
        $brandon = Location::where('slug', 'brandon')->sole();
        $bio = '<script>Private bio</script>';
        $settings = ['name' => $member->name, 'bio' => $bio, 'preferred_location_id' => $brandon->id];

        $this->actingAs($member)->post('https://brandonmb.ca/account/settings', $settings + [
            'show_bio' => '0', 'show_city' => '0', 'email' => 'changed@example.test', 'role' => 'super_admin',
        ])->assertRedirect('/account/settings');

        $this->get('https://canadamb.ca/account/settings')->assertOk()
            ->assertSee($bio)->assertSee('private@example.test')->assertSee('Unverified')->assertSee('Last updated');
        $this->assertDatabaseHas('users', ['id' => $member->id, 'email' => 'private@example.test', 'role' => 'member']);
        $this->get("https://canadamb.ca/users/$member->id")->assertOk()
            ->assertDontSee($bio)->assertDontSee('private@example.test')->assertDontSee('Based in Brandon');
        $this->get('https://canadamb.ca/account/profile')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertSee('private@example.test');

        $this->post('https://canadamb.ca/account/settings', $settings + ['show_bio' => '1', 'show_city' => '1'])->assertRedirect();
        $this->actingAsGuest();
        foreach (['canadamb.ca', 'brandonmb.ca'] as $host) {
            $this->get("https://$host/users/$member->id")->assertOk()
                ->assertSee('Public member')->assertSee('Based in Brandon')->assertSee($bio)
                ->assertDontSee($bio, false)->assertDontSee('private@example.test');
        }
        $this->get('https://brandonmb.ca/account/settings')->assertRedirect('/auth/start');
    }

    public function test_settings_reject_an_unselectable_location_and_keep_notification_preferences_on_name_only_edits(): void
    {
        $member = User::factory()->create();
        $location = Location::where('slug', 'manitoba')->sole();
        $this->actingAs($member)->get('https://canadamb.ca/account/settings')->assertOk()->assertSee('Verified');
        $this->post('https://canadamb.ca/account/settings', ['name' => $member->name, 'preferred_location_id' => $location->id])
            ->assertSessionHasErrors('preferred_location_id');

        $this->post('https://canadamb.ca/account/settings', ['name' => $member->name, 'notify_discussion_replies' => '0'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->post('https://brandonmb.ca/account/settings', ['name' => 'Updated display name'])->assertRedirect();

        $this->assertDatabaseHas('member_settings', [
            'user_id' => $member->id, 'notify_discussion_replies' => 0, 'notify_marketplace_messages' => 1,
        ]);
        $this->assertDatabaseHas('users', ['id' => $member->id, 'name' => 'Updated display name']);
    }

    public function test_invalid_form_values_do_not_break_the_settings_page(): void
    {
        $member = User::factory()->create();
        $path = 'https://canadamb.ca/account/settings';

        $this->actingAs($member)->from($path)->post($path, [
            'name' => ['invalid'], 'bio' => ['invalid'], 'preferred_location_id' => ['invalid'], 'show_bio' => 'yes',
        ])->assertRedirect($path)->assertSessionHasErrors(['name', 'bio', 'preferred_location_id', 'show_bio']);

        $this->withCookie(config('session.cookie'), session()->getId())
            ->get($path)->assertOk()->assertSee($member->name)->assertSee('Check the following details.');
        $this->assertDatabaseMissing('member_settings', ['user_id' => $member->id]);
    }

    public function test_background_pwa_requests_leave_settings_feedback_for_the_redirect(): void
    {
        $member = User::factory()->create();
        $host = 'https://canadamb.ca';
        $this->actingAs($member)->post($host.'/account/settings', ['name' => 'Updated member'])->assertRedirect('/account/settings');
        $sessionCookie = config('session.cookie');
        $this->withCookie($sessionCookie, session()->getId());
        foreach (['/sw.js', '/app.webmanifest', '/pwa/icon/192.png'] as $metadata) {
            $this->get($host.$metadata)->assertOk()->assertCookieMissing($sessionCookie);
        }
        $this->get($host.'/account/settings')->assertOk()->assertSee('Profile settings saved.');
    }

    public function test_profile_applies_listing_privacy_and_publication_scope(): void
    {
        $member = User::factory()->create();
        $brandon = $this->listing($member, 'brandon', 'Listed only in Brandon', 'active');
        $this->listing($member, 'winnipeg', 'Listed only in Winnipeg', 'active');
        $this->listing($member, 'brandon', 'Still pending approval', 'pending');

        $this->get("https://canadamb.ca/users/$member->id")->assertOk()
            ->assertSee('Listed only in Brandon')->assertSee('Listed only in Winnipeg')->assertDontSee('Still pending approval');
        $this->get("https://brandonmb.ca/users/$member->id")->assertOk()
            ->assertSee('Listed only in Brandon')->assertDontSee('Listed only in Winnipeg')->assertDontSee('Still pending approval');

        $this->actingAs($member)->post('https://brandonmb.ca/account/settings', ['name' => $member->name, 'show_listings' => '0'])->assertRedirect();
        $this->actingAsGuest();
        foreach (['canadamb.ca', 'brandonmb.ca'] as $host) {
            $this->get("https://$host/users/$member->id")->assertOk()
                ->assertDontSee('Listed only in Brandon')->assertDontSee('Listed only in Winnipeg');
        }
        $this->get("https://brandonmb.ca/marketplace/$brandon->id")->assertOk()->assertSee("href=\"/users/$member->id\"", false);
    }

    public function test_discussion_contributors_link_to_public_profiles(): void
    {
        $author = User::factory()->create();
        $replier = User::factory()->create();
        $thread = CommunityThread::create([
            'author_id' => $author->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Planning a fixture discussion', 'body' => 'We are discussing this community project.', 'category' => 'general',
        ]);
        $thread->forceFill(['status' => 'approved', 'last_activity_at' => now()])->save();
        $thread->replies()->create(['author_id' => $replier->id, 'body' => 'I can help with the project.'])->forceFill(['status' => 'approved'])->save();

        $this->get("https://brandonmb.ca/community/$thread->id")->assertOk()
            ->assertSee("href=\"/users/$author->id\"", false)->assertSee("href=\"/users/$replier->id\"", false)
            ->assertDontSee($author->email)->assertDontSee($replier->email);
    }

    public function test_password_change_validates_credentials_and_revokes_sessions_and_oauth(): void
    {
        $member = User::factory()->create();
        $client = Str::uuid()->toString();
        DB::table('oauth_auth_codes')->insert(['id' => 'previous-code', 'user_id' => $member->id, 'client_id' => $client, 'revoked' => false]);
        DB::table('oauth_access_tokens')->insert(['id' => 'previous-access', 'user_id' => $member->id, 'client_id' => $client, 'revoked' => false]);
        DB::table('oauth_refresh_tokens')->insert(['id' => 'previous-refresh', 'access_token_id' => 'previous-access', 'revoked' => false]);
        $path = '/account/settings/password';
        $replacement = ['password' => 'A-strong-new-pass-42', 'password_confirmation' => 'A-strong-new-pass-42'];

        $this->post('https://canadamb.ca'.$path, $replacement + ['current_password' => 'password'])->assertRedirect('/login');
        $this->actingAs($member)->post('https://brandonmb.ca'.$path, $replacement + ['current_password' => 'password'])->assertNotFound();
        $this->post('https://canadamb.ca'.$path, $replacement + ['current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password')->assertSessionMissing('_old_input.current_password');
        $this->post('https://canadamb.ca'.$path, ['current_password' => 'password', 'password' => 'weak', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password');
        $this->assertTrue(Hash::check('password', $member->fresh()->password));
        $this->assertSame(0, $member->fresh()->session_version);

        $this->post('https://canadamb.ca'.$path, $replacement + ['current_password' => 'password'])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertTrue(Hash::check($replacement['password'], $member->fresh()->password));
        $this->assertSame(1, $member->fresh()->session_version);
        $this->assertDatabaseHas('oauth_auth_codes', ['id' => 'previous-code', 'revoked' => 1]);
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => 'previous-access', 'revoked' => 1]);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['id' => 'previous-refresh', 'revoked' => 1]);

        $this->actingAs($member->fresh())->withSession(['session_host' => 'brandonmb.ca', 'session_version' => 0]);
        $this->get('https://brandonmb.ca/account/settings')->assertRedirect('/auth/start');
        $this->assertGuest();
    }

    private function listing(User $member, string $location, string $title, string $status): Listing
    {
        $listing = Listing::create([
            'user_id' => $member->id, 'location_id' => Location::where('slug', $location)->sole()->id,
            'category' => 'furniture', 'title' => $title, 'description' => 'A fixture listing for the public profile.', 'condition' => 'good',
            'price_cents' => 1000, 'status' => $status,
        ]);
        $listing->forceFill(['published_at' => now(), 'expires_at' => now()->addDay()])->save();

        return $listing;
    }
}

<?php

namespace Tests\Feature;

use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\Listing;
use App\Models\Site;
use App\Models\User;
use App\Support\NetworkSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_explicit_published_policy_applies_only_to_eligible_new_content(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $member = User::factory()->create(['created_at' => now()->subDays(8)]);
        $fresh = User::factory()->create();
        $settings = app(NetworkSettings::class);
        $url = 'https://canadamb.ca/admin/publication-policy';
        $data = ['configuration_version' => NetworkSettings::stateVersion($settings->state()), 'confirm_policy' => '1',
            'policy' => ['listings' => 'established', 'discussions' => 'verified', 'replies' => 'verified']];
        $this->actingAs($member)->post($url, $data)->assertForbidden();
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('Manual moderation');
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('manual', $settings->revision($settings->state()->published_id)['publication_policy']['listings'] ?? 'manual');
        $this->post('https://canadamb.ca/admin/settings/publish', ['configuration_version' => NetworkSettings::stateVersion($settings->state())])->assertRedirect();
        $listing = ['title' => 'Policy test desk', 'description' => 'A useful wooden desk.', 'price' => 25, 'category' => 'furniture', 'condition' => 'good'];
        $this->actingAs($fresh)->post('https://brandonmb.ca/marketplace', $listing)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending', Listing::sole()->status);
        $this->actingAs($member)->post('https://brandonmb.ca/marketplace', array_replace($listing, ['title' => 'Established member desk']))->assertRedirect()->assertSessionHasNoErrors();
        $active = Listing::where('title', 'Established member desk')->sole();
        $this->assertSame('active', $active->status);
        $this->assertNotNull($active->expires_at);
        $this->post('https://brandonmb.ca/community', ['title' => 'Garden discussion', 'body' => 'A community gardening discussion.', 'category' => 'general'])->assertRedirect();
        $thread = CommunityThread::sole();
        $this->assertSame('approved', $thread->status);
        $this->post('https://brandonmb.ca/community/'.$thread->id.'/follow')->assertRedirect();
        $this->actingAs($fresh)->post('https://brandonmb.ca/community/'.$thread->id.'/replies', ['body' => 'I can help with planting.'])->assertRedirect();
        $this->assertSame('approved', CommunityReply::sole()->status);
        $this->assertDatabaseHas('community_notifications', ['user_id' => $member->id, 'reply_id' => CommunityReply::sole()->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'community.reply.auto-approved', 'actor_id' => null]);
        $this->actingAs($member)->put('https://brandonmb.ca/account/listings/'.$active->id, array_replace($listing, ['title' => 'Edited established desk', 'version' => $active->reviewVersion()]))->assertRedirect();
        $this->assertSame('pending', $active->fresh()->status);
        $this->actingAs($admin)->post($url, ['configuration_version' => NetworkSettings::stateVersion($settings->state()), 'confirm_policy' => '1',
            'policy' => ['listings' => 'manual', 'discussions' => 'manual', 'replies' => 'manual']])->assertRedirect();
        $this->post('https://canadamb.ca/admin/settings/publish', ['configuration_version' => NetworkSettings::stateVersion($settings->state())])->assertRedirect();
        $this->actingAs($fresh)->post('https://brandonmb.ca/community/'.$thread->id.'/replies', ['body' => 'This later reply requires review.'])->assertRedirect();
        $this->assertDatabaseHas('community_replies', ['body' => 'This later reply requires review.', 'status' => 'pending']);
        $this->assertDatabaseHas('community_replies', ['body' => 'I can help with planting.', 'status' => 'approved']);
    }

    public function test_community_restrictions_cannot_be_bypassed_by_the_provincial_portal_or_ordinary_site_edits(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $member = User::factory()->create();
        $operator = User::factory()->create(['role' => 'network_admin']);
        $settings = app(NetworkSettings::class);
        $settings->saveDraft($admin->id, fn (array $current) => array_replace($current, ['publication_policy' => [
            'listings' => 'verified', 'discussions' => 'verified', 'replies' => 'verified',
        ]]));
        $settings->publish($admin->id);
        $site = Site::where('code', 'brandon')->sole();
        $base = 'https://canadamb.ca/admin/communities/'.$site->id;
        $data = ['version' => $site->configurationVersion(), 'confirm_policy' => '1', 'manual' => ['listings' => '1', 'discussions' => '1', 'replies' => '1']];
        $this->actingAs($operator)->post($base.'/publication-policy', $data)->assertForbidden();
        $this->actingAs($admin)->get($base)->assertOk()->assertSee('Community moderation restrictions');
        $this->post($base.'/publication-policy', $data)->assertRedirect($base)->assertSessionHasNoErrors();
        $this->post($base.'/publication-policy', $data)->assertSessionHasErrors('version');
        $this->actingAs($operator)->put($base, [
            'version' => $site->fresh()->configurationVersion(), 'name' => $site->name, 'description' => $site->description,
            'accent_type' => 'solid', 'accent_colors' => '#228855', 'accent_thickness' => 4, 'accent_direction' => 90,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($site->fresh()->overrides['manual_publication']['listings']);
        $this->actingAs($member)->post('https://canadamb.ca/marketplace', [
            'title' => 'Restricted location desk', 'description' => 'A useful desk.', 'price' => 20,
            'category' => 'furniture', 'condition' => 'good', 'location_id' => $site->location_id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending', Listing::sole()->status);
        $this->post('https://brandonmb.ca/community', ['title' => 'Restricted city thread', 'body' => 'A local discussion for the community.', 'category' => 'general'])->assertRedirect();
        $thread = CommunityThread::sole();
        $this->assertSame('pending', $thread->status);
        $thread->forceFill(['status' => 'approved'])->save();
        $this->post('https://canadamb.ca/community/'.$thread->id.'/replies', ['body' => 'This local reply requires review.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pending', CommunityReply::sole()->status);
        $this->post('https://canadamb.ca/community', ['title' => 'Province-wide topic', 'body' => 'This topic is for the whole province.', 'category' => 'general'])->assertRedirect();
        $this->assertDatabaseHas('community_threads', ['title' => 'Province-wide topic', 'status' => 'approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.publication-policy', 'site_id' => $site->id]);
    }
}

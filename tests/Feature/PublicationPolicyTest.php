<?php

namespace Tests\Feature;

use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\Listing;
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
    }
}

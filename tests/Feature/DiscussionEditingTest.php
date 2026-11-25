<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscussionEditingTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_owner_changes_return_to_moderation_and_withdrawal_hides_replies(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $thread = CommunityThread::create([
            'author_id' => $owner->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'category' => 'general', 'title' => 'Original discussion', 'body' => 'An original discussion body.',
        ]);
        $thread->forceFill(['status' => 'approved', 'pinned' => true, 'last_activity_at' => now()])->save();
        $reply = $thread->replies()->create(['author_id' => $stranger->id, 'body' => 'A published reply.']);
        $reply->forceFill(['status' => 'approved'])->save();
        $url = 'https://brandonmb.ca/account/discussions/'.$thread->id;
        $public = 'https://brandonmb.ca/community/'.$thread->id;
        $data = ['title' => 'Edited discussion', 'body' => 'The updated discussion content.', 'category' => 'help', 'version' => $thread->contentVersion()];
        $this->actingAs($stranger)->put($url, $data)->assertNotFound();
        $this->actingAs($owner)->get($url.'/edit')->assertOk()->assertSee('Original discussion');
        $this->put($url, $data)->assertRedirect('/account/discussions')->assertSessionHasNoErrors();
        $this->assertSame('pending', $thread->fresh()->status);
        $this->assertFalse($thread->fresh()->pinned);
        $this->get($public)->assertNotFound();
        $this->put($url, $data)->assertSessionHasErrors('version');
        $this->actingAs($admin)->post('https://canadamb.ca/admin/community/threads/'.$thread->id, ['action' => 'approve', 'version' => $data['version']])->assertSessionHasErrors('version');
        $this->post('https://canadamb.ca/admin/community/threads/'.$thread->id, ['action' => 'approve', 'version' => $thread->fresh()->contentVersion()])->assertRedirect();
        $this->get($public)->assertOk()->assertSee('Edited discussion')->assertSee('A published reply.');
        $thread->refresh()->forceFill(['locked' => true])->save();
        $this->actingAs($owner)->get($url.'/edit')->assertConflict();
        $this->post($url.'/withdraw')->assertConflict();
        $thread->forceFill(['locked' => false])->save();
        $this->post($url.'/withdraw')->assertRedirect();
        $this->get($public)->assertNotFound();
        $this->get('https://brandonmb.ca/account/discussions?status=withdrawn')->assertOk()->assertSee('Edited discussion');
        $this->assertDatabaseHas('audit_logs', ['action' => 'community.thread.withdrawn', 'actor_id' => $owner->id]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscussionEditingTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_reply_lifecycle_preserves_scope_and_requires_fresh_moderation(): void
    {
        $owner = User::factory()->create();
        $follower = User::factory()->create();
        $stranger = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $thread = CommunityThread::create([
            'author_id' => $follower->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'category' => 'general', 'title' => 'Reply lifecycle discussion', 'body' => 'A local community discussion.',
        ]);
        $thread->forceFill(['status' => 'approved'])->save();
        DB::table('community_thread_follows')->insert(['thread_id' => $thread->id, 'user_id' => $follower->id]);
        $reply = $thread->replies()->create(['author_id' => $owner->id, 'body' => 'Original reply text.']);
        $base = 'https://brandonmb.ca/account/replies/'.$reply->id;
        $public = 'https://brandonmb.ca/community/'.$thread->id;
        $review = 'https://canadamb.ca/admin/community/replies/'.$reply->id;
        $this->actingAs($admin)->post($review, ['action' => 'approve', 'version' => $reply->contentVersion()])->assertRedirect()->assertSessionHasNoErrors();
        $version = $reply->fresh()->contentVersion();
        $this->assertDatabaseCount('community_notifications', 1);
        $this->actingAs($stranger)->get($base.'/edit')->assertNotFound();
        $this->put($base, ['body' => 'Unauthorized change.', 'version' => $version])->assertNotFound();
        $this->actingAs($owner)->get($public)->assertSee('Edit my reply');
        $this->get($base.'/edit')->assertOk()->assertSee('Original reply text.');
        $this->put($base, ['body' => 'Original reply text.', 'version' => $version])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $reply->fresh()->status);
        $this->put($base, ['body' => 'Edited reply text.', 'version' => $version])->assertRedirect('/account/replies')->assertSessionHasNoErrors();
        $this->assertSame('pending', $reply->fresh()->status);
        $this->get($public)->assertOk()->assertDontSee('Edited reply text.')->assertDontSee('Original reply text.');
        $this->get('https://brandonmb.ca/account/replies?status=pending')->assertSee('Edited reply text.');
        $this->actingAs($stranger)->get('https://brandonmb.ca/account/replies')->assertDontSee('Edited reply text.');
        $this->actingAs($admin)->post($review, ['action' => 'approve', 'version' => $version])->assertSessionHasErrors('version');
        $this->post($review, ['action' => 'approve'])->assertSessionHasErrors('version');
        $this->post($review, ['action' => 'approve', 'version' => $reply->fresh()->contentVersion()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('community_notifications', 1);
        $this->get($public)->assertSee('Edited reply text.');
        $thread->forceFill(['locked' => true])->save();
        $this->actingAs($owner)->get($base.'/edit')->assertConflict();
        $thread->forceFill(['locked' => false])->save();
        $this->post($base.'/withdraw', ['version' => $reply->fresh()->contentVersion()])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($public)->assertDontSee('Edited reply text.');
        $this->get($public.'/replies/'.$reply->id)->assertNotFound();
        $this->get('https://brandonmb.ca/account/replies?status=withdrawn')->assertSee('Edited reply text.');
        $this->actingAs($admin)->post($review, ['action' => 'approve', 'version' => $reply->fresh()->contentVersion()])->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['action' => 'community.reply.withdrawn', 'actor_id' => $owner->id]);
    }

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

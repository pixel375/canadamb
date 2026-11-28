<?php

namespace Tests\Feature;

use App\Models\CommunityReply;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommentReplyTest extends TestCase
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

    public function test_comment_replies_preserve_scope_moderation_and_direct_notification_privacy(): void
    {
        $author = User::factory()->create();
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $thread = $this->thread($author, 'brandon');
        $other = $this->thread($author, 'winnipeg');
        $parent = $thread->replies()->create(['author_id' => $author->id, 'body' => 'Original visible comment fixture.']);
        $parent->forceFill(['status' => 'approved'])->save();
        $hidden = $thread->replies()->create(['author_id' => $author->id, 'body' => 'Hidden parent fixture.']);
        $foreign = $other->replies()->create(['author_id' => $author->id, 'body' => 'Other thread fixture.']);
        $foreign->forceFill(['status' => 'approved'])->save();
        $url = 'https://canadamb.ca/community/'.$thread->id;
        $local = 'https://brandonmb.ca/community/'.$thread->id;
        $data = ['body' => 'A direct comment reply.', 'parent_reply_id' => $parent->id];
        $this->actingAs($member)->get($url)->assertOk()->assertDontSee('Reply to comment');
        $this->post($url.'/replies', $data)->assertNotFound();
        config(['network.reply_links' => true, 'network.notification_preferences' => true]);
        $this->get($url.'?reply_to='.$parent->id)->assertOk()->assertSee('Replying to '.$author->name)
            ->assertSee('name="parent_reply_id" value="'.$parent->id.'"', false)->assertSee('Cancel comment reply');
        $this->get($url.'?reply_to='.$hidden->id)->assertNotFound();
        $this->get($url.'?reply_to='.$foreign->id)->assertNotFound();
        foreach ([$hidden, $foreign] as $target) {
            $this->post($url.'/replies', ['parent_reply_id' => $target->id, 'body' => ''])->assertNotFound();
        }
        $this->post($local.'/replies', $data)->assertRedirect()->assertSessionHasNoErrors();
        $child = CommunityReply::where('body', $data['body'])->sole();
        $this->assertDatabaseHas('community_reply_links', ['reply_id' => $child->id, 'parent_reply_id' => $parent->id]);
        $this->get($url)->assertOk()->assertDontSee($child->body);
        $this->assertDatabaseMissing('community_notifications', ['user_id' => $author->id, 'reply_id' => $child->id]);
        DB::table('community_thread_follows')->insert(['thread_id' => $thread->id, 'user_id' => $author->id]);
        $this->actingAs($admin)->post('https://canadamb.ca/admin/community/replies/'.$child->id, ['action' => 'approve', 'version' => $child->contentVersion()])->assertRedirect();
        $this->assertSame(1, DB::table('community_notifications')->where(['user_id' => $author->id, 'reply_id' => $child->id])->count());
        foreach ([$url, $local] as $portal) {
            $this->get($portal)->assertOk()->assertSee($child->body)->assertSee('Replying to '.$author->name)
                ->assertSee('href="/community/'.$thread->id.'/replies/'.$parent->id.'"', false);
        }
        $this->actingAs($author)->get('https://canadamb.ca/account/community-notifications')->assertOk()
            ->assertSee('replied to your comment.');
        $this->post($url.'/replies', ['body' => 'Reply to my own comment.', 'parent_reply_id' => $parent->id])->assertRedirect()->assertSessionHasNoErrors();
        $own = CommunityReply::where('body', 'Reply to my own comment.')->sole();
        $this->actingAs($admin)->post('https://canadamb.ca/admin/community/replies/'.$own->id, ['action' => 'approve', 'version' => $own->contentVersion()])->assertRedirect();
        $this->assertDatabaseMissing('community_notifications', ['user_id' => $author->id, 'reply_id' => $own->id]);
        $this->post('https://canadamb.ca/admin/community/replies/'.$parent->id, ['action' => 'reject', 'reason' => 'Parent removed after review.', 'version' => $parent->fresh()->contentVersion()])->assertRedirect();
        $this->get($url)->assertOk()->assertSee($child->body)->assertDontSee($parent->body)->assertDontSee('Replying to '.$author->name);
        $this->get($url.'/replies/'.$parent->id)->assertNotFound();
        $this->actingAs($author)->get('https://canadamb.ca/account/community-notifications')->assertOk()->assertDontSee('replied to your comment.');
    }

    public function test_unfollowed_parent_author_is_notified_only_when_preferences_allow_it(): void
    {
        config(['network.reply_links' => true, 'network.notification_preferences' => true]);
        $author = User::factory()->create();
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $thread = $this->thread($author, 'brandon');
        $parent = $thread->replies()->create(['author_id' => $author->id, 'body' => 'A parent without a follow.']);
        $parent->forceFill(['status' => 'approved'])->save();
        $url = 'https://canadamb.ca/community/'.$thread->id.'/replies';
        $this->actingAs($member)->post($url, ['body' => 'Notify this member directly.', 'parent_reply_id' => $parent->id])->assertRedirect();
        $reply = CommunityReply::where('body', 'Notify this member directly.')->sole();
        $this->actingAs($admin)->post('https://canadamb.ca/admin/community/replies/'.$reply->id, ['action' => 'approve', 'version' => $reply->contentVersion()])->assertRedirect();
        $this->assertDatabaseHas('community_notifications', ['user_id' => $author->id, 'reply_id' => $reply->id]);
        DB::table('notification_preferences')->insert(['user_id' => $author->id, 'discussion_replies' => false, 'job_summary' => true, 'updated_at' => now()]);
        $this->actingAs($member)->post($url, ['body' => 'Respect paused notifications.', 'parent_reply_id' => $parent->id])->assertRedirect();
        $paused = CommunityReply::where('body', 'Respect paused notifications.')->sole();
        $this->actingAs($admin)->post('https://canadamb.ca/admin/community/replies/'.$paused->id, ['action' => 'approve', 'version' => $paused->contentVersion()])->assertRedirect();
        $this->assertDatabaseMissing('community_notifications', ['user_id' => $author->id, 'reply_id' => $paused->id]);
    }

    private function thread(User $author, string $location): CommunityThread
    {
        $thread = CommunityThread::create(['author_id' => $author->id, 'location_id' => Location::where('slug', $location)->sole()->id,
            'title' => 'Comment fixture '.$location, 'category' => 'general', 'body' => 'A community discussion for comment reply testing.']);
        $thread->forceFill(['status' => 'approved'])->save();

        return $thread;
    }
}

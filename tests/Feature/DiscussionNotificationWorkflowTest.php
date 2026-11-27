<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscussionNotificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_followed_discussions_only_show_approved_threads_visible_on_the_current_portal(): void
    {
        $member = User::factory()->create();
        $author = User::factory()->create();
        $brandon = $this->thread($author, 'Brandon garden swap', 'brandon');
        $winnipeg = $this->thread($author, 'Winnipeg local projects', 'winnipeg');
        $pending = $this->thread($author, 'Brandon pending conversation', 'brandon', 'pending');

        foreach ([$brandon, $winnipeg, $pending] as $thread) {
            DB::table('community_thread_follows')->insert([
                'user_id' => $member->id,
                'thread_id' => $thread->id,
            ]);
        }

        $url = 'https://brandonmb.ca/account/followed-discussions';
        $this->get($url)->assertRedirect();
        $this->actingAs($member)->get($url)
            ->assertOk()
            ->assertSee('Following discussions')
            ->assertSee('Brandon garden swap')
            ->assertSee('1 discussion')
            ->assertDontSee('Winnipeg local projects')
            ->assertDontSee('Brandon pending conversation')
            ->assertSee('href="/account/community-notifications"', false);

        $this->get('https://canadamb.ca/account/followed-discussions')
            ->assertOk()
            ->assertSee('Brandon garden swap')
            ->assertSee('Winnipeg local projects')
            ->assertSee('2 discussions')
            ->assertDontSee('Brandon pending conversation');

        $this->actingAs(User::factory()->create())->get($url)
            ->assertOk()
            ->assertSee("You're not following any discussions here", false)
            ->assertDontSee('Brandon garden swap');

        $this->actingAs(User::factory()->create(['email_verified_at' => null]))
            ->get($url)->assertRedirect();
    }

    public function test_follow_management_filters_and_removes_only_owned_portal_follows(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $visible = $this->thread($other, 'Garden 0 swap', 'brandon');
        $hidden = $this->thread($other, 'Hidden Brandon topic', 'brandon', 'pending');
        $elsewhere = $this->thread($other, 'Hidden Winnipeg topic', 'winnipeg', 'pending');
        foreach ([$visible, $hidden, $elsewhere] as $thread) {
            foreach ([$member, $other] as $follower) {
                DB::table('community_thread_follows')->insert(['user_id' => $follower->id, 'thread_id' => $thread->id]);
            }
        }
        $url = 'https://brandonmb.ca/account/followed-discussions';
        $this->actingAs($member)->get($url.'?q=0')->assertOk()->assertSee('Garden 0 swap')->assertSee('1 unavailable discussion followed')
            ->assertDontSee('Hidden Brandon topic')->assertDontSee('Hidden Winnipeg topic');
        $this->get($url.'?q=unmatched')->assertSee('No followed discussions match these filters');
        $this->post($url.'/clear-unavailable')->assertSessionHasErrors('confirm_remove');
        $this->post($url.'/clear-unavailable', ['confirm_remove' => '1'])->assertRedirect('/account/followed-discussions');
        $this->assertDatabaseMissing('community_thread_follows', ['user_id' => $member->id, 'thread_id' => $hidden->id]);
        $this->assertDatabaseHas('community_thread_follows', ['user_id' => $member->id, 'thread_id' => $elsewhere->id]);
        $this->assertDatabaseHas('community_thread_follows', ['user_id' => $other->id, 'thread_id' => $hidden->id]);
        $this->delete('https://brandonmb.ca/community/'.$elsewhere->id.'/follow')->assertNotFound();
        $this->delete('https://brandonmb.ca/community/'.$visible->id.'/follow', ['return_to' => 'following'])->assertRedirect('/account/followed-discussions');
        $this->assertDatabaseMissing('community_thread_follows', ['user_id' => $member->id, 'thread_id' => $visible->id]);
        $this->assertDatabaseHas('community_thread_follows', ['user_id' => $other->id, 'thread_id' => $visible->id]);
    }

    public function test_unread_filter_and_mark_all_read_respect_approval_user_and_city_scope(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $author = User::factory()->create();
        $brandon = $this->notify($member, $author, 'Visible Brandon reply', 'brandon');
        $winnipeg = $this->notify($member, $author, 'Visible Winnipeg reply', 'winnipeg');
        $pending = $this->notify($member, $author, 'Pending reply should not show', 'brandon', false);
        $otherMember = $this->notify($other, $author, 'Other member reply', 'brandon');

        $this->actingAs($member)->get('https://brandonmb.ca/account/community-notifications?view=unread')
            ->assertOk()
            ->assertSee('Visible Brandon reply')
            ->assertSee('1', false)
            ->assertSee('Mark all as read')
            ->assertSee('action="/account/community-notifications/'.$brandon.'/open"', false)
            ->assertSee($author->name)
            ->assertDontSee('Visible Winnipeg reply')
            ->assertDontSee('Pending reply should not show')
            ->assertDontSee('Other member reply');

        $this->post('https://brandonmb.ca/account/community-notifications/read-all')->assertSessionHasErrors('cutoff');
        $this->post('https://brandonmb.ca/account/community-notifications/read-all', ['cutoff' => $brandon])
            ->assertRedirect('/account/community-notifications')
            ->assertSessionHas('status', 'All visible discussion notifications marked as read.');

        $this->assertNotNull(DB::table('community_notifications')->where('id', $brandon)->value('read_at'));
        foreach ([$winnipeg, $pending, $otherMember] as $unreadId) {
            $this->assertNull(DB::table('community_notifications')->where('id', $unreadId)->value('read_at'));
        }

        $this->get('https://brandonmb.ca/account/community-notifications?view=unread')
            ->assertOk()->assertSee('All caught up')->assertDontSee('Visible Brandon reply');

        $this->get('https://brandonmb.ca/account/community-notifications')
            ->assertOk()->assertSee('Visible Brandon reply');

        $this->get('https://canadamb.ca/account/community-notifications?view=unread')
            ->assertOk()->assertSee('Visible Winnipeg reply')->assertDontSee('Visible Brandon reply');

        $this->get('https://canadamb.ca/account/community-notifications?view=invalid')
            ->assertSessionHasErrors('view');

        $this->actingAs($other)->post('https://brandonmb.ca/account/community-notifications/read-all', ['cutoff' => $otherMember])
            ->assertRedirect();
        $this->assertNull(DB::table('community_notifications')->where('id', $winnipeg)->value('read_at'));
    }

    public function test_read_actions_preserve_search_and_do_not_mark_newer_activity(): void
    {
        $member = User::factory()->create();
        $author = User::factory()->create();
        $first = $this->notify($member, $author, 'Garden 0 swap', 'brandon');
        $url = 'https://brandonmb.ca/account/community-notifications';
        $page = $this->actingAs($member)->get($url.'?view=unread&q=0&period=7')->assertOk();
        $cutoff = $page->viewData('readCutoff');
        $second = $this->notify($member, $author, 'Newer garden reply', 'brandon');
        $this->post($url.'/read-all', ['cutoff' => $cutoff, 'view' => 'unread', 'q' => '0', 'period' => '7'])
            ->assertRedirect('/account/community-notifications?view=unread&q=0&period=7');
        $this->assertNotNull(DB::table('community_notifications')->where('id', $first)->value('read_at'));
        $this->assertNull(DB::table('community_notifications')->where('id', $second)->value('read_at'));
        $this->post($url.'/selected', ['notifications' => [$first], 'action' => 'unread', 'view' => 'read', 'q' => '0', 'period' => '7'])
            ->assertRedirect('/account/community-notifications?view=read&q=0&period=7');
        $this->assertNull(DB::table('community_notifications')->where('id', $first)->value('read_at'));
    }

    public function test_individual_notification_read_returns_to_requested_safe_filter(): void
    {
        $member = User::factory()->create();
        $id = $this->notify($member, User::factory()->create(), 'New approved reply', 'brandon');
        $this->actingAs($member)->post('https://brandonmb.ca/account/community-notifications/'.$id.'/read', [
            'return_to' => 'unread',
        ])->assertRedirect('/account/community-notifications?view=unread');
        $this->assertNotNull(DB::table('community_notifications')->where('id', $id)->value('read_at'));

        $this->post('https://brandonmb.ca/account/community-notifications/'.$id.'/read', [
            'return_to' => 'https://example.com',
        ])->assertRedirect('/account/notifications');
    }

    public function test_notification_reply_permalink_selects_the_correct_page_and_never_exposes_pending_reply(): void
    {
        $member = User::factory()->create();
        $author = User::factory()->create();
        $thread = $this->thread($author, 'Brandon long conversation', 'brandon');

        for ($i = 1; $i <= 27; $i++) {
            $reply = $thread->replies()->create([
                'author_id' => $author->id,
                'body' => 'Approved reply number '.$i,
            ]);
            $reply->forceFill(['status' => 'approved'])->save();
            if ($i === 27) {
                $last = $reply;
            }
        }
        $pending = $thread->replies()->create([
            'author_id' => $author->id, 'body' => 'A pending hidden reply',
        ]);
        $notification = DB::table('community_notifications')->insertGetId([
            'user_id' => $member->id, 'reply_id' => $last->id, 'created_at' => now(),
        ]);

        $this->actingAs($member)->get('https://brandonmb.ca/account/community-notifications')
            ->assertOk()
            ->assertSee('action="/account/community-notifications/'.$notification.'/open"', false);
        $this->get('https://canadamb.ca/account/notifications')
            ->assertOk()
            ->assertSee('action="/account/community-notifications/'.$notification.'/open"', false);

        $expected = 'https://brandonmb.ca/community/'.$thread->id.'?page=2#reply-'.$last->id;
        $other = User::factory()->create();
        $elsewhere = $this->notify($member, $author, 'Outside Brandon', 'winnipeg');
        $hidden = $this->notify($member, $author, 'Pending notification', 'brandon', false);
        $otherNotification = $this->notify($other, $author, 'Other recipient', 'brandon');
        $this->post('https://brandonmb.ca/account/community-notifications/'.$elsewhere.'/open')->assertNotFound();
        $this->post('https://brandonmb.ca/account/community-notifications/'.$hidden.'/open')->assertNotFound();
        $this->post('https://canadamb.ca/account/community-notifications/'.$otherNotification.'/open')->assertNotFound();
        $this->get('https://canadamb.ca/account/community-notifications/'.$notification.'/open')->assertStatus(405);
        $this->assertNull(DB::table('community_notifications')->where('id', $notification)->value('read_at'));
        $this->post('https://canadamb.ca/account/community-notifications/'.$notification.'/open', ['return_to' => 'https://evil.example'])
            ->assertStatus(303)->assertRedirect('/community/'.$thread->id.'/replies/'.$last->id);
        $readAt = DB::table('community_notifications')->where('id', $notification)->value('read_at');
        $this->assertNotNull($readAt);
        foreach ([$elsewhere, $hidden, $otherNotification] as $unread) {
            $this->assertNull(DB::table('community_notifications')->where('id', $unread)->value('read_at'));
        }
        $this->travel(1)->minutes();
        $this->post('https://brandonmb.ca/account/community-notifications/'.$notification.'/open')->assertStatus(303);
        $this->assertSame($readAt, DB::table('community_notifications')->where('id', $notification)->value('read_at'));
        $this->get('https://brandonmb.ca/community/'.$thread->id.'/replies/'.$last->id)
            ->assertRedirect($expected);
        $this->get('https://canadamb.ca/community/'.$thread->id.'/replies/'.$last->id)
            ->assertRedirect('https://canadamb.ca/community/'.$thread->id.'?page=2#reply-'.$last->id);
        $this->get('https://canadamb.ca/community/'.$thread->id.'?page=2')->assertOk()->assertSee('id="reply-'.$last->id.'"', false);
        $this->get('https://brandonmb.ca/community/'.$thread->id.'/replies/'.$pending->id)
            ->assertNotFound();
        $this->get('https://brandonmb.ca/community/'.$thread->id.'?page=2')
            ->assertOk()->assertSee('id="reply-'.$last->id.'"', false)
            ->assertDontSee('A pending hidden reply');
    }

    private function thread(
        User $author, string $title, string $place, string $status = 'approved'
    ): CommunityThread {
        $thread = CommunityThread::create([
            'author_id' => $author->id,
            'location_id' => Location::where('slug', $place)->sole()->id,
            'title' => $title,
            'category' => 'general',
            'body' => 'Community updates and replies for the neighbourhood.',
        ]);
        $thread->forceFill(['status' => $status])->save();

        return $thread;
    }

    private function notify(
        User $member, User $author, string $title, string $place, bool $approved = true
    ): int {
        $thread = $this->thread($author, $title, $place);
        $reply = $thread->replies()->create([
            'author_id' => $author->id,
            'body' => 'Discussion notification test content for this community.',
        ]);
        if ($approved) {
            $reply->forceFill(['status' => 'approved'])->save();
        }

        return DB::table('community_notifications')->insertGetId([
            'user_id' => $member->id,
            'reply_id' => $reply->id,
            'created_at' => now(),
        ]);
    }
}

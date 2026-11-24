<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscussionThreadExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_guest_sees_an_accessible_thread_but_only_approved_replies_and_city_sign_in(): void
    {
        $author = User::factory()->create([
            'name' => 'Neighbour Garden Club',
            'email' => 'private-garden@example.test',
        ]);
        $thread = $this->approvedThread($author);
        $approved = $thread->replies()->create([
            'author_id' => User::factory()->create(['name' => 'Local Helper'])->id,
            'body' => 'We can bring a trailer of seedlings this weekend.',
        ]);
        $approved->forceFill(['status' => 'approved'])->save();
        $thread->replies()->create([
            'author_id' => User::factory()->create()->id,
            'body' => 'Hidden reply still awaiting moderation.',
        ]);

        $url = 'https://brandonmb.ca/community/'.$thread->id;
        $this->get($url)
            ->assertOk()
            ->assertSee('aria-label="Discussion navigation"', false)
            ->assertSee('class="discussion-thread-layout"', false)
            ->assertSee('class="discussion-original"', false)
            ->assertSee('id="discussion-replies"', false)
            ->assertSee('id="reply-'.$approved->id.'"', false)
            ->assertSee('Neighbour Garden Club')
            ->assertSee('We can bring a trailer of seedlings this weekend.')
            ->assertSee('1 reply')
            ->assertSee('Sign in to participate')
            ->assertSee('href="/auth/start"', false)
            ->assertDontSee('Hidden reply still awaiting moderation.')
            ->assertDontSee('private-garden@example.test')
            ->assertDontSee('id="reply-composer"', false)
            ->assertDontSee('action="/community/'.$thread->id.'/follow"', false);

        // Discussions keep existing community canonical rules.
        $this->get('https://canadamb.ca/community/'.$thread->id)->assertRedirect($url);
    }

    public function test_verified_member_can_reply_follow_unfollow_and_locked_thread_hides_composer(): void
    {
        $author = User::factory()->create();
        $member = User::factory()->create();
        $thread = $this->approvedThread($author);
        $url = 'https://brandonmb.ca/community/'.$thread->id;

        $this->actingAs($member)->get($url)
            ->assertOk()
            ->assertSee('id="reply-composer"', false)
            ->assertSee('action="/community/'.$thread->id.'/replies"', false)
            ->assertSee('Follow discussion')
            ->assertSee('5–3,000 characters');

        $this->post($url.'/follow')->assertRedirect('/community/'.$thread->id);
        $this->assertTrue(DB::table('community_thread_follows')->where([
            'user_id' => $member->id,
            'thread_id' => $thread->id,
        ])->exists());
        $this->get($url)->assertSee('Stop following');

        $this->delete($url.'/follow')->assertRedirect('/community/'.$thread->id);
        $this->assertFalse(DB::table('community_thread_follows')->where([
            'user_id' => $member->id,
            'thread_id' => $thread->id,
        ])->exists());

        $thread->forceFill(['locked' => true])->save();
        $this->get($url)
            ->assertOk()
            ->assertSee('Discussion closed')
            ->assertSee('This discussion is closed to new replies.')
            ->assertDontSee('id="reply-composer"', false);
    }

    public function test_unverified_member_is_not_shown_actions_requiring_verified_email(): void
    {
        $thread = $this->approvedThread(User::factory()->create());
        $member = User::factory()->create(['email_verified_at' => null]);
        $this->actingAs($member)->get('https://brandonmb.ca/community/'.$thread->id)
            ->assertOk()
            ->assertSee('Verify your email to reply')
            ->assertSee('href="/account/settings"', false)
            ->assertDontSee('id="reply-composer"', false)
            ->assertDontSee('action="/community/'.$thread->id.'/follow"', false);
    }

    public function test_discussion_feed_displays_author_and_escaped_preview_without_private_data(): void
    {
        $author = User::factory()->create([
            'name' => 'Local Volunteer',
            'email' => 'not-public@example.test',
        ]);
        $thread = $this->approvedThread($author, 'Garden tips <script>alert(1)</script>');
        $this->get('https://brandonmb.ca/community')
            ->assertOk()
            ->assertSee('Local Volunteer')
            ->assertSee('class="discussion-topic-excerpt"', false)
            ->assertSee('Garden tips &lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('href="/community/'.$thread->id.'"', false)
            ->assertDontSee('not-public@example.test')
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    private function approvedThread(User $author, string $body = 'Planning a community seed exchange and sharing local gardening tips.'): CommunityThread
    {
        $thread = CommunityThread::create([
            'author_id' => $author->id,
            'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'title' => 'Neighbourhood garden planning',
            'category' => 'general',
            'body' => $body,
        ]);
        $thread->forceFill(['status' => 'approved'])->save();

        return $thread;
    }
}

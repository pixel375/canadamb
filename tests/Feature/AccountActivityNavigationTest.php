<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountActivityNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_notifications_hub_requires_login_and_shows_empty_state_for_member(): void
    {
        $this->get('https://canadamb.ca/account/notifications')->assertRedirect();
        $member = User::factory()->create();
        $this->actingAs($member)->get('https://canadamb.ca/account/notifications')
            ->assertOk()->assertSee('No unread private messages.')
            ->assertSee('No unread discussion replies.')
            ->assertSee('href="/account/messages"', false);
        $this->get('https://brandonmb.ca/account/notifications')->assertOk();
    }

    public function test_only_message_participants_receive_unread_counts_and_read_markers_clear_them(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $stranger = User::factory()->create();
        $listing = Listing::create([
            'user_id' => $seller->id,
            'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'category' => 'furniture',
            'title' => 'Small table',
            'description' => 'A table for sale in Brandon',
            'condition' => 'good',
            'price_cents' => 2500,
        ]);
        $conversation = Conversation::create([
            'listing_id' => $listing->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id,
        ]);
        $conversation->messages()->create(['sender_id' => $buyer->id, 'body' => 'Is this table still available?']);

        $this->actingAs($seller)->get('https://canadamb.ca/')
            ->assertOk()->assertSee('aria-label="1 unread messages"', false);
        $this->get('https://brandonmb.ca/account/notifications')
            ->assertOk()->assertSee('1 unread message');
        $this->actingAs($stranger)->get('https://canadamb.ca/')
            ->assertOk()->assertDontSee('aria-label="1 unread messages"', false);
        $this->get('https://canadamb.ca/account/notifications')
            ->assertOk()->assertSee('No unread private messages.');

        $this->actingAs($seller)->get('https://canadamb.ca/account/messages/'.$conversation->id)->assertOk();
        $this->get('https://canadamb.ca/')->assertOk()->assertDontSee('aria-label="1 unread messages"', false);
        $this->actingAs($buyer)->get('https://brandonmb.ca/')->assertOk()->assertDontSee('aria-label="1 unread messages"', false);
    }

    public function test_discussion_unread_counts_respect_site_visibility_and_read_actions(): void
    {
        $member = User::factory()->create();
        $author = User::factory()->create();
        $brandon = Location::where('slug', 'brandon')->sole()->id;
        $winnipeg = Location::where('slug', 'winnipeg')->sole()->id;

        $brandonId = $this->notification($member, $author, $brandon, 'Brandon neighbourhood news');
        $this->notification($member, $author, $winnipeg, 'Winnipeg neighbourhood news');
        $this->notification($member, $author, $brandon, 'Unapproved reply hidden', false);

        $this->actingAs($member)->get('https://canadamb.ca/')
            ->assertOk()->assertSee('aria-label="2 unread notifications"', false);
        $this->get('https://brandonmb.ca/')
            ->assertOk()->assertSee('aria-label="1 unread notifications"', false);
        $this->get('https://brandonmb.ca/account/notifications')
            ->assertOk()->assertSee('Brandon neighbourhood news')
            ->assertDontSee('Winnipeg neighbourhood news')
            ->assertDontSee('Unapproved reply hidden');

        $other = User::factory()->create();
        $this->actingAs($other)->post('https://canadamb.ca/account/community-notifications/'.$brandonId.'/read')
            ->assertNotFound();

        $this->actingAs($member)->post('https://canadamb.ca/account/community-notifications/'.$brandonId.'/read')
            ->assertRedirect('/account/notifications');
        $this->get('https://brandonmb.ca/')->assertOk()->assertDontSee('aria-label="1 unread notifications"', false);
        $this->get('https://canadamb.ca/')->assertOk()->assertSee('aria-label="1 unread notifications"', false);
    }

    private function notification(User $member, User $author, int $location, string $title, bool $approved = true): int
    {
        $thread = CommunityThread::create([
            'author_id' => $author->id, 'location_id' => $location, 'title' => $title,
            'body' => 'Local community discussion details', 'category' => 'general',
        ]);
        $thread->status = 'approved';
        $thread->save();
        $reply = $thread->replies()->create(['author_id' => $author->id, 'body' => 'A new neighbourhood update is available.']);
        if ($approved) {
            $reply->status = 'approved';
            $reply->save();
        }

        return DB::table('community_notifications')->insertGetId([
            'user_id' => $member->id, 'reply_id' => $reply->id, 'created_at' => now(),
        ]);
    }
}

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
        $this->getJson('https://canadamb.ca/account/activity-counts')->assertUnauthorized();
        $member = User::factory()->create();
        $this->actingAs($member)->get('https://canadamb.ca/account/notifications')
            ->assertOk()->assertSee('No unread private messages.')
            ->assertSee('No unread discussion replies.')
            ->assertSee('href="/account/messages"', false);
        $this->get('https://brandonmb.ca/account/notifications')->assertOk();
        $this->getJson('https://canadamb.ca/account/activity-counts')->assertOk()->assertExactJson(['messages' => 0, 'notifications' => 0]);
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
        $this->getJson('https://brandonmb.ca/account/activity-counts')->assertOk()->assertJsonPath('messages', 1);
        $this->actingAs($stranger)->get('https://canadamb.ca/')
            ->assertOk()->assertDontSee('aria-label="1 unread messages"', false);
        $this->get('https://canadamb.ca/account/notifications')
            ->assertOk()->assertSee('No unread private messages.');
        $this->getJson('https://canadamb.ca/account/activity-counts')->assertOk()->assertExactJson(['messages' => 0, 'notifications' => 0]);

        $this->actingAs($seller)->get('https://canadamb.ca/account/messages/'.$conversation->id)->assertOk();
        $this->get('https://canadamb.ca/')->assertOk()->assertDontSee('aria-label="1 unread messages"', false);
        $this->actingAs($buyer)->get('https://brandonmb.ca/')->assertOk()->assertDontSee('aria-label="1 unread messages"', false);
    }

    public function test_private_message_search_does_not_acknowledge_unread_messages_and_inbox_role_is_scoped(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $stranger = User::factory()->create();
        $location = Location::where('slug', 'brandon')->sole()->id;
        $listing = Listing::create(['user_id' => $other->id, 'location_id' => $location, 'category' => 'furniture',
            'title' => 'Private search fixture', 'description' => 'A local listing.', 'condition' => 'good', 'price_cents' => 100]);
        $thread = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $member->id, 'seller_id' => $other->id]);
        $thread->messages()->create(['sender_id' => $other->id, 'body' => 'Delivery on Saturday']);
        $thread->messages()->create(['sender_id' => $member->id, 'body' => 'Saturday works for me']);
        $thread->messages()->create(['sender_id' => $other->id, 'body' => 'Bring a trailer']);
        $base = 'https://canadamb.ca/account/messages';
        $url = $base.'/'.$thread->id;
        $this->actingAs($stranger)->post($url.'/search', ['q' => 'Saturday'])->assertNotFound();
        $response = $this->actingAs($member)->post($url.'/search', ['q' => 'SATURDAY', 'sender' => 'other'])->assertRedirect();
        $resultUrl = $response->headers->get('Location');
        $this->assertStringContainsString('search=', $resultUrl);
        $this->assertStringNotContainsString('SATURDAY', $resultUrl);
        $this->get($resultUrl)->assertOk()->assertSee('Delivery on Saturday')
            ->assertDontSee('Saturday works for me')->assertDontSee('Bring a trailer')->assertDontSee('data-chat-sync=', false)
            ->assertSee('1 matching message')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->actingAs($other)->get($resultUrl)->assertNotFound();
        $this->actingAs($member);
        $this->assertSame(0, $thread->fresh()->buyer_last_read_id);
        $this->get($base.'?role=sent')->assertOk()->assertSee('Private search fixture');
        $this->get($base.'?role=received')->assertOk()->assertDontSee('Private search fixture');
        $this->actingAs($other)->get($base.'?role=received')->assertOk()->assertSee('Private search fixture');
        $response = $this->post($url.'/search', ['q' => '%'])->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('No matching messages.');
        $this->get($url)->assertOk();
        $this->get($response->headers->get('Location'))->assertNotFound();
    }

    public function test_bulk_message_read_keeps_later_messages_and_other_members_unread(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $stranger = User::factory()->create();
        $listing = Listing::create(['user_id' => $other->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'category' => 'furniture', 'title' => 'Bulk read fixture', 'description' => 'A table for sale.', 'condition' => 'good', 'price_cents' => 100]);
        $thread = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $member->id, 'seller_id' => $other->id]);
        $unrelated = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $stranger->id, 'seller_id' => $other->id]);
        $first = $thread->messages()->create(['sender_id' => $other->id, 'body' => 'First incoming message']);
        $unrelated->messages()->create(['sender_id' => $other->id, 'body' => 'Other member message']);
        $url = 'https://canadamb.ca/account/messages';
        $this->actingAs($member)->get($url)->assertOk()->assertSee('Mark all messages as read')->assertSee('name="cutoff" value="'.$first->id.'"', false);
        $later = $thread->messages()->create(['sender_id' => $other->id, 'body' => 'Later incoming message']);
        $this->post($url.'/read-all', ['cutoff' => $first->id])->assertSessionHasErrors('confirm_read');
        $this->post($url.'/read-all', ['cutoff' => $first->id, 'confirm_read' => '1'])->assertRedirect('/account/messages?view=unread');
        $this->assertSame($first->id, $thread->fresh()->buyer_last_read_id);
        $this->assertSame(0, $unrelated->fresh()->buyer_last_read_id);
        $this->getJson('https://brandonmb.ca/account/activity-counts')->assertJsonPath('messages', 1);
        $this->post($url.'/read-all', ['cutoff' => $later->id, 'confirm_read' => '1'])->assertRedirect();
        $this->getJson('https://brandonmb.ca/account/activity-counts')->assertJsonPath('messages', 0);
        $this->assertDatabaseCount('messages', 3);
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
        $this->getJson('https://canadamb.ca/account/activity-counts')->assertOk()->assertJsonPath('notifications', 2);
        $this->getJson('https://brandonmb.ca/account/activity-counts')->assertOk()->assertJsonPath('notifications', 1);
        $this->get('https://canadamb.ca/account/community-notifications?q=NEWS%20Brandon&period=7')->assertOk()->assertSee('Brandon neighbourhood news')->assertDontSee('Winnipeg neighbourhood news');
        $this->get('https://brandonmb.ca/account/community-notifications?q=Winnipeg')->assertOk()->assertSee('No notifications match these filters')->assertDontSee('Winnipeg neighbourhood news');
        $this->get('https://canadamb.ca/account/community-notifications?q=Unapproved')->assertOk()->assertDontSee('Unapproved reply hidden');
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

    public function test_discussion_bulk_read_preserves_new_notifications_and_other_portals(): void
    {
        $member = User::factory()->create();
        $author = User::factory()->create();
        $brandon = Location::where('slug', 'brandon')->sole()->id;
        $winnipeg = Location::where('slug', 'winnipeg')->sole()->id;
        $first = $this->notification($member, $author, $brandon, 'First local notification');
        $other = $this->notification($member, $author, $winnipeg, 'Other portal notification');
        $url = 'https://brandonmb.ca/account/community-notifications';
        $this->actingAs($member)->get($url)->assertOk()->assertSee('name="cutoff" value="'.$first.'"', false);
        $later = $this->notification($member, $author, $brandon, 'Later local notification');
        $this->post($url.'/read-all', ['cutoff' => $first])->assertRedirect('/account/community-notifications');
        $this->assertNotNull(DB::table('community_notifications')->where('id', $first)->value('read_at'));
        $this->assertNull(DB::table('community_notifications')->where('id', $later)->value('read_at'));
        $this->assertNull(DB::table('community_notifications')->where('id', $other)->value('read_at'));
        $this->getJson('https://brandonmb.ca/account/activity-counts')->assertJsonPath('notifications', 1);
    }

    public function test_selected_notification_changes_are_atomic_owned_and_portal_scoped(): void
    {
        $member = User::factory()->create();
        $author = User::factory()->create();
        $brandon = Location::where('slug', 'brandon')->sole()->id;
        $winnipeg = Location::where('slug', 'winnipeg')->sole()->id;
        $first = $this->notification($member, $author, $brandon, 'Local first fixture');
        $second = $this->notification($member, $author, $brandon, 'Local second fixture');
        $remote = $this->notification($member, $author, $winnipeg, 'Remote fixture');
        $foreign = $this->notification($author, $member, $brandon, 'Foreign fixture');
        $hidden = $this->notification($member, $author, $brandon, 'Hidden fixture', false);
        $url = 'https://brandonmb.ca/account/community-notifications';
        $this->actingAs($member)->get($url)->assertOk()->assertSee('Manage selected notifications');
        foreach ([$remote, $foreign, $hidden] as $id) {
            $this->post($url.'/selected', ['notifications' => [$first, $id], 'action' => 'read'])->assertNotFound();
            $this->assertNull(DB::table('community_notifications')->where('id', $first)->value('read_at'));
        }
        $this->post($url.'/selected', ['notifications' => [$first, $second], 'action' => 'read'])->assertRedirect();
        $this->get($url.'?view=read')->assertOk()->assertSee('Local first fixture')->assertSee('Local second fixture')->assertDontSee('Remote fixture');
        $this->post($url.'/selected', ['notifications' => [$first], 'action' => 'unread'])->assertRedirect();
        $this->get($url.'?view=unread')->assertOk()->assertSee('Local first fixture')->assertDontSee('Local second fixture');
        $this->getJson('https://brandonmb.ca/account/activity-counts')->assertJsonPath('notifications', 1);
        $this->assertNull(DB::table('community_notifications')->where('id', $remote)->value('read_at'));
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

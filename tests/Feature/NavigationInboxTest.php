<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Location;
use App\Models\Message;
use App\Models\User;
use App\Support\Marketplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NavigationInboxTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const MAIN = 'https://canadamb.ca';

    private const CITY = 'https://brandonmb.ca';

    public function test_desktop_header_exposes_messages_notifications_and_private_unread_counter(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $outsider = User::factory()->create();
        $item = Listing::create([
            'user_id' => $seller->id,
            'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'category' => 'furniture',
            'title' => 'Local end table',
            'description' => 'An end table for sale.',
            'condition' => 'good',
            'price_cents' => 1500,
        ]);
        app(Marketplace::class)->changeStatus($item, 'active', null);
        $thread = Conversation::create([
            'listing_id' => $item->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id,
        ]);
        $message = Message::create([
            'conversation_id' => $thread->id, 'sender_id' => $buyer->id, 'body' => 'Is this table available?',
        ]);

        foreach ([self::MAIN, self::CITY] as $portal) {
            $this->actingAs($seller)->get($portal.'/')->assertOk()
                ->assertSee('class="sign-in header-inbox" href="/account/messages"', false)
                ->assertSee('class="sign-in header-inbox" href="/account/notifications"', false)
                ->assertSee('aria-label="1 unread messages"', false)
                ->assertSee('aria-label="My profile"', false);
            $this->actingAs($buyer)->get($portal.'/')->assertOk()
                ->assertDontSee('aria-label="1 unread messages"', false);
            $this->actingAs($outsider)->get($portal.'/')->assertOk()
                ->assertDontSee('aria-label="1 unread messages"', false);
        }

        DB::table('conversations')->where('id', $thread->id)->update(['seller_last_read_id' => $message->id]);
        $this->actingAs($seller)->get(self::MAIN.'/')->assertOk()
            ->assertDontSee('aria-label="1 unread messages"', false);

        DB::table('conversations')->where('id', $thread->id)->update(['seller_last_read_id' => 0]);
        DB::table('user_blocks')->insert(['user_id' => $seller->id, 'blocked_id' => $buyer->id]);
        $this->actingAs($seller)->get(self::MAIN.'/')->assertOk()
            ->assertDontSee('aria-label="1 unread messages"', false);
    }
}

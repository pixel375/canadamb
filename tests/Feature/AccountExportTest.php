<?php

namespace Tests\Feature;

use App\Models\CommunityThread;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountExportTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_conversation_metadata_exports_only_participant_local_fields(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $stranger = User::factory()->create();
        $listing = Listing::create(['user_id' => $other->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'category' => 'furniture', 'title' => 'Private fixture', 'description' => 'A test item.', 'condition' => 'good', 'price_cents' => 100]);
        $thread = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $member->id, 'seller_id' => $other->id]);
        $message = $thread->messages()->create(['sender_id' => $other->id, 'body' => 'Received confidential text.']);
        $thread->forceFill(['buyer_last_read_id' => $message->id, 'seller_last_read_id' => $message->id])->save();
        Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $stranger->id, 'seller_id' => $other->id]);
        $url = 'https://canadamb.ca/account/data-export';
        $this->actingAs($member)->get($url)->assertOk()->assertSee('Conversation metadata (1)');
        $data = ['current_password' => 'password', 'section' => 'conversation-metadata'];
        $this->post($url, $data)->assertOk()->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.export_id', $thread->id)->assertJsonPath('records.0.member_role', 'buyer')
            ->assertJsonPath('records.0.own_last_read_id', $message->id)
            ->assertJsonMissingPath('records.0.seller_id')->assertJsonMissingPath('records.0.seller_last_read_id')
            ->assertDontSee('Received confidential text.');
        $this->post($url, $data + ['after_id' => $thread->id])->assertOk()->assertJsonCount(0, 'records');
    }

    public function test_owned_photo_metadata_is_exported_without_storage_paths(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $location = Location::where('slug', 'brandon')->sole()->id;
        foreach ([$member, $other] as $owner) {
            $listing = Listing::create(['user_id' => $owner->id, 'location_id' => $location,
                'category' => 'furniture', 'title' => 'Photo listing', 'description' => 'A test item.',
                'condition' => 'good', 'price_cents' => 100]);
            DB::table('listing_media')->insert(['listing_id' => $listing->id, 'position' => 0,
                'image_path' => 'private-listing-'.$owner->id, 'thumbnail_path' => 'private-listing-thumb-'.$owner->id]);
            $property = DB::table('property_listings')->insertGetId(['owner_id' => $owner->id, 'location_id' => $location,
                'title' => 'Private property', 'description' => 'A test property.', 'transaction' => 'sale',
                'property_type' => 'house', 'seller_type' => 'owner', 'price_cents' => 100]);
            DB::table('property_photos')->insert(['property_listing_id' => $property, 'position' => 0,
                'image_path' => 'private-property-'.$owner->id, 'thumbnail_path' => 'private-property-thumb-'.$owner->id]);
        }
        $url = 'https://canadamb.ca/account/data-export';
        $this->actingAs($member)->get($url)->assertOk()->assertSee('Listing photo metadata (1)')->assertSee('Property photo metadata (1)');
        foreach (['listing-photo-metadata' => 'listing_id', 'property-photo-metadata' => 'property_listing_id'] as $section => $key) {
            $data = ['current_password' => 'password', 'section' => $section];
            $response = $this->post($url, $data)->assertOk()->assertJsonCount(1, 'records')
                ->assertJsonPath('records.0.position', 0)->assertJsonMissingPath('records.0.image_path')
                ->assertJsonMissingPath('records.0.thumbnail_path')->assertDontSee('private-');
            $table = $key === 'listing_id' ? 'listings' : 'property_listings';
            $ownerKey = $key === 'listing_id' ? 'user_id' : 'owner_id';
            $response->assertJsonPath('records.0.'.$key, DB::table($table)->where($ownerKey, $member->id)->value('id'));
            $after = $response->json('records.0.export_id');
            $this->post($url, $data + ['after_id' => $after])->assertOk()->assertJsonCount(0, 'records')
                ->assertHeader('Content-Disposition', 'attachment; filename="canadamb-'.$section.'-after-'.$after.'.json"');
        }
    }

    public function test_exports_require_password_and_only_include_owned_allowlisted_data(): void
    {
        $url = 'https://canadamb.ca/account/data-export';
        $member = User::factory()->create(['password' => 'Export-test-password-42']);
        $other = User::factory()->create();
        $mine = CommunityThread::create(['author_id' => $member->id, 'title' => 'My private discussion', 'body' => 'My own pending content.', 'category' => 'general']);
        CommunityThread::create(['author_id' => $other->id, 'title' => 'Other private discussion', 'body' => 'Other member content.', 'category' => 'general']);
        $this->get($url)->assertRedirect();
        $this->actingAs($member)->get($url)->assertOk()->assertSee('Download my data');
        $this->post($url, ['current_password' => 'wrong', 'section' => 'profile'])->assertSessionHasErrors('current_password');
        $this->post($url, ['current_password' => 'Export-test-password-42', 'section' => 'users'])->assertSessionHasErrors('section');
        $data = ['current_password' => 'Export-test-password-42'];
        $this->post($url, $data + ['section' => 'profile'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('records.0.email', $member->email)->assertJsonMissingPath('records.0.password')->assertJsonMissingPath('records.0.remember_token')->assertJsonMissingPath('records.0.session_version');
        $this->post($url, $data + ['section' => 'discussions', 'user_id' => $other->id])->assertOk()
            ->assertJsonCount(1, 'records')->assertJsonPath('records.0.id', $mine->id)->assertJsonPath('next_after_id', null);
        $this->post($url, $data + ['section' => 'discussions', 'after_id' => $mine->id])->assertOk()->assertJsonCount(0, 'records');
        DB::table('user_blocks')->insert(['user_id' => $member->id, 'blocked_id' => $other->id]);
        DB::table('user_blocks')->insert(['user_id' => $other->id, 'blocked_id' => $member->id]);
        $this->post($url, $data + ['section' => 'blocked-members'])->assertOk()->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.blocked_id', $other->id)->assertJsonMissingPath('records.0.email');
        $this->post($url, $data + ['section' => 'blocked-members', 'after_id' => $other->id])->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->post($url, $data + ['section' => 'blocked-members', 'after_id' => $other->id])->assertOk()->assertJsonCount(0, 'records');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.data-exported', 'actor_id' => $member->id]);
    }
}

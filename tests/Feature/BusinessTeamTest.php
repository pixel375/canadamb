<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessTeamTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const CITY = 'https://brandonmb.ca';

    private const MAIN = 'https://canadamb.ca';

    public function test_invitation_requires_owner_and_explicit_verified_member_acceptance(): void
    {
        [$owner, $business] = $this->business();
        $member = User::factory()->create();
        $other = User::factory()->create();
        $path = "/account/businesses/$business->id/team";
        $invitation = ['invited_user_id' => $member->id, 'role' => 'manager'];

        $this->actingAs($other)->postJson(self::CITY.$path.'/invitations', $invitation)->assertNotFound();
        $this->actingAs($owner)->post(self::CITY.$path.'/invitations', $invitation)->assertRedirect();
        $id = DB::table('business_team_invitations')->value('id');
        $this->assertDatabaseHas('business_team_invitations', [
            'id' => $id, 'status' => 'pending', 'role' => 'manager',
        ]);
        $this->assertDatabaseMissing('business_memberships', ['business_id' => $business->id, 'user_id' => $member->id]);
        $this->postJson(self::CITY.$path.'/invitations', $invitation)->assertUnprocessable();
        $this->actingAs($other)->post(self::CITY."/account/business-invitations/$id/respond", ['decision' => 'accept'])->assertNotFound();
        $this->actingAs($member)->get(self::CITY.'/account/business-invitations')->assertOk()->assertSee($business->name);
        $this->post(self::CITY."/account/business-invitations/$id/respond", ['decision' => 'accept'])->assertRedirect();
        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $member->id, 'role' => 'manager']);
        $this->postJson(self::CITY."/account/business-invitations/$id/respond", ['decision' => 'accept'])->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.team-accepted', 'actor_id' => $member->id]);
    }

    public function test_owner_can_remove_staff_and_old_business_chat_is_not_inherited(): void
    {
        [$owner, $business, $branch] = $this->business();
        $member = User::factory()->create();
        $customer = User::factory()->create();
        $business->members()->attach($member->id, ['role' => 'manager']);
        $branch->forceFill(['message_contact_user_id' => $member->id])->save();
        $job = JobPosting::create([
            'business_location_id' => $branch->id, 'author_id' => $owner->id, 'title' => 'Store assistant',
            'description' => 'Help with the local service desk and customers.',
            'employment_type' => 'part-time', 'workplace' => 'on-site', 'apply_url' => 'https://example.test/apply',
            'closing_on' => now()->addMonth()->toDateString(), 'status' => 'approved', 'published_at' => now(),
        ]);
        $job->forceFill(['message_contact_user_id' => $member->id])->save();
        $chat = Conversation::create([
            'context_type' => 'business', 'business_location_id' => $branch->id, 'location_id' => $branch->location_id,
            'buyer_id' => $customer->id, 'seller_id' => $member->id,
        ]);
        $path = "/account/businesses/$business->id/team/$member->id";
        $this->actingAs($member)->get(self::CITY."/account/messages/$chat->id")->assertOk();
        $this->actingAs($owner)->delete(self::CITY.$path, ['expected_role' => 'manager'])->assertRedirect();
        $this->assertDatabaseMissing('business_memberships', ['business_id' => $business->id, 'user_id' => $member->id]);
        $this->assertNull($branch->fresh()->message_contact_user_id);
        $this->assertNull($job->fresh()->message_contact_user_id);
        $this->assertDatabaseHas('conversations', ['id' => $chat->id, 'seller_id' => $member->id]);
        $this->actingAs($member)->get(self::CITY."/account/messages/$chat->id")->assertNotFound();
        $this->actingAs($owner)->get(self::CITY."/account/messages/$chat->id")->assertNotFound();
        $this->actingAs($customer)->get(self::CITY."/account/messages/$chat->id")->assertOk();
        $this->actingAs($owner)->deleteJson(self::CITY.$path, ['expected_role' => 'manager'])->assertConflict();
        $this->deleteJson(self::CITY."/account/businesses/$business->id/team/$owner->id", ['expected_role' => 'manager'])->assertConflict();
    }

    public function test_wrong_city_expired_unverified_and_cancelled_invitations_cannot_grant_staff_access(): void
    {
        [$owner, $business] = $this->business('elkhorn');
        $member = User::factory()->create();
        $unverified = User::factory()->unverified()->create();
        $path = "/account/businesses/$business->id/team";
        $this->actingAs($owner)->postJson(self::CITY.$path.'/invitations', [
            'invited_user_id' => $member->id, 'role' => 'editor',
        ])->assertNotFound();
        $this->postJson(self::MAIN.$path.'/invitations', ['invited_user_id' => $unverified->id, 'role' => 'editor'])
            ->assertUnprocessable();
        $this->postJson(self::MAIN.$path.'/invitations', ['invited_user_id' => $member->id, 'role' => 'owner'])
            ->assertUnprocessable();
        $this->post(self::MAIN.$path.'/invitations', ['invited_user_id' => $member->id, 'role' => 'editor'])->assertRedirect();
        $id = DB::table('business_team_invitations')->value('id');
        $this->actingAs($member)->get(self::CITY.'/account/business-invitations')->assertOk()->assertDontSee($business->name);
        $this->postJson(self::CITY."/account/business-invitations/$id/respond", ['decision' => 'accept'])->assertNotFound();
        $this->actingAs($owner);
        DB::table('business_team_invitations')->where('id', $id)->update(['expires_at' => now()->subDay()]);
        $this->actingAs($member)->postJson(self::MAIN."/account/business-invitations/$id/respond", ['decision' => 'accept'])->assertConflict();
        $this->assertDatabaseMissing('business_memberships', ['business_id' => $business->id, 'user_id' => $member->id]);
        $this->actingAs($owner)->post(self::MAIN.$path.'/invitations', [
            'invited_user_id' => $member->id, 'role' => 'editor',
        ])->assertRedirect();
        $latest = DB::table('business_team_invitations')->max('id');
        $this->delete(self::MAIN.$path."/invitations/$latest")->assertRedirect();
        $this->actingAs($member)->postJson(self::MAIN."/account/business-invitations/$latest/respond", ['decision' => 'accept'])
            ->assertConflict();
    }

    private function business(string $city = 'brandon'): array
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'slug' => 'example-team-'.$city, 'name' => 'Example Team '.$city, 'category' => 'services', 'status' => 'active',
        ]);
        $branch = $business->locations()->create([
            'location_id' => Location::where('slug', $city)->sole()->id, 'address' => '1 Test Road',
        ]);
        $business->members()->attach($owner->id, ['role' => 'owner']);

        return [$owner, $business, $branch];
    }
}

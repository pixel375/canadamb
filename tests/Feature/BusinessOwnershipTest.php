<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\User;
use App\Support\ModerationDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const CITY = 'https://brandonmb.ca';

    private const MAIN = 'https://canadamb.ca';

    public function test_transfer_requires_current_owner_successor_acceptance_and_independent_verified_approval(): void
    {
        [$business, $branch, $owner] = $this->business();
        $successor = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $customer = User::factory()->create();
        $branch->forceFill(['message_contact_user_id' => $owner->id])->save();
        $chat = Conversation::create([
            'context_type' => 'business', 'business_location_id' => $branch->id, 'location_id' => $branch->location_id,
            'buyer_id' => $customer->id, 'seller_id' => $owner->id,
        ]);
        $job = JobPosting::create([
            'business_location_id' => $branch->id, 'author_id' => $owner->id,
            'title' => 'Test shop assistant', 'description' => 'Help customers at this test business.',
            'employment_type' => 'part-time', 'workplace' => 'on-site',
            'apply_url' => 'https://example.test/apply', 'closing_on' => now()->addMonth()->toDateString(),
            'status' => 'approved', 'published_at' => now(),
        ]);
        $job->forceFill(['message_contact_user_id' => $owner->id])->save();

        $create = ['proposed_owner_id' => $successor->id,
            'evidence' => 'I have formally agreed to hand ownership over after an independent identity check.'];
        $this->actingAs($customer)->postJson(self::CITY."/account/businesses/$business->id/ownership-transfer", $create)->assertNotFound();
        $this->actingAs($owner)->post(self::CITY."/account/businesses/$business->id/ownership-transfer", $create)->assertRedirect();
        $case = DB::table('business_ownership_cases')->sole();
        $this->assertSame('awaiting_acceptance', $case->status);
        $this->assertStringNotContainsString($create['evidence'], $case->evidence);
        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $proof = ['decision' => 'approved', 'version' => ModerationDecision::version('ownership-case', $case),
            'proof_method' => 'official_authority',
            'proof_reference' => 'Independent registry test verification 73543',
            'authority_confirmed' => '1'];
        $review = self::MAIN."/admin/ownership-cases/$case->id";
        $this->actingAs($reviewer)->postJson($review, $proof)->assertConflict();
        $this->actingAs($owner)->postJson($review, $proof)->assertForbidden();

        $this->actingAs($successor)->get(self::CITY.'/account/ownership-cases')->assertOk()->assertSee($business->name);
        $this->post(self::CITY."/account/ownership-cases/$case->id/respond", [
            'action' => 'accept', 'version' => $proof['version'],
        ])->assertRedirect();
        $accepted = DB::table('business_ownership_cases')->find($case->id);
        $this->assertSame('pending_review', $accepted->status);
        $this->assertDatabaseMissing('business_memberships', ['business_id' => $business->id, 'user_id' => $successor->id]);
        $proof['version'] = ModerationDecision::version('ownership-case', $accepted);
        $this->actingAs($reviewer)->get($review)->assertOk()->assertSee($create['evidence']);
        $this->postJson($review, $proof)->assertRedirect();
        $this->postJson($review, $proof)->assertConflict();

        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $successor->id, 'role' => 'owner']);
        $this->assertDatabaseMissing('business_memberships', ['business_id' => $business->id, 'user_id' => $owner->id]);
        $this->assertNull($branch->fresh()->message_contact_user_id);
        $this->assertNull($job->fresh()->message_contact_user_id);
        $this->assertDatabaseHas('job_postings', ['id' => $job->id]);
        $this->assertDatabaseHas('conversations', ['id' => $chat->id, 'seller_id' => $owner->id]);
        $this->actingAs($successor)->get(self::CITY."/account/messages/$chat->id")->assertNotFound();
        $this->actingAs($owner)->get(self::CITY."/account/messages/$chat->id")->assertNotFound();
        $this->actingAs($customer)->get(self::CITY."/account/messages/$chat->id")->assertOk();
        $this->actingAs($successor)->put(self::CITY."/account/businesses/$business->id", ['description' => 'Verified transfer works.'])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.ownership-approved', 'actor_id' => $reviewer->id]);
        $this->assertStringNotContainsString($proof['proof_reference'], DB::table('business_ownership_cases')->find($case->id)->proof_reference);
    }

    public function test_independently_confirmed_dispute_holds_role_changes_but_reporting_alone_does_not(): void
    {
        [$business, $branch, $owner] = $this->business();
        $reporter = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $secondReviewer = User::factory()->create(['role' => 'network_admin']);
        $staff = User::factory()->create();
        $report = ['evidence' => 'The public listing claims a business role that conflicts with independently verifiable records.'];
        $this->actingAs($reporter)->post(self::CITY."/businesses/$business->slug/ownership-dispute", $report)->assertRedirect();
        $case = DB::table('business_ownership_cases')->sole();
        $this->assertSame('pending_review', $case->status);
        $this->assertStringNotContainsString($report['evidence'], $case->evidence);
        $this->actingAs($owner)->post(self::CITY."/account/businesses/$business->id/team/invitations", [
            'invited_user_id' => $staff->id, 'role' => 'editor',
        ])->assertRedirect();
        $this->actingAs($reporter)->postJson(self::CITY."/businesses/$business->slug/ownership-dispute", $report)
            ->assertUnprocessable()->assertJsonValidationErrors('evidence');

        $path = self::MAIN."/admin/ownership-cases/$case->id";
        $pending = ModerationDecision::version('ownership-case', $case);
        $decision = ['decision' => 'on_hold', 'version' => $pending,
            'proof_method' => 'official_authority', 'proof_reference' => 'Verified inconsistent ownership documentation.',
            'authority_confirmed' => '1'];
        $this->actingAs($reporter)->postJson($path, $decision)->assertForbidden();
        $this->actingAs($reviewer)->get($path)->assertOk()->assertSee($report['evidence']);
        $this->postJson($path, $decision)->assertRedirect();
        $onHold = DB::table('business_ownership_cases')->find($case->id);
        $this->assertSame('on_hold', $onHold->status);
        $this->actingAs($owner)->deleteJson(self::CITY."/account/businesses/$business->id/team/invitations/1")->assertConflict();
        $this->putJson(self::CITY."/account/businesses/$business->id", ['description' => 'Change while held.'])->assertConflict();
        $this->actingAs($staff)->postJson(self::CITY.'/account/business-invitations/1/respond', ['decision' => 'accept'])->assertConflict();
        $this->actingAs($reporter)->postJson(self::CITY."/account/ownership-cases/$case->id/respond", [
            'action' => 'withdraw', 'version' => ModerationDecision::version('ownership-case', $onHold),
        ])->assertForbidden();

        $release = ['decision' => 'released', 'version' => ModerationDecision::version('ownership-case', $onHold),
            'proof_method' => 'independent_contact',
            'proof_reference' => 'Second independent examiner closed this ownership investigation.',
            'authority_confirmed' => '1'];
        $this->actingAs($reviewer)->postJson($path, $release)->assertConflict();
        $this->actingAs($secondReviewer)->postJson($path, $release)->assertRedirect();
        $this->actingAs($owner)->put(self::CITY."/account/businesses/$business->id", ['description' => 'Updated after review.'])
            ->assertRedirect();
        $this->assertDatabaseHas('business_ownership_cases', ['id' => $case->id, 'status' => 'released']);
        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $owner->id, 'role' => 'owner']);
    }

    public function test_wrong_city_duplicate_transfer_unverified_successor_and_stale_token_fail_closed(): void
    {
        [$business, $branch, $owner] = $this->business('elkhorn');
        $successor = User::factory()->create();
        $unverified = User::factory()->unverified()->create();
        $reporter = User::factory()->create();
        $request = ['proposed_owner_id' => $successor->id,
            'evidence' => 'I request a verified handover following proper independent administrative review.'];
        $this->actingAs($owner)->postJson(self::CITY."/account/businesses/$business->id/ownership-transfer", $request)->assertNotFound();
        $this->postJson(self::MAIN."/account/businesses/$business->id/ownership-transfer", [...$request, 'proposed_owner_id' => $unverified->id])->assertUnprocessable();
        $this->post(self::MAIN."/account/businesses/$business->id/ownership-transfer", $request)->assertRedirect();
        $this->postJson(self::MAIN."/account/businesses/$business->id/ownership-transfer", $request)->assertUnprocessable();
        $case = DB::table('business_ownership_cases')->sole();
        $version = ModerationDecision::version('ownership-case', $case);
        $this->actingAs($successor)->postJson(self::CITY."/account/ownership-cases/$case->id/respond", [
            'action' => 'accept', 'version' => $version,
        ])->assertNotFound();
        $this->post(self::MAIN."/account/ownership-cases/$case->id/respond", [
            'action' => 'decline', 'version' => $version,
        ])->assertRedirect();
        $this->postJson(self::MAIN."/account/ownership-cases/$case->id/respond", [
            'action' => 'accept', 'version' => $version,
        ])->assertConflict();
        $this->actingAs($reporter)->postJson(self::CITY."/businesses/$business->slug/ownership-dispute", [
            'evidence' => 'An irrelevant report to a business in another municipality must not be accepted.',
        ])->assertNotFound();
        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $owner->id, 'role' => 'owner']);
    }

    private function business(string $city = 'brandon'): array
    {
        $owner = User::factory()->create();
        $business = Business::create(['slug' => 'ownership-case-'.$city,
            'name' => 'Ownership Example '.$city, 'category' => 'services', 'status' => 'active']);
        $branch = $business->locations()->create([
            'location_id' => Location::where('slug', $city)->sole()->id, 'address' => '1 Synthetic Ownership Road',
        ]);
        $business->members()->attach($owner->id, ['role' => 'owner']);

        return [$business, $branch, $owner];
    }
}

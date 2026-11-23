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
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessBranchesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const CITY = 'https://brandonmb.ca';

    private const MAIN = 'https://canadamb.ca';

    public function test_new_branch_requires_independent_review_and_city_scoping(): void
    {
        [$business, $branch, $owner, $reviewer] = $this->setupBusiness();
        $elkhorn = Location::where('slug', 'elkhorn')->sole();
        $input = ['action' => 'add', 'location_id' => $elkhorn->id, 'proposed_address' => '400 Test Route',
            'evidence' => 'This second Manitoba branch is documented in a verifiable synthetic business record.'];

        $this->actingAs($owner)->postJson(self::CITY."/account/businesses/$business->id/branches", $input)
            ->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->post(self::MAIN."/account/businesses/$business->id/branches", $input)->assertRedirect();
        $change = DB::table('business_branch_requests')->sole();
        $this->assertDatabaseCount('business_locations', 1);
        $this->assertDatabaseHas('business_branch_requests', ['id' => $change->id, 'status' => 'pending']);
        $this->postJson(self::MAIN."/account/businesses/$business->id/branches", $input)->assertUnprocessable();
        $this->actingAs($owner)->postJson(self::MAIN."/admin/business-branches/$change->id", $this->approve($change))->assertForbidden();

        $this->actingAs($reviewer)->get(self::MAIN."/admin/business-branches/$change->id")
            ->assertOk()->assertSee($input['evidence']);
        $this->postJson(self::MAIN."/admin/business-branches/$change->id", $this->approve($change))->assertRedirect();
        $this->assertDatabaseCount('business_locations', 2);
        $this->assertDatabaseHas('business_locations', ['business_id' => $business->id,
            'location_id' => $elkhorn->id, 'address' => '400 Test Route',
            'source_id' => null, 'location_status' => 'user_selected', 'operational_status' => 'open']);
        $this->get(self::MAIN."/businesses/$business->slug")->assertOk()->assertSee('400 Test Route');
        $this->get(self::CITY."/businesses/$business->slug")->assertOk()->assertSee('1 Original Street')
            ->assertDontSee('400 Test Route');
        $this->postJson(self::MAIN."/admin/business-branches/$change->id", $this->approve($change))->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.branch-change-approved', 'actor_id' => $reviewer->id]);
    }

    public function test_address_correction_preserves_import_identity_and_closure_keeps_job_and_chat_history(): void
    {
        [$business, $branch, $owner, $reviewer] = $this->setupBusiness();
        $source = DB::table('business_sources')->insertGetId([
            'name' => 'Synthetic licensed import', 'url' => 'https://example.test',
            'license' => 'Synthetic test-only fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch->forceFill(['source_id' => $source, 'external_key' => 'BRANDON-1',
            'location_status' => 'imported_address', 'imported_at' => now()])->save();
        $initialImportKey = $branch->fresh()->import_key;
        $branch->forceFill(['message_contact_user_id' => $owner->id])->save();
        $job = JobPosting::create([
            'business_location_id' => $branch->id, 'author_id' => $owner->id,
            'title' => 'Test local work', 'description' => 'Synthetic original branch job description.',
            'employment_type' => 'part-time', 'workplace' => 'on-site',
            'apply_url' => 'https://example.test/apply', 'closing_on' => now()->addMonth()->toDateString(),
            'status' => 'approved', 'published_at' => now(),
        ]);
        $job->forceFill([
            'status' => 'approved', 'published_at' => now(),
            'message_contact_user_id' => $owner->id,
        ])->save();
        $customer = User::factory()->create();
        $conversation = Conversation::create([
            'context_type' => 'business', 'business_location_id' => $branch->id, 'location_id' => $branch->location_id,
            'buyer_id' => $customer->id, 'seller_id' => $owner->id,
        ]);
        $this->actingAs($owner)->post(self::CITY."/account/businesses/$business->id/branches", [
            'action' => 'correct_address', 'business_location_id' => $branch->id,
            'proposed_address' => '17 Revised Avenue',
            'evidence' => 'This is an independent street address correction, not a company relocation.',
        ])->assertRedirect();
        $change = DB::table('business_branch_requests')->latest('id')->first();
        $this->assertSame('1 Original Street', $branch->fresh()->address);
        $this->actingAs($reviewer)->postJson(self::MAIN."/admin/business-branches/$change->id",
            $this->approve($change))->assertRedirect();
        $corrected = $branch->fresh();
        $this->assertSame('17 Revised Avenue', $corrected->address);
        $this->assertSame($source, $corrected->source_id);
        $this->assertSame('BRANDON-1', $corrected->external_key);
        $this->assertSame($initialImportKey, $corrected->import_key);
        $this->assertSame('imported_address', $corrected->location_status);
        $this->assertNotNull($corrected->last_verified_at);

        $this->actingAs($owner)->post(self::CITY."/account/businesses/$business->id/branches", [
            'action' => 'temporarily_closed', 'business_location_id' => $branch->id,
            'evidence' => 'Synthetic temporary closure follows a reviewed independent operations notice.',
        ])->assertRedirect();
        $closed = DB::table('business_branch_requests')->latest('id')->first();
        $this->actingAs($reviewer)->postJson(self::MAIN."/admin/business-branches/$closed->id",
            $this->approve($closed))->assertRedirect();
        $this->assertSame('temporarily_closed', $branch->fresh()->operational_status);
        $this->assertNull($branch->fresh()->message_contact_user_id);
        $this->assertNull($job->fresh()->message_contact_user_id);
        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'status' => 'approved']);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
        $this->actingAs($owner)->get(self::CITY."/account/messages/$conversation->id")->assertOk();
        $this->actingAs($customer)->get(self::CITY."/account/messages/$conversation->id")->assertOk();
        $this->postJson(self::CITY."/businesses/$business->slug/branches/$branch->id/message", [
            'body' => 'Cannot start a new inquiry for a closed location.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->get(self::CITY."/businesses/$business->slug")
            ->assertOk()->assertSee('Temporarily closed')->assertSee('17 Revised Avenue');

        $this->actingAs($owner)->post(self::CITY."/account/businesses/$business->id/branches", [
            'action' => 'reopen', 'business_location_id' => $branch->id,
            'evidence' => 'The previously closed test shop has reopened after independent verification.',
        ])->assertRedirect();
        $reopen = DB::table('business_branch_requests')->latest('id')->first();
        $this->actingAs($reviewer)->postJson(self::MAIN."/admin/business-branches/$reopen->id",
            $this->approve($reopen))->assertRedirect();
        $this->assertSame('open', $branch->fresh()->operational_status);
        $this->assertNull($branch->fresh()->message_contact_user_id);
        $this->assertDatabaseHas('job_postings', ['id' => $job->id]);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    }

    public function test_stale_branch_changes_and_wrong_city_requests_cannot_be_applied(): void
    {
        [$business, $branch, $owner, $reviewer] = $this->setupBusiness('elkhorn');
        $this->actingAs($owner)->post(self::MAIN."/account/businesses/$business->id/branches", [
            'action' => 'correct_address', 'business_location_id' => $branch->id,
            'proposed_address' => 'New Test Road',
            'evidence' => 'Independently evidenced correction for a synthetic existing Manitoba branch.',
        ])->assertRedirect();
        $request = DB::table('business_branch_requests')->sole();
        $this->postJson(self::CITY."/account/businesses/$business->id/branches", [
            'action' => 'closed', 'business_location_id' => $branch->id,
            'evidence' => 'A false request in another community should not affect the existing branch.',
        ])->assertNotFound();
        $branch->forceFill(['address' => 'Locally changed Road'])->save();
        $this->actingAs($reviewer)->postJson(self::MAIN."/admin/business-branches/$request->id",
            $this->approve($request))->assertConflict();
        $this->assertDatabaseHas('business_branch_requests', ['id' => $request->id, 'status' => 'pending']);
        $this->assertSame('Locally changed Road', $branch->fresh()->address);
    }

    private function setupBusiness(string $city = 'brandon'): array
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $business = Business::create(['slug' => 'branch-review-'.$city, 'name' => 'Branch Review '.$city,
            'category' => 'services', 'status' => 'active']);
        $branch = $business->locations()->create([
            'location_id' => Location::where('slug', $city)->sole()->id, 'address' => '1 Original Street',
        ]);
        $business->members()->attach($owner->id, ['role' => 'owner']);

        return [$business, $branch, $owner, $reviewer];
    }

    private function approve(object $case): array
    {
        return [
            'decision' => 'approved', 'version' => ModerationDecision::version('branch-request', $case),
            'proof_method' => 'official_authority',
            'proof_reference' => 'Independently verified synthetic branch records 94812',
            'authority_confirmed' => '1',
        ];
    }
}

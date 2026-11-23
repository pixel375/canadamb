<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\Support\ModerationDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessClaimLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_claim_approval_requires_independent_authority_proof_and_a_still_verified_claimant(): void
    {
        $claimant = User::factory()->create(['role' => 'network_admin']);
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $claim = $this->submit($claimant);
        $url = 'https://canadamb.ca/admin/moderation/items/claim/'.$claim->id;
        $version = ModerationDecision::version('claim', $claim);
        $approval = ['action' => 'approve', 'version' => $version, 'notes' => 'Contact email happens to match.'];
        $proof = ['proof_method' => 'official_authority', 'proof_reference' => 'Reviewed test reference 8523.',
            'authority_confirmed' => 'on'];
        $this->actingAs($reviewer)->postJson($url, $approval)->assertUnprocessable()->assertJsonValidationErrors('proof_method');
        $this->actingAs($claimant)->postJson($url, $approval + $proof)->assertForbidden();
        $claimant->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($reviewer)->postJson($url, $approval + $proof)->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertDatabaseCount('business_memberships', 0);
        $claimant->forceFill(['email_verified_at' => now()])->save();
        $this->get($url)->assertOk()->assertSee('Synthetic confidential evidence')->assertSee('Independent verification');
        $this->postJson($url, $approval + $proof)->assertRedirect();
        $this->postJson($url, $approval + $proof)->assertConflict();
        $this->assertDatabaseHas('business_memberships', ['user_id' => $claimant->id, 'business_id' => $claim->business_id, 'role' => 'owner']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $reviewer->id, 'action' => 'business.claim-evidence-viewed']);
        $this->assertDatabaseHas('notifications', ['user_id' => $claimant->id, 'resource_id' => $claim->id, 'event' => 'claim.approved']);
    }

    public function test_claim_revision_withdrawal_and_history_are_private_and_location_scoped(): void
    {
        $claimant = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $claim = $this->submit($claimant, 'winnipeg');
        $path = '/account/business-claims/'.$claim->id;
        $this->get('https://brandonmb.ca'.$path)->assertNotFound();
        $this->get('https://brandonmb.ca/account/businesses')->assertDontSee('Synthetic Claim Profile');
        $this->actingAs(User::factory()->create())->get('https://canadamb.ca'.$path)->assertNotFound();
        $this->actingAs($claimant)->get('https://canadamb.ca'.$path)->assertOk()->assertSee('Synthetic confidential evidence');
        $version = ModerationDecision::version('claim', $claim);
        $next = ['contact_email' => 'claimed@example.test', 'evidence' => 'An updated confidential synthetic verification reference.',
            'version' => $version];
        $this->put('https://canadamb.ca'.$path, $next)->assertSessionHasNoErrors()->assertRedirect();
        $this->putJson('https://canadamb.ca'.$path, $next)->assertConflict();
        $this->assertStringNotContainsString($next['evidence'], DB::table('business_claims')->find($claim->id)->evidence);
        $current = ModerationDecision::version('claim', DB::table('business_claims')->find($claim->id));
        $this->delete('https://canadamb.ca'.$path, ['version' => $current])->assertRedirect();
        $this->get('https://canadamb.ca'.$path)->assertSee('Withdrawn');
        $this->actingAs($reviewer)->get('https://canadamb.ca/admin/moderation/items/claim/'.$claim->id)
            ->assertOk()->assertSee($next['evidence']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $claimant->id, 'action' => 'business.claim-revised']);
    }

    private function submit(User $claimant, string $place = 'brandon'): object
    {
        $business = Business::create(['slug' => 'synthetic-claim', 'name' => 'Synthetic Claim Profile', 'category' => 'retail', 'status' => 'active']);
        $business->locations()->create(['location_id' => Location::where('slug', $place)->sole()->id, 'address' => '1 Test Street']);
        $this->actingAs($claimant)->post('https://canadamb.ca/businesses/synthetic-claim/claim', [
            'contact_email' => 'claimant@example.test', 'evidence' => 'Synthetic confidential evidence included for offline review.',
        ])->assertSessionHasNoErrors()->assertRedirect();

        return DB::table('business_claims')->sole();
    }
}

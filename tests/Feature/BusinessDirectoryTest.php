<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessUpdate;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_business_team_permissions_are_owner_controlled_and_revocable(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $business = Business::create(['slug' => 'team-test', 'name' => 'Team Test', 'category' => 'services', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Team Road']);
        $path = 'https://canadamb.ca/account/businesses/'.$business->id.'/team';
        $grant = ['email' => $member->email, 'role' => 'editor', 'consent' => '1'];
        $this->actingAs($stranger)->get($path)->assertNotFound();
        $this->post($path, $grant)->assertNotFound();
        $this->actingAs($owner)->post($path, array_replace($grant, ['role' => 'owner']))->assertSessionHasErrors('role');
        $this->post($path, $grant)->assertRedirect()->assertSessionHasNoErrors();
        $this->get($path)->assertOk()->assertSee($member->name)->assertDontSee($member->email);
        $this->actingAs($member)->get($path)->assertOk()->assertDontSee('Grant access');
        $this->get('https://canadamb.ca/account/businesses/'.$business->id.'/jobs')->assertNotFound();
        $this->put($path.'/'.$member->id, ['role' => 'manager'])->assertNotFound();
        $this->actingAs($owner)->put($path.'/'.$member->id, ['role' => 'manager'])->assertRedirect()->assertSessionHasNoErrors();
        $this->delete($path.'/'.$owner->id)->assertNotFound();
        $this->actingAs($member)->get('https://canadamb.ca/account/businesses/'.$business->id.'/jobs')->assertOk();
        $this->actingAs($owner)->delete($path.'/'.$member->id)->assertRedirect();
        $this->actingAs($member)->get($path)->assertNotFound();
        $this->put('https://canadamb.ca/account/businesses/'.$business->id, ['description' => 'Unauthorized change'])->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.member-removed', 'actor_id' => $owner->id]);
        $this->assertDatabaseCount('business_memberships', 1);
        $this->get('https://canadamb.ca/admin/business-ownership')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))->get('https://canadamb.ca/admin/business-ownership?q=Team&ownership=claimed')->assertOk()->assertSee('Team Test')->assertSee($owner->name)->assertDontSee($owner->email);
        $this->get('https://canadamb.ca/admin/business-ownership?q=Team&ownership=unclaimed')->assertOk()->assertSee('No matching businesses.');
    }

    public function test_team_members_can_leave_but_owners_cannot_and_stale_role_changes_are_rejected(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $business = Business::create(['slug' => 'leave-team', 'name' => 'Leave Team', 'category' => 'services', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $business->members()->attach($editor->id, ['role' => 'editor']);
        $url = 'https://canadamb.ca/account/businesses/'.$business->id.'/team';
        $this->actingAs($owner)->put($url.'/'.$editor->id, ['role' => 'editor', 'expected_role' => 'editor'])->assertRedirect();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'business.member-role-changed']);
        $this->put($url.'/'.$editor->id, ['role' => 'manager', 'expected_role' => 'editor'])->assertRedirect();
        $this->delete($url.'/'.$editor->id, ['expected_role' => 'editor'])->assertSessionHasErrors('expected_role');
        $this->post($url.'/leave', ['expected_role' => 'manager', 'confirm_leave' => '1'])->assertNotFound();
        $this->actingAs($editor)->get($url)->assertOk()->assertSee('Leave business team');
        $this->post($url.'/leave', ['expected_role' => 'editor', 'confirm_leave' => '1'])->assertSessionHasErrors('expected_role');
        $this->post($url.'/leave', ['expected_role' => 'manager'])->assertSessionHasErrors('confirm_leave');
        $this->post($url.'/leave', ['expected_role' => 'manager', 'confirm_leave' => '1'])->assertRedirect('/account/businesses');
        $this->get($url)->assertNotFound();
        $this->assertDatabaseCount('business_memberships', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.member-left', 'actor_id' => $editor->id]);
    }

    public function test_business_profile_editor_preserves_concurrent_changes_and_audits_real_edits(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $business = Business::create(['slug' => 'concurrent-profile', 'name' => 'Concurrent Profile', 'category' => 'services', 'status' => 'active', 'description' => 'Original profile']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $business->members()->attach($editor->id, ['role' => 'editor']);
        $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Test Road']);
        $url = 'https://canadamb.ca/account/businesses/'.$business->id;
        $version = $business->profileVersion();
        $this->actingAs($owner)->get($url)->assertOk()->assertSee($version);
        $this->put($url, ['description' => 'Original profile', 'version' => $version])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'business.profile-updated']);
        $this->actingAs($editor)->put($url, ['description' => 'Editor changed profile', 'version' => $version])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->put($url, ['description' => 'Stale owner edit', 'version' => $version])->assertSessionHasErrors('version');
        $this->assertSame('Editor changed profile', $business->fresh()->description);
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.profile-updated', 'actor_id' => $editor->id]);
    }

    public function test_pending_business_review_is_versioned_and_searchable_by_community(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $business = Business::create(['slug' => 'pending-repair', 'name' => 'Pending Repair', 'category' => 'services', 'status' => 'pending', 'description' => 'Original description']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $branch = $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Review Road']);
        $url = 'https://canadamb.ca/admin/business-review';
        $version = $business->profileVersion();
        $this->actingAs($admin)->get($url.'?q=Repair&location='.$branch->location_id)->assertOk()->assertSee('Pending Repair')->assertSee($version);
        $this->get($url.'?location='.Location::where('slug', 'winnipeg')->sole()->id)->assertOk()->assertDontSee('Pending Repair');
        $business->update(['description' => 'Revised description']);
        $decision = ['decision' => 'approved', 'notes' => 'Independent profile review completed.'];
        $this->post($url.'/'.$business->id, $decision + ['version' => $version])->assertConflict();
        $this->assertSame('pending', $business->fresh()->status);
        $this->post($url.'/'.$business->id, $decision + ['version' => $business->fresh()->profileVersion()])->assertRedirect();
        $this->get($url.'?history=completed&q=Repair')->assertOk()->assertSee('Pending Repair')->assertSee('Historical record')->assertDontSee('action="/admin/business-review/'.$business->id.'"', false);
    }

    public function test_import_is_idempotent_and_directory_respects_physical_branch_location(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'canadamb-business-');
        try {
            file_put_contents($file, "external_key,name,category,location,address,website\nb-1,Brandon Bicycle Repair,services,brandon,123 Test Street,https://example.test\nr-1,Elkhorn Farm Repairs,services,elkhorn,456 Test Road,https://example.test\n");
            foreach ([1, 2] as $attempt) {
                $this->artisan('businesses:import', ['file' => $file, '--source' => 'QA licensed input', '--license' => 'Test fixture', '--url' => 'https://example.test'])->assertSuccessful();
            }
        } finally {
            unlink($file);
        }
        $this->assertDatabaseCount('businesses', 2);
        $this->assertDatabaseCount('business_locations', 2);
        $this->assertDatabaseCount('business_import_runs', 2);
        $this->get('https://canadamb.ca/businesses')->assertOk()->assertSee('Brandon Bicycle Repair')->assertSee('Elkhorn Farm Repairs');
        $this->get('https://brandonmb.ca/businesses')->assertOk()->assertSee('Brandon Bicycle Repair')->assertDontSee('Elkhorn Farm Repairs');
        $this->get('https://canadamb.ca/businesses?location=brandon&category=services')->assertOk()
            ->assertSee('Browse by category')->assertSee('1 business')->assertSee('Brandon Bicycle Repair')->assertDontSee('Elkhorn Farm Repairs');
        $this->get('https://canadamb.ca/businesses?q=Repair&category=retail')->assertOk()
            ->assertSee('2 businesses')->assertSee('No matching businesses.');
        $business = Business::where('name', 'Brandon Bicycle Repair')->sole();
        $this->get('https://brandonmb.ca/businesses/'.$business->slug)->assertSee('Own this business?');
        $this->get('https://canadamb.ca/businesses/'.$business->slug)->assertStatus(301)->assertRedirect('https://brandonmb.ca/businesses/'.$business->slug);
        $this->get('https://brandonmb.ca/sitemap.xml')->assertSee('https://brandonmb.ca/businesses/'.$business->slug);
        $this->get('https://canadamb.ca/sitemap.xml')->assertDontSee('businesses/'.$business->slug);
    }

    public function test_verified_claim_requires_review_and_grants_business_editing_to_the_claimant(): void
    {
        $business = Business::create(['slug' => 'test-repair', 'name' => 'Test Repair', 'category' => 'services', 'status' => 'active']);
        $branch = $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Test Road']);
        $claimant = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $this->actingAs($claimant)->post('https://brandonmb.ca/businesses/test-repair/claim', [
            'contact_email' => 'owner@example.test', 'evidence' => 'I can supply the registration document and respond from our business address.',
        ])->assertRedirect();
        $claimId = DB::table('business_claims')->value('id');
        $competitor = User::factory()->create();
        $this->actingAs($competitor)->post('https://brandonmb.ca/businesses/test-repair/claim', [
            'contact_email' => 'competing@example.test', 'evidence' => 'I have independent evidence for another ownership request.',
        ])->assertRedirect();
        $competing = DB::table('business_claims')->where('user_id', $competitor->id)->value('id');
        $this->post('https://brandonmb.ca/account/business-claims/'.$claimId.'/withdraw')->assertNotFound();
        $this->post('https://brandonmb.ca/account/business-claims/'.$competing.'/withdraw')->assertRedirect();
        $this->assertDatabaseHas('business_claims', ['id' => $competing, 'status' => 'withdrawn']);
        $this->post('https://brandonmb.ca/businesses/test-repair/claim', [
            'contact_email' => 'competing@example.test', 'evidence' => 'I have independent evidence for another ownership request.',
        ])->assertRedirect();
        $this->actingAs($claimant);
        $this->put("https://brandonmb.ca/account/businesses/$business->id", ['description' => 'Repairs bicycles.'])->assertNotFound();
        $this->actingAs($admin)->post("https://canadamb.ca/admin/business-claims/$claimId", [
            'decision' => 'approved', 'notes' => 'Matched the registration and confirmed control of contact email.',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $claimant->id, 'role' => 'owner']);
        $this->assertDatabaseHas('business_claims', ['user_id' => $competitor->id, 'status' => 'superseded']);
        $this->actingAs($claimant)->put("https://brandonmb.ca/account/businesses/$business->id", [
            'description' => 'Repairs bicycles.', 'public_email' => 'repairs@example.test',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->get('https://brandonmb.ca/businesses/test-repair')->assertSee('Repairs bicycles.')->assertDontSee('owner@example.test');

        $this->post("https://brandonmb.ca/account/businesses/$business->id/updates", [
            'business_location_id' => $branch->id, 'type' => 'announcement', 'title' => 'Workshop opens Saturday',
            'body' => 'Bring your bike for a tune-up.',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->get('https://brandonmb.ca/')->assertDontSee('Workshop opens Saturday');
        $this->get('https://brandonmb.ca/business-updates?q=Workshop')->assertOk()->assertDontSee('Workshop opens Saturday');
        $this->get('https://brandonmb.ca/account/businesses')->assertOk()->assertSee('Manage jobs')->assertSee('Test Repair');
        $this->get('https://brandonmb.ca/account/businesses?q=Repair&role=owner')->assertOk()->assertSee('Manage jobs')->assertSee('1 matching');
        $this->get('https://brandonmb.ca/account/businesses?role=editor')->assertOk()->assertSee('No managed profiles match your filters.')->assertDontSee('Manage jobs');
        $this->get('https://brandonmb.ca/account/businesses?claim_status=withdrawn')->assertOk()->assertSee('No claims match this status.');
        $this->actingAs($competitor)->get('https://brandonmb.ca/account/businesses?claim_status=withdrawn')->assertOk()->assertSee('Test Repair')->assertDontSee('Manage jobs');
        $this->actingAs($claimant);
        $this->get("https://brandonmb.ca/account/businesses/$business->id")->assertOk()->assertSee('Recent business updates')->assertSee('Workshop opens Saturday')->assertSee('Pending');
        $update = DB::table('business_updates')->value('id');
        $this->actingAs($admin)->post("https://canadamb.ca/admin/business-updates/$update", [
            'decision' => 'approved', 'notes' => 'Reviewed the update.', 'version' => BusinessUpdate::findOrFail($update)->reviewVersion(),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->get('https://brandonmb.ca/')->assertSee('Workshop opens Saturday');
        $this->get('https://canadamb.ca/')->assertSee('Workshop opens Saturday');
        $this->get('https://canadamb.ca/business-updates?location=brandon&type=announcement&q=bike%20Workshop')->assertOk()->assertSee('Workshop opens Saturday');
        $this->get('https://canadamb.ca/business-updates?location=elkhorn&q=Workshop')->assertOk()->assertDontSee('Workshop opens Saturday');
        $this->get('https://brandonmb.ca/business-updates?type=promotion')->assertOk()->assertDontSee('Workshop opens Saturday');
        $this->assertDatabaseCount('business_updates', 1);
        $record = BusinessUpdate::findOrFail($update);
        $version = $record->reviewVersion();
        $manage = "https://brandonmb.ca/account/businesses/$business->id/updates";
        $revision = ['type' => 'announcement', 'title' => 'Workshop opens Sunday', 'body' => 'Updated public workshop hours.', 'version' => $version];
        $this->actingAs($competitor)->put($manage.'/'.$update, $revision)->assertNotFound();
        $this->actingAs($claimant)->get($manage)->assertOk()->assertSee('Workshop opens Saturday');
        $this->put($manage.'/'.$update, $revision)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('https://brandonmb.ca/')->assertDontSee('Workshop opens Sunday')->assertDontSee('Workshop opens Saturday');
        $this->actingAs($admin)->post("https://canadamb.ca/admin/business-updates/$update", ['decision' => 'approved', 'notes' => 'Old approval attempt.', 'version' => $version])->assertConflict();
        $this->post("https://canadamb.ca/admin/business-updates/$update", ['decision' => 'approved', 'notes' => 'Reviewed revised content.', 'version' => $record->fresh()->reviewVersion()])->assertRedirect();
        $this->get('https://brandonmb.ca/')->assertSee('Workshop opens Sunday');
        $this->actingAs($claimant)->post($manage.'/'.$update.'/withdraw', ['version' => $record->fresh()->reviewVersion()])->assertRedirect();
        $this->get('https://brandonmb.ca/')->assertDontSee('Workshop opens Sunday');
        $this->get($manage.'?status=withdrawn')->assertSee('Workshop opens Sunday');
    }
}

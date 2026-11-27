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

    public function test_dashboard_excludes_business_records_the_reviewer_cannot_moderate(): void
    {
        $admin = User::factory()->create(['role' => 'network_admin']);
        $other = User::factory()->create();
        foreach ([$admin, $other] as $owner) {
            $business = Business::create(['slug' => 'review-count-'.$owner->id, 'name' => 'Review Count '.$owner->id, 'category' => 'services', 'status' => 'pending']);
            $business->members()->attach($owner->id, ['role' => 'owner']);
            $branch = $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => $owner->id.' Review Road']);
            BusinessUpdate::create(['business_location_id' => $branch->id, 'author_id' => $owner->id, 'title' => 'Review count update', 'body' => 'A pending update for review.', 'type' => 'announcement']);
        }
        $this->actingAs($admin)->get('https://canadamb.ca/admin')->assertOk()->assertViewHas('queues', function ($queues) {
            $counts = collect($queues)->pluck('count', 'label');

            return $counts['Business profiles'] === 1 && $counts['Business updates'] === 1;
        });
        $this->get('https://canadamb.ca/admin?queue=attention')->assertOk()
            ->assertViewHas('queues', fn ($queues) => count($queues) >= 2 && collect($queues)->every(fn ($queue) => $queue['count'] > 0))
            ->assertSee('Needs attention')->assertSee('Business profiles')->assertSee('Business updates');
        $this->get('https://canadamb.ca/admin?queue=invalid')->assertSessionHasErrors('queue');
    }

    public function test_selected_business_update_withdrawal_is_atomic_and_role_scoped(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $business = Business::create(['slug' => 'batch-updates', 'name' => 'Batch Updates', 'category' => 'services', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $business->members()->attach($editor->id, ['role' => 'editor']);
        $branch = $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '10 Update Road']);
        $other = $business->locations()->create(['location_id' => Location::where('slug', 'elkhorn')->sole()->id, 'address' => '20 Update Road']);
        $records = collect();
        foreach ([[$branch, $owner], [$branch, $editor], [$other, $owner]] as [$place, $author]) {
            $records->push(BusinessUpdate::create(['business_location_id' => $place->id, 'author_id' => $author->id,
                'title' => 'Update fixture '.$author->id.' '.$place->id, 'body' => 'A sample update.', 'type' => 'announcement', 'status' => 'approved', 'published_at' => now()]));
        }
        $base = 'https://brandonmb.ca/account/businesses/'.$business->id.'/updates';
        $url = $base.'/withdraw-selected';
        $versions = $records->mapWithKeys(fn ($record) => [$record->id => $record->fresh()->reviewVersion()])->all();
        $data = ['updates' => [$records[0]->id, $records[1]->id], 'versions' => $versions, 'confirm_withdraw' => '1'];
        $this->actingAs($editor)->post($url, $data)->assertNotFound();
        $this->actingAs($owner)->get($base.'?type=announcement&branch='.$branch->id)->assertOk()->assertSee('2 matching updates')->assertSee('Withdraw selected updates');
        $this->post($url, array_replace($data, ['updates' => [$records[0]->id, $records[2]->id]]))->assertNotFound();
        $this->post($url, array_replace($data, ['versions' => array_replace($versions, [$records[1]->id => str_repeat('0', 64)])]))->assertSessionHasErrors('updates');
        $this->assertSame('approved', $records[0]->fresh()->status);
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $records[0]->fresh()->status);
        $this->assertSame('withdrawn', $records[1]->fresh()->status);
        $this->assertSame('approved', $records[2]->fresh()->status);
    }

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
        $this->actingAs($owner)->put($path.'/'.$member->id, ['role' => 'manager'])->assertSessionHasErrors('expected_role');
        $this->put($path.'/'.$member->id, ['role' => 'manager', 'expected_role' => 'editor'])->assertRedirect()->assertSessionHasNoErrors();
        $this->delete($path.'/'.$owner->id)->assertNotFound();
        $this->actingAs($member)->get('https://canadamb.ca/account/businesses/'.$business->id.'/jobs')->assertOk();
        $this->actingAs($owner)->delete($path.'/'.$member->id)->assertSessionHasErrors('expected_role');
        $this->delete($path.'/'.$member->id, ['expected_role' => 'manager'])->assertRedirect();
        $this->actingAs($member)->get($path)->assertNotFound();
        $this->put('https://canadamb.ca/account/businesses/'.$business->id, ['description' => 'Unauthorized change'])->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.member-removed', 'actor_id' => $owner->id]);
        $this->assertDatabaseCount('business_memberships', 1);
        $this->get('https://canadamb.ca/admin/business-ownership')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'network_admin']))->get('https://canadamb.ca/admin/business-ownership?q=Team&ownership=claimed')->assertOk()->assertSee('Team Test')->assertSee($owner->name)->assertDontSee($owner->email);
        $this->get('https://canadamb.ca/admin/business-ownership?q=Team&ownership=unclaimed')->assertOk()->assertSee('No matching businesses.');
        $this->get('https://canadamb.ca/admin/business-ownership?member='.$owner->id.'&status=active')->assertOk()->assertSee('Team Test')->assertDontSee($owner->email);
        $this->get('https://canadamb.ca/admin/business-ownership?member='.$owner->id.'&ownership=ambiguous')->assertOk()->assertSee('No matching businesses.');
        $business->members()->attach($stranger->id, ['role' => 'owner']);
        $this->get('https://canadamb.ca/admin/business-ownership?reference='.$business->id.'&ownership=ambiguous')->assertOk()->assertSee('Multiple owner records.');
        $business->members()->detach($stranger->id);
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
        $this->put($url, ['description' => 'Missing version'])->assertSessionHasErrors('version');
        $this->assertSame('Original profile', $business->fresh()->description);
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
        $this->get('https://canadamb.ca/businesses/'.$business->slug)->assertOk()->assertSee($business->name)->assertSee('Own this business?');
        $this->get('https://brandonmb.ca/sitemap.xml')->assertSee('https://brandonmb.ca/businesses/'.$business->slug);
        $this->get('https://canadamb.ca/sitemap.xml')->assertDontSee('businesses/'.$business->slug);
    }

    public function test_multicommunity_profiles_preserve_local_branches_and_provincial_canonical_profile(): void
    {
        $brandon = Location::where('slug', 'brandon')->sole();
        $rural = Location::where('slug', 'elkhorn')->sole();
        $business = Business::create(['slug' => 'multi-repair', 'name' => 'Multi Repair', 'category' => 'services', 'status' => 'active', 'phone' => '204-555-0123']);
        $business->locations()->create(['location_id' => $brandon->id, 'address' => '1 Brandon Fixture Road']);
        $business->locations()->create(['location_id' => $rural->id, 'address' => '2 Elkhorn Fixture Road']);
        $other = Business::create(['slug' => 'other-repair', 'name' => 'Other Repair', 'category' => 'services', 'status' => 'active', 'website' => 'https://example.test']);
        $other->locations()->create(['location_id' => $brandon->id, 'address' => '3 Fixture Road']);
        $this->get('https://brandonmb.ca/businesses/multi-repair')->assertOk()->assertSee('1 Brandon Fixture Road')->assertDontSee('2 Elkhorn Fixture Road')
            ->assertSee('Showing only branches in this community.')->assertDontSee('<a class="text-link" href="https://canadamb.ca/businesses/multi-repair">', false)
            ->assertSee('<link rel="canonical" href="https://canadamb.ca/businesses/multi-repair">', false);
        $this->get('https://canadamb.ca/businesses/multi-repair')->assertOk()->assertSee('1 Brandon Fixture Road')->assertSee('2 Elkhorn Fixture Road');
        $this->get('https://canadamb.ca/sitemap.xml')->assertSee('https://canadamb.ca/businesses/multi-repair');
        $this->get('https://brandonmb.ca/sitemap.xml')->assertDontSee('businesses/multi-repair');
        $this->get('https://brandonmb.ca/businesses?contact=phone')->assertOk()->assertSee('Multi Repair')->assertDontSee('Other Repair');
        $this->get('https://canadamb.ca/businesses?location=brandon&sort=name-desc')->assertOk()->assertSeeInOrder(['Other Repair', 'Multi Repair']);
        $this->actingAs(User::factory()->create())->get('https://canadamb.ca/account/businesses/new?location=elkhorn')->assertOk()
            ->assertSee('value="'.$rural->id.'" selected', false);
    }

    public function test_business_profile_update_pages_preserve_browsing_scope_and_branch_visibility(): void
    {
        $business = Business::create(['slug' => 'branch-update-pages', 'name' => 'Branch Update Pages', 'category' => 'services', 'status' => 'active']);
        $owner = User::factory()->create();
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $brandon = $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Local Road']);
        $rural = $business->locations()->create(['location_id' => Location::where('slug', 'elkhorn')->sole()->id, 'address' => '2 Rural Road']);
        for ($i = 1; $i <= 13; $i++) {
            BusinessUpdate::create(['business_location_id' => $brandon->id, 'author_id' => $owner->id,
                'title' => 'Local update fixture '.$i, 'body' => 'A published business update.', 'type' => 'announcement',
                'status' => 'approved', 'published_at' => now()]);
        }
        BusinessUpdate::create(['business_location_id' => $rural->id, 'author_id' => $owner->id,
            'title' => 'Rural branch fixture update', 'body' => 'A published business update.', 'type' => 'announcement',
            'status' => 'approved', 'published_at' => now()]);
        $path = '/businesses/'.$business->slug;
        $this->withCookie('canadamb_community', 'winnipeg')->get('https://canadamb.ca'.$path.'?browse=')
            ->assertOk()->assertSee('Rural branch fixture update')->assertSee('Announcement · Elkhorn')
            ->assertSee('browse=&amp;page=2', false);
        $this->get('https://canadamb.ca'.$path.'?browse=&page=2')->assertOk()
            ->assertSee('Local update fixture 1')->assertSee('href="/businesses?location="', false);
        $this->get('https://brandonmb.ca'.$path)->assertOk()->assertSee('Announcement · Brandon')
            ->assertDontSee('Rural branch fixture update');
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
            'description' => 'Repairs bicycles.', 'public_email' => 'repairs@example.test', 'version' => $business->fresh()->profileVersion(),
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

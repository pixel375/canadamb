<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessUpdate;
use App\Models\Location;
use App\Models\User;
use App\Support\BusinessImports\BusinessSources;
use App\Support\ModerationDecision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_import_is_idempotent_and_directory_respects_physical_branch_location(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'canadamb-business-');
        rename($file, $file.'.csv');
        $file .= '.csv';
        $operator = User::factory()->create(['role' => 'network_admin']);
        try {
            file_put_contents($file, "external_key,name,category,location,address,website\nb-1,Brandon Bicycle Repair,services,brandon,123 Test Street,https://example.test\nr-1,Elkhorn Farm Repairs,services,elkhorn,456 Test Road,https://example.test\n");
            $sources = app(BusinessSources::class);
            $source = $sources->register($operator, 'QA licensed input', 'https://example.test', 'Test fixture',
                'https://example.test/reuse', '2026-01-01');
            $sources->approve($operator, $source, 1, 'Reviewed the synthetic QA fixture.');
            foreach ([1, 2] as $attempt) {
                $this->artisan('businesses:import', ['file' => $file, '--actor' => $operator->id, '--source' => 'QA licensed input',
                    '--license' => 'Test fixture', '--url' => 'https://example.test'])
                    ->expectsQuestion('Operator password', 'password')->assertSuccessful();
                $plan = DB::table('business_import_runs')->latest('id')->first();
                $this->assertDatabaseCount('businesses', $attempt === 1 ? 0 : 2);
                $this->artisan('businesses:import', ['--apply' => $plan->id, '--actor' => $operator->id, '--token' => $plan->review_token,
                    '--reason' => 'Reviewed plan against the QA input.'])
                    ->expectsQuestion('Operator password', 'password')->assertSuccessful();
            }
        } finally {
            unlink($file);
        }
        $this->assertDatabaseCount('businesses', 2);
        $this->assertDatabaseCount('business_locations', 2);
        $this->assertDatabaseCount('business_import_runs', 2);
        $this->get('https://canadamb.ca/businesses')->assertOk()->assertSee('Brandon Bicycle Repair')->assertSee('Elkhorn Farm Repairs');
        $this->get('https://brandonmb.ca/businesses')->assertOk()->assertSee('Brandon Bicycle Repair')->assertDontSee('Elkhorn Farm Repairs');
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
        $this->put("https://brandonmb.ca/account/businesses/$business->id", ['description' => 'Repairs bicycles.'])->assertNotFound();
        $this->actingAs($admin)->post("https://canadamb.ca/admin/business-claims/$claimId", [
            'decision' => 'approved', 'notes' => 'Matched the registration and confirmed control of contact email.',
            'version' => ModerationDecision::version('claim', DB::table('business_claims')->where('id', $claimId)->first()),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $claimant->id, 'role' => 'owner']);
        $this->actingAs($claimant)->put("https://brandonmb.ca/account/businesses/$business->id", [
            'description' => 'Repairs bicycles.', 'public_email' => 'repairs@example.test',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->get('https://brandonmb.ca/businesses/test-repair')->assertSee('Repairs bicycles.')->assertDontSee('owner@example.test');

        $this->post("https://brandonmb.ca/account/businesses/$business->id/updates", [
            'business_location_id' => $branch->id, 'type' => 'announcement', 'title' => 'Workshop opens Saturday',
            'body' => 'Bring your bike for a tune-up.',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->get('https://brandonmb.ca/')->assertDontSee('Workshop opens Saturday');
        $update = DB::table('business_updates')->value('id');
        $this->actingAs($admin)->post("https://canadamb.ca/admin/business-updates/$update", [
            'decision' => 'approved', 'notes' => 'Reviewed the update.',
            'version' => ModerationDecision::version('business-update', BusinessUpdate::findOrFail($update)),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->get('https://brandonmb.ca/')->assertSee('Workshop opens Saturday');
        $this->get('https://canadamb.ca/')->assertSee('Workshop opens Saturday');
        $this->assertDatabaseCount('business_updates', 1);
    }
}

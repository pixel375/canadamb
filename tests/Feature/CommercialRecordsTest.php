<?php

namespace Tests\Feature;

use App\Models\AdvertisingCampaign;
use App\Models\Business;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\CommercialRecords;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommercialRecordsTest extends TestCase
{
    use DatabaseMigrations;

    protected $seeder = DatabaseSeeder::class;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app->make('migrator')->path($app->databasePath('proposed-migrations'));

        return $app;
    }

    public function test_private_campaign_and_trial_workflows_do_not_publish_ads(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $business = Business::create(['slug' => 'campaign-test', 'name' => 'Campaign Test', 'category' => 'services', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Test Road']);
        $site = Site::where('code', 'brandon')->sole();
        $path = 'https://canadamb.ca/account/businesses/'.$business->id.'/advertising';
        $data = ['site_id' => $site->id, 'placement' => 'home-banner', 'title' => 'Private campaign headline', 'body' => 'Private campaign content', 'starts_on' => today('America/Winnipeg')->addDay()->toDateString(), 'weeks' => 3];
        config(['network.commercial_planning_records' => false]);
        $this->actingAs($owner)->get($path)->assertNotFound();
        config(['network.commercial_planning_records' => true]);
        $this->actingAs($stranger)->post($path, $data)->assertNotFound();
        $this->actingAs($owner)->get($path)->assertOk()->assertSee('No active trial record');
        $this->post($path, $data)->assertRedirect()->assertSessionHasNoErrors();
        $campaign = AdvertisingCampaign::sole();
        $this->assertSame('pending', $campaign->status);
        $this->assertNull($campaign->estimated_cents);
        $this->get($path)->assertOk()->assertSee($data['title']);
        $this->get('https://canadamb.ca/')->assertDontSee($data['title']);
        $this->actingAs($stranger)->get($path)->assertNotFound();
        $review = 'https://canadamb.ca/admin/advertising/'.$campaign->id;
        $this->post($review, ['decision' => 'approved', 'note' => 'Verified request.'])->assertForbidden();
        $this->actingAs($admin)->post($review, ['decision' => 'approved', 'note' => 'Verified request.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $campaign->fresh()->status);
        $this->get('https://brandonmb.ca/')->assertDontSee($data['title']);
        $this->post($review, ['decision' => 'rejected', 'note' => 'Duplicate review.'])->assertConflict();
        $trial = 'https://canadamb.ca/admin/entitlements';
        $grant = ['business_id' => $business->id, 'days' => 30, 'note' => 'Internal test trial.'];
        $this->post($trial, $grant)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(CommercialRecords::hasTrial($business));
        $this->post($trial, $grant)->assertSessionHasErrors('business_id');
        $id = DB::table('business_entitlements')->value('id');
        $this->post($trial.'/'.$id.'/revoke')->assertRedirect();
        $this->assertFalse(CommercialRecords::hasTrial($business));
        $this->actingAs($owner)->post($path.'/'.$campaign->id.'/withdraw')->assertRedirect();
        $this->assertSame('withdrawn', $campaign->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'advertising.withdrawn', 'actor_id' => $owner->id]);
    }
}

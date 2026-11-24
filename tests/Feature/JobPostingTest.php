<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobPostingTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_business_jobs_are_authorized_moderated_scoped_and_expired(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $admin = User::factory()->create(['role' => 'network_admin']);
        $business = Business::create(['name' => 'Manitoba Co-op', 'slug' => 'manitoba-co-op', 'category' => 'retail', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $brandon = $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Main St']);
        $winnipeg = $business->locations()->create(['location_id' => Location::where('slug', 'winnipeg')->sole()->id, 'address' => '2 Main St']);
        $url = "https://brandonmb.ca/account/businesses/$business->id/jobs";
        $input = [
            'title' => 'Storefront supervisor', 'description' => 'Supervise the store and support the local team.',
            'employment_type' => 'full-time', 'workplace' => 'on-site', 'apply_url' => 'https://example.test/careers',
            'closing_on' => today()->addWeek()->toDateString(), 'pay_unit' => 'hour', 'pay_min' => '25.00', 'pay_max' => '30.00',
        ];

        $this->actingAs($stranger)->post($url, $input + ['business_location_id' => $brandon->id])->assertNotFound();
        $this->actingAs($owner)->post($url, $input + ['business_location_id' => $winnipeg->id])->assertSessionHasErrors('location_id');
        $this->post($url, $input + ['business_location_id' => $brandon->id])->assertSessionHasNoErrors()->assertRedirect();
        $job = JobPosting::sole();
        $this->get($url.'?status=pending&q=Storefront')->assertOk()->assertSee('1 job found')->assertSee($input['title']);
        $this->get($url.'?status=approved')->assertOk()->assertSee('No jobs match these filters.');
        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->actingAs($owner);

        $this->get("https://brandonmb.ca/jobs/$job->id")->assertNotFound();
        $this->actingAs($admin)->get('https://canadamb.ca/admin/jobs')->assertOk()->assertSee($input['apply_url']);
        $this->post("https://canadamb.ca/admin/jobs/$job->id", ['action' => 'approve', 'version' => $job->reviewVersion()])->assertRedirect();
        $this->get('https://brandonmb.ca/jobs?pay_unit=hour&minimum_pay=27')->assertOk()->assertSee($input['title']);
        $this->get('https://brandonmb.ca/jobs?pay_unit=hour&minimum_pay=31')->assertDontSee($input['title']);
        $this->get("https://canadamb.ca/jobs/$job->id")->assertRedirect("https://brandonmb.ca/jobs/$job->id");
        $this->get("https://brandonmb.ca/jobs/$job->id")->assertSee('https://example.test/careers')->assertSee('JobPosting');
        $this->get('https://brandonmb.ca/sitemap.xml')->assertOk()->assertSee("https://brandonmb.ca/jobs/$job->id");

        $this->actingAs($stranger)->post("https://brandonmb.ca/jobs/$job->id/save")->assertRedirect();
        $this->get('https://canadamb.ca/account/saved-jobs')->assertSee($input['title']);
        $this->actingAs($owner)->get($url)->assertOk()->assertSee($input['title']);
        $this->get("$url/$job->id/edit")->assertOk()->assertSee($input['title']);
        $oldVersion = $job->fresh()->reviewVersion();
        $this->put("$url/$job->id", array_replace($input, ['title' => 'Senior storefront supervisor']))->assertSessionHasNoErrors();
        $this->get("https://brandonmb.ca/jobs/$job->id")->assertNotFound();
        $this->actingAs($admin)->post("https://canadamb.ca/admin/jobs/$job->id", ['action' => 'approve', 'version' => $oldVersion])->assertConflict();
        $this->post("https://canadamb.ca/admin/jobs/$job->id", ['action' => 'approve', 'version' => $job->fresh()->reviewVersion()])->assertRedirect();
        $this->travel(8)->days();
        $this->get("https://brandonmb.ca/jobs/$job->id")->assertNotFound();
        $this->artisan('job-postings:expire')->assertSuccessful();
        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'status' => 'expired']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JobPosting;
use App\Models\JobSearch;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

class JobSearchesTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => DatabaseSeeder::class])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_saved_job_searches_are_private_scoped_and_show_only_new_visible_matches(): void
    {
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $business = Business::create(['name' => 'Alert employer', 'slug' => 'alert-employer', 'category' => 'retail', 'status' => 'active']);
        $branch = $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Alert Road']);
        $other = $business->locations()->create(['location_id' => Location::where('slug', 'winnipeg')->sole()->id, 'address' => '2 Alert Road']);
        $base = 'https://brandonmb.ca/account/job-searches';
        $filters = ['name' => 'Retail search', 'q' => 'clerk', 'type' => 'full-time', 'pay_unit' => 'hour', 'minimum_pay' => '25', 'alerts' => '1'];
        $this->actingAs($member)->post($base, $filters)->assertNotFound();
        config(['network.job_searches' => true]);
        $this->post($base, $filters)->assertRedirect()->assertSessionHasNoErrors();
        $search = JobSearch::sole();
        $url = $base.'/'.$search->id;
        $this->post($base, array_replace($filters, ['minimum_pay' => '25.00']))->assertSessionHasErrors('name');
        $this->travel(2)->minutes();
        $make = function ($location, string $title, string $status, int $pay = 3000) {
            $job = JobPosting::create([
                'business_location_id' => $location->id, 'title' => $title, 'description' => 'A retail employment opportunity.',
                'employment_type' => 'full-time', 'workplace' => 'on-site', 'apply_url' => 'https://example.test/apply',
                'closing_on' => today()->addWeek()->toDateString(), 'pay_unit' => 'hour', 'pay_min' => $pay, 'pay_max' => $pay,
            ]);
            $job->forceFill(['status' => $status, 'published_at' => now()])->save();
        };
        $make($branch, 'Matching clerk', 'approved');
        $make($branch, 'Pending clerk', 'pending');
        $make($other, 'Other city clerk', 'approved');
        $make($branch, 'Low pay clerk', 'approved', 2000);
        $this->get($base)->assertOk()->assertSee('1 new match');
        $this->get($url)->assertOk()->assertSee('Matching clerk')->assertDontSee('Pending clerk')->assertDontSee('Other city clerk')->assertDontSee('Low pay clerk');
        $this->get('https://canadamb.ca/account/job-searches/'.$search->id)->assertNotFound();
        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->post($url.'/read')->assertNotFound();
        $this->actingAs($member)->post($url.'/read')->assertRedirect();
        $this->get($url)->assertDontSee('Matching clerk');
        $this->get($url.'?view=all')->assertSee('Matching clerk');
        $this->post($url.'/preferences', ['name' => 'Retail search', 'alerts' => '0'])->assertRedirect();
        $this->get($base)->assertSee('Alerts paused');
        $this->travel(2)->minutes();
        $make($branch, 'While paused clerk', 'approved');
        $this->post($url.'/preferences', ['name' => 'Retail search', 'alerts' => '1'])->assertRedirect();
        $this->get($url)->assertDontSee('While paused clerk');
        $this->delete($url)->assertRedirect();
        $this->assertDatabaseCount('job_searches', 0);
    }
}

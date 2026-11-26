<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JobPostingTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = DatabaseSeeder::class;

    public function test_bulk_job_bookmark_cleanup_preserves_open_and_other_portal_items(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $business = Business::create(['name' => 'Bookmark employer', 'slug' => 'bookmark-employer', 'category' => 'retail', 'status' => 'active']);
        foreach ([['brandon', 'expired'], ['brandon', 'approved'], ['winnipeg', 'expired']] as [$place, $status]) {
            $branch = $business->locations()->create(['location_id' => Location::where('slug', $place)->sole()->id, 'address' => $status.' test road']);
            $job = JobPosting::create(['business_location_id' => $branch->id, 'title' => 'Saved '.$place.' '.$status,
                'description' => 'A bookmarked job fixture.', 'employment_type' => 'full-time', 'workplace' => 'on-site',
                'apply_url' => 'https://example.test/apply', 'closing_on' => today()->addWeek()->toDateString()]);
            $job->forceFill(['status' => $status, 'published_at' => now()])->save();
            foreach ([$member, $other] as $user) {
                DB::table('job_posting_bookmarks')->insert(['user_id' => $user->id, 'job_posting_id' => $job->id]);
            }
        }
        $url = 'https://brandonmb.ca/account/saved-jobs/clear-unavailable';
        $this->actingAs($member)->post($url)->assertSessionHasErrors('confirm_remove');
        $this->post($url, ['confirm_remove' => '1'])->assertRedirect('/account/saved-jobs');
        $removed = JobPosting::where('title', 'Saved brandon expired')->sole();
        $this->assertDatabaseMissing('job_posting_bookmarks', ['user_id' => $member->id, 'job_posting_id' => $removed->id]);
        $this->assertSame(2, DB::table('job_posting_bookmarks')->where('user_id', $member->id)->count());
        $this->assertSame(3, DB::table('job_posting_bookmarks')->where('user_id', $other->id)->count());
    }

    public function test_bulk_withdrawal_respects_employer_roles_portal_and_stale_versions(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $business = Business::create(['name' => 'Batch employer', 'slug' => 'batch-employer', 'category' => 'retail', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $business->members()->attach($editor->id, ['role' => 'editor']);
        $items = collect();
        foreach (['brandon', 'brandon', 'winnipeg'] as $index => $place) {
            $branch = $business->locations()->create(['location_id' => Location::where('slug', $place)->sole()->id, 'address' => $index.' Batch Road']);
            $items->push(JobPosting::create(['business_location_id' => $branch->id, 'title' => 'Batch job '.$index,
                'description' => 'A local employment opportunity.', 'employment_type' => 'full-time', 'workplace' => 'on-site',
                'apply_url' => 'https://example.test/apply', 'closing_on' => today()->addWeek()->toDateString()])->fresh());
        }
        $path = 'https://brandonmb.ca/account/businesses/'.$business->id.'/jobs';
        $url = $path.'/withdraw-selected';
        $versions = $items->mapWithKeys(fn ($job) => [$job->id => $job->reviewVersion()])->all();
        $input = ['jobs' => [$items[0]->id, $items[1]->id], 'versions' => $versions, 'confirm_withdraw' => '1'];
        $this->actingAs($editor)->post($url, $input)->assertNotFound();
        $this->actingAs($owner)->get($path)->assertOk()->assertSee('Withdraw selected postings');
        $this->post($url, array_replace($input, ['jobs' => [$items[0]->id, $items[2]->id]]))->assertNotFound();
        $this->post($url, array_replace($input, ['versions' => array_replace($versions, [$items[1]->id => str_repeat('0', 64)])]))->assertSessionHasErrors('jobs');
        $this->assertSame('pending', $items[0]->fresh()->status);
        $this->post($url, $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $items[0]->fresh()->status);
        $this->assertSame('withdrawn', $items[1]->fresh()->status);
        $this->assertSame('pending', $items[2]->fresh()->status);
        $admin = User::factory()->create(['role' => 'network_admin']);
        $queue = 'https://canadamb.ca/admin/jobs?status=withdrawn&location='.$items[0]->branch->location_id;
        $this->actingAs($admin)->get($queue)->assertOk()->assertSee('Batch job 0')->assertSee('Historical record')->assertDontSee('action="/admin/jobs/'.$items[0]->id.'"', false);
        $this->get('https://canadamb.ca/admin/jobs?status=withdrawn&location='.$items[2]->branch->location_id)->assertOk()->assertDontSee('Batch job 0');
    }

    public function test_selected_deadlines_extend_atomically_and_return_to_independent_review(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $business = Business::create(['name' => 'Deadline employer', 'slug' => 'deadline-employer', 'category' => 'retail', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $branch = $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Deadline Road']);
        $items = collect();
        foreach (['approved', 'expired'] as $status) {
            $job = JobPosting::create(['business_location_id' => $branch->id, 'title' => 'Deadline '.$status,
                'description' => 'A local employment opportunity.', 'employment_type' => 'full-time', 'workplace' => 'on-site',
                'apply_url' => 'https://example.test/apply', 'closing_on' => today('America/Winnipeg')->addWeek()->toDateString()]);
            $job->forceFill(['status' => $status, 'published_at' => now()->subDay()])->save();
            $items->push($job->fresh());
        }
        $base = 'https://brandonmb.ca/account/businesses/'.$business->id.'/jobs';
        $url = $base.'/extend-selected';
        $versions = $items->mapWithKeys(fn ($job) => [$job->id => $job->reviewVersion()])->all();
        $input = ['jobs' => $items->pluck('id')->all(), 'versions' => $versions, 'confirm_extend' => '1', 'new_closing_on' => today('America/Winnipeg')->addMonth()->toDateString()];
        $this->actingAs($other)->post($url, $input)->assertNotFound();
        $this->actingAs($owner)->get($base)->assertOk()->assertSee('Extend selected closing dates');
        $this->post($url, array_replace($input, ['new_closing_on' => today('America/Winnipeg')->toDateString()]))->assertSessionHasErrors('new_closing_on');
        $this->post($url, array_replace($input, ['versions' => array_replace($versions, [$items[1]->id => str_repeat('0', 64)])]))->assertSessionHasErrors('jobs');
        $this->assertSame('approved', $items[0]->fresh()->status);
        $this->post($url, $input)->assertRedirect()->assertSessionHasNoErrors();
        foreach ($items as $job) {
            $this->assertSame('pending', $job->fresh()->status);
            $this->assertSame($input['new_closing_on'], $job->fresh()->closing_on->toDateString());
        }
        $this->assertNotNull($items[0]->fresh()->published_at);
        $this->assertNull($items[1]->fresh()->published_at);
        $this->get('https://brandonmb.ca/jobs/'.$items[0]->id)->assertNotFound();
    }

    public function test_selected_job_review_rejects_conflicts_stale_versions_and_ineligible_postings(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $business = Business::create(['name' => 'Review employer', 'slug' => 'review-employer', 'category' => 'retail', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $branch = $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '1 Review Road']);
        $items = collect();
        foreach ([$owner, $owner, $reviewer] as $index => $author) {
            $items->push(JobPosting::create(['business_location_id' => $branch->id, 'author_id' => $author->id,
                'title' => 'Job review fixture '.$index, 'description' => 'A local job posting.',
                'employment_type' => 'full-time', 'workplace' => 'on-site', 'apply_url' => 'https://example.test/careers',
                'closing_on' => today('America/Winnipeg')->addWeek()->toDateString()])->fresh());
        }
        $versions = $items->mapWithKeys(fn ($job) => [$job->id => $job->reviewVersion()])->all();
        $url = 'https://canadamb.ca/admin/jobs/selected';
        $input = ['action' => 'approve', 'jobs' => [$items[0]->id, $items[1]->id], 'versions' => $versions, 'confirm_review' => '1'];
        $this->actingAs($owner)->post($url, $input)->assertForbidden();
        $this->actingAs($reviewer)->get('https://canadamb.ca/admin/jobs')->assertOk()->assertSee('Review selected job postings');
        $this->post($url, array_replace($input, ['jobs' => [$items[0]->id, $items[2]->id]]))->assertForbidden();
        $this->post($url, array_replace($input, ['versions' => array_replace($versions, [$items[1]->id => str_repeat('0', 64)])]))->assertSessionHasErrors('jobs');
        $business->members()->attach($reviewer->id, ['role' => 'editor']);
        $this->post($url, $input)->assertForbidden();
        $business->members()->detach($reviewer->id);
        $items[1]->update(['closing_on' => today('America/Winnipeg')->subDay()->toDateString()]);
        $versions[$items[1]->id] = $items[1]->fresh()->reviewVersion();
        $this->post($url, array_replace($input, ['versions' => $versions]))->assertSessionHasErrors('jobs');
        $this->assertSame('pending', $items[0]->fresh()->status);
        $items[1]->update(['closing_on' => today('America/Winnipeg')->addWeek()->toDateString()]);
        $versions[$items[1]->id] = $items[1]->fresh()->reviewVersion();
        $this->post($url, array_replace($input, ['versions' => $versions]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $items[0]->fresh()->status);
        $this->assertSame('approved', $items[1]->fresh()->status);
        $this->assertSame('pending', $items[2]->fresh()->status);
        $this->assertNotNull($items[0]->fresh()->published_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'jobs.approve', 'actor_id' => $reviewer->id]);
    }

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
        $this->post($url, $input + ['business_location_id' => $brandon->id])->assertSessionHasErrors('title');
        $this->assertDatabaseCount('job_postings', 1);
        $this->get($url.'?status=pending&q=Storefront')->assertOk()->assertSee('1 job found')->assertSee($input['title']);
        $this->get($url.'?status=approved')->assertOk()->assertSee('No jobs match these filters.');
        $this->get($url.'?type=full-time&workplace=on-site&closing=month&reference='.$job->id)->assertOk()->assertSee('1 job found')->assertSee('Reference #'.$job->id);
        $this->get($url.'?type=part-time')->assertOk()->assertSee('No jobs match these filters.');
        $this->get($url.'?closing=past')->assertOk()->assertSee('No jobs match these filters.');
        $this->get($url.'?q=0')->assertOk()->assertSee('No jobs match these filters.');
        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->actingAs($owner);

        $this->get("https://brandonmb.ca/jobs/$job->id")->assertNotFound();
        $this->get('https://brandonmb.ca/businesses/manitoba-co-op')->assertOk()->assertDontSee($input['title']);
        $this->actingAs($admin)->get('https://canadamb.ca/admin/jobs')->assertOk()->assertSee($input['apply_url']);
        $this->post("https://canadamb.ca/admin/jobs/$job->id", ['action' => 'approve', 'version' => $job->reviewVersion()])->assertRedirect();
        $this->get('https://brandonmb.ca/jobs?pay_unit=hour&minimum_pay=27')->assertOk()->assertSee($input['title']);
        $this->get('https://brandonmb.ca/jobs?pay_unit=hour&minimum_pay=31')->assertDontSee($input['title']);
        $this->get('https://canadamb.ca/jobs?location=Brandon')->assertOk()->assertSee($input['title']);
        $this->get('https://canadamb.ca/jobs?location=%25')->assertOk()->assertDontSee($input['title']);
        $this->get('https://canadamb.ca/jobs?q=0')->assertOk()->assertDontSee($input['title']);
        $this->get("https://canadamb.ca/jobs/$job->id")->assertRedirect("https://brandonmb.ca/jobs/$job->id");
        $this->get("https://brandonmb.ca/jobs/$job->id")->assertSee('https://example.test/careers')->assertSee('JobPosting')->assertSee('Job overview')->assertSee('About the employer');
        $this->get('https://brandonmb.ca/businesses/manitoba-co-op')->assertOk()->assertSee('Current job opportunities')->assertSee($input['title']);
        $this->get('https://brandonmb.ca/sitemap.xml')->assertOk()->assertSee("https://brandonmb.ca/jobs/$job->id");

        $this->actingAs($stranger)->post("https://brandonmb.ca/jobs/$job->id/save", ['return_to' => 'job'])->assertRedirect('/jobs/'.$job->id);
        $this->get("https://brandonmb.ca/jobs/$job->id")->assertOk()->assertSee('Remove saved job');
        $this->delete("https://brandonmb.ca/jobs/$job->id/save", ['return_to' => 'job'])->assertRedirect('/jobs/'.$job->id);
        $this->post("https://brandonmb.ca/jobs/$job->id/save")->assertRedirect('/account/saved-jobs');
        $this->get('https://canadamb.ca/account/saved-jobs')->assertSee($input['title']);
        $this->get('https://canadamb.ca/account/saved-jobs?q=STOREFRONT&sort=closing')->assertOk()->assertSee($input['title']);
        $this->get('https://canadamb.ca/account/saved-jobs?q=zzmissing')->assertOk()->assertDontSee($input['title']);
        $this->get('https://canadamb.ca/account/saved-jobs?type='.$input['employment_type'].'&workplace='.$input['workplace'].'&location=Brandon')->assertOk()->assertSee($input['title']);
        $this->get('https://canadamb.ca/account/saved-jobs?location=Winnipeg')->assertOk()->assertDontSee($input['title']);
        $this->actingAs($owner)->get($url)->assertOk()->assertSee($input['title']);
        $this->get("$url/$job->id/edit")->assertOk()->assertSee($input['title']);
        $oldVersion = $job->fresh()->reviewVersion();
        $this->put("$url/$job->id", array_replace($input, ['title' => 'Senior storefront supervisor', 'version' => $oldVersion]))->assertSessionHasNoErrors();
        $this->get("https://brandonmb.ca/jobs/$job->id")->assertNotFound();
        $this->put("$url/$job->id", $input + ['version' => $oldVersion])->assertConflict();
        $this->post("$url/$job->id/withdraw", ['version' => $oldVersion])->assertConflict();
        $this->actingAs($admin)->post("https://canadamb.ca/admin/jobs/$job->id", ['action' => 'approve', 'version' => $oldVersion])->assertConflict();
        $this->post("https://canadamb.ca/admin/jobs/$job->id", ['action' => 'approve', 'version' => $job->fresh()->reviewVersion()])->assertRedirect();
        $this->travel(8)->days();
        $this->get("https://brandonmb.ca/jobs/$job->id")->assertNotFound();
        $this->artisan('job-postings:expire')->assertSuccessful();
        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'status' => 'expired']);
        $this->actingAs($stranger)->get('https://canadamb.ca/account/saved-jobs')->assertSee('Unavailable saved jobs (1)')->assertDontSee('Senior storefront supervisor');
        $this->delete("https://brandonmb.ca/jobs/$job->id/save")->assertRedirect('/account/saved-jobs');
        $this->actingAs($stranger)->get("$url/$job->id/duplicate")->assertNotFound();
        $this->actingAs($owner)->get("$url/$job->id/duplicate")->assertOk()->assertSee('Reuse job posting')->assertSee('Senior storefront supervisor');
        $this->post($url, array_replace($input, ['business_location_id' => $brandon->id, 'closing_on' => today()->addWeek()->toDateString()]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('job_postings', 2);
        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'status' => 'expired']);
        $published = $job->fresh()->published_at;
        $this->put("$url/$job->id", array_replace($input, ['title' => 'Senior storefront supervisor', 'closing_on' => today()->addWeek()->toDateString(), 'version' => $job->fresh()->reviewVersion()]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($job->fresh()->published_at);
        $this->actingAs($admin)->post("https://canadamb.ca/admin/jobs/$job->id", ['action' => 'approve', 'version' => $job->fresh()->reviewVersion()])->assertRedirect();
        $this->assertTrue($job->fresh()->published_at->greaterThan($published));
    }
}

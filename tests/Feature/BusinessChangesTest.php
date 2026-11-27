<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessUpdate;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessChangesTest extends TestCase
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

    public function test_branch_requests_are_reviewed_and_contact_approval_is_invalidated_on_change(): void
    {
        $owner = User::factory()->create(['role' => 'network_admin']);
        $editor = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $business = Business::create(['slug' => 'change-test', 'name' => 'Change Test', 'category' => 'services', 'status' => 'active', 'public_email' => 'original@example.test']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $business->members()->attach($editor->id, ['role' => 'editor']);
        $brandon = Location::where('slug', 'brandon')->sole()->id;
        $other = Location::where('slug', 'elkhorn')->sole()->id;
        $branch = $business->locations()->create(['location_id' => $brandon, 'address' => '1 Original Road']);
        $url = 'https://brandonmb.ca/account/businesses/'.$business->id.'/changes';
        $queue = 'https://canadamb.ca/admin/business-changes';
        $this->actingAs($owner)->get($url)->assertNotFound();
        config(['network.business_changes' => true]);
        $this->actingAs($editor)->get($url)->assertNotFound();
        $this->actingAs($owner)->get($url)->assertOk()->assertSee('1 Original Road');
        $this->post($url, ['kind' => 'branch-add', 'location_id' => $other, 'address' => '2 Other Road'])->assertSessionHasErrors('location_id');
        $this->post($url, ['kind' => 'branch-address', 'branch_id' => $branch->id, 'address' => '3 Corrected Road'])->assertRedirect()->assertSessionHasNoErrors();
        $change = DB::table('business_change_requests')->value('id');
        $this->assertSame('1 Original Road', $branch->fresh()->address);
        $decision = ['decision' => 'approved', 'note' => 'Independently confirmed the physical address.'];
        $this->post($queue.'/'.$change, $decision)->assertForbidden();
        $this->actingAs($reviewer)->get($queue)->assertSee('3 Corrected Road');
        $this->post($queue.'/'.$change, $decision)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('3 Corrected Road', $branch->fresh()->address);
        $this->assertDatabaseCount('business_locations', 1);
        $this->actingAs($owner)->post($url, ['kind' => 'branch-add', 'address' => '4 New Road'])->assertRedirect()->assertSessionHasNoErrors();
        $new = DB::table('business_change_requests')->max('id');
        $this->assertDatabaseCount('business_locations', 1);
        $this->actingAs($reviewer)->post($queue.'/'.$new, $decision)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('business_locations', 2);
        $this->actingAs($owner)->post($url, ['kind' => 'contact'])->assertRedirect()->assertSessionHasNoErrors();
        $contact = DB::table('business_change_requests')->where('kind', 'contact')->value('id');
        $this->actingAs($reviewer)->post($queue.'/'.$contact, ['decision' => 'approved', 'note' => 'Confirmed control of the contact email.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('business_change_requests', ['id' => $contact, 'status' => 'approved']);
        $this->actingAs($editor)->put('https://brandonmb.ca/account/businesses/'.$business->id, ['public_email' => 'changed@example.test', 'version' => $business->fresh()->profileVersion()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('business_change_requests', ['id' => $contact, 'status' => 'stale']);
        $this->actingAs($owner)->get($url.'?kind=contact&status=stale&reference='.$contact)->assertOk()->assertSee('Reference #'.$contact)->assertSee('1 matching requests');
        $this->get($url.'?status=pending&kind=contact')->assertOk()->assertSee('No requests match these filters.');
    }

    public function test_relocation_and_closure_preserve_history_and_remove_public_branch_content(): void
    {
        config(['network.business_changes' => true]);
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $business = Business::create(['slug' => 'moving-shop', 'name' => 'Moving shop', 'category' => 'retail', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $business->members()->attach($manager->id, ['role' => 'manager']);
        $brandon = Location::where('slug', 'brandon')->sole()->id;
        $elkhorn = Location::where('slug', 'elkhorn')->sole()->id;
        $branch = $business->locations()->create(['location_id' => $brandon, 'address' => '5 Old Road']);
        $job = JobPosting::create([
            'business_location_id' => $branch->id, 'author_id' => $owner->id, 'title' => 'Old branch clerk',
            'description' => 'An employment posting at the original branch.', 'employment_type' => 'full-time', 'workplace' => 'on-site',
            'apply_url' => 'https://example.test/jobs', 'closing_on' => today()->addWeek()->toDateString(),
        ]);
        $job->forceFill(['status' => 'approved', 'published_at' => now()])->save();
        $update = BusinessUpdate::create(['business_location_id' => $branch->id, 'author_id' => $owner->id, 'type' => 'announcement',
            'title' => 'Old branch announcement', 'body' => 'The original shop is open.', 'status' => 'approved', 'published_at' => now()]);
        $main = 'https://canadamb.ca/account/businesses/'.$business->id.'/changes';
        $city = 'https://brandonmb.ca/account/businesses/'.$business->id.'/changes';
        $queue = 'https://canadamb.ca/admin/business-changes/';
        $relocation = ['kind' => 'branch-relocate', 'branch_id' => $branch->id, 'location_id' => $elkhorn, 'address' => '6 New Road'];
        $this->actingAs($manager)->post($main, $relocation)->assertNotFound();
        $this->actingAs($owner)->post($city, $relocation)->assertNotFound();
        $this->post($main, $relocation)->assertRedirect()->assertSessionHasNoErrors();
        $id = DB::table('business_change_requests')->max('id');
        $this->assertSame('5 Old Road', $branch->fresh()->address);
        $this->get('https://brandonmb.ca/jobs/'.$job->id)->assertOk();
        $this->actingAs($reviewer)->post($queue.$id, ['decision' => 'approved', 'note' => 'Independently verified the move.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('closed', $branch->fresh()->location_status);
        $this->assertSame($brandon, $branch->fresh()->location_id);
        $this->assertSame('withdrawn', $job->fresh()->status);
        $this->assertSame('withdrawn', $update->fresh()->status);
        $new = $business->openLocations()->sole();
        $this->assertSame($elkhorn, $new->location_id);
        $this->get('https://brandonmb.ca/businesses/moving-shop')->assertNotFound();
        $this->get('https://brandonmb.ca/jobs/'.$job->id)->assertNotFound();
        $this->get('https://canadamb.ca/business-updates?q=Old')->assertDontSee('Old branch announcement');
        $this->get('https://canadamb.ca/businesses/moving-shop')->assertOk()->assertSee('6 New Road')->assertDontSee('5 Old Road');
        $this->actingAs($owner)->post($main, ['kind' => 'branch-close', 'branch_id' => $new->id, 'reason' => 'The new shop has permanently closed.'])->assertRedirect();
        $close = DB::table('business_change_requests')->max('id');
        $this->actingAs($reviewer)->post($queue.$close, ['decision' => 'approved', 'note' => 'Independently confirmed closure.'])->assertRedirect();
        $this->get('https://canadamb.ca/businesses/moving-shop')->assertNotFound();
        $this->assertDatabaseCount('business_locations', 2);
        $this->assertDatabaseCount('job_postings', 1);
        $this->assertDatabaseCount('business_updates', 1);
        $this->actingAs($owner)->get($main)->assertOk()->assertSee('Closed')->assertSee('Request reopening');
        $reopen = ['kind' => 'branch-reopen', 'branch_id' => $branch->id, 'reason' => 'The original shop is open again.'];
        $this->actingAs($manager)->post($city, $reopen)->assertNotFound();
        $this->actingAs($owner)->post($city, $reopen)->assertRedirect()->assertSessionHasNoErrors();
        $reopenId = DB::table('business_change_requests')->max('id');
        $this->get('https://brandonmb.ca/businesses/moving-shop')->assertNotFound();
        $this->actingAs($reviewer)->get($queue.'?kind=branch-reopen')->assertSee('The original shop is open again.');
        $this->post($queue.$reopenId, ['decision' => 'approved', 'note' => 'Independently verified reopening.'])->assertRedirect();
        $this->assertTrue($branch->fresh()->isOpen());
        $this->assertSame('withdrawn', $job->fresh()->status);
        $this->assertSame('withdrawn', $update->fresh()->status);
        $this->get('https://brandonmb.ca/businesses/moving-shop')->assertOk()->assertSee('5 Old Road')->assertDontSee('6 New Road');
        $this->get('https://brandonmb.ca/jobs/'.$job->id)->assertNotFound();
        $this->get($queue.'?status=approved&reference='.$reopenId)->assertSee('The original shop is open again.')->assertDontSee('Approve verified request');
    }
}

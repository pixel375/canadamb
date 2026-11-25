<?php

namespace Tests\Feature;

use App\Models\Business;
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
        $this->actingAs($editor)->put('https://brandonmb.ca/account/businesses/'.$business->id, ['public_email' => 'changed@example.test'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('business_change_requests', ['id' => $contact, 'status' => 'stale']);
    }
}

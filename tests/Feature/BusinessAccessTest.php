<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessAccessTest extends TestCase
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

    public function test_expired_and_cancelled_requests_do_not_grant_access(): void
    {
        config(['network.business_access_requests' => true]);
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $business = Business::create(['slug' => 'expiry-test', 'name' => 'Expiry Test', 'category' => 'services', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $base = 'https://canadamb.ca/account/business-access';
        $create = 'https://canadamb.ca/account/businesses/'.$business->id.'/access-requests';
        $data = ['email' => $recipient->email, 'kind' => 'editor', 'current_password' => 'password'];
        $this->actingAs($owner)->post($create, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($create, $data)->assertSessionHasErrors('email');
        $id = DB::table('business_access_requests')->value('id');
        DB::table('business_access_requests')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
        $response = ['action' => 'accept', 'current_password' => 'password'];
        $this->actingAs($recipient)->get($base)->assertSee('Expired');
        $this->get($base.'?direction=received&status=expired&reference='.$id)->assertOk()->assertSee('Expiry Test');
        $this->get($base.'?direction=sent&status=expired')->assertOk()->assertDontSee('Expiry Test');
        $this->get($base.'?status=open')->assertOk()->assertDontSee('Expiry Test');
        $this->post($base.'/'.$id.'/respond', $response)->assertConflict();
        $this->actingAs($owner)->post($create, $data)->assertRedirect()->assertSessionHasNoErrors();
        $second = DB::table('business_access_requests')->max('id');
        $this->post($base.'/'.$second.'/cancel')->assertRedirect();
        $this->actingAs($recipient)->post($base.'/'.$second.'/respond', $response)->assertConflict();
        $this->assertDatabaseCount('business_memberships', 1);
    }

    public function test_invitation_and_transfer_require_recipient_and_independent_review(): void
    {
        $owner = User::factory()->create(['role' => 'network_admin']);
        $recipient = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $stranger = User::factory()->create();
        $business = Business::create(['slug' => 'access-test', 'name' => 'Access Test', 'category' => 'services', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $base = 'https://canadamb.ca/account/business-access';
        $create = 'https://canadamb.ca/account/businesses/'.$business->id.'/access-requests';
        $data = ['email' => $recipient->email, 'kind' => 'manager', 'current_password' => 'password'];
        $this->actingAs($owner)->post($create, $data)->assertNotFound();
        config(['network.business_access_requests' => true]);
        $this->actingAs($stranger)->post($create, $data)->assertNotFound();
        $this->actingAs($owner)->post($create, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('business_memberships', 1);
        $id = DB::table('business_access_requests')->value('id');
        $this->actingAs($stranger)->get($base)->assertDontSee('Access Test');
        $this->post($base.'/'.$id.'/respond', ['action' => 'accept', 'current_password' => 'password'])->assertNotFound();
        $this->actingAs($recipient)->get($base)->assertSee('Access Test');
        $this->post($base.'/'.$id.'/respond', ['action' => 'accept', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->post($base.'/'.$id.'/respond', ['action' => 'accept', 'current_password' => 'password'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $recipient->id, 'role' => 'manager']);
        $this->actingAs($owner)->post($create, array_replace($data, ['kind' => 'transfer']))->assertRedirect()->assertSessionHasNoErrors();
        $transfer = DB::table('business_access_requests')->where('kind', 'transfer')->value('id');
        $review = 'https://canadamb.ca/admin/business-transfers/'.$transfer;
        $decision = ['decision' => 'approve', 'note' => 'Independent verification completed.', 'current_password' => 'password'];
        $this->actingAs($reviewer)->post($review, $decision)->assertConflict();
        $this->actingAs($recipient)->post($base.'/'.$transfer.'/respond', ['action' => 'accept', 'current_password' => 'password'])->assertRedirect();
        $this->actingAs($owner)->post($review, $decision)->assertForbidden();
        $this->actingAs($reviewer)->get('https://canadamb.ca/admin/business-transfers')->assertSee('Access Test');
        $this->post($review, $decision)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('business_memberships', ['business_id' => $business->id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $recipient->id, 'role' => 'owner']);
        $this->post($review, $decision)->assertConflict();
    }
}

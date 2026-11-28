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

class BusinessDisputesTest extends TestCase
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

    public function test_dispute_intake_is_private_scoped_and_does_not_transfer_ownership(): void
    {
        $owner = User::factory()->create(['role' => 'network_admin']);
        $claimant = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'network_admin']);
        $business = Business::create(['slug' => 'dispute-test', 'name' => 'Dispute Test', 'category' => 'services', 'status' => 'active']);
        $business->members()->attach($owner->id, ['role' => 'owner']);
        $business->locations()->create(['location_id' => Location::where('slug', 'brandon')->sole()->id, 'address' => '10 Dispute Road']);
        $url = 'https://brandonmb.ca/businesses/dispute-test/dispute';
        $history = 'https://brandonmb.ca/account/business-disputes';
        $queue = 'https://canadamb.ca/admin/business-disputes';
        $data = ['contact_email' => 'claimant@example.test', 'explanation' => 'I can supply independent evidence of a contested ownership claim.'];
        $this->actingAs($claimant)->get($url)->assertNotFound();
        config(['network.business_disputes' => true]);
        $this->get($url)->assertOk();
        $this->withCookie('canadamb_community', 'brandon')->get('https://canadamb.ca/businesses/dispute-test/dispute?browse=')
            ->assertOk()->assertSee('href="/businesses/dispute-test?browse="', false)
            ->assertSee('action="/businesses/dispute-test/dispute?browse="', false);
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasErrors('explanation');
        $id = DB::table('business_disputes')->value('id');
        $this->get($history)->assertSee($data['explanation']);
        $this->get($queue)->assertForbidden();
        $this->actingAs($owner)->get($history)->assertDontSee($data['explanation']);
        $this->get($queue)->assertDontSee($data['contact_email']);
        $decision = ['decision' => 'escalated', 'note' => 'Further verification is required.', 'expected_status' => 'pending'];
        $this->post($queue.'/'.$id, [])->assertForbidden();
        $this->post($queue.'/'.$id, $decision)->assertForbidden();
        $this->actingAs($reviewer)->get($queue)->assertSee($data['contact_email']);
        $this->post($queue.'/'.$id, ['decision' => 'escalated', 'note' => $decision['note']])->assertSessionHasErrors('expected_status');
        $this->post($queue.'/'.$id, $decision)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('business_disputes', ['id' => $id, 'status' => 'escalated']);
        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $this->assertDatabaseCount('business_memberships', 1);
        $this->actingAs($claimant)->get($history)->assertSee('Escalated')->assertDontSee($decision['note']);
        $this->get($history.'?status=escalated&reference='.$id)->assertOk()->assertSee($data['explanation'])->assertDontSee($decision['note']);
        $this->get($history.'?status=pending')->assertOk()->assertDontSee($data['explanation']);
        $this->post($history.'/'.$id.'/withdraw', ['expected_status' => 'pending'])->assertSessionHasErrors('expected_status');
        $this->post($history.'/'.$id.'/withdraw')->assertSessionHasErrors('expected_status');
        $this->post($url, $data)->assertSessionHasErrors('explanation');
        $this->actingAs($reviewer)->get($queue.'?status=escalated')->assertSee($data['contact_email']);
        $this->post($queue.'/'.$id, ['decision' => 'closed', 'note' => 'Independent verification was completed.', 'expected_status' => 'pending'])->assertSessionHasErrors('expected_status');
        $this->post($queue.'/'.$id, ['decision' => 'closed', 'note' => 'Independent verification was completed.', 'expected_status' => 'escalated'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($claimant)->get($history)->assertSee('Closed');
        $this->post($history.'/'.$id.'/withdraw')->assertConflict();
    }
}

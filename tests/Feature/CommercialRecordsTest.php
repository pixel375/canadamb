<?php

namespace Tests\Feature;

use App\Models\AdvertisingCampaign;
use App\Models\Business;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Support\AdvertisingSchedule;
use App\Support\CommercialRecords;
use App\Support\TestCheckoutAttempts;
use App\Support\TestPaymentLedger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommercialRecordsTest extends TestCase
{
    use DatabaseMigrations;

    protected $seeder = DatabaseSeeder::class;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            // Drop only the reviewed proposal, then reset the disposable test
            // schema without reversing unrelated historical migrations.
            $this->artisan('migrate:rollback', ['--path' => 'database/proposed-migrations'])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
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
        $this->post($path, $data)->assertSessionHasErrors('title');
        $campaign = AdvertisingCampaign::sole();
        $this->assertSame('pending', $campaign->status);
        $this->assertNull($campaign->estimated_cents);
        $export = 'https://canadamb.ca/account/data-export';
        $this->get($export)->assertOk()->assertSee('Advertising requests (1)');
        $input = ['current_password' => 'password', 'section' => 'advertising-requests'];
        $this->post($export, $input)->assertOk()->assertJsonCount(1, 'records')->assertJsonPath('records.0.title', $data['title'])
            ->assertJsonMissingPath('records.0.reviewer_id')->assertJsonMissingPath('records.0.review_note')->assertJsonMissingPath('records.0.rate_revision_id');
        $this->actingAs($stranger)->post($export, $input)->assertOk()->assertJsonCount(0, 'records');
        $this->actingAs($owner);
        $this->get($path)->assertOk()->assertSee($data['title']);
        $this->get($path.'?placement=home-banner&site_id='.$site->id)->assertOk()->assertSee($data['title']);
        $this->get($path.'?placement=marketplace-banner')->assertOk()->assertDontSee($data['title']);
        $this->get('https://canadamb.ca/')->assertDontSee($data['title']);
        $this->actingAs($stranger)->get($path)->assertNotFound();
        $review = 'https://canadamb.ca/admin/advertising/'.$campaign->id;
        $this->post($review, ['decision' => 'approved', 'note' => 'Verified request.'])->assertForbidden();
        $this->actingAs($admin)->get('https://canadamb.ca/admin/advertising?placement=home-banner')->assertOk()->assertSee($campaign->reviewVersion());
        $this->post($review, ['decision' => 'approved', 'note' => 'Verified request.', 'version' => str_repeat('0', 64)])->assertConflict();
        $this->post($review, ['decision' => 'approved', 'note' => 'Verified request.', 'version' => $campaign->reviewVersion()])->assertRedirect()->assertSessionHasNoErrors();
        $campaign->refresh();
        // Model a reviewed stored price for this isolated test. The adapter
        // must not accept caller-supplied payment amounts.
        $campaign->forceFill(['estimated_cents' => 3000])->save();
        config(['network.commercial_test_checkout' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => function ($request) {
            return Http::response([
                'id' => 'cs_test_example', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_example',
                'livemode' => false, 'client_reference_id' => $request['client_reference_id'],
                'amount_total' => 3000, 'currency' => 'cad',
            ]);
        }]);
        $checkout = app(TestCheckoutAttempts::class);
        $first = $checkout->open($owner, $business->id, $campaign->id, 'sk_test_fixture_only');
        $second = $checkout->open($owner, $business->id, $campaign->id, 'sk_test_fixture_only');
        $reference = $first['reference'];
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('commercial_payment_attempts', 1);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'canadamb-test-'.$reference));
        $this->assertDatabaseHas('commercial_payment_attempts', ['reference' => $reference, 'amount_cents' => 3000, 'provider_session_id' => 'cs_test_example']);
        $event = ['id' => 'evt_test_paid', 'livemode' => false, 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'object' => 'checkout.session', 'id' => 'cs_test_example', 'livemode' => false, 'mode' => 'payment',
            'currency' => 'cad', 'amount_total' => 3000, 'client_reference_id' => $reference, 'payment_status' => 'paid',
        ]]];
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $secret = 'whsec_test_fixture_only';
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret);
        $ledger = app(TestPaymentLedger::class);
        $this->assertSame('paid', $ledger->reconcile($raw, $signature, $secret, $timestamp));
        $this->assertSame('duplicate', $ledger->reconcile($raw, $signature, $secret, $timestamp));
        $this->assertDatabaseCount('commercial_payment_events', 1);
        $ledgerUrl = 'https://canadamb.ca/admin/commercial/test-ledger';
        $this->get($ledgerUrl.'?status=paid&campaign='.$campaign->id)->assertOk()->assertSee('CAD 30.00')->assertSee('Paid')->assertDontSee($reference)->assertDontSee('cs_test_example');
        $this->actingAs($owner)->get($ledgerUrl)->assertForbidden();
        $this->actingAs($admin);
        $this->assertDatabaseHas('commercial_payment_attempts', ['reference' => $reference, 'status' => 'paid']);
        $this->assertDatabaseCount('business_entitlements', 0);
        try {
            $checkout->open($owner, $business->id, $campaign->id, 'sk_test_fixture_only');
            $this->fail('A settled attempt must not reopen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('checkout', $exception->errors());
        }
        Http::assertSentCount(2);
        $this->assertSame('approved', $campaign->status);
        $schedule = app(AdvertisingSchedule::class);
        $start = $campaign->starts_on->toDateString();
        $this->assertSame('scheduled', $campaign->planningPhase());
        $this->assertSame('in-window', $campaign->planningPhase($start));
        $this->assertSame('expired', $campaign->planningPhase($campaign->endsOn()->toDateString()));
        $this->assertCount(0, $schedule->candidates($site, 'home-banner'));
        $this->assertSame([$campaign->id], $schedule->candidates($site, 'home-banner', $start)->pluck('id')->all());
        $this->assertCount(0, $schedule->candidates($site, 'home-banner', $campaign->endsOn()->toDateString()));
        $this->assertCount(0, $schedule->candidates($site, 'marketplace-banner', $start));
        $other = $campaign->replicate();
        $other->forceFill(['title' => 'Second competing request', 'reviewed_at' => now()->addSecond()])->save();
        $this->assertCount(2, $schedule->eligible($site, 'home-banner', $start));
        $this->assertSame([$campaign->id], $schedule->candidates($site, 'home-banner', $start)->pluck('id')->all());
        $this->get('https://canadamb.ca/admin/advertising/schedule?site_id='.$site->id.'&date='.$start)
            ->assertOk()->assertSee('eligible requests compete')->assertSee($data['title'])->assertDontSee('Second competing request');
        $other->forceFill(['status' => 'withdrawn'])->save();
        $this->get('https://brandonmb.ca/')->assertDontSee($data['title']);
        $this->post($review, ['decision' => 'rejected', 'note' => 'Duplicate review.'])->assertConflict();
        $trial = 'https://canadamb.ca/admin/entitlements';
        $grant = ['business_id' => $business->id, 'days' => 30, 'note' => 'Internal test trial.'];
        $this->post($trial, $grant)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(CommercialRecords::hasTrial($business));
        $this->post($trial, $grant)->assertSessionHasErrors('business_id');
        $id = DB::table('business_entitlements')->value('id');
        $this->actingAs($owner)->get($path.'/export')->assertNotFound();
        config(['network.commercial_trial_export' => true]);
        $this->get($path.'/export')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('planning_only', true)->assertJsonPath('campaigns.0.headline', $data['title']);
        $this->actingAs($stranger)->get($path.'/export')->assertNotFound();
        $this->actingAs($admin);
        $this->post($trial.'/'.$id.'/revoke')->assertRedirect();
        $this->assertFalse(CommercialRecords::hasTrial($business));
        $this->actingAs($owner)->get($path.'/export')->assertNotFound();
        $this->actingAs($owner)->post($path.'/'.$campaign->id.'/withdraw')->assertRedirect();
        $this->assertSame('withdrawn', $campaign->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'advertising.withdrawn', 'actor_id' => $owner->id]);
    }
}

<?php

namespace App\Support;

use App\Models\AdvertisingCampaign;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

final class TestCheckoutAttempts
{
    /**
     * Internal sandbox orchestration only. No controller or scheduler calls it.
     * A single immutable attempt is allowed per campaign. Retries reuse its
     * reference, with a conservative lifetime below provider idempotency TTL.
     */
    public function open(User $actor, int $businessId, int $campaignId, string $secret): array
    {
        abort_unless(self::enabled(), 404);
        abort_unless($actor->hasVerifiedEmail(), 403);
        if (! str_starts_with($secret, 'sk_test_')) {
            throw ValidationException::withMessages(['checkout' => 'Only a test-mode secret is accepted.']);
        }

        $attempt = DB::transaction(function () use ($actor, $businessId, $campaignId) {
            $campaign = $this->eligibleCampaign($actor, $businessId, $campaignId);
            $attempt = DB::table('commercial_payment_attempts')->where('campaign_id', $campaignId)->lockForUpdate()->first();
            if ($attempt) {
                $this->assertRetryable($attempt, $campaign);

                return $attempt;
            }

            $id = DB::table('commercial_payment_attempts')->insertGetId([
                'campaign_id' => $campaignId,
                'reference' => (string) Str::uuid(),
                'provider' => 'stripe-test',
                'amount_cents' => $campaign->estimated_cents,
                'currency' => 'cad',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Audit::record('commercial.test-attempt-created', $actor->id, context: ['attempt' => $id, 'campaign' => $campaignId]);

            return DB::table('commercial_payment_attempts')->where('id', $id)->firstOrFail();
        }, attempts: 3);

        // Do not hold database locks across a network request. A timeout leaves
        // the reference intact so a retry can recover the same provider session.
        $session = app(StripeTestCheckout::class)->create($secret, $attempt->reference, (int) $attempt->amount_cents, Carbon::parse($attempt->created_at)->addHours(20)->timestamp);

        // Bind the provider session even if the campaign was withdrawn during
        // the request, so any later test payment event can still reconcile.
        DB::transaction(function () use ($actor, $attempt, $session) {
            $locked = DB::table('commercial_payment_attempts')->where('id', $attempt->id)->lockForUpdate()->firstOrFail();
            if ($locked->provider_session_id !== null && $locked->provider_session_id !== $session['id']) {
                throw ValidationException::withMessages(['checkout' => 'The provider returned a different session for the same attempt.']);
            }
            if ($locked->provider_session_id === null) {
                DB::table('commercial_payment_attempts')->where('id', $locked->id)->update(['provider_session_id' => $session['id'], 'updated_at' => now()]);
                Audit::record('commercial.test-session-bound', $actor->id, context: ['attempt' => $locked->id]);
            }
        }, attempts: 3);

        // Recheck access, campaign eligibility and attempt state before
        // returning the hosted checkout URL to the caller.
        DB::transaction(function () use ($actor, $businessId, $campaignId, $attempt) {
            $campaign = $this->eligibleCampaign($actor, $businessId, $campaignId);
            $locked = DB::table('commercial_payment_attempts')->where('id', $attempt->id)->lockForUpdate()->firstOrFail();
            $this->assertRetryable($locked, $campaign);
        }, attempts: 3);

        return ['reference' => $attempt->reference, 'id' => $session['id'], 'url' => $session['url']];
    }

    public static function enabled(): bool
    {
        return (bool) config('network.commercial_test_checkout')
            && CommercialRecords::enabled()
            && Schema::hasTable('commercial_payment_attempts')
            && Schema::hasTable('commercial_payment_events');
    }

    private function eligibleCampaign(User $actor, int $businessId, int $campaignId): AdvertisingCampaign
    {
        $business = Business::lockForUpdate()->findOrFail($businessId);
        abort_unless($business->members()->whereKey($actor->id)->wherePivot('role', 'owner')->exists(), 404);
        $campaign = AdvertisingCampaign::where('business_id', $businessId)->lockForUpdate()->findOrFail($campaignId);
        if (! in_array($campaign->planningPhase(), ['scheduled', 'in-window'], true)
            || ! $campaign->reviewed_at || ! CommercialRecords::eligible($business, $campaign->site, $campaign->placement)
            || $campaign->estimated_cents === null || $campaign->estimated_cents < 50 || $campaign->estimated_cents > 100000000) {
            throw ValidationException::withMessages(['checkout' => 'An eligible, independently approved and priced campaign is required.']);
        }

        return $campaign;
    }

    private function assertRetryable(stdClass $attempt, AdvertisingCampaign $campaign): void
    {
        if ($attempt->provider !== 'stripe-test' || $attempt->currency !== 'cad' || $attempt->status !== 'created'
            || (int) $attempt->amount_cents !== $campaign->estimated_cents
            || Carbon::parse($attempt->created_at)->addHours(19)->lte(now())) {
            throw ValidationException::withMessages(['checkout' => 'The existing test attempt is no longer retryable.']);
        }
    }
}

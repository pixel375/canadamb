# Commercial schema review — proposed only

Baseline release: cf91a97d5ba309de54c3947138dcaf9844025557. SQLite and MariaDB each passed 105 tests / 1,915 assertions, including explicit proposal migration and rollback; Chromium passed the private campaign workflow. The new hardening changes require their own CI.

## Review decisions
- Four additive tables; no alteration of existing production tables. Campaigns reference businesses, portals and immutable rate revisions with restrictive deletion. Actor references are nullable. Business deletion will require an explicit retention workflow; do not cascade financial history.
- Trial grants and campaign approvals use business locks, independent reviewer checks and date boundaries. A trial grants no paid ranking, ad placement or recurring billing.
- Test attempts now have a unique campaign constraint; one attempt per campaign is intentional for the sandbox. Price is derived from the approved campaign snapshot, never a request parameter. Persist the reference before calling Stripe, retry with the same immutable inputs and expiry, bind the resulting session, then recheck owner access and eligibility. No automatic retry after payment/expiry, or beyond the conservative retry deadline.
- Raw signed test events reconcile only a bound session with matching amount/currency/reference. Paid status is not downgraded by a later event. Event receipts contain hashes rather than full customer payloads. This is not a real accounting ledger or tax implementation.
- No payment endpoints or provider credentials are configured; all automated provider HTTP calls are mocked. No public renderer or measurement process is connected.

## Required approval and rehearsal
1. Verify exact SHA backend (both engines) and browser checks, plus normal no-schema-change deployment rehearsal.
2. Decide retention/deletion policy, independent approval and campaign overlap handling; confirm whether restrictive references are acceptable. Review provider test-only keys, tax/refund requirements, and event delivery/reconciliation.
3. On explicit approval, promote the proposed migrations to the standard migration directory in a reviewed release; adapt the test harness to the promoted paths. Run read-only migration preflight and backup verification with the existing guarded workflow. Do not use migrate:fresh on production. Rollback of a populated commercial schema requires a reviewed data-preservation plan, not destructive down migrations.
4. Keep all three CANADAMB_COMMERCIAL_* feature flags false until the schema is confirmed. Enable only the desired private planning capabilities separately. Checkout remains disconnected from routes; never weaken the IP allowlist for webhook testing.
5. For actual provider sandbox testing, use dedicated test credentials and a verified event retrieval/relay process. Test mismatched amount, stale/tampered signature, network timeout/retry, duplicate event, expiry and cancellation. Require independent approval before any live checkout or ad delivery.

Review is technical preparation, not permission to change the production database or activate sales.

# Monetization

Marketplace private posts and directory claims are free in the current implementation. The billing and advertising engines are planned. Build configurable entitlements, campaign approval, privacy-conscious usage events, and a test-mode payment adapter before taking payments. Use test-mode webhook validation and audit-grade reconciliation before asking to enable live billing.

Property advertising and public property publishing require a reviewed compliance gate. Keep real estate commercial and public release disabled until that review is complete.

## Proposed first commercial release (not enabled)

Keep basic Marketplace listings, business profiles and jobs free initially. Separate paid advertising placements from organic listings and moderation. Candidate products: clearly labelled sponsored business directory placements and local banner campaigns with community scope, start/end dates, placement limits and moderator approval. Avoid implying sponsorship or paid membership verifies a business.

Before implementation, review a dedicated additive schema for plans, entitlements, campaigns, creative assets, payment attempts and immutable provider-event reconciliation. Use test-mode billing and signed idempotent webhooks only; do not store card information. Enforce entitlements server-side, expiry and refunds independently of payment callbacks. Confirm pricing, currency, tax and refund policy with the owner before enabling any sales. No billing or ad delivery is introduced by the team management pass.

## Implemented: internal commercial planning

The super-administrator-only /admin/commercial workspace stores an optional CAD rate card for Business Pro, sponsored directory placements, homepage banners and Marketplace banners. It uses existing versioned network configuration, integer cents and explicit draft/publish controls. No rate is preset. Preview cards use an active directory business or an explicitly illustrative example, with no links, billing, live placement, tracking or entitlements. Publishing a configuration revision does not enable advertising. The operational schema, advertiser workflows and payment adapter are still outstanding.

The private placement preview also calculates estimates for one to twelve whole weeks using proposed rates, checks whether the selected active portal hides the target module, and flags businesses without a branch in the selected community. The estimate is not a quote, allocation or promise of delivery. No campaign record is created.

## Review-staged persistence

The additive migration in database/proposed-migrations/2026_11_24_000001_create_commercial_planning_tables.php is deliberately outside the automatically discovered migrations directory. It adds advertising_campaigns and business_entitlements without changing existing tables. Do not run it on production until reviewed and explicitly approved; move it into the standard migration directory as part of that reviewed release. CANADAMB_COMMERCIAL_PLANNING_RECORDS defaults to false; even if enabled, both tables must exist. No delivery, charge, inventory reservation or paid feature is implemented. Campaigns use published internal rates snapshotted at submission, owner-only access, physical branch eligibility and independent admin review. Trial entitlement checks are informational; super administrators can grant non-overlapping 1–90-day internal trials and revoke them with audit records. No features or billing are unlocked. No external payment integration is present.

The proposed schema is loaded explicitly by the commercial records feature test and disposable browser QA only. Normal production migrations and feature flags remain unchanged.

## Private placement schedule

Planning phases use Manitoba calendar dates and exclusive end dates: pending requests lapse once their proposed start passes; approved requests become scheduled, in-window or expired. History is retained and past records no longer consume the owner's open-request limit. A private simulation selects at most one independently reviewed, still-eligible campaign per active portal and placement, oldest approval first. No public renderer calls the selector, and no inventory is reserved. The selection policy is proposed, not a commercial guarantee.

The schedule preview reports competing requests and applies a single-slot selection rule. It is not an inventory booking system. An optional internal trial tool exports the owner's own campaign plans as JSON only while a valid trial exists and CANADAMB_COMMERCIAL_TRIAL_EXPORT is explicitly enabled (default false). No payment or public ranking privileges are granted.

### Future measurement contract

Do not record simulated placements as impressions. Once separately approved public rendering exists, count served placements in aggregate by campaign, portal, placement and Manitoba calendar date, with atomic increments; distinguish served render opportunities from verified viewability. Do not retain visitor identifiers, IP addresses, fingerprinting data or per-visitor histories. Exclude admin previews, disabled modules and private drafts. Define bot exclusions, caching behavior, retention and reconciliation before enabling measurement. Until then all public delivery and measurement remain disconnected.

## Test payment foundation (not connected)

A second review-staged migration adds a minimal test-payment attempt ledger and deduplicated event receipts. The unconnected Stripe test adapter accepts only sk_test_ keys, sends server-generated references and integer CAD amounts with idempotency, and validates the returned test Checkout session. The verifier checks the raw signed event, five-minute timestamp tolerance, event type, test mode, session, amount, currency and reference before reconciliation. The ledger is transactionally idempotent and stores hashes rather than complete event payloads. No real provider calls are made by CI; HTTP is faked. There is no checkout or webhook route and no account credential has been supplied. Neither payment success nor test records activate campaign delivery or Business Pro. Before wiring routes, obtain explicit schema review, settle tax/refund policy, review amount locking, provider session creation/recovery and webhook access under the IP gate, then test with a dedicated Stripe sandbox. Stripe Checkout and webhook references: https://docs.stripe.com/api/checkout/sessions/create and https://docs.stripe.com/webhooks.

See [COMMERCIAL_SCHEMA_REVIEW.md](COMMERCIAL_SCHEMA_REVIEW.md) for the staged schema review and acceptance checklist. The unwired TestCheckoutAttempts service persists one immutable attempt per eligible approved campaign, reuses the same idempotency key on retry, binds the provider session, and checks authorization again before returning a hosted test URL. CANADAMB_COMMERCIAL_TEST_CHECKOUT defaults false. Automated checkout remains mocked; no endpoint invokes this service. A super-administrator-only, read-only test ledger view shows bounded pages of attempts and grouped event outcomes, without provider references, raw events or credentials. Campaign planning supports text/portal filters, a one-year scheduling horizon, duplicate prevention and cursor-bounded exports.

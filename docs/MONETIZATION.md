# Monetization

Marketplace private posts and directory claims are free in the current implementation. The billing and advertising engines are planned. Build configurable entitlements, campaign approval, privacy-conscious usage events, and a test-mode payment adapter before taking payments. Use test-mode webhook validation and audit-grade reconciliation before asking to enable live billing.

Property advertising and public property publishing require a reviewed compliance gate. Keep real estate commercial and public release disabled until that review is complete.

## Proposed first commercial release (not enabled)

Keep basic Marketplace listings, business profiles and jobs free initially. Separate paid advertising placements from organic listings and moderation. Candidate products: clearly labelled sponsored business directory placements and local banner campaigns with community scope, start/end dates, placement limits and moderator approval. Avoid implying sponsorship or paid membership verifies a business.

Before implementation, review a dedicated additive schema for plans, entitlements, campaigns, creative assets, payment attempts and immutable provider-event reconciliation. Use test-mode billing and signed idempotent webhooks only; do not store card information. Enforce entitlements server-side, expiry and refunds independently of payment callbacks. Confirm pricing, currency, tax and refund policy with the owner before enabling any sales. No billing or ad delivery is introduced by the team management pass.

## Implemented: internal commercial planning

The super-administrator-only /admin/commercial workspace stores an optional CAD rate card for Business Pro, sponsored directory placements, homepage banners and Marketplace banners. It uses existing versioned network configuration, integer cents and explicit draft/publish controls. No rate is preset. Preview cards use an active directory business or an explicitly illustrative example, with no links, billing, live placement, tracking or entitlements. Publishing a configuration revision does not enable advertising. The operational schema, advertiser workflows and payment adapter are still outstanding.

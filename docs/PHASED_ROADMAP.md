# CanadaMB phased implementation and launch roadmap

## Pass 01 — Registration and Authentication

Issue #11 delivers a default-off prelaunch registration switch, audited manual verification, centralized recovery and Passport sign-in across both portals. Keep the live registration switch off until the owner begins [allowlisted browser acceptance](AUTHENTICATION.md#pass-01-prelaunch-acceptance). Account profile, notification and later design work are outside Pass 01. [Issue #11](https://github.com/pixel375/canadamb/issues/11)

**Strategy:** Finish infrastructure reliability and end-to-end functionality before a major visual makeover. Establish basic reusable UI primitives now so later design work doesn't require rebuilding feature logic. All changes must be committed/tested and promoted to the live release deliberately; no blind auto-deploy.

| Phase | Priority | Scope and release gate |
| --- | --- | --- |
| 0 — Live deployment reliability | **Now** | Correct Hostinger Bash logging; guard release integrity; backup and **real restore rehearsal**; hPanel cron and queues; logs, health checks, rollback; establish controlled one-click deployment without exposing Laravel root or secrets; maintain IP-restricted prelaunch. |
| 1 — Marketplace end-to-end | **Next functional pass** | Image click, `contain`/responsive media, full-res gallery, accessible multi-image lightbox, upload/edit tests, preserve browsing site with canonical SEO policy, category filters, seller card, messaging/owner controls, related and recent listings. See [feedback](MARKETPLACE_FEEDBACK.md). |
| 2 — Business directory and moderation | High | Actual business importing from licensed sources, verification/claim workflow, admin review and branch management; test owner updates, reversals, disputes, report moderation and permissions. |
| 3 — Remaining core modules | High | Real news feed editor/publisher flow, forum/reply/moderation, events and calendar, employer jobs and expiration, messaging/report handling, admin/settings usability. Keep Real Estate public publishing/commercial features gated pending legal review. |
| 4 — Automation & network operations | High | Robust hourly/daily scheduled ingest and manual editorial checks, notifications, mail delivery and preferences, failure/retry observability, city onboarding and manual DNS/TLS readiness, geographic coverage and authoritative location boundaries. |
| 5 — Monetization | Later | Ad inventory, placements, advertiser portal, Business/Pro entitlements, payment **test mode**, invoices/refunds, fraud/compliance gating; no live payments until verified. |
| 6 — Full professional UI/UX redesign | Later (parallel design research allowed) | Distinct polished design direction informed by owner references, provincial/homepage modularity, listing and seller cards, photo galleries, responsive sidebars, color presets, light/dark mode, accessibility and performance. Use shared components across city sites while retaining local accents. |
| 7 — Public launch | Last | Robust review of authentication/SSO, CSRF, access controls, mail, logs, backups, performance, data rights and moderation; policies, user privacy/export/deletion, launch content, search indexing; explicit approval before disabling prelaunch. |

## Mobile installable app (PWA) — deployed in private prelaunch

[Issue #8 — Optional PWA with mobile-footer Install App button](https://github.com/pixel375/canadamb/issues/8) has been implemented and released on both IP-restricted portals with the initial mobile navigation and profile functionality. Provide independent CanadaMB/BrandonMB app manifests and icons, Android install prompting only after the visitor taps the footer button, and honest iOS Add to Home Screen instructions. Keep install optional and non-intrusive. Cache static public assets conservatively; never offline-cache private messages, OAuth tokens, admin content or other personal pages. Preserve IP-restricted prelaunch and existing SSO. A global published admin toggle now controls install availability. PHP tests and both deployment/audit workflows passed; real Android/iOS install acceptance remains. See [PWA testing](PWA_INSTALL.md).

## Observed rollout checkpoints

- **Phase 0 functional infrastructure:** SSH/SFTP, real production MariaDB restore rehearsal, guarded two-domain first/second deployments, hPanel cron execution and a live database queue canary all passed. Keep deployment manual-only and public prelaunch gate active.
- **Phase 1 first functional rollout:** target `0b2359f5eb4587f9fd5dfad1b36402df2b7d5349` deployed with core cards/gallery/canonical/category/seller/related improvements, PHPUnit on both database engines and independent postdeploy audit. Approved-IP mobile/browser interaction tests and five-photo edge cases are the next acceptance gate; broad redesign is later.

## Engineering discipline
- Live SHA and remote branch SHA may differ. **A Git push must never be presented as live deployment** until Hostinger release and two-domain smoke are confirmed.
- Preserve one database, one Laravel app, one SSO identity, one central admin, host-specific content scope; keep `canadamb.ca` as provincial aggregator and `brandonmb.ca` city-restricted.
- No changes to production `.env`, APP_KEY, Passport keys, database content, or shared media as part of documentation/deployment script fixes.
- For each phase: tests on SQLite and MariaDB, actual affected-host browser checks, security checks, one release at a time, and reversible UI changes. Use non-destructive migrations with a verified backup/restore strategy.
- Avoid implementing new monetization or a broad redesign during the deployment-stabilization pass. Capture visual references separately.

## Local context and administration expansion (2026-11-25)

- Responsive Manitoba-local clock: compact desktop utility row and mobile navigation drawer, named timezone and daylight-saving support; configurable visibility/format.
- Local current weather: optional free-tier compatible WeatherAPI.com integration with protected credential entry, community-level coordinates only, attribution, informational disclaimer, bounded shared cache and provider-call budget. No real key in Git or browser. Keep off until configured and verified.
- Admin redesign: grouped role-aware navigation, clear operational dashboard, settings shortcuts, accessible responsive layouts; expand theme presets, preview and safe publishing controls.
- Configurable independent moderation policies for new Marketplace listings, discussions and comments/replies. Default manual review; deliberate super-admin draft/publish, verified-member eligibility, audit trail, no retroactive approval; edits and sensitive workflows retain manual review. Extend further types only after separate acceptance.
- Follow-up: full theme editor UX, per-community policy overrides, finer trusted-member/anti-spam controls, weather provider acceptance and quota monitoring, real device acceptance.

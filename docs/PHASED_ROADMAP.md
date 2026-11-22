# CanadaMB phased implementation and launch roadmap

## Pass 01 — Registration and Authentication

Issue #11 delivers a default-off prelaunch registration switch, audited manual verification, centralized recovery and Passport sign-in across both portals. Keep the live registration switch off until the owner begins [allowlisted browser acceptance](AUTHENTICATION.md#pass-01-prelaunch-acceptance). Account profile, notification and later design work are outside Pass 01. [Issue #11](https://github.com/pixel375/canadamb/issues/11)

## Pass 02 — Member profiles, settings and in-app notifications

Issue #13 adds profile privacy controls and one member inbox for existing activity across CanadaMB and BrandonMB. Existing community notifications remain in their original table. The new schema requires a reviewed upgrade and explicit production approval before merge into the deployment branch. See the [migration and owner browser checks](MEMBER_NOTIFICATIONS.md). [Issue #13](https://github.com/pixel375/canadamb/issues/13)

## Pass 03 — Marketplace completion

Issue #15 completes ordered photo editing, normalized private images, accessible media browsing, seller relisting and published listing discovery. The review is stacked on Pass 02 until the approved schema release is complete. Verify the rebased or retargeted Pass 03 diff and exact commit before a future release. [Issue #15](https://github.com/pixel375/canadamb/issues/15) · [Marketplace feedback](MARKETPLACE_FEEDBACK.md)

## Pass 04 — Unified private messaging

Issue #17 expands the existing inbox to Marketplace, business, and job conversations. The migration preserves legacy Marketplace records and adds context, independent notification preferences, restrained contact assignment, private attachments, and reports. The stacked review targets the corrected Pass 03 branch. Production schema review and allowlisted acceptance follow the earlier release gates. [Issue #17](https://github.com/pixel375/canadamb/issues/17) · [Messaging and recovery runbook](UNIFIED_MESSAGING.md)

## Pass 05 — Centralized moderation and reporting

The CanadaMB workbench handles existing submission types and abuse reports, including audited access to specific reported messages. Approval policies start on manual review. The review branch targets corrected Pass 04. Sequence prior-pass acceptance and explicit production migration approval before releasing the additive decision and report tables. [Issue #19](https://github.com/pixel375/canadamb/issues/19) · [Moderation and recovery notes](CENTRAL_MODERATION.md)

## Pass 06 — Discussions

`/discussions` is the public forum path. Same-host compatibility covers old bookmarks and submitted forms. Members can manage their discussions and edit or withdraw eligible posts through the existing review rules. The stacked PR targets corrected Pass 05 and adds no migrations. Pass 07 will handle the agenda and event requests from existing discussions. [Issue #21](https://github.com/pixel375/canadamb/issues/21) · [Routes and author workflow](DISCUSSIONS.md)

**Current release process.** The protected implementation branch uses guarded GitHub CI-to-Hostinger automation. Its checks include an exact verified commit and a default denial for pending unreviewed migrations. Feature-branch pushes and stacked pull requests are review-only. The Phase 0 and Phase 1 checkpoints below describe earlier rollout procedures. [Deployment automation](DEPLOYMENT_AUTOMATION.md)

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

- **Phase 0 functional infrastructure:** SSH/SFTP, real production MariaDB restore rehearsal, guarded two-domain first/second deployments, hPanel cron execution and a live database queue canary all passed. The rollout used manual deployment at this stage. The current guarded workflow superseded that procedure. The public prelaunch gate remains active.
- **Phase 1 first functional rollout:** target `0b2359f5eb4587f9fd5dfad1b36402df2b7d5349` deployed with core cards/gallery/canonical/category/seller/related improvements, PHPUnit on both database engines and independent postdeploy audit. Approved-IP mobile/browser interaction tests and five-photo edge cases are the next acceptance gate; broad redesign is later.

## Engineering discipline
- Live SHA and remote branch SHA may differ. **A Git push must never be presented as live deployment** until Hostinger release and two-domain smoke are confirmed.
- Preserve one database, one Laravel app, one SSO identity, one central admin, host-specific content scope; keep `canadamb.ca` as provincial aggregator and `brandonmb.ca` city-restricted.
- No changes to production `.env`, APP_KEY, Passport keys, database content, or shared media as part of documentation/deployment script fixes.
- For each phase: tests on SQLite and MariaDB, actual affected-host browser checks, security checks, one release at a time, and reversible UI changes. Use non-destructive migrations with a verified backup/restore strategy.
- Avoid implementing new monetization or a broad redesign during the deployment-stabilization pass. Capture visual references separately.

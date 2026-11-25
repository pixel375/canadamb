# Progress

## 2026-11-24 — dispute intake and business access CI repair

Reply lifecycle deployment [45549311383](https://github.com/pixel375/canadamb/actions/runs/45549311383) succeeded. Business access release `c753cbb` failed PHP formatting checks in controller and route imports, before functional tests. This pass repairs those issues and stages private ownership dispute intake, claimant history, withdrawal and independent close/escalate review. No contested ownership mutation, production migration or feature activation. New exact-SHA CI required.

## 2026-11-24 — business access and ownership lifecycle

Prior reply lifecycle `7515f79fdeba52c2c015e73c8e7348a06d887e2f` passed backend and browser CI ([backend](https://github.com/pixel375/canadamb/actions/runs/45549036272), [browser](https://github.com/pixel375/canadamb/actions/runs/45549036293)). Reporting deployment [45548974394](https://github.com/pixel375/canadamb/actions/runs/45548974394) succeeded. This pass stages recipient-accepted team invitations and independent ownership transfer review, with expiry, cancellation, password checks and a proposed additive schema. No live migration or feature switch changes. Disputes remain separate.

## 2026-11-24 — reply lifecycle and moderation

Prior profile/private-message reporting source `aac294331540e83f9a16fb30a37485e039fe4b15` passed [backend CI](https://github.com/pixel375/canadamb/actions/runs/45548693682) and [browser QA](https://github.com/pixel375/canadamb/actions/runs/45548693671); deployment was queued. This pass adds scoped My replies history, author editing and withdrawal, stale-version protection in moderation, and duplicate-safe notifications on reapproval. Existing notification read state is preserved. Hidden parent titles are not shown in reply history. No migrations or feature switches are changed. New release verification remains separate.

## 2026-11-24 — reporting completion and release checkpoint

The previous source `664df941cdb0d765629c82fff69b593db3b2cb50` passed [backend CI](https://github.com/pixel375/canadamb/actions/runs/45543910619), [browser QA](https://github.com/pixel375/canadamb/actions/runs/45543910600) and [guarded production deployment and independent audits](https://github.com/pixel375/canadamb/actions/runs/45544157484). The next pass extends review-staged reports to profiles and individually reported received Marketplace messages, with participant-only submission and independent review, including refreshed chat messages. No production migration or feature activation is included; this pass requires its own CI and deployment evidence.

## 2026-11-24 — accumulated responsive, account and Marketplace releases verified

Source `0a7a9ab8ccb40438ebc9392f742e3ac1ae11e420` passed both [backend CI](https://github.com/pixel375/canadamb/actions/runs/45518899797) and [browser QA](https://github.com/pixel375/canadamb/actions/runs/45518899917), then [guarded production deployment](https://github.com/pixel375/canadamb/actions/runs/45519178954) and independent exact-SHA, database/backup, and PWA access audits. The November 24 Manitoba local date corresponds to November 25 UTC audit timestamps. Both domains returned 403 externally. Exact-SHA backend AND browser success are now required for auto-deployment; migration approval remains off.

Delivered since earlier snapshots: responsive desktop/mobile navigation; scoped keyword search; preferred community; Marketplace lifecycle and media editing; business, jobs, discussions/events, news and administration dashboards; private property workflows; account password change and session revocation; saved-content filters, blocking, reporting history and messaging refresh. Android standalone installation remains a device-level acceptance item. Neither public registration nor property publication was opened.

The subsequent safety/readiness pass introduces guidance pages and a read-only configuration dashboard; its own deployment is tracked separately. Configuration indicators do not establish external service health.

## 2026-11-21 — Pass 01 registration and authentication

Implemented default-off, published prelaunch tester registration, targeted and audited tester verification, non-enumerating mail-enabled password recovery, and session-bound Passport sign-in across CanadaMB and BrandonMB. No migrations, seeders or operational secrets changed. Deployment and real-device acceptance are recorded separately in the PR and the release checklist. [Issue #11](https://github.com/pixel375/canadamb/issues/11) · [Browser acceptance](AUTHENTICATION.md#pass-01-prelaunch-acceptance)

## 2026-11-21 — independently verified public PWA manifest and icon access

A further real-device report showed Android still added browser shortcuts. Investigated the WebAPK minting requirements and confirmed prelaunch middleware returned 403 for manifest/icons from non-approved networks. Live source `7c86e4cef9986c54e8a72b3ee0fffc3f63a58d32` now makes **only safe public PWA metadata** readable externally. [CI](https://github.com/pixel375/canadamb/actions/runs/44871680397), [deployment](https://github.com/pixel375/canadamb/actions/runs/44871824468), [real external asset/403 audit](https://github.com/pixel375/canadamb/actions/runs/44871914153), and [independent deployment audit](https://github.com/pixel375/canadamb/actions/runs/44871963432) all passed. Approved-IP protection remains on homepage and all application content; network-only service worker remains protected and no private cache is created. **A real Chrome Android WebAPK still needs user validation; Brave may only provide shortcuts.** Details: [PWA acceptance notes](PWA_INSTALL.md).


## 2026-11-20 — screenshot-driven mobile header/admin and PWA install fixes

User mobile Brave screenshots uncovered (1) account/profile icon wrapping under dark-mode control, (2) admin navigation labels shrinking into a single overlapped line, and (3) Brave adding a plain shortcut instead of a standalone installed PWA. Source `7702f240537b3e39e7102854f6ed2e17b0b07277` passed [Verify CanadaMB](https://github.com/pixel375/canadamb/actions/runs/44862188182), was deployed with [guarded Hostinger update](https://github.com/pixel375/canadamb/actions/runs/44862352431) and independently verified by [postdeploy audit](https://github.com/pixel375/canadamb/actions/runs/44862436382). Backups, original web-root directory and both IP-restricted websites remained intact.

Mobile header now uses a fixed one-row CSS grid; theme selector is in the hamburger drawer and profile icon stays centered on the right. Admin navigation uses a collapsible disclosure with two-column links on phones. PWA adds a network-only root-scoped service worker with a fetch handler (no Cache API, private offline data or authorization bypass). **Real Android Chrome standalone/WebAPK install and phone screenshots must still be tested; Brave has a reported browser-side shortcut-only bug**, tracked on [issue #8](https://github.com/pixel375/canadamb/issues/8). Previous Brave shortcut should be removed before retesting in Chrome. There are no schema migrations and deploy-on-push is disabled.


## 2026-11-20 — mobile shell and PWA deployed to IP-restricted prelaunch

Source SHA 90c36b5c9b56530fa15ebbe2633e23cd8b9916b0 passed Verify CanadaMB CI (run 44849693577), the guarded Hostinger release (run 44849860885) and the independent read-only production audit (run 44850000025). Both domains remain behind the approved-IP access gate; database, OAuth keys, backups and original public_html inode were preserved. The release had no schema migrations. GitHub deployment remains manual-only.

Delivered: mobile four-tab navigation, responsive hamburger drawer and desktop grouped navigation; user-facing Discussions labels with legacy routes preserved; collapsed search forms and input overflow fixes; public member profile (name/member since/visible listings) and authenticated display name settings; About and Contact pages; and an optional PWA mobile footer Install App control. Each portal gets its own manifest/PNG icons and the global admin setting can enable/disable install support. There is no service worker or private offline caching. Real device install/navigation QA remains pending, along with registration/testing workflow, business/job messaging, centralized moderation, discussions/events migration, homepage redesign and legal policies. See [PWA notes](PWA_INSTALL.md) and issues #1–#8.


## 2026-11-19 — first Marketplace UX functional pass deployed to live prelaunch

[CI `0b2359f`](https://github.com/pixel375/canadamb/actions/runs/44643244451), [live deploy](https://github.com/pixel375/canadamb/actions/runs/44643501263) and [independent postdeploy audit](https://github.com/pixel375/canadamb/actions/runs/44643771924) all succeeded. CanadaMB and BrandonMB now share current active SHA `0b2359f5eb4587f9fd5dfad1b36402df2b7d5349`, with database/media backups, original web-root inode, OAuth keys and external IP restrictions verified. Changes cover clickable cards, non-cropped larger photo viewer and native lightbox, browsing on the current portal with city canonical links, category filters, seller and related listings. Backend PHP tests include multiple photo upload/replacement and city-scope canonical logic. **Manual browser acceptance and full professional redesign remain unfinished**; no unapproved public access, live mail, news polling or deploy-on-push.


## 2026-11-19 — cron queue canary passed; Marketplace interaction fixes staged

A harmless private queue canary was enqueued through live Laravel and consumed by **the real hPanel minute scheduler**, without manually starting a worker. [Run 44642175328](https://github.com/pixel375/canadamb/actions/runs/44642175328) reported automatic processing and **no failed jobs**. The test marker was removed. This completes the scheduled database queue integration check while prelaunch remains IP-gated.

Marketplace follow-on code now links card images; displays uncropped `contain` media with the larger WebP on listing details; adds a keyboard/arrow/swipe-capable native-dialog photo viewer with direct-image fallback; leaves provincial browsing on `canadamb.ca` while preserving a single canonical city URL; links categories; and adds a seller/contact management card plus scope-filtered related/recent listings. PHPUnit tests now cover image access and two-photo upload/replacement. **Do not treat features as live until a separate green-CI-backed Hostinger release and IP-allowlisted browser acceptance.** No major redesign, schema change, news polling or public registration.


## 2026-11-19 — Hostinger hPanel cron running and verified

Owner created the every-minute custom hPanel cron job after installing the private wrapper. [Hostinger cron diagnostic run 44635981084](https://github.com/pixel375/canadamb/actions/runs/44635981084) confirmed actual scheduler output with a last write 49 seconds before inspection, an operational pause-aware script and **zero detected errors**. The site remains on the first successful GitHub Actions production update `44448bd0de1b736a51909f6ec87f692a41d57950` and prelaunch gate remains enabled. Separate controlled queue and browser/SSO tests are still pending.


## 2026-11-19 — live GitHub Actions deployment completed

Actual production backup restore passed (53 tables, 1 user, 3 listings, two domains); first guarded production update moved `b5f16a6` to `44448bd` via GitHub Actions run 44631266297. Independent postdeploy audit run 44631660770 passed, preserving original public_html inode, database, media, OAuth keys and IP gate. A private cron wrapper has been installed; **owner must schedule it in hPanel and confirm execution**. No public launch, no product/UX overhaul, and no automatic deployment on push. See [live state](LIVE_STATE_2026-11-19.md).


## 2026-11-19 — populated-database upgrade rehearsal passed

GitHub Actions [run 44622393391](https://github.com/pixel375/canadamb/actions/runs/44622393391) passed 35 PHP tests / 409 assertions on both database engines, compiled Vite, and completed a disposable second-release rehearsal. It created an extra user record before updating, produced private MariaDB and storage backups, recorded migration preflight, preserved the user, OAuth keys and *existing* public_html directory inode. A separate doc-updated run [44622640759](https://github.com/pixel375/canadamb/actions/runs/44622640759) also passed. This is **not** an active production deployment; live sites remain `b5f16a6`.


## 2026-11-19 — verified GitHub Actions SSH and SFTP, preparing gated updater

GitHub Actions from public default `main` successfully authenticated to Hostinger using environment secrets (run 44619746774) and independently uploaded/verified/removed a scratch file under the private `.builds` directory (run 44622010185). Both test workflows have returned to manual-only. The production database and the two active websites were not modified.

A separate manual [Deploy CanadaMB Prelaunch](https://github.com/pixel375/canadamb/actions/workflows/hostinger-deploy.yml) now exists on default `main`, with exact SHA/green CI gating and a secret-free build phase. The implementation branch has a guarded `production-update` release mode, a DB backup helper and an upgrade orchestrator; **a production update has NOT been performed**. Disposable CI upgrade tests and first release acceptance remain required. See [deployment automation](DEPLOYMENT_AUTOMATION.md).


## 2026-11-19 — GitHub Actions read-only SSH probe available

Created `.github/workflows/hostinger-ssh-test.yml` **on default branch `main`**. This manually triggered workflow will check the actual Hostinger private/public layout and report the active release SHA using a dedicated SSH environment secret and pinned known-hosts entry. It does not deploy anything or query production DB contents. The operator must configure `hostinger-prelaunch` secrets/variables and run the connection test before any production update automation is introduced. See [setup](ACTIONS_SSH_SETUP.md). Live sites are unchanged.


## 2026-11-19 — live prelaunch confirmed; owner UX feedback; deployment reliability fix

**Owner verified** the real CanadaMB and BrandonMB sites are running successfully on Hostinger Business with the same PHP application and database and IP-restricted prelaunch; initial acceptance 7/7 passed. Test Marketplace listings were created. The deployed site is pinned to `b5f16a6c08eefcd804deb1970f52490220af67f5`, **not** the newer repository commit. No automatic deployment, cron verification, outbound SMTP, live billing or public access was enabled by this pass.

The owner reported incomplete Marketplace media, card click targets, cross-domain redirection, category links, seller contact, recommendations and overall visual polish. Code inspection of card, detail template, photo pipeline and controller confirmed several root causes. Captured in [MARKETPLACE_FEEDBACK.md](MARKETPLACE_FEEDBACK.md), with priorities in [PHASED_ROADMAP.md](PHASED_ROADMAP.md).

The GitHub release script now avoids Hostinger-incompatible Bash process substitution and `/dev/stderr`; CI regression assertions protect this change. The exact cause of the previously zero-byte **working-copy** scripts remains unknown. The original live cutover had to preserve the existing `public_html` directory inode, not replace it. A safe Git auto-deployment proposal is documented in [DEPLOYMENT_AUTOMATION.md](DEPLOYMENT_AUTOMATION.md); it is **not enabled**.

See [LIVE_STATE_2026-11-19.md](LIVE_STATE_2026-11-19.md) for evidence and operational gaps.


## 2026-11-19 production-first prelaunch

The initial Hostinger release now uses the existing production MariaDB schema in `production-initial` mode. The script confirms the exact schema and grants, requires backup hashes and a read-only migration fingerprint, and requires the approved SHA and production database name at activation. Future isolated staging remains supported. This change adds no migrations or product modules.

Prelaunch is independent of `APP_ENV`. It requires exact tester IPs, denies public registration, blocks indexing and shared caching, disables news polling and uses non-delivering mail. Controlled testers retain the central sign-in and Brandon callback. The Hostinger HTTPS probe proved both domains share the same physical root and receive the correct original Host header. PHP web and CLI prerequisites and the existing empty MariaDB schema were verified. No production migrations or cutover were run.

Next use the [production runbook](HOSTINGER_DEPLOYMENT.md) and [prelaunch acceptance checklist](DEPLOYMENT_CHECKLIST.md). Complete the private config and backups, confirm the allowlisted operator networks, and obtain explicit approval before moving the current web root or activating Laravel.

## 2026-11-19 Hostinger staging preparation

Preserved the three implementation commits on [implementation/network-foundation](https://github.com/pixel375/canadamb/tree/implementation/network-foundation) at `e38b910bbead5c804c6d3c262d06ae828dfb8d5c` before auditing. Gitleaks found no secrets in those commits. The first GitHub CI run exposed a Passport state-validation ordering issue and an obsolete 2027 timezone assumption in the event test. Both are addressed in the staging checkpoint.

The release script now uses the private `canadamb-app` layout. A staging-only MariaDB credential, verified backups, and a reviewed migration preflight are required. The guarded scheduler command serializes runs against deployment and sets a hard runtime cap. Staging pages opt out of indexing and shared caching. No new schema migration is included in this pass.

Read-only SSH and public HTTPS checks confirmed the Hostinger CLI, WebP, scheduling tools, existing public Git checkout, both certificates, and blocked responses for the tested Git and environment paths. Sodium is present but disabled by default on the CLI. The web runtime, exact parked alias routing, isolated staging database, restore rehearsal, and live application checks remain pending. No Hostinger application release or migration was run.

Local PHP suites passed on SQLite and disposable MariaDB, with 33 tests and 384 assertions each. The MariaDB-backed two-host HTTP and Chrome smoke flows passed. Both negative CSRF requests returned 419. See the [source audit](CODE_AUDIT.md) and [acceptance checklist](DEPLOYMENT_CHECKLIST.md) for evidence and pending checks. The next task is the Hostinger staging checklist, beginning with Sodium, a verified parked alias, and an isolated staging database. Advertising and billing remain deferred.

## 2026-11-19 continuation

| Milestone | State |
| --- | --- |
| 4. Feed discovery | Opt-in RSS and Atom polling, source health, backoff, and deduplicated candidates for editorial review |
| 6. Forums and events | Location-scoped discussions, moderation, follows, in-app reply notifications, and Manitoba-time calendars |
| 7. Job board | Employer-owned submissions, version-bound review, searchable public posts, bookmarks, and scheduled expiration |
| 8. Real estate | Private structured drafts, photo storage, and internal review. Public and commercial publishing remain unavailable. |

Existing installations must publish their desired forum, event, and job visibility in Admin → Settings after migration. See [community modules](COMMUNITY_MODULES.md), [news operations](NEWS_AUTOMATION.md), and [private property drafts](PROPERTY_DRAFTS.md).

Local checks passed 31 PHP tests with 363 assertions on both SQLite and MariaDB 11.4.14. The Vite build, Pint, Laravel route and view caches, two-host HTTP smoke, and Chrome smoke passed. The Chrome check included the Brandon calendar at 390 CSS pixels, plus the shared sign-in and geographic Marketplace flow.

At this checkpoint the remaining milestones included Hostinger staging, advertising, subscriptions, scheduled AI summaries, property compliance and launch review.

## 2026-11-19 foundation

The repository started with a README and two commits. The implementation branch now contains one Laravel 13 application for CanadaMB and BrandonMB.

| Milestone | State |
| --- | --- |
| 1. Network foundation | Shared Laravel, MariaDB schema, exact host routing, central admin, audited theme publishing and rollback, separate local accents, Fortify identity and Passport PKCE |
| 2. Community management | Draft creation, unique host/location, public layout preview, operator DNS/TLS readiness, lifecycle status and activation callback provisioning |
| 3. Business directory | Licensed CSV intake, deduplication and import logs, directory and profiles, manual claim review, ownership permissions and moderated branch updates |
| 4. News | Manual and authenticated idempotent drafts, approved sources, geographic scope, manual publishing, source attribution, correction and withdrawal history |
| 5. Marketplace | Moderated listings, structured fields, media and thumbnails, search and filters, owner controls, expiration, canonical links, favourites, reports and private messaging |
| 6–9. Remaining product modules | Forums, events, jobs, real estate, advertising, subscriptions and billing are still open |
| 10. Production readiness | Shared-hosting environment template, CI asset build, deployment scripts, two-host smoke tests and runbook. Hostinger staging and launch gates remain open. |

Migrations and dependencies are recorded in [database](DATABASE.md) and the package lockfiles. See [testing](TESTING.md) for reproducible commands and [known issues](KNOWN_ISSUES.md) for uncovered acceptance items.

Local verification covered 17 PHP tests with 203 assertions each on SQLite and MariaDB 11.4.14, Playwright in Chrome across two localhost origins, the HTTP smoke script, the Vite build, style and dependency checks, and a disposable MariaDB migration from a production-style configuration. The official Manitoba CSV imported 1,884 supported place records into a disposable database and preserved Brandon's existing location ID. Account-specific hosting validation remains pending.

Run private Hostinger staging validation before connecting an application release to automatic Git deployment.

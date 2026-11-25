# Progress

## 2026-11-25 — private data export coverage

Added bounded password-confirmed participant-local conversation metadata and sent-photo metadata exports without other participants' read markers, identity fields, received content or storage paths. Expanded read-only privacy impact counts and regression coverage. No deletion or retention execution.

## 2026-11-25 — private property review and reuse

Added owner-scoped similar-draft creation without photos, address, land description, availability or prior declarations; authorized full-resolution photo viewing with no-store/nosniff headers; creation and exact-content declaration/review audit hashes; version-bound browser archive actions. Public property publication remains disabled.

## 2026-11-25 — Marketplace edit integrity

Browser editor and lifecycle actions carry full version tokens including latest status history; stale submissions are rejected when a token is supplied. Legacy callers without tokens remain compatible. Unchanged active edits retain publication; photo order updates only when needed; duplicate price is prefilled. Active moderation permits removal rather than rejection. No schema change.

## 2026-11-25 — staged schema integrity inventory

Expanded read-only readiness to required columns, selected uniqueness/primary keys and conversation listing nullability, with per-feature mismatch details and an installed-schema regression over every proposal. No migrations applied or feature flags changed.

## 2026-11-25 — saved search revision

Added private portal-bound edit forms for Marketplace and job saved filters, shared normalized validation with creation, serialized duplicate checks and job alert watermark reset only on changed filters. Added edit authorization and matching regressions. No schema or feature activation.

## 2026-11-25 — event reuse and bounded batch cancellation

Owner-only reuse prepopulates event details but never copies dates or discussion links. Added atomic selected-event cancellation (up to eight, same portal and author, explicit confirmation, no partial changes), audit records, single-event duplicate prevention and one-year submission horizon. No series identity schema or automatic republication.

## 2026-11-25 — business announcement lifecycle

Added private portal-scoped update history with filters, version-bound revisions/withdrawal, independent version-bound moderation, audit transitions and paginated claim history. Revised public content returns to pending, and withdrawal preserves the historical row. Owner/manager may manage team posts; editors may manage their own posts. No migration.

## 2026-11-25 — member discovery and unified inbox filters

Added conversation-type filtering across all/unread/archived views, visible context labels and preserved query parameters, with no private message content used as search filters. Saved Marketplace searches are directly linked from account shortcuts; both saved-search types join the shared saved-content navigation. Added scope and navigation regressions.

## 2026-11-25 — review privacy and consolidated workload

Content report list and count share a query that excludes reporter, author, business-member and private-conversation conflicts. Private reports must have a valid original recipient. Added reference filters and regression coverage; target checks remain in resolution actions. Dashboard now surfaces enabled business change, transfer, dispute, content, privacy and commercial queues with permission-scoped private counts. No schema or feature switch changes.

## 2026-11-25 — messaging lifecycle and activity integrity

Business/job replies require a single verified original owner and an open business; job inquiries remain replyable after ordinary expiry but not after withdrawal, rejection or branch closure. New inquiries recheck the recipient after participant locking. Archive/unarchive and sync read receipts no longer mutate conversation activity order. Photo processing errors map to the actual upload field. Added regression checks for stale owner verification, ambiguous ownership, withdrawn/expired jobs and archive activity timestamps.

## 2026-11-25 — branch reopening and review history

Owner-only reopening requires independent review at the original address, preserves branch identity and does not restore withdrawn jobs or announcements. Added status/kind/reference review filters and regression coverage. Updated confirmed production source and quota recovery record. No new migration or switch changes.

## 2026-11-25 — reviewed branch closure and relocation

Used existing branch location_status and staged business change requests: owner-only closure or CanadaMB-only relocation, independent review, preserved original branch/address/history, withdrawal of pending/approved jobs and announcements, open-only public directory/canonical/search/sitemap, job and inquiry eligibility, and commercial eligibility. Management retains closed branches; relocation creates a new branch rather than moving old records. No new schema or public activation; reopening requires separate review. Added branch lifecycle regression coverage.

## 2026-11-25 — moderation concurrency and production dependency reclaim

Marketplace reviews now require a current content/photo version and reject self-review. Listing report resolution excludes the reporter and seller, rejects repeated decisions and uses consistent listing/report locks. Added stale-review regression and staged saved-search browser acceptance. The Hostinger read-only inventory found about 4.7 GiB of accumulated release files; trusted-main workflow 45655814730 reclaimed 2,020,852 KiB of Composer dependencies from 20 explicitly verified inactive releases, retaining all source, backups, shared storage and recent rollback copies. Live DB checks and both domain access gates passed after reclaim. Retired vendor directories require dependency reinstall before rollback. No database changes or public access changes.

## 2026-11-25 — private saved Marketplace searches

Shared existing public filter validation and matching logic, added a default-off private saved-search store, duplicate normalization, per-account limit, rename/delete, current public result counts and links, account export and impact inventory. No email, push or background alerts; no production migration applied.

## 2026-11-25 — hiring lifecycle and deployment incident

Prior `8851bdd` passed exact backend and browser CI but production preparation failed before activation. Read-only audit 45655286097 classified the private log as Disk quota exceeded; prior `ba1ee92` remains active and both domain gates passed. Added branch filters, version-bound job edits, expired job re-review, conflict-free job review, audited hiring transitions, one-year closing horizon and private unavailable bookmark cleanup. No schema changes.

## 2026-11-25 — campaign duplicate-date regression

Backend 45612984060 reached the full 126-test suite with one failing assertion: equivalent campaign submission was not detected on SQLite because Eloquent stores date casts with a time suffix. Compare using whereDate, as the existing campaign date filters do. Keep duplicate protection and its test. Final cumulative CI required.

## 2026-11-25 — Marketplace seller and saved-item lifecycle

Added owner-only status history (no internal notes or staff identifiers), prefilled new-listing workflow for sold/expired/rejected items without copying photos or conversations, renewal after expiry even before the scheduled sweep, unavailable-bookmark cleanup with no hidden titles, and strict media path validation. Added owner and portal boundaries to regression checks. Existing review and public visibility rules remain.

## 2026-11-25 — business ownership lifecycle

Implemented claim withdrawal, verified claimant recheck, conflicted-review exclusion, atomic supersession of competing claims and audit records. Dispute queue supports escalated follow-up, filters and closure; open cases block duplicates and may be withdrawn. Access requests identify the parties without exposing email. Added lifecycle regression assertions; no database or production switch changes.

## 2026-11-25 — cumulative browser acceptance green

Exact `cca7be8` browser run 45612098709 passed, including recurring event submission after isolating its rate-limit bucket. Backend 45612098489 stopped on a missing blank line between property test methods. Corrected that style issue without changing assertions. Final full backend verification remains required.

## 2026-11-25 — commercial formatting and property photo regression coverage

Corrected campaign export indentation and isolated duplicate query for Pint; added explicit retained-photo scope, stale edit and non-destructive property withdrawal coverage. Earlier property and privacy suites passed their backend test steps; final cumulative QA remains required.

## 2026-11-25 — staged event browser diagnostics

Chat menu repair passed the expanded browser flow through archive and restoration. Recurrence form timed out at redirect; isolate the event submission rate-limit bucket (still six per minute), capture the POST status, validation page and screenshot on failure. The cause remains to be confirmed by exact-SHA QA.

## 2026-11-25 — commercial planning and sandbox ledger visibility

Added campaign search and portal filtering, duplicate guard, one-year start horizon, bounded trial exports and a read-only, gated test-payment ledger with grouped outcome counts. Tests check the ledger omits provider references and rejects members. No checkout route, real provider call, payment or ad renderer.

## 2026-11-25 — privacy request triage and record impact inventory

Added restricted request filters, withdrawn history and audited aggregate-only impact inspection for open requests (including shared conversations, ownership, security and optional schema records). Expanded self-export with follows, notification history and access-request status, excluding other account identities and review notes. No deletion, automatic retention or fulfillment.

## 2026-11-25 — property draft management and independent review

Private property drafts now support version-bound editing/submission/review, retained photo ordering/removal/addition (six total), private thumbnail path validation, independent reviewer filtering, and returning submitted/reviewed records to draft without deleting photos. Existing public gates unchanged. No schema migration or publication action.

## 2026-11-25 — staged browser QA chat menu repair

Expanded browser run `45606518944` passed saved searches, preferences, privacy request lifecycle and business controls before finding overlapping absolute-positioned Archive and Block forms. Use a single stacked popover and hit-test both actions at 320, 390 and 1440px; preserve archived inbox filters on search reset. Backend `45606518888` passed; the latest release was held by browser QA. No schema or configuration change.

## 2026-11-25 — cumulative calendar regression fixes

Backend CI `45605838934` reached the full suite and reported two calendar failures. The monthly agenda now respects an explicit day selection rather than exposing other days' event titles. The repeat test uses Manitoba's known March 2026 clock change (the existing event test already fixes this historical fixture), rather than assuming future March 2027 changes. Agenda previews now have deterministic chronological order. Exact latest SHA checks still required.

## 2026-11-25 — staged schema inventory and launch safeguards

Added read-only inventory for all 13 proposed migration files, dependency flags, presence of expected tables/columns and recorded migration history. Exposes readiness indicators and a CLI report without secrets; deployment database checks reject configured features with missing/unrecorded schema. Added tests for manifest coverage and mismatch detection. Does not apply migrations or switch features on. Review actual schema details, retention, backups and acceptance separately.

## 2026-11-25 — editorial workflow and event lint repair

Added article-version checks, source-rights editing and locking, keyword/source/status queue filters, staged candidate dismissal/restore and audit logging. Polling remains off in prelaunch and automatic publication is not introduced. Fixed event controller import/negation style and calendar string formatting flagged by CI `45605521848`. Exact-SHA verification required.

## 2026-11-25 — notification preferences and moderation template repair

Added default-off account-wide preferences for new discussion notifications and job-alert dashboard summaries. Existing unread counts/history and follows remain unchanged, and no email or push delivery is added. Added bounded on-demand new-match search summary and optional export of notification preferences. Corrected adjacent Blade conditionals causing the photo moderation queue to fail compilation in CI `45605372175`. No migration or feature activation.

## 2026-11-25 — recurring event submissions and calendar usability

Added atomic bounded weekly/biweekly submissions (up to eight dates, individually reviewed, 24 pending per member when enabled), wall-clock DST validation and duplicate rollback, monthly day agenda, scoped approved-only iCalendar downloads and regression tests. No schema changes or scheduled recurrence expansion. Recurrence switch defaults off; existing one-time events and moderation remain intact.

## 2026-11-25 — photo report moderation and export CI repair

Attachment source `ce2f4d7` passed exact backend and browser checks. Privacy pass `fac6793` failed only because its expanded export test exceeded the production six-per-minute limit. Keep the throttle; assert 429 then advance test time. This pass stages distinct photo reports, narrowly scoped pending-report download for independent moderators, audit logging and participant-only reporting. No arbitrary inbox access or automatic removal.

## 2026-11-25 — privacy intake, export expansion and attachment inventory

Expanded owned-record and bookmark exports, including optional schema-aware sections; added proposed password-confirmed deletion/data-review intake and super-admin-only triage without destructive actions. Added a bounded, read-only private attachment inventory command (aggregate counts only; no filenames, automatic deletion or scheduling). Retention and legal holds, actual account erasure and export fulfillment remain unresolved. Previous attachment commit `ce2f4d7` was still in CI at start of pass. No production migrations or feature changes.

## 2026-11-25 — private message photos and owner inquiry preferences

Prior source `ba1ee92` passed backend/browser and [deployment with independent audits](https://github.com/pixel375/canadamb/actions/runs/45602126083). Stages single re-encoded photos in existing replies, participant-only noncached download, sync links, per-sender/day and conversation quotas, and owner-bound independent opt-in for business and job inquiries. The dedicated private disk lies within shared storage backed up by the existing release process. No document uploads, public file serving, attachment-specific reviewer access, automatic retention or live schema/flag changes. Review retention and orphan cleanup before enabling.

## 2026-11-25 — unified private inquiries and archives

Saved search source `080ea68` passed browser QA but failed Pint on a test import; corrected in this pass. Stages existing-inbox business/job inquiries to a sole verified owner, participant-specific archive/read state and automatic restoration on new messages. Prior participant history stays private on transfer; new replies to former owners are denied. No attachments or live schema changes. Proposed conversation migration requires special rollback review and exact-SHA tests.

## 2026-11-25 — saved job searches and in-app alerts

Prior business changes `c45e223` passed [backend](https://github.com/pixel375/canadamb/actions/runs/45553503749), [browser](https://github.com/pixel375/canadamb/actions/runs/45553503763) and [deployment plus independent audits](https://github.com/pixel375/canadamb/actions/runs/45553789528). This pass stages portal-scoped saved searches, shared public filters, bounded ten-search limit, per-search alert opt-in/pause, new matches since last checked and account navigation. Counts are computed on demand only; no scheduler, push, email or global unread badge change. Proposed migration and feature switch remain off.

## 2026-11-24 — production gate recovery and business changes

`ff48c94` passed exact backend/browser checks and activated; initial deployment audit reported transient HTTP 503 after activation. Read-only main audit [45553330607](https://github.com/pixel375/canadamb/actions/runs/45553330607) independently confirmed active SHA, DB and expected HTTP 403 on both domains. No access gate bypass. This source pass stages moderated branch additions/address correction and exact-email manual verification with invalidation on change. No proposed migrations applied and no feature activation; no automatic email or public verification badge.

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

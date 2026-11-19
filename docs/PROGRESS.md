# Progress

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

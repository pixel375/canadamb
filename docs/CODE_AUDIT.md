# CanadaMB source-code audit

The preservation checkpoint is [`e38b910bbead5c804c6d3c262d06ae828dfb8d5c`](https://github.com/pixel375/canadamb/commit/e38b910bbead5c804c6d3c262d06ae828dfb8d5c). The audit covers that implementation and the staging changes in this branch. A verified application capability refers to its exercised scope. Hosting acceptance is tracked separately.

## Findings by priority

| Priority | Finding | Status |
| --- | --- | --- |
| High | The account has a Git checkout under `public_html`. Direct HTTPS HEAD checks for `/.git/HEAD` and `/.env` returned 403 on both domains. Physical isolation is still required. | Quarantine the checkout at the first authorized cutover. The activation script rejects unexpected files, directories, and links. |
| High | The former activation script could migrate and seed the database selected by the shared `.env`. It also assumed a different private path and could silently replace site rewrite rules. | Staging requires a separately supplied database name, a staging marker, an exact MariaDB selection and scoped grants, two private backup files, and a reviewed preflight. Managed rules replace only the expected configuration. |
| High | Hostinger's current CLI has Sodium disabled. Passport's locked dependencies require it. | Read-only SSH confirmed the installed module loads on demand. Enable and verify it for the web and CLI before staging. |
| High | The first remote CI run failed on Linux. The OAuth callback loaded Passport keys even before rejecting forged state. The calendar test expected a 2027 Manitoba clock change. | Moved OAuth resolution after state validation. The calendar test now exercises the actual spring 2026 transition. Hostinger CLI reports UTC−05:00 in winter and summer 2027. [Initial CI run](https://github.com/pixel375/canadamb/actions/runs/44524069031) · [IANA timezone release](https://www.iana.org/time-zones/releases/2026e) |
| Medium | Administration covers the current publishing paths. Local moderator assignments, account suspension, MFA, account export and deletion, and complete audit and maintenance screens remain open. | Treat these as public launch requirements where applicable. |
| Medium | Geographic rules use canonical location IDs. They can reject an incompatible ID and prevent city-feed leakage, but a self-reported address is not verified against municipal boundaries. | Retain the distinction between selected locations, imported place names and boundary-verified addresses. |
| Medium | Scheduled news creates candidates only and uses a synchronous DNS resolver. `queue:work --max-time` is checked between jobs. | Keep live polling off for staging. The Hostinger cron wrapper locks scheduling and caps wall-clock runtime. Check the service's actual cron behavior separately. |
| Medium | PHP and SMTP behavior, shared database, and real redirects and sessions have not been verified on the deployed two-domain application. The current apex responses differ. | Record acceptance against the staged release and exact HTTPS hostnames. |
| Low | The homepage displays at most four recent discussions. The required limit is five. Featured businesses and advertising placements remain open. | Existing homepage widgets show approved records. Track the remaining specification gaps in the product backlog. |

## Area-by-area assessment

| Area | Status | Implementation evidence and gaps |
| --- | --- | --- |
| Multi-domain routing | Complete and verified | `ResolveSite` resolves exact registered active hosts, rejects unknown and inactive hosts, and enforces central-only routes. `NetworkTest` covers both site identities. Account-level alias proof remains in deployment acceptance. |
| Shared accounts and cross-domain SSO | Implemented but not fully tested | Fortify identity, Passport Authorization Code with S256 PKCE, state, exact callbacks, host-scoped sessions and network logout. `IdentityTest` verifies registration, verification, reset and invalid state. Run real TLS callback and SMTP tests on Hostinger. |
| Central administration | Partially implemented | Central host and server-side gates protect community management and several moderation queues. Navigation editing, advanced user and operations management, and local moderator administration are incomplete. |
| Global settings and inheritance | Partially implemented | `NetworkSettings` reads the active immutable revision and applies approved module overrides. Tests verify propagation and rollback. Policy, notification and commercial settings are outside the implemented schema. |
| Theme and color picker | Implemented but not fully tested | Light and dark palettes, presets, color inputs, contrast preview, CSS tokens and audited draft publish/rollback. Verify accessibility and interactive behavior on the staged build. |
| Provincial rainbow and Brandon green | Complete and verified | Separate accent data renders in the shared layout. `NetworkTest` verifies isolation and propagation. |
| Community configuration and Add Community | Partially implemented | A real draft, validation, preview, audited status transitions and manual readiness checks are tested. The create form does not provision DNS/TLS or assign local moderators and news coverage. |
| Geographic restrictions | Complete and verified | For supported self-service publishing, city branches are scoped to canonical locations. `NetworkTest`, `MarketplaceTest`, `CommunityPublishingTest`, `JobPostingTest` and `PropertyDraftTest` exercise conflicting locations or ownership. |
| Manitoba rural locations | Partially implemented | Source-attributed CGNDB loader, starter rural support and location search preserve Brandon's ID. Boundary shapes, local hierarchy, missing-location requests, radius queries and verified total coverage remain open. |
| Business directory and import | Partially implemented | Scoped public profiles, reusable licensed CSV import, deduplication, branches and moderated announcements and promotions. `BusinessDirectoryTest` verifies intake and local scope. Branch editing, stale-data operations and richer business tools are incomplete. |
| Business ownership claims | Partially implemented | A verified user submits private evidence. A separate reviewer approves claims and grants ownership atomically. Automatic business-domain verification, disputes, transfers and self-service team changes remain open. |
| News ingestion, editorial and publication | Partially implemented | Authenticated idempotent intake, feed candidate deduplication and backoff, editor review, sensitive-story checks, revisions, and published source attribution have test coverage. AI summaries and production publisher transport verification remain open. |
| Marketplace and messaging | Partially implemented | Persisted drafts, media, filters, moderation, canonical discovery, favourites, reports, conversations and blocking. Tests cover visibility, scoping and participant permissions. Advanced category administration, message report review and media retention remain open. |
| Real Estate | Partially implemented | Gated private drafts, structured fields, photos and reviewer access. Public publishing and monetization are blocked by external compliance review. |
| Jobs | Partially implemented | Employer branch ownership, moderation, search, bookmarks, expiry and structured data. Job alerts, richer employer management and featured jobs remain open. |
| Forums | Partially implemented | Location-scoped topics, replies, follows, review, pin and lock. Editing, images, per-member blocking and forum-specific report paths remain open. |
| Events | Partially implemented | Moderated listings and local calendars, date overlap and cancellation. Recurrence, ticket details, event images and featured placements remain open. |
| Notifications | Partially implemented | Forum reply notifications and account email flows exist. Other event types, preferences and production email delivery remain open. |
| Advertising and subscriptions | Missing | There is no campaign, billing, entitlement or payment workflow. This audit leaves commercial feature work for a separate milestone. |
| SEO and canonical URLs | Partially implemented | Per-domain sitemaps, canonical redirects, metadata and structured data for public modules. Production redirects and cache behavior, full breadcrumbs, and sitemap scaling remain open. |
| Security and authorization | Partially implemented | Exact hosts, Laravel CSRF, verified-account and ownership rules, upload controls, and rate limits are in use. Production secret isolation, abuse workflows, MFA and browser validation remain open. |
| Test coverage | Partially implemented | The PHP suite covers major implemented application paths. Hostinger browser, cron and deliverability acceptance and wider security and load tests remain open. |
| Production deployment | Blocked by an external dependency | SSH read-only checks ran. The staging database, hPanel web extensions, alias routing, web-root cutover, PHP web settings and real scheduler acceptance require account-level setup and verification. |

## Evidence from this pass

- The PHP suite passed 33 tests and 384 assertions on each of SQLite and disposable MariaDB 11.4.14. The guarded deployment check also accepted a real schema-scoped staging user and rejected privileged test cases.
- Pint, Vite, strict Composer validation, Composer advisory scanning and npm audit passed locally. The Linux workflow runs the application suite against both databases and rehearses the release and bounded queue using a disposable MariaDB service.
- Existing HTTPS responses had valid certificates. CanadaMB's root returned 403 and Brandon's root returned 200. The tested `.git` and `.env` requests returned 403, and the tested log request returned 404 on each domain. These are observations of the pre-staging sites.
- Hostinger deployment and application smoke are pending. The [acceptance checklist](DEPLOYMENT_CHECKLIST.md) records the outstanding browser, TLS alias, SMTP, job and rollback checks.

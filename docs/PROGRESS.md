# Progress

## 2026-11-19

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

The next implementation task is bounded news source polling with source health logs, disabled-by-default external integrations and fixture-backed retries. Then build forums and events on the same location scope and moderation patterns. Run private Hostinger staging validation before connecting an application release to automatic Git deployment.

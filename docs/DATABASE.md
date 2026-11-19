# Database

Production uses a single MariaDB database. Local development and feature tests can use SQLite. Run migrations before the starter seeder. The seeder creates stable system configuration and categories without creating demo business or listing content.

| Migration group | Purpose |
| --- | --- |
| Laravel defaults | Users, sessions, cache, queue and failed jobs |
| 2026_11_18_000001 | Site, domain, geography, settings revisions and audit |
| 2026_11_18_000002 | Marketplace categories, listings and status history |
| 2026_11_18_000003 and Passport migrations | Community client references, OAuth codes and tokens |
| 2026_11_18_230001 | Marketplace photos, favourites, reports, messaging and blocks |
| 2026_11_18_230002 | Businesses, branches, claims, memberships, updates and import provenance |
| 2026_11_19_000001 | News sources, draft articles and editorial revisions |
| 2026_11_19_000002 | Feed configuration, polling claims, candidates, and source health |
| 2026_11_19_000003 | Community forums, follows, notifications, and local events |
| 2026_11_19_000004 | Employer job postings and bookmarks |
| 2026_11_19_000005 | Private property drafts and photo metadata |

Configuration publishing locks the singleton settings pointer and swaps it to an immutable numbered revision in one transaction with its audit. Revision payloads are cached by immutable ID. The published pointer and site records are read from the database, so a visibility or theme edit takes effect across domains. Enable database cache locks and retain a tested pre-migration backup.

# Staged features and migration review

No proposed migration is automatically discovered by ordinary migrate. Read-only inspection is available in Admin → Launch readiness and `php artisan network:staged-features`. A configured flag with missing/unrecorded schema or an unmet dependency fails deployment database validation. The inventory checks table/column presence and migration history, not data integrity, nullability, constraints or operator acceptance.

Review batches in dependency order:

| Proposed migration | Scope | Approval considerations |
| --- | --- | --- |
| 000001–000002 | Commercial planning and test ledger | Financial retention, sandbox only, independent review |
| 000003–000004 | Content reports and event discussion links | Moderation and privacy; linked lifecycle |
| 000005–000007 | Business invitations, disputes, branch/contact changes | Ownership evidence and consent; no forced takeover |
| 000008 | Saved job searches | Opt-in, portal scope and personal data |
| 000009 | Conversation alteration | Review nullable foreign key, existing chat integrity, rollback; never drop inquiry history |
| 000010 | Private photos and inquiry settings | Private media backups, orphan inventory, report access; consent |
| 000011 | Privacy request intake | Staff, response procedure and fulfillment; not deletion execution |
| 000012 | Notification preferences | Existing history and follows preserved |
| 000013 | News candidate reviews | Editorial rights, retention and no auto-publication |
| 000014 | Saved Marketplace searches | Portal scope, data export and privacy |

For each approved batch: freeze the reviewed commit, rehearse schema changes on disposable SQLite and MariaDB, inspect rollback and populated-data preservation, capture verified production database and private storage backups, review the exact migration diff, and use the existing guarded release process only with explicit database approval. Preserve prelaunch allowlist, suppressed outbound mail and polling, and registration controls. Keep feature flags off until the exact recorded schema, postdeployment checks and operational acceptance are confirmed. Test all active portals and real devices separately.

Do not use migrate:fresh, direct cascades, destructive rollback, or unreviewed seeders on production. See PRIVACY_RETENTION.md and COMMERCIAL_SCHEMA_REVIEW.md for special considerations. Recurring event submissions use a separate default-off switch and existing event schema.

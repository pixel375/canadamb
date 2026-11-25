# Privacy and retention review

The proposed account privacy request table records password-confirmed deletion or data-access requests. Super administrators can record review progress; claimants can withdraw. This is intake, not fulfillment. Reviewers can filter requests and explicitly inspect aggregate account record counts for an open request. The read-only inspection is audited, omits record contents and never deletes or disables an account. Do not enable until a monitored support route, responsible reviewer, response deadlines, retention rules and a method of recording fulfillment are agreed.

Never delete a user by a raw cascade. Inspect business ownership, active listings, shared conversations, reports, pending disputes, commercial records, media, sessions, OAuth tokens and backups. Establish legal holds and minimization rules before a reviewed execution design. Preserve other participants' legitimate access where appropriate and do not expose their personal records in self-service exports. Do not promise historical deletion from backups without a verified backup expiration and restoration procedure.

Run `php artisan messages:attachment-inventory --limit=500` for aggregate counts of missing references, unexpected entries and files older than 48 hours with no matching attachment row. This is bounded, not exhaustive; truncation flags explicitly signal incomplete scans. A candidate is not approval to delete. Pause writes, verify database and private media backups, check reports/holds and inspect a full reconciliation before any future cleanup. Do not schedule deletion. No filenames or private bodies are printed.

The dedicated attachment disk is inside shared private storage. Keep private storage out of public roots. Review orphan cleanup following account erasure and attachment-specific moderation before enabling uploads.

Exports are password-confirmed, allowlisted and limited to 500 current records per request; bookmark cursors use the referenced ID. Optional sections appear only when their table exists. Internal notes, security credentials, received messages, files and third-party records are excluded. Full access requests remain separate.

The self-export includes participant-local conversation metadata and sent-photo metadata (IDs and dates only), excluding the other participant's read/archive state, identity fields, message body and storage paths. Privacy impact counts now include bookmarks, follows, notification settings, blocks, advertising authorship, inquiry preferences and owned listing/property photos. These counts do not authorize erasure or demonstrate backup deletion.

### Photo metadata exports

Member exports include the identifiers and display order of their own Marketplace and private property photographs. No filesystem paths, thumbnails, image bytes or other members' photographs are included. Continuation downloads include the record cursor in the filename. Historical values of business change requests are excluded from self-service exports; the submitted value and status remain available.

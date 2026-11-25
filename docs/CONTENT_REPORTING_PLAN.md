# Broader content reporting — review-staged

The existing Marketplace report workflow stays intact. Help pages explain the current limitation and direct members to configured contact channels.

## Proposed additive migration
- New content_reports table with typed target, reporter FK, site FK, target record ID, structured reason and bounded explanation, status, created and resolved timestamps; unique reporter/target pair.
- Use a fixed target-type allowlist (discussion, reply, event, job, business, business update, profile, message). No arbitrary class names or dynamically chosen tables from user input.
- Public target reports require the same visibility and geographic scope as viewing the target. Message reports require conversation participation; do not provide administrators an unrestricted inbox or export private threads.
- Reviewer access is separately authorized, central-only, with an audited action trail. Do not disclose reporter identity to reported accounts. Minimize stored evidence and establish retention before launch.
- Keep existing listing_reports intact initially. Implement per-target resolution and reporting status, rate limiting and duplicate prevention. Design deletion behavior and evidence retention explicitly.
- Add authorization, cross-community isolation, unpublished target, duplicate, race, message participant, retention and both SQLite/MariaDB migration tests.
- Review migration SQL and rollback/restore evidence before allowing database changes. The guarded production workflow defaults to no migrations; do not change this default or apply schema changes implicitly.

Implemented in the review-staged release: discussions, approved replies to approved threads, and approved/cancelled events, with owner-private history and network-admin resolution. The proposed migration is outside normal migration discovery; CANADAMB_CONTENT_REPORTS defaults false. Resolution is separate from content moderation and no private message data is collected. Jobs, business profiles and approved business updates are now included. Member profiles and private messages are addressed in the follow-up below. Contact guidance remains the fallback until activation.

Member profiles and received Marketplace messages are now covered by the same proposed schema. Profiles and private inboxes are network-wide, consistent with their existing visibility. Self-reports are rejected for these two types. Only a recipient can report a received message, including after blocking; reviewers see only that reported message, never the surrounding conversation. Both conversation participants are excluded from private-message report queues and resolution, even if they are network admins. Missing message/conversation evidence fails closed; orphan handling and retention remain a launch review requirement. No message text is copied into the audit trail or report table. The feature remains default-off and requires reviewed migration activation.

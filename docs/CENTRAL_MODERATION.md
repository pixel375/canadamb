# Central moderation and reporting

A verified network administrator or super administrator can open `/admin/moderation` on CanadaMB. The workbench reviews submissions province-wide. The location filter limits the displayed queue and source counts. Community hosts return 404 for central administration routes.

| Submission | Review checks | Member outcome |
| --- | --- | --- |
| Marketplace listing | Current listing and photo order. Listings with photos require individual review. | Seller receives approval, rejection or removal. |
| Business profile | Current profile version. An active profile can be removed from public view. | Current owners receive the decision. |
| Business claim | Independent reviewer, active profile and no existing owner before approval. Individual review only. | Claimant receives the decision. |
| Business update | Current submission, active parent and current author membership before approval. | Eligible author receives the decision. |
| Job | Current posting, active employer and valid closing date before approval. | Eligible author receives the decision. |
| Discussion thread | Current thread, with pin and lock controls for approved discussions. | Author receives publication decisions. |
| Discussion reply | Current reply and an approved parent under lock before approval. | Author receives the decision. Eligible followers receive the existing reply notice. |
| Event | Current submission and an event that has not ended before approval. | Author receives the decision. |

Open a row to read the submission and its decision history. Existing review screens use the same decision actions. When rejecting or removing content, enter an explanation suitable for the affected member. Keep investigation details and reporter information in internal notes. The member inbox displays the short reason only for the authorized outcome tied to that decision.

Same-type batches take up to 25 pending submissions. The preview records the selected IDs and versions for the reviewer. Each decision has independent validation, a transaction and a result. Open changed submissions to review their latest version. Reuse the original owner resubmission workflow to restore content where it is supported.

## Reports and evidence

The Reports queue includes existing listing and conversation reports plus reports on eligible business profiles and updates, jobs, threads, replies and events. Reports use the submitting portal's visibility rules, rate limits and deduplication. A business profile report submitted on CanadaMB covers the profile as a whole and appears under each location with a current branch. A city submission retains its city attribution. Earlier reports retain their recorded locations. A report decision leaves the content's publication state unchanged.

A CSRF-protected **View reported evidence** action discloses the report submission and, when eligible, the specifically reported message. The audit records the actor, report ID, message ID and time. The reviewer cannot be a participant. Reporter names, unrelated messages and attachment details or bytes stay outside this view. Evidence responses use `private, no-store` and `noindex`. Resolve or dismiss reports with a private note. Report decisions generate no notices for reported members.

## Approval policies

Use **Global settings** to draft, preview and publish approval modes. All types default to manual review. Only a verified super administrator can publish or roll back changes to automatic approval. A previewed revision must still be current when published. Community overrides can restrict the network allowance. Business identities and claims, Marketplace photos and Marketplace resubmissions go through manual review. The setting applies to future submissions after validation, authorization and portal checks.

## Schema and recovery

- `2026_11_22_000002_create_moderation_decisions.php` creates indexed `moderation_decisions`, references `audit_logs`, and stores source ID, action, member-facing reason and private notes. The notification source index ties a reason to its exact inbox event or listing status-history record.
- `2026_11_22_000002_create_moderation_reports.php` creates `content_reports` and `moderation_report_decisions`. The first uses a unique source type, source ID and reporter key. The second uses a unique report type and report ID key for new and legacy reports, and references the audit record.
- The upgrade creates three empty tables and preserves existing report IDs, listing history, messages, read markers and media. The disposable rehearsal compares existing rows and DDL before and after the upgrade and restores SQL and private storage separately.
- MariaDB can implicitly commit DDL. Keep a reviewed backup for recovery after a partial upgrade. The down migrations refuse to drop recorded moderation data. Use the release runbook to review restoration or a forward repair before changing the active release.

## Allowlisted acceptance after authorization

1. Confirm normal members are denied central administration and community hosts return 404.
2. Review a listing with photos and a discussion reply. Verify queue counts, decision history and owner notifications. Try a decision from a stale review form.
3. Preview a same-type batch, change one submission, and check the per-item result.
4. Report a specific conversation message. Verify the audit, exact-message evidence and denial of the ordinary attachment route to reviewers outside the conversation.
5. Test the admin menu, filters and decisions on Android and iOS, in dark and light modes. Keep all live approval policies on manual review.

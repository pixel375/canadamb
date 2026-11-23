# Business directory imports

The directory distinguishes a business from its physical branches. Existing businesses, ownership, legacy importer history, jobs and private conversation references keep their IDs. Imported rows can identify an organization with an explicit source-scoped group key. Name and address matches are evidence for operator review.

The implementation uses private database staging with a reviewed plan and a single atomic apply transaction. A separate import lock serializes reviewed applications, and unique source identity and branch constraints provide a second check. Keeping the transaction atomic means a failure leaves every directory row untouched. It also makes very large plans an off-peak CLI operation on shared hosting. No new scheduled ingest or external fetch is configured.

Migration `2026_11_22_000004_create_reviewed_business_imports.php` adds source revisions and approval, indexed name and branch lookups, source-owned field hashes, snapshot timestamps, plan review fields, and the `business_import_identities`, `business_import_rows` and `business_import_mutex` tables. It fills only new lookup columns on historical business and branch records. Legacy source records start unapproved. If reviewed source or import history exists, downgrade is refused.

Review the exact schema on the actual deployment baseline. MariaDB DDL implicitly commits and can lock hot tables. Preserve SQL, OAuth keys and private storage before any approved change. Rehearse restore into a fresh schema before cutover. A failure after DDL may require rolling forward with compatible code or restoring the verified backup. The Verify workflow exercises the exact populated Pass 07 baseline, default upgrade denial, a read-only SQL preview, preservation and restore, competing imports, and 5,000 synthetic branches. None of those fixtures are production data.

Operator instructions and legal-source review requirements are in [Business imports](BUSINESS_IMPORTS.md). Conflicting identities can be reviewed individually. Existing records are never merged or reparented by this workflow.

## Manual ownership claims

A verified member can submit an initial claim for an unclaimed approved profile, revise a pending claim, or withdraw it from My businesses. Reviewer access to individual evidence is logged. New evidence and prior revision snapshots use the application encryption key. Historical submissions remain readable. Keep the existing application key and its backup.

Work through the central moderation workbench. Check an independent source for the business identity and verify that the claimant can act for that business. Record a private reference and confirmation. Matching a name or email domain alone is insufficient. The claimant and current business representatives cannot decide the claim. Approval rechecks the account and takes the business lock before assigning ownership. The member gets the existing claim outcome notice.

## Original ownership handoff (historical, superseded by the Pass 09 continuation below)

Continue from the final head of [PR #26](https://github.com/pixel375/canadamb/pull/26). Use Issue #25 for the remaining scope. Before adding any forms, define versioned dispute, transfer, staff acceptance and branch-change transitions that take the business lock before writing child records. Keep existing claim IDs and membership rows. Any transfer should require a verified successor and independent review and must preserve at least one authorized owner.

When those transitions exist, exercise them against the existing `ConversationAccess`, `MessageContact` and `JobBoard` paths. Test immediate revocation, historical thread isolation, explicit consent for new message contacts and conservative handling of jobs and updates at closed branches. Run the populated upgrade and recovery rehearsal on the revised exact SHA before requesting separate schema review. Wider Jobs and Messaging redesign is outside this pass.


## Staff membership continuation (review branch)

Pass 09 continuation adds a private, consent-based staff invitation workflow. An existing verified **owner** can nominate an existing verified account ID as `manager` or `editor`; invitations expire after seven days and require the recipient to accept while signed in. No external email is sent. Ownership itself cannot be invited or transferred using this workflow. Rejected, expired and revoked invitations retain an audit trail.

Owners manage staff at `/account/businesses/{id}/team`; recipients review invitations at `/account/business-invitations`. A city portal displays only invitations from businesses with a local branch. All mutation endpoints require verified sessions and CSRF; current ownership and business state are rechecked while holding the business row lock.

Removing a manager or editor preserves business IDs, source records, existing jobs, private conversations and their historical sender/recipient identity. Membership-based access disappears immediately for the removed representative, and their branch/job message-contact designation is cleared in the same transaction. Another owner does **not** inherit the former representative's conversation history. A separate reviewed ownership transfer/dispute workflow now exists on the stacked continuation branch; staff invitations still cannot grant ownership.

Migration `2026_11_23_000001_create_business_team_invitations.php` adds one table, without changing existing membership rows. Its rollback refuses to erase invitation history. This is unmerged review work and requires explicit schema approval in release order.


## Pass 09 ownership and branch review continuation (unreleased)

[Business ownership, disputes, staff permissions and branch review runbook](BUSINESS_OWNERSHIP.md) now describes the implementation in PR #27. It includes verified member consent, independent moderator approval, encrypted evidence, two-reviewer resolution of confirmed ownership holds, and owner-submitted branch corrections/closures. All incoming source IDs, historical jobs, conversations and previously approved business data are preserved. The public branch status reflects only independently approved operational changes; merely submitting a request never creates a location or changes its closure state. This code is **not** merged/deployed. Schema review, current exact-head CI, real-browser acceptance and owner authorization remain release gates.

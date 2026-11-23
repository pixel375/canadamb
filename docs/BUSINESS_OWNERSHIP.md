# CanadaMB Business ownership and branch review — prelaunch runbook

**Status:** Source-controlled review only, stacked [PR #27](https://github.com/pixel375/canadamb/pull/27) above [PR #26](https://github.com/pixel375/canadamb/pull/26). The reviewed implementation has **not** been merged, released, or approved for production schema changes. All flows are private to verified members and network administrators. No real mail or public launch is included.

## Member staff management
- An already verified business owner can propose a verified existing account as `manager` or `editor` using `/account/businesses/{business}/team`. The nominated member must explicitly accept at `/account/business-invitations`. Staff invitations expire after seven days.
- The owner may revoke a pending invitation or remove a staff representative. The business row is locked before role changes. Revocation clears that member's opted-in branch and job message-contact assignments, but does **not** reassign any previous conversation or attachment to somebody else.
- The owner role is never granted from a staff invitation. A confirmed independent dispute hold pauses staff and owner-managed profile/job edits while continuing existing private messaging.

## Ownership transfers and disputes
- Existing owner opens a proposed transfer using a verified successor ID and privately encrypted supporting evidence. The successor must **accept** under their own account, then a different authorized administrator must independently verify authority before approving. Only the current outgoing owner is removed, in the same transaction that establishes the successor as an owner. Other owners and unrelated staff remain.
- The outgoing owner's existing branch/job message-contact opt-ins are disabled on approval; historical conversations retain the original participant IDs and permissions. Pending staff invitations issued by the outgoing owner are revoked; none silently move to the successor.
- Someone concerned about an already claimed business may file private evidence from its directory page. Filing an unverified concern only creates a review entry, **not** a role freeze or automatic transfer. An independent administrator can dismiss it or explicitly establish a hold. A **different** authorized administrator with a separate proof reference must release a confirmed hold.
- A transfer cannot be approved while a dispute is pending or held. Audits store IDs and encrypted proof references, not raw personal evidence. Invalid or outdated review versions fail with a conflict rather than overriding newer state.

Member cases: `/account/ownership-cases`. Administrative review queue: `/admin/ownership-cases`, linked from Moderation and Business review. The existing Pass 05 moderation decision ledger records all completed decisions. Ownership cases use a dedicated private review queue: full union/filter integration into the central mixed-content queue remains a future enhancement.

## Reviewed branch changes
- An owner can submit a **new Manitoba branch**, **physical address correction**, **temporary or permanent closure**, or **reopening** at `/account/businesses/{business}/branches`. All require private supporting evidence, verified identity and a currently active business. Requests have independent stale-version checks and cannot be auto-approved merely because the submitter owns the listing.
- Administrators review cases at `/admin/business-branches`. The reviewer must not be the requester or a business representative. Address changes and closures require an independently verifiable reference; competing changes or duplicate physical addresses must be rejected. The reviewer is responsible for independently confirming the correct physical location, right to represent the business and closure/reopening status.
- Creating a branch preserves all existing business IDs. Correcting an address keeps source IDs, source branch keys, import provenance, business membership, existing jobs, and previous conversation IDs. It does **not** silently relocate a branch to another town or merge two businesses. A proposed town change must be addressed through a separately verified new branch/closure process.
- Closure sets `business_locations.operational_status` to `temporarily_closed` or `closed`. The public directory retains the historical branch with a truthful status label; new branch/job message-contact starts and fresh posts are blocked. Existing conversations, previously approved updates and jobs are retained. The opted-in new-message contact assignments are cleared, not moved to another staff account. Reopening does **not** restore contacts automatically; an eligible representative must opt in again.

## Production safety and recovery

The continuation introduces only these **new, unapplied** schema changes, on top of all already-reviewed earlier passes:

1. `2026_11_23_000001_create_business_team_invitations.php` — additive private invitation history table.
2. `2026_11_23_000002_create_business_ownership_cases.php` — additive private transfer/dispute case history.
3. `2026_11_23_000003_create_business_branch_requests.php` — additive reviewed change history plus a non-destructive `operational_status` column on existing branches, default `open`.

The migration down paths intentionally refuse to erase actual invitations, ownership history, branch requests or non-open status. A forward fix or separately verified SQL + media restore may be required because MariaDB DDL is not transactional.

Before any authorized release: review the exact stacked SHA and SQL/index/FK plan; confirm previous 02/04/05/07/08 migration sequence; take verified production database **and private media** backups, and restore to an isolated schema; run approved-host / wrong-host and privacy checks on a real test device; review encrypted evidence readability with the existing application key; confirm default deployment denial remains enabled; secure explicit approval for the exact SHA. Never move/apply migrations on the live database, change the IP gate, SMTP or public launch setting from this review branch.

The automated GitHub CI exercises both SQLite and MariaDB, source-control formatting, Vite and dependency audits, and a disposable populated Hostinger upgrade rehearsal with default migration denial and backup restoration. These synthetic checks do **not** substitute for legal verification of an actual business's authority, real-browser acceptance, or a production release approval.

## Deliberate exclusions
- No automatic ownership from an email domain, a logo, a data importer or a manually typed claim.
- No owner-to-owner transfer without successor acceptance and independent review.
- No deletion/merging of approved businesses, imported branches, jobs or historical conversations.
- No reassignment of archived customer conversations to a new owner or manager.
- No change to registration, Passport SSO, real email delivery or the prelaunch IP restrictions.

# Business directory imports

The directory distinguishes a business from its physical branches. Existing businesses, ownership, legacy importer history, jobs and private conversation references keep their IDs. Imported rows can identify an organization with an explicit source-scoped group key. Name and address matches are evidence for operator review.

The implementation uses private database staging with a reviewed plan and a single atomic apply transaction. A separate import lock serializes reviewed applications, and unique source identity and branch constraints provide a second check. Keeping the transaction atomic means a failure leaves every directory row untouched. It also makes very large plans an off-peak CLI operation on shared hosting. No new scheduled ingest or external fetch is configured.

Migration `2026_11_22_000004_create_reviewed_business_imports.php` adds source revisions and approval, indexed name and branch lookups, source-owned field hashes, snapshot timestamps, plan review fields, and the `business_import_identities`, `business_import_rows` and `business_import_mutex` tables. It fills only new lookup columns on historical business and branch records. Legacy source records start unapproved. If reviewed source or import history exists, downgrade is refused.

Review the exact schema on the actual deployment baseline. MariaDB DDL implicitly commits and can lock hot tables. Preserve SQL, OAuth keys and private storage before any approved change. Rehearse restore into a fresh schema before cutover. A failure after DDL may require rolling forward with compatible code or restoring the verified backup. The Verify workflow exercises the exact populated Pass 07 baseline, default upgrade denial, a read-only SQL preview, preservation and restore, competing imports, and 5,000 synthetic branches. None of those fixtures are production data.

Operator instructions and legal-source review requirements are in [Business imports](BUSINESS_IMPORTS.md). Conflicting identities can be reviewed individually. Existing records are never merged or reparented by this workflow.

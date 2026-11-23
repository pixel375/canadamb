# Business directory imports

The directory distinguishes a business from its physical branches. Existing businesses, ownership, legacy importer history, jobs and private conversation references keep their IDs. Imported rows can identify an organization with an explicit source-scoped group key. Name and address matches are evidence for operator review.

The implementation uses private database staging with a reviewed plan and a single atomic apply transaction. A separate import lock serializes reviewed applications, and unique source identity and branch constraints provide a second check. Keeping the transaction atomic means a failure leaves every directory row untouched. It also makes very large plans an off-peak CLI operation on shared hosting. No new scheduled ingest or external fetch is configured.

The initial schema checkpoint introduces source approval revisions, indexed normalized lookups, private staged rows, provenance and an apply lock. The migration fills only new lookup columns on historical records. Legacy source records start unapproved. If reviewed source or import history exists, the migration refuses a downgrade. The disposable migration rehearsal must verify all historical fields against the populated Pass 07 baseline before production review.

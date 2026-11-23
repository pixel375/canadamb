# Business imports

Run imports from a private SSH terminal as a verified CanadaMB network or super administrator. The commands authenticate with your account ID and a hidden password prompt. They read a local CSV file and never fetch a source or its website. Start on a disposable database after the reviewed migration.

## Review the source first

Independently verify that the publisher permits directory publication and that the file contains business information suitable for public display. Record the authoritative origin, the reuse statement, the terms or permission URL and the vintage. The registry records an operator's approval. It does not verify the license or its legal sufficiency.

```bash
php artisan businesses:sources register --name="Publisher" --url="https://example.test/register" \
  --license="Documented redistribution permission" --terms="https://example.test/reuse" --vintage=2026-01-01
php artisan businesses:sources show 3
php artisan businesses:sources approve 3 --revision=1
```

Use the actual ID and revision printed by the command. Revise metadata with `businesses:sources revise ID --revision=N` and the replacement origin, license, terms and vintage flags. Revision or suspension clears approval and invalidates staged plans. A legacy source must be revised with complete evidence before approval. Existing published entries stay available.

## Stage, review and apply

Use a UTF-8 CSV with an optional BOM and one physical line per record. Excel and other spreadsheet formats require a separate CSV export. Headers are case sensitive.

```csv
external_key,name,category,location,address,website,business_key
west-branch,Example Company,retail,brandon,1 Example Avenue,https://example.test,company-1
east-branch,Example Company,retail,elkhorn,2 Example Road,https://example.test,company-1
```

The seventh column is optional. A stable branch key identifies one physical branch within the source. A stable business key identifies an organization within the same source. Provide it only when the publisher or independently reviewed evidence establishes that the branches belong together. The group must agree on normalized name, category and website. The location is a selectable Manitoba slug. Key characters are ASCII letters, numbers, dots, underscores, colons and dashes. Leading and trailing whitespace is trimmed and the keys are lowercased. The original spelling of historical keys remains in the database. Blank website entries are allowed. The limit is 20,000 rows, 16 MB per file and 4,096 bytes per physical line.

```bash
php artisan businesses:import /private/businesses.csv --source-id=3
php artisan businesses:import --review=15 --decision=identity_conflict
php artisan businesses:import --review=15 --export > /private/review-15.csv
php artisan businesses:import --resolve=15 --line=4 --action=link --business=81 --token=REVIEW_TOKEN
php artisan businesses:import --apply=15 --token=LATEST_REVIEW_TOKEN
```

The review shows dispositions, row numbers, candidate business IDs and existing or resulting IDs. The commands prompt for a review reference when it is required. Keep the original input private and use the listed line numbers to inspect it. A plan expires after 24 hours. A correction or reviewer decision requires the latest printed token. Inspect a newly rebuilt plan before applying. The six-column invocation with `--source`, `--license` and `--url` still works for staging. It creates a draft source if necessary and never publishes by itself.

Name matches need explicit evidence. With `--action=link`, use a known matching business ID. Use `--action=distinct` only when evidence shows a separate organization. Both decisions apply to the group of the reviewed row. Use `--action=skip` to exclude one invalid or unresolved row from an incremental plan. Correct the original CSV and restage when in doubt. The importer never reassigns a branch, merges existing profiles, moves a branch between municipalities, or takes over another source's physical branch. Conflicting identities and claimed businesses remain visible in conflict reports. A reviewer may skip conflicts and handle them through a separate directory correction.

A new profile receives an unclaimed status and goes live only when the reviewed plan is explicitly applied. Rights approval, the authenticated apply actor, input SHA-256, row dispositions and resulting IDs remain in the database. Existing claimed or staffed profiles, pending claims, verified addresses and owner-managed fields are protected. Writes to an address or organization website require an unchanged source-owned baseline. Existing slugs and relations are retained. A group can appear in several communities, while a city detail shows that city's branches.

Use `--full-snapshot` only for genuinely complete input. A full snapshot cannot skip rows and only flags omitted branches as stale. It never deletes or unpublishes them. Incremental plans leave omissions untouched. After a run expires and another day passes, `businesses:import --purge` removes normalized private payloads and keeps the provenance records. Original files and exports are operator-managed outside the web root, subject to the same review retention and backup permissions.

## Shared-hosting release and recovery

The [foundation runbook](BUSINESS_FOUNDATION.md) covers the schema and deployment checks. Plan and apply run in CLI PHP, with chunked reads and inserts and one transaction per apply. The import lock serializes writes across import operators. Plan preparation keeps business data read-only. Import in off-peak windows because revalidation locks affected records until commit. Avoid enabling database query logging during real imports. Inspect real server timings and lock pressure with a disposable dataset before attempting the maximum file size.

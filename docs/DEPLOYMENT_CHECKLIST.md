# Hostinger production prelaunch acceptance

## Pass 04 messaging release checks

- [ ] Confirm Verify CanadaMB is green for the exact reviewed SHA. Check the disposable Pass 03 to Pass 04 MariaDB rehearsal for the default denial, previewed SQL, preserved legacy data, isolated SQL and storage restore, and concurrent send result.
- [ ] Obtain explicit approval for the reviewed migration on the named production database before promotion. Keep the migration input at its default `false` until that approval. Record the source SHA and backup checksums.
- [ ] Verify shared private storage has room for attachments and the database and media backups. Confirm web PHP upload limits support 5 MiB per file and a 12 MiB message plus multipart overhead. The Pass 03 Marketplace upload limits also cover this size.
- [ ] Confirm the guarded release pauses writes and cron, takes SQL and storage backups, records the matching migration preflight, and runs the reviewed migrations. Verify the active SHA and independent deployment and external prelaunch audits.
- [ ] Complete the [allowlisted messaging browser checks](UNIFIED_MESSAGING.md#allowlisted-browser-checks) before recording owner acceptance.

## Pass 03 Marketplace release checks

- [ ] After the approved Pass 02 release, retarget or rebase the stacked Marketplace PR and confirm exact-commit CI and the media backup. Pass 03 has no new schema changes.
- [ ] Verify PHP's effective web upload limits allow five 4 MB files and multipart overhead (`upload_max_filesize` at least 4 MB, `post_max_size` at least 22 MB, and `max_file_uploads` at least 5), with GD JPEG, PNG and WebP support and sufficient memory.
- [ ] From allowlisted desktop and phone browsers, sign in from a Brandon Marketplace action and return to it from CanadaMB. Post five portrait and landscape photos, save an edit without changing them, then retain, remove, replace and reorder individual photos. Test common iPhone and Android JPEG orientations on actual devices.
- [ ] Check keyboard, touch, narrow-width and installed-PWA gallery and form behavior on both domains. Confirm current-host browsing, one canonical URL, Brandon-only results, in-app seller contact, hidden private photos, and rejection feedback visible only to the owner.
- [ ] Confirm the existing minute hPanel cron invokes the Laravel scheduler and observe an authorized disposable listing at the next due hourly expiry. Verify one status-history entry and one inbox notice across repeated execution. Record the live SHA and independent deployment and prelaunch/PWA audit evidence.

## Pass 02 member settings and inbox release checks

- [ ] Confirm Verify CanadaMB is green for the reviewed SHA. Check the Pass 01 to Pass 02 MariaDB rehearsal output for the previewed SQL, preservation of legacy read/unread rows, expected indexes, and successful isolated restore of the SQL and storage backups.
- [ ] Review the two additive migrations and obtain explicit production database-change approval for the exact target SHA and database. Keep the PR open until that approval and the guarded promotion plan are in place. Leave the automatic migration input at its default `false`.
- [ ] Record the current release and backup checksums. Confirm the guarded release pauses cron and writes, backs up the database and storage, and records a matching migration preflight before activation.
- [ ] Verify the live SHA and applied migrations, independent deployment and external PWA/prelaunch audits, and existing notification read state using the approved [owner checks](MEMBER_NOTIFICATIONS.md#allowlisted-owner-checks). Preserve Pass 01 acceptance as a separate check.

## Pass 01 authentication release checks

- [ ] Confirm the exact merged SHA passed Verify CanadaMB, guarded Hostinger deployment with database changes disabled, and both independent postdeploy audits.
- [ ] Confirm the published tester-registration switch is off and remains off after release. The approved connection receives 404 for central registration, and unapproved connections receive 403 for both hosts' application and auth routes.
- [ ] Complete the [approved-network desktop and phone authentication walkthrough](AUTHENTICATION.md#pass-01-prelaunch-acceptance). Use a separate administrator profile, then turn the registration switch off again and publish. Keep verification and password reset email disabled in prelaunch.

Record the reviewed SHA, operator, timestamp, result and evidence for each step. Use controlled test accounts and track test record IDs. Approval to activate restricted prelaunch and approval to open the site to everyone are separate steps.

## Marketplace first functional UX release

- [x] Green CI for active SHA `0b2359f5eb4587f9fd5dfad1b36402df2b7d5349` with double database-engine tests, Vite and full Hostinger release rehearsal.
- [x] Guarded live deployment and independent audit passed ([deploy](https://github.com/pixel375/canadamb/actions/runs/44643501263), [audit](https://github.com/pixel375/canadamb/actions/runs/44643771924)); production SQL/media backup, OAuth keys, original public root inode, hPanel cron and IP gate preserved.
- [x] Backend test covers two-photo original/thumb size, browser template endpoints, replacement uploads/deletion, and provincial browsing with a city canonical.
- [ ] On an allowlisted computer and phone, verify image cards, detail gallery lightbox Escape/arrow/swipe/focus, long/tall/wide item photos, sign-in and message seller, owner edit/mark sold, same-domain browsing, category filters and related listing scope.
- [ ] Verify five-image maximum, invalid uploads, edit behavior with no replacement, and image orientation on real devices; remove/keep known test listings deliberately.
- [ ] Full UX redesign remains unstarted; do not open public registration or crawlers without separate authorization.

## Subsequent deployments — first updates verified

- [x] GitHub Actions SSH authentication and private upload verified without changing site or database.
- [x] Manual-only update workflow created on default GitHub branch; no push-to-deploy or Hostinger Git mirror enabled.
- [x] Upgrade rehearsal passed on a populated disposable MariaDB with sample user data, preserved OAuth keys and same public_html inode (run 44622393391).
- [x] Downloaded and restored actual production SQL into isolated MariaDB (53 tables, 1 user, 3 listings, 2 domains; run 44630938711). Uploaded media snapshot archive also created/verified in release; separately rehearse file-level media restoration if needed.
- [x] Owner added the Hostinger every-minute hPanel cron job and [Hostinger cron diagnostic run 44635981084](https://github.com/pixel375/canadamb/actions/runs/44635981084) confirmed private scheduler log updates (49 seconds old at inspection), run/completion messages and no detected error lines.
- [ ] Require an exact green implementation SHA and review migration/seeder differences; migration input OFF by default.
- [x] Owner gave YOLO approval; first live update and independent postdeploy infrastructure audit passed (runs 44631266297, 44631660770).\n- [ ] Manual allowlisted provincial/city browser, admin, listings and SSO smoke for new SHA.
- [ ] Only after repeated green updates consider more automation.

## Before activation

- [x] Verify the SSH host key, PHP CLI 8.3.36 and Sodium, Composer 2.9.8 and platform requirements.
- [x] Verify HTTPS, the same physical document root and PHP probe file, and preserved Host headers on both domains. Confirm web PHP 8.3.36 loads Sodium and other required extensions, supports WebP and has display errors disabled. The temporary probe was removed.
- [x] Verify the existing production MariaDB 11.8.9 connection, no tables/views, and database scope limited to that exact schema. Inventory the clean Git checkout under `public_html`.
- [ ] Select the reviewed implementation-branch SHA and its green CI artifact. Review migration SQL and schema effects using the disposable Linux/MariaDB run.
- [ ] Copy the private `.env` once into the release shared directory, preserve original values, add a stable application key if absent, and set exact tester IPs. Keep Git auto-deployment and full-page caching disabled.
- [ ] Prepare privately, take production DB and storage backups, rehearse restore in an isolated database, and complete read-only migration preflight. Record explicit production activation approval for the exact SHA and DB.
- [ ] Quarantine the old checkout outside `public_html`. Activate the reviewed release with the public allowlist, confirm private environment, logs, uploads and keys, then bring up the release for approved testers.

## Both real HTTPS domains after activation

- [ ] On an allowlisted network, run `CANADAMB_SMOKE_PRELAUNCH=1 bash deploy/smoke.sh`. Verify the provincial CanadaMB directory and the Brandon-only portal, valid TLS, correct host-specific canonical URLs and assets, and independent rainbow and green accents.
- [ ] From a different network, verify both sites deny prelaunch pages and write requests, including requests with forged forwarding headers. Verify `robots.txt` disallows crawling and successful pages use noindex and no-store. Registration stays unavailable for testers too.
- [ ] Sign in from Brandon through CanadaMB with a controlled administrator and verify the HTTPS return callback. Verify central admin routing, sign-out everywhere, distinct host-only Secure/HttpOnly SameSite=Lax cookies, and a rejected invalid OAuth state. Test valid, missing and invalid CSRF evidence.
- [ ] Publish a temporary global theme change and verify both portals receive it without changing their accent colors. Restore the prior settings. Create a controlled Brandon listing and verify the same record appears in both feeds with the Brandon canonical URL. Verify city scope rejects a conflicting location and track any test records for removal.
- [ ] Verify `.env`, `.git`, private storage and framework source remain inaccessible from both domains, and application failures return generic errors while private logs record details.
- [x] Owner hPanel cron executes and consumes a controlled queued marker job without manual worker invocation; Laravel reported no failed jobs (run 44642175328). Keep live feed discovery disabled and application mail isolated. Payments and public real estate publication remain unavailable.
- [ ] Before public launch, remove test data, agree on mail delivery, and explicitly approve disabling prelaunch. Recheck both portals and the resulting registration behavior.

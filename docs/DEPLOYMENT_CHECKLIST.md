# Hostinger production prelaunch acceptance

## Pass 01 authentication release checks

- [ ] Confirm the exact merged SHA passed Verify CanadaMB, guarded Hostinger deployment with database changes disabled, and both independent postdeploy audits.
- [ ] Confirm the published tester-registration switch is off and remains off after release. The approved connection receives 404 for central registration, and unapproved connections receive 403 for both hosts' application and auth routes.
- [ ] Complete the [password-unlocked desktop and phone authentication walkthrough](AUTHENTICATION.md#pass-01-prelaunch-acceptance). Use a separate administrator profile, then turn the registration switch off again and publish. Keep verification and password reset email disabled in prelaunch.

Record the reviewed SHA, operator, timestamp, result and evidence for each step. Use controlled test accounts and track test record IDs. Approval to activate restricted prelaunch and approval to open the site to everyone are separate steps.

## Marketplace first functional UX release

- [x] Green CI for active SHA `0b2359f5eb4587f9fd5dfad1b36402df2b7d5349` with double database-engine tests, Vite and full Hostinger release rehearsal.
- [x] Guarded live deployment and independent audit passed ([deploy](https://github.com/pixel375/canadamb/actions/runs/44643501263), [audit](https://github.com/pixel375/canadamb/actions/runs/44643771924)); production SQL/media backup, OAuth keys, original public root inode, hPanel cron and IP gate preserved.
- [x] Backend test covers two-photo original/thumb size, browser template endpoints, replacement uploads/deletion, and provincial browsing with a city canonical.
- [ ] On a password-unlocked computer and phone, verify image cards, detail gallery lightbox Escape/arrow/swipe/focus, long/tall/wide item photos, sign-in and message seller, owner edit/mark sold, same-domain browsing, category filters and related listing scope.
- [ ] Verify five-image maximum, invalid uploads, edit behavior with no replacement, and image orientation on real devices; remove/keep known test listings deliberately.
- [ ] Full UX redesign remains unstarted; do not open public registration or crawlers without separate authorization.

## Subsequent deployments — first updates verified

- [x] GitHub Actions SSH authentication and private upload verified without changing site or database.
- [x] Manual-only update workflow created on default GitHub branch; no push-to-deploy or Hostinger Git mirror enabled.
- [x] Upgrade rehearsal passed on a populated disposable MariaDB with sample user data, preserved OAuth keys and same public_html inode (run 44622393391).
- [x] Downloaded and restored actual production SQL into isolated MariaDB (53 tables, 1 user, 3 listings, 2 domains; run 44630938711). Uploaded media snapshot archive also created/verified in release; separately rehearse file-level media restoration if needed.
- [x] Owner added the Hostinger every-minute hPanel cron job and [Hostinger cron diagnostic run 44635981084](https://github.com/pixel375/canadamb/actions/runs/44635981084) confirmed private scheduler log updates (49 seconds old at inspection), run/completion messages and no detected error lines.
- [ ] Require an exact green implementation SHA and review migration/seeder differences; migration input OFF by default.
- [x] Owner gave YOLO approval; first live update and independent postdeploy infrastructure audit passed (runs 44631266297, 44631660770).\n- [ ] Manual password-unlocked provincial/city browser, admin, listings and SSO smoke for new SHA.
- [ ] Only after repeated green updates consider more automation.

## Before activation

- [x] Verify the SSH host key, PHP CLI 8.3.36 and Sodium, Composer 2.9.8 and platform requirements.
- [x] Verify HTTPS, the same physical document root and PHP probe file, and preserved Host headers on both domains. Confirm web PHP 8.3.36 loads Sodium and other required extensions, supports WebP and has display errors disabled. The temporary probe was removed.
- [x] Verify the existing production MariaDB 11.8.9 connection, no tables/views, and database scope limited to that exact schema. Inventory the clean Git checkout under `public_html`.
- [ ] Select the reviewed implementation-branch SHA and its green CI artifact. Review migration SQL and schema effects using the disposable Linux/MariaDB run.
- [ ] Copy the private `.env` once into the release shared directory, preserve original values, add a stable application key if absent, and set exact tester IPs. Keep Git auto-deployment and full-page caching disabled.
- [ ] Prepare privately, take production DB and storage backups, rehearse restore in an isolated database, and complete read-only migration preflight. Record explicit production activation approval for the exact SHA and DB.
- [ ] Quarantine the old checkout outside `public_html`. Activate the reviewed release with the password preview gate, confirm private environment, logs, uploads and keys, then bring up the release for approved testers.

## Both real HTTPS domains after activation

- [ ] In a password-unlocked browser, run the two-domain acceptance checks. The older command-line smoke assumes IP access; do not use that mode as evidence of authenticated preview access. Verify the provincial CanadaMB directory and the Brandon-only portal, valid TLS, correct host-specific canonical URLs and assets, and independent rainbow and green accents.
- [ ] From a fresh browser without the preview cookie, verify both sites show the password form (HTTP 403), deny write requests, and reject forged forwarding headers. Verify `robots.txt` disallows crawling and successful pages use noindex and no-store. Registration stays unavailable for testers too.
- [ ] Sign in from Brandon through CanadaMB with a controlled administrator and verify the HTTPS return callback. Verify central admin routing, sign-out everywhere, distinct host-only Secure/HttpOnly SameSite=Lax cookies, and a rejected invalid OAuth state. Test valid, missing and invalid CSRF evidence.
- [ ] Publish a temporary global theme change and verify both portals receive it without changing their accent colors. Restore the prior settings. Create a controlled Brandon listing and verify the same record appears in both feeds with the Brandon canonical URL. Verify city scope rejects a conflicting location and track any test records for removal.
- [ ] Verify `.env`, `.git`, private storage and framework source remain inaccessible from both domains, and application failures return generic errors while private logs record details.
- [x] Owner hPanel cron executes and consumes a controlled queued marker job without manual worker invocation; Laravel reported no failed jobs (run 44642175328). Keep live feed discovery disabled and application mail isolated. Payments and public real estate publication remain unavailable.
- [ ] Before public launch, remove test data, agree on mail delivery, and explicitly approve disabling prelaunch. Recheck both portals and the resulting registration behavior.

# Hostinger production prelaunch acceptance

Record the reviewed SHA, operator, timestamp, result and evidence for each step. Use controlled test accounts and track test record IDs. Approval to activate restricted prelaunch and approval to open the site to everyone are separate steps.

## Subsequent deployments — not yet approved

- [x] GitHub Actions SSH authentication and private upload verified without changing site or database.
- [x] Manual-only update workflow created on default GitHub branch; no push-to-deploy or Hostinger Git mirror enabled.
- [x] Upgrade rehearsal passed on a populated disposable MariaDB with sample user data, preserved OAuth keys and same public_html inode (run 44622393391).
- [x] Downloaded and restored actual production SQL into isolated MariaDB (53 tables, 1 user, 3 listings, 2 domains; run 44630938711). Uploaded media snapshot archive also created/verified in release; separately rehearse file-level media restoration if needed.
- [ ] Private pause-aware scheduler installed and verified by postdeploy audit; owner still needs to add custom every-minute hPanel Cron Job and inspect its output/logs.
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
- [ ] Observe the custom cron and process a controlled test job. Verify private output and inspect failed jobs. Keep live feed discovery disabled and check that application mail is isolated. Payments and public real estate publication remain unavailable.
- [ ] Before public launch, remove test data, agree on mail delivery, and explicitly approve disabling prelaunch. Recheck both portals and the resulting registration behavior.

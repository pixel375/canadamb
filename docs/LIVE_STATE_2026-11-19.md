# CanadaMB production prelaunch — observed state (2026-11-19)

This is an operational checkpoint, not a claim that the product is ready for a public launch. Distinguish the owner's verified live results from code/CI evidence.

## Current live release (after the Marketplace usability deployment)

- Both sites run the **same production release `0b2359f5eb4587f9fd5dfad1b36402df2b7d5349`**, confirmed by independent GitHub Actions postdeploy audit. `CANADAMB_PRELAUNCH=true` remains on, with external visitors denied HTTP 403.
- First secure GitHub Actions release from the previous `b5f16a6` version, DB/media backup and real off-host SQL restore rehearsal succeeded. Public root directory inode is unchanged.
- GitHub deployment is currently **manual only**, not on every push. The private cron wrapper is installed and **hPanel scheduled execution was confirmed** from the live persistent scheduler log.
- The first targeted Marketplace UX functionality pass is live in prelaunch; phone/desktop approved-IP browser acceptance remains to be performed, while the full visual redesign is still deferred.

## Initial hosting and deployment snapshot (historical)
- **Host:** Hostinger Business Web Hosting (shared hosting), PHP 8.3.36, MariaDB, Composer 2.9.8; Sodium works in CLI and web PHP.
- **Real domains:** `https://canadamb.ca` (provincial network) and `https://brandonmb.ca` (Brandon portal); Brandon is a Hostinger parked alias pointing to the *same physical* CanadaMB `public_html`.
- **Single installation:** Laravel 13, one application and production database; private application resides in `domains/canadamb.ca/canadamb-app/`, persistent environment in `canadamb-app/shared/.env`, **only** public entrypoint and allowlisted assets in `public_html`.
- **Deployed application SHA:** `b5f16a6c08eefcd804deb1970f52490220af67f5` on `implementation/network-foundation` at this checkpoint. Newer Git commits do **not** automatically reach Hostinger.
- **Access state:** `APP_ENV=production` but `CANADAMB_PRELAUNCH=true` with tester IP allowlist; no public registration, outbound mail delivery or live news polling. Git auto-deployment and full-page caching remain off.
- **Owner-verified acceptance:** 7/7 initial checks passed on actual domains: both homes, assets/navigation, central admin login, Brandon's redirect-through-CanadaMB sign-in, separate accents and unapproved-IP rejection.
- **Actual data:** Owner created several **test Marketplace listings** and reported usability/design feedback. Treat them as disposable test content; no real users or traffic reported.

## What was actually deployed and tested
- Initial installation: production database migrations and seeding completed; Passport keys generated; Brandon OAuth callback registered; release symlink points to reviewed SHA.
- Clean web root currently includes `index.php`, `.htaccess`, `build/assets/`, `images/`, favicon, but **not** the full repository, `.git`, `.env`, dependencies or framework source.
- CI for deployed SHA: `https://github.com/pixel375/canadamb/actions/runs/44544063747` passed 35 tests / 409 assertions per database engine, Linux release rehearsal and asset build. These CI results do not substitute for live functional verification.
- A live SQL backup and storage archive were created, integrity-checked, and used in a read-only migration preflight before initial activation. **The exact live SQL dump has not been independently restored in a disposable database**; preserve backup, rehearse a restore, and add fresh post-activation backups before further schema changes.
- Initial Hostinger cutover succeeded by **keeping the existing `public_html` directory inode** and moving only the old `.git`/README contents to a private quarantine. Renaming/replacing the entire web-root directory caused both domains to return 404 and was rolled back.
- A Hostinger shell error `/dev/fd/62: No such file or directory` exposed Bash process substitution in the original release script. Two manually patched script files unexpectedly became zero bytes; the Git-tracked source working-copy script also became zero bytes. The exact cause of truncation is **unknown**, not proven to be Hostinger. An in-memory script loaded from the reviewed Git object with `/dev/fd` patterns removed successfully completed preflight and initial activation. Do not rely on the old mutable working-copy script for future releases.

## Automation progress (historical, before first release)

Actions SSH connection run `44619746774` and private SFTP upload run `44622010185` both passed. A new manual-only Deploy CanadaMB Prelaunch workflow exists on default `main`; deployment update scripts are staged on the implementation branch. **They are not activated on Hostinger.** The live release remains `b5f16a6c08eefcd804deb1970f52490220af67f5` with IP restriction. Further tasks: populated-database upgrade rehearsal, real restore, cron check, and first reviewed rollout.

## Marketplace interaction release — completed after queue integration

- [Exact-SHA CI](https://github.com/pixel375/canadamb/actions/runs/44643244451) passed with PHP tests on SQLite/MariaDB, WebP media upload/replacement tests, Pint, Vite build, Composer/npm audits and disposable Hostinger release rehearsal.
- [Second guarded live release](https://github.com/pixel375/canadamb/actions/runs/44643501263) moved the current app from `44448bd0de1b736a51909f6ec87f692a41d57950` to **`0b2359f5eb4587f9fd5dfad1b36402df2b7d5349`**. A new private production SQL dump (~104 KB), storage archive and verified read-only migration preflight were created. There were **no migration or seeder changes**; database, user account, three original Marketplace test listings and persistent OAuth material were preserved.
- [Independent postdeploy check](https://github.com/pixel375/canadamb/actions/runs/44643771924) passed: SHA, backups and checksums, MariaDB deployment config, original `public_html` directory inode, no maintenance/pause marker, private cron script and both domains' external HTTP 403 prelaunch restriction.
- This release fixes linked card images, object-fit containment, larger listing photos, native-dialog in-page lightbox, current-domain browsing with city canonical URL, category links, seller/contact and owner-management panel, and scope-filtered related/recent cards.
- **Not yet end-to-end tested on real phones or allowlisted browsers:** image viewer swipes/focus, actual uploaded orientation, 1/2/5-image workflows, two-domain login and mobile layout. The user should verify the visible UX before the next refinement. The full professional redesign remains a separate phase.
- GitHub automatic deployment on every push is still OFF. Existing Actions deploy/test workflows were returned to manual-only.

## Live scheduled queue verification — 2026-11-19

[GitHub Actions run 44642175328](https://github.com/pixel375/canadamb/actions/runs/44642175328) created a benign queued Laravel closure on the live database while respecting `CANADAMB_PRELAUNCH=true`. The **actual hPanel cron process** executed `queue:work database --stop-when-empty` and wrote the expected private marker around 20 seconds after enqueue; the marker was deleted, and `queue:failed` reported **No failed jobs found**. No test user or Marketplace records were changed. This closes the cron/queue end-to-end operations gate. Real outgoing mail/news remain disabled.

## hPanel cron verification — completed later on 2026-11-19

Owner configured the one shared Hostinger hPanel custom cron command. Independent [Hostinger cron diagnostic run 44635981084](https://github.com/pixel375/canadamb/actions/runs/44635981084) checked the actual `canadamb-app/shared/storage/logs/scheduler.log` at **2026-11-19 23:14:54 UTC**: log existed (2,079 bytes / 28 lines), last write was **23:14:05 UTC**, only **49 seconds** earlier; **7 scheduler run/completion indications** and **0 detected error lines**. The correct pause-aware private wrapper was present, executable mode `700`, active SHA `44448bd0de1b736a51909f6ec87f692a41d57950`, and no deployment pause marker. This confirms real hPanel cron invocation although the `crontab` CLI is unavailable and hPanel itself does not display the private log. Next check: run a controlled queue-job and review failed jobs; do not enable news polling or external email automatically.

## First controlled production update — completed 2026-11-19

- GitHub CI for target `44448bd0de1b736a51909f6ec87f692a41d57950` passed, with no migration or seeder differences from the original `b5f16a6` installation.
- GitHub Actions [run 44630938711](https://github.com/pixel375/canadamb/actions/runs/44630938711) backed up and **restored a copy of the actual production MariaDB dump into disposable MariaDB**, recovering **53 tables, 1 user, 3 Marketplace listings, and both network domains**. The runner database and dump were discarded; private backup remains on Hostinger.
- [Deploy CanadaMB Prelaunch run 44631266297](https://github.com/pixel375/canadamb/actions/runs/44631266297) succeeded. Active release moved from `b5f16a6c08eefcd804deb1970f52490220af67f5` to `44448bd0de1b736a51909f6ec87f692a41d57950`. Actual production backups, media archive, and preflight passed; production migration/activation succeeded, private keys and assets persisted.
- Independent [postdeploy audit run 44631660770](https://github.com/pixel375/canadamb/actions/runs/44631660770) verified the active SHA, MariaDB state, release backups/checksums, no maintenance/pause marker, persistent OAuth keys, private cron wrapper, and original `public_html` directory inode **446174247**. Non-allowlisted runners received HTTP **403** on both `canadamb.ca` and `brandonmb.ca`.
- This was **infrastructure-only**; Marketplace UX backlog has NOT been implemented. The application remains in **IP-restricted prelaunch**, and **approved-IP user browser/SSO acceptance for this new SHA is not independently completed**.
- New verified private scheduler wrapper at `/home/u713153343/domains/canadamb.ca/canadamb-app/hostinger-cron.sh`; cron **scheduling through hPanel must be performed by the owner** (the account has no `crontab` CLI). Custom command: `/usr/bin/bash /home/u713153343/domains/canadamb.ca/canadamb-app/hostinger-cron.sh /home/u713153343/domains/canadamb.ca /usr/bin/php`. Configure every minute and verify private `shared/storage/logs/scheduler.log` and expected queue processing. **Do not claim hPanel job is active until verified**.
- First live deploy was triggered through a **one-time GitHub workflow push**, then the deploy workflow was restored to **manual-only**. Future full auto-deployment on every green push is **not enabled yet**; secure workflow/branch changes, failure handling and first cron acceptance remain for a later gate.
- Original empty working-copy release script remains known to be zero bytes on Hostinger. New deployment uses immutable Git objects and checksum matching; do not execute the zero-byte file directly.

## Remaining operational acceptance
1. **Completed:** Owner added hPanel cron; independent GitHub Actions SSH diagnostic verified real recurring writes to the private scheduler log (last recorded 2026-11-19 23:14:05 UTC; checked 49 seconds later). Scheduler output included run/completion messages with zero detected error lines. `crontab` is unavailable from SSH, but actual scheduler execution is confirmed. Continue monitoring and perform a controlled queue-job test before public launch.
2. **Completed:** Actual production DB restore rehearsal on isolated MariaDB (53 tables, user and listings intact), first updated release, backup checksums, original web-root inode, and both domains' IP gate.
3. **Future automation:** Manual one-click deployment is functioning. Enabling automated release on every green Git commit remains a separate security/branch-protection decision; never deploy Git source to `public_html`.
4. **Owner browser verification:** Recheck approved-IP login/SSO, admin and listing interactions on the new release; external runner cannot test approved-IP user flows.
5. Verify SMTP when public registration opens; real news transport, abuse handling, business claims, jobs/events, multi-image experience, and extensive feature flows.
6. Do not open public access until security, privacy, moderation, availability, data, payments and launch requirements are signed off.

See [Marketplace feedback](MARKETPLACE_FEEDBACK.md), [phased roadmap](PHASED_ROADMAP.md), [deployment automation options](DEPLOYMENT_AUTOMATION.md), and [Hostinger deployment runbook](HOSTINGER_DEPLOYMENT.md).

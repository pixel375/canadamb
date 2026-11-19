# CanadaMB production prelaunch — observed state (2026-11-19)

This is an operational checkpoint, not a claim that the product is ready for a public launch. Distinguish the owner's verified live results from code/CI evidence.

## Current hosting and release
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

## Automation progress (later same day)

Actions SSH connection run `44619746774` and private SFTP upload run `44622010185` both passed. A new manual-only Deploy CanadaMB Prelaunch workflow exists on default `main`; deployment update scripts are staged on the implementation branch. **They are not activated on Hostinger.** The live release remains `b5f16a6c08eefcd804deb1970f52490220af67f5` with IP restriction. Further tasks: populated-database upgrade rehearsal, real restore, cron check, and first reviewed rollout.

## Pending operational acceptance
1. Add and verify Hostinger bounded cron (including queue jobs, logs, failure alerts); not reported complete.
2. Rehearse restoration of an actual live database backup in an isolated environment; protect ongoing database and private media backups.
3. Permanently use the corrected deployment script from a verified **new** commit. Verify Git checkout integrity and no truncation before/after release operations; never re-run `production-initial` first-cutover steps on the already running site.
4. Add safe one-click/controlled deployment pipeline after successful fresh-release rehearsal; do not enable Hostinger Git auto-deploy pointed at `public_html`.
5. Verify SMTP when public registration opens; real news transport, abuse handling, business claims, jobs/events, multi-image experience, and extensive feature flows.
6. Do not open public access until security, privacy, moderation, availability, data, payments and launch requirements are signed off.

See [Marketplace feedback](MARKETPLACE_FEEDBACK.md), [phased roadmap](PHASED_ROADMAP.md), [deployment automation options](DEPLOYMENT_AUTOMATION.md), and [Hostinger deployment runbook](HOSTINGER_DEPLOYMENT.md).

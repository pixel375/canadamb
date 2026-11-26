# Hostinger Git auto-deployment — options and safe approach (2026-11-19)

**Current policy (2026-11-26):** All development and production-prelaunch deployments use `main` only. Exact backend and browser push CI automatically dispatch a guarded SHA-pinned deploy, followed by independent live audits and two-release retention. Earlier branch-specific/manual-only instructions below are historical, not current operating instructions. No unapproved schema/flag or public-access changes.

## 2026-11-21 — Routine deployment now automated after green CI

The trusted default-branch `.github/workflows/hostinger-autodeploy.yml` listens for
successful **push** runs of **Verify CanadaMB** on
`main` only. It checks that the exact green SHA
is still the implementation branch tip and dispatches the existing guarded
`Deploy CanadaMB Prelaunch` workflow, explicitly setting
`allow_reviewed_database_changes=false`. PRs, other branches, failed CI and
superseded commits do not dispatch. GitHub Actions, not a public Hostinger Git
webhook, holds the SSH environment credentials.

The unchanged deploy workflow independently rechecks exact-SHA push CI and
branch ancestry, builds assets, verifies SSH host keys, backs up the production
database and media, deploys a private versioned Laravel release, and checks
both IP-restricted domains. The deployment script refuses unattended changes
to database migrations or seeders. Sensitive schema changes, public launch and
prelaunch allowlist changes remain reviewed operations.

After a successful deployment, two independent reusable audit jobs run
automatically as part of that same guarded workflow. The private audit checks
the **exact deployed SHA provided by the verified deployment**, database/media
backup checksums, original web root and prelaunch protection; a separate
public-origin audit checks PWA manifests/icons and private-route HTTP 403.
Failures flag the overall deployment workflow rather than relying on chained
workflow_run events. The manual workflow remains available for exceptional
reviewed updates, but routine code/UI releases no longer require a Run click.

**First automated CI-to-deployment check passed:** [dispatcher run 44942686683](https://github.com/pixel375/canadamb/actions/runs/44942686683) launched [guarded live deployment 44942699928](https://github.com/pixel375/canadamb/actions/runs/44942699928) without manual intervention. The follow-on documentation-only commit exercises the new audit-job gate.

This section supersedes the older 2026-11-19 manual-only description below.
It is also a harmless documentation-only implementation commit used to validate
the full CI-to-deploy-to-audit handoff before declaring automation proven.

## Automated workflow status — connected, no production update yet (2026-11-19)

**Implemented on GitHub `main`:**
- [Hostinger SSH Connection Test](https://github.com/pixel375/canadamb/actions/workflows/hostinger-ssh-test.yml) — executed successfully (run [44619746774](https://github.com/pixel375/canadamb/actions/runs/44619746774)): verified private/public file split, existing `b5f16a6` release, PHP 8.3.36 and required extensions with no changes to files or DB.
- [Hostinger Private Upload Test](https://github.com/pixel375/canadamb/actions/workflows/hostinger-upload-test.yml) — executed successfully (run [44622010185](https://github.com/pixel375/canadamb/actions/runs/44622010185)): uploaded a harmless scratch file under private `.builds`, checked its checksum and deleted it.
- [Deploy CanadaMB Prelaunch](https://github.com/pixel375/canadamb/actions/workflows/hostinger-deploy.yml) — **manual workflow only**. It accepts a full implementation SHA and optional reviewed DB change flag. A separate no-secret build job requires green exact-SHA CI, builds a matching Vite artifact, and passes only public build output to the approval-bound deployment job. The deployment job uses verified SSH host keys, uploads private asset intake, loads upgrade helpers from checked Git objects, prepares a versioned release, enters maintenance, backs up MariaDB/media, runs preflight, activates, checks the DB, reopens restricted prelaunch and checks both domains from an **unapproved** runner.
- **No production update has been executed using this new workflow.** It must pass the disposable upgrade rehearsal and have its first live update specifically approved. The runner cannot perform approved-IP browser/SSO smoke; record this manual acceptance afterward.
- Connection and upload tests are manual-only after their initial integration runs. Ordinary pushes trigger **CI only**, never deployment.

**Implementation branch:** `deploy/hostinger-upgrade.sh`, backup helper, existing release script `production-update` mode and cron pause marker passed the disposable green [CI upgrade rehearsal (44622393391)](https://github.com/pixel375/canadamb/actions/runs/44622393391). It performed a simulated second deployment after inserting user data, backed up ~79 KB of MariaDB data, ran preflight and confirmed the test user, OAuth private key and `public_html` inode were preserved. **This proves the disposable test path, not a completed live second release.**

### User path once first live update is approved
1. Open **Actions → Deploy CanadaMB Prelaunch → Run workflow**, select `main`, paste the **full green implementation commit SHA** into `commit_sha`. Leave `allow_reviewed_database_changes` unchecked for routine UI and code updates that have no migration/seeder changes.
2. Review the green exact-SHA CI evidence, then approve the `hostinger-prelaunch` Environment if GitHub requests it.
3. Watch the Actions run. Failure after schema migration must be treated as a manual recovery event; check private logs and backup, do not rerun blind. Unapproved runners should receive 403 on both public homepages.
4. From approved tester IPs, test both domains, sign-in/SSO, admin, listings, CSS/images and any changed flows. Do not disable prelaunch to make runners pass.
5. Later enable push-to-deploy **only** after controlled releases, backup restore rehearsal, queue/cron integration and branch/environment protection are verified.

**Historical blockers resolved:** The production SQL backup restored in an isolated MariaDB; the first live update succeeded; hPanel scheduled cron invocation was independently verified by [Hostinger cron diagnostic run 44635981084](https://github.com/pixel375/canadamb/actions/runs/44635981084). Routine deploys remain manual-only; verify controlled queue behavior and browser/SSO acceptance before public launch.

## First controlled production update — completed 2026-11-19

- GitHub CI for target `44448bd0de1b736a51909f6ec87f692a41d57950` passed, with no migration or seeder differences from the original `b5f16a6` installation.
- GitHub Actions [run 44630938711](https://github.com/pixel375/canadamb/actions/runs/44630938711) backed up and **restored a copy of the actual production MariaDB dump into disposable MariaDB**, recovering **53 tables, 1 user, 3 Marketplace listings, and both network domains**. The runner database and dump were discarded; private backup remains on Hostinger.
- [Deploy CanadaMB Prelaunch run 44631266297](https://github.com/pixel375/canadamb/actions/runs/44631266297) succeeded. Active release moved from `b5f16a6c08eefcd804deb1970f52490220af67f5` to `44448bd0de1b736a51909f6ec87f692a41d57950`. Actual production backups, media archive, and preflight passed; production migration/activation succeeded, private keys and assets persisted.
- Independent [postdeploy audit run 44631660770](https://github.com/pixel375/canadamb/actions/runs/44631660770) verified the active SHA, MariaDB state, release backups/checksums, no maintenance/pause marker, persistent OAuth keys, private cron wrapper, and original `public_html` directory inode **446174247**. Non-allowlisted runners received HTTP **403** on both `canadamb.ca` and `brandonmb.ca`.
- This was **infrastructure-only**; Marketplace UX backlog has NOT been implemented. The application remains in **IP-restricted prelaunch**, and **approved-IP user browser/SSO acceptance for this new SHA is not independently completed**.
- New verified private scheduler wrapper at `/home/u713153343/domains/canadamb.ca/canadamb-app/hostinger-cron.sh`; cron **scheduling through hPanel must be performed by the owner** (the account has no `crontab` CLI). Custom command: `/usr/bin/bash /home/u713153343/domains/canadamb.ca/canadamb-app/hostinger-cron.sh /home/u713153343/domains/canadamb.ca /usr/bin/php`. Configure every minute and verify private `shared/storage/logs/scheduler.log` and expected queue processing. **Do not claim hPanel job is active until verified**.
- First live deploy was triggered through a **one-time GitHub workflow push**, then the deploy workflow was restored to **manual-only**. Future full auto-deployment on every green push is **not enabled yet**; secure workflow/branch changes, failure handling and first cron acceptance remain for a later gate.
- Original empty working-copy release script remains known to be zero bytes on Hostinger. New deployment uses immutable Git objects and checksum matching; do not execute the zero-byte file directly.

## Owner goal
Avoid repeated long manual SSH/SFTP commands on every change while preserving Laravel's secure private/public split, production database, persistent `.env`, media, Passport keys and existing two-domain application.

> **Connection-stage implementation:** A read-only manual SSH test now exists on default `main`. See [one-time setup](ACTIONS_SSH_SETUP.md). This is not the update deployment workflow; there is still no automatic Hostinger rollout.

## Cron verification (November 19)

[Hostinger cron diagnostic run 44635981084](https://github.com/pixel375/canadamb/actions/runs/44635981084) confirmed the hPanel job is actually running: at 23:14:54 UTC the private `scheduler.log` had 28 lines and was last written 49 seconds earlier, including 7 scheduler run/completion indications with no detected error lines. The private wrapper checks `update.pause` to cooperate with future deployments; an operator still needs to test a controlled queue job. No need to expect task output in hPanel, since the wrapper logs to `canadamb-app/shared/storage/logs/scheduler.log`.

## Current status
- **Hostinger Business Web Hosting** is sufficient for this architecture; the two sites use the same existing `public_html`. Git auto-deploy remains **OFF**. No pipeline or Hostinger configuration is changed by this document.
- The live app was installed from a pinned implementation SHA outside the document root, with only vetted assets and a front controller in `public_html`.
- The GitHub repository contains the whole Laravel app (private source, configuration, framework, etc.). **Do not point Hostinger's existing auto Git integration at `public_html`**, as it would copy private code and potentially replace the safe entrypoint/asset allowlist.
- Hostinger's current Git integration can deploy a selected branch to a user-configurable root directory, defaulting to `public_html`, and automatically pulls on push. Exact allowed filesystem paths and hooks for the current account need a non-production test; do not assume Hostinger's Git pull automatically executes `composer`, migrations, Vite artifact install, backups, SSO update or smoke tests.

## Options
| Option | Pros | Limitations / safety | Decision |
| --- | --- | --- | --- |
| A. Hostinger auto Git → current `public_html` | Simple toggle | **Unsafe for this full Laravel repository.** Private source/secrets exposure risk, overwrite/delete runtime files, no vetted migration/rollback | **Reject** |
| B. Hostinger auto Git → *new private intake directory* (outside document root) | Auto-sync code without exposing it | Verify hPanel accepts outside-web-root path, isolation, ownership; cannot activate safely by itself; never target active `source` / `shared` / `releases`; may be overwritten on pull | **Possible experiment only; no activation** |
| C. GitHub Actions → SSH/SFTP gated release | One-click manual approval, pin passing SHA and matching Vite artifact, run preflight/backups/smoke; can later automate with protected environment | Configure least-privilege SSH key/known hosts in GitHub Environments, verify Hostinger outbound/inbound support and runner allowlist; design safe rollback and detect failures | **Preferred** |
| D. Single Hostinger SSH `deploy-update` helper | One command rather than many; no CI secret integration | Requires manual SSH login, artifact transfer unless helper fetches verified artifact; must keep explicit approval | **Short-term fallback** |

## Proposed one-click deployment pipeline (design, NOT enabled)
1. Code changes land on the implementation branch; CI must pass tests, build artifacts and security checks for **the exact commit**.
2. A **manual GitHub Actions `workflow_dispatch` Deploy Prelaunch** takes the SHA (or selects the verified branch head), validates commit ancestry and artifact provenance. Use a protected GitHub **Environment** requiring explicit approval. Do not automatically deploy on every push while prelaunch is the only safeguard.
3. Action authenticates to Hostinger over SSH with a dedicated limited-access key (if supported), pinning the host key and never printing credentials; transfers only the exact Vite assets and invokes the private deploy helper on Hostinger.
4. Helper verifies the repository/asset SHA, checks public root and existing release state, acquires release lock, pauses cron/writes for migration window, creates protected MariaDB and private-storage backups, and verifies backup restore on a **separate** disposable DB when supported. Require migration preflight fingerprint review; fail closed if unsupported.
5. An approved activation updates the private release, runs reviewed migrations, keeps permanent secrets/media intact, publishes allowlisted assets into the **existing** `public_html` directory (don't rename the inode), atomically switches current release where possible, and leaves maintenance enabled until verification.
6. Run both-domain HTTPS smoke (IP allowlist must include the test runner or use a privileged controlled test endpoint) and application checks. Log SHA, timestamp, outcome, backup paths, rollback eligibility; resume cron and disable maintenance only after acceptance.
7. Never auto-rollback database-changing releases without schema-compatibility review; preserve previous assets and isolate failed deployments.

## Before enabling
- First apply and test the `/dev/fd`-free release script from a new green CI commit; root cause of zero-byte working-copy scripts on Hostinger is unknown and must be investigated.
- Trial a second release with **no schema changes** manually through the fixed script, keeping current root inode and IP gate, then test a single-action SSH release.
- Make backup restores, hPanel cron, secure credentials, status checks and notifications reliable. Decide how dynamic IP allowlists and runner-origin smoke will work.
- Do **not** use automatic Git deploy into `public_html` or start uncontrolled background PHP scripts.

## Relevant Hostinger documentation
- https://www.hostinger.com/support/1583302-how-to-deploy-a-git-repository-in-hostinger/
- https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/

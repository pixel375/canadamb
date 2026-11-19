# Hostinger Git auto-deployment — options and safe approach (2026-11-19)

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

**First-run blocker**: Hostinger's real database dump still needs an isolated restore rehearsal, cron deployment pause behavior must be verified against the actual hPanel cron job (which may still point at an old script), and the private updater needs a successful CI production-update simulation. No deployment should be attempted while these are unresolved.

## Owner goal
Avoid repeated long manual SSH/SFTP commands on every change while preserving Laravel's secure private/public split, production database, persistent `.env`, media, Passport keys and existing two-domain application.

> **Connection-stage implementation:** A read-only manual SSH test now exists on default `main`. See [one-time setup](ACTIONS_SSH_SETUP.md). This is not the update deployment workflow; there is still no automatic Hostinger rollout.

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

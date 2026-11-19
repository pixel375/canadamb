# Hostinger Git auto-deployment — options and safe approach (2026-11-19)

## Owner goal
Avoid repeated long manual SSH/SFTP commands on every change while preserving Laravel's secure private/public split, production database, persistent `.env`, media, Passport keys and existing two-domain application.

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

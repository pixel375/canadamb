# GitHub Actions → Hostinger SSH: one-time setup (connection stage)

**Status 2026-11-19:** Both the SSH connection test and the private SFTP upload test succeeded. GitHub now has a MANUAL-only [Deploy CanadaMB Prelaunch](https://github.com/pixel375/canadamb/actions/workflows/hostinger-deploy.yml) workflow, but it has **not** deployed the live website. Environment keys and variables are already configured and working. New deployment requires explicit approval and passing prelaunch update rehearsal.

**Historical connection setup:** A read-only `Hostinger SSH Connection Test` workflow exists on the repository's **default branch `main`** (GitHub manual workflow dispatch requires the file there). This is a connection **test only**, not an automatic deployment. The production sites remain on `b5f16a6c08eefcd804deb1970f52490220af67f5` behind the allowlist. Never paste SSH private keys, passwords, known-hosts values or `.env` into a public GitHub commit or chat.

## Configure in GitHub (one time)

1. Open https://github.com/pixel375/canadamb/settings/environments . Create or open environment **`hostinger-prelaunch`**. For initial testing, allow only the **`main`** branch as a deployment branch; this read-only workflow runs from the default branch. Reviewers may be configured when an appropriate independent reviewer exists; do not enable "prevent self-review" if the owner is the sole reviewer.
2. Under **Environment secrets**, add:
   - `HOSTINGER_SSH_PRIVATE_KEY`: the complete **unencrypted OpenSSH private key** corresponding to the public key authorized for this Hostinger account. Multiline content; do not use a GitHub deploy-key meant for fetching GitHub source. Do **not** share the private key in chat.
   - `HOSTINGER_KNOWN_HOSTS`: the full verified known_hosts line for the Hostinger **SSH host and port**. Obtain from a trusted local `known_hosts` entry or `ssh-keyscan -t ed25519 -p PORT HOST`; compare its `ssh-keygen -lf` fingerprint to Hostinger hPanel → SSH Access **before** trusting it. When port is not 22 the host field must be `[HOST]:PORT`.
3. Under **Environment variables**, add:
   - `HOSTINGER_HOST`: actual publicly reachable Hostinger SSH **IP or hostname from hPanel**, **not** `https://canadamb.ca` or `us-phx-web1268` unless Hostinger explicitly presents it as public SSH host.
   - `HOSTINGER_PORT`: the actual SSH port shown in hPanel (often `65002`).
   - `HOSTINGER_USER`: `u713153343`.
4. If the key requires an interactive passphrase, generate a dedicated noninteractive deployment key instead; store it **only** as an environment secret. Never disable SSH host-key verification. Keep the original operator/Codex SSH keys intact.

## Run the safe test

1. Visit https://github.com/pixel375/canadamb/actions/workflows/hostinger-ssh-test.yml
2. Click **Run workflow**, choose `main`, confirm and (if configured) approve the environment.
3. Read the job `Read-only Hostinger prelaunch inspection` log. Success prints `SSH CONNECTION OK`, the active commit SHA and PHP verification. It does not read database data, execute application migrations, upload code, change site configuration or place anything in `public_html`.
4. If the test fails, review redacted errors: bad key format vs host fingerprint mismatch vs connection refused/timed out vs file-layout check. Never solve a fingerprint mismatch with `StrictHostKeyChecking=no`.

## Safety and future update deployment

- The existing key only proves an SSH public key was authorized. **It is not proof that GitHub Actions itself can reach or authenticate to Hostinger.** This manual action is the proof.
- Standard GitHub-hosted Actions runner usage is currently free for public repositories; artifact usage/storage policy may differ. Public source still requires protecting environment secrets and restricting workflow edits.
- Do not point Hostinger Git auto-deploy at `public_html` or copy `.git`, Laravel source, `.env`, `vendor`, or private uploads into web root.
- After connection succeeds, implement/test a **separate** manual deploy workflow that requires a green exact SHA, matching Vite artifact, verified backup, read-only migration preflight, maintenance/cutover, unchanged original `public_html` directory inode, explicit approval and on-host checks. No silent rollout on every push until multiple controlled updates are accepted.
- The current live database and uploads must not be reset; the original first-install `production-initial` sequence is historical.

Related: [automation design](DEPLOYMENT_AUTOMATION.md), [live state](LIVE_STATE_2026-11-19.md), [Hostinger runbook](HOSTINGER_DEPLOYMENT.md).

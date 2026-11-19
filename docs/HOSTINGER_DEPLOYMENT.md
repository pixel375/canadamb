# CanadaMB production-first prelaunch on Hostinger

> **LIVE CHECKPOINT — 2026-11-19:** Initial installation/activation of commit `b5f16a6c08eefcd804deb1970f52490220af67f5` already completed successfully and both domains passed owner-verified IP-restricted browser acceptance. The first-install commands below are **historical** and must **not** be repeated against an already populated production schema. See [current live state](LIVE_STATE_2026-11-19.md) and [deployment automation plan](DEPLOYMENT_AUTOMATION.md).
>
> **Hostinger-specific correction:** Renaming or replacing the `public_html` directory caused both domain requests to return 404, so future cutovers must retain the existing directory inode and only modify reviewed allowlisted **contents**. The old `.git` checkout was privately quarantined during initial activation. The original Bash process-substitution logger failed at `/dev/fd/62`; release-script fix is now versioned, but repeatability of a new live update is unverified. **Do not activate a new release merely because Hostinger Git pulled files.**

CanadaMB and BrandonMB share one Laravel release, one account system, and the existing production MariaDB database. PHP runs as `APP_ENV=production` with debugging off. `CANADAMB_PRELAUNCH=true` restricts dynamic routes to exact tester IPs, disables registration and live mail delivery, disallows indexing, and keeps feed polling off. The tester can sign in on CanadaMB and follow the same browser OAuth flow on BrandonMB. General availability is a separate decision.

The Hostinger account was checked through SSH and an authenticated HTTPS probe that was removed after use. Both hostnames presented the correct Host header and served the same physical document root and probe file. CLI and web PHP are 8.3.36. Web PHP loaded Sodium and the required extensions, supported GD WebP, and had error display off. Composer 2.9.8 passed the production lockfile platform check. The prepared private database credentials select their configured schema on MariaDB 11.8.9, with no existing tables or views and grants limited to that schema. The original `.env` had no application key. These checks preceded installation.

## 1. Prepare the private release over SSH

Keep hPanel Git auto-deployment and full-page caching disabled. Keep Brandon configured as a parked domain and confirm both certificates remain valid. Use the Bash, PHP and Composer binaries verified over SSH. Download the `vite-<full SHA>` artifact from the successful GitHub Actions run for the reviewed commit. From a workstation, `gh run download RUN_ID --name "vite-$SHA" --dir "vite-$SHA"` downloads the matching artifact. Upload `assets/`, `manifest.json` and `source-commit` to `$SITE_HOME/.builds/$SHA` using SFTP.

```bash
set -euo pipefail
umask 077
SITE_HOME="$HOME/domains/canadamb.ca"
APP="$SITE_HOME/canadamb-app"
WEB="$SITE_HOME/public_html"
read -rp 'Reviewed full GitHub commit SHA: ' SHA
[[ $SHA =~ ^[0-9a-f]{40}$ ]]
read -rp 'Exact existing production MariaDB database name from hPanel: ' CANADAMB_PRODUCTION_DB
[[ $CANADAMB_PRODUCTION_DB =~ ^[A-Za-z0-9_]{1,64}$ ]]
export CANADAMB_DEPLOYMENT_MODE=production-initial CANADAMB_PRODUCTION_DB
unset CANADAMB_STAGING_DB CANADAMB_ACTIVATION_CONFIRMED CANADAMB_PRODUCTION_CONFIRMED
export CANADAMB_PHP_BIN=/usr/bin/php CANADAMB_COMPOSER_BIN=/usr/local/bin/composer
install -d -m 700 "$APP" "$APP/shared" "$SITE_HOME/.builds/$SHA"
if [[ ! -d "$APP/source/.git" ]]; then
  git clone --branch implementation/network-foundation --single-branch \
    https://github.com/pixel375/canadamb.git "$APP/source"
fi
git -C "$APP/source" fetch origin implementation/network-foundation
git -C "$APP/source" merge-base --is-ancestor "$SHA" origin/implementation/network-foundation
git -C "$APP/source" switch --detach "$SHA"
if [[ ! -e "$APP/shared/.env" && ! -L "$APP/shared/.env" ]]; then
  (set -o noclobber; cat "$SITE_HOME/private/.env" > "$APP/shared/.env")
fi
test -f "$APP/shared/.env" && test ! -L "$APP/shared/.env"
chmod 600 "$APP/shared/.env"
```

Edit `$APP/shared/.env` with a private editor. Preserve the database credentials and any existing valid key. This account had an empty key at audit time. In that case, generate a key once using `php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;'` in the SSH session and enter it in the private file. Keep the original `private/.env` intact. Apply these settings, replacing the example IP with the exact public IPv4 or IPv6 address of each approved tester. Use only real tester addresses. The gate uses the web server's `REMOTE_ADDR` and ignores browser-supplied forwarding headers. If a proxy obscures tester addresses, fix the routing first. An empty list denies all testers.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://canadamb.ca
APP_MAINTENANCE_DRIVER=file
DB_CONNECTION=mariadb
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_CHANNEL=stack
LOG_STACK=daily
CANADAMB_STAGING=false
CANADAMB_PRELAUNCH=true
CANADAMB_PRELAUNCH_IPS=192.0.2.10
CANADAMB_SSO_ENABLED=true
CANADAMB_NEWS_POLLING=false
CANADAMB_CRON_QUEUE_DRAIN=true
CANADAMB_NEWS_INGEST_TOKEN=
CANADAMB_TRUSTED_PROXIES=
```

Keep session cookies host-only. The existing application automatically selects the non-delivering `array` mailer while prelaunch is enabled. Use only controlled test identities and content. Public real estate publishing remains unavailable and payments remain unconfigured.

```bash
DEPLOY="$APP/source/deploy/hostinger-release.sh"
bash "$DEPLOY" prepare "$SITE_HOME" "$SHA" "$SITE_HOME/.builds/$SHA"
git -C "$APP/source" ls-tree -r --name-only "$SHA" -- database/migrations
```

Preparation installs locked dependencies, checks the platform and exact database credentials and grants, and builds Laravel caches privately. It does not apply migrations. Confirm the exact release rehearsal passed on disposable MariaDB in GitHub Actions. Review the migration files above. For later releases, compare `git diff PREVIOUS_SHA "$SHA" -- database/migrations`, including schema and data effects.

## 2. Back up and run read-only preflight

For later releases, pause the hPanel cron and run `php "$APP/current/artisan" down --retry=60` before backing up. Allow in-flight requests to finish. This initial installation has no current Laravel release to pause. Enter the host and login from the private configuration and supply the password at the prompt. Backups will include the current shared media, private keys and logs if present. The empty database still needs a dump. Verify the SQL backup can be restored using an isolated local MariaDB or the disposable CI rehearsal. Keep the restore off the production database.

```bash
read -rp 'Database host from the private configuration: ' DB_HOSTNAME
read -rp 'Database login from the private configuration: ' DB_LOGIN
BACKUP="$APP/shared/backups/$SHA"
install -d -m 700 "$BACKUP"
mariadb --host "$DB_HOSTNAME" --user "$DB_LOGIN" --password \
  "$CANADAMB_PRODUCTION_DB" --execute='SELECT DATABASE(), VERSION()'
mariadb-dump --host "$DB_HOSTNAME" --user "$DB_LOGIN" --password \
  --single-transaction --skip-lock-tables "$CANADAMB_PRODUCTION_DB" > "$BACKUP/$CANADAMB_PRODUCTION_DB.sql"
tar -czf "$BACKUP/storage.tar.gz" -C "$APP/shared" storage
tar -tzf "$BACKUP/storage.tar.gz" >/dev/null
bash "$DEPLOY" preflight "$SITE_HOME" "$SHA"
```

The preflight reads MariaDB metadata and Laravel migration history and records hashes for the schema inventory, history and backups. It performs no database writes, even when the migrations table is absent. It rejects a nonempty database without Laravel migration history. If the database has changed or a backup is replaced, take a fresh backup and repeat the review. Keep the dump and original `.env` private. Confirm an authorized cutover before continuing.

## 3. Activate only after explicit approval

The first cutover requires a private quarantine of the existing web root. Inventory it before moving anything. The audit found a clean Git checkout containing `.git` and `README.md`. Verify this is still accurate and preserve any later contents. Move the root as a whole on the same filesystem where hPanel permits it. If hPanel requires the same directory inode, move reviewed contents including dotfiles to quarantine and keep the existing top-level directory. Preserve any verified ACME challenge tokens in the clean root. Keep the old checkout outside the public tree during recovery.

```bash
git -C "$WEB" status --short
find "$WEB" -mindepth 1 -maxdepth 1 -printf '%f\n'
NEXT_WEB=$(mktemp -d "$SITE_HOME/.builds/public-$SHA.XXXXXXXX")
QUARANTINE=$(mktemp -d "$SITE_HOME/private/legacy-web.XXXXXXXX")
chmod 755 "$NEXT_WEB"
test -d "$WEB" && test ! -L "$WEB"
# Copy only any verified .well-known/acme-challenge token files to NEXT_WEB.
mv -T -- "$WEB" "$QUARANTINE/public_html" && mv -T -- "$NEXT_WEB" "$WEB"

CANADAMB_ACTIVATION_CONFIRMED="$SHA" CANADAMB_PRODUCTION_CONFIRMED="$CANADAMB_PRODUCTION_DB" \
  bash "$DEPLOY" activate "$SITE_HOME" "$SHA"
```

The script rejects unexpected public files and mismatched backups, database state or confirmations before migrating. It applies reviewed migrations, seeds the initial network through idempotent operations, and keeps the private OAuth key pair across later releases. It installs the allowlisted assets and entrypoint and switches the private release symlink. The release stays in maintenance mode for final checks.

```bash
php "$APP/current/artisan" network:deployment-check --hostinger \
  --production-database="$CANADAMB_PRODUCTION_DB" --database
read -rp 'Controlled administrator email: ' TEST_ADMIN
php "$APP/current/artisan" network:admin "$TEST_ADMIN"
php "$APP/current/artisan" up
```

From an allowlisted workstation, run `CANADAMB_SMOKE_PRELAUNCH=1 bash deploy/smoke.sh`. From a different network verify that both portals return a prelaunch 403, that `robots.txt` disallows crawling, and that forwarded headers do not grant access. Run the [two-domain acceptance checklist](DEPLOYMENT_CHECKLIST.md), including real browser OAuth, admin publishing and cleanup of tracked test records. Keep CDN full-page caching disabled during prelaunch.

## 4. Bounded cron, updates, and recovery

In hPanel, create a Custom cron every minute. Run the following command to print the exact entry for this SSH account, then paste the output into hPanel. Observe at least two invocations, a controlled test job, and the private scheduler logs. The wrapper uses a deployment lock and a 60-second maximum runtime. Keep the database queue retry interval above 60 seconds. [Hostinger cron setup](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/)

```bash
printf '/usr/bin/bash %s/source/deploy/hostinger-cron.sh %s /usr/bin/php >> %s/shared/storage/logs/scheduler.log 2>&1\n' "$APP" "$SITE_HOME" "$APP"
```

For each GitHub update during prelaunch, repeat prepare, backup, preflight, approval, and activation with the new exact SHA and its exact Vite artifact. Skip the initial web-root move and administrator creation. Keep the private `.env`, `APP_KEY`, shared storage and Passport keys. Database sessions, queues, records and network settings remain in the same schema. Review migration compatibility and keep the previous releases and hashed public assets. Resume cron after both domain checks pass.

On failure, pause cron, retain the deploy log and keep the application in maintenance. For a schema-compatible rollback to a reviewed SHA, use a subshell so a failure releases the lock. Recheck a partial MariaDB migration before reopening traffic. An incompatible rollback needs a reviewed forward fix or restoration from the tested backup. Keep copies of failure evidence before any restore and require explicit production approval.

```bash
(
  read -rp 'Prior compatible release SHA: ' ROLLBACK_SHA
  [[ $ROLLBACK_SHA =~ ^[0-9a-f]{40}$ ]]
  PREVIOUS="$APP/releases/$ROLLBACK_SHA"
  test -f "$PREVIOUS/artisan" && test -f "$PREVIOUS/.prepared"
  exec 9>"$APP/release.lock"
  flock -n 9
  php "$APP/current/artisan" down --retry=60
  ln -s "$PREVIOUS" "$APP/.rollback-$ROLLBACK_SHA"
  mv -Tf "$APP/.rollback-$ROLLBACK_SHA" "$APP/current"
  php "$APP/current/artisan" up
)
```

When general availability is approved, review and remove controlled test records and accounts, configure and verify intended outbound mail, and set `CANADAMB_PRELAUNCH=false` in the existing private environment. Pause cron, cache configuration with `php "$APP/current/artisan" config:cache`, and verify both domains and registration again. This switch keeps the same database, files, application key and authentication system. The release command named `production-initial` requires prelaunch to remain enabled, so use a reviewed post-launch release procedure once the gate is permanently open.

The separate staging mode remains available for future isolated hosting using `CANADAMB_DEPLOYMENT_MODE=staging`, `CANADAMB_STAGING_DB` ending in `_staging`, and `CANADAMB_STAGING=true`. [Hostinger document root](https://www.hostinger.com/support/1583494-what-is-the-path-to-your-website-s-root-home-directory-and-how-to-change-it-in-hostinger/) · [Parked domains](https://www.hostinger.com/support/1583424-what-are-the-differences-between-subdomain-parked-domain-and-add-on-domain/)

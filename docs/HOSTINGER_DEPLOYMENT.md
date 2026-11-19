# CanadaMB staging on Hostinger Business Web Hosting

## Account requirements

Read-only SSH verified Linux, PHP CLI 8.3.36, Composer 2.9.8, PHP WebP, PDO MySQL, cURL, `pcntl`, `proc_open`, MariaDB client 11.8.9, Bash, Git, `flock` and `timeout`. The CLI has `display_errors=Off` and no `open_basedir` restriction. Its `America/Winnipeg` data uses UTC−05:00 year-round in 2027. The remote database server version, PHP web runtime, scheduler quota, and parked alias routing still require acceptance. [Laravel deployment requirements](https://laravel.com/docs/13.x/deployment) · [IANA timezone release](https://www.iana.org/time-zones/releases/2026e)

Enable Sodium for PHP 8.3 in hPanel under PHP Configuration → PHP Extensions and verify `php --ri sodium` over SSH. The module was installed and `php -d extension=sodium --ri sodium` can load it for one CLI process. The normal PHP binary used by Composer subprocesses, cron and the web SAPI must all load it. If selecting it in hPanel updates only the web runtime, have Hostinger apply it to SSH/cron too. Verify PHP compatibility with `composer check-platform-reqs --no-dev` in the prepared release. [Hostinger PHP extension setup](https://www.hostinger.com/support/4667515-how-to-manage-php-extensions-and-options-in-hostinger/)

The staging design uses one account, one PHP application and one MariaDB database for both domains. Node builds assets in GitHub Actions. The deployed system uses bounded cron runs and the MariaDB queue. A VPS is unnecessary for this design. Root access, Redis, a queue supervisor, WebSocket services and a Node server are outside the runtime requirements.

## Prepare a commit and a staging database

In hPanel, keep Git auto-deployment disabled. Use SSH to fetch the private checkout outside the fixed web root. Configure `brandonmb.ca` as a parked domain of `canadamb.ca`, with DNS and valid HTTPS for each apex. Preserve the original request host, disable any cross-domain redirect or full-page cache, and leave other domains inactive. Verify both certificates and the alias before traffic cutover. [Hostinger document root](https://www.hostinger.com/support/1583494-what-is-the-path-to-your-website-s-root-home-directory-and-how-to-change-it-in-hostinger/) · [Parked domains](https://www.hostinger.com/support/1583424-what-are-the-differences-between-subdomain-parked-domain-and-add-on-domain/)

From a verified SSH session, run the following in Bash. Supply the audited commit SHA, and use the staging database name assigned by hPanel. Create a staging-only user with schema-level grants limited to the literal staging database. The script rejects global, wildcard, other-schema and grant-option privileges. The database name must end in `_staging`. Use the existing database only if its intended staging role and isolation have been verified. Preserve the existing production configuration under `private/`.

```bash
umask 077
SITE_HOME="$HOME/domains/canadamb.ca"
APP="$SITE_HOME/canadamb-app"
SHA=REPLACE_WITH_VERIFIED_FULL_COMMIT_SHA
export CANADAMB_STAGING_DB=REPLACE_WITH_HPANEL_DB_NAME_ENDING_IN_staging
export CANADAMB_PHP_BIN=/usr/bin/php
export CANADAMB_COMPOSER_BIN=/usr/local/bin/composer
install -d -m 700 "$APP" "$APP/shared" "$SITE_HOME/.builds/$SHA"
if [ ! -d "$APP/source/.git" ]; then
  git clone --branch implementation/network-foundation --single-branch \
    https://github.com/pixel375/canadamb.git "$APP/source"
fi
git -C "$APP/source" fetch origin implementation/network-foundation
git -C "$APP/source" merge-base --is-ancestor "$SHA" origin/implementation/network-foundation
if [ ! -e "$APP/shared/.env" ]; then
  git -C "$APP/source" show "$SHA:.env.production.example" > "$APP/shared/.env"
fi
chmod 600 "$APP/shared/.env"
```

Edit only `$APP/shared/.env`. Set `CANADAMB_STAGING=true`, `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL=https://canadamb.ca`. Set the staging database credentials and a unique 32-byte base64 application key. Generate the key once with `php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;'` in the private SSH session and keep it across releases. Use database-backed session/cache/queue, encrypted host-only Secure and HttpOnly sessions with SameSite=Lax, and verify SMTP with controlled recipients. Keep feed scheduling and unverified ingestion off. Leave only verified reverse proxy IPs in the proxy settings if needed. The application's trusted hostnames come from its domain table. The shared local disk stores private uploads and serves eligible media through Laravel. Keep storage and Passport keys private across releases.

Download `vite-<SHA>` from the successful GitHub Actions run for that SHA and extract its `manifest.json`, `source-commit`, and `assets/` into `$SITE_HOME/.builds/$SHA`. The resulting deploy command verifies the artifact revision, runs production Composer install from the lockfile, creates caches, and checks the named database and grants.

```bash
DEPLOY="$APP/source/deploy/hostinger-release.sh"
git -C "$APP/source" switch --detach "$SHA"
bash "$DEPLOY" prepare "$SITE_HOME" "$SHA" "$SITE_HOME/.builds/$SHA"
```

## Back up, preflight, and activate

Rehearse loading the backup and applying the migrations on disposable MariaDB before activating this SHA. Review the pending migrations. MariaDB DDL may commit partway through a failed migration. On an existing app release, pause hPanel cron and run `php "$APP/current/artisan" down --retry=60` before taking the backup. Confirm any in-progress writes have drained. The initial bootstrap has no existing Laravel release to pause.

Prepare `private/staging-db-client.cnf` with `chmod 600`, containing a `[client]` stanza with the **same staging-only account** and host used by the staging `.env`. Verify the database selection and server version before producing the private backup. The exact staging database name is supplied independently to both the backup and release script.

```bash
BACKUP="$APP/shared/backups/$SHA"
CLIENT="$SITE_HOME/private/staging-db-client.cnf"
install -d -m 700 "$BACKUP"
mariadb --defaults-extra-file="$CLIENT" "$CANADAMB_STAGING_DB" \
  --execute='SELECT DATABASE(), VERSION()'
mariadb-dump --defaults-extra-file="$CLIENT" --single-transaction --skip-lock-tables \
  "$CANADAMB_STAGING_DB" > "$BACKUP/$CANADAMB_STAGING_DB.sql"
tar -czf "$BACKUP/storage.tar.gz" -C "$APP/shared" storage
bash "$DEPLOY" preflight "$SITE_HOME" "$SHA"
```

`migrate --pretend` can create Laravel's migration bookkeeping on an empty database. The script verifies the isolated database and backups before this step. Review the output before approving activation. Logs and saved backup checksums stay under the private application tree.

For first activation, inventory the current hosting files and preserve the existing checkout as a private backup. Verify that the website and alias both use this `public_html`, that `public_html` is a real directory, and that hPanel permits its replacement. Prepare an empty sibling directory or one containing only verified certificate challenge files. Use the same filesystem for both moves and keep a brief maintenance fallback available. These first-cutover commands move the entire old checkout and never republish it. Skip them on subsequent releases with an already managed public root.

```bash
WEB="$SITE_HOME/public_html"
NEXT_WEB="$SITE_HOME/.builds/public-$SHA"
QUARANTINE=$(mktemp -d "$SITE_HOME/private/legacy-web.XXXXXXXX")
install -d -m 755 "$NEXT_WEB"
test -d "$WEB" && test ! -L "$WEB" && test ! -L "$NEXT_WEB"
# Manually transfer any verified .well-known/acme-challenge token files to NEXT_WEB.
mv -T -- "$WEB" "$QUARANTINE/public_html" && mv -T -- "$NEXT_WEB" "$WEB"
CANADAMB_ACTIVATION_CONFIRMED="$SHA" bash "$DEPLOY" activate "$SITE_HOME" "$SHA"
```

If hPanel requires keeping the original directory inode, inventory and move its contents, including dotfiles, to the private quarantine under an agreed maintenance window. Publish the same clean allowlist into the managed directory. If the new root move fails, restore service with a clean maintenance placeholder while resolving the directory problem. Keep the original checkout in quarantine.

Activation applies the approved staging migrations and the idempotent seed, creates private Passport keys only when needed, and provisions exact site callbacks. It then activates the SHA in maintenance mode. Confirm PHP web configuration, both certificates, and the public allowlist before bringing the staged release up. There is no extra site access gate for this staging phase. The application sends `noindex` and `no-store` on staging pages. Create any test administrator with the interactive command and use staging data only.

```bash
php "$APP/current/artisan" network:deployment-check --hostinger \
  --staging-database="$CANADAMB_STAGING_DB" --database
php "$APP/current/artisan" network:admin REPLACE_WITH_STAGING_ADMIN_EMAIL
php "$APP/current/artisan" up
CANADAMB_SMOKE_STAGING=1 bash "$APP/current/deploy/smoke.sh"
php "$APP/current/artisan" schedule:list
```

## Cron and recovery

In hPanel, create a Custom cron at `* * * * *` if the actual account accepts it. Verify at least two executions and inspect their output. Ensure a slower plan interval still invokes the minute-based queue drain and confirm how it affects other schedules. Leave live news polling disabled. Run a controlled editorial-ingestion fixture and queue test with a private outcome. [Hostinger cron setup](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/)

```bash
printf '/usr/bin/bash %s/source/deploy/hostinger-cron.sh %s /usr/bin/php >> %s/shared/storage/logs/scheduler.log 2>&1\n' "$APP" "$SITE_HOME" "$APP"
```

The wrapper shares a lock with deployment and limits the entire run to 60 seconds including forced termination. The queue drains at most 25 jobs, stops when empty, and limits jobs to 30 seconds with CLI `pcntl`. Keep `DB_QUEUE_RETRY_AFTER` above 60 seconds. Monitor `queue:failed`, hPanel errors, and log rotation. Keep media and database backups outside `public_html`.

On migration, cache, or cutover failure, pause cron and retain the release log. Leave the current app in maintenance until the database is compatible with the intended release. A code-only rollback is safe only after schema compatibility is reviewed. To restore a compatible previous SHA, hold the release lock from a Bash terminal, then switch the private symlink and bring that release up.

```bash
ROLLBACK_SHA=REPLACE_WITH_COMPATIBLE_RELEASE_SHA
[[ $ROLLBACK_SHA =~ ^[0-9a-f]{40}$ ]] || exit 1
PREVIOUS="$APP/releases/$ROLLBACK_SHA"
test -f "$PREVIOUS/artisan" && test -f "$PREVIOUS/.prepared"
exec 9>"$APP/release.lock"
flock -n 9
php "$APP/current/artisan" down --retry=60
ln -s "$PREVIOUS" "$APP/.rollback-$ROLLBACK_SHA"
mv -Tf "$APP/.rollback-$ROLLBACK_SHA" "$APP/current"
php "$APP/current/artisan" up
flock -u 9
CANADAMB_SMOKE_STAGING=1 bash "$APP/current/deploy/smoke.sh"
```

Restore incompatible or partial changes into a verified staging-only destination using the rehearsed backup procedure or apply a reviewed forward fix. Preserve failed database and storage evidence before restoring. Keep the safe public root, the current deployment logs, and the checkpoints for both domains together. Resume cron after both smoke and application acceptance succeed.

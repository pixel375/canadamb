#!/usr/bin/env bash

# Run in Linux CI after publishing the exact checkout's Vite build locally.
set -euo pipefail
umask 077

repo=$(git rev-parse --show-toplevel)
commit=$(git rev-parse HEAD)
site=$(mktemp -d /tmp/canadamb-release-test.XXXXXXXX)
trap 'rm -rf -- "$site"' EXIT
app="$site/canadamb-app"
release="$app/releases/$commit"
export CANADAMB_STAGING_DB=canadamb_ci_staging
backup="$app/shared/backups/$commit"
mkdir -p "$site/public_html" "$backup"
git clone --quiet --no-hardlinks "$repo" "$app/source"

MYSQL_PWD="$CI_MARIADB_ROOT_PASSWORD" docker exec -i -e MYSQL_PWD "$CI_MARIADB_CONTAINER" mariadb -u root <<'SQL'
CREATE DATABASE canadamb_ci_staging;
CREATE USER 'release_probe'@'%' IDENTIFIED BY 'release-probe-test-only';
GRANT ALL PRIVILEGES ON `canadamb\_ci\_staging`.* TO 'release_probe'@'%';
SQL

key=$(php -r 'echo base64_encode(random_bytes(32));')
cat > "$app/shared/.env" <<ENV
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$key
APP_URL=https://canadamb.ca
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_DATABASE=$CANADAMB_STAGING_DB
DB_USERNAME=release_probe
DB_PASSWORD=release-probe-test-only
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CANADAMB_STAGING=true
CANADAMB_CRON_QUEUE_DRAIN=true
MAIL_MAILER=log
ENV

deploy() {
    bash "$repo/deploy/hostinger-release.sh" "$1" "$site" "$commit" "${@:2}"
}

expect_rejection() {
    if "$@"; then
        echo 'Expected the release operation to be rejected.' >&2
        exit 1
    fi
}

deploy prepare "$repo/public/build"
expect_rejection deploy preflight
MYSQL_PWD=release-probe-test-only docker exec -e MYSQL_PWD "$CI_MARIADB_CONTAINER" mariadb-dump -u release_probe "$CANADAMB_STAGING_DB" > "$backup/$CANADAMB_STAGING_DB.sql"
tar -czf "$backup/storage.tar.gz" -C "$app/shared" storage
deploy preflight

export CANADAMB_ACTIVATION_CONFIRMED="$commit"
mkdir "$site/public_html/.git"
expect_rejection deploy activate
test ! -e "$app/current"
rmdir "$site/public_html/.git"
deploy activate
test "$(readlink -f "$app/current")" = "$release"
test -f "$release/storage/framework/down"
test -f "$site/public_html/index.php"
test ! -e "$site/public_html/build/manifest.json"
php "$release/artisan" up

cat > "$site/dispatch-probe.php" <<'PHP'
<?php

require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Queue::connection('database')->push(Illuminate\Queue\CallQueuedClosure::create(static function () {
    file_put_contents(storage_path('logs/queue-probe'), 'processed');
}));
PHP
php "$site/dispatch-probe.php" "$release"
bash "$repo/deploy/hostinger-cron.sh" "$site" "$(command -v php)"
grep -qx processed "$app/shared/storage/logs/queue-probe"

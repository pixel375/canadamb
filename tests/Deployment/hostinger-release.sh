#!/usr/bin/env bash

# Run in Linux CI after publishing the exact checkout's Vite build locally.
set -euo pipefail
umask 077

repo=$(git rev-parse --show-toplevel)
target=$(git rev-parse HEAD)
commit=$target
scenario=${1:-staging}
member_migration=2026_11_21_000001_create_member_settings_table
inbox_migration=2026_11_21_000002_create_notification_inbox_tables
messaging_migration=2026_11_22_000001_expand_unified_messaging
decision_migration=2026_11_22_000002_create_moderation_decisions
reports_migration=2026_11_22_000002_create_moderation_reports
pending_migrations=()
if [[ $scenario == production-pass-02 ]]; then
    commit=f8497962b32b6720fc39f28814acf5a63c259d26
    pending_migrations=("$member_migration" "$inbox_migration")
elif [[ $scenario == production-pass-04 ]]; then
    commit=cc3112c036e26ef5ed4893451b7b4d735aa7d567
    pending_migrations=("$messaging_migration")
elif [[ $scenario == production-pass-05 ]]; then
    commit=013637b1278c24a2c572ed742f87d131fc2ff162
    pending_migrations=("$decision_migration" "$reports_migration")
fi
if (( ${#pending_migrations[@]} )); then
    if [[ $commit == "$target" ]] || ! git merge-base --is-ancestor "$commit" "$target"; then
        echo 'The rehearsal requires the exact baseline and a descendant candidate.' >&2
        exit 1
    fi
    for migration in "${pending_migrations[@]}"; do
        git cat-file -e "$target:database/migrations/$migration.php"
    done
fi
site=$(mktemp -d /tmp/canadamb-release-test.XXXXXXXX)
trap 'rm -rf -- "$site"' EXIT
app="$site/canadamb-app"
release="$app/releases/$commit"
export CANADAMB_DEPLOYMENT_MODE="$scenario"
case "$scenario" in
    staging)
        export CANADAMB_STAGING_DB=canadamb_ci_staging
        database=$CANADAMB_STAGING_DB
        database_option=staging-database
        staging=true
        prelaunch=false
        partner_user=release_probe
        ;;
    production-initial|production-pass-02|production-pass-04|production-pass-05)
        export CANADAMB_DEPLOYMENT_MODE=production-initial
        export CANADAMB_PRODUCTION_DB=canadamb_ci_production
        partner_user=production_probe
        if [[ $scenario == production-pass-02 ]]; then
            export CANADAMB_PRODUCTION_DB=canadamb_ci_pass02
            partner_user=pass02_probe
        elif [[ $scenario == production-pass-04 ]]; then
            export CANADAMB_PRODUCTION_DB=canadamb_ci_pass04
            partner_user=pass04_probe
        elif [[ $scenario == production-pass-05 ]]; then
            export CANADAMB_PRODUCTION_DB=canadamb_ci_pass05
            partner_user=pass05_probe
        fi
        database=$CANADAMB_PRODUCTION_DB
        database_option=production-database
        staging=false
        prelaunch=true
        ;;
    *) exit 2 ;;
esac
backup="$app/shared/backups/$commit"
mkdir -p "$site/public_html" "$backup"
git clone --quiet --no-hardlinks "$repo" "$app/source"

literal_database=${database//_/\\_}
MYSQL_PWD="$CI_MARIADB_ROOT_PASSWORD" docker exec -i -e MYSQL_PWD "$CI_MARIADB_CONTAINER" mariadb -u root <<SQL
CREATE DATABASE $database;
CREATE USER '$partner_user'@'%' IDENTIFIED BY 'release-probe-test-only';
GRANT ALL PRIVILEGES ON \`$literal_database\`.* TO '$partner_user'@'%';
SQL

key=$(php -r 'echo base64_encode(random_bytes(32));')
cat > "$app/shared/.env" <<ENV
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$key
APP_URL=https://canadamb.ca
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_DATABASE=$database
DB_USERNAME=$partner_user
DB_PASSWORD=release-probe-test-only
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CANADAMB_STAGING=$staging
CANADAMB_PRELAUNCH=$prelaunch
CANADAMB_PRELAUNCH_IPS=127.0.0.1
CANADAMB_CRON_QUEUE_DRAIN=true
MAIL_MAILER=smtp
ENV

deploy() {
    if [[ $commit != "$target" ]]; then
        git -C "$app/source" show "$commit:deploy/hostinger-release.sh" |
            bash -s -- "$1" "$site" "$commit" "${@:2}"
    else
        bash "$repo/deploy/hostinger-release.sh" "$1" "$site" "$commit" "${@:2}"
    fi
}

expect_rejection() {
    if "$@"; then
        echo 'Expected the release operation to be rejected.' >&2
        exit 1
    fi
}

# Regression guard: deployed scripts must not depend on /dev/fd process substitution.
if grep -Fq 'exec > >(' "$repo/deploy/hostinger-release.sh" ||
   grep -Fq 'tee /dev/stderr' "$repo/deploy/hostinger-release.sh"; then
    echo 'The Hostinger release script uses an unsupported /dev/fd-dependent pattern.' >&2
    exit 1
fi
release_script_checksum=$(sha256sum "$repo/deploy/hostinger-release.sh" | awk '{print $1}')
build="$repo/public/build"
if [[ $commit != "$target" ]]; then
    baseline="$site/baseline-source"
    mkdir "$baseline"
    git -C "$app/source" archive "$commit" | tar -x -C "$baseline"
    (cd "$baseline" && npm ci --prefer-offline && npm run build)
    build="$baseline/public/build"
    printf '%s\n' "$commit" > "$build/source-commit"
fi
deploy prepare "$build"
test -s "$repo/deploy/hostinger-release.sh"
expect_rejection deploy preflight
MYSQL_PWD=release-probe-test-only docker exec -e MYSQL_PWD "$CI_MARIADB_CONTAINER" mariadb-dump -u "$partner_user" "$database" > "$backup/$database.sql"
tar -czf "$backup/storage.tar.gz" -C "$app/shared" storage
deploy preflight
test -s "$release/.preflight"
test "$(sha256sum "$repo/deploy/hostinger-release.sh" | awk '{print $1}')" = "$release_script_checksum"
tables=$(MYSQL_PWD=release-probe-test-only docker exec -e MYSQL_PWD "$CI_MARIADB_CONTAINER" mariadb -N -u "$partner_user" "$database" -e 'SHOW FULL TABLES')
test -z "$tables"
echo 'Preflight left the empty database untouched.'

expect_rejection php "$release/artisan" network:deployment-check --hostinger "--$database_option=unexpected_staging"

export CANADAMB_ACTIVATION_CONFIRMED="$commit"
if [[ $CANADAMB_DEPLOYMENT_MODE == production-initial ]]; then
    expect_rejection deploy activate
    test ! -e "$app/current"
    export CANADAMB_PRODUCTION_CONFIRMED="$database"
fi
mkdir "$site/public_html/.git"
expect_rejection deploy activate
test ! -e "$app/current"
rmdir "$site/public_html/.git"
deploy activate
test -s "$repo/deploy/hostinger-release.sh"
test "$(sha256sum "$repo/deploy/hostinger-release.sh" | awk '{print $1}')" = "$release_script_checksum"
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

database_sql() {
    MYSQL_PWD=release-probe-test-only docker exec -i -e MYSQL_PWD "$CI_MARIADB_CONTAINER" \
        mariadb --batch --skip-column-names -u "$partner_user" "$database" "$@"
}
restore_sql() {
    MYSQL_PWD="$CI_MARIADB_ROOT_PASSWORD" docker exec -i -e MYSQL_PWD "$CI_MARIADB_CONTAINER" \
        mariadb --batch --skip-column-names -u root "$@"
}

if [[ $scenario == production-pass-05 ]]; then
    source "$repo/tests/Deployment/pass05-moderation.sh"
    exit 0
fi

if [[ $scenario == production-pass-04 ]]; then
    source "$repo/tests/Deployment/pass04-messaging.sh"
    exit 0
fi

if [[ $scenario == production-pass-02 ]]; then
    database_sql <<'SQL'
INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES
    (8101, 'Prelaunch Reader', 'ci-reader@example.invalid', 'disabled-fixture', '2026-01-01 09:00:00', '2026-01-01 09:00:00'),
    (8102, 'Discussion Author', 'ci-author@example.invalid', 'disabled-fixture', '2026-01-01 09:00:00', '2026-01-01 09:00:00');
INSERT INTO community_threads (id, author_id, location_id, title, body, category, status, last_activity_at)
    SELECT 8101, 8101, id, 'Legacy discussion', 'Disposable rehearsal fixture.', 'general', 'approved', '2026-01-01 09:20:00'
    FROM locations WHERE slug = 'brandon';
INSERT INTO community_replies (id, thread_id, author_id, body, status) VALUES
    (8101, 8101, 8102, 'Already read.', 'approved'),
    (8102, 8101, 8102, 'Still unread.', 'approved');
INSERT INTO community_notifications (id, user_id, reply_id, read_at, created_at) VALUES
    (8101, 8101, 8101, '2026-01-01 09:30:00', '2026-01-01 09:10:00'),
    (8102, 8101, 8102, NULL, '2026-01-01 09:20:00');
SQL
    legacy_query='SELECT id, user_id, reply_id, read_at, created_at FROM community_notifications ORDER BY id'
    legacy_rows=$'8101\t8101\t8101\t2026-01-01 09:30:00\t2026-01-01 09:10:00\n8102\t8101\t8102\tNULL\t2026-01-01 09:20:00'
    inventory_query="SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('member_settings','notifications','notification_locations') ORDER BY BINARY TABLE_NAME"
    migration_query="SELECT migration FROM migrations WHERE migration IN ('$member_migration','$inbox_migration') ORDER BY migration"
    legacy_schema=$(database_sql -e 'SHOW CREATE TABLE community_notifications')
    test "$(database_sql -e "$legacy_query")" = "$legacy_rows"
    test -z "$(database_sql -e "$inventory_query")"
    test -z "$(database_sql -e "$migration_query")"
    printf '%s\n' 'Pass 02 media rehearsal' > "$app/shared/storage/app/private/pass02-probe.txt"
    root_inode=$(stat -c '%i' "$site/public_html")
    key_hashes=$(sha256sum "$app/shared/storage/"oauth-{private,public}.key)

    export CANADAMB_PHP_BIN="$(command -v php)"
    export CANADAMB_COMPOSER_BIN="$(command -v composer)"
    unset CANADAMB_STAGING_DB
    upgrade=(bash "$repo/deploy/hostinger-upgrade.sh" "$site" "$target" "$repo/public/build")
    expect_rejection "${upgrade[@]}"
    test "$(readlink -f "$app/current")" = "$release"
    test ! -e "$app/releases/$target"
    test ! -e "$app/update.pause"
    test ! -e "$release/storage/framework/down"
    test -z "$(database_sql -e "$inventory_query")"
    test -z "$(database_sql -e "$migration_query")"
    test "$(database_sql -e "$legacy_query")" = "$legacy_rows"
    echo 'Default production upgrade refused the pending Pass 02 migrations.'

    mkdir "$site/pending-migrations"
    git -C "$app/source" archive "$target" "database/migrations/$member_migration.php" "database/migrations/$inbox_migration.php" |
        tar -x -C "$site/pending-migrations"
    php "$release/artisan" migrate --pretend --force --no-ansi --realpath --path="$site/pending-migrations/database/migrations"
    test -z "$(database_sql -e "$migration_query")"
    test -z "$(database_sql -e "$inventory_query")"

    # Explicit authorization is limited to the exact candidate in this disposable CI schema.
    "${upgrade[@]}" true
    test "$(readlink -f "$app/current")" = "$app/releases/$target"
    test "$(stat -c '%i' "$site/public_html")" = "$root_inode"
    test "$(sha256sum "$app/shared/storage/"oauth-{private,public}.key)" = "$key_hashes"
    test ! -e "$app/update.pause"
    test ! -e "$app/releases/$target/storage/framework/down"
    test "$(database_sql -e "$legacy_query")" = "$legacy_rows"
    test "$(database_sql -e 'SHOW CREATE TABLE community_notifications')" = "$legacy_schema"
    test "$(database_sql -e "$migration_query")" = "$member_migration"$'\n'"$inbox_migration"
    test "$(database_sql -e "$inventory_query")" = $'member_settings\nnotification_locations\nnotifications'
    for table in member_settings notifications notification_locations; do
        test "$(database_sql -e "SELECT COUNT(*) FROM $table")" = 0
    done
    indexes=$(database_sql <<'SQL'
SELECT CONCAT(TABLE_NAME, ':', NON_UNIQUE, ':', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX))
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('member_settings','notifications','notification_locations')
GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE;
SQL
    )
    for index in \
        member_settings:0:user_id \
        notification_locations:0:notification_id,location_id \
        notification_locations:1:location_id,notification_id \
        notifications:0:user_id,event,source_id \
        notifications:1:user_id,created_at,id \
        notifications:1:user_id,read_at,created_at,id; do
        grep -qxF "$index" <<< "$indexes"
    done
    test "$(cat "$app/shared/storage/app/private/pass02-probe.txt")" = 'Pass 02 media rehearsal'

    upgrade_backup="$app/shared/backups/$target"
    (cd "$upgrade_backup" && sha256sum -c checksums.sha256)
    mkdir "$site/restored-media"
    tar -xzf "$upgrade_backup/storage.tar.gz" -C "$site/restored-media"
    cmp -s "$site/restored-media/storage/app/private/pass02-probe.txt" "$app/shared/storage/app/private/pass02-probe.txt"
    for key_file in oauth-{private,public}.key; do
        cmp -s "$site/restored-media/storage/$key_file" "$app/shared/storage/$key_file"
    done
    restore_database=canadamb_ci_pass02_restore
    restore_sql -e "CREATE DATABASE $restore_database"
    restore_sql "$restore_database" < "$upgrade_backup/$database.sql"
    test -z "$(restore_sql "$restore_database" -e "$inventory_query")"
    test -z "$(restore_sql "$restore_database" -e "$migration_query")"
    test "$(restore_sql "$restore_database" -e "$legacy_query")" = "$legacy_rows"
    test "$(restore_sql "$restore_database" -e 'SHOW CREATE TABLE community_notifications')" = "$legacy_schema"
    test "$(restore_sql "$restore_database" -e 'SELECT host FROM domains WHERE host IN ("canadamb.ca", "brandonmb.ca") ORDER BY host')" = $'brandonmb.ca\ncanadamb.ca'
    echo 'Pass 02 upgrade preserved legacy read state and restored the pre-migration database and media backups in isolation.'
    mkdir "$site/notification-race"
    php "$repo/tests/Deployment/pass02-notifications.php" "$app/releases/$target" "$site/notification-race"
    exit 0
fi

if [[ $CANADAMB_DEPLOYMENT_MODE == production-initial ]]; then
    MYSQL_PWD=release-probe-test-only docker exec -e MYSQL_PWD "$CI_MARIADB_CONTAINER" mariadb-dump -u "$partner_user" "$database" > "$site/installed.sql"
    MYSQL_PWD="$CI_MARIADB_ROOT_PASSWORD" docker exec -e MYSQL_PWD "$CI_MARIADB_CONTAINER" mariadb -u root -e 'CREATE DATABASE canadamb_ci_restore'
    MYSQL_PWD="$CI_MARIADB_ROOT_PASSWORD" docker exec -i -e MYSQL_PWD "$CI_MARIADB_CONTAINER" mariadb -u root canadamb_ci_restore < "$site/installed.sql"
    domains=$(MYSQL_PWD="$CI_MARIADB_ROOT_PASSWORD" docker exec -e MYSQL_PWD "$CI_MARIADB_CONTAINER" mariadb -N -u root canadamb_ci_restore -e 'SELECT host FROM domains WHERE host IN ("canadamb.ca", "brandonmb.ca") ORDER BY host')
    test "$domains" = $'brandonmb.ca\ncanadamb.ca'
    echo 'Restored the installed database to a disposable schema and verified both domains.'

    # Simulate the NEXT release on an installation which already contains data.
    # This is a local-only empty commit with identical source, not a production push.
    php -r '
      require $argv[1]."/vendor/autoload.php";
      $app = require $argv[1]."/bootstrap/app.php";
      $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
      Illuminate\Support\Facades\DB::table("users")->insert([
        "name" => "Prelaunch User",
        "email" => "ci-update@example.invalid",
        "password" => password_hash("test-only", PASSWORD_BCRYPT),
        "created_at" => now(),
        "updated_at" => now(),
      ]);
    ' "$release"
    test "$(MYSQL_PWD=release-probe-test-only docker exec -e MYSQL_PWD "$CI_MARIADB_CONTAINER" mariadb -N -u "$partner_user" "$database" -e 'SELECT count(*) FROM users WHERE email="ci-update@example.invalid"')" = 1
    git -C "$app/source" config user.email 'ci@example.invalid'
    git -C "$app/source" config user.name 'Release rehearsal'
    git -C "$app/source" commit --allow-empty -m 'simulated next release' --quiet
    next=$(git -C "$app/source" rev-parse HEAD)
    install -d "$site/.builds/$next"
    cp -a "$repo/public/build/." "$site/.builds/$next/"
    printf '%s\n' "$next" > "$site/.builds/$next/source-commit"
    old_inode=$(stat -c '%i' "$site/public_html")
    old_key_hash=$(sha256sum "$app/shared/storage/oauth-private.key" | cut -d' ' -f1)
    export CANADAMB_PRODUCTION_DB="$database"
    export CANADAMB_PHP_BIN="$(command -v php)"
    export CANADAMB_COMPOSER_BIN="$(command -v composer)"
    unset CANADAMB_STAGING_DB
    bash "$repo/deploy/hostinger-upgrade.sh" "$site" "$next" "$site/.builds/$next" false
    test "$(readlink -f "$app/current")" = "$app/releases/$next"
    test "$(stat -c '%i' "$site/public_html")" = "$old_inode"
    test "$(sha256sum "$app/shared/storage/oauth-private.key" | cut -d' ' -f1)" = "$old_key_hash"
    test ! -f "$app/update.pause"
    test ! -f "$app/releases/$next/storage/framework/down"
    test -s "$app/shared/backups/$next/$database.sql"
    tar -tzf "$app/shared/backups/$next/storage.tar.gz" >/dev/null
    test "$(MYSQL_PWD=release-probe-test-only docker exec -e MYSQL_PWD "$CI_MARIADB_CONTAINER" mariadb -N -u "$partner_user" "$database" -e 'SELECT count(*) FROM users WHERE email="ci-update@example.invalid"')" = 1
    echo 'Production-update rehearsal preserved user data, private keys and the public_html inode.'
fi

#!/usr/bin/env bash

[[ $scenario == production-pass-08 && $database == canadamb_ci_pass08 ]]
expected_schema_diff=$(printf 'A\tdatabase/migrations/%s.php' "$business_import_migration")
if git -C "$repo" cat-file -e "$target:database/migrations/$team_migration.php" 2>/dev/null; then
    expected_schema_diff+=

source "$repo/tests/Deployment/pass05-legacy-fixture.sh"
database_sql < "$repo/tests/Deployment/pass06-discussions.sql"
database_sql <<'SQL'
INSERT INTO business_sources (id,name,url,license,created_at,updated_at)
    VALUES (8501,'Pass 07 source fixture','https://example.invalid','Legacy fixture only','2026-01-01 00:00:00','2026-01-01 00:00:00');
UPDATE business_locations SET source_id=8501, external_key='OLD-KEY', location_status='imported_address',
    address_key=SHA2('legacy business|1 rehearsal street',256), imported_at='2026-01-01 09:00:00' WHERE id=8501;
INSERT INTO business_import_runs (id,source_id,status,created,skipped,created_at,updated_at)
    VALUES (8501,8501,'succeeded',1,0,'2026-01-01 09:00:00','2026-01-01 09:01:00');
UPDATE community_events SET thread_id=8501 WHERE id=8501;
SQL

tables=(business_sources businesses business_locations business_import_runs business_memberships business_claims business_updates
    job_postings conversations messages message_attachments conversation_reports message_reads message_send_replays notifications
    notification_locations community_threads community_replies community_events content_reports moderation_decisions moderation_report_decisions)
{
    for table in "${tables[@]}"; do
        columns=$(database_sql -e "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' ORDER BY ORDINAL_POSITION" | paste -sd, -)
        printf 'SELECT %s FROM %s ORDER BY %s;\n' "$columns" "$table" "$columns"
    done
} > "$site/pass08-snapshot.sql"
database_sql < "$site/pass08-snapshot.sql" > "$site/pass08-before.txt"
before_ddl=$(database_sql -e 'SHOW CREATE TABLE businesses')
migrations=$(database_sql -e 'SELECT migration,batch FROM migrations ORDER BY id')
root_inode=$(stat -c '%i' "$site/public_html")
(cd "$app/shared/storage" && sha256sum oauth-private.key oauth-public.key "${media[@]/#/app/private/}") > "$site/pass08-media.sha256"
table_check="SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='business_import_rows'"
team_table_check="SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='business_team_invitations'"

export CANADAMB_PHP_BIN="$(command -v php)"
export CANADAMB_COMPOSER_BIN="$(command -v composer)"
upgrade=(bash "$repo/deploy/hostinger-upgrade.sh" "$site" "$target" "$repo/public/build")
expect_rejection "${upgrade[@]}" false > "$site/pass08-denied.log" 2>&1
grep -F 'Database migrations or seeders differ.' "$site/pass08-denied.log"
test "$(readlink -f "$app/current")" = "$release"
test "$(database_sql -e "$table_check")" = 0
test "$(database_sql -e "$team_table_check")" = 0
test ! -e "$app/releases/$target"
echo 'Default migration guard refused the reviewed business import migration.'

mkdir "$site/pending-migrations"
migration_paths=("database/migrations/$business_import_migration.php")
if git -C "$repo" cat-file -e "$target:database/migrations/$team_migration.php" 2>/dev/null; then
    migration_paths+=("database/migrations/$team_migration.php")
fi
git -C "$app/source" archive "$target" "${migration_paths[@]}" | tar -x -C "$site/pending-migrations"
php "$release/artisan" migrate --pretend --force --no-ansi --realpath --path="$site/pending-migrations/database/migrations"
test "$(database_sql -e "$table_check")" = 0
test "$(database_sql -e 'SHOW CREATE TABLE businesses')" = "$before_ddl"
database_sql < "$site/pass08-snapshot.sql" > "$site/pass08-pretend.txt"
cmp "$site/pass08-before.txt" "$site/pass08-pretend.txt"

# The reviewed-change switch applies only to this disposable database.
"${upgrade[@]}" true
test "$(readlink -f "$app/current")" = "$app/releases/$target"
test "$(stat -c '%i' "$site/public_html")" = "$root_inode"
test ! -e "$app/update.pause"
test -s "$app/releases/$target/.last-preflight"
test "$(database_sql -e "$table_check")" = 1
test "$(database_sql -e "SELECT COUNT(*) FROM migrations WHERE migration='$business_import_migration'")" = 1
if git -C "$repo" cat-file -e "$target:database/migrations/$team_migration.php" 2>/dev/null; then
    test "$(database_sql -e "$team_table_check")" = 1
    test "$(database_sql -e "SELECT COUNT(*) FROM migrations WHERE migration='$team_migration'")" = 1
fi
test "$(database_sql -e 'SELECT import_name_key FROM businesses WHERE id=8501')" = "$(database_sql -e "SELECT SHA2('legacy business',256)")"
test "$(database_sql -e 'SELECT import_key FROM business_locations WHERE id=8501')" = "$(database_sql -e "SELECT SHA2('old-key',256)")"
test "$(database_sql -e 'SELECT COUNT(*) FROM business_sources WHERE approved_revision IS NOT NULL')" = 0
database_sql < "$site/pass08-snapshot.sql" > "$site/pass08-after.txt"
cmp "$site/pass08-before.txt" "$site/pass08-after.txt"
(cd "$app/shared/storage" && sha256sum -c "$site/pass08-media.sha256")
echo 'Pass 08 preserved existing profiles, branches, ownership, import provenance, Jobs and private Messaging.'

backup="$app/shared/backups/$target"
(cd "$backup" && sha256sum -c checksums.sha256)
mkdir "$site/pass08-restored"
tar -xzf "$backup/storage.tar.gz" -C "$site/pass08-restored"
(cd "$site/pass08-restored/storage" && sha256sum -c "$site/pass08-media.sha256")
restore_database=canadamb_ci_pass08_restore
restore_sql -e "CREATE DATABASE $restore_database"
restore_sql "$restore_database" < "$backup/$database.sql"
test "$(restore_sql "$restore_database" -e "$table_check")" = 0
test "$(restore_sql "$restore_database" -e "$team_table_check")" = 0
test "$(restore_sql "$restore_database" -e 'SHOW CREATE TABLE businesses')" = "$before_ddl"
test "$(restore_sql "$restore_database" -e 'SELECT migration,batch FROM migrations ORDER BY id')" = "$migrations"
restore_sql "$restore_database" < "$site/pass08-snapshot.sql" > "$site/pass08-restore.txt"
cmp "$site/pass08-before.txt" "$site/pass08-restore.txt"
echo 'Restored the exact populated Pass 07 database and private media in isolation.'

mkdir "$site/import-race"
php -d memory_limit=128M "$repo/tests/Deployment/pass08-import-race.php" "$app/releases/$target" "$site/import-race"
mkdir "$site/claim-race"
php "$repo/tests/Deployment/pass08-claim-race.php" "$app/releases/$target" "$site/claim-race"
\n'$(printf 'A\tdatabase/migrations/%s.php' "$team_migration")
fi
test "$(git -C "$repo" diff --name-status --no-renames "$commit" "$target" -- database/migrations database/seeders)" \
    = "$expected_schema_diff"

source "$repo/tests/Deployment/pass05-legacy-fixture.sh"
database_sql < "$repo/tests/Deployment/pass06-discussions.sql"
database_sql <<'SQL'
INSERT INTO business_sources (id,name,url,license,created_at,updated_at)
    VALUES (8501,'Pass 07 source fixture','https://example.invalid','Legacy fixture only','2026-01-01 00:00:00','2026-01-01 00:00:00');
UPDATE business_locations SET source_id=8501, external_key='OLD-KEY', location_status='imported_address',
    address_key=SHA2('legacy business|1 rehearsal street',256), imported_at='2026-01-01 09:00:00' WHERE id=8501;
INSERT INTO business_import_runs (id,source_id,status,created,skipped,created_at,updated_at)
    VALUES (8501,8501,'succeeded',1,0,'2026-01-01 09:00:00','2026-01-01 09:01:00');
UPDATE community_events SET thread_id=8501 WHERE id=8501;
SQL

tables=(business_sources businesses business_locations business_import_runs business_memberships business_claims business_updates
    job_postings conversations messages message_attachments conversation_reports message_reads message_send_replays notifications
    notification_locations community_threads community_replies community_events content_reports moderation_decisions moderation_report_decisions)
{
    for table in "${tables[@]}"; do
        columns=$(database_sql -e "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' ORDER BY ORDINAL_POSITION" | paste -sd, -)
        printf 'SELECT %s FROM %s ORDER BY %s;\n' "$columns" "$table" "$columns"
    done
} > "$site/pass08-snapshot.sql"
database_sql < "$site/pass08-snapshot.sql" > "$site/pass08-before.txt"
before_ddl=$(database_sql -e 'SHOW CREATE TABLE businesses')
migrations=$(database_sql -e 'SELECT migration,batch FROM migrations ORDER BY id')
root_inode=$(stat -c '%i' "$site/public_html")
(cd "$app/shared/storage" && sha256sum oauth-private.key oauth-public.key "${media[@]/#/app/private/}") > "$site/pass08-media.sha256"
table_check="SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='business_import_rows'"

export CANADAMB_PHP_BIN="$(command -v php)"
export CANADAMB_COMPOSER_BIN="$(command -v composer)"
upgrade=(bash "$repo/deploy/hostinger-upgrade.sh" "$site" "$target" "$repo/public/build")
expect_rejection "${upgrade[@]}" false > "$site/pass08-denied.log" 2>&1
grep -F 'Database migrations or seeders differ.' "$site/pass08-denied.log"
test "$(readlink -f "$app/current")" = "$release"
test "$(database_sql -e "$table_check")" = 0
test ! -e "$app/releases/$target"
echo 'Default migration guard refused the reviewed business import migration.'

mkdir "$site/pending-migrations"
git -C "$app/source" archive "$target" "database/migrations/$business_import_migration.php" | tar -x -C "$site/pending-migrations"
php "$release/artisan" migrate --pretend --force --no-ansi --realpath --path="$site/pending-migrations/database/migrations"
test "$(database_sql -e "$table_check")" = 0
test "$(database_sql -e 'SHOW CREATE TABLE businesses')" = "$before_ddl"
database_sql < "$site/pass08-snapshot.sql" > "$site/pass08-pretend.txt"
cmp "$site/pass08-before.txt" "$site/pass08-pretend.txt"

# The reviewed-change switch applies only to this disposable database.
"${upgrade[@]}" true
test "$(readlink -f "$app/current")" = "$app/releases/$target"
test "$(stat -c '%i' "$site/public_html")" = "$root_inode"
test ! -e "$app/update.pause"
test -s "$app/releases/$target/.last-preflight"
test "$(database_sql -e "$table_check")" = 1
test "$(database_sql -e "SELECT COUNT(*) FROM migrations WHERE migration='$business_import_migration'")" = 1
test "$(database_sql -e 'SELECT import_name_key FROM businesses WHERE id=8501')" = "$(database_sql -e "SELECT SHA2('legacy business',256)")"
test "$(database_sql -e 'SELECT import_key FROM business_locations WHERE id=8501')" = "$(database_sql -e "SELECT SHA2('old-key',256)")"
test "$(database_sql -e 'SELECT COUNT(*) FROM business_sources WHERE approved_revision IS NOT NULL')" = 0
database_sql < "$site/pass08-snapshot.sql" > "$site/pass08-after.txt"
cmp "$site/pass08-before.txt" "$site/pass08-after.txt"
(cd "$app/shared/storage" && sha256sum -c "$site/pass08-media.sha256")
echo 'Pass 08 preserved existing profiles, branches, ownership, import provenance, Jobs and private Messaging.'

backup="$app/shared/backups/$target"
(cd "$backup" && sha256sum -c checksums.sha256)
mkdir "$site/pass08-restored"
tar -xzf "$backup/storage.tar.gz" -C "$site/pass08-restored"
(cd "$site/pass08-restored/storage" && sha256sum -c "$site/pass08-media.sha256")
restore_database=canadamb_ci_pass08_restore
restore_sql -e "CREATE DATABASE $restore_database"
restore_sql "$restore_database" < "$backup/$database.sql"
test "$(restore_sql "$restore_database" -e "$table_check")" = 0
test "$(restore_sql "$restore_database" -e 'SHOW CREATE TABLE businesses')" = "$before_ddl"
test "$(restore_sql "$restore_database" -e 'SELECT migration,batch FROM migrations ORDER BY id')" = "$migrations"
restore_sql "$restore_database" < "$site/pass08-snapshot.sql" > "$site/pass08-restore.txt"
cmp "$site/pass08-before.txt" "$site/pass08-restore.txt"
echo 'Restored the exact populated Pass 07 database and private media in isolation.'

mkdir "$site/import-race"
php -d memory_limit=128M "$repo/tests/Deployment/pass08-import-race.php" "$app/releases/$target" "$site/import-race"
mkdir "$site/claim-race"
php "$repo/tests/Deployment/pass08-claim-race.php" "$app/releases/$target" "$site/claim-race"

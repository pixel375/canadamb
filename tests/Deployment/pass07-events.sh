#!/usr/bin/env bash

# Rehearse the only Pass 07 migration on the isolated GitHub Actions database.
[[ $scenario == production-pass-07 && $database == canadamb_ci_pass07 ]]
test "$(git -C "$repo" diff --name-status --no-renames "$commit" "$target" -- database/migrations database/seeders)" \
    = "$(printf 'A\tdatabase/migrations/%s.php' "$events_migration")"

source "$repo/tests/Deployment/pass05-legacy-fixture.sh"
database_sql < "$repo/tests/Deployment/pass06-discussions.sql"
database_sql <<'SQL'
INSERT INTO community_events (id, author_id, location_id, title, body, category, venue, organizer, event_url, starts_at, ends_at, status, cancelled_at, cancellation_note, created_at, updated_at)
    SELECT 8502, author_id, location_id, 'Cancelled legacy event', body, category, venue, organizer,
        'https://example.invalid/legacy-event', starts_at, ends_at, 'cancelled',
        '2026-01-01 11:00:00', 'Historical cancellation', created_at, updated_at
    FROM community_events WHERE id = 8501;
INSERT INTO content_reports (id, source_type, source_id, location_id, reporter_id, reason, created_at)
    SELECT 8504, 'event', id, location_id, 8502, 'Historical report on a standalone event', '2026-01-01 11:30:00'
    FROM community_events WHERE id = 8501;
INSERT INTO notifications (id,user_id,event,source_id,resource_id,read_at,created_at)
    VALUES (8515, 8501, 'event.approved', 8516, 8501, '2026-01-01 11:25:00', '2026-01-01 11:20:00');
INSERT INTO notification_locations (notification_id, location_id)
    SELECT 8515, location_id FROM community_events WHERE id=8501;
SQL

inventory="SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY BINARY TABLE_NAME"
tables=$(database_sql -e "$inventory")
tracked=(
    users:id sites:id domains:id locations:id oauth_clients:id network_configuration:id configuration_revisions:id
    listings:id listing_status_history:id listing_media:id listing_reports:id listing_favourites:user_id,listing_id
    businesses:id business_locations:id business_memberships:business_id,user_id business_claims:id business_updates:id job_postings:id
    community_threads:id community_replies:id community_thread_follows:thread_id,user_id community_notifications:id
    conversations:id messages:id message_attachments:id conversation_reports:id message_reads:message_id,user_id
    message_send_replays:sender_id,idempotency_key member_settings:user_id notifications:id notification_locations:notification_id,location_id
    content_reports:id moderation_decisions:id moderation_report_decisions:id audit_logs:id
)
legacy_event_columns=$(database_sql -e "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='community_events' ORDER BY ORDINAL_POSITION" | paste -sd, -)
snapshot() {
    local table entry
    {
        for table in $tables; do
            [[ $table == community_events || $table == migrations ]] || printf 'SHOW CREATE TABLE %s;\n' "$table"
        done
        for entry in "${tracked[@]}"; do
            printf 'SELECT * FROM %s ORDER BY %s;\n' "${entry%:*}" "${entry#*:}"
        done
        printf 'SELECT %s FROM community_events ORDER BY id;\n' "$legacy_event_columns"
    } | "$@"
}

root_inode=$(stat -c '%i' "$site/public_html")
snapshot database_sql > "$site/pass07-before.txt"
before_ddl=$(database_sql -e 'SHOW CREATE TABLE community_events')
migrations=$(database_sql -e 'SELECT migration,batch FROM migrations ORDER BY id')
(cd "$app/shared/storage" && sha256sum oauth-private.key oauth-public.key "${media[@]/#/app/private/}") > "$site/pass07-media.sha256"
column="SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='community_events' AND COLUMN_NAME='thread_id'"
test "$(database_sql -e "$column")" = 0

export CANADAMB_PHP_BIN="$(command -v php)"
export CANADAMB_COMPOSER_BIN="$(command -v composer)"
upgrade=(bash "$repo/deploy/hostinger-upgrade.sh" "$site" "$target" "$repo/public/build")
expect_rejection "${upgrade[@]}" false > "$site/pass07-denied.log" 2>&1
grep -F 'Database migrations or seeders differ.' "$site/pass07-denied.log"
test "$(readlink -f "$app/current")" = "$release"
test ! -e "$app/releases/$target"
test "$(database_sql -e "$column")" = 0
echo 'Default production guard refused the Pass 07 linked-event migration.'

mkdir "$site/pending-migrations"
git -C "$app/source" archive "$target" "database/migrations/$events_migration.php" | tar -x -C "$site/pending-migrations"
php "$release/artisan" migrate --pretend --force --no-ansi --realpath --path="$site/pending-migrations/database/migrations"
test "$(database_sql -e "$column")" = 0
test "$(database_sql -e 'SHOW CREATE TABLE community_events')" = "$before_ddl"
snapshot database_sql > "$site/pass07-pretend.txt"
cmp "$site/pass07-before.txt" "$site/pass07-pretend.txt"

# This switch is confined to the disposable CI site and database.
"${upgrade[@]}" true
test "$(readlink -f "$app/current")" = "$app/releases/$target"
test "$(stat -c '%i' "$site/public_html")" = "$root_inode"
test -s "$app/releases/$target/.last-preflight"
test ! -e "$app/update.pause"
test ! -e "$app/releases/$target/storage/framework/down"
test "$(database_sql -e "$column")" = 1
test "$(database_sql -e "SELECT COUNT(*) FROM migrations WHERE migration='$events_migration'")" = 1
test "$(database_sql -e 'SELECT COUNT(*) FROM community_events WHERE thread_id IS NOT NULL')" = 0
test "$(database_sql -e "$inventory")" = "$tables"
test "$(database_sql -e "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='community_events_thread_id_foreign'")" = RESTRICT
snapshot database_sql > "$site/pass07-after.txt"
cmp "$site/pass07-before.txt" "$site/pass07-after.txt"
(cd "$app/shared/storage" && sha256sum -c "$site/pass07-media.sha256")
echo 'Pass 07 preserved legacy event IDs, dates, cancellation notes, reports, read states, discussions and private media.'

upgrade_backup="$app/shared/backups/$target"
(cd "$upgrade_backup" && sha256sum -c checksums.sha256)
mkdir "$site/pass07-restored"
tar -xzf "$upgrade_backup/storage.tar.gz" -C "$site/pass07-restored"
(cd "$site/pass07-restored/storage" && sha256sum -c "$site/pass07-media.sha256")
restore_database=canadamb_ci_pass07_restore
restore_sql -e "CREATE DATABASE $restore_database"
restore_sql "$restore_database" < "$upgrade_backup/$database.sql"
test "$(restore_sql "$restore_database" -e "$column")" = 0
test "$(restore_sql "$restore_database" -e 'SHOW CREATE TABLE community_events')" = "$before_ddl"
test "$(restore_sql "$restore_database" -e 'SELECT migration,batch FROM migrations ORDER BY id')" = "$migrations"
snapshot restore_sql "$restore_database" > "$site/pass07-restored.txt"
cmp "$site/pass07-before.txt" "$site/pass07-restored.txt"
echo 'Restored the exact populated Pass 06 SQL, OAuth keys and private media in isolation.'

mkdir "$site/event-races"
php "$repo/tests/Deployment/pass07-event-race.php" "$app/releases/$target" "$site/event-races"

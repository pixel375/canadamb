#!/usr/bin/env bash

# Sourced after the exact corrected Pass 05 release is running in the CI database.
[[ $scenario == production-pass-06 && $database == canadamb_ci_pass06 ]]
git -C "$app/source" diff --exit-code "$commit" "$target" -- database/migrations/ database/seeders/

source "$repo/tests/Deployment/pass05-legacy-fixture.sh"
database_sql < "$repo/tests/Deployment/pass06-discussions.sql"

inventory_query="SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY BINARY TABLE_NAME"
tables=$(database_sql -e "$inventory_query")
data_tables=(
    migrations:id users:id locations:id sites:id domains:id oauth_clients:id network_configuration:id configuration_revisions:id
    listings:id listing_status_history:id listing_media:id listing_favourites:user_id,listing_id listing_reports:id
    businesses:id business_locations:id business_memberships:business_id,user_id business_claims:id business_updates:id job_postings:id
    community_threads:id community_replies:id community_thread_follows:thread_id,user_id community_notifications:id community_events:id
    conversations:id messages:id message_attachments:id conversation_reports:id message_reads:message_id,user_id
    message_send_replays:sender_id,idempotency_key member_settings:user_id notifications:id notification_locations:notification_id,location_id
    content_reports:id moderation_decisions:id moderation_report_decisions:id audit_logs:id
)

snapshot() {
    local table ordered_table
    {
        while IFS= read -r table; do
            printf 'SHOW CREATE TABLE %s;\n' "$table"
        done <<< "$tables"
        for ordered_table in "${data_tables[@]}"; do
            printf 'SELECT * FROM %s ORDER BY %s;\n' "${ordered_table%:*}" "${ordered_table#*:}"
        done
    } | "$@"
}

test "$(database_sql -e "SELECT COUNT(*) FROM migrations WHERE migration IN ('$decision_migration', '$reports_migration')")" = 2
before="$site/pass06-before.txt"
snapshot database_sql > "$before"
root_inode=$(stat -c '%i' "$site/public_html")
(cd "$app/shared/storage" && sha256sum "oauth-private.key" "oauth-public.key" "${media[@]/#/app/private/}") > "$site/pass06-storage.sha256"

export CANADAMB_PHP_BIN="$(command -v php)"
export CANADAMB_COMPOSER_BIN="$(command -v composer)"
bash "$repo/deploy/hostinger-upgrade.sh" "$site" "$target" "$repo/public/build" false

test "$(readlink -f "$app/current")" = "$app/releases/$target"
test "$(stat -c '%i' "$site/public_html")" = "$root_inode"
test -s "$app/releases/$target/.last-preflight"
test ! -e "$app/update.pause"
test ! -e "$app/releases/$target/storage/framework/down"
test "$(database_sql -e "$inventory_query")" = "$tables"
snapshot database_sql > "$site/pass06-after.txt"
cmp "$before" "$site/pass06-after.txt"
(cd "$app/shared/storage" && sha256sum -c "$site/pass06-storage.sha256")
echo 'Pass 06 activated with database-change approval disabled and preserved the schema and populated Pass 05 records.'

upgrade_backup="$app/shared/backups/$target"
(cd "$upgrade_backup" && sha256sum -c checksums.sha256)
mkdir "$site/pass06-restored"
tar -xzf "$upgrade_backup/storage.tar.gz" -C "$site/pass06-restored"
(cd "$site/pass06-restored/storage" && sha256sum -c "$site/pass06-storage.sha256")
restore_database=canadamb_ci_pass06_restore
restore_sql -e "CREATE DATABASE $restore_database"
restore_sql "$restore_database" < "$upgrade_backup/$database.sql"
test "$(restore_sql "$restore_database" -e "$inventory_query")" = "$tables"
snapshot restore_sql "$restore_database" > "$site/pass06-restored.txt"
cmp "$before" "$site/pass06-restored.txt"
echo 'Restored the populated Pass 05 SQL, OAuth keys and private WebP files in isolation.'

echo 'MariaDB reply pagination and direct-link count plans (2,500 additional mixed-status replies):'
database_sql --column-names <<'SQL'
EXPLAIN SELECT * FROM community_replies WHERE thread_id = 8501 AND status = 'approved' ORDER BY id LIMIT 25 OFFSET 475;
EXPLAIN SELECT COUNT(*) FROM community_replies WHERE thread_id = 8501 AND status = 'approved' AND id < 9600;
SQL

mkdir "$site/discussion-race"
php "$repo/tests/Deployment/pass06-discussion-race.php" "$app/releases/$target" "$site/discussion-race"

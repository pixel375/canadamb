#!/usr/bin/env bash

# Sourced from hostinger-release.sh after the exact corrected Pass 04 baseline is running.
[[ $scenario == production-pass-05 && $database == canadamb_ci_pass05 ]]

source "$repo/tests/Deployment/pass05-legacy-fixture.sh"

tables=(
    users:id domains:id network_configuration:id configuration_revisions:id
    listings:id listing_status_history:id listing_media:id listing_favourites:user_id,listing_id listing_reports:id
    businesses:id business_locations:id business_memberships:business_id,user_id business_claims:id business_updates:id job_postings:id
    community_threads:id community_replies:id community_thread_follows:thread_id,user_id community_notifications:id community_events:id
    conversations:id messages:id message_attachments:id conversation_reports:id message_reads:message_id,user_id
    message_send_replays:sender_id,idempotency_key member_settings:user_id notifications:id notification_locations:notification_id,location_id audit_logs:id
)

snapshot_legacy() {
    local ordered_table table order
    {
        for ordered_table in "${tables[@]}"; do
            table=${ordered_table%:*}
            order=${ordered_table#*:}
            printf 'SHOW CREATE TABLE %s; SELECT * FROM %s ORDER BY %s;\n' "$table" "$table" "$order"
        done
    } | "$@"
}

inventory_query="SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('content_reports','moderation_decisions','moderation_report_decisions') ORDER BY BINARY TABLE_NAME"
migration_query="SELECT migration FROM migrations WHERE migration IN ('$decision_migration','$reports_migration') ORDER BY migration"
before="$site/before.txt"
snapshot_legacy database_sql > "$before"
baseline_migrations=$(database_sql -e 'SELECT migration, batch FROM migrations ORDER BY id')
test -n "$(database_sql -e "SELECT migration FROM migrations WHERE migration = '$messaging_migration'")"
test -z "$(database_sql -e "$inventory_query")"
test -z "$(database_sql -e "$migration_query")"
root_inode=$(stat -c '%i' "$site/public_html")
key_hashes=$(sha256sum "$app/shared/storage/"oauth-{private,public}.key)
(cd "$media_root" && sha256sum "${media[@]}") > "$site/media.sha256"

export CANADAMB_PHP_BIN="$(command -v php)"
export CANADAMB_COMPOSER_BIN="$(command -v composer)"
unset CANADAMB_STAGING_DB
upgrade=(bash "$repo/deploy/hostinger-upgrade.sh" "$site" "$target" "$repo/public/build")
expect_rejection "${upgrade[@]}" > "$site/default-guard.log" 2>&1
grep -F 'Database migrations or seeders differ.' "$site/default-guard.log"
test "$(readlink -f "$app/current")" = "$release"
test ! -e "$app/releases/$target"
test ! -e "$app/update.pause"
test ! -e "$release/storage/framework/down"
test -z "$(database_sql -e "$inventory_query")"
test -z "$(database_sql -e "$migration_query")"
snapshot_legacy database_sql > "$site/denied.txt"
cmp "$before" "$site/denied.txt"
echo 'Default production upgrade refused the pending Pass 05 migrations.'

mkdir "$site/pending-migrations"
git -C "$app/source" archive "$target" "database/migrations/$decision_migration.php" "database/migrations/$reports_migration.php" |
    tar -x -C "$site/pending-migrations"
php "$release/artisan" migrate --pretend --force --no-ansi --realpath --path="$site/pending-migrations/database/migrations"
test -z "$(database_sql -e "$migration_query")"
test -z "$(database_sql -e "$inventory_query")"
snapshot_legacy database_sql > "$site/pretend.txt"
cmp "$before" "$site/pretend.txt"

# Approval is scoped to the isolated CI database and this exact target SHA.
"${upgrade[@]}" true
test "$(readlink -f "$app/current")" = "$app/releases/$target"
test "$(stat -c '%i' "$site/public_html")" = "$root_inode"
test "$(sha256sum "$app/shared/storage/"oauth-{private,public}.key)" = "$key_hashes"
test -s "$app/releases/$target/.last-preflight"
test ! -e "$app/update.pause"
test ! -e "$app/releases/$target/storage/framework/down"
test "$(database_sql -e "$migration_query")" = "$decision_migration"$'\n'"$reports_migration"
test "$(database_sql -e "$inventory_query")" = $'content_reports\nmoderation_decisions\nmoderation_report_decisions'
for table in content_reports moderation_decisions moderation_report_decisions; do
    test "$(database_sql -e "SELECT COUNT(*) FROM $table")" = 0
done
snapshot_legacy database_sql > "$site/after.txt"
cmp "$before" "$site/after.txt"
(cd "$media_root" && sha256sum -c "$site/media.sha256")
echo 'Pass 05 preserved all populated moderation categories, report IDs, business and job contacts, read state, and private media.'

upgrade_backup="$app/shared/backups/$target"
(cd "$upgrade_backup" && sha256sum -c checksums.sha256)
mkdir "$site/restored-media"
tar -xzf "$upgrade_backup/storage.tar.gz" -C "$site/restored-media"
(cd "$site/restored-media/storage/app/private" && sha256sum -c "$site/media.sha256")
for key_file in oauth-{private,public}.key; do
    cmp "$site/restored-media/storage/$key_file" "$app/shared/storage/$key_file"
done

restore_database=canadamb_ci_pass05_restore
restore_sql -e "CREATE DATABASE $restore_database"
restore_sql "$restore_database" < "$upgrade_backup/$database.sql"
test -z "$(restore_sql "$restore_database" -e "$inventory_query")"
test "$(restore_sql "$restore_database" -e 'SELECT migration, batch FROM migrations ORDER BY id')" = "$baseline_migrations"
snapshot_legacy restore_sql "$restore_database" > "$site/restored.txt"
cmp "$before" "$site/restored.txt"
echo 'Restored the pre-upgrade Pass 04 SQL and private-media backups in isolation.'

mkdir "$site/moderation-race"
php "$repo/tests/Deployment/pass05-moderation-race.php" "$app/releases/$target" "$site/moderation-race"

#!/usr/bin/env bash

# Sourced from hostinger-release.sh after the exact Pass 03 baseline is running.
[[ $scenario == production-pass-04 && $database == canadamb_ci_pass04 ]]
database_sql < "$repo/tests/Deployment/pass04-messaging.sql"

legacy_queries='SELECT id, listing_id, buyer_id, seller_id, buyer_last_read_id, seller_last_read_id, created_at, updated_at FROM conversations ORDER BY id;
SELECT id, conversation_id, sender_id, body, created_at, updated_at FROM messages ORDER BY id;
SELECT user_id, blocked_id, created_at FROM user_blocks ORDER BY user_id, blocked_id;
SELECT user_id, notify_marketplace_messages, show_listings FROM member_settings ORDER BY user_id;
SELECT id, user_id, event, source_id, resource_id, read_at, created_at FROM notifications ORDER BY id;
SELECT notification_id, location_id FROM notification_locations ORDER BY notification_id, location_id;
SELECT id, business_id, location_id, address FROM business_locations WHERE id = 8401;
SELECT id, business_location_id, author_id, title FROM job_postings WHERE id = 8401;'
schema_queries='SHOW CREATE TABLE conversations; SHOW CREATE TABLE messages; SHOW CREATE TABLE business_locations; SHOW CREATE TABLE job_postings; SHOW CREATE TABLE member_settings;'
inventory_query="SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('conversation_reports','message_attachments','message_reads','message_send_replays') ORDER BY BINARY TABLE_NAME"
migration_query="SELECT migration FROM migrations WHERE migration = '$messaging_migration'"
legacy_rows=$(database_sql -e "$legacy_queries")
legacy_schema=$(database_sql -e "$schema_queries")
test "$(wc -l <<< "$legacy_rows")" = 11
test -z "$(database_sql -e "$inventory_query")"
test -z "$(database_sql -e "$migration_query")"

media=app/private/pass04-probe.txt
printf '%s\n' 'Pass 04 private storage rehearsal' > "$app/shared/storage/$media"
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
test "$(database_sql -e "$legacy_queries")" = "$legacy_rows"
test "$(database_sql -e "$schema_queries")" = "$legacy_schema"
echo 'Default production upgrade refused the pending Pass 04 migration.'

mkdir "$site/pending-migrations"
git -C "$app/source" archive "$target" "database/migrations/$messaging_migration.php" |
    tar -x -C "$site/pending-migrations"
php "$release/artisan" migrate --pretend --force --no-ansi --realpath --path="$site/pending-migrations/database/migrations"
test -z "$(database_sql -e "$migration_query")"
test -z "$(database_sql -e "$inventory_query")"
test "$(database_sql -e "$schema_queries")" = "$legacy_schema"

# Approval is scoped to the isolated CI database and this exact target SHA.
"${upgrade[@]}" true
test "$(readlink -f "$app/current")" = "$app/releases/$target"
test "$(stat -c '%i' "$site/public_html")" = "$root_inode"
test "$(sha256sum "$app/shared/storage/"oauth-{private,public}.key)" = "$key_hashes"
test -s "$app/releases/$target/.last-preflight"
test ! -e "$app/update.pause"
test ! -e "$app/releases/$target/storage/framework/down"
test "$(database_sql -e "$legacy_queries")" = "$legacy_rows"
test "$(database_sql -e "$migration_query")" = "$messaging_migration"
test "$(database_sql -e "$inventory_query")" = $'conversation_reports\nmessage_attachments\nmessage_reads\nmessage_send_replays'
test "$(database_sql -e 'SELECT context_type, business_location_id, job_posting_id, location_id FROM conversations WHERE id = 8401')" = $'marketplace\tNULL\tNULL\tNULL'
test "$(database_sql -e 'SELECT COUNT(*) FROM messages WHERE idempotency_key IS NULL')" = 2
test "$(database_sql -e 'SELECT notify_marketplace_messages, notify_business_messages, notify_job_messages FROM member_settings WHERE user_id = 8401')" = $'0\t1\t1'
test "$(database_sql -e 'SELECT message_contact_user_id FROM business_locations WHERE id = 8401; SELECT message_contact_user_id FROM job_postings WHERE id = 8401;')" = $'NULL\nNULL'
for table in conversation_reports message_attachments message_reads message_send_replays; do
    test "$(database_sql -e "SELECT COUNT(*) FROM $table")" = 0
done

indexes=$(database_sql <<'SQL'
SELECT CONCAT(TABLE_NAME, ':', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX))
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND NON_UNIQUE=0
  AND TABLE_NAME IN ('conversations', 'messages', 'conversation_reports', 'message_attachments', 'message_reads', 'message_send_replays')
GROUP BY TABLE_NAME, INDEX_NAME;
SQL
)
for index in \
    conversations:listing_id,buyer_id \
    conversations:business_location_id,buyer_id,seller_id \
    conversations:job_posting_id,buyer_id,seller_id \
    messages:sender_id,idempotency_key \
    conversation_reports:conversation_id,reporter_id \
    message_attachments:path \
    message_reads:message_id,user_id \
    message_send_replays:sender_id,idempotency_key; do
    grep -qxF "$index" <<< "$indexes"
done

foreign_keys=$(database_sql <<'SQL'
SELECT CONCAT(key_column.TABLE_NAME, ':', key_column.COLUMN_NAME, ':', key_column.REFERENCED_TABLE_NAME, ':', reference_rule.DELETE_RULE)
FROM information_schema.KEY_COLUMN_USAGE key_column
JOIN information_schema.REFERENTIAL_CONSTRAINTS reference_rule
  ON reference_rule.CONSTRAINT_SCHEMA=key_column.CONSTRAINT_SCHEMA AND reference_rule.CONSTRAINT_NAME=key_column.CONSTRAINT_NAME
 AND reference_rule.TABLE_NAME=key_column.TABLE_NAME
WHERE key_column.TABLE_SCHEMA=DATABASE();
SQL
)
for foreign_key in \
    conversations:listing_id:listings:RESTRICT \
    conversations:buyer_id:users:CASCADE \
    conversations:seller_id:users:CASCADE \
    conversations:business_location_id:business_locations:RESTRICT \
    conversations:job_posting_id:job_postings:RESTRICT \
    conversations:location_id:locations:RESTRICT \
    messages:conversation_id:conversations:CASCADE \
    message_attachments:message_id:messages:CASCADE \
    'conversation_reports:message_id:messages:SET NULL' \
    'business_locations:message_contact_user_id:users:SET NULL' \
    'job_postings:message_contact_user_id:users:SET NULL'; do
    grep -qxF "$foreign_key" <<< "$foreign_keys"
done

upgrade_backup="$app/shared/backups/$target"
(cd "$upgrade_backup" && sha256sum -c checksums.sha256)
mkdir "$site/restored-media"
tar -xzf "$upgrade_backup/storage.tar.gz" -C "$site/restored-media"
test "$(cat "$site/restored-media/storage/$media")" = 'Pass 04 private storage rehearsal'
cmp -s "$site/restored-media/storage/$media" "$app/shared/storage/$media"
for key_file in oauth-{private,public}.key; do
    cmp -s "$site/restored-media/storage/$key_file" "$app/shared/storage/$key_file"
done

restore_database=canadamb_ci_pass04_restore
restore_sql -e "CREATE DATABASE $restore_database"
restore_sql "$restore_database" < "$upgrade_backup/$database.sql"
test -z "$(restore_sql "$restore_database" -e "$inventory_query")"
test -z "$(restore_sql "$restore_database" -e "$migration_query")"
test "$(restore_sql "$restore_database" -e "$legacy_queries")" = "$legacy_rows"
test "$(restore_sql "$restore_database" -e "$schema_queries")" = "$legacy_schema"
test "$(restore_sql "$restore_database" -e 'SELECT host FROM domains WHERE host IN ("canadamb.ca", "brandonmb.ca") ORDER BY host')" = $'brandonmb.ca\ncanadamb.ca'
echo 'Pass 04 preserved legacy messages, read markers, notices, preferences and blocks. The pre-upgrade schema and private storage restored in isolation.'

mkdir "$site/message-race"
php "$repo/tests/Deployment/pass04-messaging-race.php" "$app/releases/$target" "$site/message-race"

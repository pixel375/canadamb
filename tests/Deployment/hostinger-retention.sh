#!/usr/bin/env bash
set -Eeuo pipefail
repo=$(git rev-parse --show-toplevel)
home=$(mktemp -d)
trap 'rm -rf -- "$home"' EXIT
export HOME="$home"
app="$HOME/domains/canadamb.ca/canadamb-app"
source="$app/source"
mkdir -p "$app/releases" "$app/shared/backups" "$app/shared/storage"
git init -q -b main "$source"
git -C "$source" config user.name 'Retention fixture'
git -C "$source" config user.email 'fixture@example.invalid'
for i in 1 2 3 4; do
    git -C "$source" commit -q --allow-empty -m "fixture $i"
    id=$(git -C "$source" rev-parse HEAD)
    r="$app/releases/$id"
    b="$app/shared/backups/$id"
    mkdir -p "$r/vendor" "$b"
    printf '<?php exit(0);\n' > "$r/artisan"
    printf '{}\n' > "$r/composer.lock"
    printf 'fixture\n' > "$r/vendor/autoload.php"
    touch "$r/.prepared"
    printf 'fixture\n' > "$r/.last-preflight"
    printf 'database %s\n' "$i" > "$b/u713153343_canadamb.sql"
    printf 'storage %s\n' "$i" > "$b/storage.tar.gz"
    sha256sum "$b/u713153343_canadamb.sql" "$b/storage.tar.gz" > "$b/checksums.sha256"
    case "$i" in
        1) oldest="$id" ;;
        2) older="$id" ;;
        3) rollback="$id" ;;
        4) current="$id" ;;
    esac
done
git clone -q --bare "$source" "$home/origin.git"
git -C "$source" remote add origin "$home/origin.git"
ln -s "$app/releases/$current" "$app/current"
printf 'member data\n' > "$app/shared/storage/sentinel"
run() { bash "$repo/ops/hostinger-release-retention.sh" "$1"; }
legacy="$app/shared/backups/restore-rehearsal-44630938711"
mkdir "$legacy" "$app/shared/backups/restore-rehearsal-44630210485"
printf 'test SQL\n' > "$legacy/u713153343_canadamb.sql"
sha256sum "$legacy/u713153343_canadamb.sql" > "$legacy/checksums.sha256"
config_backup="$app/shared/backups/allowlist-44654858210"
mkdir "$config_backup"
for file in append-ip.php original-config.php original.env; do
    printf 'fixture\n' > "$config_backup/$file"
done
run false
test -d "$legacy"
test -d "$config_backup"
test -d "$app/releases/$oldest"
test -d "$app/shared/backups/$oldest"
mv "$app/shared/backups/$current/checksums.sha256" "$home/receipt"
if run true; then exit 1; fi
test -d "$app/releases/$oldest"
mv "$home/receipt" "$app/shared/backups/$current/checksums.sha256"
printf 'unknown\n' > "$app/shared/backups/$older/unrecognized"
if run true; then exit 1; fi
test -f "$app/shared/backups/$older/unrecognized"
test ! -e "$app/shared/backups/$oldest"
test ! -e "$legacy"
test ! -e "$config_backup"
test ! -e "$app/shared/backups/restore-rehearsal-44630210485"
test ! -e "$app/releases/$oldest"
test -d "$app/shared/backups/$rollback"
test -d "$app/shared/backups/$current"
rm "$app/shared/backups/$older/unrecognized"
run true
test ! -e "$app/shared/backups/$older"
test "$(find "$app/releases" -mindepth 1 -maxdepth 1 -printf . | wc -c)" -eq 2
test "$(find "$app/shared/backups" -mindepth 1 -maxdepth 1 -printf . | wc -c)" -eq 2
test "$(cat "$app/shared/storage/sentinel")" = 'member data'
run true
echo 'Two-release and two-backup retention, refusal and idempotency passed.'

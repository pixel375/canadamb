#!/usr/bin/env bash

# One approved prelaunch UPDATE, never the initial installation.
# Prepare while the old release is still serving, then pause writes, back up,
# fingerprint the schema, activate and verify. No public_html directory rename.
set -Eeuo pipefail
umask 077

usage() {
    echo "Usage: CANADAMB_PRODUCTION_DB=exact_database bash hostinger-upgrade.sh SITE_HOME TARGET_SHA VITE_BUILD [allow-reviewed-db-changes]" >&2
    exit 2
}
[[ $# -ge 3 && $# -le 4 ]] || usage

site=$(cd "$1" && pwd -P)
sha=$2
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || usage
assets=$(cd "$3" && pwd -P)
allow_database_changes=${4:-false}
[[ "$allow_database_changes" == false || "$allow_database_changes" == true ]] || usage

app="$site/canadamb-app"
source="$app/source"
web="$site/public_html"
release="$app/releases/$sha"
database="${CANADAMB_PRODUCTION_DB:-}"
php="${CANADAMB_PHP_BIN:-/usr/bin/php}"
composer="${CANADAMB_COMPOSER_BIN:-/usr/local/bin/composer}"
[[ "$database" =~ ^[a-zA-Z0-9_]{1,64}$ &&
   -x "$php" && -x "$composer" && -d "$web" && ! -L "$web" &&
   -d "$source/.git" && -f "$app/shared/.env" && ! -L "$app/shared/.env" ]] || usage
[[ -s "$assets/source-commit" && $(cat "$assets/source-commit") == "$sha" &&
   -s "$assets/manifest.json" && -d "$assets/assets" ]] || usage

# Serialize complete updates independently of per-command release locks.
exec 8>"$app/update.lock"
flock -w 120 8 || { echo "Update lock remained busy after 120 seconds; no release changed." >&2; exit 1; }
old=$(readlink -e "$app/current" || true)
[[ "$old" == "$app/releases/"* && -s "$old/artisan" && "$old" != "$release" ]] || {
    echo "No valid previous live release, or requested SHA is already active." >&2
    exit 1
}
old_sha=${old##*/}
[[ "$old_sha" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ -s "$old/vendor/autoload.php" ]] || exit 1
[[ -s "$web/index.php" && -s "$web/.htaccess" && ! -e "$web/.git" ]] || exit 1
[[ -s "$app/shared/storage/oauth-private.key" && -s "$app/shared/storage/oauth-public.key" ]] || exit 1

# Use immutable Git object content. Working-copy files previously became empty
# on the actual Hostinger account; do not execute those mutable copies.
git -C "$source" cat-file -e "$sha^{commit}"
git -C "$source" cat-file -e "$old_sha^{commit}"
git -C "$source" merge-base --is-ancestor "$old_sha" "$sha" || {
    echo "Target must descend from currently deployed code." >&2
    exit 1
}
git -C "$source" cat-file -e "$sha:deploy/hostinger-release.sh"
git -C "$source" cat-file -e "$sha:deploy/hostinger-backup.php"
[[ $(git -C "$source" cat-file -s "$sha:deploy/hostinger-release.sh") -gt 10000 ]] || exit 1

changed_database=$(git -C "$source" diff --name-only "$old_sha" "$sha" -- database/migrations/ database/seeders/)
if [[ -n "$changed_database" && "$allow_database_changes" != true ]]; then
    echo "Database migrations or seeders differ. Obtain reviewed database-change approval before deploying." >&2
    exit 1
fi

export CANADAMB_DEPLOYMENT_MODE=production-update
export CANADAMB_PRODUCTION_DB="$database"
export CANADAMB_PHP_BIN="$php"
export CANADAMB_COMPOSER_BIN="$composer"
export CANADAMB_APPROVED_CURRENT_SHA="$old_sha"
export CANADAMB_ACTIVATION_CONFIRMED="$sha"
export CANADAMB_PRODUCTION_CONFIRMED="$database"
unset CANADAMB_STAGING_DB

run_release_step() {
    git -C "$source" show "$sha:deploy/hostinger-release.sh" |
      bash -s -- "$@"  # pipefail required
}

# First prepare release while the OLD application is still live.
echo "Preparing private update $sha; prior release $old_sha."
run_release_step prepare "$site" "$sha" "$assets"
[[ -f "$release/.prepared" && -s "$release/artisan" ]] || {
    echo "Prepared receipt missing; stopping before downtime." >&2
    exit 1
}

state=preparing
paused_marker="$app/update.pause"
cleanup() {
    status=$?
    if (( status != 0 )); then
        echo "CanadaMB update FAILED at stage $state. Inspect private release logs." >&2
        if [[ ( "$state" == pausing || "$state" == paused ) && $(readlink -f "$app/current" || true) == "$old" ]]; then
            # Safe recovery: migration/activation has NOT started.
            "$php" "$old/artisan" up >/dev/null 2>&1 || true
            rm -f "$paused_marker"
            echo "Old release resumed after pre-activation failure." >&2
        elif [[ "$state" == activating || "$state" == verifying ]]; then
            echo "Leave maintenance/cron pause in place; review partial migrations before recovery." >&2
        fi
    fi
}
trap cleanup EXIT

# A planned cron command must respect this marker; the shared release lock
# allows any existing bounded scheduler invocation to drain before snapshot.
[[ ! -e "$paused_marker" && ! -L "$paused_marker" ]] || {
    echo "Maintenance pause marker already exists. Investigate prior update." >&2; exit 1;
}
: > "$paused_marker"
state=pausing
exec 7>"$app/release.lock"
flock -w 90 7 || { echo "Scheduler did not finish within the timeout." >&2; exit 1; }
flock -u 7
exec 7>&-

"$php" "$old/artisan" down --retry=60
state=paused
# Flush already-open requests before a consistent database and media snapshot.
sleep 5

backups="$app/shared/backups/$sha"
[[ ! -L "$app/shared/backups" && ! -e "$backups" ]] || {
    echo "A backup directory already exists for target SHA. Manual reconciliation required." >&2
    exit 1
}
install -d -m 700 "$backups"
helper="$assets/.db-backup.php"
git -C "$source" show "$sha:deploy/hostinger-backup.php" > "$helper"
[[ -s "$helper" ]] &&
[[ $(git -C "$source" hash-object "$helper") == $(git -C "$source" rev-parse "$sha:deploy/hostinger-backup.php") ]] || {
    echo "DB backup helper integrity check failed." >&2; exit 1;
}
"$php" "$helper" "$old" "$backups/$database.sql" "$database"
[[ -s "$backups/$database.sql" ]] || exit 1
tar -czf "$backups/storage.tar.gz" -C "$app/shared" storage
tar -tzf "$backups/storage.tar.gz" >/dev/null
[[ -s "$backups/storage.tar.gz" ]] || exit 1
sha256sum "$backups/$database.sql" "$backups/storage.tar.gz" > "$backups/checksums.sha256"
chmod 600 "$backups/"*
echo "Private database and media backups verified."

run_release_step preflight "$site" "$sha"
[[ -s "$release/.preflight" ]] || {
    echo "Missing migration preflight receipt." >&2
    exit 1
}
echo "Read-only migration preflight passed."

# Failure after this point may have performed schema mutations. NEVER
# auto-rollback or resume a potentially incompatible release after errors.
state=activating
run_release_step activate "$site" "$sha"
[[ -f "$app/current/artisan" &&
   $(readlink -f "$app/current") == "$release" &&
   -s "$web/index.php" && -s "$web/.htaccess" ]] || {
    echo "Release or public entrypoint was not activated." >&2; exit 1;
}
state=verifying
"$php" "$release/artisan" network:deployment-check --hostinger "--production-database=$database" --database
"$php" "$release/artisan" migrate:status --no-ansi >/dev/null
"$php" "$release/artisan" up
[[ ! -f "$release/storage/framework/down" ]] || {
    echo "Release remained in maintenance." >&2; exit 1;
}
rm -f "$paused_marker"
state=complete
echo "CanadaMB prelaunch update OK: $old_sha -> $sha"
echo "Hostinger PHP/DB verified; manual password-unlocked browser and SSO acceptance is still required."

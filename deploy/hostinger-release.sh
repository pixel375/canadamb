#!/usr/bin/env bash

set -Eeuo pipefail
umask 027

usage() {
    echo "Usage: CANADAMB_DEPLOYMENT_MODE=production-initial CANADAMB_PRODUCTION_DB=exact_name bash deploy/hostinger-release.sh prepare|preflight|activate SITE_HOME COMMIT [VITE_BUILD]" >&2
    echo "For staging use CANADAMB_DEPLOYMENT_MODE=staging and CANADAMB_STAGING_DB=name_staging." >&2
    exit 2
}

[[ $# -ge 3 && $# -le 4 ]] || usage
action=$1
site_home=$(cd "$2" && pwd -P)
commit=$3
[[ $commit =~ ^[0-9a-f]{40}$ ]] || usage

private_dir="$site_home/canadamb-app"
web="$site_home/public_html"
release="$private_dir/releases/$commit"
shared="$private_dir/shared"
source_dir="$private_dir/source"
php_bin="${CANADAMB_PHP_BIN:-php}"
composer_bin="${CANADAMB_COMPOSER_BIN:-composer}"
mode="${CANADAMB_DEPLOYMENT_MODE:-staging}"
case "$mode" in
    staging)
        database="${CANADAMB_STAGING_DB:-}"
        [[ $database =~ ^[a-zA-Z0-9_]+_staging$ && -z ${CANADAMB_PRODUCTION_DB:-} ]] || usage
        database_option=staging-database
        ;;
    production-initial|production-update)
        database="${CANADAMB_PRODUCTION_DB:-}"
        [[ $database =~ ^[a-zA-Z0-9_]{1,64}$ && -z ${CANADAMB_STAGING_DB:-} ]] || usage
        database_option=production-database
        ;;
    *) usage ;;
esac
backups="$shared/backups/$commit"

[[ -d "$web" && ! -L "$web" && ! -L "$private_dir" ]] || { echo "Expected separate public_html and private canadamb-app directories." >&2; exit 1; }
for directory in "$private_dir/releases" "$shared" "$shared/storage" "$shared/storage/logs"; do
    [[ ! -L "$directory" ]] || exit 1
done
mkdir -p "$private_dir/releases" "$shared/storage/logs"
exec 9>"$private_dir/release.lock"
flock -n 9 || { echo "Another release operation is active." >&2; exit 1; }
log="$shared/storage/logs/release-$commit.log"
trap 'echo "Release step failed (line $LINENO). Inspect $log and the deployment runbook." >&2' ERR
# Hostinger shared hosting does not reliably expose /dev/fd process substitution.
# Redirect directly to the private release log; never route a release through tee.
echo "CanadaMB $mode $action $commit; private log: $log"
exec >> "$log" 2>&1
echo "$(date -Is) $mode $action $commit"

[[ -f "$shared/.env" && ! -L "$shared/.env" ]] || { echo "Set up the persistent private .env in canadamb-app/shared first."; exit 1; }

artisan() {
    "$php_bin" "$1/artisan" "${@:2}"
}

check_deployment() {
    artisan "$release" network:deployment-check --hostinger "--$database_option=$database" "$@"
}

backup_checksums() {
    [[ ! -L "$shared/backups" && ! -L "$backups" ]] || return 1
    local file
    for file in "$database.sql" storage.tar.gz; do
        [[ -s "$backups/$file" && -f "$backups/$file" && ! -L "$backups/$file" ]] || {
            echo "A private, verified database and storage backup is required for this commit." >&2
            return 1
        }
    done
    (cd "$backups" && sha256sum "$database.sql" storage.tar.gz)
}

preflight_receipt() {
    local checksums state
    checksums=$(backup_checksums) || return 1
    state=$(check_deployment --migration-state) || return 1
    # Keep the diagnostic visible in the private log without /dev/stderr.
    printf '%s\n' "$state" >&2
    printf '%s\n' "$mode" "$database" "$checksums" "$state"
}

validate_files() {
    local directory=$1 mode=$2 file relative
    [[ -d "$directory" && ! -L "$directory" ]] || return 1
    find "$directory" -mindepth 1 -print0 | while IFS= read -r -d '' file; do
        relative="${file#"$directory"/}"
        if [[ -d "$file" && ! -L "$file" ]]; then
            case "$mode/$relative" in
                web/build|web/build/assets|web/images|web/.well-known|web/.well-known/acme-challenge) continue ;;
                *) echo "Found an unexpected directory in the $mode directory."; return 1 ;;
            esac
        fi
        if [[ ! -f "$file" || -L "$file" ]]; then
            echo "Found a link or non-regular file in the $mode directory."
            return 1
        fi
        case "$mode/$relative" in
            web/index.php|web/.htaccess|web/favicon.ico) continue ;;
            web/.well-known/acme-challenge/*)
                [[ "$relative" =~ ^\.well-known/acme-challenge/[A-Za-z0-9_-]+$ ]] && continue ;;
            *)
                [[ "$mode/$relative" =~ ^(web/(build/assets|images)|assets|photos)/[A-Za-z0-9_-][A-Za-z0-9_.-]*\.(css|js|png|jpe?g|webp|avif|gif|woff2?|ico)$ ]] && continue ;;
        esac
        echo "Found an unexpected file in the $mode directory."
        return 1
    done
}

case "$action" in
    prepare)
        [[ $# -eq 4 && -f "$4/manifest.json" && -d "$4/assets" && -f "$4/source-commit" ]] || usage
        if [[ $mode == production-update ]]; then
            old=$(readlink -e "$private_dir/current" || true)
            [[ "$old" == "$private_dir/releases/"* && -s "$old/artisan" && "$old" != "$release" ]] || {
                echo "Production updates require an existing, different active release." >&2
                exit 1
            }
        elif [[ $mode == production-initial ]]; then
            [[ ! -e "$private_dir/current" && ! -L "$private_dir/current" ]] || {
                echo "Initial production activation is forbidden after a release already exists." >&2
                exit 1
            }
        fi
        assets=$(cd "$4" && pwd -P)
        [[ ! -L "$assets/manifest.json" && ! -L "$assets/source-commit" ]] || exit 1
        [[ $(cat "$assets/source-commit") == "$commit" ]] || { echo "The build artifact does not match the requested release."; exit 1; }
        validate_files "$assets/assets" assets
        [[ -d "$source_dir/.git" && ! -L "$release" ]] || { echo "A private Git checkout is required and a release directory cannot be a link."; exit 1; }
        git -C "$source_dir" cat-file -e "$commit^{commit}"
        if [[ -e "$release" ]]; then
            [[ ! -e "$release/.prepared" && $(readlink -f "$private_dir/current" || true) != "$release" ]] || {
                echo "That commit already has a prepared or running release."; exit 1;
            }
            mkdir -p "$private_dir/failed-releases"
            [[ ! -L "$private_dir/failed-releases" ]] || exit 1
            retired=$(mktemp -d "$private_dir/failed-releases/$commit.XXXXXX")
            mv "$release" "$retired/release"
            echo "Retained the earlier failed preparation in $retired."
        fi
        mkdir -p "$release"
        git -C "$source_dir" archive "$commit" | tar -x -C "$release"
        install -d "$shared/storage/app/private" "$shared/storage/app/public" \
            "$shared/storage/framework/cache/data" "$shared/storage/framework/sessions" \
            "$release/storage/framework/views"
        ln -s "$shared/.env" "$release/.env"
        mv "$release/storage/app" "$release/storage/app.skeleton"
        ln -s "$shared/storage/app" "$release/storage/app"
        mv "$release/storage/logs" "$release/storage/logs.skeleton"
        ln -s "$shared/storage/logs" "$release/storage/logs"
        for part in oauth-private.key oauth-public.key; do
            [[ ! -e "$shared/storage/$part" ]] || ln -s "$shared/storage/$part" "$release/storage/$part"
        done
        mkdir -p "$release/public/build"
        cp -a "$assets/assets" "$assets/manifest.json" "$release/public/build/"
        (cd "$release" && "$composer_bin" install --no-dev --prefer-dist --no-interaction --optimize-autoloader && "$composer_bin" check-platform-reqs --no-dev)
        artisan "$release" config:cache
        artisan "$release" route:cache
        artisan "$release" view:cache
        check_deployment
        touch "$release/.prepared"
        echo "Prepared $release. Rehearse migrations and archive verified backups before preflight."
        ;;
    preflight)
        [[ $# -eq 3 && -f "$release/.prepared" ]] || usage
        old=$(readlink -f "$private_dir/current" || true)
        if [[ $mode == production-update ]]; then
            [[ "$old" == "$private_dir/releases/"* && -s "$old/artisan" &&
               "$old" != "$release" && -f "$old/storage/framework/down" ]] || {
                echo "Existing production release must be in maintenance before update preflight." >&2
                exit 1
            }
        fi
        if [[ -f "$old/artisan" && ! -f "$old/storage/framework/down" ]]; then
            echo "Pause writes and cron before backing up and preflighting the existing release."; exit 1
        fi
        artisan "$release" config:cache
        receipt=$(preflight_receipt)
        printf '%s\n' "$receipt" > "$release/.preflight"
        echo "Read-only database preflight complete. Review migrations and the restore rehearsal before authorizing activation."
        ;;
    activate)
        [[ $# -eq 3 && -f "$release/.prepared" && -f "$release/.preflight" ]] || usage
        [[ "${CANADAMB_ACTIVATION_CONFIRMED:-}" == "$commit" ]] || {
            echo "Set CANADAMB_ACTIVATION_CONFIRMED to the reviewed commit."; exit 1;
        }
        if [[ "$mode" == production-initial || "$mode" == production-update ]] && [[ ${CANADAMB_PRODUCTION_CONFIRMED:-} != "$database" ]]; then
            echo "Set CANADAMB_PRODUCTION_CONFIRMED to the exact production database after approving activation."; exit 1
        fi
        validate_files "$web" web
        validate_files "$release/public/build/assets" assets
        validate_files "$release/public/images" photos
        artisan "$release" config:cache
        receipt=$(preflight_receipt)
        [[ $(cat "$release/.preflight") == "$receipt" ]] || { echo "Database state or backups changed. Repeat preflight."; exit 1; }
        old=$(readlink -f "$private_dir/current" || true)
        [[ "$old" != "$release" ]] || { echo "That release is already current."; exit 1; }
        if [[ $mode == production-update ]]; then
            [[ "${CANADAMB_APPROVED_CURRENT_SHA:-}" =~ ^[0-9a-f]{40}$ &&
               "$old" == "$private_dir/releases/$CANADAMB_APPROVED_CURRENT_SHA" &&
               -f "$old/storage/framework/down" ]] || {
                echo "Running release changed or is not in maintenance. Refuse update." >&2
                exit 1
            }
        fi
        if [[ -f "$old/artisan" && ! -f "$old/storage/framework/down" ]]; then
            echo "Writes resumed after preflight. Take a new backup and repeat preflight."; exit 1
        fi
        if [[ -e "$web/.htaccess" ]] && ! cmp -s "$web/.htaccess" "$release/deploy/hostinger.htaccess" &&
            { [[ -z "$old" ]] || ! cmp -s "$web/.htaccess" "$old/deploy/hostinger.htaccess"; }; then
            echo "Review the existing .htaccess before installing the managed rules."; exit 1
        fi
        mv "$release/.preflight" "$release/.last-preflight"
        artisan "$release" down --retry=60
        artisan "$release" migrate --force
        artisan "$release" db:seed --force
        if [[ ! -f "$shared/storage/oauth-private.key" && ! -f "$shared/storage/oauth-public.key" ]]; then
            artisan "$release" passport:keys --no-interaction
            for part in oauth-private.key oauth-public.key; do
                mv "$release/storage/$part" "$shared/storage/$part"
            done
        fi
        for part in oauth-private.key oauth-public.key; do
            [[ -s "$shared/storage/$part" ]]
            [[ -e "$release/storage/$part" ]] || ln -s "$shared/storage/$part" "$release/storage/$part"
        done
        artisan "$release" network:sync-sso
        check_deployment --database
        install -d "$web/build/assets" "$web/images"
        install -m 644 "$release/public/build/assets/"* "$web/build/assets/"
        install -m 644 "$release/public/images/"* "$web/images/"
        [[ ! -f "$release/public/favicon.ico" ]] || install -m 644 "$release/public/favicon.ico" "$web/favicon.ico"
        install -m 644 "$release/deploy/hostinger.htaccess" "$release/.htaccess.pending"
        install -m 644 "$release/deploy/front-controller.php" "$release/index.php.pending"
        mv -f "$release/.htaccess.pending" "$web/.htaccess"
        mv -f "$release/index.php.pending" "$web/index.php"
        ln -s "$release" "$private_dir/.current-$commit"
        mv -Tf "$private_dir/.current-$commit" "$private_dir/current"
        echo "Activated $commit in maintenance mode. Confirm the document root and TLS before bringing the release up for authorized testers."
        ;;
    *) usage ;;
esac

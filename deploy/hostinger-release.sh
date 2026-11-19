#!/usr/bin/env bash

set -Eeuo pipefail
umask 027

usage() {
    echo "Usage: bash deploy/hostinger-release.sh prepare|activate SITE_HOME COMMIT [VITE_BUILD]" >&2
    exit 2
}

[[ $# -ge 3 && $# -le 4 ]] || usage
action=$1
site_home=$(cd "$2" && pwd -P)
commit=$3
[[ $commit =~ ^[0-9a-f]{40}$ ]] || usage

private_dir="$site_home/canadamb"
web="$site_home/public_html"
release="$private_dir/releases/$commit"
shared="$private_dir/shared"
source_dir="$private_dir/source"
php_bin="${CANADAMB_PHP_BIN:-php}"
composer_bin="${CANADAMB_COMPOSER_BIN:-composer}"

[[ -d "$web" && ! -L "$web" && ! -L "$private_dir" ]] || { echo "Expected separate public_html and private canadamb directories." >&2; exit 1; }
mkdir -p "$private_dir/releases" "$shared/storage/logs"
[[ ! -L "$private_dir/releases" && ! -L "$shared" && ! -L "$shared/storage" ]] || exit 1
exec 9>"$private_dir/release.lock"
flock -n 9 || { echo "Another release operation is active." >&2; exit 1; }
log="$shared/storage/logs/release-$commit.log"
trap 'echo "Release step failed (line $LINENO). Inspect $log and the deployment runbook." >&2' ERR
exec > >(tee -a "$log") 2>&1
echo "$(date -Is) $action $commit"

[[ -f "$shared/.env" ]] || { echo "Set up the private shared .env first."; exit 1; }

artisan() {
    "$php_bin" "$1/artisan" "${@:2}"
}

validate_files() {
    local directory=$1 mode=$2 file relative
    [[ -d "$directory" && ! -L "$directory" ]] || return 1
    find "$directory" -mindepth 1 ! -type d -print0 | while IFS= read -r -d '' file; do
        relative="${file#"$directory"/}"
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
        artisan "$release" network:deployment-check --hostinger
        touch "$release/.prepared"
        echo "Prepared $release. Rehearse migrations and record the backups before activation."
        ;;
    activate)
        [[ $# -eq 3 && -f "$release/.prepared" ]] || usage
        [[ "${CANADAMB_ACTIVATION_CONFIRMED:-}" == "$commit" ]] || {
            echo "Set CANADAMB_ACTIVATION_CONFIRMED to the commit after backups, staging migration and origin restriction."; exit 1;
        }
        unexpected=$(find "$web" -mindepth 1 -maxdepth 1 ! -name index.php ! -name .htaccess ! -name favicon.ico ! -name build ! -name images ! -name .well-known -print -quit)
        [[ -z "$unexpected" ]] || { echo "Unexpected content in public_html. Back it up privately and prepare a public-only root before activation."; exit 1; }
        validate_files "$web" web
        validate_files "$release/public/build/assets" assets
        validate_files "$release/public/images" photos
        artisan "$release" config:cache
        artisan "$release" network:deployment-check --hostinger
        old=$(readlink -f "$private_dir/current" || true)
        if [[ -f "$old/artisan" ]]; then
            artisan "$old" down --retry=60
        fi
        artisan "$release" down --retry=60
        artisan "$release" migrate --pretend --force
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
        artisan "$release" network:deployment-check --hostinger --database
        install -d "$web/build/assets" "$web/images"
        cp -a "$release/public/build/assets/." "$web/build/assets/"
        cp -a "$release/public/images/." "$web/images/"
        [[ ! -f "$release/public/favicon.ico" ]] || install -m 644 "$release/public/favicon.ico" "$web/favicon.ico"
        install -m 644 "$release/deploy/hostinger.htaccess" "$release/.htaccess.pending"
        install -m 644 "$release/deploy/front-controller.php" "$release/index.php.pending"
        mv -f "$release/.htaccess.pending" "$web/.htaccess"
        mv -f "$release/index.php.pending" "$web/index.php"
        ln -s "$release" "$private_dir/.current-$commit"
        mv -Tf "$private_dir/.current-$commit" "$private_dir/current"
        echo "Activated $commit in maintenance mode. Keep the origin restricted and manually run artisan up for the smoke checks."
        ;;
    *) usage ;;
esac

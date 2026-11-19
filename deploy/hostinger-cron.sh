#!/usr/bin/env bash

set -euo pipefail
umask 027

[[ $# -eq 2 ]] || { echo 'Usage: hostinger-cron.sh SITE_HOME ABSOLUTE_PHP' >&2; exit 2; }
app="$1/canadamb-app"
php_bin="$2"
[[ -d "$app" && -x "$php_bin" && "$php_bin" == /* ]] || exit 2

exec 9>"$app/release.lock"
if ! flock -n 9; then
    # An active deployment or scheduler run owns the slot.
    exit 0
fi
release=$(readlink -e "$app/current")
[[ "$release" == "$app/releases/"* && -f "$release/artisan" ]] || exit 2
cd "$release"
timeout --kill-after=5s 55s "$php_bin" artisan schedule:run --no-interaction

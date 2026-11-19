#!/usr/bin/env bash

set -Eeuo pipefail

main="${CANADAMB_SMOKE_MAIN:-https://canadamb.ca}"
local_portal="${CANADAMB_SMOKE_LOCAL:-https://brandonmb.ca}"
temp=$(mktemp -d)
trap 'rm -f "$temp/main" "$temp/local" "$temp/headers" "$temp/response"; rmdir "$temp"' EXIT

fetch() {
    curl --fail --silent --show-error --max-time 15 "$@"
}

fetch "$main/" > "$temp/main"
fetch "$local_portal/" > "$temp/local"
grep -Fq 'Manitoba, Canada' "$temp/main"
grep -Fq 'Brandon, Manitoba' "$temp/local"
grep -Fq '#E85D75' "$temp/main"
grep -Fq '#22A06B' "$temp/local"
grep -Fq "href=\"$main/\"" "$temp/main"
grep -Fq "href=\"$local_portal/\"" "$temp/local"
fetch "$main/communities" >/dev/null

for portal in "$main" "$local_portal"; do
    fetch "$portal/up" >/dev/null
    fetch "$portal/sitemap.xml" > "$temp/response"
    grep -Fq "$portal/" "$temp/response"
    for resource in '/.env' '/.git/config' '/vendor/autoload.php' '/storage/logs/laravel.log' '/app/Models/User.php' '/composer.json'; do
        code=$(curl --silent --show-error --max-time 15 --output "$temp/response" --write-out '%{http_code}' "$portal$resource")
        [[ "$code" == 403 || "$code" == 404 ]] || { echo "$portal$resource returned $code"; exit 1; }
    done
    fetch -D "$temp/headers" "$portal/" > "$temp/response"
    for extension in css js; do
        asset=$(grep -oE "/build/assets/[A-Za-z0-9_.-]+\.$extension" "$temp/response" | sed -n '1p')
        [[ -n "$asset" ]] || { echo "A built $extension asset is missing from $portal"; exit 1; }
        fetch "$portal$asset" >/dev/null
    done
    grep -Eiq '^set-cookie:.*-session=.*[Hh]ttponly' "$temp/headers"
    if grep -Ei '^set-cookie:.*-session=' "$temp/headers" | grep -Eiq ';[[:space:]]*domain='; then
        echo "Unexpected cookie domain on $portal"
        exit 1
    fi
    if [[ $portal == https:* ]]; then
        grep -Ei '^set-cookie:.*-session=' "$temp/headers" | grep -Eiq ';[[:space:]]*secure'
    fi
done
fetch "$main/images/manitoba.webp" >/dev/null
fetch "$local_portal/images/brandon.webp" >/dev/null

fetch -D "$temp/headers" "$local_portal/auth/start" >/dev/null
grep -Fiq "location: $main/oauth/authorize?" "$temp/headers"
echo "Both portals passed origin, identity, canonical, private-path and cookie checks."

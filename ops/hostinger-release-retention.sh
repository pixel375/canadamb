#!/usr/bin/env bash
# Trusted main only. Remove reconstructible application copies, never shared data.
set -Eeuo pipefail
umask 077
reclaim=$1
[[ "$reclaim" == true || "$reclaim" == false ]] || exit 2
app="$HOME/domains/canadamb.ca/canadamb-app"
releases="$app/releases"
source="$app/source"
backups="$app/shared/backups"
[[ -d "$app" && ! -L "$app" && -d "$releases" && ! -L "$releases" &&
   -d "$source/.git" && ! -L "$source" && -d "$backups" && ! -L "$backups" ]] || exit 1
[[ "$(readlink -e "$releases")" == "$releases" ]] || exit 1
for lock in update.lock release.lock; do
    [[ ! -L "$app/$lock" ]] || exit 1
done
exec 8>"$app/update.lock"
flock -w 90 8 || { echo 'Update lock busy; no deletion.'; exit 1; }
exec 9>"$app/release.lock"
flock -w 90 9 || { echo 'Release lock busy; no deletion.'; exit 1; }
[[ ! -e "$app/update.pause" && ! -L "$app/update.pause" ]] || exit 1
active=$(readlink -e "$app/current")
current=$(basename "$active")
[[ "$current" =~ ^[a-f0-9]{40}$ && "$active" == "$releases/$current" &&
   -d "$active" && ! -L "$active" && -s "$active/vendor/autoload.php" &&
   ! -e "$active/storage/framework/down" && ! -e "$active/storage/framework/maintenance.php" ]] || exit 1
git -C "$source" cat-file -e "$current^{commit}"
git -C "$source" merge-base --is-ancestor "$current" refs/remotes/origin/implementation/network-foundation
entries() { find -P "$1" -xdev -printf '.' | wc -c; }
receipt_ok() {
    local id=$1 dir="$backups/$1" db="$backups/$1/u713153343_canadamb.sql" media="$backups/$1/storage.tar.gz"
    [[ -d "$dir" && ! -L "$dir" && -s "$dir/checksums.sha256" && ! -L "$dir/checksums.sha256" &&
       -s "$db" && ! -L "$db" && -s "$media" && ! -L "$media" ]] || return 1
    # Verify the exact two expected files rather than trusting paths supplied in a receipt.
    local expected actual
    expected=$(sha256sum "$db" "$media") || return 1
    actual=$(cat "$dir/checksums.sha256") || return 1
    [[ "$actual" == "$expected" ]]
}
safe_root() {
    local root=$1
    [[ -d "$root" && ! -L "$root" && "$(readlink -e "$root")" == "$root" &&
       "$root" != "$active" && ! -L "$root/artisan" && -s "$root/artisan" &&
       ! -L "$root/composer.lock" && -s "$root/composer.lock" ]] || return 1
    # Shared storage and credentials must be symlinks in a completed release.
    # find/rm never follow them; reject real application data directories.
    [[ ! -d "$root/storage/app" || -L "$root/storage/app" ]] || return 1
    [[ ! -d "$root/storage/logs" || -L "$root/storage/logs" ]] || return 1
    [[ ! -e "$root/.env" || -L "$root/.env" ]] || return 1
    # Reject nested mount points to avoid partial recursive removal.
    local rootdev other
    rootdev=$(stat -c %d "$root") || return 1
    other=$(find -P "$root" -xdev -type d -printf '%D\n' | sort -u) || return 1
    [[ "$other" == "$rootdev" ]] || return 1
}
still_safe() {
    [[ "$(readlink -e "$app/current")" == "$active" &&
       ! -e "$app/update.pause" && ! -L "$app/update.pause" &&
       -s "$active/vendor/autoload.php" ]]
}
echo "Active release: $current"
before=$(entries "$releases")
echo "Release entries before: $before"
declare -A keep=()
keep["$current"]=1
protected=1
history=$(git -C "$source" rev-list --first-parent "$current")
for id in $history; do
    [[ "$id" =~ ^[a-f0-9]{40}$ ]] || exit 1
    [[ "$id" != "$current" ]] || continue
    root="$releases/$id"
    if [[ -d "$root" && ! -L "$root" && -s "$root/vendor/autoload.php" &&
          -f "$root/.prepared" && -s "$root/.last-preflight" ]] &&
          safe_root "$root" && receipt_ok "$id"; then
        keep["$id"]=1
        protected=$((protected + 1))
        echo "Protected rollback-ready release: $id"
        [[ "$protected" -lt 3 ]] || break
    fi
done
[[ "$protected" -eq 3 ]] || { echo 'Fewer than two verified rollback candidates; refusing cleanup.'; exit 1; }
receipt_ok "$current"
# Inventory all entries first, then remove only explicitly classified paths.
candidates=()
counts=()
total=0
unknown=0
for root in "$releases/"*; do
    [[ -e "$root" || -L "$root" ]] || continue
    id=$(basename "$root")
    if [[ ! "$id" =~ ^[a-f0-9]{40}$ ]]; then
        echo 'Unrecognized release entry preserved.'
        unknown=$((unknown + 1))
        continue
    fi
    n=$(entries "$root")
    if [[ -n "${keep[$id]:-}" ]]; then
        echo "Keep $id: $n entries"
        continue
    fi
    if ! safe_root "$root" || ! git -C "$source" cat-file -e "$id^{commit}" ||
       ! git -C "$source" merge-base --is-ancestor "$id" "$current"; then
        echo "Preserve unverified release $id: $n entries"
        unknown=$((unknown + 1))
        continue
    fi
    if receipt_ok "$id"; then
        kind=historical
    elif [[ ! -e "$backups/$id" && ! -L "$backups/$id" &&
            ! -e "$root/.prepared" && ! -e "$root/.preflight" && ! -e "$root/.last-preflight" ]]; then
        kind=failed-preparation
    else
        echo "Preserve release without valid backup or failed-preparation evidence $id: $n entries"
        unknown=$((unknown + 1))
        continue
    fi
    candidates+=("$root")
    counts+=("$n")
    total=$((total + n))
    echo "Eligible $kind $id: $n entries"
done
# Previously quarantined failed preparations contain exactly one release folder.
failed="$app/failed-releases"
if [[ -e "$failed" || -L "$failed" ]]; then
    [[ -d "$failed" && ! -L "$failed" && "$(readlink -e "$failed")" == "$failed" ]] || exit 1
    for dir in "$failed/"*; do
        [[ -e "$dir" || -L "$dir" ]] || continue
        name=$(basename "$dir")
        root="$dir/release"
        id=$(printf '%s' "$name" | cut -d. -f1)
        if [[ "$name" =~ ^[a-f0-9]{40}\.[A-Za-z0-9]+$ && -d "$dir" && ! -L "$dir" &&
              "$(readlink -e "$dir")" == "$dir" && "$(find -P "$dir" -mindepth 1 -maxdepth 1 -printf '%f\n')" == release &&
              "$id" != "$current" && ! -e "$root/.prepared" && ! -e "$root/.preflight" && ! -e "$root/.last-preflight" ]] &&
              safe_root "$root" && git -C "$source" cat-file -e "$id^{commit}" &&
              git -C "$source" merge-base --is-ancestor "$id" "$current"; then
            n=$(entries "$dir")
            candidates+=("$dir")
            counts+=("$n")
            total=$((total+n))
            echo "Eligible quarantined preparation $id: $n entries"
        else
            echo 'Unverified failed preparation preserved.'
            unknown=$((unknown+1))
        fi
    done
fi
echo "Protected complete releases: $protected; eligible directories: ${#candidates[@]}; eligible entries: $total; unverified entries preserved: $unknown"
if [[ "$reclaim" == true ]]; then
    /usr/bin/php "$active/artisan" network:deployment-check --hostinger --production-database=u713153343_canadamb --database --migration-state
    removed=0
    for dir in "${candidates[@]}"; do
        still_safe
        [[ -d "$dir" && ! -L "$dir" && "$(readlink -e "$dir")" == "$dir" ]] || exit 1
        case "$dir" in
            "$releases/"*) id=$(basename "$dir"); [[ "$id" =~ ^[a-f0-9]{40}$ && -z "${keep[$id]:-}" ]] || exit 1; safe_root "$dir" ;;
            "$failed/"*) safe_root "$dir/release" ;;
            *) exit 1 ;;
        esac
        rm -rf --one-file-system -- "$dir"
        [[ ! -e "$dir" && ! -L "$dir" ]] || exit 1
        removed=$((removed+1))
    done
    echo "Reclaimed $removed inactive release directories."
fi
still_safe
for id in "${!keep[@]}"; do
    [[ -s "$releases/$id/vendor/autoload.php" ]] || exit 1
done
after=$(entries "$releases")
echo "Release entries after: $after"
echo "Release entries reclaimed: $((before-after))"
echo "Unverified entries preserved: $unknown"
echo "Release directories remaining: $(find -P "$releases" -mindepth 1 -maxdepth 1 -type d -printf '.' | wc -c)"
/usr/bin/php "$active/artisan" network:deployment-check --hostinger --production-database=u713153343_canadamb --database --migration-state
echo 'Active release and rollback candidates preserved; shared storage and backups untouched.'

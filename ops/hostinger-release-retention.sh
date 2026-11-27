#!/usr/bin/env bash
# Trusted main only. Retain the active and one verified rollback release and their backup sets.
# Never touch live shared storage, unknown archives or unverified snapshots.
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
[[ "$(readlink -e "$releases")" == "$releases" && "$(readlink -e "$backups")" == "$backups" ]] || exit 1
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
git -C "$source" fetch --no-tags origin '+refs/heads/main:refs/remotes/origin/main'
git -C "$source" merge-base --is-ancestor "$current" refs/remotes/origin/main
entries() { find -P "$1" -xdev -printf '.' | wc -c; }
receipt_ok() {
    local id=$1 dir="$backups/$1" db="$backups/$1/u713153343_canadamb.sql" media="$backups/$1/storage.tar.gz"
    [[ -d "$dir" && ! -L "$dir" && -s "$db" && ! -L "$db" && -s "$media" && ! -L "$media" ]] || return 1
    # Verify the exact two expected files rather than trusting paths supplied in a receipt.
    local expected actual receipt="$dir/checksums.sha256"
    if [[ "$id" == b5f16a6c08eefcd804deb1970f52490220af67f5 && ! -s "$receipt" ]]; then
        receipt="$dir/verified-initial-checksums.sha256"
    fi
    [[ -s "$receipt" && ! -L "$receipt" ]] || return 1
    expected=$(sha256sum "$db" "$media") || return 1
    actual=$(cat "$receipt") || return 1
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
        [[ "$protected" -lt 2 ]] || break
    fi
done
[[ "$protected" -eq 2 ]] || { echo 'No verified rollback candidate; refusing cleanup.'; exit 1; }
receipt_ok "$current"
# The initial deployment predates the generated checksum file. Its original
# .last-preflight records both relative-path checksums. Verify those exact
# archived bytes, preserve the original receipt alongside the backup, and
# generate a separately named checksum record without replacing any evidence.
initial=b5f16a6c08eefcd804deb1970f52490220af67f5
initial_root="$releases/$initial"
initial_backup="$backups/$initial"
if [[ "$reclaim" == true && -d "$initial_root" && -z "${keep[$initial]:-}" ]] &&
   ! receipt_ok "$initial"; then
    original="$initial_root/.last-preflight"
    db="$initial_backup/u713153343_canadamb.sql"
    media="$initial_backup/storage.tar.gz"
    if safe_root "$initial_root" &&
       git -C "$source" merge-base --is-ancestor "$initial" "$current" &&
       [[ -s "$original" && ! -L "$original" && -f "$initial_root/.prepared" &&
          ! -e "$initial_root/.preflight" &&
          -d "$initial_backup" && ! -L "$initial_backup" &&
          -s "$db" && ! -L "$db" && -s "$media" && ! -L "$media" &&
          ! -s "$initial_backup/checksums.sha256" &&
          ! -L "$initial_backup/checksums.sha256" ]]; then
        recorded=$(sed -n '3,4p' "$original")
        expected=$(cd "$initial_backup" && sha256sum u713153343_canadamb.sql storage.tar.gz)
        if [[ "$(sed -n '1p' "$original")" == production-initial &&
              "$(sed -n '2p' "$original")" == u713153343_canadamb &&
              "$recorded" == "$expected" ]]; then
            saved="$initial_backup/original-initial-preflight.receipt"
            receipt="$initial_backup/verified-initial-checksums.sha256"
            if [[ ! -e "$saved" && ! -L "$saved" ]]; then
                (set -o noclobber; cat "$original" > "$saved")
            fi
            [[ -f "$saved" && ! -L "$saved" ]] && cmp -s "$saved" "$original" || exit 1
            if [[ ! -e "$receipt" && ! -L "$receipt" ]]; then
                (set -o noclobber; sha256sum "$db" "$media" > "$receipt")
            fi
            receipt_ok "$initial"
            echo 'Original installation backup matches recorded preflight hashes; preserved legacy receipt.'
        else
            echo 'Original installation backup does not match its recorded preflight; keeping release.'
        fi
    else
        echo 'Original installation receipt could not be verified; keeping release.'
    fi
fi
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
release_remaining=$(find -P "$releases" -mindepth 1 -maxdepth 1 -printf '.' | wc -c)
echo "Release entries (top-level) remaining: $release_remaining"
/usr/bin/php "$active/artisan" network:deployment-check --hostinger --production-database=u713153343_canadamb --database --migration-state
# Backup retention follows release reclamation, under the same locks. Only
# checksum-verified, ancestral, no-longer-retained snapshots are eligible.
# Refuse unknown entries rather than recursively deleting arbitrary data.
shopt -s dotglob nullglob
backup_candidates=()
legacy_candidates=()
backup_unknown=0
# Exact historical run IDs and expected contents verified on 2026-11-27.
legacy_ok() {
    local dir=$1 name file names
    name=$(basename "$dir")
    [[ -d "$dir" && ! -L "$dir" && "$(readlink -e "$dir")" == "$dir" ]] || return 1
    names=$(find -P "$dir" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort) || return 1
    for file in "$dir/"*; do
        [[ -f "$file" && ! -L "$file" && "$(stat -c %h "$file")" == 1 ]] || return 1
    done
    case "$name" in
        allowlist-44654858210|allowlist-45507325094)
            [[ "$names" == "$(printf '%s\n' append-ip.php original-config.php original.env)" &&
               -s "$dir/append-ip.php" && -s "$dir/original-config.php" && -s "$dir/original.env" ]] ;;
        restore-rehearsal-44630210485)
            [[ -z "$names" ]] ;;
        restore-rehearsal-44630938711|restore-rehearsal-45866760393)
            [[ "$names" == "$(printf '%s\n' checksums.sha256 u713153343_canadamb.sql)" &&
               -s "$dir/u713153343_canadamb.sql" && -s "$dir/checksums.sha256" &&
               "$(cat "$dir/checksums.sha256")" == "$(sha256sum "$dir/u713153343_canadamb.sql")" ]] ;;
        *) return 1 ;;
    esac
}
for dir in "$backups/"*; do
    id=$(basename "$dir")
    if legacy_ok "$dir"; then
        legacy_candidates+=("$dir")
        echo "Eligible retired maintenance backup: $id"
        continue
    fi
    if [[ ! "$id" =~ ^[a-f0-9]{40}$ || ! -d "$dir" || -L "$dir" ||
          "$(readlink -e "$dir")" != "$dir" ]]; then
        echo 'Unrecognized backup entry preserved.'
        backup_unknown=$((backup_unknown+1))
        continue
    fi
    [[ -z "${keep[$id]:-}" ]] || continue
    if [[ -e "$releases/$id" || -L "$releases/$id" ]] ||
       ! git -C "$source" merge-base --is-ancestor "$id" "$current" ||
       ! receipt_ok "$id"; then
        echo "Unverified or still-referenced backup preserved: $id"
        backup_unknown=$((backup_unknown+1))
        continue
    fi
    valid=true
    for file in "$dir/"*; do
        [[ -f "$file" && ! -L "$file" ]] || { valid=false; break; }
        case "$(basename "$file")" in
            u713153343_canadamb.sql|storage.tar.gz|checksums.sha256) ;;
            original-initial-preflight.receipt|verified-initial-checksums.sha256)
                [[ "$id" == "$initial" ]] || { valid=false; break; } ;;
            *) valid=false; break ;;
        esac
    done
    if [[ "$valid" != true ]]; then
        echo "Unexpected backup contents preserved: $id"
        backup_unknown=$((backup_unknown+1))
        continue
    fi
    backup_candidates+=("$dir")
    echo "Eligible verified historical backup: $id"
done
echo "Historical backup sets eligible: ${#backup_candidates[@]}; legacy maintenance entries eligible: ${#legacy_candidates[@]}; unverified entries preserved: $backup_unknown"
if [[ "$reclaim" == true ]]; then
    # Verify both surviving backup sets again before deleting any archive.
    for id in "${!keep[@]}"; do
        receipt_ok "$id"
    done
    for dir in "${backup_candidates[@]}"; do
        still_safe
        id=$(basename "$dir")
        [[ "$id" =~ ^[a-f0-9]{40}$ && -z "${keep[$id]:-}" &&
           -d "$dir" && ! -L "$dir" && "$(readlink -e "$dir")" == "$dir" &&
           ! -e "$releases/$id" && ! -L "$releases/$id" ]] || exit 1
        receipt_ok "$id"
        # No recursive removal; only known files inside this verified set.
        rm -- "$dir/u713153343_canadamb.sql" "$dir/storage.tar.gz"
        rm -f -- "$dir/checksums.sha256"
        if [[ "$id" == "$initial" ]]; then
            rm -f -- "$dir/original-initial-preflight.receipt" "$dir/verified-initial-checksums.sha256"
        fi
        rmdir -- "$dir"
        echo "Removed historical backup set: $id"
    done
    for dir in "${legacy_candidates[@]}"; do
        still_safe
        legacy_ok "$dir" || exit 1
        case "$(basename "$dir")" in
            allowlist-44654858210|allowlist-45507325094)
                rm -- "$dir/append-ip.php" "$dir/original-config.php" "$dir/original.env" ;;
            restore-rehearsal-44630210485) ;;
            restore-rehearsal-44630938711|restore-rehearsal-45866760393)
                rm -- "$dir/checksums.sha256" "$dir/u713153343_canadamb.sql" ;;
            *) exit 1 ;;
        esac
        rmdir -- "$dir"
        echo "Removed retired maintenance backup: $(basename "$dir")"
    done
fi
still_safe
for id in "${!keep[@]}"; do
    receipt_ok "$id"
done
remaining=$(find -P "$backups" -mindepth 1 -maxdepth 1 -printf '.' | wc -c)
echo "Backup entries remaining: $remaining"
if [[ "$reclaim" == true && ( "$remaining" -ne 2 || "$release_remaining" -ne 2 || "$unknown" -ne 0 || "$backup_unknown" -ne 0 ) ]]; then
    echo 'Retention requires reconciliation: unexpected entries remain; no unverified data was deleted.' >&2
    exit 1
fi
echo 'Active release and one verified rollback preserved with their backup sets; live shared storage untouched.'

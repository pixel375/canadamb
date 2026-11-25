# CanadaMB

canadamb.ca

## Production release retention

After independent database, backup and prelaunch-access audits pass, trusted main runs
`ops/hostinger-release-retention.sh` under both update and release locks. It retains
the active release plus two verified complete first-parent predecessors. Older
release directories are reconstructible from Git history and their exact build;
all private backup receipts, database/storage archives, shared storage and public
document roots remain untouched. Failed preparations are reclaimed only when
proven inactive, unprepared and attributable to a known ancestor commit.
Unrecognized or unverified directories are retained and reported for investigation.
Predeployment checks count release entries and report filesystem inode availability
(which is not the account quota); account-wide quota is reported if available.
Manual retention dispatch is inventory-only unless reclaim is explicitly enabled.
Do not roll back code across incompatible database migrations; restore review is
still required. Older releases require a fresh build and dependency install.

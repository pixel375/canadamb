<?php

namespace App\Support\BusinessImports;

use App\Models\Location;
use App\Support\BusinessDirectory;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class BusinessImportResolver
{
    public const ACTIONS = ['create_business', 'create_branch', 'update_facts', 'unchanged', 'duplicate_exact', 'skipped'];

    /**
     * Work through groups in key order. Buffer at most a chunk of rows plus one
     * group's candidate IDs. Lock current records when proving an apply plan.
     */
    public function decisions(object $plan, bool $lock = false): iterable
    {
        $rows = DB::table('business_import_rows')->where('run_id', $plan->id);
        foreach ((clone $rows)->whereNull('group_key')->lazyById(200) as $row) {
            yield [$row, $row->review ? 'skipped' : 'invalid', json_decode($row->details, true, flags: JSON_THROW_ON_ERROR)];
        }

        $places = [];
        $websites = [];
        $repeatedKeys = [];
        $last = '';
        while (($keys = (clone $rows)->where('group_key', '>', $last)->select('group_key')->distinct()
            ->orderBy('group_key')->limit(200)->pluck('group_key'))->isNotEmpty()) {
            foreach ($keys as $key) {
                $group = (clone $rows)->where('group_key', $key);
                foreach ($this->group($plan, $group, $rows, $key, $places, $repeatedKeys, $lock) as [$row, $decision, $details]) {
                    $proposal = $details['website_proposal'] ?? null;
                    if ($proposal !== null && in_array($decision, self::ACTIONS, true)) {
                        $business = $details['business'];
                        $websites[$business] ??= $proposal;
                        if ($websites[$business] !== $proposal) {
                            $decision = 'field_conflict';
                            $details['reason'] = 'Rows linked to the same business propose different websites. Reconcile them before applying.';
                        }
                    }
                    yield [$row, $decision, $details];
                }
            }
            $last = $keys->last();
        }
    }

    private function group(object $plan, Builder $group, Builder $rows, string $key, array &$places, array &$repeatedKeys, bool $lock): iterable
    {
        $candidates = (clone $group)->where(fn ($query) => $query->whereNull('review')->orWhere('review->action', '!=', 'skip'));
        $first = (clone $candidates)->oldest('id')->first();
        if ($first === null) {
            foreach ($group->lazyById(200) as $row) {
                yield [$row, 'skipped', ['reason' => 'Operator excluded this row.']];
            }

            return;
        }
        $header = json_decode($first->payload, true, flags: JSON_THROW_ON_ERROR);
        $linked = str_starts_with($key, 'g:') ? substr($key, 2) : null;
        $mapping = $linked === null ? null : DB::table('business_import_identities')->where([
            'source_id' => $plan->source_id, 'identity_key' => $linked,
        ])->when($lock, fn ($query) => $query->lockForUpdate())->value('business_id');

        // A historical branch key may anchor an explicit group to one existing business.
        $anchors = (clone $candidates)->select('branch.business_id')->join('business_locations as branch',
            'branch.import_key', '=', 'business_import_rows.branch_key')
            ->where('branch.source_id', $plan->source_id)->distinct()->limit(2)->pluck('branch.business_id')->all();
        $reviews = (clone $candidates)->whereNotNull('review')->pluck('review')->map(fn ($value) => json_decode($value, true, flags: JSON_THROW_ON_ERROR));
        $links = $reviews->where('action', 'link')->pluck('business')->all();
        $known = array_values(array_unique(array_filter([$mapping, ...$anchors, ...$links])));
        $businessId = $known[0] ?? null;
        $business = $businessId === null ? null : DB::table('businesses')->where('id', $businessId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $protected = $business !== null && (
            $this->protectedRecord('business_memberships', $businessId, $lock)
            || $this->protectedRecord('business_claims', $businessId, $lock, 'pending')
        );
        $resolvedDistinct = $reviews->contains('action', 'distinct');
        $collision = [];
        if ($business === null) {
            $collision = DB::table('businesses')->where('import_name_key', $first->name_key)
                ->orderBy('id')->limit(5)->when($lock, fn ($query) => $query->lockForUpdate())->pluck('id')->all();
        }

        $groupError = match (true) {
            count($known) > 1 => 'Source keys point to different businesses. Keep both existing profiles and skip the conflicting rows.',
            $businessId !== null && ($business === null || $business->status !== 'active') => 'The associated business is unavailable or unpublished.',
            $business !== null && ($business->import_name_key !== $first->name_key || $business->category !== $header['category']) => 'The incoming name or category differs from the associated business. Confirm the identity outside this import.',
            $business === null && ! $resolvedDistinct && $collision !== [] => 'Choose a matching business or confirm a separate organization.',
            default => null,
        };
        $websiteChange = $business !== null && $business->website_source_id === $plan->source_id
            && $business->website !== $header['website'];

        foreach ((clone $group)->lazyById(200) as $row) {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            $review = $row->review === null ? null : json_decode($row->review, true, flags: JSON_THROW_ON_ERROR);
            if (($review['action'] ?? null) === 'skip') {
                yield [$row, 'skipped', ['reason' => 'Operator excluded this row.']];

                continue;
            }
            if (! array_key_exists($payload['location'], $places)) {
                $places[$payload['location']] = Location::selectable()->where('slug', $payload['location'])
                    ->when($lock, fn ($query) => $query->sharedLock())->value('id');
            }
            $location = $places[$payload['location']];
            $sameKey = $this->branchRepetitions($rows, $row, $repeatedKeys);
            if (! $sameKey['conflict'] && $sameKey['first'] !== $row->id) {
                yield [$row, 'duplicate_exact', ['reason' => 'Same normalized input as an earlier row.', 'repeat_of' => $sameKey['first']]];

                continue;
            }
            $sameAddress = (clone $rows)->where('physical_key', $row->physical_key)->where('branch_key', '!=', $row->branch_key)
                ->where(fn ($query) => $query->whereNull('review')->orWhere('review->action', '!=', 'skip'))->exists();
            $sameLegacyName = ! $resolvedDistinct && $business === null && (clone $rows)->where('name_key', $row->name_key)
                ->where('group_key', '!=', $key)->where(fn ($query) => $query->whereNull('review')->orWhere('review->action', '!=', 'skip'))
                ->when($linked !== null, fn ($query) => $query->where('group_key', 'like', 'b:%'))->exists();
            $branches = DB::table('business_locations')->where('source_id', $plan->source_id)->where('import_key', $row->branch_key)
                ->orderBy('id')->limit(2)->when($lock, fn ($query) => $query->lockForUpdate())->get();
            $branch = $branches->first();
            $addressKey = BusinessDirectory::addressKey($payload['name'], $payload['address']);
            $physical = $location === null ? null : DB::table('business_locations')->where([
                'location_id' => $location, 'address_key' => $addressKey,
            ])->when($lock, fn ($query) => $query->lockForUpdate())->first(['id', 'business_id']);
            $addressChange = $branch !== null && $branch->address !== $payload['address'];
            $leader = $first->id === $row->id;
            $inconsistent = $row->name_key !== $first->name_key || $payload['category'] !== $header['category']
                || $payload['website'] !== $header['website'];

            [$decision, $message] = match (true) {
                $location === null => ['invalid', 'Use a selectable Manitoba location slug.'],
                $sameKey['conflict'] || $branches->count() > 1 => ['duplicate_key', 'The source branch key has conflicting records.'],
                $sameAddress => ['physical_conflict', 'Another row has this name and physical address.'],
                $groupError !== null => ['identity_conflict', $groupError],
                $inconsistent => ['identity_conflict', 'Every row in a group must agree on organization name, category and website.'],
                $sameLegacyName => ['identity_conflict', 'Repeated names need an explicit reviewed group key or separate-organization confirmation.'],
                $branch !== null && ($branch->business_id !== $businessId || $branch->location_id !== $location) => ['identity_conflict', 'An existing source branch cannot be reparented or moved between places.'],
                $physical !== null && $physical->id !== $branch?->id => ['physical_conflict', 'The physical branch already exists. Import cannot take over its source or existing links.'],
                $protected && ($branch === null || $addressChange || ($websiteChange && $leader)) => ['claimed_conflict', 'An owner, staff member or pending claim protects the existing business from changes.'],
                $addressChange && ($branch->last_verified_at !== null || $branch->imported_address_hash !== self::fact($branch->address)) => ['field_conflict', 'The source does not control the current address. Review the business separately.'],
                $websiteChange && $leader && $business->imported_website_hash !== self::fact($business->website) => ['field_conflict', 'The source does not control the current website. Review the business separately.'],
                $branch !== null && ($addressChange || ($leader && $websiteChange)) => ['update_facts', null],
                $branch !== null => ['unchanged', null],
                $business === null && $leader => ['create_business', null],
                default => ['create_branch', null],
            };
            if ($leader && $websiteChange && $decision === 'create_branch' && $business->imported_website_hash !== self::fact($business->website)) {
                [$decision, $message] = ['field_conflict', 'The source does not control the current website. Review the business separately.'];
            }
            $details = [
                'reason' => $message, 'business' => $businessId, 'branch' => $branch?->id,
                'location' => $location, 'address_key' => $addressKey,
                'change_address' => $addressChange, 'change_website' => $websiteChange && $leader,
                'website_proposal' => $business !== null && $business->website_source_id === $plan->source_id ? self::fact($payload['website']) : null,
                'establish_address_baseline' => ! $protected && $branch !== null && $branch->imported_address_hash === null
                    && $branch->last_verified_at === null && $branch->location_status === 'imported_address',
                'protected' => $protected, 'candidates' => array_values(array_unique([...$known, ...$collision, ...($physical === null ? [] : [$physical->business_id])])),
                'state' => self::fact([$business, $branch, $physical, $mapping, $protected, $collision]),
            ];
            yield [$row, $decision, $details];
        }
    }

    public static function fact(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function branchRepetitions(Builder $rows, object $row, array &$cache): array
    {
        if (isset($cache[$row->branch_key])) {
            return $cache[$row->branch_key];
        }
        $others = (clone $rows)->where('branch_key', $row->branch_key)
            ->where(fn ($query) => $query->whereNull('review')->orWhere('review->action', '!=', 'skip'));
        if (! (clone $others)->where('id', '!=', $row->id)->exists()) {
            return ['first' => $row->id, 'conflict' => false];
        }
        // HEX provides the same byte-exact comparison under SQLite and MariaDB collations.
        $summary = $others->selectRaw('MIN(id) AS first_id, MIN(HEX(payload)) <> MAX(HEX(payload)) AS differing')->first();

        return $cache[$row->branch_key] = ['first' => (int) $summary->first_id, 'conflict' => (bool) $summary->differing];
    }

    private function protectedRecord(string $table, int $business, bool $lock, ?string $status = null): bool
    {
        return DB::table($table)->where('business_id', $business)->when($status, fn ($query) => $query->where('status', $status))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first() !== null;
    }
}

<?php

namespace App\Support\BusinessImports;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDirectory;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BusinessImportPlan
{
    public function __construct(
        private readonly BusinessCsv $csv,
        private readonly BusinessSources $sources,
        private readonly BusinessImportResolver $resolver,
    ) {}

    public function stage(User $actor, int $source, $stream, bool $fullSnapshot = false): object
    {
        BusinessSources::authorize($actor);

        return DB::transaction(function () use ($actor, $source, $stream, $fullSnapshot): object {
            $registry = $this->sources->get($source);
            $id = DB::table('business_import_runs')->insertGetId([
                'source_id' => $source, 'source_revision' => $registry->revision, 'status' => 'staged',
                'full_snapshot' => $fullSnapshot, 'staged_by' => $actor->id, 'expires_at' => now()->addDay(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $batch = [];
            $hash = $this->csv->read($stream, function (int $line, array $record) use ($id, &$batch): void {
                $data = $record['data'] ?? null;
                $batch[] = [
                    'run_id' => $id, 'row_number' => $line,
                    'group_key' => $data === null ? null : ($data['business_key'] !== null ? 'g:'.$data['business_key'] : 'b:'.$data['external_key']),
                    'branch_key' => $data === null ? null : BusinessCsv::branchKey($data['external_key']),
                    'name_key' => $data === null ? null : BusinessDirectory::identityNameKey($data['name']),
                    'physical_key' => $data === null ? null : BusinessImportResolver::fact([
                        $data['location'], BusinessDirectory::addressKey($data['name'], $data['address']),
                    ]),
                    'payload' => $data === null ? null : self::json($data),
                    'decision' => 'invalid', 'details' => self::json(['reason' => $record['error'] ?? null]),
                ];
                if (count($batch) === 150) {
                    DB::table('business_import_rows')->insert($batch);
                    $batch = [];
                }
            });
            if ($batch !== []) {
                DB::table('business_import_rows')->insert($batch);
            }
            DB::table('business_import_runs')->where('id', $id)->update(['input_sha256' => $hash]);
            $plan = $this->get($id);
            $this->fingerprint($plan, save: true);
            Audit::record('business.import-staged', $actor->id, context: [
                'run' => $id, 'source' => $source, 'input_sha256' => $hash, 'source_revision' => $registry->revision,
            ]);

            return $this->get($id);
        });
    }

    public function inspect(User $actor, int $id): object
    {
        BusinessSources::authorize($actor);

        return $this->get($id);
    }

    public function counts(User $actor, int $id): array
    {
        $this->inspect($actor, $id);

        return $this->rows($id)->selectRaw('decision, COUNT(*) AS total')->groupBy('decision')->orderBy('decision')
            ->pluck('total', 'decision')->map(fn ($n) => (int) $n)->all();
    }

    public function listRows(User $actor, int $id, ?string $decision = null): iterable
    {
        $this->inspect($actor, $id);

        return $this->rows($id)->when($decision, fn ($query) => $query->where('decision', $decision))->lazyById(200);
    }

    public function resolve(User $actor, int $id, string $token, int $line, string $action, ?int $business, string $reason): void
    {
        BusinessSources::authorize($actor);
        $reason = BusinessSources::note($reason);
        DB::transaction(function () use ($actor, $id, $token, $line, $action, $business, $reason): void {
            $plan = $this->get($id, lock: true);
            $this->requirePlan($plan, $token);
            $this->requireSource($plan, approval: false);
            $row = $this->rows($id)->where('row_number', $line)->first() ?? throw new RuntimeException('Unknown staged row.');
            if (! in_array($action, ['skip', 'link', 'distinct'], true)
                || ($action === 'skip' && in_array($row->decision, BusinessImportResolver::ACTIONS, true))
                || ($action !== 'skip' && $row->decision !== 'identity_conflict')
                || ($action === 'link') !== ($business !== null)) {
                throw new RuntimeException('This row does not accept that resolution.');
            }
            if ($plan->full_snapshot && $action === 'skip') {
                throw new RuntimeException('Stage an incremental plan to exclude rows from incomplete snapshots.');
            }
            if ($business !== null && ! Business::whereKey($business)->where('status', 'active')->exists()) {
                throw new RuntimeException('Choose an existing approved business.');
            }

            DB::table('business_import_rows')->where('id', $row->id)->update(['review' => self::json([
                'action' => $action, 'business' => $business, 'actor' => $actor->id, 'reason' => $reason,
            ])]);
            $this->fingerprint($plan, save: true);
            Audit::record('business.import-row-resolved', $actor->id, context: [
                'run' => $id, 'line' => $line, 'action' => $action, 'business' => $business,
            ]);
        }, attempts: 3);
    }

    public function apply(User $actor, int $id, string $token, string $reason): array
    {
        BusinessSources::authorize($actor);
        $reason = BusinessSources::note($reason);

        return DB::transaction(function () use ($actor, $id, $token, $reason): array {
            // A write obtains the same import lock on both SQLite and MariaDB before taking any snapshots.
            DB::table('business_import_mutex')->where('id', 1)->increment('generation');
            BusinessSources::authorize($actor, lock: true);
            $plan = $this->get($id, lock: true);
            $this->requirePlan($plan, $token);
            $this->requireSource($plan, approval: true);
            if (! hash_equals($plan->review_token, $this->fingerprint($plan))) {
                throw new RuntimeException('Directory records changed after preview. Stage and review a fresh plan.');
            }
            if ($this->rows($id)->whereNotIn('decision', BusinessImportResolver::ACTIONS)->exists()) {
                throw new RuntimeException('This plan has unresolved rows. Resolve, correct, or explicitly skip each conflict.');
            }

            $seenGroups = [];
            $updatedWebsites = [];
            $created = $updated = $skipped = 0;
            foreach ($this->rows($id)->lazyById(200) as $row) {
                if ($row->decision === 'skipped') {
                    $skipped++;

                    continue;
                }
                $data = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                $details = json_decode($row->details, true, flags: JSON_THROW_ON_ERROR);
                if ($row->decision === 'duplicate_exact') {
                    $first = $this->rows($id)->where('id', $details['repeat_of'])->first(['result_business_id', 'result_branch_id']);
                    if ($first?->result_branch_id === null) {
                        throw new RuntimeException('The duplicate references a row without an applied branch.');
                    }
                    $this->rows($id)->where('id', $row->id)->update((array) $first);
                    $skipped++;

                    continue;
                }
                $business = $details['business'] ?? $seenGroups[$row->group_key] ?? null;
                if ($row->decision === 'create_business') {
                    $business = $this->createBusiness($plan, $data);
                    if ($data['business_key'] !== null) {
                        $seenGroups[$row->group_key] = $business;
                    }
                } elseif ($details['change_website'] && ! isset($updatedWebsites[$business])) {
                    DB::table('businesses')->where('id', $business)->update([
                        'website' => $data['website'], 'imported_website_hash' => BusinessImportResolver::fact($data['website']), 'updated_at' => now(),
                    ]);
                    $updatedWebsites[$business] = true;
                }

                if ($data['business_key'] !== null && ! $details['protected']) {
                    $this->bindGroup($plan->source_id, $data['business_key'], $business);
                }
                $branch = $details['branch'];
                if ($branch === null) {
                    $record = new BusinessLocation([
                        'business_id' => $business, 'location_id' => $details['location'], 'address' => $data['address'],
                        'address_key' => $details['address_key'], 'external_key' => $data['external_key'], 'source_id' => $plan->source_id,
                        'location_status' => 'imported_address', 'imported_at' => now(),
                    ]);
                    $record->forceFill([
                        'imported_address_hash' => BusinessImportResolver::fact($data['address']), 'last_import_run_id' => $id,
                    ])->save();
                    $branch = $record->id;
                    $created++;
                } else {
                    $changes = ['last_import_run_id' => $id, 'import_stale_at' => null];
                    if ($details['change_address'] || $details['establish_address_baseline']) {
                        $changes['imported_address_hash'] = BusinessImportResolver::fact($data['address']);
                    }
                    if ($details['change_address']) {
                        $changes += ['address' => $data['address'], 'address_key' => $details['address_key'], 'updated_at' => now()];
                    }
                    DB::table('business_locations')->where('id', $branch)->update($changes);
                    $updated += (int) ($row->decision === 'update_facts');
                }
                DB::table('business_import_rows')->where('id', $row->id)->update([
                    'result_business_id' => $business, 'result_branch_id' => $branch,
                ]);
            }
            $stale = 0;
            if ($plan->full_snapshot) {
                $stale = DB::table('business_locations')->where('source_id', $plan->source_id)->whereNull('import_stale_at')
                    ->where(fn ($query) => $query->whereNull('last_import_run_id')->orWhere('last_import_run_id', '!=', $id))
                    ->update(['import_stale_at' => now()]);
            }
            DB::table('business_import_runs')->where('id', $id)->update([
                'status' => 'applied', 'applied_by' => $actor->id, 'created' => $created, 'skipped' => $skipped, 'updated_at' => now(),
            ]);
            $counts = compact('created', 'updated', 'skipped', 'stale');
            Audit::record('business.import-applied', $actor->id, context: [
                'run' => $id, 'source' => $plan->source_id, 'input_sha256' => $plan->input_sha256, 'source_revision' => $plan->source_revision,
                'review_token' => $token, 'reference' => $reason, 'counts' => $counts,
            ]);

            return $counts;
        }, attempts: 3);
    }

    /** Purge expired payloads without losing dispositions, original row numbers, or apply provenance. */
    public function purge(User $actor): int
    {
        BusinessSources::authorize($actor);
        $count = $this->rowsForPurge()->update(['payload' => null]);
        Audit::record('business.import-payloads-purged', $actor->id, context: ['rows' => $count]);

        return $count;
    }

    private function rowsForPurge(): Builder
    {
        return DB::table('business_import_rows')->whereNotNull('payload')->whereIn('run_id',
            DB::table('business_import_runs')->select('id')->where('expires_at', '<', now()->subDay()));
    }

    private function get(int $id, bool $lock = false): object
    {
        return DB::table('business_import_runs')->where('id', $id)->whereNotNull('input_sha256')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first() ?? throw new RuntimeException('Unknown reviewed import plan.');
    }

    private function requirePlan(object $plan, string $token): void
    {
        if ($plan->status === 'applied') {
            throw new RuntimeException('This plan has already applied. No further changes were written.');
        }
        if (now()->gte($plan->expires_at)) {
            throw new RuntimeException('This plan expired. Stage and review a new import.');
        }
        if (! hash_equals($plan->review_token, $token)) {
            throw new RuntimeException('The reviewed plan token does not match. Inspect the current plan.');
        }
    }

    private function requireSource(object $plan, bool $approval): void
    {
        $source = $this->sources->get($plan->source_id, lock: true);
        if ($source->revision !== $plan->source_revision || ($approval && $source->approved_revision !== $source->revision)) {
            throw new RuntimeException('This plan needs a current approved source revision. Review the source and stage a fresh plan.');
        }
    }

    private function fingerprint(object $plan, bool $save = false): string
    {
        $digest = hash_init('sha256');
        hash_update($digest, self::json([$plan->id, $plan->source_id, $plan->source_revision, $plan->input_sha256,
            (bool) $plan->full_snapshot, $plan->expires_at])."\n");
        if ($plan->full_snapshot) {
            foreach (DB::table('business_locations')->where('source_id', $plan->source_id)
                ->when(! $save, fn ($query) => $query->lockForUpdate())->lazyById(200) as $branch) {
                hash_update($digest, self::json($branch)."\n");
            }
        }
        foreach ($this->resolver->decisions($plan, lock: ! $save) as [$row, $decision, $details]) {
            if (! $save && ($row->decision !== $decision || self::json(json_decode($row->details, true, flags: JSON_THROW_ON_ERROR)) !== self::json($details))) {
                throw new RuntimeException('Directory records or staged decisions changed after preview. Stage and review a fresh plan.');
            }
            hash_update($digest, self::json([$row->id, $row->payload, $row->review, $decision, $details])."\n");
            if ($save) {
                DB::table('business_import_rows')->where('id', $row->id)->update(['decision' => $decision, 'details' => self::json($details)]);
            }
        }
        $token = hash_final($digest);
        if ($save) {
            DB::table('business_import_runs')->where('id', $plan->id)->update(['review_token' => $token]);
        }

        return $token;
    }

    private function createBusiness(object $plan, array $data): int
    {
        $business = new Business([
            'slug' => BusinessDirectory::slug($data['name']), 'name' => $data['name'], 'category' => $data['category'],
            'website' => $data['website'], 'status' => 'active',
        ]);
        $business->forceFill(['website_source_id' => $plan->source_id, 'imported_website_hash' => BusinessImportResolver::fact($data['website'])])->save();

        return $business->id;
    }

    private function bindGroup(int $source, string $identity, int $business): void
    {
        $key = ['source_id' => $source, 'identity_key' => $identity];
        $existing = DB::table('business_import_identities')->where($key)->value('business_id');
        if ($existing === null) {
            DB::table('business_import_identities')->insert($key + ['business_id' => $business]);
        } elseif ($existing !== $business) {
            throw new RuntimeException('The source identity changed during apply.');
        }
    }

    private function rows(int $plan): Builder
    {
        return DB::table('business_import_rows')->where('run_id', $plan);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}

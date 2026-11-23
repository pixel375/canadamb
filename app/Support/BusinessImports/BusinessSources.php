<?php

namespace App\Support\BusinessImports;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class BusinessSources
{
    public const ABILITY = 'manage-business-imports';

    public static function authorize(User $actor, bool $lock = false): void
    {
        Gate::forUser(User::whereKey($actor->id)->when($lock, fn ($query) => $query->lockForUpdate())->first())->authorize(self::ABILITY);
    }

    public static function note(string $note): string
    {
        $note = trim($note);
        if (mb_strlen($note) < 12 || mb_strlen($note) > 300 || preg_match('/[\p{Cc}\p{Cf}]/u', $note) !== 0) {
            throw new RuntimeException('Enter a review reference or reason of 12–300 characters.');
        }

        return $note;
    }

    public function register(User $actor, string $name, string $url, string $license, ?string $terms, ?string $vintage): int
    {
        self::authorize($actor);
        $metadata = $this->metadata($url, $license, $terms, $vintage);
        $name = BusinessCsv::metadataText($name, 255);
        if ($name === '') {
            throw new RuntimeException('Enter the registry source name.');
        }

        return DB::transaction(function () use ($actor, $name, $metadata): int {
            $source = DB::table('business_sources')->where('name', $name)->lockForUpdate()->first();
            if ($source !== null) {
                if ($source->url !== $metadata['url'] || $source->license !== $metadata['license']
                    || ($metadata['terms_url'] !== null && $source->terms_url !== $metadata['terms_url'])
                    || ($metadata['data_as_of'] !== null && $source->data_as_of !== $metadata['data_as_of'])) {
                    throw new RuntimeException('Source metadata differs. Revise the registry entry explicitly.');
                }

                return $source->id;
            }
            $id = DB::table('business_sources')->insertGetId($metadata + ['name' => $name,
                'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('business.import-source-registered', $actor->id, context: ['source' => $id, 'revision' => 1] + $metadata);

            return $id;
        }, attempts: 3);
    }

    public function revise(User $actor, int $id, int $revision, string $url, string $license, ?string $terms, ?string $vintage): void
    {
        self::authorize($actor);
        $metadata = $this->metadata($url, $license, $terms, $vintage);
        DB::transaction(function () use ($actor, $id, $revision, $metadata): void {
            $source = $this->get($id, lock: true);
            $this->requireRevision($source, $revision);
            DB::table('business_sources')->where('id', $id)->update($metadata + [
                'revision' => $revision + 1, 'approved_revision' => null, 'updated_at' => now(),
            ]);
            Audit::record('business.import-source-revised', $actor->id, context: ['source' => $id, 'revision' => $revision + 1] + $metadata);
        }, attempts: 3);
    }

    public function approve(User $actor, int $id, int $revision, string $reference): void
    {
        self::authorize($actor);
        $reference = self::note($reference);
        DB::transaction(function () use ($actor, $id, $revision, $reference): void {
            $source = $this->get($id, lock: true);
            $this->requireRevision($source, $revision);
            if ($revision < 1 || $source->terms_url === null || $source->data_as_of === null) {
                throw new RuntimeException('Revise the source with its reuse terms URL and verified data vintage before approval.');
            }
            if ($source->approved_revision === $revision) {
                throw new RuntimeException('This source revision has already been approved.');
            }
            DB::table('business_sources')->where('id', $id)->update(['approved_revision' => $revision, 'updated_at' => now()]);
            Audit::record('business.import-source-approved', $actor->id, context: [
                'source' => $id, 'revision' => $revision, 'reference' => $reference,
            ]);
        }, attempts: 3);
    }

    public function suspend(User $actor, int $id, int $revision, string $reference): void
    {
        self::authorize($actor);
        $reference = self::note($reference);
        DB::transaction(function () use ($actor, $id, $revision, $reference): void {
            $source = $this->get($id, lock: true);
            $this->requireRevision($source, $revision);
            DB::table('business_sources')->where('id', $id)->update([
                'approved_revision' => null, 'revision' => $revision + 1, 'updated_at' => now(),
            ]);
            Audit::record('business.import-source-suspended', $actor->id, context: [
                'source' => $id, 'revision' => $revision + 1, 'reference' => $reference,
            ]);
        }, attempts: 3);
    }

    public function get(int $id, bool $lock = false): object
    {
        return DB::table('business_sources')->where('id', $id)->when($lock, fn ($query) => $query->lockForUpdate())->first()
            ?? throw new RuntimeException('Unknown business source.');
    }

    private function requireRevision(object $source, int $revision): void
    {
        if ($source->revision !== $revision) {
            throw new RuntimeException('Source revision changed. Review the current registry entry.');
        }
    }

    private function metadata(string $url, string $license, ?string $terms, ?string $vintage): array
    {
        $license = BusinessCsv::metadataText($license, 255);
        if (! BusinessCsv::webUrl($url, secure: true) || strlen($url) > 255 || $license === ''
            || ($terms !== null && (! BusinessCsv::webUrl($terms, secure: true) || strlen($terms) > 512))) {
            throw new RuntimeException('Enter a license statement and valid authoritative HTTPS URLs.');
        }
        Validator::validate(['data_as_of' => $vintage], ['data_as_of' => 'nullable|date_format:Y-m-d|before_or_equal:today']);

        return ['url' => $url, 'license' => $license, 'terms_url' => $terms, 'data_as_of' => $vintage];
    }
}

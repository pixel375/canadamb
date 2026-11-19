<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Location;
use App\Support\BusinessDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class ImportBusinesses extends Command
{
    protected $signature = 'businesses:import {file} {--source=} {--license=} {--url=}';

    protected $description = 'Import pre-reviewed licensed business branches from a private UTF-8 CSV';

    public function handle(): int
    {
        $meta = Validator::make([
            'name' => $this->option('source'), 'license' => $this->option('license'), 'url' => $this->option('url'),
        ], ['name' => 'required|string|max:255', 'license' => 'required|string|max:255', 'url' => 'required|url:https|max:255'])->validate();
        $path = $this->argument('file');
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 1_500_000) {
            $this->error('Select a readable local CSV file under 1.5 MB.');

            return self::FAILURE;
        }

        $source = DB::transaction(function () use ($meta) {
            DB::table('business_sources')->insertOrIgnore($meta + ['created_at' => now(), 'updated_at' => now()]);
            $source = DB::table('business_sources')->where('name', $meta['name'])->lockForUpdate()->firstOrFail();
            if ($source->url !== $meta['url'] || $source->license !== $meta['license']) {
                throw new RuntimeException('The source URL and license must match the existing source entry.');
            }

            return $source;
        });
        $run = DB::table('business_import_runs')->insertGetId(['source_id' => $source->id, 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);
        $line = 1;
        $stream = fopen($path, 'rb');
        try {
            $header = fgetcsv($stream, separator: ',', enclosure: '"', escape: '');
            $columns = ['external_key', 'name', 'category', 'location', 'address', 'website'];
            if ($header !== $columns) {
                throw new RuntimeException('CSV header does not match the import format.');
            }
            [$created, $skipped] = DB::transaction(function () use ($stream, $source, $columns, &$line) {
                DB::table('business_sources')->where('id', $source->id)->lockForUpdate()->first();
                $created = $skipped = 0;
                while (($record = fgetcsv($stream, separator: ',', enclosure: '"', escape: '')) !== false) {
                    $line++;
                    if ($record === [null]) {
                        continue;
                    }
                    if ($line > 1001 || count($record) !== count($columns)) {
                        throw new RuntimeException('Invalid columns or too many rows.');
                    }
                    $row = Validator::make(array_combine($columns, array_map(trim(...), $record)), [
                        'external_key' => 'required|string|max:100', 'name' => 'required|string|max:140',
                        'category' => ['required', Rule::in(array_keys(BusinessDirectory::CATEGORIES))],
                        'location' => 'required|string|max:100', 'address' => 'required|string|max:255', 'website' => 'nullable|url:http,https|max:255',
                    ])->validate();
                    $location = Location::selectable()->where('slug', $row['location'])->firstOrFail();
                    $distinct = BusinessDirectory::addressKey($row['name'], $row['address']);
                    if (BusinessLocation::where(['source_id' => $source->id, 'external_key' => $row['external_key']])->exists()
                        || BusinessLocation::where(['location_id' => $location->id, 'address_key' => $distinct])->exists()) {
                        $skipped++;

                        continue;
                    }
                    $business = Business::create([
                        'slug' => BusinessDirectory::slug($row['name']), 'name' => $row['name'], 'category' => $row['category'],
                        'website' => $row['website'] ?: null, 'status' => 'active',
                    ]);
                    $business->locations()->create([
                        'location_id' => $location->id, 'address' => $row['address'], 'address_key' => $distinct,
                        'external_key' => $row['external_key'], 'source_id' => $source->id,
                        'location_status' => 'imported_address', 'imported_at' => now(),
                    ]);
                    $created++;
                }

                return [$created, $skipped];
            });
        } catch (Throwable $exception) {
            DB::table('business_import_runs')->where('id', $run)->update([
                'status' => 'failed', 'error' => "Row $line: ".$exception::class, 'updated_at' => now(),
            ]);
            $this->error("Import failed on row $line. No business changes were committed.");

            return self::FAILURE;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        DB::table('business_import_runs')->where('id', $run)->update(compact('created', 'skipped') + ['status' => 'succeeded', 'updated_at' => now()]);
        $this->info("Created $created branches. Skipped $skipped duplicates.");

        return self::SUCCESS;
    }
}

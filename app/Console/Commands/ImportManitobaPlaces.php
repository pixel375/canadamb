<?php

namespace App\Console\Commands;

use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ImportManitobaPlaces extends Command
{
    protected $signature = 'locations:import-cgn {file} {--apply : Import into the directory after reviewing the source}';

    protected $description = 'Validate or import a reviewed English Manitoba CGNDB CSV';

    private const TYPES = [
        'City' => 'city', 'Town' => 'town', 'Village' => 'village', 'Community' => 'rural community',
        'Northern Community' => 'northern community', 'Urban Community' => 'urban community',
        'Locality' => 'rural locality', 'Hamlet' => 'hamlet', 'Local Urban District' => 'local urban district',
        'Rural Municipality' => 'rural municipality', 'Local Government District' => 'local government district',
        'First Nation Administrative Location' => 'First Nation administrative location', 'Indian Reserve' => 'First Nation reserve',
        'Settlement' => 'settlement',
    ];

    private const STARTERS = [
        'GADMZ' => 'brandon', 'GAGDE' => 'dauphin', 'GAHPW' => 'elkhorn', 'GAILW' => 'flin-flon',
        'GAWDD' => 'portage-la-prairie', 'GBAML' => 'steinbach', 'GBBRP' => 'thompson',
        'GBCSD' => 'virden', 'GBSLJ' => 'winkler', 'GBEIN' => 'winnipeg',
    ];

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file) || ! is_readable($file) || filesize($file) > 15_000_000) {
            $this->error('Provide an extracted local CSV under 15 MB.');

            return self::FAILURE;
        }
        $stream = fopen($file, 'rb');
        if (! $stream) {
            $this->error('The CSV could not be opened.');

            return self::FAILURE;
        }
        try {
            $rows = $this->read($stream);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            fclose($stream);
        }

        if ($rows === []) {
            $this->error('The CSV contained no eligible Manitoba place records.');

            return self::FAILURE;
        }
        $this->info(count($rows).' eligible Manitoba place records.');
        if (! $this->option('apply')) {
            $this->info('Validation only. Use --apply after reviewing names and place types.');

            return self::SUCCESS;
        }
        DB::transaction(fn () => $this->persist($rows));
        $this->info('Imported selected places and preserved existing starter location IDs.');

        return self::SUCCESS;
    }

    private function read($stream): array
    {
        $header = fgetcsv($stream, escape: '');
        if ($header) {
            $header[0] = Str::replaceStart("\xEF\xBB\xBF", '', $header[0]);
        }
        $required = ['CGNDB ID', 'Geographical Name', 'Generic Term', 'Latitude', 'Longitude', 'Province - Territory'];
        if (! $header || array_diff($required, $header)) {
            throw new RuntimeException('The CSV does not have the expected CGNDB columns.');
        }
        $places = [];
        while (($values = fgetcsv($stream, escape: '')) !== false) {
            if ($values === [null]) {
                continue;
            }
            if (count($values) !== count($header)) {
                throw new RuntimeException('The CSV has an incomplete row.');
            }
            $row = array_combine($header, $values);
            $type = self::TYPES[$row['Generic Term']] ?? null;
            if (! $type || $row['Province - Territory'] !== 'Manitoba') {
                continue;
            }
            $key = $row['CGNDB ID'];
            $name = trim($row['Geographical Name']);
            $latitude = $row['Latitude'];
            $longitude = $row['Longitude'];
            if (! preg_match('/^[A-Z0-9]{5}$/D', $key) || $name === '' || ! mb_check_encoding($name, 'UTF-8')
                || mb_strlen($name) > 150 || ! is_numeric($latitude) || ! is_numeric($longitude)
                || $latitude < 48.9 || $latitude > 60.1 || $longitude < -102.1 || $longitude > -88.8) {
                throw new RuntimeException('A Manitoba row had an invalid identifier, name or coordinate.');
            }
            $places[$key] = ['name' => $name, 'type' => $type, 'latitude' => $latitude, 'longitude' => $longitude];
        }

        return $places;
    }

    private function persist(array $rows): void
    {
        $reference = ['name' => 'Natural Resources Canada · Canadian Geographical Names Database (Manitoba)'];
        DB::table('location_sources')->insertOrIgnore($reference + [
            'url' => 'https://natural-resources.canada.ca/maps-tools-publications/maps/geographical-names-canada/download-geographical-names-data',
            'license' => 'Open Government Licence – Canada', 'imported_at' => now(),
        ]);
        $source = DB::table('location_sources')->where($reference)->lockForUpdate()->firstOrFail();
        $province = Location::where('slug', 'manitoba')->firstOrFail();
        $records = [];
        foreach ($rows as $key => $record) {
            $record += [
                'slug' => self::STARTERS[$key] ?? ((Str::slug($record['name']) ?: 'place').'-'.strtolower($key)),
                'source_id' => $source->id, 'source_key' => $key, 'parent_id' => $province->id,
                'province' => 'MB', 'selectable' => true, 'created_at' => now(), 'updated_at' => now(),
            ];
            if (isset(self::STARTERS[$key])) {
                Location::where('slug', $record['slug'])->whereNull('source_key')->update([
                    'source_id' => $source->id, 'source_key' => $key,
                ]);
            }
            $records[] = $record;
        }
        foreach (array_chunk($records, 250) as $chunk) {
            Location::upsert($chunk, ['source_id', 'source_key'], ['type', 'latitude', 'longitude', 'updated_at']);
        }
        DB::table('location_sources')->where('id', $source->id)->update(['imported_at' => now()]);
    }
}

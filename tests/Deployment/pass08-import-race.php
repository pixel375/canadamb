<?php

use App\Models\User;
use App\Support\BusinessImports\BusinessImportPlan;
use App\Support\BusinessImports\BusinessSources;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[$script, $release, $directory] = $argv;
require $release.'/vendor/autoload.php';
$app = require $release.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mariadb' || config('database.connections.mariadb.host') !== '127.0.0.1'
    || DB::getDatabaseName() !== 'canadamb_ci_pass08' || ! is_dir($directory)) {
    throw new RuntimeException('This script requires its isolated Pass 08 MariaDB database.');
}

$imports = $app->make(BusinessImportPlan::class);
if (isset($argv[3])) {
    [, , , $worker, $id, $token] = $argv;
    DB::statement('SET SESSION innodb_lock_wait_timeout = 25');
    DB::connection()->beforeExecuting(function (string $sql) use ($directory, $worker): void {
        if (str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'business_import_mutex')) {
            touch("$directory/$worker.ready");
        }
    });
    try {
        $imports->apply(User::findOrFail($worker === 'first' ? 8503 : 8504), (int) $id, $token, 'Reviewed synthetic test plan.');
        echo 'applied';
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'changed after preview')) {
            throw $exception;
        }
        echo 'stale';
    }
    exit;
}

$operator = User::findOrFail(8503);
$registry = $app->make(BusinessSources::class);
$source = $registry->register($operator, 'Isolated import replay', 'https://example.invalid', 'Synthetic fixture only',
    'https://example.invalid/terms', '2026-01-01');
$registry->approve($operator, $source, 1, 'Synthetic testing rights documented.');

function plan(BusinessImportPlan $imports, User $operator, int $source, iterable $records): object
{
    $stream = fopen('php://temp/maxmemory:524288', 'w+b');
    try {
        fwrite($stream, "external_key,name,category,location,address,website,business_key\n");
        foreach ($records as $record) {
            fputcsv($stream, $record, escape: '');
        }
        rewind($stream);

        return $imports->stage($operator, $source, $stream);
    } finally {
        fclose($stream);
    }
}

$fixture = [
    ['west', 'Concurrent repair', 'services', 'brandon', '1 Test Street', '', 'repair'],
    ['east', 'Concurrent repair', 'services', 'winnipeg', '2 Test Street', '', 'repair'],
];
$plans = [plan($imports, $operator, $source, $fixture), plan($imports, $operator, $source, $fixture)];
$workers = ['first', 'second'];
$processes = [];
DB::beginTransaction();
try {
    DB::table('business_import_mutex')->where('id', 1)->lockForUpdate()->first();
    foreach ($workers as $index => $worker) {
        $job = $plans[$index];
        $process = proc_open([PHP_BINARY, $script, $release, $directory, $worker, (string) $job->id, $job->review_token], [
            0 => ['pipe', 'r'], 1 => ['file', "$directory/$worker.result", 'w'], 2 => STDERR,
        ], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the competing importer.');
        }
        fclose($pipes[0]);
        $processes[] = $process;
    }
    $deadline = hrtime(true) + 12_000_000_000;
    do {
        clearstatcache();
        if (is_file("$directory/first.ready") && is_file("$directory/second.ready")) {
            break;
        }
        if (hrtime(true) > $deadline) {
            throw new RuntimeException('Both import processes must reach the serialization lock.');
        }
        usleep(20_000);
    } while (true);
} finally {
    DB::rollBack();
    $exits = array_map(proc_close(...), $processes);
}
$results = array_map(fn ($worker) => trim(file_get_contents("$directory/$worker.result")), $workers);
sort($results);
if (array_filter($exits) !== [] || $results !== ['applied', 'stale']
    || DB::table('business_import_identities')->where('source_id', $source)->count() !== 1
    || DB::table('business_locations')->where('source_id', $source)->count() !== 2) {
    throw new RuntimeException('The competing reviewed plans must commit one organization and two branches exactly once.');
}
echo "Concurrent reviewed imports committed once; the other plan became stale.\n";

$volume = (function (): Generator {
    for ($group = 1; $group <= 2500; $group++) {
        foreach (['brandon', 'elkhorn'] as $place) {
            yield ["load-$group-$place", "Load test business $group", 'retail', $place, "$group Data Road", '', "load-$group"];
        }
    }
})();
$start = hrtime(true);
$large = plan($imports, $operator, $source, $volume);
$staged = (hrtime(true) - $start) / 1e9;
if ($imports->counts($operator, $large->id) !== ['create_branch' => 2500, 'create_business' => 2500]) {
    throw new RuntimeException('The 5,000-row dry run did not group the incoming businesses correctly.');
}
$start = hrtime(true);
$counts = $imports->apply($operator, $large->id, $large->review_token, 'Reviewed scoped load test.');
$applied = (hrtime(true) - $start) / 1e9;
if ($counts !== ['created' => 5000, 'updated' => 0, 'skipped' => 0, 'stale' => 0]
    || DB::table('business_import_identities')->where('source_id', $source)->count() !== 2501
    || DB::table('business_locations')->where('source_id', $source)->count() !== 5002) {
    throw new RuntimeException('The 5,000-row reviewed plan did not apply atomically.');
}
printf("MariaDB 5,000-row import: stage %.2fs; apply %.2fs; PHP peak %.1f MiB.\n", $staged, $applied, memory_get_peak_usage(true) / 1048576);

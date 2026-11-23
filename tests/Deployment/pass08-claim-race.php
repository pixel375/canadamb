<?php

use App\Http\Controllers\Admin\BusinessReviewController;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\Support\BusinessClaims;
use App\Support\ModerationDecision;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

[$script, $release, $directory] = $argv;
require $release.'/vendor/autoload.php';
$app = require $release.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mariadb' || config('database.connections.mariadb.host') !== '127.0.0.1'
    || DB::getDatabaseName() !== 'canadamb_ci_pass08' || ! is_dir($directory)) {
    throw new RuntimeException('This claim race requires the isolated Pass 08 database.');
}
if (isset($argv[3])) {
    [, , , $worker, $claim, $version] = $argv;
    DB::statement('SET SESSION innodb_lock_wait_timeout = 25');
    DB::connection()->beforeExecuting(function (string $sql) use ($directory, $worker): void {
        if (str_contains($sql, 'businesses') && str_contains(strtolower($sql), 'for update')) {
            touch("$directory/$worker.ready");
        }
    });
    $actor = User::findOrFail($worker === 'first' ? 8503 : 8504);
    $request = Request::create("https://canadamb.ca/admin/business-claims/$claim", 'POST', [
        'decision' => 'approved', 'version' => $version, 'notes' => 'Isolated competing decision.',
        'proof_method' => 'official_authority', 'proof_reference' => 'Synthetic proof of authority.',
        'authority_confirmed' => 'on',
    ]);
    $request->setUserResolver(fn () => $actor);
    try {
        $response = $app->make(BusinessReviewController::class)->claim($request, (int) $claim);
        echo $response->getStatusCode() === 302 ? 'approved' : 'unexpected';
    } catch (ValidationException $exception) {
        echo isset($exception->errors()['decision']) ? 'owned' : 'unexpected';
    }
    exit;
}

$business = Business::create(['slug' => 'canadamb-ci-claim-race', 'name' => 'Claim rehearsal organization', 'category' => 'services', 'status' => 'active']);
$business->locations()->create(['location_id' => Location::where('slug', 'brandon')->value('id'), 'address' => '1 Synthetic Drive']);
$workers = ['first' => 8501, 'second' => 8502];
$claims = [];
foreach ($workers as $worker => $claimant) {
    $id = DB::table('business_claims')->insertGetId(['business_id' => $business->id, 'user_id' => $claimant,
        'contact_email' => 'claimant@example.invalid', 'evidence' => BusinessClaims::seal('Synthetic independent claim evidence.'), 'status' => 'pending']);
    $claims[$worker] = DB::table('business_claims')->find($id);
}

$processes = [];
DB::beginTransaction();
try {
    Business::whereKey($business->id)->lockForUpdate()->first();
    foreach ($claims as $worker => $claim) {
        $process = proc_open([PHP_BINARY, $script, $release, $directory, $worker, (string) $claim->id, ModerationDecision::version('claim', $claim)], [
            0 => ['pipe', 'r'], 1 => ['file', "$directory/$worker.result", 'w'], 2 => STDERR,
        ], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the claim reviewer.');
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
            throw new RuntimeException('Both claim decisions must reach the business lock.');
        }
        usleep(20_000);
    } while (true);
} finally {
    DB::rollBack();
    $exits = array_map(proc_close(...), $processes);
}

$results = [trim(file_get_contents("$directory/first.result")), trim(file_get_contents("$directory/second.result"))];
sort($results);
$noticeCount = DB::table('notifications')->where('event', 'claim.approved')->whereIn('resource_id', array_column($claims, 'id'))->count();
if ($results !== ['approved', 'owned'] || array_filter($exits) !== [] || $noticeCount !== 1
    || $business->members()->wherePivot('role', 'owner')->count() !== 1) {
    throw new RuntimeException('Competing independent claim approvals must assign one owner and send one notice.');
}
echo "Competing independent claim approvals assigned one owner and one approval notice.\n";

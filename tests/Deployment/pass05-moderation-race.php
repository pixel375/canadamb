<?php

use App\Http\Controllers\Admin\ModerationWorkbenchController;
use App\Http\Controllers\Admin\NetworkController;
use App\Models\Listing;
use App\Models\User;
use App\Support\ListingPhotoOrder;
use App\Support\Marketplace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

const RACE_LISTING = 8502;
const RACE_REVIEWERS = [8503 => 'active', 8504 => 'rejected'];
const REJECTION_REASON = 'Synthetic rehearsal rejection.';
const PRIVATE_NOTES = 'Synthetic internal reviewer note.';

[$script, $release, $directory] = $argv;
require $release.'/vendor/autoload.php';
$app = require $release.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, 'Pass 05 moderation rehearsal failed: '.$error->getMessage().PHP_EOL);
    exit(1);
});

if (config('database.default') !== 'mariadb' || DB::getDatabaseName() !== 'canadamb_ci_pass05'
    || config('database.connections.mariadb.host') !== '127.0.0.1') {
    throw new RuntimeException('The rehearsal requires the isolated Pass 05 MariaDB database.');
}

if (isset($argv[3], $argv[4])) {
    $reviewer = User::findOrFail((int) $argv[3]);
    $legacy = RACE_REVIEWERS[$reviewer->id] === 'active';
    $baseUrl = $legacy ? 'https://canadamb.ca/admin/moderation/' : 'https://canadamb.ca/admin/moderation/items/listing/';
    $data = $legacy ? ['status' => 'active'] : ['action' => 'reject', 'reason' => REJECTION_REASON];
    $request = Request::create($baseUrl.RACE_LISTING, 'POST', $data + ['version' => $argv[4], 'notes' => PRIVATE_NOTES]);
    $request->setUserResolver(fn () => $reviewer);

    DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
    DB::connection()->beforeExecuting(function (string $sql) use ($directory, $reviewer): void {
        if (str_contains($sql, '`listings`') && str_contains(strtolower($sql), 'for update')) {
            touch("$directory/$reviewer->id.ready");
        }
    });

    try {
        $response = $legacy
            ? $app->make(NetworkController::class)->moderate($request, RACE_LISTING, $app->make(Marketplace::class))
            : $app->make(ModerationWorkbenchController::class)->decide($request, 'listing', RACE_LISTING);
        echo $response->getStatusCode();
    } catch (HttpExceptionInterface $error) {
        echo $error->getStatusCode();
    }
    exit;
}

$reviewers = array_keys(RACE_REVIEWERS);
$processes = [];
$auditFloor = DB::table('audit_logs')->max('id');
DB::beginTransaction();
try {
    // A separate connection holds the listing lock until both reviewers attempt it.
    $listing = Listing::lockForUpdate()->findOrFail(RACE_LISTING);
    $version = ListingPhotoOrder::version($listing);
    foreach ($reviewers as $reviewer) {
        $process = proc_open([PHP_BINARY, $script, $release, $directory, (string) $reviewer, $version], [
            0 => ['pipe', 'r'], 1 => ['file', "$directory/$reviewer.result", 'w'], 2 => STDERR,
        ], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start a moderation worker.');
        }
        fclose($pipes[0]);
        $processes[] = $process;
    }

    $deadline = hrtime(true) + 12_000_000_000;
    while (true) {
        clearstatcache();
        if (is_file("$directory/$reviewers[0].ready") && is_file("$directory/$reviewers[1].ready")) {
            break;
        }
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Both reviewers did not reach the listing lock.');
        }
        usleep(20_000);
    }
} finally {
    DB::rollBack();
    $exits = array_map(proc_close(...), $processes);
}
if (array_filter($exits) !== []) {
    throw new RuntimeException('A moderation worker exited unexpectedly.');
}

$results = [];
foreach ($reviewers as $reviewer) {
    $results[$reviewer] = trim(file_get_contents("$directory/$reviewer.result"));
}
asort($results);
if (array_values($results) !== ['302', '409']) {
    throw new RuntimeException('Expected one successful decision and one stale conflict, received '.implode(', ', $results).'.');
}

$winner = array_key_first($results);
$status = RACE_REVIEWERS[$winner];
$active = $status === 'active';
$reason = $active ? null : REJECTION_REASON;
$action = $active ? 'approve' : 'reject';
$event = $active ? 'listing.approved' : 'listing.rejected';
$history = DB::table('listing_status_history')->where('listing_id', RACE_LISTING)->sole();
$audit = DB::table('audit_logs')->where('id', '>', $auditFloor)->whereIn('actor_id', $reviewers)->sole();
$decision = DB::table('moderation_decisions')->where(['type' => 'listing', 'resource_id' => RACE_LISTING])->sole();
$notice = DB::table('notifications')->where('resource_id', RACE_LISTING)->where('event', 'like', 'listing.%')->sole();
$noticeLocations = DB::table('notification_locations')->where('notification_id', $notice->id)->pluck('location_id')->all();
$auditContext = json_decode($audit->context, true, flags: JSON_THROW_ON_ERROR);

if ($listing->fresh()->status !== $status || $history->actor_id !== $winner || $history->previous_status !== 'pending'
    || $history->status !== $status || $history->reason !== $reason || $audit->actor_id !== $winner
    || $audit->action !== 'listing.'.$status || ($auditContext['listing'] ?? null) !== RACE_LISTING || str_contains($audit->context, PRIVATE_NOTES)
    || $decision->audit_id !== $audit->id || $decision->action !== $action || $decision->reason !== $reason || $decision->notice_source_id !== $history->id
    || $decision->notes !== PRIVATE_NOTES
    || $notice->user_id !== $listing->user_id || $notice->event !== $event || $notice->read_at !== null
    || $notice->source_id !== $history->id || $noticeLocations !== [$listing->location_id]) {
    throw new RuntimeException('The winning review must persist one decision, status history, audit and scoped owner notice.');
}

echo "Simultaneous legacy and workbench reviews produced one listing decision, status history, audit and scoped owner notice. The stale review returned 409.\n";

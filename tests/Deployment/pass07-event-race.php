<?php

use App\Http\Controllers\Admin\CommunityModerationController;
use App\Http\Controllers\DiscussionEventController;
use App\Http\Controllers\OwnedDiscussionsController;
use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\User;
use App\Support\ApprovalPolicy;
use App\Support\ModerationDecision;
use App\Support\NetworkUrl;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

const SOURCE = 8501;
const OWNER = 8501;

[$script, $release, $directory] = $argv;
require $release.'/vendor/autoload.php';
$app = require $release.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mariadb' || DB::getDatabaseName() !== 'canadamb_ci_pass07'
    || config('database.connections.mariadb.host') !== '127.0.0.1') {
    throw new RuntimeException('The rehearsal requires its isolated Pass 07 MariaDB database.');
}

function metadata(): array
{
    return ['category' => 'community', 'organizer' => 'Disposable event team', 'venue' => 'Community hall',
        'starts_at' => now('America/Winnipeg')->addDays(10)->setTime(12, 0)->format('Y-m-d\TH:i')];
}

function actionRequest(int $actor, string $method, string $path, array $fields): Request
{
    $request = Request::create('https://canadamb.ca'.$path, $method, $fields);
    $user = User::findOrFail($actor);
    $request->setUserResolver(fn () => $user);
    $request->attributes->set('site', NetworkUrl::main());

    return $request;
}

if (isset($argv[3])) {
    [, , , $phase, $worker, $threadVersion, $eventVersion, $id] = $argv;
    DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
    DB::connection()->beforeExecuting(function (string $sql) use ($directory, $phase, $worker): void {
        if (str_contains($sql, '`community_threads`') && stripos($sql, 'for update') !== false) {
            touch("$directory/$phase-$worker.ready");
        }
    });
    try {
        $policy = $app->make(ApprovalPolicy::class);
        $parent = ['thread_version' => $threadVersion];
        if ($phase === 'request') {
            $request = actionRequest(OWNER, 'POST', '/discussions/'.SOURCE.'/event', metadata() + $parent);
            $response = $app->make(DiscussionEventController::class)->store($request, SOURCE, $policy);
        } elseif ($phase === 'source' && $worker === 'first') {
            $request = actionRequest(OWNER, 'PUT', '/account/discussions/'.SOURCE, [
                'title' => 'Updated event discussion', 'body' => 'The source discussion was materially changed.',
                'category' => 'general', 'version' => $threadVersion,
            ]);
            $response = $app->make(OwnedDiscussionsController::class)->update($request, SOURCE, $policy);
        } else {
            $request = actionRequest($worker === 'first' ? 8503 : 8504, 'POST', "/admin/community/events/$id",
                ['action' => 'approve', 'version' => $eventVersion] + $parent);
            $response = $app->make(CommunityModerationController::class)->events($request, (int) $id);
        }
        echo $response->getStatusCode();
    } catch (HttpExceptionInterface $error) {
        echo $error->getStatusCode();
    }
    exit;
}

function compete(string $phase, string $script, string $release, string $directory): array
{
    $workers = ['first', 'second'];
    $processes = [];
    DB::beginTransaction();
    try {
        $thread = CommunityThread::lockForUpdate()->findOrFail(SOURCE);
        $item = CommunityEvent::where('thread_id', SOURCE)->first();
        $parent = ModerationDecision::version('thread', $thread);
        $version = $item ? ModerationDecision::version('event', $item) : '';
        foreach ($workers as $worker) {
            $process = proc_open([PHP_BINARY, $script, $release, $directory, $phase, $worker, $parent, $version, (string) ($item?->id ?? 0)], [
                0 => ['pipe', 'r'], 1 => ['file', "$directory/$phase-$worker.result", 'w'], 2 => STDERR,
            ], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start the linked event worker.');
            }
            fclose($pipes[0]);
            $processes[] = $process;
        }
        $until = hrtime(true) + 12_000_000_000;
        while (true) {
            clearstatcache();
            if (is_file("$directory/$phase-first.ready") && is_file("$directory/$phase-second.ready")) {
                break;
            }
            if (hrtime(true) >= $until) {
                throw new RuntimeException('Both workers must reach the source discussion lock.');
            }
            usleep(20_000);
        }
    } finally {
        DB::rollBack();
        $exits = array_map(proc_close(...), $processes);
    }
    if (array_filter($exits) !== []) {
        throw new RuntimeException('A linked event operation failed unexpectedly.');
    }

    return array_map(fn ($worker) => trim(file_get_contents("$directory/$phase-$worker.result")), $workers);
}

foreach (['request', 'approval'] as $phase) {
    $results = compete($phase, $script, $release, $directory);
    sort($results);
    if ($results !== ['302', '409']) {
        throw new RuntimeException("$phase should commit once and reject the competing request.");
    }
}
$event = CommunityEvent::where('thread_id', SOURCE)->sole();
$notices = DB::table('notifications')->where('user_id', OWNER)->where('resource_id', $event->id)
    ->whereIn('event', ['event.pending', 'event.approved'])->orderBy('id')->pluck('event')->all();
if ($event->status !== 'approved' || $notices !== ['event.pending', 'event.approved']
    || DB::table('moderation_decisions')->where(['type' => 'event', 'resource_id' => $event->id])->count() !== 2) {
    throw new RuntimeException('Concurrent requests or decisions produced inconsistent history.');
}
echo "Concurrent requests and review decisions each committed once and rejected the duplicate.\n";

$edit = actionRequest(8503, 'POST', "/admin/community/events/$event->id", array_replace(metadata(), [
    'action' => 'edit', 'venue' => 'Rehearsal annex', 'reason' => 'Exercise event resubmission.',
    'version' => ModerationDecision::version('event', $event),
    'thread_version' => ModerationDecision::version('thread', $event->thread),
]));
$app->make(CommunityModerationController::class)->events($edit, $event->id);
[$author, $review] = compete('source', $script, $release, $directory);
if ($author !== '302' || ! in_array($review, ['302', '409'], true) || $event->fresh()->status !== 'pending'
    || CommunityThread::findOrFail(SOURCE)->status !== 'pending') {
    throw new RuntimeException('The source edit must leave the event pending, regardless of which write takes the lock first.');
}
echo "Competing source edit and event approval left both in review.\n";

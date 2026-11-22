<?php

use App\Http\Controllers\Admin\CommunityModerationController;
use App\Http\Controllers\OwnedDiscussionsController;
use App\Models\CommunityThread;
use App\Models\User;
use App\Support\ApprovalPolicy;
use App\Support\ModerationDecision;
use App\Support\NetworkUrl;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

const THREAD = 8502;
const OWNER = 8501;
const MODERATOR = 8503;
const EDIT = ['title' => 'Revised draft', 'body' => 'The owner revised the pending discussion.', 'category' => 'help'];

[$script, $release, $directory] = $argv;
require $release.'/vendor/autoload.php';
$app = require $release.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, 'Pass 06 discussion rehearsal failed: '.$error->getMessage().PHP_EOL);
    exit(1);
});

if (config('database.default') !== 'mariadb' || DB::getDatabaseName() !== 'canadamb_ci_pass06'
    || config('database.connections.mariadb.host') !== '127.0.0.1') {
    throw new RuntimeException('The rehearsal requires the isolated Pass 06 MariaDB database.');
}

if (isset($argv[3], $argv[4])) {
    $worker = $argv[3];
    $author = $worker === 'author';
    $user = User::findOrFail($author ? OWNER : MODERATOR);
    $threadPath = $author ? '/account/discussions/' : '/admin/community/threads/';
    $data = $author ? EDIT : ['action' => 'approve'];
    $request = Request::create('https://canadamb.ca'.$threadPath.THREAD, $author ? 'PUT' : 'POST', $data + ['version' => $argv[4]]);
    $request->setUserResolver(fn () => $user);
    $request->attributes->set('site', NetworkUrl::main());

    DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
    DB::connection()->beforeExecuting(function (string $sql) use ($directory, $worker): void {
        if (str_contains($sql, '`community_threads`') && stripos($sql, 'for update') !== false) {
            touch("$directory/$worker.ready");
        }
    });

    try {
        $response = $author
            ? $app->make(OwnedDiscussionsController::class)->update($request, THREAD, $app->make(ApprovalPolicy::class))
            : $app->make(CommunityModerationController::class)->threads($request, THREAD);
        echo $response->getStatusCode();
    } catch (HttpExceptionInterface $error) {
        echo $error->getStatusCode();
    }
    exit;
}

$workers = ['author', 'moderator'];
$processes = [];
$auditFloor = DB::table('audit_logs')->max('id');
DB::beginTransaction();
try {
    // Hold the row until both normal source operations attempt their locks.
    $initial = CommunityThread::lockForUpdate()->findOrFail(THREAD);
    $version = ModerationDecision::version('thread', $initial);
    foreach ($workers as $worker) {
        $process = proc_open([PHP_BINARY, $script, $release, $directory, $worker, $version], [
            0 => ['pipe', 'r'], 1 => ['file', "$directory/$worker.result", 'w'], 2 => STDERR,
        ], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException("Could not start the $worker worker.");
        }
        fclose($pipes[0]);
        $processes[] = $process;
    }

    $deadline = hrtime(true) + 12_000_000_000;
    while (true) {
        clearstatcache();
        if (is_file("$directory/author.ready") && is_file("$directory/moderator.ready")) {
            break;
        }
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Both workers did not reach the discussion row lock.');
        }
        usleep(20_000);
    }
} finally {
    DB::rollBack();
    $exits = array_map(proc_close(...), $processes);
}
if (array_filter($exits) !== []) {
    throw new RuntimeException('A discussion worker exited unexpectedly.');
}

$results = [];
foreach ($workers as $worker) {
    $results[$worker] = trim(file_get_contents("$directory/$worker.result"));
}
asort($results);
if (array_values($results) !== ['302', '409']) {
    throw new RuntimeException('Expected one discussion change and one stale conflict, received '.json_encode($results, JSON_THROW_ON_ERROR).'.');
}

$winner = array_key_first($results);
$authorEdited = $winner === 'author';
$actor = $authorEdited ? OWNER : MODERATOR;
$action = $authorEdited ? 'author-edit' : 'approve';
$thread = $initial->fresh();
$audit = DB::table('audit_logs')->where('id', '>', $auditFloor)->sole();
$context = json_decode($audit->context, true, flags: JSON_THROW_ON_ERROR);
$decision = DB::table('moderation_decisions')->where(['type' => 'thread', 'resource_id' => THREAD])->sole();
$notices = DB::table('notifications')->where('resource_id', THREAD)->where('event', 'like', 'thread.%')->get();

if ($thread->title !== ($authorEdited ? EDIT['title'] : $initial->title)
    || $thread->body !== ($authorEdited ? EDIT['body'] : $initial->body)
    || $thread->category !== ($authorEdited ? EDIT['category'] : $initial->category)
    || $thread->status !== ($authorEdited ? 'pending' : 'approved')
    || $thread->author_id !== OWNER || $thread->location_id !== $initial->location_id
    || $thread->pinned !== $initial->pinned || $thread->locked !== $initial->locked
    || $audit->actor_id !== $actor || $audit->action !== 'community.thread.'.$action || ($context['thread'] ?? null) !== THREAD
    || $decision->audit_id !== $audit->id || $decision->action !== $action
    || ModerationDecision::version('thread', $thread) === $version) {
    throw new RuntimeException('The winning discussion operation must persist its status, text, revision, audit and decision exactly once.');
}

if ($authorEdited) {
    if ($notices->isNotEmpty() || $decision->notice_source_id !== null) {
        throw new RuntimeException('Editing an already pending draft must not send a moderation notice.');
    }
} else {
    $notice = $notices->sole();
    $locations = DB::table('notification_locations')->where('notification_id', $notice->id)->pluck('location_id')->all();
    if ($notice->user_id !== OWNER || $notice->event !== 'thread.approved' || $notice->read_at !== null
        || $notice->source_id !== $audit->id || $decision->notice_source_id !== $audit->id || $locations !== [$thread->location_id]) {
        throw new RuntimeException('Approval must create exactly one unread and locally scoped owner notice.');
    }
}

echo "Simultaneous author edit and admin approval committed one $winner decision. The other received HTTP 409.\n";

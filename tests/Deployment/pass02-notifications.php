<?php

use App\Http\Controllers\Admin\CommunityModerationController;
use App\Models\CommunityThread;
use App\Models\Site;
use App\Models\User;
use App\Support\ModerationDecision;
use App\Support\NotificationFeed;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

[$script, $release, $directory] = $argv;
require $release.'/vendor/autoload.php';
$app = require $release.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, 'Pass 02 notification rehearsal failed: '.$error->getMessage().PHP_EOL);
    exit(1);
});

if (DB::getDatabaseName() !== 'canadamb_ci_pass02' || config('database.connections.mariadb.host') !== '127.0.0.1') {
    throw new RuntimeException('The rehearsal requires the isolated Pass 02 MariaDB database.');
}

if (isset($argv[3], $argv[4])) {
    $editor = User::findOrFail((int) $argv[3]);
    $reply = (int) $argv[4];
    $request = Request::create("https://canadamb.ca/admin/community/replies/$reply", 'POST', [
        'action' => 'approve', 'version' => $argv[5],
    ]);
    $request->setUserResolver(fn () => $editor);
    DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
    DB::connection()->beforeExecuting(function (string $sql) use ($directory, $editor): void {
        if (str_contains($sql, 'community_threads') && str_contains($sql, 'for update')) {
            touch("$directory/$editor->id.ready");
        }
    });
    try {
        echo $app->make(CommunityModerationController::class)->replies($request, $reply)->getStatusCode();
    } catch (HttpException $error) {
        echo $error->getStatusCode();
    }
    exit;
}

$site = Site::where('code', 'brandon')->firstOrFail();
$feed = $app->make(NotificationFeed::class);
$historical = $feed->rows($site, 8101)->where('storage', 'legacy')->orderBy('id')->get();
if ($historical->pluck('id')->all() !== [8101, 8102]
    || $historical[0]->read_at !== '2026-01-01 09:30:00' || $historical[1]->read_at !== null
    || $feed->unread($site, 8101) !== 1) {
    throw new RuntimeException('Upgraded inbox did not read the original notices and timestamps.');
}

$thread = CommunityThread::findOrFail(8101);
$reply = $thread->replies()->create(['author_id' => 8102, 'body' => 'Review race fixture.']);
$version = class_exists(ModerationDecision::class) ? ModerationDecision::version('reply', $reply->fresh()) : '';
DB::table('community_thread_follows')->insert(['thread_id' => $thread->id, 'user_id' => 8101]);
$editors = [];
foreach (['first', 'second'] as $name) {
    $editors[] = User::forceCreate([
        'email' => "$name-reviewer@example.invalid", 'name' => 'Rehearsal editor',
        'password' => bin2hex(random_bytes(24)), 'role' => 'network_admin', 'email_verified_at' => now(),
    ])->id;
}

$processes = [];
DB::beginTransaction();
try {
    // Hold the parent row so both independent connections reach the same lock before either decision.
    CommunityThread::lockForUpdate()->findOrFail($thread->id);
    foreach ($editors as $editor) {
        $process = proc_open([PHP_BINARY, $script, $release, $directory, (string) $editor, (string) $reply->id, $version], [
            0 => ['pipe', 'r'], 1 => ['file', "$directory/$editor.result", 'w'], 2 => STDERR,
        ], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start rehearsal editor.');
        }
        fclose($pipes[0]);
        $processes[] = $process;
    }
    $deadline = hrtime(true) + 12_000_000_000;
    do {
        clearstatcache();
        if (is_file("$directory/$editors[0].ready") && is_file("$directory/$editors[1].ready")) {
            break;
        }
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Both editors did not reach the moderation lock.');
        }
        usleep(20_000);
    } while (true);
} finally {
    DB::rollBack();
    foreach ($processes as $process) {
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Rehearsal editor exited unexpectedly.');
        }
    }
}

$statuses = array_map(fn (int $editor) => trim(file_get_contents("$directory/$editor.result")), $editors);
sort($statuses);
$notice = DB::table('notifications')->where('resource_id', $reply->id);
$recipients = $notice->orderBy('user_id')->pluck('event', 'user_id')->all();
if ($statuses !== ['302', '409'] || $reply->fresh()->status !== 'approved'
    || $recipients !== [8101 => 'reply.followed', 8102 => 'reply.approved'] || $notice->count() !== 2
    || DB::table('notification_locations')->where('location_id', $site->location_id)->count() !== 2) {
    throw new RuntimeException('Concurrent review should persist one decision and one notice for each intended recipient.');
}

echo "Upgraded inbox preserved legacy read state. Concurrent editors persisted exactly one review.\n";

<?php

use App\Http\Controllers\MessagesController;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Site;
use App\Models\User;
use App\Support\Marketplace;
use App\Support\NotificationFeed;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

const LISTING_ID = 8402;
const BUYER_ID = 8401;
const SELLER_ID = 8402;
const SEND_BODY = 'A simultaneous Marketplace send.';
const BRANCH_SLUG = 'rehearsal-branch';

function sendKey(string $scenario): string
{
    return Uuid::uuid5(Uuid::NAMESPACE_URL, 'canadamb-rehearsal/'.$scenario)->toString();
}

[$script, $release, $directory] = $argv;
require $release.'/vendor/autoload.php';
$app = require $release.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, 'Pass 04 messaging rehearsal failed: '.$error->getMessage().PHP_EOL);
    exit(1);
});

if (config('database.default') !== 'mariadb' || DB::getDatabaseName() !== 'canadamb_ci_pass04'
    || config('database.connections.mariadb.host') !== '127.0.0.1') {
    throw new RuntimeException('The rehearsal requires the isolated Pass 04 MariaDB database.');
}

$site = Site::where('code', 'brandon')->firstOrFail();
if (isset($argv[3])) {
    if ($argv[3] === 'revoke') {
        $branch = (int) $argv[4];
        $buyer = User::findOrFail(BUYER_ID);
        $request = Request::create('https://brandonmb.ca/businesses/'.BRANCH_SLUG."/branches/$branch/message", 'POST', [
            'body' => 'A contact request during reassignment.', 'idempotency_key' => sendKey('role-revocation'),
        ]);
        $request->attributes->set('site', $site);
        $request->setUserResolver(fn () => $buyer);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
        DB::connection()->beforeExecuting(function (string $sql) use ($directory): void {
            if (str_contains($sql, '`business_memberships`') && str_contains($sql, 'for update')) {
                touch("$directory/revoke.ready");
            }
        });
        try {
            echo $app->make(MessagesController::class)->startBusiness($request, BRANCH_SLUG, $branch)->getStatusCode();
        } catch (HttpExceptionInterface $error) {
            echo $error->getStatusCode();
        }
        exit;
    }

    $worker = (int) $argv[3];
    $buyer = User::findOrFail(BUYER_ID);
    $request = Request::create('https://brandonmb.ca/marketplace/'.LISTING_ID.'/message', 'POST', [
        'body' => SEND_BODY, 'idempotency_key' => sendKey('simultaneous-send'),
    ]);
    $request->attributes->set('site', $site);
    $request->setUserResolver(fn () => $buyer);
    DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
    DB::connection()->beforeExecuting(function (string $sql) use ($directory, $worker): void {
        if (str_contains($sql, '`listings`') && str_contains($sql, 'for update')) {
            touch("$directory/$worker.ready");
        }
    });
    $response = $app->make(MessagesController::class)->start($request, LISTING_ID, $app->make(Marketplace::class));
    echo $response->getStatusCode().' '.parse_url($response->getTargetUrl(), PHP_URL_PATH);
    exit;
}

$feed = $app->make(NotificationFeed::class);
$read = $feed->rows($site, SELLER_ID, 'messages')->where('id', 8401)->first();
$unread = $feed->rows($site, BUYER_ID, 'messages')->where('id', 8402)->first();
if ($read?->read_at !== '2026-01-01 09:15:00' || $unread === null || $unread->read_at !== null
    || $feed->unread($site, SELLER_ID) !== 0 || $feed->unread($site, BUYER_ID) !== 1
    || $feed->destination($unread) !== '/account/messages/8401') {
    throw new RuntimeException('The upgraded inbox did not preserve legacy Marketplace destination or read state.');
}

$processes = [];
DB::beginTransaction();
try {
    // Keep both workers waiting on the same listing lock before releasing either request.
    Listing::lockForUpdate()->findOrFail(LISTING_ID);
    foreach ([1, 2] as $worker) {
        $process = proc_open([PHP_BINARY, $script, $release, $directory, (string) $worker], [
            0 => ['pipe', 'r'], 1 => ['file', "$directory/$worker.result", 'w'], 2 => STDERR,
        ], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start a send worker.');
        }
        fclose($pipes[0]);
        $processes[] = $process;
    }
    $deadline = hrtime(true) + 12_000_000_000;
    while (true) {
        clearstatcache();
        if (is_file("$directory/1.ready") && is_file("$directory/2.ready")) {
            break;
        }
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Both send workers did not reach the listing lock.');
        }
        usleep(20_000);
    }
} finally {
    DB::rollBack();
    $statuses = array_map(proc_close(...), $processes);
    if (count(array_filter($statuses)) > 0) {
        throw new RuntimeException('A rehearsal send worker exited unexpectedly.');
    }
}

$threads = Conversation::where(['listing_id' => LISTING_ID, 'buyer_id' => BUYER_ID])->get();
if ($threads->count() !== 1) {
    throw new RuntimeException('The same sender and listing must produce one thread.');
}
$thread = $threads->sole();
$messages = $thread->messages()->get();
if ($messages->count() !== 1) {
    throw new RuntimeException('The same sender and token must produce one message.');
}
$message = $messages->sole();
$result = '302 /account/messages/'.$thread->id;
$notice = DB::table('notifications')->where(['event' => 'message.received', 'source_id' => $message->id]);
if ($thread->context_type !== 'marketplace' || $thread->seller_id !== SELLER_ID || $thread->buyer_last_read_id !== 0
    || $message->sender_id !== BUYER_ID || $message->body !== SEND_BODY || $message->idempotency_key !== sendKey('simultaneous-send')
    || trim(file_get_contents("$directory/1.result")) !== $result || trim(file_get_contents("$directory/2.result")) !== $result
    || $notice->count() !== 1 || DB::table('notification_locations')->where('notification_id', $notice->value('id'))->pluck('location_id')->all() !== [$site->location_id]
    || ! $notice->where(['user_id' => SELLER_ID, 'resource_id' => $message->id])->whereNull('read_at')->exists()) {
    throw new RuntimeException('Concurrent requests should redirect to the same thread and create one scoped recipient notice without changing legacy read markers.');
}

echo "The upgraded inbox preserved legacy read states. Concurrent sends persisted one thread, one message, and one scoped notice.\n";

$organization = Business::create(['slug' => BRANCH_SLUG, 'name' => 'Rehearsal Branch', 'category' => 'retail', 'status' => 'active']);
$organization->members()->attach(SELLER_ID, ['role' => 'manager']);
$branch = $organization->locations()->create(['location_id' => $site->location_id, 'address' => '1 Rehearsal Avenue']);
$branch->forceFill(['message_contact_user_id' => SELLER_ID])->save();
$worker = false;
DB::beginTransaction();
try {
    // The worker can read the previous membership until the revocation commits.
    $organization->members()->detach(SELLER_ID);
    $worker = proc_open([PHP_BINARY, $script, $release, $directory, 'revoke', (string) $branch->id], [
        0 => ['pipe', 'r'], 1 => ['file', "$directory/revoke.result", 'w'], 2 => STDERR,
    ], $pipes);
    if (! is_resource($worker)) {
        throw new RuntimeException('Could not start the organization send worker.');
    }
    fclose($pipes[0]);
    $deadline = hrtime(true) + 12_000_000_000;
    while (! is_file("$directory/revoke.ready")) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('The send worker did not reach the membership lock.');
        }
        usleep(20_000);
    }
    DB::commit();
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if (is_resource($worker) && proc_close($worker) !== 0) {
        throw new RuntimeException('The organization send worker exited unexpectedly.');
    }
}
if (trim(file_get_contents("$directory/revoke.result")) !== '404'
    || Conversation::where('business_location_id', $branch->id)->exists()) {
    throw new RuntimeException('The send worker should observe the committed role revocation before persisting a thread.');
}
echo "A concurrent role revocation prevented the organization send.\n";

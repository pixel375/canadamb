<?php

/**
 * Disposable Chromium QA data. This script runs only in the isolated GitHub
 * Actions SQLite application; no fixture user or authentication shortcut is
 * published with the website.
 */

use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local') || ! config('network.development_hosts') || config('database.default') !== 'sqlite') {
    fwrite(STDERR, "Refusing to seed browser fixtures outside isolated local SQLite QA.\n");
    exit(1);
}

$member = User::factory()->create([
    'name' => 'Browser QA Member',
    'email' => 'browser-notifications@example.test',
    'password' => 'browser-qa-password-only',
    'email_verified_at' => now(),
]);
$author = User::factory()->create(['name' => 'Community QA Volunteer']);
$brandon = Location::where('slug', 'brandon')->sole();

$thread = CommunityThread::create([
    'author_id' => $author->id,
    'location_id' => $brandon->id,
    'title' => 'Community garden neighbours',
    'category' => 'general',
    'body' => 'Planning a community garden and neighbour volunteer meetup.',
]);
$thread->forceFill(['status' => 'approved', 'last_activity_at' => now()])->save();

DB::table('community_thread_follows')->insert([
    'thread_id' => $thread->id, 'user_id' => $member->id, 'created_at' => now(),
]);

foreach ([
    ['Welcome to the new community garden project.', null],
    ['Bring your favourite gardening ideas to the local event.', now()->subDay()],
] as [$body, $readAt]) {
    $reply = $thread->replies()->create(['author_id' => $author->id, 'body' => $body]);
    $reply->forceFill(['status' => 'approved'])->save();
    DB::table('community_notifications')->insert([
        'user_id' => $member->id,
        'reply_id' => $reply->id,
        'read_at' => $readAt,
        'created_at' => now(),
    ]);
}

// Pending replies must remain invisible even if a notification record exists.
$pending = $thread->replies()->create([
    'author_id' => $author->id,
    'body' => 'Private unpublished reply that browser QA must never show.',
]);
DB::table('community_notifications')->insert([
    'user_id' => $member->id,
    'reply_id' => $pending->id,
    'created_at' => now(),
]);

echo "Disposable authenticated notification fixtures seeded.\n";

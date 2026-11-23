<?php

use App\Models\CommunityEvent;
use App\Models\CommunityThread;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$repo = dirname(__DIR__, 2);
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->isLocal() || config('database.default') !== 'sqlite' || ! config('network.development_hosts')
    || parse_url(config('app.url'), PHP_URL_HOST) !== 'canadamb.localhost' || User::exists()) {
    throw new RuntimeException('Use a fresh disposable local SQLite database for event browser fixtures.');
}
$author = User::factory()->create(['name' => 'Event Author', 'email' => 'event-author@example.test']);
User::factory()->create(['name' => 'Event Reviewer', 'email' => 'event-reviewer@example.test', 'role' => 'network_admin']);
$brandon = Location::where('slug', 'brandon')->sole()->id;
$winnipeg = Location::where('slug', 'winnipeg')->sole()->id;
foreach (['Brandon heritage workshop' => $brandon, 'Winnipeg heritage workshop' => $winnipeg] as $title => $location) {
    CommunityThread::forceCreate(['title' => $title, 'body' => 'The workshop details and discussion belong together.',
        'author_id' => $author->id, 'location_id' => $location, 'category' => 'general', 'status' => 'approved']);
}
$legacy = now('America/Winnipeg')->addDays(2)->setTime(17, 0)->utc();
CommunityEvent::forceCreate(['author_id' => $author->id, 'location_id' => $brandon,
    'title' => 'Legacy archive tour', 'body' => 'The standalone archive tour keeps its original page.',
    'category' => 'community', 'venue' => 'Archive building', 'organizer' => 'Archive staff',
    'starts_at' => $legacy, 'ends_at' => $legacy->copy()->addHour(), 'status' => 'approved']);

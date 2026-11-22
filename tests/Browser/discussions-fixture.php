<?php

use App\Models\CommunityThread;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$repo = dirname(__DIR__, 2);
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->isLocal() || config('database.default') !== 'sqlite' || ! config('network.development_hosts')
    || parse_url(config('app.url'), PHP_URL_HOST) !== 'canadamb.localhost' || User::exists()) {
    fwrite(STDERR, "Use a fresh disposable SQLite database for discussion browser fixtures.\n");
    exit(1);
}

$author = User::factory()->create(['name' => 'Discussion Author', 'email' => 'discussions-author@example.test']);
User::factory()->create(['name' => 'Discussion Reader', 'email' => 'discussions-reader@example.test']);
User::factory()->create(['name' => 'Discussion Reviewer', 'email' => 'discussions-reviewer@example.test', 'role' => 'network_admin']);
CommunityThread::forceCreate([
    'title' => 'Province-wide trail notice', 'body' => 'Planning information for the province.',
    'author_id' => $author->id, 'category' => 'general', 'status' => 'approved', 'last_activity_at' => now(),
]);

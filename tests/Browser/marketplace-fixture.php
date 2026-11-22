<?php

use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$repo = dirname(__DIR__, 2);
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->isLocal() || config('database.default') !== 'sqlite' || ! config('network.development_hosts')
    || ! in_array(parse_url(config('app.url'), PHP_URL_HOST), ['canadamb.localhost', 'localhost'], true)
    || User::exists() || Listing::exists()) {
    fwrite(STDERR, "Use a fresh, seeded, disposable local SQLite database on the development hosts.\n");
    exit(1);
}

$owner = User::factory()->create(['name' => 'Marketplace Browser QA', 'email' => 'browser-qa@example.test', 'role' => 'super_admin']);
User::factory()->create(['name' => 'Marketplace Browser Buyer', 'email' => 'browser-buyer@example.test']);
foreach (['brandon' => ['expired', 'Marketplace browser fixture expired'], 'elkhorn' => ['active', 'Marketplace browser fixture rural']] as $place => [$state, $title]) {
    Listing::forceCreate([
        'user_id' => $owner->id, 'title' => $title, 'description' => 'Disposable Marketplace browser fixture.',
        'location_id' => Location::where('slug', $place)->sole()->id, 'category' => 'furniture', 'condition' => 'good',
        'price_cents' => 1000, 'status' => $state, 'published_at' => now()->subMonth(),
        'expires_at' => $state === 'expired' ? now()->subHour() : now()->addWeek(),
    ]);
}

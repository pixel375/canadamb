<?php

use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$repo = dirname(__DIR__, 2);
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->isLocal() || config('database.default') !== 'sqlite'
    || ! config('network.development_hosts')
    || parse_url(config('app.url'), PHP_URL_HOST) !== 'canadamb.localhost'
    || User::exists() || Listing::exists()) {
    fwrite(STDERR, "Use only a newly migrated, seeded, disposable local SQLite database.\n");
    exit(1);
}

$viewer = User::factory()->create([
    'name' => 'Navigation Browser Member',
    'email' => 'navigation-browser@example.test',
]);
$seller = User::factory()->create([
    'name' => 'Navigation Browser Seller',
    'email' => 'navigation-seller@example.test',
]);
$listing = Listing::forceCreate([
    'user_id' => $seller->id,
    'location_id' => Location::where('slug', 'brandon')->sole()->id,
    'category' => 'furniture',
    'title' => 'Navigation screenshot fixture table',
    'description' => 'A disposable non-public browser fixture.',
    'condition' => 'good',
    'price_cents' => 1300,
    'status' => 'active',
    'published_at' => now(),
    'expires_at' => now()->addWeek(),
]);
$conversation = Conversation::create([
    'listing_id' => $listing->id, 'buyer_id' => $viewer->id, 'seller_id' => $seller->id,
]);
$conversation->messages()->create(['sender_id' => $seller->id, 'body' => 'Private fixture message to verify inbox placement.']);

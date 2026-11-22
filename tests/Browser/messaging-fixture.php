<?php

use App\Models\Business;
use App\Models\JobPosting;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$repo = dirname(__DIR__, 2);
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->isLocal() || config('database.default') !== 'sqlite' || ! config('network.development_hosts')
    || parse_url(config('app.url'), PHP_URL_HOST) !== 'canadamb.localhost' || User::exists()) {
    fwrite(STDERR, "Use a fresh disposable SQLite database for the local messaging fixtures.\n");
    exit(1);
}

User::factory()->create(['name' => 'Browser Buyer', 'email' => 'buyer-messages@example.test']);
$seller = User::factory()->create(['name' => 'Browser Seller', 'email' => 'seller-messages@example.test']);
$staff = User::factory()->create(['name' => 'Browser Employer', 'email' => 'employer-messages@example.test']);
User::factory()->create(['name' => 'Browser Admin', 'email' => 'admin-messages@example.test', 'role' => 'super_admin']);
$brandon = Location::where('slug', 'brandon')->sole();
Listing::forceCreate([
    'user_id' => $seller->id, 'location_id' => $brandon->id, 'category' => 'furniture', 'title' => 'Browser Desk',
    'description' => 'A disposable fixture for private messages.', 'price_cents' => 5500, 'condition' => 'good',
    'status' => 'active', 'published_at' => now(), 'expires_at' => now()->addWeek(),
]);
$business = Business::create(['slug' => 'browser-store', 'name' => 'Browser Store', 'category' => 'retail', 'status' => 'active']);
$business->members()->attach($staff->id, ['role' => 'owner']);
$branch = $business->locations()->create(['location_id' => $brandon->id, 'address' => '1 Browser Street']);
$branch->forceFill(['message_contact_user_id' => $staff->id])->save();
JobPosting::forceCreate([
    'business_location_id' => $branch->id, 'author_id' => $staff->id, 'title' => 'Browser Sales Assistant',
    'description' => 'A disposable jobs fixture.', 'employment_type' => 'full-time', 'workplace' => 'on-site',
    'apply_url' => 'https://example.test/jobs', 'closing_on' => now()->addWeek()->toDateString(),
    'status' => 'approved', 'published_at' => now(), 'message_contact_user_id' => $staff->id,
]);

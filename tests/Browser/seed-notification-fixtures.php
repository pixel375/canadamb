<?php

/**
 * Disposable Chromium QA data. This script runs only in the isolated GitHub
 * Actions SQLite application; no fixture user or authentication shortcut is
 * published with the website.
 */

use App\Models\Business;
use App\Models\CommunityThread;
use App\Models\JobPosting;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use App\Support\ListingPhotos;
use App\Support\Marketplace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
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

User::factory()->create([
    'name' => 'Browser QA Administrator',
    'email' => 'browser-admin@example.test',
    'password' => 'browser-qa-admin-password-only',
    'email_verified_at' => now(),
    'role' => 'super_admin',
]);

CommunityThread::create([
    'author_id' => $member->id,
    'location_id' => $brandon->id,
    'title' => 'Pending local garden discussion for moderation',
    'category' => 'general',
    'body' => 'A pending discussion visible only to its author and moderators.',
]);

$listing = Listing::create([
    'user_id' => $author->id, 'location_id' => $brandon->id,
    'title' => 'Browser QA oak bookshelf', 'description' => 'A solid oak bookshelf for testing saved listings and messaging.',
    'category' => 'furniture', 'condition' => 'good', 'price_cents' => 4500, 'status' => 'pending',
]);
app(Marketplace::class)->changeStatus($listing, 'active', null);
DB::table('listing_favourites')->insert(['user_id' => $member->id, 'listing_id' => $listing->id]);
$conversation = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $member->id, 'seller_id' => $author->id]);
$conversation->messages()->create(['sender_id' => $author->id, 'body' => 'The oak bookshelf is still available.']);

$ownedListing = Listing::create([
    'user_id' => $member->id, 'location_id' => $brandon->id,
    'title' => 'Browser QA private photo editor', 'description' => 'A private listing with two existing photos.',
    'category' => 'furniture', 'condition' => 'good', 'price_cents' => 3500, 'status' => 'pending',
]);
$ownedListing->media()->createMany(app(ListingPhotos::class)->store([
    UploadedFile::fake()->image('first.jpg', 200, 200),
    UploadedFile::fake()->image('second.jpg', 200, 200),
]));

$business = Business::create(['slug' => 'browser-qa-team', 'name' => 'Browser QA Team', 'category' => 'services', 'status' => 'active']);
$business->members()->attach($member->id, ['role' => 'owner']);
$branch = $business->locations()->create(['location_id' => $brandon->id, 'address' => '1 QA Street']);
JobPosting::create([
    'business_location_id' => $branch->id, 'author_id' => $member->id,
    'title' => 'Browser QA technician', 'description' => 'A sample technician role for the browser suite.',
    'employment_type' => 'full-time', 'workplace' => 'on-site', 'apply_url' => 'https://example.test/careers',
    'closing_on' => today()->addWeek()->toDateString(),
]);
User::factory()->create(['name' => 'Browser QA Teammate', 'email' => 'team-qa@example.test']);

echo "Disposable authenticated notification, marketplace, messaging and administrator fixtures seeded.\n";

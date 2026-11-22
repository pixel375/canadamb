<?php

use App\Models\CommunityThread;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$repo = dirname(__DIR__, 2);
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->isLocal() || config('database.default') !== 'sqlite' || ! config('network.development_hosts')
    || parse_url(config('app.url'), PHP_URL_HOST) !== 'canadamb.localhost' || User::exists()) {
    fwrite(STDERR, "Use a fresh disposable SQLite database for moderation browser fixtures.\n");
    exit(1);
}

$author = User::factory()->create(['name' => 'Browser Author']);
$member = User::factory()->create(['email' => 'moderation-member@example.test']);
User::factory()->create(['email' => 'moderation-reviewer@example.test', 'role' => 'super_admin']);
$local = Location::where('slug', 'brandon')->sole()->id;
$rural = Location::where('slug', 'elkhorn')->sole()->id;
foreach (['Park opening request' => $local, 'First trail proposal' => $rural, 'Second trail proposal' => $rural] as $title => $location) {
    CommunityThread::create(['title' => $title, 'author_id' => $author->id, 'location_id' => $location,
        'category' => 'general', 'body' => 'A proposed improvement for the community.']);
}
$public = CommunityThread::create(['title' => 'Public trail notice', 'author_id' => $author->id,
    'location_id' => $rural, 'category' => 'general', 'body' => 'Community trail information.']);
$public->forceFill(['status' => 'approved', 'last_activity_at' => now()])->save();
$listing = Listing::forceCreate(['user_id' => $author->id, 'location_id' => $local, 'category' => 'furniture',
    'title' => 'Browser desk', 'description' => 'A disposable fixture for report review.', 'price_cents' => 3500,
    'condition' => 'good', 'status' => 'active', 'published_at' => now(), 'expires_at' => now()->addWeek()]);
$conversation = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $member->id, 'seller_id' => $author->id]);
$conversation->messages()->create(['sender_id' => $author->id, 'body' => 'This unrelated private message stays hidden.']);
$message = $conversation->messages()->create(['sender_id' => $author->id, 'body' => 'This is the reported message.']);
DB::table('conversation_reports')->insert([
    'conversation_id' => $conversation->id, 'reporter_id' => $member->id,
    'message_id' => $message->id, 'reason' => 'Review this reported message.', 'created_at' => now(),
]);

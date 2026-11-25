<?php

use App\Models\JobPosting;
use App\Models\Listing;
use App\Models\Site;
use App\Models\User;
use App\Support\Audit;
use App\Support\Identity;
use App\Support\JobBoard;
use App\Support\Marketplace;
use App\Support\MessageFileInventory;
use App\Support\PropertyMediaInventory;
use App\Support\StagedFeatures;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('network:sync-sso', function () {
    Site::whereNotNull('location_id')->where('status', 'active')->each(function (Site $site) {
        Identity::provision($site);
        $this->info('OAuth redirect registered for '.$site->domain->host);
    });
})->purpose('Register the exact OAuth callback for each active community domain');

Artisan::command('network:admin {email}', function (string $email) {
    $email = strtolower($email);
    $password = $this->secret('Password (at least 12 characters)');
    $repeat = $this->secret('Repeat password');
    $validator = Validator::make(['email' => $email, 'password' => $password, 'password_confirmation' => $repeat], [
        'email' => 'required|email|max:254|unique:users,email',
        'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(12)],
    ]);
    if ($validator->fails()) {
        $this->error($validator->errors()->first());

        return 1;
    }
    DB::transaction(function () use ($email, $password) {
        $user = User::create(['name' => 'Network administrator', 'email' => $email, 'password' => $password]);
        $user->forceFill(['email_verified_at' => now(), 'role' => 'super_admin'])->save();
        Audit::record('network.admin-created', $user->id);
    });
    $this->info('Administrator created.');
})->purpose('Create a network administrator interactively');

Artisan::command('marketplace:expire', function (Marketplace $marketplace) {
    Listing::where('status', 'active')->where('expires_at', '<=', now())->eachById(function (Listing $item) use ($marketplace) {
        DB::transaction(function () use ($item, $marketplace) {
            $listing = Listing::lockForUpdate()->find($item->id);
            if ($listing && $listing->status === 'active' && $listing->expires_at <= now()) {
                $marketplace->changeStatus($listing, 'expired', null);
            }
        });
    });
})->purpose('Expire published listings and preserve status history');

Schedule::command('marketplace:expire')->hourly()->withoutOverlapping(5);
Artisan::command('job-postings:expire', function (JobBoard $board) {
    $due = JobPosting::whereIn('status', ['pending', 'approved'])->where('closing_on', '<', $board->closingToday());
    $ids = (clone $due)->oldest('id')->limit(200)->pluck('id');
    $count = $due->whereIn('id', $ids)->update(['status' => 'expired']);
    $this->info("Expired $count job posting(s).");
})->purpose('Expire a batch of job postings after their Manitoba closing date');
Schedule::command('job-postings:expire')->hourly()->withoutOverlapping(5);
Artisan::command('network:staged-features', function () {
    $features = StagedFeatures::inspect();
    $this->line(json_encode($features, JSON_THROW_ON_ERROR));

    return collect($features)->contains(fn ($feature) => $feature['configured'] && ! $feature['ready']) ? 1 : 0;
})->purpose('Read-only inventory of proposed migrations, schema and activation switches');

Artisan::command('messages:attachment-inventory {--limit=500}', function (MessageFileInventory $inventory) {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
    if ($limit === false) {
        $this->error('Choose a limit between 1 and 5000.');

        return 1;
    }
    $this->line(json_encode($inventory->inspect($limit), JSON_THROW_ON_ERROR));
})->purpose('Read-only bounded private attachment inventory; never delete or print file paths');

Artisan::command('media:inventory {--limit=500}', function (PropertyMediaInventory $inventory) {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
    if ($limit === false) {
        $this->error('Choose a limit between 1 and 5000.');

        return 1;
    }
    $this->line(json_encode($inventory->inspect($limit), JSON_THROW_ON_ERROR));
})->purpose('Read-only bounded Marketplace and private property photo inventory');

Schedule::command('passport:purge --hours=24')->daily()->withoutOverlapping(10);
if (config('network.news_polling') && ! config('network.prelaunch')) {
    Schedule::command('news:poll')->everyFiveMinutes()->withoutOverlapping(2);
}
if (config('network.cron_queue_drain')) {
    Schedule::command('queue:work database --stop-when-empty --max-jobs=25 --max-time=45 --timeout=30 --tries=3')
        ->everyMinute()->withoutOverlapping(3);
}

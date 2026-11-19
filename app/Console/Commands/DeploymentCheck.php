<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\NetworkSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeploymentCheck extends Command
{
    protected $signature = 'network:deployment-check
        {--hostinger : Require the Hostinger production configuration}
        {--database : Check the installed database state after migration}';

    protected $description = 'Validate deployment prerequisites without printing environment secrets';

    public function handle(): int
    {
        $issues = [];
        if (! function_exists('imagewebp')) {
            $issues[] = 'PHP GD with WebP encoding is required for Marketplace photos.';
        }
        $manifestPath = public_path('build/manifest.json');
        $manifest = is_readable($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
        foreach (['resources/css/app.css', 'resources/js/app.js'] as $entry) {
            $path = $manifest[$entry]['file'] ?? '';
            if (! preg_match('/^assets\/[^\/]+$/D', $path) || ! is_file(public_path('build/'.$path))) {
                $issues[] = 'Missing compiled asset for '.$entry.'.';
            }
        }

        if (app()->isProduction() || $this->option('hostinger')) {
            if (! app()->isProduction() || config('app.debug') || ! config('app.key')
                || parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https') {
                $issues[] = 'Hostinger releases require APP_ENV=production, an application key, HTTPS APP_URL and disabled debug output.';
            }
            if (config('session.driver') !== 'database' || ! config('session.secure') || ! config('session.http_only')
                || config('session.same_site') !== 'lax' || config('cache.default') !== 'database'
                || config('queue.default') !== 'database' || config('database.default') !== 'mariadb') {
                $issues[] = 'Business Web Hosting requires MariaDB, database sessions/cache/queues and secure host-only browser cookies.';
            }
            if (! config('network.cron_queue_drain')) {
                $issues[] = 'Enable bounded cron queue processing for Business Web Hosting.';
            }
        }

        if ($this->option('database')) {
            DB::connection()->getPdo();
            app(NetworkSettings::class)->published();
            $main = Site::with('domain')->where('code', 'network')->firstOrFail();
            if ($main->domain->host !== parse_url(config('app.url'), PHP_URL_HOST)) {
                $issues[] = 'APP_URL must identify the central domain in the database.';
            }
            if (config('network.sso_enabled') && Site::whereNotNull('location_id')->where('status', 'active')
                ->whereDoesntHave('domain', fn ($domain) => $domain->whereNotNull('oauth_client_id'))->exists()) {
                $issues[] = 'An active community has no SSO redirect registration.';
            }
        }

        foreach ($issues as $issue) {
            $this->error($issue);
        }
        if ($issues !== []) {
            return self::FAILURE;
        }

        $this->info('CanadaMB deployment checks passed.');

        return self::SUCCESS;
    }
}

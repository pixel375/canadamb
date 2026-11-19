<?php

namespace App\Console\Commands;

use App\Models\CommunityOAuthClient;
use App\Models\Site;
use App\Support\NetworkSettings;
use App\Support\NetworkUrl;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeploymentCheck extends Command
{
    protected $signature = 'network:deployment-check
        {--hostinger : Require the Hostinger production configuration}
        {--staging-database= : Check the independently selected MariaDB staging schema and grants}
        {--database : Check the installed database state after migration}';

    protected $description = 'Validate deployment prerequisites without printing environment secrets';

    public function handle(): int
    {
        if ($this->option('staging-database') !== null && ! $this->checkStagingDatabase($this->option('staging-database'))) {
            return self::FAILURE;
        }

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
            $key = (string) config('app.key');
            if (str_starts_with($key, 'base64:')) {
                $key = base64_decode(substr($key, 7), true);
            }
            if (! app()->isProduction() || config('app.debug') || ! is_string($key) || ! Encrypter::supported($key, config('app.cipher'))
                || parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https') {
                $issues[] = 'Hostinger releases require APP_ENV=production, a valid application key, HTTPS APP_URL and disabled debug output.';
            }
            if (config('session.driver') !== 'database' || ! config('session.secure') || ! config('session.http_only')
                || ! config('session.encrypt') || config('session.domain')
                || config('session.same_site') !== 'lax' || config('cache.default') !== 'database'
                || config('queue.default') !== 'database' || config('database.default') !== 'mariadb') {
                $issues[] = 'Business Web Hosting requires MariaDB, database sessions/cache/queues and secure host-only browser cookies.';
            }
            if (! extension_loaded('pdo_mysql') || ! extension_loaded('curl')) {
                $issues[] = 'Enable the PDO MySQL and cURL PHP extensions.';
            }
            if (! config('network.cron_queue_drain')) {
                $issues[] = 'Enable bounded cron queue processing for Business Web Hosting.';
            }
            if (config('queue.connections.database.retry_after') <= 60) {
                $issues[] = 'The database queue retry window must exceed the 60-second scheduler limit.';
            }
            if (config('network.staging') && (! config('network.sso_enabled') || config('network.news_polling'))) {
                $issues[] = 'Staging requires shared sign-in and disabled live feed scheduling.';
            }
        }

        if ($this->option('database')) {
            DB::connection()->getPdo();
            app(NetworkSettings::class)->published();
            $main = Site::with('domain')->where('code', 'network')->firstOrFail();
            if ($main->domain->host !== parse_url(config('app.url'), PHP_URL_HOST)) {
                $issues[] = 'APP_URL must identify the central domain in the database.';
            }
            if ($this->option('hostinger') && (config('app.url') !== 'https://canadamb.ca' || $main->status !== 'active'
                || ! Site::where('code', 'brandon')->where('status', 'active')
                    ->whereHas('domain', fn ($domain) => $domain->where('host', 'brandonmb.ca'))
                    ->whereHas('location', fn ($location) => $location->where('slug', 'brandon'))->exists())) {
                $issues[] = 'The CanadaMB and BrandonMB staging domains must be active with their intended location scopes.';
            }
            if (config('network.sso_enabled')) {
                foreach (Site::with('domain')->whereNotNull('location_id')->where('status', 'active')->get() as $site) {
                    $client = CommunityOAuthClient::find($site->domain?->oauth_client_id);
                    if (! $client || $client->revoked || $client->redirect_uris !== [NetworkUrl::to($site, '/auth/callback')]) {
                        $issues[] = 'An active community has no valid SSO redirect registration.';
                        break;
                    }
                }
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

    private function checkStagingDatabase(string $expected): bool
    {
        if (! config('network.staging') || config('database.default') !== 'mariadb' || ! preg_match('/^[a-z0-9_]+_staging$/iD', $expected)) {
            $this->error('Staging requires a staging-marked environment and an explicitly named MariaDB staging database.');

            return false;
        }

        try {
            if (DB::selectOne('SELECT DATABASE() AS name')?->name !== $expected) {
                $this->error('The database connection does not select the confirmed staging database.');

                return false;
            }

            $localGrant = '/^GRANT [A-Z ,]+ ON `'.preg_quote(str_replace('_', '\\_', $expected), '/').'`\.\* TO /i';
            $hasLocalGrant = false;
            foreach (DB::select('SHOW GRANTS') as $row) {
                $grant = (string) array_values((array) $row)[0];
                if (preg_match('/^GRANT USAGE ON \*\.\* TO /i', $grant) && ! str_contains(strtoupper($grant), 'WITH GRANT OPTION')) {
                    continue;
                }
                if (! preg_match($localGrant, $grant) || str_contains(strtoupper($grant), 'WITH GRANT OPTION')) {
                    $this->error('Use a database user with grants restricted to the selected staging database.');

                    return false;
                }
                $hasLocalGrant = true;
            }
        } catch (Throwable) {
            $this->error('Could not verify the staging database connection and grants.');

            return false;
        }

        if (! $hasLocalGrant) {
            $this->error('No database-level grants were found for the selected staging database.');
        }

        return $hasLocalGrant;
    }
}

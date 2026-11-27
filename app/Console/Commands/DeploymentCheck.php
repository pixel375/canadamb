<?php

namespace App\Console\Commands;

use App\Http\Middleware\RestrictPrelaunch;
use App\Models\CommunityOAuthClient;
use App\Models\Site;
use App\Support\NetworkSettings;
use App\Support\NetworkUrl;
use App\Support\StagedFeatures;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeploymentCheck extends Command
{
    protected $signature = 'network:deployment-check
        {--hostinger : Require the Hostinger production configuration}
        {--staging-database= : Check the independently selected MariaDB staging schema and grants}
        {--production-database= : Check the confirmed exact production schema and grants for prelaunch}
        {--migration-state : Print a read-only fingerprint of managed tables and migration history}
        {--database : Check the installed database state after migration}';

    protected $description = 'Validate deployment prerequisites without printing environment secrets';

    public function handle(): int
    {
        $staging = $this->option('staging-database');
        $production = $this->option('production-database');
        if (($staging !== null && $production !== null) || ($this->option('migration-state') && $staging === null && $production === null)) {
            $this->error('Select one deployment database when checking migration state.');

            return self::FAILURE;
        }
        if ($staging !== null && (! config('network.staging') || ! preg_match('/^[a-z0-9_]+_staging$/iD', $staging))) {
            $this->error('Staging requires a staging-marked environment and an explicitly named MariaDB staging database.');

            return self::FAILURE;
        }
        if ($production !== null && (! config('network.prelaunch') || config('network.staging') || ! preg_match('/^[a-z0-9_]{1,64}$/iD', $production))) {
            $this->error('Production-initial requires a prelaunch environment and the exact MariaDB production database name.');

            return self::FAILURE;
        }
        $target = $staging ?? $production;
        if ($target !== null && (config('database.default') !== 'mariadb' || ! $this->checkDatabaseTarget($target))) {
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
                || parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https' || config('app.maintenance.driver') !== 'file') {
                $issues[] = 'Hostinger releases require APP_ENV=production, a valid application key, HTTPS APP_URL and disabled debug output.';
            }
            if (config('session.driver') !== 'database' || ! config('session.secure') || ! config('session.http_only')
                || ! config('session.encrypt') || config('session.domain')
                || config('session.same_site') !== 'lax' || config('cache.default') !== 'database'
                || config('queue.default') !== 'database' || config('database.default') !== 'mariadb') {
                $issues[] = 'Business Web Hosting requires MariaDB, database sessions/cache/queues and secure host-only browser cookies.';
            }
            if (! extension_loaded('pdo_mysql') || ! extension_loaded('curl') || ! extension_loaded('sodium')) {
                $issues[] = 'Enable the PDO MySQL, cURL and Sodium PHP extensions.';
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

        if ($production !== null) {
            if (! RestrictPrelaunch::validHash(config('network.prelaunch_password_hash'))) {
                $issues[] = 'Configure a valid prelaunch password hash.';
            }
            if (! app()->isProduction() || config('app.url') !== 'https://canadamb.ca' || ! config('network.sso_enabled')
                || config('network.news_polling') || config('mail.default') !== 'array') {
                $issues[] = 'Prelaunch requires the production origin, shared sign-in, disabled news polling and the non-delivering array mailer.';
            }
        }

        if ($this->option('database')) {
            DB::connection()->getPdo();
            foreach (StagedFeatures::inspect() as $feature) {
                if ($feature['configured'] && ! $feature['ready']) {
                    $issues[] = 'Staged feature configuration requires recorded schema: '.$feature['label'].'.';
                }
            }
            app(NetworkSettings::class)->published();
            $main = Site::with('domain')->where('code', 'network')->firstOrFail();
            if ($main->domain->host !== parse_url(config('app.url'), PHP_URL_HOST)) {
                $issues[] = 'APP_URL must identify the central domain in the database.';
            }
            if ($this->option('hostinger') && (config('app.url') !== 'https://canadamb.ca' || $main->status !== 'active'
                || ! Site::where('code', 'brandon')->where('status', 'active')
                    ->whereHas('domain', fn ($domain) => $domain->where('host', 'brandonmb.ca'))
                    ->whereHas('location', fn ($location) => $location->where('slug', 'brandon'))->exists())) {
                $issues[] = 'The CanadaMB and BrandonMB domains must be active with their intended location scopes.';
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
        if ($this->option('migration-state') && ! $this->writeMigrationState()) {
            return self::FAILURE;
        }

        $this->info('CanadaMB deployment checks passed.');

        return self::SUCCESS;
    }

    private function checkDatabaseTarget(string $expected): bool
    {
        try {
            if (DB::selectOne('SELECT DATABASE() AS name')?->name !== $expected) {
                $this->error('The database connection does not select the confirmed deployment database.');

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
                    $this->error('Use a database user with grants restricted to the selected deployment database.');

                    return false;
                }
                $hasLocalGrant = true;
            }
        } catch (Throwable) {
            $this->error('Could not verify the deployment database connection and grants.');

            return false;
        }

        if (! $hasLocalGrant) {
            $this->error('No database-level grants were found for the selected deployment database.');
        }

        return $hasLocalGrant;
    }

    private function writeMigrationState(): bool
    {
        try {
            $table = config('database.migrations.table');
            $tables = array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW FULL TABLES'));
            sort($tables, SORT_STRING);
            if ($tables !== [] && ! in_array($table, $tables, true)) {
                $this->error('The deployment database contains an unmanaged schema. Review it without running migrations.');

                return false;
            }
            $history = $tables === [] ? [] : DB::table($table)->orderBy('id')->get(['migration', 'batch'])->all();
            $this->line('Migration state: '.hash('sha256', json_encode([$tables, $history], JSON_THROW_ON_ERROR)));

            return true;
        } catch (Throwable) {
            $this->error('Could not read the deployment migration state.');

            return false;
        }
    }
}

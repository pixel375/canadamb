# Testing

Run composer install and npm ci, then run vendor/bin/phpunit and npm run build. PHP tests use a private in-memory SQLite database by default. Use an empty disposable database and explicit DB_CONNECTION=mariadb, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD to exercise the same suite against MariaDB. RefreshDatabase migrates fresh. Never point it at production data.

## Browser smoke test

Follow the local setup in README.md. Create a local-only verified account in the disposable test database:

    php artisan tinker --execute 'App\Models\User::factory()->create(["name" => "Browser QA", "email" => "browser-qa@example.test", "role" => "super_admin"]);'

The factory password is password. Use a test-only local server and a fresh browser session. Then:

    npx --yes --package @playwright/cli playwright-cli -s=canadamb open http://canadamb.localhost:8000/ --browser=chrome
    npx --yes --package @playwright/cli playwright-cli -s=canadamb run-code --filename tests/Browser/two-host-smoke.js

The browser check covers both brand identities, separate sessions, appearance persistence, sign-in across the simulated origins, the locked Brandon location, approval and distinct geographic visibility for test listings, canonical routing, the theme preview and network-wide sign-out. Run deploy/smoke.sh separately over site URLs with real HTTPS after setting up and restricting the production origin. Use CANADAMB_SMOKE_MAIN and CANADAMB_SMOKE_LOCAL to point it at local or staging URLs.

The browser fixture needs the new community, events, and jobs modules enabled. A fresh seeded database enables them. When launching Artisan with a temporary DB_DATABASE override, use `php artisan serve --no-reload --host=127.0.0.1 --port=8000` to preserve it in Laravel’s development-server child process.

For staging acceptance, use clearly labelled test records in Brandon and a rural community. Check both detail links and sitemaps. Use a real SMTP test mailbox, submit a form with its CSRF token, reject a cross-origin submission without one, test Vite URLs on both hosts, and remove staging fixtures before launch.

## Pass 03 Marketplace browser workflow

Use a fresh, seeded, disposable SQLite database and the local `canadamb.localhost` and `brandonmb.localhost` hosts from the README. Set `APP_URL` to the CanadaMB development URL including the chosen port. Generate local Passport keys, run `php artisan network:sync-sso`, and build Vite assets. With the same database and storage settings for both processes, run:

    php tests/Browser/marketplace-fixture.php
    php artisan serve --no-reload --host=127.0.0.1 --port=8000

In a second terminal, run the browser using the same port:

    npx --yes --package @playwright/cli playwright-cli -s=marketplace open http://canadamb.localhost:8000/
    npx --yes --package @playwright/cli playwright-cli -s=marketplace run-code --filename tests/Browser/marketplace-completion.js

The script signs in through CanadaMB, creates and edits a Brandon photo set, approves the listing, checks current-host browsing and the Brandon canonical, exercises keyboard and swipe gallery navigation, tests the buyer contact return, filters provincial and city results, and relists sold and expired fixture records. Use a fresh database when rerunning so the fixtures start in the expected states. Local browser emulation complements the [allowlisted physical-device checks](DEPLOYMENT_CHECKLIST.md#pass-03-marketplace-release-checks).

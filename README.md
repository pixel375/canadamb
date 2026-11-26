# CanadaMB

**Development handoff:** [Master launch checklist (TODO.md)](TODO.md). Owner requirements take priority over older roadmap notes; update each completed task with item-specific source/CI/live evidence after every pass. Complete all independently doable work first; consolidate owner-only credentials, approvals and physical testing in the checklist’s Manual actions — last queue.

CanadaMB is a shared community network for Manitoba. The first two portal configurations are CanadaMB.ca and BrandonMB.ca. One Laravel installation and one database serve both domains. Hostinger Business Web Hosting is the initial production target.

## Local development

Requires PHP 8.3+ with SQLite and GD WebP support, Composer 2, and Node 24 for asset compilation.

    composer install
    cp .env.example .env
    php artisan key:generate
    touch database/database.sqlite
    php artisan migrate --seed
    php artisan passport:keys
    php artisan network:sync-sso
    npm ci
    npm run build
    php artisan network:admin admin@example.test
    php artisan serve --host=127.0.0.1 --port=8000

On PowerShell, use Copy-Item and New-Item -ItemType File for the copy and touch steps. Run setup in a fresh local database. Open http://canadamb.localhost:8000/ and http://brandonmb.localhost:8000/. Administration is at http://canadamb.localhost:8000/admin. Local mail goes to the application log.

## Project status

See [progress](docs/PROGRESS.md), the [implementation plan](docs/IMPLEMENTATION_PLAN.md), [testing](docs/TESTING.md), and the [Hostinger deployment runbook](docs/DEPLOYMENT.md).

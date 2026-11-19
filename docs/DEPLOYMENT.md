# Hostinger deployment

## Compatibility decision

Hostinger Business Web Hosting is the mandatory initial target. The design fits Web and Cloud plans that provide PHP 8.3+ on the web and CLI, Composer 2 over SSH, MariaDB, private sibling directories readable by PHP, TLS for the alias, and sufficiently frequent cron. PHP serves requests and handles bounded database-queue runs via cron. GitHub Actions produces the Vite assets. The initial system has no dependency on root access, Redis, Supervisor, persistent workers, WebSockets or server-side Node.js. A VPS would be needed for a future feature that requires OS-managed services or a custom document root. Check the account's specific quotas and staging behavior before launch. [Hostinger plan limits](https://www.hostinger.com/support/6976044-parameters-and-limits-of-hosting-plans-in-hostinger/)

[Hostinger Git deployment](https://www.hostinger.com/support/1583302-how-to-deploy-a-git-repository-in-hostinger/) · [Document root](https://www.hostinger.com/support/1583494-what-is-the-path-to-your-website-s-root-home-directory-and-how-to-change-it-in-hostinger/) · [MariaDB](https://www.hostinger.com/support/1583226-which-database-management-system-is-used-at-hostinger/) · [Composer](https://www.hostinger.com/support/8727597-how-to-install-composer-locally-at-hostinger/) · [Background processes](https://www.hostinger.com/support/which-server-capabilities-are-supported-at-hostinger/)

## Shared hosting layout

| Path relative to the site's parent directory | Contents |
| --- | --- |
| public_html/ | Front controller, Laravel rewrite rules and a small allowlist of public assets |
| canadamb/current | Private symlink to the intended release |
| canadamb/releases/ | Private commit-specific release trees, including vendor and Vite manifest |
| canadamb/shared/ | Private environment, application storage, logs and keys |
| canadamb/source/ | Private staging checkout used as the Git deployment destination |

1. In hPanel, locate the exact site path under FTP Accounts. Check PHP 8.3+ from SSH with the same binary cron uses, and check the PHP configuration selected for the website. Required extensions include Ctype, cURL, DOM, Fileinfo, Filter, GD with WebP, Hash, Mbstring, OpenSSL, PCRE, PDO MySQL, Session, Sodium, Tokenizer and XML. Check that the account supports symlinks in private paths and that PHP can read the sibling directory. Verify the installed Bash, Git, flock and GNU coreutils commands. Run Composer's platform check as part of release preparation.
2. Create one database and a dedicated database account in hPanel. Use the actual database hostname, credentials, and MariaDB connection from the production environment template. Run SELECT VERSION() and confirm a maintained MariaDB version supported by Laravel. Store credentials outside the document root and keep a tested database backup.
3. In Advanced → Git, use pixel375/canadamb on the intended release branch. Set the destination to the private source directory only if hPanel accepts and verifies it outside public_html. Disable automatic deployment during setup. If the account restricts the destination to public_html, use SSH Git fetch/checkout into that private directory. The staging checkout alone cannot switch the running release. Treat Hostinger's Git success as confirmation that it fetched files.
4. Provision the private environment from .env.production.example with chmod 600, generate APP_KEY once, and configure database and real SMTP credentials. To generate the first key from a private Composer-equipped checkout, run php artisan key:generate --show and paste its output into the shared .env. Retain the key across releases. Set APP_DEBUG=false. Keep SESSION_DOMAIN unset, enable Secure and HttpOnly, and use SameSite=Lax. Preserve exact request hosts. Configure trusted proxy IPs only if the hosting setup needs them and the origin is restricted accordingly.
5. Stage a fresh release from a reviewed commit. Run Composer install from composer.lock and composer check-platform-reqs --no-dev. Run npm ci and npm run build in CI or with Node 24. Transfer the resulting public/build directory for that same commit. Never move the repository, .env, source, backups, vendor tree, or storage under public_html.
6. Add brandonmb.ca in Websites → Manage site → Parked Domains for canadamb.ca. Follow hPanel's DNS instructions for both apex records. Confirm the original Brandon hostname is preserved through the alias. Obtain a valid certificate for each apex, then enable HTTPS redirects. Leave unowned domains unpublished. Route www aliases explicitly at the hosting edge only if provisioned and covered by TLS.
7. Validate on an access-controlled staging installation or restrict the origin while testing. Inspect hPanel access and error logs. Complete the two-host smoke procedure before removing access restrictions. Keep CDN full-page caching off until cookies, login, preview, admin and publication changes are verified.

[Park a domain](https://support.hostinger.com/en/articles/1583404-how-to-park-a-domain) · [Parked-domain certificates](https://support.hostinger.com/en/articles/5613445-how-to-fix-a-failed-lifetime-ssl-installation-in-hostinger) · [Enable HTTPS](https://www.hostinger.com/support/1583201-how-to-enable-or-disable-https-for-your-website-at-hostinger/)

## Release checklist

1. Supply the exact reviewed Git commit. Confirm a healthy current deployment, create private database and storage backups, and test the migration against a restored staging copy. Save release metadata and ensure sufficient storage. MariaDB DDL can commit partway through a migration, so rehearsing the restore matters.
2. Prepare the private release, validate its Composer dependencies and Vite manifest, and review artisan migrate --pretend --force on staging. On a new install Laravel may first create its migration table even in pretend mode. Point the staged release to the same private environment and storage solely during the authorized maintenance window.
3. Stop or pause worker cron jobs. Put the current app into maintenance mode. Run php artisan migrate --force and the idempotent starter seeder. Generate Passport keys once in private shared storage and run php artisan network:sync-sso. Populate any owner/admin credentials through the interactive bootstrap command. Rebuild configuration, route and view caches from the candidate release. Run the included deploy script for the private cutover.
4. Inspect both hostnames over valid TLS. Verify brand, location, content scope, asset responses, auth redirects, host-only cookies, CSRF, canonical links and absence of public access to environment, source and logs. Resume cron after acceptance.
5. If a step fails before cutover, leave the current release pointer intact. Drain queues and keep maintenance mode while you inspect the deployment and PHP logs. A code rollback can reuse the previous release pointer only after confirming database schema compatibility. For incompatible or partial schema changes, restore the tested backup or apply a reviewed forward fix. Do not discard a failed release log. Bring both domains back together.

Example commands over SSH, run from the private checkout. Replace CHECKED_COMMIT with the full 40-character reviewed SHA and give VITE_BUILD the absolute path to the extracted CI artifact for that SHA:

    SITE_HOME=/home/u12345678/domains/canadamb.ca
    CHECKED_COMMIT=REPLACE_WITH_REVIEWED_SHA
    VITE_BUILD=/private/path/to/extracted-vite-build
    bash deploy/hostinger-release.sh prepare "$SITE_HOME" "$CHECKED_COMMIT" "$VITE_BUILD"

After rehearsing the migration in staging, pausing worker cron, backing up the database and storage, and restricting public access, run:

    CANADAMB_ACTIVATION_CONFIRMED="$CHECKED_COMMIT" bash deploy/hostinger-release.sh activate "$SITE_HOME" "$CHECKED_COMMIT"

Activation keeps Laravel in maintenance mode. From the restricted origin, run the current release's artisan up to begin smoke tests. Leave the origin restricted until acceptance. The script checks the production environment and refuses unexpected public files and symbolic links. Adopting an existing site requires backing up its old public files privately and preparing the allowed public layout before first activation. Logs are under canadamb/shared/storage/logs and identify the release by SHA. Retrying an incomplete prepare quarantines its earlier attempt in the private failed-releases directory.

## Scheduler and queues

Add a Custom cron with the verified absolute PHP path and private current release path:

    /usr/bin/php /home/u12345678/domains/canadamb.ca/canadamb/current/artisan schedule:run >> /home/u12345678/domains/canadamb.ca/canadamb/shared/storage/logs/scheduler.log 2>&1

Use the shortest available interval suitable for the scheduled work. Laravel's usual scheduler interval is every minute. Keep CANADAMB_CRON_QUEUE_DRAIN=true for Business Web Hosting. Each run drains up to 25 database jobs, stops when empty, and starts no more jobs after 45 seconds. The worker checks this time between jobs. The 30-second per-job timeout requires CLI pcntl, so jobs must also impose their own network and execution time limits. Verify the actual plan cron limit and avoid overlapping runs. Keep retry_after beyond worker timeout, monitor failed_jobs and rotate logs.

[Hostinger cron](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/)

# Architecture

CanadaMB and BrandonMB run through one Laravel installation. Each request resolves an exact hostname from the database and binds a request-scoped site. Publication scope is validated before persistence and public queries filter on the same location ID. One record owns the content ID and moderation state. An eligible active community domain supplies its canonical detail URL.

The main domain owns network administration and identity. Laravel sessions stay host-only. Laravel Passport provides OAuth 2.0 Authorization Code with PKCE and Laravel Fortify handles centralized credentials. Site and theme settings publish through administrative actions with actor-attributed audit history. MariaDB is the production target and SQLite supports fast local integration tests.

Hostinger Business Web Hosting serves the first two apex domains from one public_html root. A lightweight front controller reads the current private Laravel release. Git and deployment operations stage source and build output privately. A single database queue runs in bounded cron batches and the server serves precompiled assets. Read [deployment](DEPLOYMENT.md) for the release sequence and evidence still needed from hPanel.

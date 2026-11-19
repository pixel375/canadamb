# Domain routing

CanadaMB resolves the exact HTTP hostname against domains. CanadaMB.ca is provincial and owns /admin and the OAuth issuer. BrandonMB.ca is a local portal scoped to the Brandon location ID. Maintenance returns 503. Draft, disabled and unknown hosts return 404. Seeded Steinbach and Winnipeg entries are drafts. Virden can be configured from administration when needed.

Use an alias for Brandon on the CanadaMB Hostinger website. Keep the alias hostname intact through the hosting edge. Each apex needs trusted HTTPS. Full HTML caching at a CDN should stay off during launch verification. See [Hostinger deployment](DEPLOYMENT.md).

Local development uses two .localhost hostnames on one PHP process. The sample environment creates those hosts when seeding a fresh local database. Production seeding uses the actual apex hostnames.

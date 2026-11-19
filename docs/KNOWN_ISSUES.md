# Known issues and remaining acceptance

**Current status 2026-11-19:** CanadaMB and BrandonMB are successfully running the same Laravel release on actual Hostinger domains behind IP-restricted prelaunch; owner accepted 7/7 initial checks and created disposable Marketplace listings. See [LIVE_STATE_2026-11-19.md](LIVE_STATE_2026-11-19.md) for the verified operational snapshot. The previous statement that production prelaunch activation was pending is historical.

- **Marketplace UX:** Homepage listing photos are not linked, card images use cropping, detail gallery displays the small preview and opens a standalone image, and canonical-site 301s move provincial visitors to Brandon. No category link, substantial seller card or related/recent sidebar. Multi-image end-to-end UX remains unverified. See [MARKETPLACE_FEEDBACK.md](MARKETPLACE_FEEDBACK.md).
- **Deployment stability:** Original `/dev/fd` logging failed on Hostinger. Some manual and Git working-copy scripts unexpectedly truncated to 0 bytes (**root cause unknown**); immutable Git objects survived. The corrected script must be confirmed in a new actual release. Keep `public_html` directory in place; replacing its inode caused both sites to return 404. Keep Hostinger Git auto-deploy pointed away from `public_html`. See [DEPLOYMENT_AUTOMATION.md](DEPLOYMENT_AUTOMATION.md).
- **Operational acceptance:** Initial migrations and real-domain login passed. Current cron/queue cadence, actual production-dump restore, email delivery, monitors, broader real-browser content flows and update/rollback rehearsal are pending. Preserve IP gate, app key, OAuth private keys, database and media.

- The location starter list is small. The reviewed CGNDB import broadens coverage. Authoritative boundaries and administrative relationships require additional Manitoba data.
- Some Marketplace features remain open, including richer category administration, advanced anti-spam controls, message-report review and automated media retention.
- Business teams, ownership disputes/transfers, automated business contact verification and subscription entitlements need additional work.
- Scheduled AI processing and the ChatGPT Scheduled integration remain open. Feed transport still needs verification with the intended publisher feeds and the Hostinger resolver.
- Forum editing, member reporting and richer moderation, recurring events, and job alerts remain open.
- Public real estate and property monetization require a separate compliance review. The existing registry is for private drafts.
- Advertising, subscriptions, user export/deletion, MFA and launch policies remain open.

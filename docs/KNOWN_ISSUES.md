# Known issues and remaining acceptance

**Verified restricted production release:** `0a7a9ab8ccb40438ebc9392f742e3ac1ae11e420`, November 24, 2026 (Manitoba time). [Backend CI](https://github.com/pixel375/canadamb/actions/runs/45518899797), [browser QA](https://github.com/pixel375/canadamb/actions/runs/45518899917), [deployment and independent audits](https://github.com/pixel375/canadamb/actions/runs/45519178954) passed. Both domains retained the external HTTP 403 gate. This is not a public-launch approval.

## Outstanding launch blockers and acceptance

- Real phone/desktop testing from an approved network, including actual Android standalone/WebAPK installation (browser manifest and worker tests are not device installation proof), full HTTPS community sign-in and posting.
- SMTP delivery and monitored support mailbox, reviewed privacy policy/terms, retention, account export/deletion.
- Review-staged content-reporting queue for discussions, replies and events; jobs, businesses and private messages remain outstanding; use the existing listing-report queue for Marketplace and contact guidance for other issues. Do not store message contents or reports in the audit log as a substitute for an authorized report store. See the migration proposal in [CONTENT_REPORTING_PLAN.md](CONTENT_REPORTING_PLAN.md).
- Real estate publication, ownership/authority and compliance review; property drafts remain private.
- Business ownership transfer/dispute handling, invitation acceptance, contact verification, subscriptions, advertising and job alerts. Owner-controlled manager/editor access and read-only ownership lookup are implemented in the current development branch.
- Scheduled news ingestion and provider feeds require verification; polling remains suppressed during prelaunch.
- Recheck production backups, scheduler and mail delivery as part of launch acceptance; practice file-level media restoration. Retain the original public_html inode, private keys and access restrictions. No unreviewed migrations or seeders.
- Location boundaries and administrative relationships require additional authoritative Manitoba data. Forum editing, recurring events and automated media retention remain open.

## Superseded earlier UX concerns

Category-first Marketplace discovery, original-size galleries, same-domain browsing with canonical URLs, seller dashboards, messaging, existing-photo editing, responsive administration, scoped search and account preferences have been implemented and covered by automated checks. Real device acceptance remains separate. See [PROGRESS.md](PROGRESS.md) and the verified release above.

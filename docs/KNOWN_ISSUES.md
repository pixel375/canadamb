# Known issues and remaining acceptance

**Verified restricted production source:** `ba1ee92045150944dd0d0db3a7eeebde5e244661`. [Backend CI](https://github.com/pixel375/canadamb/actions/runs/45601743490), [browser QA](https://github.com/pixel375/canadamb/actions/runs/45601743443), and [deployment with independent audits](https://github.com/pixel375/canadamb/actions/runs/45602126083) passed. This is not a public-launch approval.

## Outstanding launch blockers and acceptance

- Business/job inquiries and participant-local archiving are review-staged; the proposed nullable-listing migration requires backup, migration and rollback review. Private re-encoded photo attachments and owner-specific inquiry opt-in are review-staged. Arbitrary files, attachment deletion/retention policy, attachment-specific report review is staged with restricted audited access; and a shared business inbox remain outstanding. Existing private chat history is never assigned to a new owner.

- Real phone/desktop testing from an approved network, including actual Android standalone/WebAPK installation (browser manifest and worker tests are not device installation proof), full HTTPS community sign-in and posting.
- SMTP delivery and monitored support mailbox, reviewed privacy policy/terms, retention, full account export/deletion (expanded allowlisted password-confirmed exports and staged privacy request intake; actual deletion execution, fulfillment and retention review outstanding). Read-only attachment inventory is available; no automatic deletion.
- Review-staged content-reporting queue covers discussions, replies, events, jobs, businesses, member profiles and individually reported received Marketplace messages; production migration and retention review are still required. Use the existing listing-report queue for Marketplace and contact guidance until broader reports are enabled. Do not store message contents or reports in the audit log as a substitute for an authorized report store. See the migration proposal in [CONTENT_REPORTING_PLAN.md](CONTENT_REPORTING_PLAN.md).
- Real estate publication, ownership/authority and compliance review; property drafts remain private.
- Contested ownership adjudication, branch closure and relocation, automated contact verification, subscriptions, advertising and proactive job alert delivery. Saved job searches and on-demand in-app new-match alerts are review-staged; no email, push or scheduler is enabled. Moderated branch additions, address corrections and manual email review are review-staged. Private dispute intake and independent triage are review-staged; they do not change memberships. Invitations and consensual transfers are review-staged and require migration approval. Owner-controlled manager/editor access and read-only ownership lookup are implemented in the active implementation branch.
- Scheduled news ingestion and provider feeds require verification; polling remains suppressed during prelaunch.
- Recheck production backups, scheduler and mail delivery as part of launch acceptance; practice file-level media restoration. Retain the original public_html inode, private keys and access restrictions. No unreviewed migrations or seeders.
- Location boundaries and administrative relationships require additional authoritative Manitoba data. Bounded recurring submissions are implemented behind a switch; series-wide changes and automated media retention remain open. Reply editing, withdrawal and private history are implemented in the current source; verify deployment separately.

## Superseded earlier UX concerns

Category-first Marketplace discovery, original-size galleries, same-domain browsing with canonical URLs, seller dashboards, messaging, existing-photo editing, responsive administration, scoped search and account preferences have been implemented and covered by automated checks. Real device acceptance remains separate. See [PROGRESS.md](PROGRESS.md) and the verified release above.

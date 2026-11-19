# Hostinger staging acceptance

Record the Git SHA, operator, timestamp, observed result and supporting CI or local log for each applicable check. Use controlled accounts and disposable listings. Keep SMTP, payment, property publication, and news automation separate from launch approval.

## Before activation

- [x] Preserve `e38b910bbead5c804c6d3c262d06ae828dfb8d5c` remotely on the implementation branch. `main` remains unchanged.
- [x] Verify Hostinger SSH host key and read-only runtime, local web-root layout, and both apex certificates.
- [ ] Enable Sodium and verify it from Composer, SSH, cron and web PHP. Check web errors are private and that PHP can read the sibling release. Remove any temporary diagnostic immediately.
- [ ] Confirm the Brandon hPanel parked alias preserves its hostname and serves the same physical document root as CanadaMB. Verify HTTPS and cache rules for both.
- [ ] Confirm a staging-only MariaDB database and credential scope. Test backup restore, keep the production `.env` untouched, and rehearse the exact commit migrations.
- [ ] Quarantine the existing checkout outside `public_html`. Verify the clean root includes only the entrypoint, managed rules, approved assets and any valid ACME challenge files.
- [ ] Verify the exact successful CI artifact, production Composer platform checks, preflight logs and recovery plan. Confirm initial staging cutover readiness.

## Exact-domain application checks

- [ ] Run `CANADAMB_SMOKE_STAGING=1 bash deploy/smoke.sh`. Confirm both show the correct public identity, accents, assets, canonical paths, cookies, and staging headers. The CanadaMB communities directory appears only on the provincial portal.
- [ ] Match the private current SHA to both responses via a trace from the same release. Confirm one staging DB with a controlled global theme publish and restore. Both portals adopt the theme and retain their different accents.
- [ ] Publish and remove a disposable Brandon item. Confirm both feeds show the same record, its detail canonical is Brandon, and removal reaches both feeds. A disposable rural listing appears only provincially. An authenticated conflicting Brandon web and API submission is rejected.
- [ ] Register a controlled account, verify its email, sign in from Brandon through CanadaMB, and verify the callback and central-only admin. Observe separate host-only encrypted Secure/HttpOnly SameSite=Lax cookies. Test password reset and sign-out everywhere. Confirm expired or forged state fails.
- [ ] Submit a valid form, then repeat with a missing token, invalid token, and an off-origin request. Confirm the rejected attempts leave no record. Verify redirects, forms, canonical URLs and sitemaps preserve the intended domain.
- [ ] Verify `.env`, `.git`, logs, source, dependencies and private uploads remain inaccessible on both origins. Check generic error output using a controlled staging request and compare with private server logs. Ensure CDN caching cannot reuse a response across hosts or sessions.
- [ ] Observe hPanel cron across two ticks. Process a controlled test job, verify private output and inspect failed jobs. Ingest an attributed controlled news fixture twice and confirm deduplication and editorial hold. Keep live feed scheduling off.
- [ ] Rehearse a compatible code rollback and an isolated database restore. Re-run smoke checks on both domains and record recovery results.

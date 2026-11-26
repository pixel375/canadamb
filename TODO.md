# CanadaMB — master launch checklist

Owner feedback set 1: 2026-11-26. This file supersedes conflicting earlier roadmap/UX preferences. Use it at the start and end of every implementation session. Keep stable IDs; add discovered work rather than losing it in chat. Check an item only when its specified work is implemented and tested; distinguish source implementation, exact green CI, independent live verification and physical-device/operational acceptance. Record evidence and new blockers in the checkpoint section. Never enable public access, registration, email, polling, commercial features or unapproved migrations merely to complete a checklist item. P0 = launch gate; P1 = desired full free launch feature; P2 = optional/expansion. Owner can change scope. MFA is excluded.

**Owner instructions (2026-11-26, apply to every future session):** Do all independently executable development, configuration preparation, automated verification and documentation first. If a step requires owner input, credentials, physical-device testing, a legal/business decision, approval or a manual action, complete all feasible preparatory work without it, record the precise remaining action and dependency in the Manual actions — last queue, and continue with other unblocked checklist work. Do not repeatedly stop to request input; bring the consolidated manual queue to the owner only after all independently doable checklist work is complete (unless a genuine safety/security blocker requires immediate attention). Do not bypass approvals, fabricate credentials or enable protected production features. For every completed TODO item, append a concise item-specific completion note directly on its checked line or immediately below it: what changed, exact source/CI/live evidence, and any residual physical-device/manual acceptance. Update the notes every session; keep IDs stable and never mark an unverified feature as deployed.

## A — Registration and identity (P0)
- [ ] A01 Separate signup UX on provincial and each local host; retain one network-wide identity/account and safe SSO. No separate user databases.
- [ ] A02 Require selectable Manitoba signup location on CanadaMB; local signup permits only that site's fixed location, enforced server-side. Note other residents should register at CanadaMB.ca.
- [ ] A03 Display signup location on profile; distinguish from editable browsing preference; review privacy and existing-account backfill.
- [x] A04 Reduce minimum password length from 12 to 8 consistently (registration, reset, account change); retain rate limits, sensible maximum and strong password guidance. **Completion:** Eight-character shared password rule and form hints; retains rate limits and token revocation. e65c47a live audit 45845814596.
- [x] A05 Persistent login by default on each host and seamless cross-host SSO without repeated password prompts; review remember-token/session revocation, expiry, logout-all and recovery. Avoid shared cross-domain cookies, caching credentials, or leaking tokens. Document any required host setup and user testing. **Completion:** 30-day host-only remember tokens, opt-out on central login, password-change revocation; 8d38ec71 backend 45849119706/browser 45849119721 green; live/physical-device acceptance pending.
- [ ] A06 Configure production SMTP and test verification, reset, recovery and branded mail on both domains; monitored support mailbox.
- [ ] A07 Anti-spam/bot protection, account suspension/ban, account settings/profile polish.
- [ ] A08 Password-confirmed exports, privacy request fulfillment, deletion/anonymization and data retention; protect other participants' records.
- [ ] A09 Physical-device registration/sign-in/reset/logout testing; public signup remains off until authorized.

## B — Locations and navigation (P1)
- [x] B01 Remove homepage Explore by location block; location selection belongs on Communities page. **Completion:** Removed homepage location selector; e65c47a live audit 45845814596.
- [x] B02 Provincial Communities: separate dedicated active local website cards from simple alphabetical Manitoba location filter dropdown (optional typeahead, not separate Find a community search). **Completion:** Alphabetical provincial dropdown and separate active dedicated portal cards; e65c47a live audit 45845814596.
- [x] B03 Location selection persists by default; remove browse-once/remember choices; clear to all Manitoba. Apply scope consistently to browsing/navigation, never override a fixed local host or publication authorization. **Completion:** Persistent selection applies to direct provincial module URLs; explicit query remains an override, local scope unchanged. 8d38ec71 backend/browser green; exact live audit pending.
- [x] B04 On CanadaMB filtered to a location with an active local domain, show a compact clickable below-header invitation to that site and explain shared account. **Completion:** Below-header active-portal invitation and shared-account explanation; e65c47a live audit 45845814596.
- [x] B05 On local hosts, Manitoba > local city breadcrumb with Manitoba linking to provincial site. **Completion:** Local Manitoba breadcrumb; e65c47a live audit 45845814596.
- [x] B06 Local location pin shows actual city, not CanadaMB network, and opens its own Communities page; mobile pin near top of drawer. **Completion:** Actual location pin and top drawer location link; e65c47a live audit 45845814596.
- [x] B07 Communities tab on every host; local Communities page identifies current site, links to provincial site for all Manitoba using same account, shows only other active dedicated website cards, notes all other communities are on CanadaMB. **Completion:** Communities page on both hosts with active dedicated cards, current local site and provincial link; e65c47a live audit 45845814596.
- [x] B08 Concise shared-publication explanation on FAQ and applicable Marketplace, discussion/event, business and job creation forms: approved local content also appears provincially, and approved provincial content for a location appears on its dedicated site where available and enabled. **Completion:** Shared publication explanation and FAQ links on Marketplace, discussion/event, business profile/update and job creation/reuse forms. 2a679b17 backend 45852884505 and Chromium 45852884447 passed; independent live verification pending.
- [x] B09 Clear FAQ on both hosts covering network, shared account, location filtering and posting. **Completion:** FAQ and footer/drawer navigation; e65c47a live audit 45845814596.
- [ ] B10 Authoritative Manitoba locations import/validation, municipality relationships, Indigenous/northern locations, boundary and address review; verify portal scope.
- [ ] B11 Simplify new portal onboarding, DNS/TLS/SSO, branding and location picker; Steinbach/Winnipeg can follow later.
- [ ] B12 Preserve the browsing domain for **all** mirrored public content and actions (Marketplace, real estate, business profiles/updates, jobs, discussions/reply permalinks, events, news, related content, notifications, saved items, search and chat context). CanadaMB detail URLs must render the same approved shared records locally instead of redirecting to a dedicated community domain; local hosts retain their fixed geographic scope and module permissions. Only explicit selection of a dedicated website from Communities cards or the below-header invitation should switch the visitor to a local domain. Preserve necessary user-initiated SSO returns, external source/application links, and SEO canonical metadata separately from human navigation. Audit all links, redirects, pagination, forms and future modules; test both hosts, hidden records, out-of-scope records and staged features. **Completion:** Removed all six forced public-detail canonical redirects; Marketplace already same-host. Current-host reply permalinks and notification open, existing local visibility checks, two-domain content/feature tests and Chromium mirrored discussion test. SEO metadata and explicit portal choices preserved. 2a679b17 backend 45852884505 and Chromium 45852884447 passed; independent live verification pending.

- [ ] B13 Separate content geography from browsing scope. Detail pages, breadcrumbs, category links, menus, search, related records and actions must preserve the visitor's explicit preference; only Communities preference POST changes the persistent cookie. Regression: CanadaMB all-Manitoba and Winnipeg preference opening Brandon/Steinbach details, then returning to other modules; no unsolicited local-domain links except Communities cards or invitation. Owner-reported regression 2026-11-26; P0 before launch.

## C — Discussions and events (P1)
- [x] C01 Reply edit cancel/back/save returns to originating discussion page (including pagination); preserve My replies entry behavior and reject unsafe redirect targets. **Completion:** Origin-aware reply editing return to thread pagination, fixed server-generated destination; e65c47a live audit 45845814596.
- [x] C02 Remove visible Link to this reply control; retain safe internal deep links for notifications. **Completion:** Visible reply permalink removed, internal notification links retained; e65c47a live audit 45845814596.
- [ ] C03 Clean reply-to-comment flow (including own comment), with appropriate parent linkage, visibility, moderation and mobile layout; review schema.
- [ ] C04 Events created only through discussion composer as final separated Event category, not independent posting; existing event history retained; date required, time optional, city fixed locally, descriptive address/venue required. Review all-day/timezone handling.
- [ ] C05 Separate discussion vs calendar event automatic/manual approval settings with independent status, audit, safe default and review.
- [ ] C06 Discussion shows Go to event when approved; disabled Review pending while event awaits review; rejected/removed states safe; ordinary replies/reporting remain.
- [ ] C07 Calendar entry displays event details and link to discussion; verify linkage lifecycle and existing records.
- [ ] C08 Activate reviewed event linkage and recurring submissions; persistent series identity, bulk title/occurrence management, cancellation/rescheduling and calendar export tests.
- [ ] C09 Polish threads, editor, calendar and agenda; consider images, reactions, mentions, event bookmarks/reminders; abuse/spam controls, scoped moderators and trending discovery.
- [ ] C10 Populate legitimate events and test discussions/events end-to-end.

## D — Notifications (P1, reporting and safety P0)
- [ ] D01 Descriptive actor/action notifications: event comment, reply to comment, approval, rejection with safe reason/support link, Marketplace expiry, account/moderation status, other relevant actions; never expose hidden/private records.
- [x] D02 Opening a notification marks only that user's notification read and navigates to authorized target; preserve scope and CSRF protection. **Completion:** CSRF-protected notification open POST; participant/recipient, approval and portal scope verified, idempotent read marker and trusted 303 reply path; two-host browser test. 2a679b17 backend 45852884505 and Chromium 45852884447 passed; independent live verification pending.
- [ ] D03 Desktop/mobile Web Push backend, opt-in/permission and unsubscribe, per-device subscriptions, PWA service worker, VAPID/provider configuration and setup guidance in admin. No credentials exposed or unsolicited sends; verify browser/OS support.
- [ ] D04 Admin notifications settings, bounded statistics, delivery/failure/retry/deduplication, audited preview and confirmation for send to all/selected users/roles (e.g. business owners); consent/preferences and abuse safeguards.
- [ ] D05 Activate staged preferences, Marketplace/job search alerts, optional email/digests/frequency and event reminders; verify read counts across portals and scheduled delivery.

## E — Messaging (P1, privacy/security P0)
- [x] E01 Context link and optional visible item thumbnail for Marketplace/business/job conversations; no access to unpublished or out-of-scope item details. Context card at top of history scrolls away; compact context title remains in header. **Completion:** Public-only context card/link, eligible Marketplace thumbnail, compact title and privacy checks. 2a679b17 backend 45852884505 and Chromium 45852884447 passed; independent live verification pending.
- [ ] E02 Activate private photo uploads after migration/media review; secure document uploads with explicit allowlist, content/MIME validation, size/quota, malware risk mitigation, non-executable private storage, authorized download and attachment safety warning/link. Do not accept arbitrary executable files.
- [ ] E03 Distinguish Marketplace, Job, Business, direct member and highlighted official admin conversations in inbox/header; add direct member and admin messaging with anti-spam/block/report safeguards and reviewed schema.
- [ ] E04 Admin scam investigation access: privacy/legal review first; default to narrowly scoped reported-content evidence, audited restricted access and retention; no blanket browsing of private messages without explicit approval and policy.
- [x] E05 Remove Search this conversation from chat UI (supersedes prior private history search enhancement); remove stale route/session search code and update regression tests as appropriate. **Completion:** Conversation history search route, controller and UI removed; clears stale session search state, participant-only regression. 2a679b17 backend 45852884505 and Chromium 45852884447 passed; independent live verification pending.
- [x] E06 Fit conversation to viewport on desktop/mobile, one history scrollbar only; compact header, clickable member name/avatar, small context subtitle, back arrow replacing All messages; no footer on chat. **Completion:** Compact viewport chat, clickable member, back arrow, internal scroll history, no footer; automated viewport tests at desktop/mobile widths. Physical-device acceptance remains M05. 2a679b17 backend 45852884505 and Chromium 45852884447 passed; independent live verification pending.
- [ ] E07 Keep composer/character count/safety/attachment/send controls visible at bottom; handle virtual keyboard, safe-area, history paging and scroll preservation.
- [x] E08 Remove Jump to latest button and automatic refresh explanatory text; keep accessible new-message/failure feedback and correct scrolling without forced jump. **Completion:** Removed jump button and routine refresh explanation; auto-scroll only when already near bottom, accessible live status/failure feedback retained. 2a679b17 backend 45852884505 and Chromium 45852884447 passed; independent live verification pending.
- [ ] E09 Icon-only chat options with accessible label, concise block/unblock, report chat; no long block description or manage-blocked link in dropdown.
- [x] E10 Inbox: remove My account button, move subdued Blocked members link below card; paginate newest ten by default. **Completion:** Ten-conversation default pagination and simplified inbox links; e65c47a live audit 45845814596.
- [ ] E11 Activate extended inquiries/archives, photo/inquiry settings and report review; verify delivery/read state, blocked participants, private media retention. Shared business inbox later.

## F — Site-wide UX and Marketplace (P1)
- [ ] F01 Required form fields visibly marked with asterisk; missing/invalid fields red with accessible error association, server validation and no color-only feedback. Cover all public/member/admin forms.
- [ ] F02 Remove redundant obvious explanatory notes; retain material safety, consent, legal, error and irreversible-action guidance.
- [x] F03 Marketplace initial publication 30 days; expired owner renewal for 7 days (not 30); review expiry jobs, re-review vs renewal semantics, ownership/concurrency and stale records. Preserve current safeguards. **Completion:** 30-day initial approval; previously published expired listing renews for seven days after review; no active-period extension on re-review. Ownership/stale version/expiry regression passed. 2a679b17 backend 45852884505 and Chromium 45852884447 passed; independent live verification pending.
- [ ] F04 Activate saved searches and new-listing alerts; expand category fields/taxonomy; seller pages, photo UX, scam/duplicate prevention, expiry and cleanup; real-device gallery/upload/edit/sold tests.
- [ ] F05 Site-wide professional visual consistency: province/local homepages, desktop/mobile nav, typography, cards, forms, search/filters, loading/empty/error states, light/dark, theme controls, accessible keyboard/focus, performance and branding/social images.
- [ ] F06 Consider radius search and further category-specific discovery.

## G — Real estate (P1; compliance gate P0 if enabled)
- [ ] G01 Regulatory review, written owner/agent authority, brokerage/registration and mandatory disclosures, address/privacy, expiry/takedown and media review.
- [ ] G02 Public submit/review/publish lifecycle with independent moderation, safe gallery/contact, sale/rental/land/commercial, expiry/renew/removal; explicit flag approval. Private drafts stay private until then.
- [ ] G03 Property cards/details, saved properties/searches, related results; optional map discovery; real-device tests.
- [ ] G04 Property commercial promotions/agent plans later.

## H — Business and jobs (P1)
- [ ] H01 Legitimately reusable business datasets, populate Brandon/province, improve dedupe/category/multi-branch mapping.
- [ ] H02 Activate invitations, consensual transfers, disputes, moderated branch/address/contact changes; ownership evidence, independent adjudication, contact verification.
- [ ] H03 Business dashboard/profile polish, logos/cover/gallery, hours/service area where appropriate, direct inquiries/reporting.
- [ ] H04 Activate saved job searches and new-match alerts, direct employer inquiries, expiry scheduler, employer dashboard and discovery/anti-fraud/reporting; populate legitimate jobs, test end-to-end.
- [ ] H05 Later: native applications/resumes/applicant tracking, proactive delivery.

## I — News and content (P1)
- [ ] I01 Legitimate news sources/rights, attribution and geographic coverage; configure and verify real RSS/Atom outbound feeds on Hostinger.
- [ ] I02 Activate candidate review and scheduled polling only with approval; AI-assisted original summaries, deduplication, geographic classification, editorial rules/independent sensitive review, publication scheduling.
- [ ] I03 Verify retry/backoff, monitoring, news layouts, seed current legitimate local content.

## J — Administration / safety (P0)
- [ ] J01 Activate comprehensive content report queue (reports/attachments privacy and retention review).
- [ ] J02 Unified moderation prioritization, local moderator roles, user search, warnings/suspension/ban, audit history and independent disputes.
- [ ] J03 Review published auto-approval and local override policies; separate calendar-event approval; trusted-member/anti-spam controls as needed.
- [ ] J04 Admin UI polish, theme/settings/operations health, notification console, privacy request fulfillment and audited retention controls.

## K — Staged feature activation (P0/P1)
- [ ] K01 Review and approve selected migrations 000003 reports, 000004 event links, 000005–000007 business workflows, 000008 saved jobs, 000009 conversation alteration, 000010 photos/inquiry preferences, 000011 privacy, 000012 notifications, 000013 news candidates, 000014 Marketplace saved searches. Inventory exact recorded schema; backup and rehearsal SQLite/MariaDB populated data, no destructive production rollback. Enable approved flags only after independent live verification and acceptance.
- [ ] K02 Separate commercial schema 000001–000002 and future new schema proposals; no unapproved migrations.

## L — PWA, weather and local utilities
- [ ] L01 (P0) Real Android standalone/WebAPK install (not shortcut), iOS Home Screen, app identity per host, login, release updates, safe offline caching; physical-device acceptance.
- [ ] L02 (P1) WeatherAPI credential/terms, outbound HTTPS, coordinates/cache/quota/diagnostics and optional activation; check clock/weather responsive design.

## M — Legal / operations / launch (P0)
- [ ] M01 Review Terms, Privacy, Community Guidelines, prohibited goods, acceptable use, copyright complaint, moderation/appeal, retention, support, Canadian privacy and email consent; real estate/commercial rules if enabled.
- [ ] M02 Security review: authorization, CSRF, SSO, rate limits, file access/upload, admin privileges, dependencies; no secret leakage.
- [ ] M03 Recheck real cron/queue, failed jobs, backups and restore (including file-level media), retention, rollback, monitoring and resource/realistic concurrent-user load tests.
- [ ] M04 SEO metadata, canonical/sitemaps/structured data, share previews, Search Console, privacy-conscious analytics, legitimate launch content and Facebook promotion; indexing remains off until launch.
- [ ] M06 SEO quality: meaningful branded page titles, record-specific location/title/descriptions, per-host social metadata, safe canonical and sitemap consistency for mirrored records, structured-data validation and no indexing of private or prelaunch pages.
- [ ] M07 Dedicated administration SEO page: network and per-portal metadata configuration/preview, canonical and sitemap/indexing diagnostics, safe audited changes, no automatic indexing activation. External Search Console setup deferred.
- [ ] M05 Freeze exact green CI and independently verified live candidate; real desktop/mobile end-to-end all enabled flows; remove controlled test content, backup, approve opening public access/registration and indexing, verify both hosts externally, monitor errors and resources.

## N — Monetization (P2; not required for first free launch)
- [ ] N01 Pricing/products, inventory, advertiser dashboard, creatives/approval, banners/sponsored directory/featured listings, Business Pro entitlements.
- [ ] N02 Stripe sandbox checkout/webhooks, idempotent ledger/reconciliation, invoices/refunds/cancellation, tax and fraud, aggregate privacy-conscious ad statistics; review legal/policy and explicitly approve live payment/delivery. No real charges until approved.

## Checkpoints
- 2026-11-26 baseline: 2f6af59 exact backend and browser CI passed (45821957973, 45821957920); guarded deployment 45822402212 independently verified live with two retained releases, 21,916 entries. Prior code includes many foundations and staged features; unchecked items mean remaining implementation, activation, polish or operational acceptance, not necessarily absence of code.
- Scope decisions: Owner feedback overrides earlier recommendation to keep conversation history search, centralized-only signup UX, separate event posting and browse-once location choices. Keep one account/database, restricted prelaunch and safe authorization. Push notifications moved into intended launch feature set. Broad admin message visibility requires privacy/legal review. No MFA.

- First feedback pass source changes: B09 FAQ, C01 reply edit return, C02 visible permalink removal, E10 inbox simplification and ten-row pagination. Implementation submitted for CI; leave unchecked until exact green verification. README points to this checklist.

- Location UX source pass: B01/B02/B04/B05/B06/B07 simplified homepage, separate community cards and persistent provincial picker, local Communities page, mobile pin, breadcrumb and active-portal invitation. Old public location-search UI removed; location API behavior retained. B03 remaining: consistent direct module browsing and explicit query selection persistence; do not mark fully complete yet. CI pending.

- A04 source implementation: shared registration/reset/change password minimum now eight characters, matching UI; retain letter/number, confirmation, byte limit, rate limits and revocation. Tests exercise eight-character passwords and seven-character rejection. CI pending.

- 2026-11-26 verified: e65c47a exact backend 45845266174 and browser 45845265991 passed; independent live deployment audit 45845814596 confirmed exact SHA and two releases/21,921 entries. Marked A04, B01/B02/B04/B05/B06/B07/B09, C01/C02, E10 complete in code/CI; physical-device checks remain under A09/L01/M05.

- Current source: A05 default 30-day persistent login on central and local SSO, central opt-out, token revocation on password change, browser restoration/revocation test. B03 applies chosen provincial preference to direct module browsing (preserves filters; explicit GET scope overrides for that visit without mutating preference). Location selector POST remains the only persistent preference write; fixed local scope and APIs unchanged. Exact CI pending.

- Chat redesign source pass: E01 public-only context card and thumbnail, E05 remove private history search endpoint/session/UI, E06 compact clickable identity/back and no footer, E07 viewport history and pinned composer/keyboard handling, E08 no jump or routine status text, E09 icon options and latest received-message reporting when staged reports enabled. Full chat report evidence workflow remains a separate task; no private message browsing added. Exact CI pending.

- F03 and B08 source: initial listing approval 30 days, elapsed previously published listing approval 7 days, re-review before original expiry does not extend it; retain renewal moderation. Shared publication notice on Marketplace, business profiles/updates, discussion/event and job creation/reuse. Exact CI pending. A05 and B03 implementation SHA 8d38ec71 backend 45849119706 and browser 45849119721 passed; live deployment verification remains outstanding for these new changes.

- F01 source foundation: global required markers beside label text, native missing-field error highlighting, unambiguous server field associations and dynamic required/disabled state. No server validation removed. Complex conditional/admin forms and real-device/no-JS fallback require separate manual acceptance before marking complete.

- Staged Chromium regression 45849402835 reached archive menu and timed out on renamed action. Restored descriptive existing label `Archive and mark read` because archive acknowledges unread messages. Keep all staged feature QA intact; rerun exact CI.

- Renewal backend CI 45849726010 stopped at Pint class attribute separation. Moved new regression before private listing fixture helper, removed trailing blank line. Chat backend CI 45849402762 passed. Latest release must pass all checks before promotion.

## Manual actions — last (consolidated owner queue)

Keep this queue updated; do not interrupt independent implementation for these actions. Complete automated preparation first. Do not mark a dependent feature complete until the remaining action and verification are recorded.

- A06: production SMTP credentials and monitored support address/real delivered-email acceptance; prepare templates, diagnostics, documentation and test coverage first.
- A09/L01/M05: physical desktop/mobile/PWA installation, keyboard, cross-host login and real-world acceptance; finish automated browser coverage first.
- G01/G02, J01/K01/K02: owner/legal/privacy and staged schema/flag activation decisions; complete code, migration rehearsals, rollback/backup plan and evidence first.
- L02: weather provider account/key and terms approval; prepare integration and diagnostic steps first.
- M01/M04: approve policies, external Search Console/analytics accounts, branding and community launch promotion; prepare drafts, configuration and metadata first.
- N02: commercial/payment credentials and live payment approval (post-launch optional); sandbox and safe verification first.
- M05: public access, registration and indexing approval only after the full launch checklist and outstanding manual gates are satisfied.

- Current B12 source pass removes six public-detail forced redirects (Marketplace already stays local); reply deep links use current host. Canonical metadata preserved. Full cross-module regression test and link audit pending. Previous backend SHA 7a67fcad passed 45850016351; browser 45850016419 failed because required marker changed Playwright exact password label text. Staged browser login now uses stable password input selector, preserving the actual required marker and native validation. Latest CI pending.

- B12 regression update: existing publication tests now assert provincial 200 detail responses for discussions, events, single-city businesses, jobs, news and staged properties, same shared approved reply on both hosts, and current-host paginated reply permalink. Preserve prior private/hidden scope checks and canonical sitemap assertions. Exact CI pending.

- D01/D02 source: approved discussion notifications show the real public display name and action; opening from either dashboard/list submits a CSRF-protected POST, locks/revalidates the owned approved scoped row, marks only that notification read idempotently and returns a 303 to the current-host reply permalink. No user-supplied destination. Hidden, foreign-recipient and wrong-portal regression tests; other notification event types remain D01 work. Exact CI pending.

- B12 CI first attempts stopped at Pint on reply permalink formatting. Restored established explicit trusted host URL construction using the request's resolved site, not the content canonical site; still same-domain and no untrusted destination.

- B12/D02 browser regression: open approved unread discussion notification on Manitoba with current-host reply fragment, verify same public discussion on Brandon without redirect, verify exactly one notification read and restore the fixture. Updated legacy two-host smoke assumptions to expect mirrored Marketplace and local Communities; legacy full smoke remains separate from automatic browser suite.

- Updated README, architecture, community work plan, news and testing guidance to explicitly distinguish canonical SEO metadata from same-host human navigation. All future development sessions must follow B12 and the manual-actions-last policy.

- C06/C07 staged source: approved linked discussions show Go to event, pending linked event displays disabled Review pending with no hidden event fields/ID, rejected events show no link and cancelled ones retain a notice. Calendar and forum agenda titles open authoritative same-host event details; separate discussion link appears only if publicly eligible. Added full staged schema regression on both domains and flag-off behavior; no production migration/flag activation. CI pending.

- Verified 2026-11-26: exact 2a679b17 full backend 45852884505 and Chromium 45852884447 passed; 52b83a89 also passed both checks (45852763041 / 45852762926). Marked B08/B12/D02/E01/E05/E06/E08/F03 implemented and CI verified, each with individual evidence. E07 physical keyboard/scroll acceptance deferred in manual queue; E09 full chat report workflow, F01 wider form acceptance, D01 additional event types and C06/C07 staged feature verification remain open. Last independently verified live SHA remains e65c47a (45845814596); do not confuse successful older CI or dispatcher with current live deployment. Current event source 1df28797 awaits exact verification. No owner input is needed for continuing independently doable work.

- Last-run verification: bf5255f3 backend 45853038145/browser 45853038312 both green. Event change 1df28797 and checklist SHA 5d3f8b41 passed Chromium but backend failed one existing ContentReportsTest assertion on the deliberately renamed Go to event button (179 tests, 3907 assertions, one failure). Updated the old assertion, retaining independent approval/cancellation and report privacy tests. Exact release verification pending.

- A01/A02/A03 source preparation: proposed migration 000015 and default-off signup_locations flag; host-specific local register form and POST, provincial required selectable location, fixed local location enforced server-side, public display disclosure and acknowledgment, one shared user, central signed verification, profile label and password-confirmed export. Legacy accounts remain unknown. No schema applied to production and no registration/email flag changed. Local registration may require one central sign-in for verification; cross-host real acceptance remains A09. CI pending.
- Manual queue addition A01/A02/A03/K01: review public signup location disclosure and legacy-account/privacy handling, approve 000015 with reviewed backup/rehearsal and activate only after exact verification; no owner input required to continue coding.

- Signup location regression suite covers mandatory selectable provincial community and explicit disclosure, local location tampering, central signed verification link, one account across hosts, profile export, legacy no-inference and unchanged prelaunch registration/IP gate. Proposed migration remains default-off; exact CI pending.

- Signup regression privacy check explicitly signs out before inspecting public profile (owner intentionally sees their own private email in account details).

- C03/D01 staged source: immutable parent reply edge (proposed 000016, flag CANADAMB_REPLY_LINKS false), approved same-thread comment reply links (including own), selected composer/cancel, bounded parent excerpt and current-host jump, hidden parents omitted, parent-author notification on approval respecting preferences and deduplication, direct-reply notification text. Existing flat pagination retained; no recursive tree or hidden parent snapshot. No schema activated. Regression and exact CI pending.

- C03/D01 regression covers flag-off behavior, both portals, approved-only parent selector, cross-thread/pending rejection, moderated publication, parent excerpt removal after withdrawal, self-replies, direct notification without following, follower deduplication and opt-out. 000016 remains in manual schema approval queue after independent rehearsal.

- 2026-11-26 verification: 9fe38438 backend 45859510950 and Chromium 45859510972 passed; deployment 45859973414 independent audit explicitly verified live SHA 9fe384382ee37ae683ae926ec44a99be98024325, private backup/cron and prelaunch gates. Registration location and reply migrations remain disabled. Reopened B12 after owner reported actual navigation regression; B13 and M06/M07 added. Found detail layouts incorrectly passing content location as browsing location; centralized request-derived browsing scope, removed detail content location from header scope and replaced detail breadcrumbs/category links with selected preference. Homepage portal cards now route to Communities instead of directly switching domains. Added branded titles, location-specific detail metadata and prelaunch noindex without removing canonical metadata. Added discussion scope regression; exact CI and broader module audit pending.

- M07 source: super-admin SEO workspace with per-site homepage title and default meta description, current metadata preview, canonical host/robots/sitemap links, optimistic version check and audit. No change to indexing flags. Added role/isolation/stale-update tests. C05/J03 source: independent new-event and companion-discussion published moderation policies, default manual, stricter local restriction and auto-approval audit. Existing content unchanged. Independent event-policy regression and exact CI remain pending. C04 all-day data model and unified final category remain open.

- B13/M06/C05 regression extension: added event detail location isolation, independent event/discussion policy tests (both directions and stricter local override), SEO admin allowlisted test setup, public clean landing canonical metadata and noindex for internal keyword searches. Event composer explains independent policies. Exact CI pending. No production feature activation or owner action.

- First location/SEO backend CI 45860976741 on ba882460: 185 tests / 4020 assertions, two failures. New thread fixture needed explicit forceFill approval; corrected in a1d0c4ea. Existing Marketplace breadcrumb test expected implicit Brandon filtering; replaced with same-host category and no implicit location assertion, retained canonical, local scope and sitemap checks and added branded detail title assertion. Latest exact green CI and independent live verification still outstanding.

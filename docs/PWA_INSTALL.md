# CanadaMB optional installable web app (PWA) — prelaunch

## Current release — 2026-11-21, PWA metadata reachability fix

Active live SHA: `7c86e4cef9986c54e8a72b3ee0fffc3f63a58d32`. The original visitor-approved-IP gate was also blocking the app manifest and icons for **Google's external WebAPK minting service**. We introduced an exact allowlist exception for **GET/HEAD only** on `/app.webmanifest` and `/pwa/icon/{180,192,512}.png`, with a short public cache lifetime and `noindex`. All HTML pages, Marketplace, admin, messages, API and `/sw.js` remain **403 to unapproved IPs**. The public metadata carries only site branding, description and app icons, not accounts, sessions or listings.

- [CI](https://github.com/pixel375/canadamb/actions/runs/44871680397)
- [Deployment](https://github.com/pixel375/canadamb/actions/runs/44871824468)
- [External metadata/privacy audit](https://github.com/pixel375/canadamb/actions/runs/44871914153)
- [Independent postdeploy audit](https://github.com/pixel375/canadamb/actions/runs/44871963432)

After this deploy, a completely unapproved GitHub runner retrieved and validated **both portals' manifests and 192/512px icons (HTTP 200)** and simultaneously confirmed private URLs still returned **HTTP 403**. The private Hostinger backups, original web-root inode and active SHA passed an independent audit. This fixes an independently verified **server-side eligibility obstacle**, but does **not guarantee Google Chrome will mint a WebAPK**; Brave and some browsers intentionally install shortcuts only. Test on current **Google Chrome for Android** from an approved IP and remove previous shortcut first. A WebAPK will appear under Android Settings → Apps and launch without the browser address bar. If Chrome still only creates a shortcut, capture the actual Chrome install dialog for further diagnosis; do not relax the website prelaunch access gate.


## Mobile QA refinement after original rollout

New live SHA: `7702f240537b3e39e7102854f6ed2e17b0b07277`.

After owner screenshots identified a Brave Android shortcut instead of standalone installation, a scoped `/sw.js` was added and registered on enabled PWA pages. It intercepts same-origin GET only to forward them directly to the network via `fetch()`—**no Cache API, precaching, offline pages, token storage or privacy bypass**. The worker endpoint is Laravel-served through the existing prelaunch IP guard. The installer remains browser-controlled; **Brave Android has a [reported PWA-vs-shortcut bug](https://github.com/brave/brave-browser/issues/56133)**. Validate with Android **Chrome** (after removing any old Brave shortcut) and confirm standalone window (no URL bar) and its presence among Android installed apps where supported. Browser install UI is not guaranteed by a website.

This update also fixed mobile profile-icon wrapping and collapsed the overlapping admin navigation into an accessible phone menu. [CI](https://github.com/pixel375/canadamb/actions/runs/44862188182), [deploy](https://github.com/pixel375/canadamb/actions/runs/44862352431), [postdeploy audit](https://github.com/pixel375/canadamb/actions/runs/44862436382).


Live application SHA: 90c36b5c9b56530fa15ebbe2633e23cd8b9916b0

## Verified

- GitHub CI passed: https://github.com/pixel375/canadamb/actions/runs/44849693577
- Guarded Hostinger deployment passed: https://github.com/pixel375/canadamb/actions/runs/44849860885
- Independent live post-deployment audit passed: https://github.com/pixel375/canadamb/actions/runs/44850000025
- Private backups, active SHA, database, public_html directory and IP restrictions were checked. No database migrations.

## Implementation

- On each portal /app.webmanifest supplies the site's own identity, accent colour and 192px/512px PNG icons. An Apple 180px icon is also available.
- Mobile footer Install App is shown only when browser install support is detected. Android Chromium uses beforeinstallprompt after user tap.
- iOS Safari displays Add to Home Screen instructions instead of a fake native prompt.
- A globally published administrator setting controls whether installation is enabled on both domains.
- A minimal, network-only service worker is registered for installability, with **no Cache API or offline storage** of messages, accounts, OAuth, or private pages.
- During prelaunch, the publicly safe manifest and icons are accessible outside the IP list for Android WebAPK packaging; the service worker, homepage and all application content still require the approved IP.

## Manual acceptance remaining

1. Android Chrome: from an allowlisted IP, install CanadaMB and then BrandonMB separately, verifying names, icons, launch and dismissal.
2. iPhone Safari: open footer install guidance, Add to Home Screen and verify standalone state hides CTA.
3. Confirm the CTA is hidden in unsupported browsers, desktop view and installed mode.
4. Verify current mobile header and four-tab bottom navigation within the standalone app.
5. Test IP restriction after mobile public-IP changes: an app does not bypass the access gate.

Tracking: https://github.com/pixel375/canadamb/issues/8

Offline mode, push notifications, background sync, polished final icons and per-city PWA admin overrides remain future work.
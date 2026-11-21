# CanadaMB optional installable web app (PWA) — prelaunch

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
- A service worker is intentionally NOT registered. No offline caching exists for messages, accounts, OAuth, or private pages.
- Existing Hostinger prelaunch IP restrictions also cover the PWA routes.

## Manual acceptance remaining

1. Android Chrome: from an allowlisted IP, install CanadaMB and then BrandonMB separately, verifying names, icons, launch and dismissal.
2. iPhone Safari: open footer install guidance, Add to Home Screen and verify standalone state hides CTA.
3. Confirm the CTA is hidden in unsupported browsers, desktop view and installed mode.
4. Verify current mobile header and four-tab bottom navigation within the standalone app.
5. Test IP restriction after mobile public-IP changes: an app does not bypass the access gate.

Tracking: https://github.com/pixel375/canadamb/issues/8

Offline mode, push notifications, background sync, polished final icons and per-city PWA admin overrides remain future work.
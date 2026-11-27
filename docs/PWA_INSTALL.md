# CanadaMB optional installable web app (PWA) — prelaunch

## November 21 follow-up — dark native bars and blue launcher icon (pending deployment)

Real owner screenshots showed a dark navy header/foot navigation but a pink
Android WebAPK icon and pink system-bar areas. **The first theme-color change was
only merged to the implementation branch, not deployed**; the public production
release was still `7c86e4cef9986c54e8a72b3ee0fffc3f63a58d32`.

This follow-up defaults the installed manifest's native theme and splash to the
published dark header/page (`#121C2A`/`#0E1622` for the default palette). The
per-page `theme-color` meta value still synchronizes with the visitor's
System/Light/Dark preference. CanadaMB launcher icon now uses the existing
blue (`#75BFFF`) and navy (`#112339`) header-brand colors instead of the
rainbow gradient's first pink accent; BrandonMB keeps its distinct green icon.
The icon URLs now carry `?v=2` to refresh cached artwork, without changing
the manifest's URL or install identity. PWA code, private prelaunch gating,
route scope, DB schema and user sessions remain unchanged.

**To make this visible:** merge after CI; run the trusted manual Hostinger
deployment on the exact green implementation SHA with database changes OFF;
verify private backups, postdeploy audit and external PWA manifest/icon
metadata; then uninstall the old Android CanadaMB WebAPK and reinstall it from
Chrome if the pink OS bars/launcher icon persist. Chrome can delay updates to
an already-minted WebAPK, and some Chrome/Android versions do not apply
page-level dynamic theme changes to the bottom system gesture area; verify on
actual device rather than promising guaranteed OS behavior.

## Chrome Android visual refinement — pending deployment

Owner confirms real PWA installation works from Google Chrome on Android. The old
manifest and HTML theme colors used each portal's brand accent, causing bright
pink (CanadaMB) or green (BrandonMB) system areas above/below the app. This
fix sets the manifest's theme color from the globally published **light header**
(and splash background from the light page), while the page-level theme meta
automatically follows the published **light/dark header** colors and the user's
System/Light/Dark selection. Icons and decorative accent lines retain their
portal-specific branding. No service worker, route, IP allowlist, auth or schema
changes are needed.

After CI and deliberate Hostinger deployment: test standalone Google Chrome on
Android in Light, Dark and System modes, including toggling OS appearance while
the app remains open. Check the top status bar and bottom Android navigation
area, the app's own four-tab bar and contrast of system icons. Previously minted
WebAPKs can retain an old manifest theme until Chrome refreshes the package;
remove/reinstall the old install if the new theme is not picked up. Android/
Chrome versions may differ in their ability to re-theme the OS's bottom gesture
area; don't treat a browser-controlled limitation as an app install failure.

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
## Optional Web Push activation and key rotation (D03, staged)

Do not activate during prelaunch. Device registration and delivery are independent flags and require their reviewed proposed migrations (000018 and 000024). Confirm the production scheduler, HTTPS, PHP OpenSSL/cURL, provider reachability and the matching P-256 key pair. Keep the delivery flag off until explicit approval and disposable-device testing. No administrator page accepts a private key.

Generate a new key only on a trusted private machine, never inside the repository or a logged CI step. The following local PHP CLI script writes the environment fragment to a newly created owner-only file; it refuses to overwrite an existing file. Run on a Unix-like system with PHP OpenSSL, from a private directory outside the web root. Replace the operator contact privately before installing the values through the hosting environment.

```php
<?php
umask(0077);
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
if (!$key || !openssl_pkey_export($key, $pem)) {
    throw new RuntimeException('Key generation failed');
}
$ec = openssl_pkey_get_details($key)['ec'];
$point = chr(4).str_pad($ec['x'], 32, chr(0), STR_PAD_LEFT).str_pad($ec['y'], 32, chr(0), STR_PAD_LEFT);
$public = rtrim(strtr(base64_encode($point), '+/', '-_'), '=');
$file = fopen('private-vapid.env', 'x');
if (!$file) {
    throw new RuntimeException('Refusing to overwrite an existing key');
}
fwrite($file, "CANADAMB_PUSH_PUBLIC_KEY=$public\nCANADAMB_PUSH_PRIVATE_KEY=".base64_encode($pem)."\nCANADAMB_PUSH_SUBJECT=mailto:operator@example.invalid\n");
fclose($file);
```

Store the key in protected hosting configuration and an encrypted backup, not in Git, web root, screenshots or chat. Do not print or upload the generated fragment. Install real operator mailto contact, refresh configuration using the normal deployment procedure, and check only the matched/not-ready indicator in admin Notifications. Remove the temporary plaintext fragment from the private machine after secure storage. Keep the old key protected until the rotation decision is final.

For rotation, disable delivery first. Install the new matching pair, invalidate old server registrations through an approved maintenance procedure, then have members explicitly unsubscribe and re-register on each portal/device. The browser rejects mismatched or unavailable application keys; it never silently removes a subscription. Do not silently send a registration for another account. Verify logout and account revocation, separate site registrations, failed permission, old key mismatch, unsubscribe and permission revocation, generic text and current-host notification click, Android installed/browser, supported desktop and iOS installed Home Screen app. Provider acceptance does not imply display or reading. Empty-body notifications are coalesced, best-effort and may be delayed or lost; no private content is included. Monitor due checks, uninitialized devices, failures and retry counts. DNS resolution and IPv4-only provider transport remain hosting compatibility checks. No physical device acceptance or live key has been completed.

# Marketplace and visual UX feedback — 2026-11-19

Source: the owner's hands-on testing of several disposable listings on `canadamb.ca` and `brandonmb.ca`, plus the provided saved HTML/CSS snapshots of the provincial home and a Brandon listing. **Record now; implement in a separate pass after deployment/reliability essentials.** Do not represent proposed work as implemented.

## Implementation pass after scheduler verification (2026-11-19)

The earlier rows document owner-reported defects at the live UX checkpoint. A **first code pass** on `implementation/network-foundation` now addresses MKT-01/02/03/04/05/07/08/09:
- listing-card image is a named keyboard-accessible link; cards show complete photos via `contain` rather than cropping;
- listing details serve the full-size stored WebP as the main image, with a native `dialog` lightbox, Escape/arrow keyboard controls, optional touch swipe, focus return, image-count display and normal image-link fallback;
- province-side browsing of visible Brandon listings no longer redirects out of the current domain; the `<link rel=canonical>` still points to Brandon for one indexed listing URL; Brandon's location visibility remains restricted;
- category labels filter the current portal, seller actions distinguish guests, buyers and owners, and desktop/mobile related/recent cards use the same geographic visibility scope.

**Verification:** PHP view and portal canonical assertions were added; media tests cover a portrait/landscape two-photo upload, larger display source, and replacement/deletion on edit. Code must pass CI and be deployed separately. **Pending:** actual phone/desktop browser, keyboard, real photo orientation, max-five/failure-path uploads, and SSO tests on approved IPs. UX-01 major visual overhaul remains later.

## Verified defects and feature requests

| ID | Priority | Area / observed problem | Evidence in code or saved page | Proposed acceptance criteria |
| --- | --- | --- | --- | --- |
| MKT-01 | P1 | Homepage/list cards only clickable via title; image isn't linked. | `resources/views/components/listing-card.blade.php` renders image in a `div` and links only the title. | Image is keyboard-accessible link to identical listing as title; no nested anchors; works on provincial and city cards. |
| MKT-02 | P1 | Homepage/list images heavily cropped. | Saved CSS uses `.listing-image img { object-fit: cover }`, `aspect-ratio: 2`. | Review fit-vs-cover with real portrait/landscape photos. Prefer complete-item visibility (`contain` with neutral background) for classified cards, preserving consistent heights; verify responsive layouts and no ugly distortion. |
| MKT-03 | P1 / UX + SEO | Clicking a Brandon listing from CanadaMB performs a 301 to BrandonMB, taking users out of their current site. | `MarketplaceController::show()` redirects to canonical site when current site differs. | Decision: **prefer retaining the visitor's current domain for in-site browsing** while publishing one canonical URL for indexing/sharing (Brandon for a Brandon record). Do not duplicate records, loosen city visibility rules, or create competing indexable URLs. Test direct links, navigation, canonical tag, redirects, SEO/sitemaps and SSO; review implications before implementation. |
| MKT-04 | P1 | Listing-detail pictures are cropped and blurry. | Detail template renders the `/thumbnail/` endpoint (480px generated) in the gallery. CSS uses `object-fit: cover` at 4:3. The photo pipeline also generates a 1600px larger WebP. | Main detail gallery uses suitable large image, displays full composition (`contain`) and crisp scaling on Retina; thumbnails only for thumbnails. Verify all aspect ratios, large photos, mobile and storage authorization. |
| MKT-05 | P1 | Clicking a picture opens a full-page standalone image, not a lightbox. | Detail template wraps thumbnail in a link to `/photo/` route. | Accessible modal/lightbox with close, previous/next, current count, keyboard Escape/arrows, focus handling, touch/swipe where appropriate and no page scroll leakage. Provide a safe non-JS fallback. |
| MKT-06 | P1 test gap | Multi-image handling not yet user-tested. | Upload service iterates `files`; detail view loops `media`, but end-to-end multi-image UX is unverified. | Test 1/2/maximum images, ordering, photo replacement on edit, failed uploads, image orientation, authorized access, touch gallery, cache and cleanup. Do not claim multi-image is fully functional until tested. |
| MKT-07 | P2 | Listing category label is not clickable. | Detail view renders `categoryRecord->name` as plain text. Marketplace already accepts `?category=slug`. | Category label links to `/marketplace?category=<slug>` on current portal, honors city scope, preserves useful navigation context. |
| MKT-08 | P1 | Listing detail page looks unfinished; needs seller/contact area. | Detail view is a single narrow panel, only `Listed by …`. Message form exists **only for signed-in non-owners**; own listing hides it by design. | Dedicated seller card (identity, verified/profile/badges only when truthful, join date if permitted), prominent in-app contact CTA for other users, owner edit/manage/sold actions, appropriate guest sign-in prompt, privacy controls and mobile layout. Do not expose private contact data by default. |
| MKT-09 | P2 | No sidebar/recommendations or recent listings on detail page. | No related/recent listing query or components in current detail template. | Desktop content + related items/recent listing sidebar; responsive mobile stacking, empty states, accurate city filters, no N+1 queries and no promotion leakage. |
| UX-01 | Later design pass | Homepage and theme feel dated, not polished/trustworthy. | Owner report and saved current-page capture; design references to follow. | Professional responsive information architecture, richer hierarchy, strong typography and spacing, coherent light/dark tokens, attractive listing/media presentation, high accessibility and performance. Preserve provincial rainbow and Brandon green accent. |

## Product/design decisions to settle in the implementation pass
- **Browsing vs canonical:** A city listing displayed on CanadaMB should be readable on CanadaMB without forcing the visitor to another domain. Canonical metadata and sitemap should still point to the dedicated city portal where available. SEO must avoid indexable duplicates. Validate with search-engine guidelines and actual HTML responses; do not use bot-specific redirects.
- **Photos:** Use `contain` for item accuracy (especially vehicles/furniture); use attractive background treatment rather than hard cropping. Provide full-resolution image inside detail viewer and optional separate thumbnails. Audit WebP conversion quality, portrait/landscape, EXIF orientation and image serving cache.
- **Seller identity:** Author's own listing shows management controls instead of a message-to-self form; other authenticated visitors see messaging. Build UI with clear states and a profile, without leaking phone/email unless seller explicitly opts into sharing.
- **Related content:** Defaults must respect Brandon-only browsing and province-wide filters. Keep suggestions relevant and moderated.
- **Visual references:** Owner intends to provide further screenshots/references; the uploaded saved HTML pages are **current-state examples**, not approved redesign comps.

## Suggested implementation order (after infrastructure)
1. MKT-01, MKT-04, MKT-05 and MKT-06 image/click/lightbox fixes; add browser tests.
2. MKT-03 original-site browsing with consistent canonical and location/SSO tests; MKT-07 category link.
3. MKT-08 seller/contact card and owner states; MKT-09 related/recent responsive sections.
4. UX-01 redesign once core interactions, data and layout constraints are solid.

## Regression checklist
- Test from both real hostnames and localhost hosts with stable IDs, at phone/tablet/desktop widths, keyboard-only and touch.
- Confirm uploads of single and multiple photos survive deployments; source files and private storage never enter `public_html`.
- Confirm public listing visibility, moderation states, current-site navigation, owned vs third-party CTA, read-only guest pages, canonical tags, cross-domain SSO and duplicate indexing policy.
- Capture before/after screenshots and performance metrics. No production CSS/UI update is included in this note.

# Private property drafts

Enable CANADAMB_PROPERTY_DRAFTS_ENABLED only in an environment authorized to collect private property information. The flag defaults to false. Verified members can create and manage their own sale or rental drafts. The shared location checks constrain Brandon submissions to Brandon. Drafts may contain structured price, room counts, floor area, acreage, year, rental details, an address, a legal land description, and a private photo gallery.

Submitting a draft allows network administrators to request changes or mark it reviewed. Every decision remains private. Property records and photos are outside public pages, public storage, and sitemaps. A change to Marketplace media storage has no effect on the private property disk.

Before enabling any public property advertising, review the intended publishing, verification, consent, and payment flows against current Manitoba law and record the applicable approvals. Paid property promotions and agent subscriptions have no implementation in this release. [Manitoba Real Estate Services Act](https://web2.gov.mb.ca/laws/statutes/ccsm/_pdf.php?cap=R21)


## Public Real Estate browse shell (November 2026)

`/real-estate` is a public, responsive taxonomy and filter page on enabled Marketplace portals. Visitors can explore sale and rental types and geographic browsing controls. Public detail and photo routes are implemented but **fail closed** by default: `CANADAMB_REAL_ESTATE_PUBLIC_LISTINGS=false` means no property records, private addresses, legal land descriptions, drafts, photos or owner data can be retrieved through them, even when a draft has been marked reviewed. Public records would require both an explicit `published` status (not available through any current owner/admin endpoint) **and** the separate deployment flag.

The `/account/properties` and `/admin/properties` workflows remain private and unchanged. No publication action, paid promotions, public seller contact, syndication, or listing importer has been enabled. The public browse shell is not a claim that the advertisement channel has launched.

Before implementing any publishing action or turning on the flag, obtain documented regulatory/legal assessment for Manitoba's Real Estate Services Act, appropriate owner/authorized-agent written advertising consent, brokerage registration/identity verification and mandatory ad disclosures, compliance moderation, address/privacy policy, expiry/takedown mechanisms, and secure media review. No unreviewed draft should be promoted by changing its status manually.

Relevant law: https://web2.gov.mb.ca/laws/statutes/ccsm/r021.php?lang=en (notably sections 35-37).

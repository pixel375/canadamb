# Private property drafts

Enable CANADAMB_PROPERTY_DRAFTS_ENABLED only in an environment authorized to collect private property information. The flag defaults to false. Verified members can create and manage their own sale or rental drafts. The shared location checks constrain Brandon submissions to Brandon. Drafts may contain structured price, room counts, floor area, acreage, year, rental details, an address, a legal land description, and a private photo gallery.

Submitting a draft allows network administrators to request changes or mark it reviewed. Every decision remains private. Property records and photos are outside public pages, public storage, and sitemaps. A change to Marketplace media storage has no effect on the private property disk.

Before enabling any public property advertising, review the intended publishing, verification, consent, and payment flows against current Manitoba law and record the applicable approvals. Paid property promotions and agent subscriptions have no implementation in this release. [Manitoba Real Estate Services Act](https://web2.gov.mb.ca/laws/statutes/ccsm/_pdf.php?cap=R21)


## Public Marketplace discovery does not publish real estate

The category chooser in stacked [draft PR #29](https://github.com/pixel375/canadamb/pull/29) shows a non-clickable **Real Estate — Coming later** card so visitors can understand the planned offering. This is **not** a `listing_categories` row, public posting form option, public property listing, saved search, property promotion or change to `CANADAMB_PROPERTY_DRAFTS_ENABLED`. Historic ordinary Marketplace slugs retain their meaning. Property sale/rental listings and agent-related monetization still require a separate compliance, permissions, marketing, moderation and launch review. Do not imply that test-only private property drafts can be viewed or posted publicly.

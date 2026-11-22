# Discussions

## URLs and portal scope

| Legacy URL | Current handling |
| --- | --- |
| GET `/community` | 301 to `/discussions` on the same portal, with valid supported filters. |
| GET `/community/new` | 301 to `/discussions/new` after verification. |
| GET `/community/{id}` | 301 to `/discussions/{id}` on the same portal, retaining a valid page. |
| GET `/community/replies/{id}` | 301 to `/discussions/replies/{id}` on the same portal. The permalink opens the current page and exact reply anchor. |
| POST `/community`, POST `/community/{id}/replies`, POST or DELETE `/community/{id}/follow` | The original method reaches the shared verified write action. It retains CSRF, rate limiting and content authorization. |

CanadaMB serves Brandon topics in place and identifies the eligible city URL as canonical. Detail URLs in the sitemap belong to the canonical host. BrandonMB shows only Brandon discussions. A topic or reply must be accessible on the current portal for either public URL to resolve. Discussion sign-in takes the visitor through the existing central flow and back to the original topic.

The browser filters by title, category, and recent activity or newest. CanadaMB also offers Manitoba locations. Title search treats SQL wildcards literally and is limited to 30 requests per minute per signed-in member or guest IP across the old and current URLs. Public replies are ordered by ID and paginated 25 at a time. The existing index on `community_replies(thread_id, status, id)` supports the count of approved replies before a permalink's target. In the disposable MariaDB 11.4 rehearsal with 2,505 interleaved approved and pending replies, the anchor-count query used this covering range index. The ordered page query also considered that index and chose the primary index without a filesort for that distribution.

## Author and moderator controls

Open **My Discussions** at `/account/discussions` to manage your posts on the current portal. Pending, rejected and withdrawn posts can be read in the owner's private preview. The latest safe rejection reason appears when available. Internal notes and report details stay in moderation.

The original location stays fixed. A moderator lock suspends author changes, including withdrawal and restoration. A material edit to an approved post returns it for moderation, except when the published approval policy allows immediate approval. Pending edits stay pending. Previously rejected posts require a material revision and manual review. An unlocked pending or approved post can be withdrawn. Explicitly restoring a withdrawn post places it in manual review. Stable IDs retain replies, follows, reports, and earlier audit history.

Owner writes and moderation use the same required revision and lock the thread. A stale decision returns a conflict and requires a fresh review. Reply approval also verifies the parent discussion revision. A changed status produces an owner notice linked to the recorded transition. Follower notices are delivered once when a reply is approved. Earlier reply notices keep their original read states and use the new links. Historical approval outcomes remain visible to their recipients, with public links available while the approved content is accessible.

When a discussion with reports is edited, the prior public text is sealed in the existing audit store before the edit commits. A reviewer opens it through the authorized report detail, and that access is recorded. The archived text is grouped by reported revision. Existing report IDs and the one-report-per-member-per-discussion rule are preserved. Keep the Laravel application key available with the rest of the deployment's protected recovery configuration.

## Release checks

Pass 06 uses the existing schema and adds no migrations. Run the populated Pass 05 rehearsal and verify the preserved records, SQL and private-storage restore, and competing author/moderator updates. The unmerged migrations in the earlier passes still require separate authorization. Keep the public launch, registration, mail, and published approval settings under the existing controls.

On authorized Android and iOS devices, verify My Discussions, pending/rejected preview, city sign-in continuation, keyboard and touch reply controls, old bookmarks and the installed PWA. Keep the existing Events submenu and `/events` routes through this pass. Discussion-originated event requests and the new agenda belong to Pass 07.

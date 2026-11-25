# News ingestion and editorial

Create a source in Admin → News editorial with its HTTPS hostname, geographic coverage and summary reuse permissions. Only enabled sources with approved summary reuse can produce a draft. Editors can write a draft manually or receive a draft through the authenticated endpoint. Every publication needs a documented review and a current article version. The editorial queue supports keyword, source and status filters. Network administrators can revise source reuse rights; existing articles require separate review. Safety and weather stories, and any story marked sensitive, also need an independent corroborating link. Publication and withdrawal preserve revision snapshots. Published pages provide source attribution and use the canonical community domain when eligible.

## API

POST /api/v1/news/ingest on the central domain. Use an Authorization Bearer header with the secret configured as CANADAMB_NEWS_INGEST_TOKEN and send JSON. Keep the token in the calling system's secret store. The endpoint is off when the token is absent or under 32 characters. Rotate the secret through deployment configuration when required.

| Field | Required |
| --- | --- |
| source_id | Yes, an approved source ID |
| external_key | Yes, stable identifier for the upstream item |
| source_url | Yes, HTTPS URL on the approved source hostname |
| source_date | Yes, YYYY-MM-DD |
| title and body | Yes, original draft summary |
| category | Yes, a configured category key such as municipal |
| location_id | Optional within the source coverage |
| sensitive | Optional boolean, with stronger rules for designated categories |

New drafts return 201. Exact resubmissions return 200 with the existing record ID and current status. Reused identifiers or source URLs with conflicting data return 409. Invalid content or coverage returns 422.

## Scheduled feed discovery

Network administrators configure an optional RSS 2.0 or Atom feed on the approved HTTPS source hostname. Set CANADAMB_NEWS_POLLING=true, rebuild the Laravel config cache, and run `php artisan news:poll` to check due sources. The scheduler checks for due sources every five minutes.

One invocation attempts up to three sources. Successful sources become due again after an hour. Failures back off for 15, 30, 60, and then 120 minutes. Recent source health and candidate counts are visible in the editorial dashboard. Polling reads at most 1 MiB and examines at most 100 entries per feed. The HTTP transfer takes at most five seconds per source or the remaining 15-second batch budget. DNS lookup itself uses the host resolver and can exceed that budget on a slow resolver, so check resolver behavior during hosting validation. The HTTP connection pins checked public IPv4 addresses, verifies TLS, and follows no redirects. IPv6-only sources require a future transport update.

Candidates keep deduplicated links and headline metadata. Publisher article text is discarded, and editors provide the draft summary and publication review. Feed polling is disabled by default. Review-staged candidate dismissal/restoration retains the original metadata and prevents dismissed feed items from becoming drafts through the candidate workflow. Scheduled AI summarization and any ChatGPT Scheduled integration remain open.

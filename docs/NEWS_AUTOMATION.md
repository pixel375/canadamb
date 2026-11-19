# News ingestion and editorial

Create a source in Admin → News editorial with its HTTPS hostname, geographic coverage and summary reuse permissions. Only enabled sources with approved summary reuse can produce a draft. Editors can write a draft manually or receive a draft through the authenticated endpoint. Every publication needs a documented review. Safety and weather stories, and any story marked sensitive, also need an independent corroborating link. Publication and withdrawal preserve revision snapshots. Published pages provide source attribution and use the canonical community domain when eligible.

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

Server-side feed polling, automated extraction and an AI provider adapter remain open. The endpoint can support an external authenticated sender. A ChatGPT Scheduled workflow has not been configured or verified.

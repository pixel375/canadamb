# Discussion events and calendar

Events opens from the Discussions menu on desktop and mobile. The agenda at `/events` groups eligible events by local day in America/Winnipeg. Day groups can be opened independently by keyboard or touch. The default view starts today, and an explicit month includes historical entries. The day view paginates busy dates. Overlap is calculated using each day's local midnight and an exclusive end, so a midnight finish belongs only to the preceding day. Single-start events appear on their start day. Month views retain up to eight cards per day with a link to the full list and its exact count.

## Request and review

1. Open an approved, unlocked discussion you own. Choose **Request event listing** in Discussion options.
2. Enter the schedule, category, organizer and venue. An optional event link must use HTTPS. The discussion provides the full event description and replies. Its location is fixed. For a province-wide discussion, choose a Manitoba location on CanadaMB. That event remains on CanadaMB because its source does.
3. The start must be in the future within one year. An optional end must be after the start and within seven days. Enter times in America/Winnipeg. Nonexistent or ambiguous DST times require a different time.
4. The usual review setting is manual. An eligible initial request can use an explicitly published automatic policy. Edits and resubmissions go through manual review. A source edit, withdrawal, rejection or lock requeues an approved event. The discussion must become eligible again and a reviewer must approve the event separately.
5. **My events** lists both linked and historical standalone events. Owners can edit or resubmit eligible linked events and cancel an approved request or withdraw a pending request. A cancelled linked event can appear with a cancellation label while its discussion remains eligible. Cancellation notes are private.

One event ID survives resubmission. The database enforces one link per discussion. Linked writes lock the discussion before the event and verify both versions when applicable. The workbench shows the source discussion and event metadata. Reviewers can approve, reject, cancel or revise metadata. Linked events are reviewed individually. Report and decision records retain their event IDs. Previously reported text and schedule details are sealed in the existing audit store before changes, and report detail access is recorded.

## Public routes

| Route | Behavior |
| --- | --- |
| `GET /events` | Vertical agenda. Optional month, day, title and category filters. CanadaMB also supports location filtering. |
| `GET /events/{id}` | Historic standalone detail keeps its original URL and canonical. A linked event opens its source discussion on the current eligible host. |
| `GET /events/new` | A verified member receives instructions and links to their discussions. |
| `POST /events` | An older HTML form redirects to those instructions. JSON receives a validation error. The existing authentication, verification and CSRF checks apply. |
| `GET/POST /discussions/{id}/event` | Request form and submission for the verified owner of an eligible source. |
| `GET /account/events` | Owner management list. Linked edit and versioned cancellation are available from here. |

A Brandon visitor sees only Brandon events whose source is accessible there. The CanadaMB agenda keeps visitors on CanadaMB when opening a discussion. Linked event structured data is emitted on the eligible canonical discussion only. Legacy cancelled detail remains available with a cancellation label and noindex. Public reports and owner notices use the same geographic and source visibility checks.

## Schema and recovery

`2026_11_22_000003_link_community_events_to_threads.php` adds a nullable unique `community_events.thread_id` with a restrictive foreign key. Existing rows have a null link and retain their IDs, authors, dates, body, cancellation and review states. A rollback with linked rows is refused. MariaDB can commit DDL implicitly. Preserve verified SQL, private media, and application and OAuth key backups and rehearse restore before an approved production migration.

## Allowlisted acceptance after release authorization

- On both portals, check a linked event, a multi-day event, and a standalone bookmark. Verify date counts, month navigation, source links and the Brandon scope.
- On a physical phone and installed PWA, use the overflow menu, submit an event request, open the calendar day and return to its discussion in light and dark modes.
- Approve the event, edit its source and check that a second event review is required. Verify old notices, private cancellation notes and reported metadata.
- Confirm the existing prelaunch IP and indexing controls. Keep the production migration setting at its default until the exact schema and recovery plan have been approved.

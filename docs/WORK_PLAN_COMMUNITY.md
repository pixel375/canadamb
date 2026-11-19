# Forums and events implementation plan

1. Test location validation, draft visibility, moderator permissions, canonical redirects, reply notifications and Canadian daylight-saving behavior through HTTP.
2. Store forum threads, approved replies, following, and read state in the shared database. Use central moderation with explicit actions.
3. Build local event submissions, Manitoba timezone validation, event calendars and detail pages. Use the same central moderation and location lookup.
4. Publish module controls in the existing settings UI and add recent activity to the location-aware homepage.
5. Verify SQLite and MariaDB migrations, web routes, view compilation and full test suites.

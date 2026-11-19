# News feed polling implementation plan

1. Run workflow tests with RSS and Atom fixtures, test first for discovery, duplicate URLs, failure backoff, and source configuration permissions.
2. Add an optional feed URL and database-backed check history. Register a cron-compatible command behind the environment flag.
3. Parse bounded XML responses from same-host HTTPS feeds. Pin resolved public addresses during fetch. Record original links and titles in a private candidate list.
4. Let an editor open a candidate and write a summary through the existing draft workflow. Continue to require explicit review for publication.
5. Run the full suite, migration and deployment checks. Keep external polling disabled in the sample production configuration.

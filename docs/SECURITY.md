# Security

Validate the request host against configured domains before session handling. Trust only explicitly configured proxy addresses and omit forwarded host from proxy headers. Keep CSRF validation on browser write routes and use exact same-origin requests. Laravel Blade escapes user-provided content. Marketplace photos are validated, decoded and re-encoded as WebP before storage. Public photo delivery checks listing visibility and geographic scope.

Use independent host-only sessions and Passport PKCE for cross-domain sign-in. Use HTTPS, database sessions, secure cookies and private key files on the host. Use Laravel's hashed password storage and Fortify rate limiting. Keep account credentials, claims and messages private. Review relevant auditing, retention and account controls before promoting the system to production.

Current launch gates include SMTP verification, admin MFA, abuse and privacy policy work, account lifecycle controls, plan-specific hosting verification, and restoration of a production-shaped backup in staging.

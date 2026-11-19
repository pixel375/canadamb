# Authentication

Fortify provides centralized registration, credentials, password reset and email verification. Passport supplies Authorization Code with S256 PKCE. Open a local portal's sign-in link to initiate a state-bound exchange with CanadaMB. Successful authorization establishes a separate browser session on the local hostname. The exchange consumes the authorization code and immediately revokes the access and refresh tokens after validating the user and local client.

Keep cookie Domain unset. Use Secure and HttpOnly with SameSite=Lax over real TLS. Configure APP_URL to the central hostname and keep Laravel and Passport private keys stable. Run passport:keys once per environment, then run network:sync-sso. Protected local forms send unverified users to CanadaMB to verify their email. A user can sign out everywhere from My account. Password reset also invalidates earlier session versions. Enable SMTP and test real verification and reset email before launch.

Backend gates distinguish members, network administrators and super administrators. Businesses have membership roles for scoped editing. City moderator assignments, MFA setup and account export/deletion are pending.

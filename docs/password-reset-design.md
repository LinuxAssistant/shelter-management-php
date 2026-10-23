# Password reset design

Forgot-password and administrator-reset routes issue 32-byte random bearer tokens. Only SHA-256 digests are stored in password_reset_tokens; tokens expire after 30 minutes. Issuing a new token replaces earlier tokens for that user.

Issuance, self-service password changes, account edits, and token consumption lock the user row before mutating token rows. Consumption rechecks the token after acquiring the user lock and atomically changes the password, increments session_version, removes pending tokens, and records an audit event. Concurrent consumption succeeds once. Self-service password changes verify the current session version under the lock.

Issuance checks that the saved recovery email still matches the address selected by the sender. Failed mail submission deletes only the corresponding token digest. Password changes and account email/status changes revoke all pending links.

All authenticated requests reject inactive accounts and mismatched session versions. The administrator reset route is POST-only and requires admin.manage plus CSRF verification.

## Deployment review

Use HTTPS and secure session cookies. Configure the web server, reverse proxy, monitoring, and analytics to omit reset query strings; the application sets no-store and no-referrer on reset pages. A bearer token in a retained access log can grant account access.

password_security_events contains event names, internal actor/subject IDs, and timestamps only. It does not store passwords, token digests, addresses, email bodies, or IP addresses. Restrict access and establish retention for these account records.

Login, password-change, public recovery, and administrator-reset limits are database-backed. Production mail delivery, timing-based enumeration, distributed abuse, proxy/IP handling, retention, and independent security review remain deployment acceptance work.

See password-management.md for migration order and mail configuration.

# Password management

Draft PR #4 implements self-service password changes, administrator-initiated recovery email, public forgot-password requests, expiring single-use reset links, session revocation, throttling, and password-security audit events.

## Database preparation

On an existing installation, check migration history first. Apply each missing migration exactly once, in this order:

- 004_account_sessions.sql
- 005_password_reset_tokens.sql
- 006_auth_rate_limits.sql
- 016_password_security_events.sql

Migration 016 uses a later number because PR #6 reserves 007-015 for platform features; it depends only on the authentication migrations above. Do not import a clean-install schema over an existing database. Deploy migrations before the new PHP code.

Do not merge PR #4 into PR #6 yet. Integration must preserve the platform routes and permission checks, add authentication tables to its consolidated clean-install schema, and update its schema smoke test.

## Account actions

Employees can change their password after providing their current password. Administrators can open an active employee's edit page and select **Send password reset email**. That uses the saved recovery address; unsaved form changes are not used.

New passwords require at least 12 Unicode characters, no null bytes, valid UTF-8, and at most 72 UTF-8 bytes. This avoids bcrypt truncation. The same rule applies to creation, self-service changes, and recovery.

Password changes and resets invalidate all login sessions and outstanding recovery links. Changing the employee email or active status also revokes sessions and links. Reactivating an employee does not restore an old login session.

## Mail configuration

Recovery email is disabled by default. Configure APP_URL with the public HTTPS application base URL, MAIL_FROM with the sending address, and PASSWORD_RESET_EMAIL_ENABLED=true only after verifying the server mail transport. PHP mail() must be correctly configured; acceptance by the local mail transport is not proof of delivery.

Public recovery requests always show the same response for absent, inactive, throttled, or valid accounts. Administrator requests report disabled or failed delivery. Failed mail submission revokes the newly issued token.

## Verification

Run composer install, then python3 tests/password-integration.py on Linux as an unprivileged user. Requires PHP 8.4+ with mysqli, Python 3, and MariaDB server/client tools. See tests/README.md.

The isolated suite passed on Dell with fictional accounts and captured local email. It covers reset forms/routes, permission denial, CSRF, current-password checks, password policy, expired/replaced/reused tokens, simultaneous issuance and consumption, session invalidation, account changes, throttling, and audit events.

Chromium and Firefox browser acceptance also passed, including mobile layout. Before marking ready: verify production mail/HTTPS without real resident data, confirm the GitHub Actions integration run passes, and complete an independent security review. Keep PR #4 draft.

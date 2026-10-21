# Password reset design (work in progress)

Migration 005 creates `password_reset_tokens`. `app/PasswordResetTokens.php` contains functions for generating a cryptographically random bearer token, storing only its SHA-256 digest, expiring it after 30 minutes, and atomically consuming it to update the password and revoke prior sessions.

**Important:** There is deliberately no publicly accessible reset-request endpoint yet. Email verification and delivery, request throttling, non-enumerating responses, and the reset form must be implemented before enabling account recovery. Never expose the returned raw token in a webpage, URL log, application log, or GitHub repository. Do not use this as a production password-reset system yet.

After updating the branch, apply migration 005 exactly once:

```bash
mariadb -u shelter_app -p shelter_management < database/migrations/005_password_reset_tokens.sql
```

Migration 004 is also required before this migration's reset consumption logic can run, because it increments `users.session_version`.

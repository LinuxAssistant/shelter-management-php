# Password management — first milestone

This draft PR implements **self-service password changes only**. Administrator-initiated resets, forgotten-password emails, reset tokens, rate limiting, and global session revocation are **not yet implemented**.

## Manual test

1. Sign in as a fictional employee and open **Change my password** on the dashboard.
2. Enter a wrong current password: the password must remain unchanged.
3. Enter mismatched new passwords or a password shorter than 12 characters: reject the request.
4. Enter the correct current password and a new password of 12+ characters: see the success page and be signed out.
5. Confirm the old password fails at login and the new password succeeds.
6. Check the GitHub Actions PHP lint workflow.

Apply migration 004 before testing:\n\n```bash\nmariadb -u shelter_app -p shelter_management < database/migrations/004_account_sessions.sql\n```\n\nThis adds a session version checked on authenticated requests. Password changes invalidate existing login sessions, including those on other devices. Active users will need to sign in again after deploying this change. This prototype is not suitable for real employee accounts until reset flows, session invalidation across devices, audit logging, and login throttling are implemented and reviewed.

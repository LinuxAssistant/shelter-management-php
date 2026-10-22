# Password management — PR #4

Updated: 2026-10-22. Keep this PR draft and separate from PR #6.

## Completed

- Administrator reset POST route and employee-page control.
- Shared password policy across creation, change, and reset.
- Atomic password/session/reset-token invalidation with user-first locking.
- Recovery-address/status changes revoke sessions and pending reset links.
- Credential-free security audit events; migration 016.
- Validated HTTPS mail configuration; failed delivery revokes newly issued token.
- Isolated HTTP/MariaDB regression suite, including concurrency; passed on Dell.
- PHP lint passed on Dell.
- GitHub Actions wired to run the isolated suite; connected GitHub app has workflow write access.

## Remaining before ready

- Browser acceptance for password changes, administrator resets, and recovery.
- Verify actual configured mail transport and delivery, HTTPS, secure cookies, reset URL logging controls, and audit retention.
- Confirm the new GitHub Actions lint and integration checks pass on the final commit.
- Independent security review, including recovery timing and abuse controls.
- Reconcile PR #6 authentication/routes, migrations, consolidated schema, and tests in a separate integration change.

No migrations were run against the deployment database. Tests used only disposable databases and fictional accounts.

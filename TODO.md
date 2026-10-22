# TODO — Shelter Management PHP

Updated: 2026-10-22
Repository: https://github.com/LinuxAssistant/shelter-management-php
Active development: draft PR #6, branch `feature/complete-shelter-platform`

## Resume tomorrow — first priorities

- [x] **Verify check-out status:** Dell checkout was behind the branch; fast-forwarded to include status fix 025105a. Disposable MariaDB/HTTP tests verify staff-directed and voluntary exits, occupancy removal, checked-out resident status with no bed, repeat-exit rejection, and returning-resident check-in.
- [x] **Finish AJAX resident search:** API route wired with existing administrator permission checks and no-store responses. Resident options load from debounced fetch requests; no resident list is preloaded. Tests cover partial names, reference codes, escaped LIKE wildcards, result limits, malformed queries, authentication/permissions, cancellation, stale responses, bed filtering, and empty/error/session states.
- [x] **Verify Resident History:** Disposable HTTP tests verify both departure types, reason, staff attribution, older unspecified stays, and missing residents.
- [x] **Run PHP lint and automated tests on Dell:** PHP lint, JavaScript syntax, asynchronous interface regression tests, and isolated MariaDB/HTTP integration tests pass. New tests are available in tests/; adding them to CI requires GitHub workflow write permission.
- [ ] **Browser acceptance:** Visually verify search selection, bed filtering, both checkout types, and history before marking PR ready.
- [ ] **GitHub Actions:** Confirm existing branch checks pass after push; wire new regression suites into CI once workflow write permission is available.

## Confirmed completed / locally tested

- [x] MariaDB migrations 007–014 applied; platform, kitchen, schedules, chores, daytime exceptions/food available.
- [x] Database schema GitHub Actions smoke test passed previously.
- [x] Resident registration with automatically generated `RES-000001`-style codes (database ID-based).
- [x] Resident check-in/out and bed availability pages implemented; staff-directed exit tested by user.
- [x] Migration `015_stay_departures.sql` applied successfully after correcting `departed_by` to `BIGINT UNSIGNED`; user confirmed new columns and staff-directed exit works.
- [x] Resident History verified by isolated HTTP integration tests; browser acceptance pending.
- [x] Residents page status verified by isolated HTTP integration tests; browser acceptance pending.

## Platform work remaining

- [ ] **Database-level occupancy safety:** Add unique active-stay constraints (one active bed per stay and one active stay per resident), including pre-migration checks for duplicate open stays; supplement transaction locking.
- [ ] **Permissions and privacy:** Implement facility-scoped employee authorization across residents, stays, histories, incidents, and administrative modules. Do not use real resident data before security review.
- [ ] **Authentication:** Review and integrate draft PR #4 password management carefully; it is separate from PR #6.
- [ ] **Resident management:** Duplicate-record prevention, lookup before registration, intake updates, and appropriate case-management workflow.
- [ ] **Operations:** Resident appointments, volunteer shifts, reporting, audit trail, inventory workflow, and additional form edit/validation capabilities.
- [ ] **Quality:** Automated MariaDB integration tests for check-in/out, concurrent double-booking, cross-facility rejection, exit reasons, returning residents, and permissions.
- [ ] **Deployment:** Production HTTPS, backups, secure sessions, access controls, and review of sensitive data retention.

## Tomorrow's local commands

```bash
cd ~/public_html/shelter-management-php  # adjust if your checkout is elsewhere
git checkout feature/complete-shelter-platform
git pull origin feature/complete-shelter-platform
php -l app/ResidentManagement.php
php -l app/ResidentHistory.php
php -l app/ResidentSearchApi.php
php -l public/index.php
```

No new migration is needed for the latest resident-history/status commits. Migration 015 is already applied on the user's local database. **Do not re-run it or reimport `database/schema.sql` into the existing database.**

## Pull request policy

Keep PR #6 **draft** until the feature wiring, lint, integration checks, and browser testing are complete. Keep PR #4 separate pending security review.

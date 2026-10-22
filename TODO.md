# TODO — Shelter Management PHP

Updated: 2026-10-22
Repository: https://github.com/LinuxAssistant/shelter-management-php
Active development: draft PR #6, branch `feature/complete-shelter-platform`

## Resume tomorrow — first priorities

- [x] **Verify check-out status:** Dell checkout was behind the branch; fast-forwarded to include status fix 025105a. Disposable MariaDB/HTTP tests verify staff-directed and voluntary exits, occupancy removal, checked-out resident status with no bed, repeat-exit rejection, and returning-resident check-in.
- [x] **Finish AJAX resident search:** API route wired with existing administrator permission checks and no-store responses. Resident options load from debounced fetch requests; no resident list is preloaded. Tests cover partial names, reference codes, escaped LIKE wildcards, result limits, malformed queries, authentication/permissions, cancellation, stale responses, bed filtering, and empty/error/session states.
- [x] **Verify Resident History:** Disposable HTTP tests verify both departure types, reason, staff attribution, older unspecified stays, and missing residents.
- [x] **Run PHP lint and automated tests on Dell:** PHP lint, JavaScript syntax, asynchronous interface regression tests, and isolated MariaDB/HTTP integration tests pass. Resident HTTP/MariaDB and asynchronous search regression suites are now wired into GitHub Actions.
- [x] **Browser acceptance:** Chromium and Firefox passed AJAX lookup by name/reference, facility bed filtering, check-in, both departures, status/history, and mobile form width. Fixed mobile control overflow found in screenshots.
- [ ] **GitHub Actions:** Confirm lint, resident integration, search regression, and schema checks pass on the latest commit.

## Confirmed completed / locally tested

- [x] MariaDB migrations 007–014 applied; platform, kitchen, schedules, chores, daytime exceptions/food available.
- [x] Database schema GitHub Actions smoke test passed previously.
- [x] Resident registration with automatically generated `RES-000001`-style codes (database ID-based).
- [x] Resident check-in/out and bed availability pages implemented; staff-directed exit tested by user.
- [x] Migration `015_stay_departures.sql` applied successfully after correcting `departed_by` to `BIGINT UNSIGNED`; user confirmed new columns and staff-directed exit works.
- [x] Resident History verified by isolated HTTP integration tests; Chromium/Firefox browser acceptance passed.
- [x] Residents page status verified by isolated HTTP integration tests; Chromium/Firefox browser acceptance passed.

## Platform work remaining

- [x] **Database-level occupancy safety:** Migration 017 adds unique active resident/bed keys and refuses duplicate open stays before altering the table. Transaction locking remains; concurrent SQL/PHP admission and migration-preflight tests pass. Migration NOT applied to the deployment database; see docs/active-stay-uniqueness.md.
- [ ] **Permissions and privacy:** Implement facility-scoped employee authorization across residents, stays, histories, incidents, and administrative modules. Do not use real resident data before security review.
- [ ] **Authentication:** Review and integrate draft PR #4 password management carefully; it is separate from PR #6.
- [ ] **Resident management:** Duplicate-record prevention, lookup before registration, intake updates, and appropriate case-management workflow.
- [ ] **Operations:** Resident appointments, volunteer shifts, reporting, audit trail, inventory workflow, and additional form edit/validation capabilities.
- [x] **Occupancy regression coverage:** Check-in/out, concurrent double-booking/resident admissions, cross-facility rejection, exit reasons, returning residents, basic permission denial, database constraints, and migration refusal/retry are covered. Facility-scoped authorization coverage remains part of the permissions work.
- [ ] **Deployment:** Local HTTPS is installed and trusted; production HTTPS, backups, secure sessions, access controls, and sensitive-data retention review remain.

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

Migration 015 is already applied on the user's local database. New migration 017 is prepared and tested only in disposable databases; apply it once during a maintenance window after the documented backup/duplicate checks. **Do not re-run 015 or reimport `database/schema.sql` into the existing database.**

## Pull request policy

Keep PR #6 **draft** until the feature wiring, lint, integration checks, and browser testing are complete. Keep PR #4 separate pending security review.

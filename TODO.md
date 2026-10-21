# TODO — Shelter Management PHP

Updated: 2026-10-21
Repository: https://github.com/LinuxAssistant/shelter-management-php
Active development: draft PR #6, branch `feature/complete-shelter-platform`

## Resume tomorrow — first priorities

- [ ] **Verify check-out status:** Test a fictional resident's staff-directed exit and voluntary departure. Confirm they disappear from **Bed Occupancy** but remain in **Residents** as **Checked out**, with no current bed. The status-column fix is commit `025105a`. Investigate whether any resident remains incorrectly marked as occupying a bed.
- [ ] **Finish AJAX resident search:** `app/ResidentSearchApi.php` was added (commit `71b8029`), but its route was **not wired into `public/index.php`** and the check-in form still uses client-side filtering of all residents. Add `GET /api/residents/search?q=...`, authenticate/authorize, return limited JSON, and replace preloaded resident options with debounced `fetch()` results. Test by reference code and partial name. Do not claim AJAX is finished until verified.
- [ ] **Verify Resident History:** New `app/ResidentHistory.php` and `/admin/residents/{id}/history` route, linked from Residents. Check check-in/out dates, departure type/reason, staff attribution, and older stays with unspecified type.
- [ ] **Run PHP lint and GitHub Actions** on PR #6; fix any errors. Test key flows in browser before marking complete.

## Confirmed completed / locally tested

- [x] MariaDB migrations 007–014 applied; platform, kitchen, schedules, chores, daytime exceptions/food available.
- [x] Database schema GitHub Actions smoke test passed previously.
- [x] Resident registration with automatically generated `RES-000001`-style codes (database ID-based).
- [x] Resident check-in/out and bed availability pages implemented; staff-directed exit tested by user.
- [x] Migration `015_stay_departures.sql` applied successfully after correcting `departed_by` to `BIGINT UNSIGNED`; user confirmed new columns and staff-directed exit works.
- [x] Resident History feature committed (not yet browser-verified).
- [x] Residents page status column committed (not yet browser-verified).

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

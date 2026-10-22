# Incident facility access

Migration 019 adds nullable incidents.facility_id and a separate incident_facility_access assignment table. Existing reports retain a NULL facility and remain visible only to global administrators. No location text or employee assignment is used to guess historical ownership.

For employees without admin.manage, an incident action requires both the existing incident role permission and an active incident facility assignment. Resident assignments do not grant incident access, and incident assignments grant neither resident access nor additional roles. Administrators manage incident assignments through the employee's "Manage incident facility access" link.

Incident lists and detail lookups apply the scope on the server. New reports require an accessible active facility. Notes and involved people are checked against their parent report before reading or writing. Guessing a report ID outside the assigned facilities returns the same 404 response as an absent report. Responses use no-store. Revocation applies on the next request in an existing session.

## Deployment and historical reports

This migration has NOT been applied to the running site. Back up the database, stop application writers during the maintenance window, and apply 017 and 018 according to their instructions before applying 019 once and deploying matching code. Do not reimport schema.sql into an existing database.

Grant incident assignments deliberately; role permissions alone no longer allow nonadministrators to access all reports. Keep an administrator available to review existing reports. An administrator-facing historical report reassignment workflow is still required before exposing those reports to facility employees. NULL is intentional and is never a wildcard.

## Validation and remaining scope

Disposable migration tests verify an older report survives migration with no inferred facility. HTTP tests cover list/detail/child privacy, forged facility and report IDs, successful assigned actions, independent roles and assignments, CSRF, assignment auditing, revocation, inactive facilities, and deactivated sessions. Only fictional fixtures are used.

Other operations remain administrator-only. This increment does not grant operational or financial access to assigned employees and does not complete the platform security review.

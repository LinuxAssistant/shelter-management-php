# Resident facility access

Migration 018 adds explicit employee-to-facility assignments for resident work. Employees with an active assigned facility can register residents, search available residents, manage stays, and read history at that facility. No assignments are created automatically. The existing admin.manage capability remains global access.

Administrators manage assignments from an employee's "Manage resident facility access" link. Assignment changes require CSRF validation and are recorded in audit_events using internal user IDs. Removing assignments takes effect on the next request, including in an existing session. Inactive facilities grant no scoped access. Assignments do not grant roles or access to employee, finance, or other administrative modules.

Lists, search, history, intake, admissions, departures, and bed options enforce the scope on the server. Requests that guess a foreign resident or stay ID cannot reveal history or mutate the stay. Inconsistent legacy stays do not expose beds from an unassigned facility.

## Deployment

This change is prepared in the isolated development checkout and has NOT been applied to the running shelter database.

Back up the database and schedule a maintenance window before deployment. Apply migration 017 using its separate duplicate-preflight instructions, then apply database/migrations/018_resident_facility_access.sql once before deploying code that reads resident_facility_access. Migration 016 belongs to the separate password-management PR and is not required here. Do not reimport the consolidated schema into an existing database.

Keep the existing administrator available to grant assignments. Deploying the code before migration 018 can break signed-in dashboard and resident requests. Verify assignments with fictional residents before allowing real resident data.

## Validation and limits

The disposable database/HTTP suite covers read and write isolation, guessed IDs, cross-facility legacy stays, invalid/duplicate assignments, CSRF, administrator-only changes, audit events, revocation, inactive facilities, and retained global administrator access. Existing occupancy concurrency tests and Chromium/Firefox acceptance also pass.

This increment covers resident workflows only. Facility permissions for incidents and other operational/administrative modules, authentication integration, and a full sensitive-data security review remain open. It does not claim a complete platform authorization review.

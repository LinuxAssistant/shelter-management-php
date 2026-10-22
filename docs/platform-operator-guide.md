# Complete shelter platform: operator guide

## Staff workflows

The dashboard shows only authorized modules. Global administrators manage employees, roles, facilities, rooms, beds, public information and audit events.

Employee access has three independent layers:
- Resident facility assignments: intake, resident lookup, stable references, check-in/out, and stay history.
- Incident roles plus incident facility assignments: reports, notes, involved people and review status.
- Facility module grants: daily operations, kitchen, finances, volunteers, and private casework.

Casework grants include optional case-worker assignment, emergency contact/consent notes, private notes, service referrals and appointments. Operational grants include operational resident names for chores and daytime access; they do not expose case notes. Finance grants allow donation acknowledgments, reconciliation and scoped CSV exports. CSV fields that resemble spreadsheet formulas are neutralized. Exports include up to the latest 5,000 authorized records.

Inventory adjustments use an append-only movement log and cannot drive stock negative. Volunteer shifts reject overlaps; completed shifts record the approving employee. Approved hours use the shift's recorded start/end time. Daily, weekly and one-time chore recurrence is recorded as a schedule label; automatic future chore creation is not implemented.

Before registration, search existing residents. Matching names are blocked by default; a deliberate distinct-person confirmation permits different people with the same name. Intake updates preserve facility/reference and audit the change.

Public pages at /welcome, /services and /contact contain only administrator-authored public information. Contact details direct visitors to the shelter; the site does not send public contact submissions. There is no public resident directory or case data.

Reports cover current occupancy and authorized finance, meal and volunteer totals. They exclude case and incident narratives.

## Existing-database upgrade

The running site has NOT been upgraded by this development work. Never import database/schema.sql into an existing database.

Stop application writers, take a private backup, and verify restoration in an isolated database first. A prepared CLI helper is available:

    php scripts/backup.php /private/backup-directory/shelter-before-upgrade.sql

It loads the deployment configuration without printing credentials, writes outside the checkout, refuses to overwrite an existing file, and creates mode-0600 output. Keep backups encrypted and access-restricted according to your organization's retention rules.

Review/apply forward migrations in order:
- 017: active-stay uniqueness; follow its duplicate preflight and reconciliation guide.
- 018: resident facility assignments.
- 019: incident ownership and incident facility assignments.
- 020: explicit module permissions.
- 021: appointment extensions, case-note author integrity, stock movements, volunteer approvals and public settings.
- 022: financial reconciliation, private case profiles and referrals.

Migration 021 refuses orphaned legacy case-note authors through its foreign key. Reconcile authors to valid employees before migration; do not delete historical notes merely to bypass it. MariaDB DDL commits independently; stop on any migration error and inspect the schema before retrying. Do not run with --force.

Password-management migrations and deployment integration are documented separately. PR #4 remains a separate draft. The complete integration preview includes its authentication changes only after both suites pass.

Deploy matching code only after the schema is ready. Use public/ as the web document root, require HTTPS, set SESSION_SECURE=true and disable PHP display_errors in the web server. Existing sessions expire after 30 minutes idle or 12 hours maximum. Do not expose Docker/Portainer administration publicly.

Grant staff roles and facility access explicitly. Existing reports without a facility remain administrator-only until deliberately attributed. Administrators cannot change their own roles or deactivate themselves.

## Installation and validation

For an empty database, import the consolidated schema from the chosen release build, configure private environment settings, generate the Composer autoloader, and create an administrator with scripts/create-admin.php. Do not serve repository roots, database files, backups, vendor files or configuration.

Run node tests/resident-search.cjs and python3 tests/integration.py as an unprivileged user. Optional --browser runs Chromium and Firefox. All tests use disposable databases, loopback servers and fictional records. Deployment configuration, backups, and real residents are not loaded.

The application does not claim legal compliance or independent security certification. Organizational consent, retention and access policies require human review before real data. Public-domain HTTPS/mail, offsite backups and operational monitoring depend on deployment configuration and are not silently configured by tests.

# Shelter Management PHP — Application Roadmap

This repository is intended to become a configurable, self-hosted shelter operations platform for multiple organizations. It is **not production ready**. Use fictional data until a security and privacy review is completed.

## Architecture

- PHP 8+, Apache, MariaDB, `mysqli` prepared statements, Composer, HTML/CSS/JavaScript.
- Incrementally migrate the existing front controller to MVC: routes, controllers, services, policies, repositories, templates.
- Versioned, forward-only SQL migrations with installation and upgrade instructions.
- Configurable facility name, time zone, address, capacity, program types and public website branding.
- CI for PHP lint, automated tests, schema tests, and security-oriented checks.

## Delivery order

1. **Foundation and account security**: finish PR #4 self-service password change; add secure reset workflow, login throttling, account/session revocation, audit events, role administration, policy checks, test harness.
2. **Facilities and employees**: facility settings, staff directory, permissions and facility-scoped authorization.
3. **Residents and intake**: minimal necessary personal information, consent notices, intake/discharge, emergency contacts with restricted access, record retention rules.
4. **Beds and occupancy**: rooms, beds, availability, reservations, check-in/check-out, occupancy history, capacity reporting.
5. **Case management**: restricted case notes, services, referrals, appointments, case assignments, separation from incident notes.
6. **Incidents and safety**: incident workflows, status changes, follow-ups, permissions and audit trails.
7. **Volunteers and schedules**: volunteer records, shifts, hours and approvals.
8. **Donations and finances**: donor records, cash/in-kind donations, receipts, expenses, reconciliation and exportable reports.
9. **Public site**: configurable public pages, contact information, services and safe contact workflow, separate from private administrative routes.
10. **Operations and deployment**: backups/restore testing, installation wizard or guided setup, migration upgrade tests, deployment guide, monitoring, security checklist.

## Security and privacy gates

Before any real resident or employee information is entered: verify least-privilege permissions, cross-facility isolation, HTTPS, secure session cookies, password reset token handling, authentication rate limiting, audit logging, input validation, output encoding, CSRF protection, database backups and restoration, data retention/deletion, and incident response procedures. Never commit credentials, private keys, real shelter records or personal information. A successful PHP lint run alone is **not** a security review.

## Contribution workflow

Each module uses a feature branch and a focused PR. Add schema migrations, test instructions and docs to every PR. Keep `main` stable. Merge only after review, passing CI, and a practical local test. Avoid committing directly to `main`.

## Existing work

- PRs #1–#3 established authentication, incident reports/notes, employee profiles and employee management.
- PR #4 (`feature/password-management`) is a **draft** covering self-service password changes only. Password resets, email delivery, throttling, audit events and global session invalidation remain future work.

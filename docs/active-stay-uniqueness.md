# Active-stay uniqueness — migration 017

Migration 017 adds two generated, uniquely indexed values to stays. Open stays expose their resident and bed IDs; closed stays expose NULL. This enforces one open stay per resident and one occupant per bed while allowing repeated historical stays.

Apply once after migration 015. Number 016 is reserved for the separate password-management PR; this migration does not depend on it.

## Existing installations

1. Back up the database and arrange a maintenance window.
2. Pause all writers that can admit, depart, or edit stays, including scripts.
3. Run read-only checks for duplicate open stays:

       SELECT resident_id, COUNT(*) AS open_stays
       FROM stays WHERE checked_out_at IS NULL
       GROUP BY resident_id HAVING COUNT(*) > 1;

       SELECT bed_id, COUNT(*) AS open_stays
       FROM stays WHERE checked_out_at IS NULL
       GROUP BY bed_id HAVING COUNT(*) > 1;

4. If conflicts exist, review the actual stay history and reconcile it manually. Do not delete records or arbitrarily close a stay to make the migration pass.
5. Apply database/migrations/017_active_stay_uniqueness.sql once with the MariaDB client. Do not use --force or a runner that continues after errors. The anonymous SQL block requires a client that supports DELIMITER.
6. Confirm uq_stays_active_resident and uq_stays_active_bed exist before resuming writes.

The migration stops with a descriptive error before ALTER TABLE if duplicates exist. The single ALTER TABLE also validates uniqueness against the final data. DDL is not wrapped in a rollbackable application transaction; use the backup and maintenance process above.

The consolidated schema already includes this migration for NEW, EMPTY databases. Never import it over an existing installation.

## Application behavior

Transactional resident/bed locks remain in place. Duplicate-key, deadlock, or lock-timeout errors during check-in return HTTP 409 with a refresh-and-retry message instead of a raw SQL exception. A known occupied bed/resident remains a validation error. This does not replace facility-scoped authorization.

## Verification

The isolated regression suite tests duplicate resident/bed inserts, attempts to reopen a conflicting historical stay, repeated closed-stay history, simultaneous direct SQL writes, and simultaneous PHP application check-ins. Separate legacy fixtures verify both preflight failures, preservation of records/schema after refusal, and successful application once the conflicts are reconciled.

No deployment migration or real-resident query was performed during development.

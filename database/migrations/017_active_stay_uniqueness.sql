-- One active stay per resident and per bed; closed history remains unrestricted.
-- Apply once after migration 015. Migration 016 belongs to password PR #4.
-- Pause admission/checkout writes and take a backup before upgrading.
-- Stop on any error; never use the MariaDB client's --force option.
DELIMITER //
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM stays WHERE checked_out_at IS NULL GROUP BY resident_id HAVING COUNT(*)>1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migration 017 blocked: duplicate open stays for a resident. Reconcile stays before retrying.';
    END IF;
    IF EXISTS (SELECT 1 FROM stays WHERE checked_out_at IS NULL GROUP BY bed_id HAVING COUNT(*)>1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migration 017 blocked: duplicate open stays for a bed. Reconcile stays before retrying.';
    END IF;
END//
DELIMITER ;

ALTER TABLE stays
    ADD COLUMN active_resident_id BIGINT UNSIGNED AS (IF(checked_out_at IS NULL,resident_id,NULL)) PERSISTENT,
    ADD COLUMN active_bed_id BIGINT UNSIGNED AS (IF(checked_out_at IS NULL,bed_id,NULL)) PERSISTENT,
    ADD UNIQUE KEY uq_stays_active_resident(active_resident_id),
    ADD UNIQUE KEY uq_stays_active_bed(active_bed_id);

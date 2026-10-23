CREATE TABLE donation_reconciliations (
 donation_id BIGINT UNSIGNED PRIMARY KEY,
 reference VARCHAR(120) NOT NULL,
 reconciled_by BIGINT UNSIGNED NOT NULL,
 reconciled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(donation_id) REFERENCES donations(id),
 FOREIGN KEY(reconciled_by) REFERENCES users(id)
);
CREATE TABLE resident_case_profiles (
 resident_id BIGINT UNSIGNED PRIMARY KEY,
 caseworker_id BIGINT UNSIGNED NULL,
 emergency_contact VARCHAR(500) NOT NULL DEFAULT '',
 consent_note VARCHAR(1000) NOT NULL DEFAULT '',
 updated_by BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(resident_id) REFERENCES residents(id),
 FOREIGN KEY(caseworker_id) REFERENCES users(id),
 FOREIGN KEY(updated_by) REFERENCES users(id)
);
CREATE TABLE case_referrals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 resident_id BIGINT UNSIGNED NOT NULL,
 service VARCHAR(160) NOT NULL,
 provider VARCHAR(160) NOT NULL,
 status ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
 notes VARCHAR(1000) NOT NULL DEFAULT '',
 created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(resident_id) REFERENCES residents(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
);

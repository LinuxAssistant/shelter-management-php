-- Staff meals and flexible daytime shelter access.
ALTER TABLE kitchen_meals
 ADD COLUMN audience ENUM('resident','staff') NOT NULL DEFAULT 'resident';
CREATE TABLE daytime_access_exceptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 facility_id BIGINT UNSIGNED NOT NULL,
 resident_id BIGINT UNSIGNED NULL,
 reason ENUM('cold_weather','rain','severe_weather','case_manager','group_meeting','other') NOT NULL,
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 authorized_by BIGINT UNSIGNED NOT NULL,
 notes VARCHAR(500) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (facility_id) REFERENCES facilities(id),
 FOREIGN KEY (resident_id) REFERENCES residents(id),
 FOREIGN KEY (authorized_by) REFERENCES users(id),
 INDEX idx_access_facility_times (facility_id,starts_at,ends_at)
);

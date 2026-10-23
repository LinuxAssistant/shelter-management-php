-- Per-facility schedules and daytime program visits.
CREATE TABLE facility_schedules (
 facility_id BIGINT UNSIGNED PRIMARY KEY,
 breakfast_enabled BOOLEAN NOT NULL DEFAULT TRUE,
 lunch_enabled BOOLEAN NOT NULL DEFAULT FALSE,
 dinner_enabled BOOLEAN NOT NULL DEFAULT TRUE,
 snacks_enabled BOOLEAN NOT NULL DEFAULT FALSE,
 daytime_exit TIME NOT NULL DEFAULT '09:00:00',
 normal_return TIME NOT NULL DEFAULT '17:00:00',
 daytime_access_policy VARCHAR(255) NOT NULL DEFAULT 'Scheduled appointments and programs; staff may authorize exceptions',
 FOREIGN KEY (facility_id) REFERENCES facilities(id)
);
CREATE TABLE shelter_programs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 facility_id BIGINT UNSIGNED NOT NULL,
 provider_name VARCHAR(160) NOT NULL,
 title VARCHAR(160) NOT NULL,
 topic VARCHAR(255) NOT NULL DEFAULT '',
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 location VARCHAR(160) NOT NULL DEFAULT '',
 notes TEXT NULL,
 FOREIGN KEY (facility_id) REFERENCES facilities(id),
 INDEX idx_program_schedule (facility_id,starts_at)
);
CREATE TABLE resident_appointments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 facility_id BIGINT UNSIGNED NOT NULL,
 resident_id BIGINT UNSIGNED NOT NULL,
 appointment_type ENUM('case_manager','group_program','other') NOT NULL,
 program_id BIGINT UNSIGNED NULL,
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 notes TEXT NULL,
 FOREIGN KEY (facility_id) REFERENCES facilities(id),
 FOREIGN KEY (resident_id) REFERENCES residents(id),
 FOREIGN KEY (program_id) REFERENCES shelter_programs(id),
 INDEX idx_appointments (facility_id,starts_at)
);

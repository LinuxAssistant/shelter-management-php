-- Shelter Management PHP consolidated clean-install schema
-- For a NEW, EMPTY database ONLY. DO NOT run against an existing installation.
-- Generated from the current migration sequence on feature/complete-shelter-platform.
-- Contains application tables, role/permission seeds, and no sample residents or passwords.
-- Existing migrations remain available for upgrades.

-- ===== 001_initial.sql =====
CREATE TABLE users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,email VARCHAR(255) NOT NULL UNIQUE,badge_number VARCHAR(64) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,active BOOLEAN NOT NULL DEFAULT TRUE,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE roles (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(80) NOT NULL UNIQUE);
CREATE TABLE permissions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL UNIQUE);
CREATE TABLE user_roles (user_id BIGINT UNSIGNED NOT NULL,role_id INT UNSIGNED NOT NULL,PRIMARY KEY(user_id,role_id),FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(role_id) REFERENCES roles(id));
CREATE TABLE role_permissions (role_id INT UNSIGNED NOT NULL,permission_id INT UNSIGNED NOT NULL,PRIMARY KEY(role_id,permission_id),FOREIGN KEY(role_id) REFERENCES roles(id),FOREIGN KEY(permission_id) REFERENCES permissions(id));
CREATE TABLE incidents (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,category VARCHAR(60) NOT NULL,severity VARCHAR(20) NOT NULL,location VARCHAR(150) NOT NULL,description TEXT NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'Open',reported_by BIGINT UNSIGNED NOT NULL,occurred_at DATETIME NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(reported_by) REFERENCES users(id));
INSERT INTO roles(name) VALUES('Administrator'),('Manager'),('Staff'),('Volunteer');
INSERT INTO permissions(name) VALUES('incidents.view'),('incidents.create'),('incidents.review'),('admin.manage');
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name='Administrator';
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name='Manager' AND p.name IN ('incidents.view','incidents.create','incidents.review');
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name='Staff' AND p.name IN ('incidents.view','incidents.create');

-- ===== 002_incident_notes_people.sql =====
-- Apply after 001_initial.sql, once per database.
CREATE TABLE incident_notes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 incident_id BIGINT UNSIGNED NOT NULL,
 author_id BIGINT UNSIGNED NOT NULL,
 body TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (incident_id) REFERENCES incidents(id),
 FOREIGN KEY (author_id) REFERENCES users(id),
 INDEX (incident_id,created_at)
);
CREATE TABLE incident_people (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 incident_id BIGINT UNSIGNED NOT NULL,
 display_name VARCHAR(150) NOT NULL,
 person_type ENUM('Resident','Employee','Visitor','Unknown') NOT NULL,
 involvement ENUM('Witness','Affected','Reported','Other') NOT NULL,
 added_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (incident_id) REFERENCES incidents(id),
 FOREIGN KEY (added_by) REFERENCES users(id),
 INDEX (incident_id)
);
INSERT IGNORE INTO permissions(name) VALUES ('incidents.note'),('incidents.people');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
 WHERE r.name IN ('Administrator','Manager','Staff') AND p.name IN ('incidents.note','incidents.people');

-- ===== 003_employee_profiles.sql =====
ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL, ADD COLUMN last_name VARCHAR(100) NULL, ADD COLUMN job_title VARCHAR(120) NULL;

-- ===== 007_platform_modules.sql =====
-- Fictional test data only until security review is complete.
CREATE TABLE facilities (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(160) NOT NULL,timezone VARCHAR(80) NOT NULL DEFAULT 'America/Detroit',address VARCHAR(255) NOT NULL DEFAULT '',active TINYINT(1) NOT NULL DEFAULT 1);
CREATE TABLE rooms (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,facility_id BIGINT UNSIGNED NOT NULL,name VARCHAR(100) NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,UNIQUE KEY uq_room(facility_id,name),FOREIGN KEY(facility_id) REFERENCES facilities(id));
CREATE TABLE beds (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,room_id BIGINT UNSIGNED NOT NULL,code VARCHAR(60) NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,UNIQUE KEY uq_bed(room_id,code),FOREIGN KEY(room_id) REFERENCES rooms(id));
CREATE TABLE residents (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,facility_id BIGINT UNSIGNED NOT NULL,reference_code VARCHAR(60) NOT NULL,display_name VARCHAR(160) NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_resident(facility_id,reference_code),FOREIGN KEY(facility_id) REFERENCES facilities(id));
CREATE TABLE stays (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,resident_id BIGINT UNSIGNED NOT NULL,bed_id BIGINT UNSIGNED NOT NULL,checked_in_at DATETIME NOT NULL,checked_out_at DATETIME NULL,FOREIGN KEY(resident_id) REFERENCES residents(id),FOREIGN KEY(bed_id) REFERENCES beds(id),INDEX idx_stay_bed(bed_id,checked_out_at));
CREATE TABLE case_notes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,resident_id BIGINT UNSIGNED NOT NULL,author_id INT UNSIGNED NOT NULL,body TEXT NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(resident_id) REFERENCES residents(id));
CREATE TABLE volunteers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,facility_id BIGINT UNSIGNED NOT NULL,display_name VARCHAR(160) NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,FOREIGN KEY(facility_id) REFERENCES facilities(id));
CREATE TABLE volunteer_shifts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,volunteer_id BIGINT UNSIGNED NOT NULL,starts_at DATETIME NOT NULL,ends_at DATETIME NOT NULL,FOREIGN KEY(volunteer_id) REFERENCES volunteers(id));
CREATE TABLE donations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,facility_id BIGINT UNSIGNED NOT NULL,donor_label VARCHAR(160) NOT NULL,kind ENUM('cash','in_kind') NOT NULL,amount DECIMAL(12,2) NULL,description TEXT NULL,received_at DATETIME NOT NULL,FOREIGN KEY(facility_id) REFERENCES facilities(id));
CREATE TABLE expenses (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,facility_id BIGINT UNSIGNED NOT NULL,description VARCHAR(255) NOT NULL,amount DECIMAL(12,2) NOT NULL,spent_at DATETIME NOT NULL,FOREIGN KEY(facility_id) REFERENCES facilities(id));
CREATE TABLE audit_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,actor_user_id BIGINT UNSIGNED NULL,event_name VARCHAR(100) NOT NULL,entity_type VARCHAR(80) NULL,entity_id BIGINT UNSIGNED NULL,occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_audit_time(occurred_at));

-- ===== 008_bed_types.sql =====
-- Migration 008: support single beds and upper/lower bunk positions.
-- Safe to rerun after a partial execution of the original migration.
-- The new unique index also supports the room_id foreign key.
-- Run against a backed-up database.

SET @schema_name = DATABASE();

SELECT COUNT(*) INTO @has_type FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='beds' AND COLUMN_NAME='bed_type';
SET @ddl = IF(@has_type=0,
 'ALTER TABLE beds ADD COLUMN bed_type ENUM(''single'',''bunk'') NOT NULL DEFAULT ''single''',
 'SELECT ''bed_type already exists''');
PREPARE statement FROM @ddl; EXECUTE statement; DEALLOCATE PREPARE statement;

SELECT COUNT(*) INTO @has_position FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='beds' AND COLUMN_NAME='position';
SET @ddl = IF(@has_position=0,
 'ALTER TABLE beds ADD COLUMN position ENUM(''single'',''lower'',''upper'') NOT NULL DEFAULT ''single''',
 'SELECT ''position already exists''');
PREPARE statement FROM @ddl; EXECUTE statement; DEALLOCATE PREPARE statement;

SELECT COUNT(*) INTO @has_new_index FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='beds' AND INDEX_NAME='uq_bed_position';
SET @ddl = IF(@has_new_index=0,
 'ALTER TABLE beds ADD UNIQUE KEY uq_bed_position (room_id,code,position)',
 'SELECT ''uq_bed_position already exists''');
PREPARE statement FROM @ddl; EXECUTE statement; DEALLOCATE PREPARE statement;

SELECT COUNT(*) INTO @has_old_index FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='beds' AND INDEX_NAME='uq_bed';
SET @ddl = IF(@has_old_index>0,
 'ALTER TABLE beds DROP INDEX uq_bed',
 'SELECT ''uq_bed already removed''');
PREPARE statement FROM @ddl; EXECUTE statement; DEALLOCATE PREPARE statement;

-- ===== 009_donation_items.sql =====
-- Optional metadata for donated goods; existing donation records are preserved.
ALTER TABLE donations
  ADD COLUMN item_category ENUM('bedding','clothing','toiletries','food','household','misc') NULL,
  ADD COLUMN quantity DECIMAL(12,2) NULL,
  ADD COLUMN unit VARCHAR(40) NULL,
  ADD COLUMN estimated_value DECIMAL(12,2) NULL;

-- ===== 010_kitchen.sql =====
-- Kitchen module: non-identifying food stock and meal-service records.
CREATE TABLE kitchen_inventory (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 facility_id BIGINT UNSIGNED NOT NULL,
 item_name VARCHAR(160) NOT NULL,
 quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
 unit VARCHAR(40) NOT NULL,
 storage_location VARCHAR(100) NOT NULL DEFAULT '',
 expires_on DATE NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (facility_id) REFERENCES facilities(id),
 CHECK (quantity >= 0)
);
CREATE TABLE kitchen_meals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 facility_id BIGINT UNSIGNED NOT NULL,
 served_on DATE NOT NULL,
 meal_type ENUM('breakfast','lunch','dinner','snack') NOT NULL,
 description VARCHAR(255) NOT NULL,
 meals_served INT UNSIGNED NOT NULL DEFAULT 0,
 FOREIGN KEY (facility_id) REFERENCES facilities(id)
);
CREATE TABLE kitchen_temperature_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 facility_id BIGINT UNSIGNED NOT NULL,
 equipment_name VARCHAR(120) NOT NULL,
 temperature_f DECIMAL(6,2) NOT NULL,
 recorded_at DATETIME NOT NULL,
 FOREIGN KEY (facility_id) REFERENCES facilities(id)
);
CREATE TABLE kitchen_waste (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 facility_id BIGINT UNSIGNED NOT NULL,
 item_name VARCHAR(160) NOT NULL,
 quantity DECIMAL(12,2) NOT NULL,
 unit VARCHAR(40) NOT NULL,
 reason VARCHAR(255) NOT NULL,
 recorded_at DATETIME NOT NULL,
 FOREIGN KEY (facility_id) REFERENCES facilities(id),
 CHECK (quantity > 0)
);

-- ===== 011_chores.sql =====
-- Chore assignments may be assigned to staff, volunteers or residents.
-- The assignee must belong to the same facility (enforced by application validation).
CREATE TABLE chores (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 facility_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(160) NOT NULL,
 description TEXT NULL,
 area VARCHAR(100) NOT NULL DEFAULT '',
 assignee_type ENUM('unassigned','staff','volunteer','resident') NOT NULL DEFAULT 'unassigned',
 assignee_id BIGINT UNSIGNED NULL,
 due_at DATETIME NOT NULL,
 recurrence ENUM('none','daily','weekly') NOT NULL DEFAULT 'none',
 status ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
 completed_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (facility_id) REFERENCES facilities(id),
 INDEX idx_chore_due (facility_id,status,due_at)
);

-- ===== 012_facility_schedules_programs.sql =====
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


-- ===== 013_daytime_exceptions_staff_meals.sql =====
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

-- ===== 014_daytime_food.sql =====
-- Food made available during weather-related or other authorized daytime stays.
-- Records distribution totals, not individual resident consumption.
CREATE TABLE daytime_food_distributions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 facility_id BIGINT UNSIGNED NOT NULL,
 exception_id BIGINT UNSIGNED NULL,
 served_at DATETIME NOT NULL,
 food_description VARCHAR(255) NOT NULL,
 source ENUM('donated','purchased','mixed') NOT NULL DEFAULT 'donated',
 portions_served INT UNSIGNED NOT NULL DEFAULT 0,
 notes VARCHAR(500) NOT NULL DEFAULT '',
 recorded_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (facility_id) REFERENCES facilities(id),
 FOREIGN KEY (exception_id) REFERENCES daytime_access_exceptions(id),
 FOREIGN KEY (recorded_by) REFERENCES users(id),
 INDEX idx_daytime_food_facility (facility_id,served_at)
);

-- ===== 015_stay_departures.sql =====
-- Record how each stay ended, including staff-directed exits.
ALTER TABLE stays
 ADD COLUMN departure_type ENUM('voluntary','staff_exit') NULL,
 ADD COLUMN departure_reason VARCHAR(500) NULL,
 ADD COLUMN departed_by BIGINT UNSIGNED NULL,
 ADD CONSTRAINT fk_stays_departed_by FOREIGN KEY (departed_by) REFERENCES users(id);

-- ===== 017_active_stay_uniqueness.sql =====
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

-- ===== 018_resident_facility_access.sql =====
-- Resident access is facility-specific unless the employee has admin.manage.
-- No existing employee receives a new assignment automatically.
CREATE TABLE resident_facility_access (
    user_id BIGINT UNSIGNED NOT NULL,
    facility_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY(user_id,facility_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(facility_id) REFERENCES facilities(id) ON DELETE CASCADE
);

-- ===== 019_incident_facility_access.sql =====
-- Existing reports retain NULL facility and are restricted to global administrators.
-- Do not infer a report's facility from its reporter or location text.
ALTER TABLE incidents
    ADD COLUMN facility_id BIGINT UNSIGNED NULL,
    ADD INDEX incidents_facility_time (facility_id,occurred_at),
    ADD CONSTRAINT incidents_facility_fk FOREIGN KEY(facility_id) REFERENCES facilities(id);
CREATE TABLE incident_facility_access (
    user_id BIGINT UNSIGNED NOT NULL,
    facility_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY(user_id,facility_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(facility_id) REFERENCES facilities(id) ON DELETE CASCADE
);

-- ===== 020_facility_module_permissions.sql =====
CREATE TABLE staff_facility_permissions (
 user_id BIGINT UNSIGNED NOT NULL,
 facility_id BIGINT UNSIGNED NOT NULL,
 module ENUM('operations','kitchen','finance','volunteers','casework') NOT NULL,
 PRIMARY KEY(user_id,facility_id,module),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(facility_id) REFERENCES facilities(id) ON DELETE CASCADE
);

-- ===== 021_platform_workflows.sql =====
-- Existing case-note authors must identify valid employees before this FK is added.
ALTER TABLE case_notes MODIFY author_id BIGINT UNSIGNED NOT NULL,
 ADD CONSTRAINT case_notes_author_fk FOREIGN KEY(author_id) REFERENCES users(id);
ALTER TABLE resident_appointments
 ADD COLUMN title VARCHAR(160) NOT NULL DEFAULT 'Appointment',
 ADD COLUMN location VARCHAR(160) NOT NULL DEFAULT '',
 ADD COLUMN status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
 ADD COLUMN created_by BIGINT UNSIGNED NULL,
 ADD CONSTRAINT appointments_created_by_fk FOREIGN KEY(created_by) REFERENCES users(id);
CREATE TABLE inventory_movements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 inventory_id BIGINT UNSIGNED NOT NULL,
 quantity_change DECIMAL(12,2) NOT NULL,
 reason VARCHAR(255) NOT NULL,
 recorded_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(inventory_id) REFERENCES kitchen_inventory(id),
 FOREIGN KEY(recorded_by) REFERENCES users(id)
);
CREATE TABLE volunteer_shift_details (
 shift_id BIGINT UNSIGNED PRIMARY KEY,
 status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
 approved_by BIGINT UNSIGNED NULL,
 FOREIGN KEY(shift_id) REFERENCES volunteer_shifts(id),
 FOREIGN KEY(approved_by) REFERENCES users(id)
);
CREATE TABLE public_site_settings (
 id TINYINT UNSIGNED PRIMARY KEY,
 site_name VARCHAR(160) NOT NULL DEFAULT 'Shelter',
 introduction TEXT NOT NULL,
 services TEXT NOT NULL,
 contact_text TEXT NOT NULL,
 updated_by BIGINT UNSIGNED NULL,
 FOREIGN KEY(updated_by) REFERENCES users(id)
);
INSERT INTO public_site_settings(id,site_name,introduction,services,contact_text)
 VALUES(1,'Shelter','Welcome to our shelter.','Contact our team for current services and availability.','Please contact the shelter directly for assistance.');

-- ===== 022_casework_finance.sql =====
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

-- ===== 023_recurring_chores.sql =====
CREATE TABLE chore_successors (
 parent_chore_id BIGINT UNSIGNED PRIMARY KEY,
 next_chore_id BIGINT UNSIGNED NOT NULL UNIQUE,
 FOREIGN KEY(parent_chore_id) REFERENCES chores(id),
 FOREIGN KEY(next_chore_id) REFERENCES chores(id)
);

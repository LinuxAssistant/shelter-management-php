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

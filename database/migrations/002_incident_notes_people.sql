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

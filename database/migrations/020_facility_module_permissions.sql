CREATE TABLE staff_facility_permissions (
 user_id BIGINT UNSIGNED NOT NULL,
 facility_id BIGINT UNSIGNED NOT NULL,
 module ENUM('operations','kitchen','finance','volunteers','casework') NOT NULL,
 PRIMARY KEY(user_id,facility_id,module),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(facility_id) REFERENCES facilities(id) ON DELETE CASCADE
);

-- Resident access is facility-specific unless the employee has admin.manage.
-- No existing employee receives a new assignment automatically.
CREATE TABLE resident_facility_access (
    user_id BIGINT UNSIGNED NOT NULL,
    facility_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY(user_id,facility_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(facility_id) REFERENCES facilities(id) ON DELETE CASCADE
);

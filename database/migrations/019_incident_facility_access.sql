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

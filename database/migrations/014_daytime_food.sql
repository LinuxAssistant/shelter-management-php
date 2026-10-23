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

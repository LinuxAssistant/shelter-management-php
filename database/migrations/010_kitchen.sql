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

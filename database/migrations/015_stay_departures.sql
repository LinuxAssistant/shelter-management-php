-- Record how each stay ended, including staff-directed exits.
ALTER TABLE stays
 ADD COLUMN departure_type ENUM('voluntary','staff_exit') NULL,
 ADD COLUMN departure_reason VARCHAR(500) NULL,
 ADD COLUMN departed_by INT UNSIGNED NULL,
 ADD CONSTRAINT fk_stays_departed_by FOREIGN KEY (departed_by) REFERENCES users(id);

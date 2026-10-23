CREATE TABLE app_email_settings (
 id TINYINT UNSIGNED PRIMARY KEY,
 configured TINYINT(1) NOT NULL DEFAULT 0,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 from_address VARCHAR(255) NOT NULL DEFAULT '',
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(updated_by) REFERENCES users(id),
 CHECK(id=1)
);
INSERT INTO app_email_settings(id) VALUES(1);

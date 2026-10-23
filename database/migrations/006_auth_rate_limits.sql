CREATE TABLE auth_rate_limits (
 action_name VARCHAR(40) NOT NULL,
 identifier_hash CHAR(64) NOT NULL,
 window_start DATETIME NOT NULL,
 attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(action_name,identifier_hash),
 INDEX idx_auth_rate_window(window_start)
);

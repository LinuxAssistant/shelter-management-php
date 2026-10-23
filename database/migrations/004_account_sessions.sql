-- Apply once after migration 003.
ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1;

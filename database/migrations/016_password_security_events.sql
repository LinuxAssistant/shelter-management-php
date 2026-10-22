-- PR #6 reserves migration numbers 007-015 for shelter platform modules.
-- Apply once after 006; it does not depend on platform migrations 007-015.
CREATE TABLE password_security_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_name VARCHAR(60) NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 subject_id BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_password_event_subject(subject_id,created_at)
);

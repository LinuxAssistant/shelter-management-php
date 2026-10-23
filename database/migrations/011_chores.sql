-- Chore assignments may be assigned to staff, volunteers or residents.
-- The assignee must belong to the same facility (enforced by application validation).
CREATE TABLE chores (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 facility_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(160) NOT NULL,
 description TEXT NULL,
 area VARCHAR(100) NOT NULL DEFAULT '',
 assignee_type ENUM('unassigned','staff','volunteer','resident') NOT NULL DEFAULT 'unassigned',
 assignee_id BIGINT UNSIGNED NULL,
 due_at DATETIME NOT NULL,
 recurrence ENUM('none','daily','weekly') NOT NULL DEFAULT 'none',
 status ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
 completed_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (facility_id) REFERENCES facilities(id),
 INDEX idx_chore_due (facility_id,status,due_at)
);

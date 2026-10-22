CREATE TABLE chore_successors (
 parent_chore_id BIGINT UNSIGNED PRIMARY KEY,
 next_chore_id BIGINT UNSIGNED NOT NULL UNIQUE,
 FOREIGN KEY(parent_chore_id) REFERENCES chores(id),
 FOREIGN KEY(next_chore_id) REFERENCES chores(id)
);

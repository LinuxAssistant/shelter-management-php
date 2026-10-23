-- Migration 008: support single beds and upper/lower bunk positions.
-- Safe to rerun after a partial execution of the original migration.
-- The new unique index also supports the room_id foreign key.
-- Run against a backed-up database.

SET @schema_name = DATABASE();

SELECT COUNT(*) INTO @has_type FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='beds' AND COLUMN_NAME='bed_type';
SET @ddl = IF(@has_type=0,
 'ALTER TABLE beds ADD COLUMN bed_type ENUM(''single'',''bunk'') NOT NULL DEFAULT ''single''',
 'SELECT ''bed_type already exists''');
PREPARE statement FROM @ddl; EXECUTE statement; DEALLOCATE PREPARE statement;

SELECT COUNT(*) INTO @has_position FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='beds' AND COLUMN_NAME='position';
SET @ddl = IF(@has_position=0,
 'ALTER TABLE beds ADD COLUMN position ENUM(''single'',''lower'',''upper'') NOT NULL DEFAULT ''single''',
 'SELECT ''position already exists''');
PREPARE statement FROM @ddl; EXECUTE statement; DEALLOCATE PREPARE statement;

SELECT COUNT(*) INTO @has_new_index FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='beds' AND INDEX_NAME='uq_bed_position';
SET @ddl = IF(@has_new_index=0,
 'ALTER TABLE beds ADD UNIQUE KEY uq_bed_position (room_id,code,position)',
 'SELECT ''uq_bed_position already exists''');
PREPARE statement FROM @ddl; EXECUTE statement; DEALLOCATE PREPARE statement;

SELECT COUNT(*) INTO @has_old_index FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='beds' AND INDEX_NAME='uq_bed';
SET @ddl = IF(@has_old_index>0,
 'ALTER TABLE beds DROP INDEX uq_bed',
 'SELECT ''uq_bed already removed''');
PREPARE statement FROM @ddl; EXECUTE statement; DEALLOCATE PREPARE statement;

-- Add a physical bed type and assignable sleeping position.
-- Existing beds remain singles. Each bunk is represented by two rows
-- with the same code in a room, one lower and one upper.
ALTER TABLE beds
  ADD COLUMN bed_type ENUM('single','bunk') NOT NULL DEFAULT 'single',
  ADD COLUMN position ENUM('single','lower','upper') NOT NULL DEFAULT 'single';
ALTER TABLE beds DROP INDEX uq_bed;
ALTER TABLE beds ADD UNIQUE KEY uq_bed_position (room_id,code,position);

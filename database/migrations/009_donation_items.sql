-- Optional metadata for donated goods; existing donation records are preserved.
ALTER TABLE donations
  ADD COLUMN item_category ENUM('bedding','clothing','toiletries','food','household','misc') NULL,
  ADD COLUMN quantity DECIMAL(12,2) NULL,
  ADD COLUMN unit VARCHAR(40) NULL,
  ADD COLUMN estimated_value DECIMAL(12,2) NULL;

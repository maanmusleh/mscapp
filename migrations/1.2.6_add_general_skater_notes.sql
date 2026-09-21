-- CAT schema migration 1.2.6
-- Adds staff-only general notes separately from medical/accommodation notes.

SET @has_general_notes := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'skater'
    AND column_name = 'general_notes'
);
SET @general_notes_sql := IF(
  @has_general_notes = 0,
  'ALTER TABLE skater ADD COLUMN general_notes TEXT NULL AFTER parent_guardian_phone',
  'SELECT 1'
);
PREPARE general_notes_statement FROM @general_notes_sql;
EXECUTE general_notes_statement;
DEALLOCATE PREPARE general_notes_statement;

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.6', 'Add general notes to skater records')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- CAT schema migration 1.2.9
-- Adds a staff-authored report-card note to each skater record.

SET @has_report_card_notes := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'skater'
    AND column_name = 'report_card_notes'
);
SET @report_card_notes_sql := IF(
  @has_report_card_notes = 0,
  'ALTER TABLE skater ADD COLUMN report_card_notes TEXT NULL AFTER general_notes',
  'SELECT 1'
);
PREPARE report_card_notes_statement FROM @report_card_notes_sql;
EXECUTE report_card_notes_statement;
DEALLOCATE PREPARE report_card_notes_statement;

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.9', 'Add report-card notes to skater records')
ON DUPLICATE KEY UPDATE description = VALUES(description);

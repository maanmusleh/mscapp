-- CAT schema migration 1.2.11
-- Tracks the user and timestamp for each report-card note update.

ALTER TABLE skater
  ADD COLUMN report_card_note_updated_by_user_id BIGINT UNSIGNED NULL AFTER report_card_notes,
  ADD COLUMN report_card_note_updated_at TIMESTAMP NULL DEFAULT NULL AFTER report_card_note_updated_by_user_id,
  ADD KEY idx_skater_report_card_note_updated_by (report_card_note_updated_by_user_id),
  ADD CONSTRAINT fk_skater_report_card_note_updated_by
    FOREIGN KEY (report_card_note_updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL;

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.11', 'Track report-card note update details')
ON DUPLICATE KEY UPDATE description = VALUES(description);

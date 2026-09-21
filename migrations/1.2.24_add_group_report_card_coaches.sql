-- Assign report-card coaches to individual colour groups, rather than whole sessions.
ALTER TABLE program_group
  ADD COLUMN report_card_coach_user_id BIGINT UNSIGNED NULL AFTER colour_hex,
  ADD KEY idx_program_group_report_card_coach (report_card_coach_user_id),
  ADD CONSTRAINT fk_program_group_report_card_coach
    FOREIGN KEY (report_card_coach_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL;

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.24', 'Add colour-group report-card coach assignments');

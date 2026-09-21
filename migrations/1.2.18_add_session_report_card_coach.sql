-- CAT schema migration 1.2.18
-- Assigns one CAT user to supply the name and optional signature on a session's report cards.

ALTER TABLE program_session
  ADD COLUMN report_card_coach_user_id BIGINT UNSIGNED NULL AFTER location,
  ADD KEY idx_program_session_report_card_coach (report_card_coach_user_id),
  ADD CONSTRAINT fk_program_session_report_card_coach
    FOREIGN KEY (report_card_coach_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL;

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.18', 'Add per-session report-card coach assignments');

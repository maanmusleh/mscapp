-- NULL keeps unpositioned/new records after explicitly ordered records.
ALTER TABLE season ADD COLUMN display_order INT UNSIGNED NULL DEFAULT NULL AFTER end_date;
ALTER TABLE program_session ADD COLUMN display_order INT UNSIGNED NULL DEFAULT NULL AFTER report_card_coach_user_id;

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.25', 'Persistent registration display order');

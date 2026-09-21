-- CAT schema migration 1.2.14
-- Current CAT uses skater_season_report_card_note instead of the old
-- skater-level note fields. Retain the old fields as a recovery safeguard:
-- removing them is irreversible and is not required by the application.

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.14', 'Retain legacy report-card-note fields for recovery')
ON DUPLICATE KEY UPDATE description = VALUES(description);

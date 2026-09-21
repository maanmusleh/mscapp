-- CAT schema migration 1.2.12
-- Stores a normalized report-card signature for each application user.

ALTER TABLE app_user
  ADD COLUMN report_card_signature_png MEDIUMBLOB NULL AFTER totp_enabled_at,
  ADD COLUMN report_card_signature_width SMALLINT UNSIGNED NULL AFTER report_card_signature_png,
  ADD COLUMN report_card_signature_height SMALLINT UNSIGNED NULL AFTER report_card_signature_width,
  ADD COLUMN report_card_signature_updated_at TIMESTAMP NULL DEFAULT NULL AFTER report_card_signature_height;

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.12', 'Add per-user report-card signatures')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- CAT schema migration 1.2.10
-- Adds each user's reusable report-card note library.

CREATE TABLE IF NOT EXISTS report_card_note_library (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  app_user_id BIGINT UNSIGNED NOT NULL,
  note_title VARCHAR(32) NOT NULL,
  note_content VARCHAR(512) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_report_card_note_library_user_created (app_user_id, created_at),
  CONSTRAINT fk_report_card_note_library_user
    FOREIGN KEY (app_user_id) REFERENCES app_user (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.10', 'Add per-user report-card note libraries')
ON DUPLICATE KEY UPDATE description = VALUES(description);

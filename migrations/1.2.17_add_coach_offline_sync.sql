-- Coach App durable replay receipts and attendance conflict revisions.
ALTER TABLE attendance ADD COLUMN sync_revision BIGINT UNSIGNED NOT NULL DEFAULT 1;

CREATE TABLE rink_offline_change (
  club_id BIGINT UNSIGNED NOT NULL,
  app_user_id BIGINT UNSIGNED NOT NULL,
  operation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  response_json JSON NOT NULL,
  synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (club_id, app_user_id, operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.17', 'Add Coach App offline sync receipts and attendance revisions');

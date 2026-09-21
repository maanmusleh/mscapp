-- CAT schema migration 1.2.13
-- Stores report-card notes per skater and season.

CREATE TABLE skater_season_report_card_note (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  skater_id BIGINT UNSIGNED NOT NULL,
  season_id BIGINT UNSIGNED NOT NULL,
  note TEXT NOT NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_skater_season_report_card_note (skater_id, season_id),
  KEY idx_skater_season_report_card_note_season (season_id, skater_id),
  KEY idx_skater_season_report_card_note_updated_by (updated_by_user_id),
  CONSTRAINT fk_skater_season_report_card_note_skater
    FOREIGN KEY (skater_id) REFERENCES skater (id)
    ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT fk_skater_season_report_card_note_season
    FOREIGN KEY (season_id) REFERENCES season (id)
    ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT fk_skater_season_report_card_note_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A legacy note had no season. Preserve it in the skater's current season, or
-- in their most recent registered season when none is current.
INSERT INTO skater_season_report_card_note
  (skater_id, season_id, note, updated_by_user_id, created_at, updated_at)
SELECT
  s.id,
  (
    SELECT ps.season_id
    FROM skater_enrollment e
    INNER JOIN program_session ps ON ps.id = e.program_session_id AND ps.deleted_at IS NULL
    INNER JOIN season se ON se.id = ps.season_id AND se.deleted_at IS NULL
    WHERE e.skater_id = s.id AND e.deleted_at IS NULL
    ORDER BY
      CASE WHEN se.start_date <= CURDATE() AND se.end_date >= CURDATE() THEN 0 ELSE 1 END,
      se.end_date DESC,
      se.start_date DESC,
      e.id DESC
    LIMIT 1
  ),
  s.report_card_notes,
  s.report_card_note_updated_by_user_id,
  COALESCE(s.report_card_note_updated_at, s.updated_at, s.created_at),
  COALESCE(s.report_card_note_updated_at, s.updated_at, s.created_at)
FROM skater s
WHERE s.report_card_notes IS NOT NULL
  AND TRIM(s.report_card_notes) <> ''
  AND s.deleted_at IS NULL
  AND EXISTS (
    SELECT 1
    FROM skater_enrollment e
    INNER JOIN program_session ps ON ps.id = e.program_session_id AND ps.deleted_at IS NULL
    INNER JOIN season se ON se.id = ps.season_id AND se.deleted_at IS NULL
    WHERE e.skater_id = s.id AND e.deleted_at IS NULL
  );

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.13', 'Store report-card notes by skater and season')
ON DUPLICATE KEY UPDATE description = VALUES(description);

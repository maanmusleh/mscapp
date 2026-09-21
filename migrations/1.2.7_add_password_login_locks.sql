-- CAT schema migration 1.2.7
-- Persistent per-user password lock state: 10 minutes after five failures,
-- escalating to 24 hours after five more failures once the short lock expires.

SET @add_password_failed_attempts := (
  SELECT COUNT(*) = 0
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'app_user' AND column_name = 'password_failed_attempts'
);
SET @sql := IF(@add_password_failed_attempts,
  'ALTER TABLE app_user ADD COLUMN password_failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER must_change_password',
  'SELECT 1');
PREPARE password_lock_statement FROM @sql;
EXECUTE password_lock_statement;
DEALLOCATE PREPARE password_lock_statement;

SET @add_password_failure_window_started_at := (
  SELECT COUNT(*) = 0
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'app_user' AND column_name = 'password_failure_window_started_at'
);
SET @sql := IF(@add_password_failure_window_started_at,
  'ALTER TABLE app_user ADD COLUMN password_failure_window_started_at TIMESTAMP NULL DEFAULT NULL AFTER password_failed_attempts',
  'SELECT 1');
PREPARE password_lock_statement FROM @sql;
EXECUTE password_lock_statement;
DEALLOCATE PREPARE password_lock_statement;

SET @add_password_short_lock_issued_at := (
  SELECT COUNT(*) = 0
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'app_user' AND column_name = 'password_short_lock_issued_at'
);
SET @sql := IF(@add_password_short_lock_issued_at,
  'ALTER TABLE app_user ADD COLUMN password_short_lock_issued_at TIMESTAMP NULL DEFAULT NULL AFTER password_failure_window_started_at',
  'SELECT 1');
PREPARE password_lock_statement FROM @sql;
EXECUTE password_lock_statement;
DEALLOCATE PREPARE password_lock_statement;

SET @add_password_lock_until := (
  SELECT COUNT(*) = 0
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'app_user' AND column_name = 'password_lock_until'
);
SET @sql := IF(@add_password_lock_until,
  'ALTER TABLE app_user ADD COLUMN password_lock_until TIMESTAMP NULL DEFAULT NULL AFTER password_short_lock_issued_at',
  'SELECT 1');
PREPARE password_lock_statement FROM @sql;
EXECUTE password_lock_statement;
DEALLOCATE PREPARE password_lock_statement;

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.7', 'Add escalating password login locks')
ON DUPLICATE KEY UPDATE description = VALUES(description);

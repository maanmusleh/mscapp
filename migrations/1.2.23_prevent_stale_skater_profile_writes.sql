-- CAT schema migration 1.2.23
-- Microsecond skater versions let the dashboard reject a stale edit instead
-- of overwriting a profile saved from another browser tab.

ALTER TABLE skater
  MODIFY COLUMN updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    ON UPDATE CURRENT_TIMESTAMP(6);

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.23', 'Prevent stale skater profile writes');

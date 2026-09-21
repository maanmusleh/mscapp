-- CAT schema migration 1.2.19
-- A soft-deleted profile must not reserve a Skate Canada number forever.

UPDATE skater
SET skate_canada_number = NULL
WHERE deleted_at IS NOT NULL
  AND skate_canada_number IS NOT NULL;

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.19', 'Release Skate Canada numbers from deleted skaters');

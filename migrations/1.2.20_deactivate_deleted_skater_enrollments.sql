-- CAT schema migration 1.2.20
-- Deleted skater profiles must not retain active session memberships.

UPDATE skater_enrollment enrollment
INNER JOIN skater skater ON skater.id = enrollment.skater_id
SET enrollment.active = 0
WHERE skater.deleted_at IS NOT NULL
  AND enrollment.active = 1
  AND enrollment.deleted_at IS NULL;

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.20', 'Deactivate enrollments for deleted skaters');

-- Store and display each skater's gender as free-form text while retaining the
-- existing lookup relationship for backward compatibility.

ALTER TABLE skater
  ADD COLUMN gender_text VARCHAR(80) NULL AFTER gender_id;

UPDATE skater s
LEFT JOIN gender g ON g.id = s.gender_id
SET s.gender_text = g.name
WHERE s.gender_text IS NULL
  AND g.name IS NOT NULL;

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.16', 'Add free-form gender text to skater records');

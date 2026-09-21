-- CAT schema migration 1.2.8
-- Adds Pre-CanSkate participation and the eight introductory skill checks.

SET @drop_stage_check := (
  SELECT COUNT(*) > 0
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name = 'canskate_stage'
    AND constraint_name = 'chk_canskate_stage_number'
);
SET @sql := IF(@drop_stage_check,
  'ALTER TABLE canskate_stage DROP CHECK chk_canskate_stage_number',
  'SELECT 1');
PREPARE pre_canskate_statement FROM @sql;
EXECUTE pre_canskate_statement;
DEALLOCATE PREPARE pre_canskate_statement;

SET @add_stage_check := (
  SELECT COUNT(*) = 0
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name = 'canskate_stage'
    AND constraint_name = 'chk_canskate_stage_number'
);
SET @sql := IF(@add_stage_check,
  'ALTER TABLE canskate_stage ADD CONSTRAINT chk_canskate_stage_number CHECK (stage_number BETWEEN 0 AND 6)',
  'SELECT 1');
PREPARE pre_canskate_statement FROM @sql;
EXECUTE pre_canskate_statement;
DEALLOCATE PREPARE pre_canskate_statement;

INSERT INTO canskate_stage (stage_number, name, display_order, active)
VALUES (0, 'Pre-CanSkate', 0, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  display_order = VALUES(display_order),
  active = VALUES(active);

INSERT INTO canskate_ribbon (canskate_stage_id, name, display_order, active)
SELECT id, 'Pre-CanSkate', 10, 1
FROM canskate_stage
WHERE stage_number = 0
ON DUPLICATE KEY UPDATE
  display_order = VALUES(display_order),
  active = VALUES(active);

INSERT INTO canskate_category (canskate_ribbon_id, name, display_order, active)
SELECT ribbon.id, 'Pre-CanSkate', 10, 1
FROM canskate_ribbon AS ribbon
INNER JOIN canskate_stage AS stage_data ON stage_data.id = ribbon.canskate_stage_id
WHERE stage_data.stage_number = 0
  AND ribbon.name = 'Pre-CanSkate'
ON DUPLICATE KEY UPDATE
  display_order = VALUES(display_order),
  active = VALUES(active);

INSERT INTO canskate_skill
  (canskate_category_id, skill_code, name, display_order, active)
SELECT category.id, source.skill_code, source.name, source.display_order, 1
FROM canskate_category AS category
INNER JOIN canskate_ribbon AS ribbon ON ribbon.id = category.canskate_ribbon_id
INNER JOIN canskate_stage AS stage_data ON stage_data.id = ribbon.canskate_stage_id
INNER JOIN (
  SELECT 'PCS-PARTICIPATION' AS skill_code, 'Participation' AS name, 0 AS display_order
  UNION ALL SELECT 'PCS-SKL-001', 'Fall down & get up', 10
  UNION ALL SELECT 'PCS-SKL-002', 'Balance on two feet', 20
  UNION ALL SELECT 'PCS-SKL-003', 'Move forward', 30
  UNION ALL SELECT 'PCS-SKL-004', 'Make snow', 40
  UNION ALL SELECT 'PCS-SKL-005', 'Move backwards', 50
  UNION ALL SELECT 'PCS-SKL-006', 'Two-foot twist', 60
  UNION ALL SELECT 'PCS-SKL-007', '360° march', 70
  UNION ALL SELECT 'PCS-SKL-008', 'Two-foot jump', 80
) AS source
WHERE stage_data.stage_number = 0
  AND ribbon.name = 'Pre-CanSkate'
  AND category.name = 'Pre-CanSkate'
ON DUPLICATE KEY UPDATE
  canskate_category_id = VALUES(canskate_category_id),
  name = VALUES(name),
  display_order = VALUES(display_order),
  active = VALUES(active);

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.8', 'Add Pre-CanSkate participation and skills')
ON DUPLICATE KEY UPDATE description = VALUES(description);

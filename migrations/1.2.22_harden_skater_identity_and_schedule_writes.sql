-- CAT schema migration 1.2.22
-- A club can retain historical deleted profiles, but must never have more than
-- one active profile with the same first name, last name, and date of birth.

DELIMITER //
CREATE PROCEDURE cat_verify_active_skater_identity()
BEGIN
  IF EXISTS (
    SELECT 1
    FROM skater
    WHERE deleted_at IS NULL
    GROUP BY club_id, first_name, last_name, date_of_birth
    HAVING COUNT(*) > 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'CAT migration 1.2.22 stopped: merge duplicate active skater profiles with the same club, first name, last name, and date of birth before applying this update.';
  END IF;
END//
CALL cat_verify_active_skater_identity()//
DROP PROCEDURE cat_verify_active_skater_identity//
DELIMITER ;

ALTER TABLE skater
  ADD COLUMN active_identity_marker TINYINT UNSIGNED
    GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) STORED
    AFTER deleted_at,
  ADD UNIQUE KEY uq_skater_active_identity
    (club_id, first_name, last_name, date_of_birth, active_identity_marker);

-- Version values are sent with schedule forms to prevent a stale tab from
-- overwriting another administrator's edit. Microseconds avoid same-second
-- edits sharing a version.
ALTER TABLE season
  MODIFY COLUMN updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    ON UPDATE CURRENT_TIMESTAMP(6);

ALTER TABLE program_session
  MODIFY COLUMN updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    ON UPDATE CURRENT_TIMESTAMP(6);

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.22', 'Prevent duplicate active skater identities and stale schedule writes');

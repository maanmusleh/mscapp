-- CAT schema migration 1.2.21
-- CAT user accounts are installation-local. The former unique keys included a
-- nullable club_id, which permits duplicate NULL combinations in MySQL.

DELIMITER //
CREATE PROCEDURE cat_verify_global_user_identity()
BEGIN
  IF EXISTS (
    SELECT 1
    FROM app_user
    GROUP BY username
    HAVING COUNT(*) > 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'CAT migration 1.2.21 stopped: duplicate usernames must be resolved before enforcing global user identity.';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM app_user
    WHERE email IS NOT NULL
    GROUP BY email
    HAVING COUNT(*) > 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'CAT migration 1.2.21 stopped: duplicate email addresses must be resolved before enforcing global user identity.';
  END IF;
END//
CALL cat_verify_global_user_identity()//
DROP PROCEDURE cat_verify_global_user_identity//
DELIMITER ;

ALTER TABLE app_user
  ADD KEY idx_app_user_club (club_id),
  DROP INDEX uq_app_user_club_username,
  DROP INDEX uq_app_user_club_email,
  ADD UNIQUE KEY uq_app_user_username (username),
  ADD UNIQUE KEY uq_app_user_email (email);

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.21', 'Enforce installation-wide unique user usernames and email addresses');

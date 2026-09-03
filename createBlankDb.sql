-- ============================================================================
-- CAT fresh installation
--
-- For a new, empty MySQL/MariaDB database only. Select the database in
-- phpMyAdmin first, then import this file once. It does not create a database
-- and does not install sample club/skater data or user accounts.
--
-- After import, create the first administrator with bin/create_admin.php.
-- Existing installations must use the numbered migration files instead.
-- ============================================================================
-- ============================================================================
-- CanSkate Achievement Tracker (CAT) Version 1.0
-- Complete database creation and reference-data script
--
-- Target: MySQL 8.0.21+ or MariaDB 10.11+
-- Import: phpMyAdmin -> Import -> choose this file -> Go
--
-- Notes:
--   * The script is safe to import into a server on which the cat database
--     does not yet exist.
--   * It creates the database, all tables, keys, indexes, constraints, lookup
--     data, Pre-CanSkate plus six CanSkate stages, and 19 ribbons.
--   * Official CanSkate categories and skills are intentionally not reproduced.
--     They can be loaded later from an authorized Skate Canada curriculum source.
--   * A row in skater_ribbon or skater_badge means the award was actually given.
--     Awards are never created automatically.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;


-- ============================================================================
-- Schema version
-- ============================================================================

CREATE TABLE schema_migration (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_number        VARCHAR(30) NOT NULL,
  description           VARCHAR(255) NOT NULL,
  applied_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schema_migration_version (version_number)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Tracks installed CAT database versions.';

-- ============================================================================
-- Lookup tables (no ENUM columns)
-- ============================================================================

CREATE TABLE gender (
  id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                  VARCHAR(30) NOT NULL,
  name                  VARCHAR(80) NOT NULL,
  display_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gender_code (code),
  UNIQUE KEY uq_gender_name (name),
  CONSTRAINT chk_gender_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Optional gender values used by skater records.';

CREATE TABLE user_role (
  id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                  VARCHAR(30) NOT NULL,
  name                  VARCHAR(80) NOT NULL,
  description           VARCHAR(255) NULL,
  display_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_role_code (code),
  UNIQUE KEY uq_user_role_name (name),
  CONSTRAINT chk_user_role_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Application authorization roles.';

CREATE TABLE season_status (
  id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                  VARCHAR(30) NOT NULL,
  name                  VARCHAR(80) NOT NULL,
  display_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_season_status_code (code),
  UNIQUE KEY uq_season_status_name (name),
  CONSTRAINT chk_season_status_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Lifecycle states for a skating season.';

CREATE TABLE coach_role (
  id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                  VARCHAR(30) NOT NULL,
  name                  VARCHAR(80) NOT NULL,
  display_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coach_role_code (code),
  UNIQUE KEY uq_coach_role_name (name),
  CONSTRAINT chk_coach_role_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Roles used for coach-to-session assignments.';

CREATE TABLE attendance_status (
  id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                  VARCHAR(30) NOT NULL,
  name                  VARCHAR(80) NOT NULL,
  counts_as_present     TINYINT(1) NOT NULL DEFAULT 0,
  display_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_status_code (code),
  UNIQUE KEY uq_attendance_status_name (name),
  CONSTRAINT chk_attendance_counts_present
    CHECK (counts_as_present IN (0, 1)),
  CONSTRAINT chk_attendance_status_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Attendance outcomes for a scheduled lesson.';

CREATE TABLE assessment_result (
  id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                  VARCHAR(30) NOT NULL,
  name                  VARCHAR(80) NOT NULL,
  is_achieved           TINYINT(1) NOT NULL,
  display_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assessment_result_code (code),
  UNIQUE KEY uq_assessment_result_name (name),
  CONSTRAINT chk_assessment_result_achieved CHECK (is_achieved IN (0, 1)),
  CONSTRAINT chk_assessment_result_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='The two agreed assessment results: Achieved and Not Achieved.';

CREATE TABLE notification_type (
  id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                  VARCHAR(50) NOT NULL,
  name                  VARCHAR(100) NOT NULL,
  display_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notification_type_code (code),
  UNIQUE KEY uq_notification_type_name (name),
  CONSTRAINT chk_notification_type_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Extensible notification categories.';

-- ============================================================================
-- Organization and people
-- ============================================================================

CREATE TABLE club (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  club_name             VARCHAR(160) NOT NULL,
  time_zone             VARCHAR(64) NOT NULL DEFAULT 'America/Toronto',
  skate_canada_club_number VARCHAR(50) NULL,
  address_line_1        VARCHAR(160) NULL,
  address_line_2        VARCHAR(160) NULL,
  city                  VARCHAR(100) NULL,
  province              VARCHAR(80) NULL,
  postal_code           VARCHAR(20) NULL,
  country_code          CHAR(2) NOT NULL DEFAULT 'CA',
  phone                 VARCHAR(40) NULL,
  email                 VARCHAR(254) NULL,
  totp_policy           VARCHAR(12) NOT NULL DEFAULT 'OPTIONAL',
  active                TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_club_public_id (public_id),
  UNIQUE KEY uq_club_sc_number (skate_canada_club_number),
  KEY idx_club_name (club_name),
  KEY idx_club_active_deleted (active, deleted_at),
  KEY idx_club_created_by (created_by_user_id),
  KEY idx_club_updated_by (updated_by_user_id),
  CONSTRAINT chk_club_active CHECK (active IN (0, 1)),
  CONSTRAINT chk_club_totp_policy CHECK (totp_policy IN ('UNAVAILABLE', 'OPTIONAL', 'REQUIRED'))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Skating clubs using CAT.';

CREATE TABLE coach (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  club_id               BIGINT UNSIGNED NOT NULL,
  skate_canada_number   CHAR(10) NULL,
  first_name            VARCHAR(100) NOT NULL,
  last_name             VARCHAR(100) NOT NULL,
  email                 VARCHAR(254) NULL,
  phone                 VARCHAR(40) NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coach_public_id (public_id),
  UNIQUE KEY uq_coach_club_sc_number (club_id, skate_canada_number),
  KEY idx_coach_club_name (club_id, last_name, first_name),
  KEY idx_coach_club_active (club_id, active, deleted_at),
  KEY idx_coach_created_by (created_by_user_id),
  KEY idx_coach_updated_by (updated_by_user_id),
  CONSTRAINT fk_coach_club
    FOREIGN KEY (club_id) REFERENCES club (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_coach_skate_canada_number
    CHECK (
      skate_canada_number IS NULL
      OR skate_canada_number REGEXP '^[A-Za-z0-9]{10}$'
    ),
  CONSTRAINT chk_coach_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Coach identities; coaches are not permanently assigned to skaters.';

CREATE TABLE app_user (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  club_id               BIGINT UNSIGNED NULL,
  user_role_id          SMALLINT UNSIGNED NOT NULL,
  coach_id              BIGINT UNSIGNED NULL,
  first_name            VARCHAR(100) NULL,
  last_name             VARCHAR(100) NULL,
  username              VARCHAR(100) NOT NULL,
  password_hash         VARCHAR(255) NOT NULL,
  email                 VARCHAR(254) NULL,
  must_change_password  TINYINT(1) NOT NULL DEFAULT 0,
  password_failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  password_failure_window_started_at TIMESTAMP NULL DEFAULT NULL,
  password_short_lock_issued_at TIMESTAMP NULL DEFAULT NULL,
  password_lock_until   TIMESTAMP NULL DEFAULT NULL,
  totp_secret_ciphertext TEXT NULL,
  totp_enabled_at       TIMESTAMP NULL DEFAULT NULL,
  report_card_signature_png MEDIUMBLOB NULL,
  report_card_signature_width SMALLINT UNSIGNED NULL,
  report_card_signature_height SMALLINT UNSIGNED NULL,
  report_card_signature_updated_at TIMESTAMP NULL DEFAULT NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at         TIMESTAMP NULL DEFAULT NULL,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_user_public_id (public_id),
  UNIQUE KEY uq_app_user_club_username (club_id, username),
  UNIQUE KEY uq_app_user_club_email (club_id, email),
  UNIQUE KEY uq_app_user_coach (coach_id),
  KEY idx_app_user_role (user_role_id),
  KEY idx_app_user_club_active (club_id, active, deleted_at),
  KEY idx_app_user_created_by (created_by_user_id),
  KEY idx_app_user_updated_by (updated_by_user_id),
  CONSTRAINT fk_app_user_club
    FOREIGN KEY (club_id) REFERENCES club (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_app_user_role
    FOREIGN KEY (user_role_id) REFERENCES user_role (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_app_user_coach
    FOREIGN KEY (coach_id) REFERENCES coach (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_app_user_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_app_user_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_app_user_active CHECK (active IN (0, 1)),
  CONSTRAINT chk_app_user_must_change_password CHECK (must_change_password IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Login accounts, separate from coach identities.';

CREATE TABLE user_login_activity (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  app_user_id         BIGINT UNSIGNED NULL,
  actor_user_id       BIGINT UNSIGNED NULL,
  event_type          VARCHAR(50) NOT NULL,
  attempted_identity  VARCHAR(254) NULL,
  source_ip           VARCHAR(45) NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_login_activity_created (created_at),
  KEY idx_user_login_activity_user_created (app_user_id, created_at),
  KEY idx_user_login_activity_user_ip_created (app_user_id, source_ip, event_type, created_at),
  KEY idx_user_login_activity_ip_created (source_ip, event_type, created_at),
  KEY idx_user_login_activity_identity_ip_created (attempted_identity, source_ip, event_type, created_at),
  CONSTRAINT fk_user_login_activity_user
    FOREIGN KEY (app_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_user_login_activity_actor
    FOREIGN KEY (actor_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Authentication and account-management activity; entries older than 90 days are purged by the application.';

CREATE TABLE skater (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  club_id               BIGINT UNSIGNED NOT NULL,
  gender_id             SMALLINT UNSIGNED NULL,
  skate_canada_number   VARCHAR(100) NULL,
  first_name            VARCHAR(100) NOT NULL,
  last_name             VARCHAR(100) NOT NULL,
  date_of_birth         DATE NOT NULL,
  parent_guardian_name  VARCHAR(200) NULL,
  parent_guardian_email VARCHAR(254) NULL,
  parent_guardian_phone VARCHAR(40) NULL,
  general_notes         TEXT NULL,
  medical_notes         TEXT NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_skater_public_id (public_id),
  UNIQUE KEY uq_skater_club_sc_number (club_id, skate_canada_number),
  KEY idx_skater_club_name (club_id, last_name, first_name),
  KEY idx_skater_club_identity (club_id, first_name, last_name, date_of_birth),
  KEY idx_skater_club_dob (club_id, date_of_birth),
  KEY idx_skater_gender (gender_id),
  KEY idx_skater_club_active (club_id, active, deleted_at),
  KEY idx_skater_created_by (created_by_user_id),
  KEY idx_skater_updated_by (updated_by_user_id),
  CONSTRAINT fk_skater_club
    FOREIGN KEY (club_id) REFERENCES club (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_gender
    FOREIGN KEY (gender_id) REFERENCES gender (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_skater_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_skater_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_skater_skate_canada_number
    CHECK (
      skate_canada_number IS NULL
      OR skate_canada_number REGEXP '^[A-Za-z0-9]+$'
    ),
  CONSTRAINT chk_skater_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='CanSkate participant records.';

CREATE TABLE skater_audit_event (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  skater_id             BIGINT UNSIGNED NOT NULL,
  event_type            VARCHAR(32) NOT NULL,
  details               TEXT NULL,
  event_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by_user_id    BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_skater_audit_event_public_id (public_id),
  KEY idx_skater_audit_event_skater_date (skater_id, event_at),
  KEY idx_skater_audit_event_type (event_type),
  KEY idx_skater_audit_event_created_by (created_by_user_id),
  CONSTRAINT fk_skater_audit_event_skater
    FOREIGN KEY (skater_id) REFERENCES skater (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_audit_event_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_skater_audit_event_type
    CHECK (event_type IN ('ADDED', 'DELETED'))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit events for skater record additions and deletions.';

-- Add deferred audit keys after app_user exists.
ALTER TABLE club
  ADD CONSTRAINT fk_club_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  ADD CONSTRAINT fk_club_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL;

ALTER TABLE coach
  ADD CONSTRAINT fk_coach_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  ADD CONSTRAINT fk_coach_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL;

-- ============================================================================
-- CanSkate curriculum hierarchy: stage -> ribbon -> category -> skill
-- ============================================================================

CREATE TABLE canskate_stage (
  id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  stage_number          TINYINT UNSIGNED NOT NULL,
  name                  VARCHAR(100) NOT NULL,
  description           TEXT NULL,
  display_order         SMALLINT UNSIGNED NOT NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_canskate_stage_public_id (public_id),
  UNIQUE KEY uq_canskate_stage_number (stage_number),
  UNIQUE KEY uq_canskate_stage_name (name),
  UNIQUE KEY uq_canskate_stage_display_order (display_order),
  KEY idx_canskate_stage_active (active, deleted_at),
  KEY idx_canskate_stage_created_by (created_by_user_id),
  KEY idx_canskate_stage_updated_by (updated_by_user_id),
  CONSTRAINT fk_canskate_stage_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_canskate_stage_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_canskate_stage_number CHECK (stage_number BETWEEN 0 AND 6),
  CONSTRAINT chk_canskate_stage_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Pre-CanSkate plus the six CanSkate stages.';

CREATE TABLE canskate_ribbon (
  id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  canskate_stage_id     SMALLINT UNSIGNED NOT NULL,
  name                  VARCHAR(100) NOT NULL,
  description           TEXT NULL,
  display_order         SMALLINT UNSIGNED NOT NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_canskate_ribbon_public_id (public_id),
  UNIQUE KEY uq_canskate_ribbon_stage_name (canskate_stage_id, name),
  UNIQUE KEY uq_canskate_ribbon_stage_order
    (canskate_stage_id, display_order),
  KEY idx_canskate_ribbon_active (active, deleted_at),
  KEY idx_canskate_ribbon_created_by (created_by_user_id),
  KEY idx_canskate_ribbon_updated_by (updated_by_user_id),
  CONSTRAINT fk_canskate_ribbon_stage
    FOREIGN KEY (canskate_stage_id) REFERENCES canskate_stage (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_canskate_ribbon_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_canskate_ribbon_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_canskate_ribbon_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Balance, Control, and Agility ribbons within each stage.';

CREATE TABLE canskate_category (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  canskate_ribbon_id    SMALLINT UNSIGNED NOT NULL,
  name                  VARCHAR(160) NOT NULL,
  description           TEXT NULL,
  display_order         SMALLINT UNSIGNED NOT NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_canskate_category_public_id (public_id),
  UNIQUE KEY uq_canskate_category_ribbon_name (canskate_ribbon_id, name),
  UNIQUE KEY uq_canskate_category_ribbon_order
    (canskate_ribbon_id, display_order),
  KEY idx_canskate_category_active (active, deleted_at),
  KEY idx_canskate_category_created_by (created_by_user_id),
  KEY idx_canskate_category_updated_by (updated_by_user_id),
  CONSTRAINT fk_canskate_category_ribbon
    FOREIGN KEY (canskate_ribbon_id) REFERENCES canskate_ribbon (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_canskate_category_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_canskate_category_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_canskate_category_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Optional curriculum groupings within a ribbon.';

CREATE TABLE canskate_skill (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  canskate_category_id  BIGINT UNSIGNED NOT NULL,
  skill_code            VARCHAR(80) NULL,
  name                  VARCHAR(255) NOT NULL,
  description           TEXT NULL,
  display_order         SMALLINT UNSIGNED NOT NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_canskate_skill_public_id (public_id),
  UNIQUE KEY uq_canskate_skill_code (skill_code),
  UNIQUE KEY uq_canskate_skill_category_order
    (canskate_category_id, display_order),
  KEY idx_canskate_skill_category_name (canskate_category_id, name),
  KEY idx_canskate_skill_active (active, deleted_at),
  KEY idx_canskate_skill_created_by (created_by_user_id),
  KEY idx_canskate_skill_updated_by (updated_by_user_id),
  CONSTRAINT fk_canskate_skill_category
    FOREIGN KEY (canskate_category_id) REFERENCES canskate_category (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_canskate_skill_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_canskate_skill_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_canskate_skill_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual CanSkate curriculum elements imported from an authorized source.';

-- ============================================================================
-- Seasons, sessions, dates, and groups
-- ============================================================================

CREATE TABLE season (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  club_id               BIGINT UNSIGNED NOT NULL,
  season_status_id      SMALLINT UNSIGNED NOT NULL,
  name                  VARCHAR(120) NOT NULL,
  registration_open_date DATE NULL,
  registration_close_date DATE NULL,
  start_date            DATE NOT NULL,
  end_date              DATE NOT NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_season_public_id (public_id),
  UNIQUE KEY uq_season_club_name (club_id, name),
  KEY idx_season_club_dates (club_id, start_date, end_date),
  KEY idx_season_status (season_status_id),
  KEY idx_season_club_active (club_id, active, deleted_at),
  KEY idx_season_created_by (created_by_user_id),
  KEY idx_season_updated_by (updated_by_user_id),
  CONSTRAINT fk_season_club
    FOREIGN KEY (club_id) REFERENCES club (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_season_status
    FOREIGN KEY (season_status_id) REFERENCES season_status (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_season_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_season_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_season_dates CHECK (end_date >= start_date),
  CONSTRAINT chk_season_registration_dates CHECK (
    registration_close_date IS NULL
    OR registration_open_date IS NULL
    OR registration_close_date >= registration_open_date
  ),
  CONSTRAINT chk_season_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Top-level skating seasons such as Fall 2026.';

CREATE TABLE program_session (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  club_id               BIGINT UNSIGNED NOT NULL,
  season_id             BIGINT UNSIGNED NOT NULL,
  sku                   VARCHAR(64) NOT NULL,
  name                  VARCHAR(160) NOT NULL,
  day_of_week           TINYINT UNSIGNED NOT NULL
                          COMMENT '1=Monday through 7=Sunday',
  start_time            TIME NOT NULL,
  end_time              TIME NOT NULL,
  location              VARCHAR(160) NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_program_session_public_id (public_id),
  UNIQUE KEY uq_program_session_sku (sku),
  UNIQUE KEY uq_program_session_schedule
    (season_id, day_of_week, start_time, end_time, location),
  KEY idx_program_session_club (club_id),
  KEY idx_program_session_season (season_id),
  KEY idx_program_session_day_time (day_of_week, start_time),
  KEY idx_program_session_active (season_id, active, deleted_at),
  KEY idx_program_session_created_by (created_by_user_id),
  KEY idx_program_session_updated_by (updated_by_user_id),
  CONSTRAINT fk_program_session_club
    FOREIGN KEY (club_id) REFERENCES club (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_program_session_season
    FOREIGN KEY (season_id) REFERENCES season (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_program_session_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_program_session_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_program_session_day CHECK (day_of_week BETWEEN 1 AND 7),
  CONSTRAINT chk_program_session_times CHECK (end_time > start_time),
  CONSTRAINT chk_program_session_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='A recurring weekly CanSkate session within a season.';

CREATE TABLE program_date (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  program_session_id    BIGINT UNSIGNED NOT NULL,
  lesson_number         SMALLINT UNSIGNED NOT NULL,
  session_date          DATE NOT NULL,
  cancelled             TINYINT(1) NOT NULL DEFAULT 0,
  cancellation_reason   VARCHAR(255) NULL,
  notes                 TEXT NULL,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_program_date_public_id (public_id),
  UNIQUE KEY uq_program_date_lesson
    (program_session_id, lesson_number),
  UNIQUE KEY uq_program_date_date
    (program_session_id, session_date),
  KEY idx_program_date_session_date (session_date, program_session_id),
  KEY idx_program_date_cancelled (program_session_id, cancelled),
  KEY idx_program_date_created_by (created_by_user_id),
  KEY idx_program_date_updated_by (updated_by_user_id),
  CONSTRAINT fk_program_date_session
    FOREIGN KEY (program_session_id) REFERENCES program_session (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_program_date_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_program_date_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_program_date_lesson CHECK (lesson_number > 0),
  CONSTRAINT chk_program_date_cancelled CHECK (cancelled IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='One scheduled lesson date in a recurring program session.';

CREATE TABLE program_group (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  program_session_id    BIGINT UNSIGNED NOT NULL,
  name                  VARCHAR(100) NOT NULL,
  colour_hex            CHAR(7) NULL COMMENT 'Optional CSS colour such as #FF0000',
  display_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  notes                 TEXT NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_program_group_public_id (public_id),
  UNIQUE KEY uq_program_group_session_name (program_session_id, name),
  UNIQUE KEY uq_program_group_session_order
    (program_session_id, display_order),
  KEY idx_program_group_active (program_session_id, active, deleted_at),
  KEY idx_program_group_created_by (created_by_user_id),
  KEY idx_program_group_updated_by (updated_by_user_id),
  CONSTRAINT fk_program_group_session
    FOREIGN KEY (program_session_id) REFERENCES program_session (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_program_group_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_program_group_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_program_group_colour CHECK (
    colour_hex IS NULL
    OR colour_hex REGEXP '^#[0-9A-Fa-f]{6}$'
  ),
  CONSTRAINT chk_program_group_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Named skater groups belonging to a specific program session.';

-- ============================================================================
-- Enrollment and skater group history
-- ============================================================================

CREATE TABLE skater_enrollment (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  skater_id             BIGINT UNSIGNED NOT NULL,
  program_session_id    BIGINT UNSIGNED NOT NULL,
  registration_date     DATE NOT NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  notes                 TEXT NULL,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_skater_enrollment_public_id (public_id),
  UNIQUE KEY uq_skater_enrollment_skater_session
    (skater_id, program_session_id),
  KEY idx_skater_enrollment_session_active
    (program_session_id, active, deleted_at),
  KEY idx_skater_enrollment_skater_active
    (skater_id, active, deleted_at),
  KEY idx_skater_enrollment_created_by (created_by_user_id),
  KEY idx_skater_enrollment_updated_by (updated_by_user_id),
  CONSTRAINT fk_skater_enrollment_skater
    FOREIGN KEY (skater_id) REFERENCES skater (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_enrollment_session
    FOREIGN KEY (program_session_id) REFERENCES program_session (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_enrollment_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_skater_enrollment_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_skater_enrollment_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='A skater registration in one program session.';

CREATE TABLE group_assignment (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  skater_enrollment_id  BIGINT UNSIGNED NOT NULL,
  program_group_id      BIGINT UNSIGNED NULL COMMENT 'NULL records an explicit unassignment',
  effective_program_date_id BIGINT UNSIGNED NULL COMMENT 'Legacy lesson-effective anchor; NULL for session-level assignments.',
  assigned_by_coach_id  BIGINT UNSIGNED NULL,
  notes                 TEXT NULL,
  created_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_group_assignment_public_id (public_id),
  KEY idx_group_assignment_effective
    (skater_enrollment_id, effective_program_date_id),
  KEY idx_group_assignment_group_date
    (program_group_id, effective_program_date_id),
  KEY idx_group_assignment_coach (assigned_by_coach_id),
  KEY idx_group_assignment_created_by (created_by_user_id),
  CONSTRAINT fk_group_assignment_enrollment
    FOREIGN KEY (skater_enrollment_id) REFERENCES skater_enrollment (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_group_assignment_group
    FOREIGN KEY (program_group_id) REFERENCES program_group (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_group_assignment_effective_date
    FOREIGN KEY (effective_program_date_id) REFERENCES program_date (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_group_assignment_coach
    FOREIGN KEY (assigned_by_coach_id) REFERENCES coach (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_group_assignment_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
COMMENT='Append-only history of skater group changes for a session registration.';

-- ============================================================================
-- Coach scheduling
-- ============================================================================

CREATE TABLE coach_session_assignment (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  coach_id              BIGINT UNSIGNED NOT NULL,
  program_session_id    BIGINT UNSIGNED NOT NULL,
  coach_role_id         SMALLINT UNSIGNED NOT NULL,
  default_program_group_id BIGINT UNSIGNED NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  notes                 TEXT NULL,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coach_session_assignment_public_id (public_id),
  UNIQUE KEY uq_coach_session_assignment_coach_session
    (coach_id, program_session_id),
  KEY idx_coach_session_assignment_session_active
    (program_session_id, active, deleted_at),
  KEY idx_coach_session_assignment_role (coach_role_id),
  KEY idx_coach_session_assignment_default_group (default_program_group_id),
  KEY idx_coach_session_assignment_created_by (created_by_user_id),
  KEY idx_coach_session_assignment_updated_by (updated_by_user_id),
  CONSTRAINT fk_coach_session_assignment_coach
    FOREIGN KEY (coach_id) REFERENCES coach (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_coach_session_assignment_session
    FOREIGN KEY (program_session_id) REFERENCES program_session (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_coach_session_assignment_role
    FOREIGN KEY (coach_role_id) REFERENCES coach_role (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_coach_session_assignment_default_group
    FOREIGN KEY (default_program_group_id) REFERENCES program_group (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_coach_session_assignment_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_coach_session_assignment_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_coach_session_assignment_active CHECK (active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Season-long assignment of a coach to a program session.';

CREATE TABLE coach_assignment (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  program_date_id       BIGINT UNSIGNED NOT NULL,
  coach_session_assignment_id BIGINT UNSIGNED NOT NULL,
  program_group_id      BIGINT UNSIGNED NULL,
  assignment_sequence   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  start_time            TIME NULL,
  end_time              TIME NULL,
  notes                 TEXT NULL,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coach_assignment_public_id (public_id),
  UNIQUE KEY uq_coach_assignment_lesson_sequence
    (program_date_id, coach_session_assignment_id, assignment_sequence),
  KEY idx_coach_assignment_group
    (program_date_id, program_group_id),
  KEY idx_coach_assignment_session_assignment
    (coach_session_assignment_id),
  KEY idx_coach_assignment_created_by (created_by_user_id),
  KEY idx_coach_assignment_updated_by (updated_by_user_id),
  CONSTRAINT fk_coach_assignment_date
    FOREIGN KEY (program_date_id) REFERENCES program_date (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_coach_assignment_session_assignment
    FOREIGN KEY (coach_session_assignment_id)
    REFERENCES coach_session_assignment (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_coach_assignment_group
    FOREIGN KEY (program_group_id) REFERENCES program_group (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_coach_assignment_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_coach_assignment_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_coach_assignment_sequence CHECK (assignment_sequence > 0),
  CONSTRAINT chk_coach_assignment_times CHECK (
    end_time IS NULL OR start_time IS NULL OR end_time > start_time
  )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Lesson-specific coach/group assignment; group may be NULL for a floater.';

-- ============================================================================
-- Attendance and assessment history
-- ============================================================================

CREATE TABLE attendance (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  program_date_id       BIGINT UNSIGNED NOT NULL,
  skater_id             BIGINT UNSIGNED NOT NULL,
  attendance_status_id  SMALLINT UNSIGNED NOT NULL,
  recorded_by_user_id   BIGINT UNSIGNED NULL,
  notes                 TEXT NULL,
  recorded_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_public_id (public_id),
  UNIQUE KEY uq_attendance_lesson_skater (program_date_id, skater_id),
  KEY idx_attendance_skater (skater_id, program_date_id),
  KEY idx_attendance_status (attendance_status_id),
  KEY idx_attendance_recorded_by (recorded_by_user_id),
  KEY idx_attendance_updated_by (updated_by_user_id),
  CONSTRAINT fk_attendance_date
    FOREIGN KEY (program_date_id) REFERENCES program_date (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_skater
    FOREIGN KEY (skater_id) REFERENCES skater (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_status
    FOREIGN KEY (attendance_status_id) REFERENCES attendance_status (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_recorded_by
    FOREIGN KEY (recorded_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_attendance_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='One attendance outcome per skater and scheduled lesson.';

CREATE TABLE assessment_history (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  program_date_id       BIGINT UNSIGNED NULL,
  coach_assignment_id   BIGINT UNSIGNED NULL,
  skater_id             BIGINT UNSIGNED NOT NULL,
  canskate_skill_id     BIGINT UNSIGNED NOT NULL,
  assessment_result_id  SMALLINT UNSIGNED NOT NULL,
  notes                 TEXT NULL,
  assessed_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assessment_history_public_id (public_id),
  KEY idx_assessment_history_skater_skill
    (skater_id, canskate_skill_id, assessed_at),
  KEY idx_assessment_history_lesson (program_date_id, skater_id),
  KEY idx_assessment_history_coach_assignment (coach_assignment_id),
  KEY idx_assessment_history_skill_result
    (canskate_skill_id, assessment_result_id),
  KEY idx_assessment_history_created_by (created_by_user_id),
  CONSTRAINT fk_assessment_history_date
    FOREIGN KEY (program_date_id) REFERENCES program_date (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_assessment_history_coach_assignment
    FOREIGN KEY (coach_assignment_id) REFERENCES coach_assignment (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_assessment_history_skater
    FOREIGN KEY (skater_id) REFERENCES skater (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_assessment_history_skill
    FOREIGN KEY (canskate_skill_id) REFERENCES canskate_skill (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_assessment_history_result
    FOREIGN KEY (assessment_result_id) REFERENCES assessment_result (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_assessment_history_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only history of Achieved or Not Achieved skill assessments.';

CREATE TABLE skater_skill (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  skater_id             BIGINT UNSIGNED NOT NULL,
  canskate_skill_id     BIGINT UNSIGNED NOT NULL,
  assessment_history_id BIGINT UNSIGNED NOT NULL,
  achievement_date      DATE NOT NULL,
  achieved_by_coach_id  BIGINT UNSIGNED NULL,
  notes                 TEXT NULL,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_skater_skill_public_id (public_id),
  UNIQUE KEY uq_skater_skill_skater_skill (skater_id, canskate_skill_id),
  UNIQUE KEY uq_skater_skill_assessment (assessment_history_id),
  KEY idx_skater_skill_skill (canskate_skill_id, skater_id),
  KEY idx_skater_skill_coach (achieved_by_coach_id),
  KEY idx_skater_skill_date (achievement_date),
  KEY idx_skater_skill_created_by (created_by_user_id),
  KEY idx_skater_skill_updated_by (updated_by_user_id),
  CONSTRAINT fk_skater_skill_skater
    FOREIGN KEY (skater_id) REFERENCES skater (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_skill_skill
    FOREIGN KEY (canskate_skill_id) REFERENCES canskate_skill (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_skill_assessment
    FOREIGN KEY (assessment_history_id) REFERENCES assessment_history (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_skill_coach
    FOREIGN KEY (achieved_by_coach_id) REFERENCES coach (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_skill_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_skater_skill_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Current achieved-skill state; one row per skater and skill.';

-- ============================================================================
-- Manual ribbon and badge awards
-- ============================================================================

CREATE TABLE skater_ribbon (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  skater_id             BIGINT UNSIGNED NOT NULL,
  canskate_ribbon_id    SMALLINT UNSIGNED NOT NULL,
  program_date_id       BIGINT UNSIGNED NULL,
  coach_assignment_id   BIGINT UNSIGNED NULL,
  awarded_by_coach_id   BIGINT UNSIGNED NULL,
  awarded_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at            TIMESTAMP NULL DEFAULT NULL,
  revoked_by_user_id    BIGINT UNSIGNED NULL,
  notes                 TEXT NULL,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_skater_ribbon_public_id (public_id),
  UNIQUE KEY uq_skater_ribbon_skater_ribbon
    (skater_id, canskate_ribbon_id),
  KEY idx_skater_ribbon_ribbon (canskate_ribbon_id, awarded_at),
  KEY idx_skater_ribbon_program_date (program_date_id),
  KEY idx_skater_ribbon_coach_assignment (coach_assignment_id),
  KEY idx_skater_ribbon_awarded_by (awarded_by_coach_id),
  KEY idx_skater_ribbon_revoked_by (revoked_by_user_id),
  KEY idx_skater_ribbon_created_by (created_by_user_id),
  KEY idx_skater_ribbon_updated_by (updated_by_user_id),
  CONSTRAINT fk_skater_ribbon_skater
    FOREIGN KEY (skater_id) REFERENCES skater (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_ribbon_ribbon
    FOREIGN KEY (canskate_ribbon_id) REFERENCES canskate_ribbon (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_ribbon_date
    FOREIGN KEY (program_date_id) REFERENCES program_date (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_skater_ribbon_coach_assignment
    FOREIGN KEY (coach_assignment_id) REFERENCES coach_assignment (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_skater_ribbon_awarded_by
    FOREIGN KEY (awarded_by_coach_id) REFERENCES coach (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_ribbon_revoked_by
    FOREIGN KEY (revoked_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_skater_ribbon_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_skater_ribbon_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Manual record that a physical/official ribbon was actually awarded.';

CREATE TABLE skater_badge (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  skater_id             BIGINT UNSIGNED NOT NULL,
  canskate_stage_id     SMALLINT UNSIGNED NOT NULL,
  program_date_id       BIGINT UNSIGNED NULL,
  coach_assignment_id   BIGINT UNSIGNED NULL,
  awarded_by_coach_id   BIGINT UNSIGNED NULL,
  awarded_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at            TIMESTAMP NULL DEFAULT NULL,
  revoked_by_user_id    BIGINT UNSIGNED NULL,
  notes                 TEXT NULL,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_skater_badge_public_id (public_id),
  UNIQUE KEY uq_skater_badge_skater_stage (skater_id, canskate_stage_id),
  KEY idx_skater_badge_stage (canskate_stage_id, awarded_at),
  KEY idx_skater_badge_program_date (program_date_id),
  KEY idx_skater_badge_coach_assignment (coach_assignment_id),
  KEY idx_skater_badge_awarded_by (awarded_by_coach_id),
  KEY idx_skater_badge_revoked_by (revoked_by_user_id),
  KEY idx_skater_badge_created_by (created_by_user_id),
  KEY idx_skater_badge_updated_by (updated_by_user_id),
  CONSTRAINT fk_skater_badge_skater
    FOREIGN KEY (skater_id) REFERENCES skater (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_badge_stage
    FOREIGN KEY (canskate_stage_id) REFERENCES canskate_stage (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_badge_date
    FOREIGN KEY (program_date_id) REFERENCES program_date (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_skater_badge_coach_assignment
    FOREIGN KEY (coach_assignment_id) REFERENCES coach_assignment (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_skater_badge_awarded_by
    FOREIGN KEY (awarded_by_coach_id) REFERENCES coach (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_skater_badge_revoked_by
    FOREIGN KEY (revoked_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_skater_badge_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_skater_badge_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Manual record that a physical/official stage badge was actually awarded.';

-- ============================================================================
-- Application support tables
-- ============================================================================

CREATE TABLE application_setting (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  club_id               BIGINT UNSIGNED NOT NULL,
  setting_key           VARCHAR(120) NOT NULL,
  setting_value         TEXT NULL,
  value_type            VARCHAR(30) NOT NULL DEFAULT 'string',
  description           VARCHAR(500) NULL,
  created_by_user_id    BIGINT UNSIGNED NULL,
  updated_by_user_id    BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_application_setting_public_id (public_id),
  UNIQUE KEY uq_application_setting_club_key (club_id, setting_key),
  KEY idx_application_setting_key (setting_key),
  KEY idx_application_setting_created_by (created_by_user_id),
  KEY idx_application_setting_updated_by (updated_by_user_id),
  CONSTRAINT fk_application_setting_club
    FOREIGN KEY (club_id) REFERENCES club (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_application_setting_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_application_setting_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_application_setting_type
    CHECK (value_type IN ('string', 'integer', 'decimal', 'boolean', 'date', 'json'))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Club-specific configurable application values.';

CREATE TABLE saved_report (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  app_user_id           BIGINT UNSIGNED NOT NULL,
  report_name           VARCHAR(160) NOT NULL,
  report_key            VARCHAR(100) NOT NULL,
  filters_json          JSON NULL,
  shared_with_club      TINYINT(1) NOT NULL DEFAULT 0,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_saved_report_public_id (public_id),
  UNIQUE KEY uq_saved_report_user_name (app_user_id, report_name),
  KEY idx_saved_report_key (report_key),
  KEY idx_saved_report_shared (shared_with_club, deleted_at),
  CONSTRAINT fk_saved_report_user
    FOREIGN KEY (app_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_saved_report_shared CHECK (shared_with_club IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Saved report filters for future CAT reporting screens.';

CREATE TABLE notification (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()),
  club_id               BIGINT UNSIGNED NOT NULL,
  notification_type_id  SMALLINT UNSIGNED NOT NULL,
  app_user_id           BIGINT UNSIGNED NULL,
  title                 VARCHAR(200) NOT NULL,
  message               TEXT NOT NULL,
  context_json          JSON NULL,
  read_at               TIMESTAMP NULL DEFAULT NULL,
  dismissed_at          TIMESTAMP NULL DEFAULT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notification_public_id (public_id),
  KEY idx_notification_user_unread (app_user_id, read_at, created_at),
  KEY idx_notification_club_type
    (club_id, notification_type_id, created_at),
  KEY idx_notification_expiry (expires_at),
  CONSTRAINT fk_notification_club
    FOREIGN KEY (club_id) REFERENCES club (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_notification_type
    FOREIGN KEY (notification_type_id) REFERENCES notification_type (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_notification_user
    FOREIGN KEY (app_user_id) REFERENCES app_user (id)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Future reminders and workflow notifications.';

-- ============================================================================
-- Reference data
-- ============================================================================

INSERT INTO gender (id, code, name, display_order, active) VALUES
  (1, 'UNSPECIFIED', 'Unspecified', 10, 1),
  (2, 'FEMALE', 'Female', 20, 1),
  (3, 'MALE', 'Male', 30, 1),
  (4, 'NON_BINARY', 'Non-binary', 40, 1);

INSERT INTO user_role (id, code, name, description, display_order, active) VALUES
  (1, 'ADMINISTRATOR', 'Administrator',
   'Full access to club configuration and data.', 10, 1),
  (2, 'COACH', 'Coach',
   'Access to assigned sessions, attendance, assessments, and awards.', 20, 1),
  (3, 'REGISTRAR', 'Editor',
   'Access to skaters, enrollments, seasons, and sessions.', 30, 1),
  (4, 'READ_ONLY', 'Read Only',
   'View access without data-entry privileges.', 40, 1);

INSERT INTO season_status (id, code, name, display_order, active) VALUES
  (1, 'REGISTRATION_OPEN', 'Registration Open', 10, 1),
  (2, 'REGISTRATION_CLOSED', 'Registration Closed', 20, 1),
  (3, 'IN_PROGRESS', 'In Progress', 30, 1),
  (4, 'COMPLETED', 'Completed', 40, 1),
  (5, 'ARCHIVED', 'Archived', 50, 1);

INSERT INTO coach_role (id, code, name, display_order, active) VALUES
  (1, 'HEAD_COACH', 'Head Coach', 10, 1),
  (2, 'COACH', 'Coach', 20, 1),
  (3, 'PROGRAM_ASSISTANT', 'Program Assistant', 30, 1);

INSERT INTO attendance_status
  (id, code, name, counts_as_present, display_order, active)
VALUES
  (1, 'PRESENT', 'Present', 1, 10, 1),
  (2, 'ABSENT', 'Absent', 0, 20, 1),
  (3, 'EXCUSED', 'Excused', 0, 30, 1),
  (4, 'LATE', 'Late', 1, 40, 1);

INSERT INTO assessment_result
  (id, code, name, is_achieved, display_order, active)
VALUES
  (1, 'NOT_ACHIEVED', 'Not Achieved', 0, 10, 1),
  (2, 'ACHIEVED', 'Achieved', 1, 20, 1);

INSERT INTO notification_type (id, code, name, display_order, active) VALUES
  (1, 'RIBBON_ELIGIBLE', 'Ribbon Eligible', 10, 1),
  (2, 'BADGE_ELIGIBLE', 'Badge Eligible', 20, 1),
  (3, 'GROUP_UNASSIGNED', 'Group Unassigned', 30, 1),
  (4, 'ATTENDANCE_MISSING', 'Attendance Missing', 40, 1),
  (5, 'GENERAL', 'General', 50, 1);

INSERT INTO canskate_stage
  (id, stage_number, name, display_order, active)
VALUES
  (7, 0, 'Pre-CanSkate', 0, 1),
  (1, 1, 'Stage 1', 10, 1),
  (2, 2, 'Stage 2', 20, 1),
  (3, 3, 'Stage 3', 30, 1),
  (4, 4, 'Stage 4', 40, 1),
  (5, 5, 'Stage 5', 50, 1),
  (6, 6, 'Stage 6', 60, 1);

INSERT INTO canskate_ribbon
  (id, canskate_stage_id, name, display_order, active)
VALUES
  (19, 7, 'Pre-CanSkate', 10, 1),
  (1,  1, 'Balance', 10, 1),
  (2,  1, 'Control', 20, 1),
  (3,  1, 'Agility', 30, 1),
  (4,  2, 'Balance', 10, 1),
  (5,  2, 'Control', 20, 1),
  (6,  2, 'Agility', 30, 1),
  (7,  3, 'Balance', 10, 1),
  (8,  3, 'Control', 20, 1),
  (9,  3, 'Agility', 30, 1),
  (10, 4, 'Balance', 10, 1),
  (11, 4, 'Control', 20, 1),
  (12, 4, 'Agility', 30, 1),
  (13, 5, 'Balance', 10, 1),
  (14, 5, 'Control', 20, 1),
  (15, 5, 'Agility', 30, 1),
  (16, 6, 'Balance', 10, 1),
  (17, 6, 'Control', 20, 1),
  (18, 6, 'Agility', 30, 1);

INSERT INTO schema_migration (version_number, description)
VALUES ('1.0.0', 'Initial CAT Version 1.0 database schema and reference data.');

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- End of CAT Version 1.0 database creation script
-- ============================================================================


-- ============================================================================
-- CanSkate Achievement Tracker (CAT) Version 1.0
-- 02_CanSkateCurriculum.sql
--
-- Purpose:
--   Loads the CanSkate curriculum hierarchy and element names.
--   The CanSkate Content Overview in the December 2024 resource guide is the
--   source of truth for stage placement, skill names, and display order.
--
-- Contents:
--   * Pre-CanSkate plus 6 numbered stages
--   * 19 ribbons: one Pre-CanSkate ribbon and Balance, Control, and Agility for every numbered stage
--   * 18 matching categories
--   * 101 skill names with stable skill codes and display order
--
-- Deliberately excluded:
--   Element descriptions, performance requirements, coaching guidance, and
--   source-page text are not stored. Coaches use Skate Canada's current
--   materials, avoiding a second copy that could become outdated.
--
-- Import order:
--   1. 01_CreateDatabase.sql
--   2. 02_CanSkateCurriculum.sql
--
-- This script is idempotent and preserves assessment history.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

INSERT INTO canskate_stage
  (stage_number, name, display_order, active)
VALUES
  (0, 'Pre-CanSkate', 0, 1),
  (1, 'Stage 1', 10, 1),
  (2, 'Stage 2', 20, 1),
  (3, 'Stage 3', 30, 1),
  (4, 'Stage 4', 40, 1),
  (5, 'Stage 5', 50, 1),
  (6, 'Stage 6', 60, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  display_order = VALUES(display_order),
  active = VALUES(active);

INSERT INTO canskate_ribbon
  (canskate_stage_id, name, display_order, active)
SELECT stage_data.id, ribbon_data.name, ribbon_data.display_order, 1
FROM canskate_stage AS stage_data
CROSS JOIN (
  SELECT 'Balance' AS name, 10 AS display_order
  UNION ALL SELECT 'Control', 20
  UNION ALL SELECT 'Agility', 30
) AS ribbon_data
WHERE stage_data.stage_number BETWEEN 1 AND 6
ON DUPLICATE KEY UPDATE
  display_order = VALUES(display_order),
  active = VALUES(active);

INSERT INTO canskate_ribbon
  (canskate_stage_id, name, display_order, active)
SELECT id, 'Pre-CanSkate', 10, 1
FROM canskate_stage
WHERE stage_number = 0
ON DUPLICATE KEY UPDATE
  display_order = VALUES(display_order),
  active = VALUES(active);

INSERT INTO canskate_category
  (canskate_ribbon_id, name, display_order, active)
SELECT ribbon.id, ribbon.name, 10, 1
FROM canskate_ribbon AS ribbon
INNER JOIN canskate_stage AS stage_data
  ON stage_data.id = ribbon.canskate_stage_id
WHERE stage_data.stage_number BETWEEN 1 AND 6
  AND ribbon.name IN ('Balance', 'Control', 'Agility')
ON DUPLICATE KEY UPDATE
  display_order = VALUES(display_order),
  active = VALUES(active);

INSERT INTO canskate_category
  (canskate_ribbon_id, name, display_order, active)
SELECT ribbon.id, 'Pre-CanSkate', 10, 1
FROM canskate_ribbon AS ribbon
INNER JOIN canskate_stage AS stage_data ON stage_data.id = ribbon.canskate_stage_id
WHERE stage_data.stage_number = 0
  AND ribbon.name = 'Pre-CanSkate'
ON DUPLICATE KEY UPDATE
  display_order = VALUES(display_order),
  active = VALUES(active);

DROP TEMPORARY TABLE IF EXISTS tmp_canskate_curriculum;
CREATE TEMPORARY TABLE tmp_canskate_curriculum (
  stage_number TINYINT UNSIGNED NOT NULL,
  ribbon_name VARCHAR(100) NOT NULL,
  skill_code VARCHAR(80) NOT NULL,
  skill_name VARCHAR(255) NOT NULL,
  display_order SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (skill_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_canskate_curriculum
  (stage_number, ribbon_name, skill_code, skill_name, display_order)
VALUES
  (0, 'Pre-CanSkate', 'PCS-PARTICIPATION', 'Participation', 0),
  (0, 'Pre-CanSkate', 'PCS-SKL-001', 'Fall down & get up', 10),
  (0, 'Pre-CanSkate', 'PCS-SKL-002', 'Balance on two feet', 20),
  (0, 'Pre-CanSkate', 'PCS-SKL-003', 'Move forward', 30),
  (0, 'Pre-CanSkate', 'PCS-SKL-004', 'Make snow', 40),
  (0, 'Pre-CanSkate', 'PCS-SKL-005', 'Move backwards', 50),
  (0, 'Pre-CanSkate', 'PCS-SKL-006', 'Two-foot twist', 60),
  (0, 'Pre-CanSkate', 'PCS-SKL-007', '360° march', 70),
  (0, 'Pre-CanSkate', 'PCS-SKL-008', 'Two-foot jump', 80),
  (1, 'Balance', 'CS-S1-BAL-001', 'Fall down & get up', 10),
  (1, 'Balance', 'CS-S1-BAL-002', 'Forward skating', 20),
  (1, 'Balance', 'CS-S1-BAL-003', 'Forward two-foot glide', 30),
  (1, 'Balance', 'CS-S1-BAL-004', 'Forward two-foot sit glide', 40),
  (1, 'Control', 'CS-S1-CTL-001', 'Snow slide steps', 10),
  (1, 'Control', 'CS-S1-CTL-002', 'Backward skating', 20),
  (1, 'Control', 'CS-S1-CTL-003', 'Backward two-foot glide', 30),
  (1, 'Agility', 'CS-S1-AGI-001', 'Stationary 180° turn', 10),
  (1, 'Agility', 'CS-S1-AGI-002', 'Stationary two-foot jump', 20),
  (1, 'Agility', 'CS-S1-AGI-003', 'Forward skating perimeter of ice surface', 30),
  (2, 'Balance', 'CS-S2-BAL-001', 'Forward sculling', 10),
  (2, 'Balance', 'CS-S2-BAL-002', 'Forward two-foot to one-foot glide', 20),
  (2, 'Balance', 'CS-S2-BAL-003', 'Forward push/glide sequence', 30),
  (2, 'Balance', 'CS-S2-BAL-004', 'Forward one-foot glide with speed', 40),
  (2, 'Control', 'CS-S2-CTL-001', 'Forward stop', 10),
  (2, 'Control', 'CS-S2-CTL-002', 'Backward two-foot sit glide', 20),
  (2, 'Control', 'CS-S2-CTL-003', 'Backward two-foot to one-foot glide', 30),
  (2, 'Control', 'CS-S2-CTL-004', 'Backward push/glide sequence', 40),
  (2, 'Agility', 'CS-S2-AGI-001', 'Forward two-foot turn', 10),
  (2, 'Agility', 'CS-S2-AGI-002', 'Backward two-foot turn', 20),
  (2, 'Agility', 'CS-S2-AGI-003', 'Forward 180° glide turn', 30),
  (2, 'Agility', 'CS-S2-AGI-004', 'Forward two-foot jump', 40),
  (3, 'Balance', 'CS-S3-BAL-001', 'Forward stationary blade push', 10),
  (3, 'Balance', 'CS-S3-BAL-002', 'Forward two-foot slalom', 20),
  (3, 'Balance', 'CS-S3-BAL-003', 'Forward circle thrusts', 30),
  (3, 'Balance', 'CS-S3-BAL-004', 'Walking crosscuts', 40),
  (3, 'Balance', 'CS-S3-BAL-005', 'Forward two-foot to one-foot curve glide', 50),
  (3, 'Control', 'CS-S3-CTL-001', 'Forward stop with speed', 10),
  (3, 'Control', 'CS-S3-CTL-002', 'Backward sculling', 20),
  (3, 'Control', 'CS-S3-CTL-003', 'Backward two-foot to one-foot glide', 30),
  (3, 'Control', 'CS-S3-CTL-004', 'Backward push/glide sequence', 40),
  (3, 'Control', 'CS-S3-CTL-005', 'Backward one-foot glide', 50),
  (3, 'Agility', 'CS-S3-AGI-001', 'Forward two-foot quick turn', 10),
  (3, 'Agility', 'CS-S3-AGI-002', 'Backward two-foot quick turn', 20),
  (3, 'Agility', 'CS-S3-AGI-003', 'Forward 360° step turn', 30),
  (3, 'Agility', 'CS-S3-AGI-004', 'Backward two-foot jump', 40),
  (3, 'Agility', 'CS-S3-AGI-005', 'Fast forward perimeter skating', 50),
  (4, 'Balance', 'CS-S4-BAL-001', 'Forward crosscuts', 10),
  (4, 'Balance', 'CS-S4-BAL-002', 'Forward inside giant slalom', 20),
  (4, 'Balance', 'CS-S4-BAL-003', 'Forward outside giant slalom', 30),
  (4, 'Balance', 'CS-S4-BAL-004', 'Forward lunge', 40),
  (4, 'Balance', 'CS-S4-BAL-005', 'Forward spiral', 50),
  (4, 'Balance', 'CS-S4-BAL-006', 'Drop-down drill', 60),
  (4, 'Balance', 'CS-S4-BAL-007', 'Forward V start', 70),
  (4, 'Control', 'CS-S4-CTL-001', 'Backward stop', 10),
  (4, 'Control', 'CS-S4-CTL-002', 'Backward circle thrusts or pumps', 20),
  (4, 'Control', 'CS-S4-CTL-003', 'Backward two-foot slalom', 30),
  (4, 'Control', 'CS-S4-CTL-004', 'Backward one-foot glide with speed', 40),
  (4, 'Control', 'CS-S4-CTL-005', 'Sustained forward one-foot glide', 50),
  (4, 'Control', 'CS-S4-CTL-006', 'Speed drill #1', 60),
  (4, 'Agility', 'CS-S4-AGI-001', 'Forward one-foot turn', 10),
  (4, 'Agility', 'CS-S4-AGI-002', 'Backward 360° step turn', 20),
  (4, 'Agility', 'CS-S4-AGI-003', 'Forward to backward two-foot jump', 30),
  (4, 'Agility', 'CS-S4-AGI-004', 'Backward to forward two-foot jump', 40),
  (4, 'Agility', 'CS-S4-AGI-005', 'Two-foot spin', 50),
  (4, 'Agility', 'CS-S4-AGI-006', 'Two-foot sit spin', 60),
  (5, 'Balance', 'CS-S5-BAL-001', 'Forward crosscuts - figure 8', 10),
  (5, 'Balance', 'CS-S5-BAL-002', 'Forward inside edges', 20),
  (5, 'Balance', 'CS-S5-BAL-003', 'Forward push/glide sequence', 30),
  (5, 'Balance', 'CS-S5-BAL-004', 'Inside spread eagle', 40),
  (5, 'Balance', 'CS-S5-BAL-005', 'Forward one-foot slalom', 50),
  (5, 'Balance', 'CS-S5-BAL-006', 'Running lateral crossovers', 60),
  (5, 'Balance', 'CS-S5-BAL-007', 'Forward perimeter skating with jumps', 70),
  (5, 'Control', 'CS-S5-CTL-001', 'Forward two-foot side stop', 10),
  (5, 'Control', 'CS-S5-CTL-002', 'Backward stop with speed', 20),
  (5, 'Control', 'CS-S5-CTL-003', 'Backward crosscuts', 30),
  (5, 'Control', 'CS-S5-CTL-004', 'Backward inside giant slalom', 40),
  (5, 'Control', 'CS-S5-CTL-005', 'Backward push/glide sequence', 50),
  (5, 'Control', 'CS-S5-CTL-006', 'Backward spiral', 60),
  (5, 'Control', 'CS-S5-CTL-007', 'Speed drill #2', 70),
  (5, 'Agility', 'CS-S5-AGI-001', 'Forward one-foot turn', 10),
  (5, 'Agility', 'CS-S5-AGI-002', 'Forward 360° glide turn', 20),
  (5, 'Agility', 'CS-S5-AGI-003', 'Forward to backward one-foot jump', 30),
  (5, 'Agility', 'CS-S5-AGI-004', 'Forward power jump', 40),
  (5, 'Agility', 'CS-S5-AGI-005', 'One-foot spin', 50),
  (5, 'Agility', 'CS-S5-AGI-006', 'Alternating foot spin', 60),
  (5, 'Agility', 'CS-S5-AGI-007', 'Forward tight glide turns', 70),
  (6, 'Balance', 'CS-S6-BAL-001', 'Forward power crosscuts', 10),
  (6, 'Balance', 'CS-S6-BAL-002', 'Forward outside edges', 20),
  (6, 'Balance', 'CS-S6-BAL-003', 'Forward one-foot slalom', 30),
  (6, 'Balance', 'CS-S6-BAL-004', 'Forward one-foot sit glide', 40),
  (6, 'Balance', 'CS-S6-BAL-005', 'Forward spiral (curve or straight line)', 50),
  (6, 'Balance', 'CS-S6-BAL-006', 'Forward crossover acceleration', 60),
  (6, 'Balance', 'CS-S6-BAL-007', 'Forward perimeter skating with crosscuts', 70),
  (6, 'Balance', 'CS-S6-BAL-008', 'Forward perimeter skating with side stops', 80),
  (6, 'Control', 'CS-S6-CTL-001', 'Forward one-foot side stop', 10),
  (6, 'Control', 'CS-S6-CTL-002', 'Forward two-foot side stop with speed', 20),
  (6, 'Control', 'CS-S6-CTL-003', 'Backward outside giant slalom', 30),
  (6, 'Control', 'CS-S6-CTL-004', 'Backward crosscuts - figure 8', 40),
  (6, 'Control', 'CS-S6-CTL-005', 'Backward perimeter skating with crosscuts', 50),
  (6, 'Control', 'CS-S6-CTL-006', 'Backward one-foot slalom', 60),
  (6, 'Control', 'CS-S6-CTL-007', 'Backward one-foot spin', 70),
  (6, 'Control', 'CS-S6-CTL-008', 'Speed drill #3', 80),
  (6, 'Agility', 'CS-S6-AGI-001', 'Forward C Step', 10),
  (6, 'Agility', 'CS-S6-AGI-002', 'Backward C Step', 20),
  (6, 'Agility', 'CS-S6-AGI-003', 'Two-foot multi turns', 30),
  (6, 'Agility', 'CS-S6-AGI-004', 'Rotating power jump', 40),
  (6, 'Agility', 'CS-S6-AGI-005', 'Backward toe-assisted jump', 50),
  (6, 'Agility', 'CS-S6-AGI-006', 'Backward 360° two-foot jump', 60),
  (6, 'Agility', 'CS-S6-AGI-007', 'Forward one-foot spin with spiraling entry', 70),
  (6, 'Agility', 'CS-S6-AGI-008', 'Forward two-foot reverse pivot turn', 80);

INSERT INTO canskate_skill
  (canskate_category_id, skill_code, name, display_order, active)
SELECT category.id, source_data.skill_code, source_data.skill_name,
       source_data.display_order, 1
FROM tmp_canskate_curriculum AS source_data
INNER JOIN canskate_stage AS stage_data
  ON stage_data.stage_number = source_data.stage_number
INNER JOIN canskate_ribbon AS ribbon
  ON ribbon.canskate_stage_id = stage_data.id
 AND ribbon.name = source_data.ribbon_name
INNER JOIN canskate_category AS category
  ON category.canskate_ribbon_id = ribbon.id
 AND category.name = source_data.ribbon_name
ON DUPLICATE KEY UPDATE
  canskate_category_id = VALUES(canskate_category_id),
  name = VALUES(name),
  display_order = VALUES(display_order),
  active = VALUES(active);

DROP TEMPORARY TABLE tmp_canskate_curriculum;
COMMIT;

-- Validation summary displayed by phpMyAdmin after import.
SELECT stage_data.stage_number, stage_data.name AS stage_name,
       ribbon.name AS ribbon_name,
       COUNT(DISTINCT category.id) AS category_count,
       COUNT(DISTINCT skill.id) AS skill_count
FROM canskate_stage AS stage_data
INNER JOIN canskate_ribbon AS ribbon
  ON ribbon.canskate_stage_id = stage_data.id
LEFT JOIN canskate_category AS category
  ON category.canskate_ribbon_id = ribbon.id
 AND category.deleted_at IS NULL
LEFT JOIN canskate_skill AS skill
  ON skill.canskate_category_id = category.id
 AND skill.deleted_at IS NULL
WHERE stage_data.stage_number BETWEEN 0 AND 6
GROUP BY stage_data.stage_number, stage_data.name, ribbon.id,
         ribbon.name, ribbon.display_order
ORDER BY stage_data.stage_number, ribbon.display_order;

SELECT COUNT(*) AS curriculum_skill_count
FROM canskate_skill
WHERE skill_code LIKE 'CS-S%-BAL-%'
   OR skill_code LIKE 'CS-S%-CTL-%'
   OR skill_code LIKE 'CS-S%-AGI-%';
-- Expected result: 101
-- ============================================================================
-- End of 02_CanSkateCurriculum.sql
-- ============================================================================


-- ============================================================================
-- Current Coach App chat schema (versions 1.2.2–1.2.4)
-- ============================================================================

CREATE TABLE rink_chat_message (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) NOT NULL DEFAULT (UUID()),
  club_id BIGINT UNSIGNED NOT NULL,
  program_session_id BIGINT UNSIGNED NOT NULL,
  app_user_id BIGINT UNSIGNED NOT NULL,
  message_text VARCHAR(1500) NOT NULL,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  edited_at TIMESTAMP NULL DEFAULT NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rink_chat_message_public_id (public_id),
  KEY idx_rink_chat_session_created (program_session_id, created_at),
  KEY idx_rink_chat_expiry (expires_at),
  CONSTRAINT fk_rink_chat_message_club FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE RESTRICT,
  CONSTRAINT fk_rink_chat_message_session FOREIGN KEY (program_session_id) REFERENCES program_session (id) ON DELETE RESTRICT,
  CONSTRAINT fk_rink_chat_message_user FOREIGN KEY (app_user_id) REFERENCES app_user (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rink_chat_view (
  program_session_id BIGINT UNSIGNED NOT NULL,
  app_user_id BIGINT UNSIGNED NOT NULL,
  last_viewed_at TIMESTAMP NOT NULL,
  PRIMARY KEY (program_session_id, app_user_id),
  CONSTRAINT fk_rink_chat_view_session FOREIGN KEY (program_session_id) REFERENCES program_session (id) ON DELETE CASCADE,
  CONSTRAINT fk_rink_chat_view_user FOREIGN KEY (app_user_id) REFERENCES app_user (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE report_card_note_library (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  app_user_id BIGINT UNSIGNED NOT NULL,
  note_title VARCHAR(32) NOT NULL,
  note_content VARCHAR(512) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_report_card_note_library_user_created (app_user_id, created_at),
  CONSTRAINT fk_report_card_note_library_user FOREIGN KEY (app_user_id) REFERENCES app_user (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  CONSTRAINT fk_skater_season_report_card_note_skater FOREIGN KEY (skater_id) REFERENCES skater (id) ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT fk_skater_season_report_card_note_season FOREIGN KEY (season_id) REFERENCES season (id) ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT fk_skater_season_report_card_note_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES app_user (id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migration (version_number, description) VALUES
  ('1.1.0', 'Add user names and temporary-password enforcement'),
  ('1.2.0', 'Make user accounts installation-local rather than club-scoped'),
  ('1.2.1', 'Allow imported skater Skate Canada numbers of variable length'),
  ('1.2.2', 'Add session-scoped Rink App chat'),
  ('1.2.3', 'Allow Rink App chat messages to be edited'),
  ('1.2.4', 'Retain visible placeholders for deleted Rink App chat messages'),
  ('1.2.5', 'Add club time zone for calendar-day attendance'),
  ('1.2.6', 'Add general notes to skater records'),
  ('1.2.7', 'Add escalating password login locks'),
  ('1.2.8', 'Add Pre-CanSkate participation and skills'),
  ('1.2.9', 'Add report-card notes to skater records'),
  ('1.2.10', 'Add per-user report-card note libraries'),
  ('1.2.11', 'Track report-card note update details'),
  ('1.2.12', 'Add per-user report-card signatures'),
  ('1.2.13', 'Store report-card notes by skater and season'),
  ('1.2.14', 'Remove legacy skater-level report-card notes'),
  ('1.2.15', 'Add indexes for database-backed login throttling');

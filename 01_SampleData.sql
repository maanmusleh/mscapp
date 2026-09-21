-- ============================================================================
-- CanSkate Achievement Tracker (CAT) Version 1.0
-- 03_SampleData.sql
--
-- Purpose:
--   Creates a coherent demonstration dataset for development and testing.
--
-- Creates:
--   * 1 demo club
--   * 2 demo seasons
--   * 3 upcoming Fall sessions plus 2 completed Spring sessions
--   * 5 demo coaches
--   * 45 demo skaters
--   * Fall registrations: 36 skaters in 1 session, 6 in 2 sessions,
--     and 3 in all 3 sessions
--   * Program dates, groups, enrollments, group history, coach assignments,
--     and attendance records
--
-- Important:
--   This file deliberately does not create assessments or achievements. Import
--   02_CanSkateCurriculum.sql first, then add assessment test cases separately
--   when that workflow is being tested.
--
-- Re-running:
--   Natural unique keys and ON DUPLICATE KEY UPDATE make the script re-runnable.
--
-- Production warning:
--   This is fictional demonstration data. Do not import it into a production
--   database unless demo records are specifically wanted.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

USE cat;

START TRANSACTION;

-- ============================================================================
-- Lookup IDs
-- ============================================================================

SET @completed_status_id := (
  SELECT id FROM season_status WHERE code = 'COMPLETED' LIMIT 1
);
SET @registration_open_status_id := (
  SELECT id FROM season_status WHERE code = 'REGISTRATION_OPEN' LIMIT 1
);
SET @head_coach_role_id := (
  SELECT id FROM coach_role WHERE code = 'HEAD_COACH' LIMIT 1
);
SET @coach_role_id := (
  SELECT id FROM coach_role WHERE code = 'COACH' LIMIT 1
);
SET @program_assistant_role_id := (
  SELECT id FROM coach_role WHERE code = 'PROGRAM_ASSISTANT' LIMIT 1
);
SET @present_status_id := (
  SELECT id FROM attendance_status WHERE code = 'PRESENT' LIMIT 1
);
SET @absent_status_id := (
  SELECT id FROM attendance_status WHERE code = 'ABSENT' LIMIT 1
);
SET @excused_status_id := (
  SELECT id FROM attendance_status WHERE code = 'EXCUSED' LIMIT 1
);
SET @late_status_id := (
  SELECT id FROM attendance_status WHERE code = 'LATE' LIMIT 1
);

-- ============================================================================
-- Demo club
-- ============================================================================

INSERT INTO club
  (
    club_name,
    skate_canada_club_number,
    address_line_1,
    city,
    province,
    postal_code,
    country_code,
    phone,
    email,
    active
  )
VALUES
  (
    'CAT Demo Skating Club',
    'CAT-DEMO-001',
    '100 Demo Rink Road',
    'Ottawa',
    'Ontario',
    'K1A 0A1',
    'CA',
    '613-555-0100',
    'demo@example.invalid',
    1
  )
ON DUPLICATE KEY UPDATE
  club_name = VALUES(club_name),
  address_line_1 = VALUES(address_line_1),
  city = VALUES(city),
  province = VALUES(province),
  postal_code = VALUES(postal_code),
  country_code = VALUES(country_code),
  phone = VALUES(phone),
  email = VALUES(email),
  active = VALUES(active),
  deleted_at = NULL;

SET @demo_club_id := (
  SELECT id
  FROM club
  WHERE skate_canada_club_number = 'CAT-DEMO-001'
  LIMIT 1
);

-- ============================================================================
-- Five demo coaches
-- ============================================================================

INSERT INTO coach
  (
    public_id,
    club_id,
    skate_canada_number,
    first_name,
    last_name,
    email,
    phone,
    active
  )
VALUES
  (
    '00000000-0000-4000-8000-000000001001',
    @demo_club_id,
    NULL,
    'Jordan',
    'Lee',
    'jordan.lee@example.invalid',
    '613-555-0201',
    1
  ),
  (
    '00000000-0000-4000-8000-000000001002',
    @demo_club_id,
    NULL,
    'Casey',
    'Morgan',
    'casey.morgan@example.invalid',
    '613-555-0202',
    1
  ),
  (
    '00000000-0000-4000-8000-000000001003',
    @demo_club_id,
    NULL,
    'Taylor',
    'Singh',
    'taylor.singh@example.invalid',
    '613-555-0203',
    1
  ),
  (
    '00000000-0000-4000-8000-000000001004',
    @demo_club_id,
    NULL,
    'Morgan',
    'Roy',
    'morgan.roy@example.invalid',
    '613-555-0204',
    1
  ),
  (
    '00000000-0000-4000-8000-000000001005',
    @demo_club_id,
    NULL,
    'Riley',
    'Wilson',
    'riley.wilson@example.invalid',
    '613-555-0205',
    1
  )
ON DUPLICATE KEY UPDATE
  skate_canada_number = NULL,
  first_name = VALUES(first_name),
  last_name = VALUES(last_name),
  email = VALUES(email),
  phone = VALUES(phone),
  active = VALUES(active),
  deleted_at = NULL;

SET @coach_1_id := (
  SELECT id FROM coach
  WHERE club_id = @demo_club_id
    AND email = 'jordan.lee@example.invalid'
  LIMIT 1
);
SET @coach_2_id := (
  SELECT id FROM coach
  WHERE club_id = @demo_club_id
    AND email = 'casey.morgan@example.invalid'
  LIMIT 1
);
SET @coach_3_id := (
  SELECT id FROM coach
  WHERE club_id = @demo_club_id
    AND email = 'taylor.singh@example.invalid'
  LIMIT 1
);
SET @coach_4_id := (
  SELECT id FROM coach
  WHERE club_id = @demo_club_id
    AND email = 'morgan.roy@example.invalid'
  LIMIT 1
);
SET @coach_5_id := (
  SELECT id FROM coach
  WHERE club_id = @demo_club_id
    AND email = 'riley.wilson@example.invalid'
  LIMIT 1
);

-- ============================================================================
-- Forty-five fictional demo skaters
-- ============================================================================

INSERT INTO skater
  (
    public_id,
    club_id,
    skate_canada_number,
    first_name,
    last_name,
    date_of_birth,
    parent_guardian_name,
    parent_guardian_email,
    parent_guardian_phone,
    active
  )
VALUES
  ('00000000-0000-4000-8000-000000000001', @demo_club_id, NULL, 'Emma', 'Reed', '2018-02-14',
   'Jamie Reed', 'guardian01@example.invalid', '613-555-0301', 1),
  ('00000000-0000-4000-8000-000000000002', @demo_club_id, NULL, 'Noah', 'Campbell', '2017-11-03',
   'Avery Campbell', 'guardian02@example.invalid', '613-555-0302', 1),
  ('00000000-0000-4000-8000-000000000003', @demo_club_id, NULL, 'Olivia', 'Chen', '2018-06-21',
   'Robin Chen', 'guardian03@example.invalid', '613-555-0303', 1),
  ('00000000-0000-4000-8000-000000000004', @demo_club_id, NULL, 'Liam', 'Brooks', '2017-08-09',
   'Cameron Brooks', 'guardian04@example.invalid', '613-555-0304', 1),
  ('00000000-0000-4000-8000-000000000005', @demo_club_id, NULL, 'Ava', 'Martin', '2019-01-17',
   'Drew Martin', 'guardian05@example.invalid', '613-555-0305', 1),
  ('00000000-0000-4000-8000-000000000006', @demo_club_id, NULL, 'Ethan', 'Nguyen', '2018-03-28',
   'Alex Nguyen', 'guardian06@example.invalid', '613-555-0306', 1),
  ('00000000-0000-4000-8000-000000000007', @demo_club_id, NULL, 'Mia', 'Tremblay', '2017-12-12',
   'Sam Tremblay', 'guardian07@example.invalid', '613-555-0307', 1),
  ('00000000-0000-4000-8000-000000000008', @demo_club_id, NULL, 'Lucas', 'Patel', '2018-09-30',
   'Devon Patel', 'guardian08@example.invalid', '613-555-0308', 1),
  ('00000000-0000-4000-8000-000000000009', @demo_club_id, NULL, 'Sophia', 'Gagnon', '2019-04-05',
   'Charlie Gagnon', 'guardian09@example.invalid', '613-555-0309', 1),
  ('00000000-0000-4000-8000-000000000010', @demo_club_id, NULL, 'Jackson', 'Murphy', '2017-05-19',
   'Quinn Murphy', 'guardian10@example.invalid', '613-555-0310', 1),
  ('00000000-0000-4000-8000-000000000011', @demo_club_id, NULL, 'Isla', 'Kim', '2018-07-07',
   'Dana Kim', 'guardian11@example.invalid', '613-555-0311', 1),
  ('00000000-0000-4000-8000-000000000012', @demo_club_id, NULL, 'Leo', 'Bouchard', '2019-10-23',
   'Skyler Bouchard', 'guardian12@example.invalid', '613-555-0312', 1),
  ('00000000-0000-4000-8000-000000000013', @demo_club_id, NULL, 'Amelia', 'Scott', '2017-04-16',
   'Hayden Scott', 'guardian13@example.invalid', '613-555-0313', 1),
  ('00000000-0000-4000-8000-000000000014', @demo_club_id, NULL, 'Benjamin', 'Clarke', '2018-12-01',
   'Reese Clarke', 'guardian14@example.invalid', '613-555-0314', 1),
  ('00000000-0000-4000-8000-000000000015', @demo_club_id, NULL, 'Charlotte', 'Young', '2019-02-26',
   'Parker Young', 'guardian15@example.invalid', '613-555-0315', 1),
  ('00000000-0000-4000-8000-000000000016', @demo_club_id, NULL, 'Henry', 'Lavoie', '2017-09-14',
   'Rowan Lavoie', 'guardian16@example.invalid', '613-555-0316', 1),
  ('00000000-0000-4000-8000-000000000017', @demo_club_id, NULL, 'Evelyn', 'Adams', '2018-01-08',
   'Finley Adams', 'guardian17@example.invalid', '613-555-0317', 1),
  ('00000000-0000-4000-8000-000000000018', @demo_club_id, NULL, 'Theodore', 'White', '2019-06-11',
   'Dakota White', 'guardian18@example.invalid', '613-555-0318', 1),
  ('00000000-0000-4000-8000-000000000019', @demo_club_id, NULL, 'Harper', 'Fournier', '2017-07-25',
   'Emerson Fournier', 'guardian19@example.invalid', '613-555-0319', 1),
  ('00000000-0000-4000-8000-000000000020', @demo_club_id, NULL, 'Oliver', 'Wong', '2018-11-18',
   'Sawyer Wong', 'guardian20@example.invalid', '613-555-0320', 1),
  ('00000000-0000-4000-8000-000000000021', @demo_club_id, NULL, 'Grace', 'Wilson', '2017-03-12',
   'Alex Wilson', 'guardian21@example.invalid', '613-555-0321', 1),
  ('00000000-0000-4000-8000-000000000022', @demo_club_id, NULL, 'James', 'Anderson', '2018-05-24',
   'Morgan Anderson', 'guardian22@example.invalid', '613-555-0322', 1),
  ('00000000-0000-4000-8000-000000000023', @demo_club_id, NULL, 'Chloe', 'Dubois', '2019-07-15',
   'Taylor Dubois', 'guardian23@example.invalid', '613-555-0323', 1),
  ('00000000-0000-4000-8000-000000000024', @demo_club_id, NULL, 'Alexander', 'Brown', '2017-10-06',
   'Jordan Brown', 'guardian24@example.invalid', '613-555-0324', 1),
  ('00000000-0000-4000-8000-000000000025', @demo_club_id, NULL, 'Lily', 'MacDonald', '2018-08-19',
   'Casey MacDonald', 'guardian25@example.invalid', '613-555-0325', 1),
  ('00000000-0000-4000-8000-000000000026', @demo_club_id, NULL, 'William', 'Johnson', '2019-11-02',
   'Riley Johnson', 'guardian26@example.invalid', '613-555-0326', 1),
  ('00000000-0000-4000-8000-000000000027', @demo_club_id, NULL, 'Zoe', 'Pelletier', '2017-06-28',
   'Avery Pelletier', 'guardian27@example.invalid', '613-555-0327', 1),
  ('00000000-0000-4000-8000-000000000028', @demo_club_id, NULL, 'Daniel', 'Smith', '2018-04-10',
   'Cameron Smith', 'guardian28@example.invalid', '613-555-0328', 1),
  ('00000000-0000-4000-8000-000000000029', @demo_club_id, NULL, 'Hannah', 'Beaulieu', '2019-09-17',
   'Robin Beaulieu', 'guardian29@example.invalid', '613-555-0329', 1),
  ('00000000-0000-4000-8000-000000000030', @demo_club_id, NULL, 'Samuel', 'Evans', '2017-01-30',
   'Drew Evans', 'guardian30@example.invalid', '613-555-0330', 1),
  ('00000000-0000-4000-8000-000000000031', @demo_club_id, NULL, 'Nora', 'Desjardins', '2018-10-13',
   'Jamie Desjardins', 'guardian31@example.invalid', '613-555-0331', 1),
  ('00000000-0000-4000-8000-000000000032', @demo_club_id, NULL, 'Owen', 'Taylor', '2019-05-07',
   'Quinn Taylor', 'guardian32@example.invalid', '613-555-0332', 1),
  ('00000000-0000-4000-8000-000000000033', @demo_club_id, NULL, 'Layla', 'Robertson', '2017-12-22',
   'Dana Robertson', 'guardian33@example.invalid', '613-555-0333', 1),
  ('00000000-0000-4000-8000-000000000034', @demo_club_id, NULL, 'Jack', 'Fraser', '2018-02-05',
   'Sam Fraser', 'guardian34@example.invalid', '613-555-0334', 1),
  ('00000000-0000-4000-8000-000000000035', @demo_club_id, NULL, 'Alice', 'Girard', '2019-03-26',
   'Devon Girard', 'guardian35@example.invalid', '613-555-0335', 1),
  ('00000000-0000-4000-8000-000000000036', @demo_club_id, NULL, 'Felix', 'Moore', '2017-07-11',
   'Charlie Moore', 'guardian36@example.invalid', '613-555-0336', 1),
  ('00000000-0000-4000-8000-000000000037', @demo_club_id, NULL, 'Maya', 'Lefebvre', '2018-09-02',
   'Parker Lefebvre', 'guardian37@example.invalid', '613-555-0337', 1),
  ('00000000-0000-4000-8000-000000000038', @demo_club_id, NULL, 'Thomas', 'King', '2019-01-21',
   'Hayden King', 'guardian38@example.invalid', '613-555-0338', 1),
  ('00000000-0000-4000-8000-000000000039', @demo_club_id, NULL, 'Ellie', 'Renaud', '2017-05-09',
   'Reese Renaud', 'guardian39@example.invalid', '613-555-0339', 1),
  ('00000000-0000-4000-8000-000000000040', @demo_club_id, NULL, 'Nathan', 'Hall', '2018-12-14',
   'Rowan Hall', 'guardian40@example.invalid', '613-555-0340', 1),
  ('00000000-0000-4000-8000-000000000041', @demo_club_id, NULL, 'Ruby', 'Caron', '2019-06-03',
   'Finley Caron', 'guardian41@example.invalid', '613-555-0341', 1),
  ('00000000-0000-4000-8000-000000000042', @demo_club_id, NULL, 'Gabriel', 'Lewis', '2017-08-27',
   'Dakota Lewis', 'guardian42@example.invalid', '613-555-0342', 1),
  ('00000000-0000-4000-8000-000000000043', @demo_club_id, NULL, 'Sadie', 'Ouellet', '2018-01-31',
   'Emerson Ouellet', 'guardian43@example.invalid', '613-555-0343', 1),
  ('00000000-0000-4000-8000-000000000044', @demo_club_id, NULL, 'Mason', 'Walker', '2019-08-08',
   'Skyler Walker', 'guardian44@example.invalid', '613-555-0344', 1),
  ('00000000-0000-4000-8000-000000000045', @demo_club_id, NULL, 'Claire', 'Fortin', '2017-11-29',
   'Sawyer Fortin', 'guardian45@example.invalid', '613-555-0345', 1)
ON DUPLICATE KEY UPDATE
  skate_canada_number = NULL,
  first_name = VALUES(first_name),
  last_name = VALUES(last_name),
  date_of_birth = VALUES(date_of_birth),
  parent_guardian_name = VALUES(parent_guardian_name),
  parent_guardian_email = VALUES(parent_guardian_email),
  parent_guardian_phone = VALUES(parent_guardian_phone),
  active = VALUES(active),
  deleted_at = NULL;

-- ============================================================================
-- Two seasons
-- ============================================================================

INSERT INTO season
  (
    club_id,
    season_status_id,
    name,
    registration_open_date,
    registration_close_date,
    start_date,
    end_date,
    active
  )
VALUES
  (
    @demo_club_id,
    @completed_status_id,
    'Spring 2026 Demo',
    '2026-01-15',
    '2026-03-15',
    '2026-04-01',
    '2026-06-30',
    1
  ),
  (
    @demo_club_id,
    @registration_open_status_id,
    'Fall 2026 Demo',
    '2026-07-15',
    '2026-09-01',
    '2026-09-08',
    '2026-12-20',
    1
  )
ON DUPLICATE KEY UPDATE
  season_status_id = VALUES(season_status_id),
  registration_open_date = VALUES(registration_open_date),
  registration_close_date = VALUES(registration_close_date),
  start_date = VALUES(start_date),
  end_date = VALUES(end_date),
  active = VALUES(active),
  deleted_at = NULL;

SET @spring_season_id := (
  SELECT id FROM season
  WHERE club_id = @demo_club_id
    AND name = 'Spring 2026 Demo'
  LIMIT 1
);
SET @fall_season_id := (
  SELECT id FROM season
  WHERE club_id = @demo_club_id
    AND name = 'Fall 2026 Demo'
  LIMIT 1
);

-- ============================================================================
-- Five program sessions: two completed Spring and three upcoming Fall
-- ============================================================================

INSERT INTO program_session
  (
    club_id,
    season_id,
    sku,
    name,
    day_of_week,
    start_time,
    end_time,
    location,
    active
  )
VALUES
  (
    @demo_club_id,
    @spring_season_id,
    'SPR-THU-1700',
    'Thu 17:00-17:50',
    4,
    '17:00:00',
    '17:50:00',
    'Demo Rink A',
    1
  ),
  (
    @demo_club_id,
    @spring_season_id,
    'SPR-SAT-0900',
    'Sat 09:00-09:50',
    6,
    '09:00:00',
    '09:50:00',
    'Demo Rink A',
    1
  ),
  (
    @demo_club_id,
    @fall_season_id,
    'FALL-THU-1700',
    'Thu 17:00-17:50',
    4,
    '17:00:00',
    '17:50:00',
    'Demo Rink A',
    1
  ),
  (
    @demo_club_id,
    @fall_season_id,
    'FALL-SAT-0900',
    'Sat 09:00-09:50',
    6,
    '09:00:00',
    '09:50:00',
    'Demo Rink A',
    1
  ),
  (
    @demo_club_id,
    @fall_season_id,
    'FALL-SUN-1000',
    'Sun 10:00-10:50',
    7,
    '10:00:00',
    '10:50:00',
    'Demo Rink B',
    1
  )
ON DUPLICATE KEY UPDATE
  sku = VALUES(sku),
  name = VALUES(name),
  active = VALUES(active),
  deleted_at = NULL;

SET @spring_thursday_session_id := (
  SELECT id FROM program_session
  WHERE season_id = @spring_season_id
    AND day_of_week = 4
    AND start_time = '17:00:00'
  LIMIT 1
);
SET @spring_saturday_session_id := (
  SELECT id FROM program_session
  WHERE season_id = @spring_season_id
    AND day_of_week = 6
    AND start_time = '09:00:00'
  LIMIT 1
);
SET @fall_thursday_session_id := (
  SELECT id FROM program_session
  WHERE season_id = @fall_season_id
    AND day_of_week = 4
    AND start_time = '17:00:00'
  LIMIT 1
);
SET @fall_saturday_session_id := (
  SELECT id FROM program_session
  WHERE season_id = @fall_season_id
    AND day_of_week = 6
    AND start_time = '09:00:00'
  LIMIT 1
);
SET @fall_sunday_session_id := (
  SELECT id FROM program_session
  WHERE season_id = @fall_season_id
    AND day_of_week = 7
    AND start_time = '10:00:00'
  LIMIT 1
);

-- ============================================================================
-- Program dates
-- ============================================================================

INSERT INTO program_date
  (program_session_id, lesson_number, session_date, cancelled, notes)
VALUES
  (@spring_thursday_session_id, 1, '2026-04-02', 0, 'Demo lesson'),
  (@spring_thursday_session_id, 2, '2026-04-09', 0, 'Demo lesson'),
  (@spring_thursday_session_id, 3, '2026-04-16', 0, 'Demo lesson'),
  (@spring_thursday_session_id, 4, '2026-04-23', 0, 'Demo lesson'),
  (@spring_saturday_session_id, 1, '2026-04-04', 0, 'Demo lesson'),
  (@spring_saturday_session_id, 2, '2026-04-11', 0, 'Demo lesson'),
  (@spring_saturday_session_id, 3, '2026-04-18', 0, 'Demo lesson'),
  (@spring_saturday_session_id, 4, '2026-04-25', 0, 'Demo lesson'),
  (@fall_thursday_session_id, 1, '2026-09-10', 0, 'Demo lesson'),
  (@fall_thursday_session_id, 2, '2026-09-17', 0, 'Demo lesson'),
  (@fall_thursday_session_id, 3, '2026-09-24', 0, 'Demo lesson'),
  (@fall_thursday_session_id, 4, '2026-10-01', 0, 'Demo lesson'),
  (@fall_saturday_session_id, 1, '2026-09-12', 0, 'Demo lesson'),
  (@fall_saturday_session_id, 2, '2026-09-19', 0, 'Demo lesson'),
  (@fall_saturday_session_id, 3, '2026-09-26', 0, 'Demo lesson'),
  (@fall_saturday_session_id, 4, '2026-10-03', 0, 'Demo lesson'),
  (@fall_sunday_session_id, 1, '2026-09-13', 0, 'Demo lesson'),
  (@fall_sunday_session_id, 2, '2026-09-20', 0, 'Demo lesson'),
  (@fall_sunday_session_id, 3, '2026-09-27', 0, 'Demo lesson'),
  (@fall_sunday_session_id, 4, '2026-10-04', 0, 'Demo lesson')
ON DUPLICATE KEY UPDATE
  cancelled = VALUES(cancelled),
  cancellation_reason = NULL,
  notes = VALUES(notes);

-- ============================================================================
-- Program groups
-- ============================================================================

INSERT INTO program_group
  (
    program_session_id,
    name,
    colour_hex,
    display_order,
    notes,
    active
  )
VALUES
  (@spring_thursday_session_id, 'Red', '#DC3545', 10, 'Demo group', 1),
  (@spring_thursday_session_id, 'Green', '#198754', 20, 'Demo group', 1),
  (@spring_thursday_session_id, 'Orange', '#FD7E14', 30, 'Demo group', 1),
  (@spring_saturday_session_id, 'Blue', '#0D6EFD', 10, 'Demo group', 1),
  (@spring_saturday_session_id, 'Yellow', '#FFC107', 20, 'Demo group', 1),
  (@fall_thursday_session_id, 'Red', '#DC3545', 10, 'Demo group', 1),
  (@fall_thursday_session_id, 'Green', '#198754', 20, 'Demo group', 1),
  (@fall_thursday_session_id, 'Orange', '#FD7E14', 30, 'Demo group', 1),
  (@fall_saturday_session_id, 'Blue', '#0D6EFD', 10, 'Demo group', 1),
  (@fall_saturday_session_id, 'Yellow', '#FFC107', 20, 'Demo group', 1),
  (@fall_sunday_session_id, 'Purple', '#6F42C1', 10, 'Demo group', 1)
ON DUPLICATE KEY UPDATE
  colour_hex = VALUES(colour_hex),
  notes = VALUES(notes),
  active = VALUES(active),
  deleted_at = NULL;

SET @spring_thursday_red_id := (
  SELECT id FROM program_group
  WHERE program_session_id = @spring_thursday_session_id AND name = 'Red'
  LIMIT 1
);
SET @spring_thursday_green_id := (
  SELECT id FROM program_group
  WHERE program_session_id = @spring_thursday_session_id AND name = 'Green'
  LIMIT 1
);
SET @spring_thursday_orange_id := (
  SELECT id FROM program_group
  WHERE program_session_id = @spring_thursday_session_id AND name = 'Orange'
  LIMIT 1
);
SET @spring_saturday_blue_id := (
  SELECT id FROM program_group
  WHERE program_session_id = @spring_saturday_session_id AND name = 'Blue'
  LIMIT 1
);
SET @spring_saturday_yellow_id := (
  SELECT id FROM program_group
  WHERE program_session_id = @spring_saturday_session_id AND name = 'Yellow'
  LIMIT 1
);
SET @fall_thursday_red_id := (
  SELECT id FROM program_group
  WHERE program_session_id = @fall_thursday_session_id AND name = 'Red'
  LIMIT 1
);
SET @fall_thursday_green_id := (
  SELECT id FROM program_group
  WHERE program_session_id = @fall_thursday_session_id AND name = 'Green'
  LIMIT 1
);
SET @fall_thursday_orange_id := (
  SELECT id FROM program_group
  WHERE program_session_id = @fall_thursday_session_id AND name = 'Orange'
  LIMIT 1
);
SET @fall_saturday_blue_id := (
  SELECT id FROM program_group
  WHERE program_session_id = @fall_saturday_session_id AND name = 'Blue'
  LIMIT 1
);
SET @fall_saturday_yellow_id := (
  SELECT id FROM program_group
  WHERE program_session_id = @fall_saturday_session_id AND name = 'Yellow'
  LIMIT 1
);
SET @fall_sunday_purple_id := (
  SELECT id FROM program_group
  WHERE program_session_id = @fall_sunday_session_id AND name = 'Purple'
  LIMIT 1
);

-- ============================================================================
-- Enrollments
-- ============================================================================

-- First 12 skaters: Spring Thursday.
INSERT INTO skater_enrollment
  (skater_id, program_session_id, registration_date, active, notes)
SELECT
  sk.id,
  @spring_thursday_session_id,
  '2026-02-15',
  1,
  'Demo enrollment'
FROM skater AS sk
WHERE sk.club_id = @demo_club_id
  AND CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED)
      BETWEEN 1 AND 12
ON DUPLICATE KEY UPDATE
  registration_date = VALUES(registration_date),
  active = VALUES(active),
  notes = VALUES(notes),
  deleted_at = NULL;

-- Skaters 9 through 16: Spring Saturday.
INSERT INTO skater_enrollment
  (skater_id, program_session_id, registration_date, active, notes)
SELECT
  sk.id,
  @spring_saturday_session_id,
  '2026-02-20',
  1,
  'Demo enrollment'
FROM skater AS sk
WHERE sk.club_id = @demo_club_id
  AND CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED)
      BETWEEN 9 AND 16
ON DUPLICATE KEY UPDATE
  registration_date = VALUES(registration_date),
  active = VALUES(active),
  notes = VALUES(notes),
  deleted_at = NULL;

-- Fall Thursday: skaters 1-21.
-- Includes all 3 three-session skaters (1-3), all 6 two-session skaters
-- (4-9), and 12 single-session skaters (10-21).
INSERT INTO skater_enrollment
  (skater_id, program_session_id, registration_date, active, notes)
SELECT
  sk.id,
  @fall_thursday_session_id,
  '2026-07-20',
  1,
  'Demo enrollment'
FROM skater AS sk
WHERE sk.club_id = @demo_club_id
  AND CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED)
      BETWEEN 1 AND 21
ON DUPLICATE KEY UPDATE
  registration_date = VALUES(registration_date),
  active = VALUES(active),
  notes = VALUES(notes),
  deleted_at = NULL;

-- Fall Saturday: skaters 1-6 and 22-33.
INSERT INTO skater_enrollment
  (skater_id, program_session_id, registration_date, active, notes)
SELECT
  sk.id,
  @fall_saturday_session_id,
  '2026-07-22',
  1,
  'Demo enrollment'
FROM skater AS sk
WHERE sk.club_id = @demo_club_id
  AND (
    CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED)
        BETWEEN 1 AND 6
    OR CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED)
        BETWEEN 22 AND 33
  )
ON DUPLICATE KEY UPDATE
  registration_date = VALUES(registration_date),
  active = VALUES(active),
  notes = VALUES(notes),
  deleted_at = NULL;

-- Fall Sunday: skaters 1-3, 7-9, and 34-45.
INSERT INTO skater_enrollment
  (skater_id, program_session_id, registration_date, active, notes)
SELECT
  sk.id,
  @fall_sunday_session_id,
  '2026-07-24',
  1,
  'Demo enrollment'
FROM skater AS sk
WHERE sk.club_id = @demo_club_id
  AND (
    CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED)
        BETWEEN 1 AND 3
    OR CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED)
        BETWEEN 7 AND 9
    OR CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED)
        BETWEEN 34 AND 45
  )
ON DUPLICATE KEY UPDATE
  registration_date = VALUES(registration_date),
  active = VALUES(active),
  notes = VALUES(notes),
  deleted_at = NULL;

SET @spring_thursday_first_date_id := (
  SELECT id FROM program_date
  WHERE program_session_id = @spring_thursday_session_id
    AND lesson_number = 1
  LIMIT 1
);
SET @spring_thursday_third_date_id := (
  SELECT id FROM program_date
  WHERE program_session_id = @spring_thursday_session_id
    AND lesson_number = 3
  LIMIT 1
);
SET @spring_saturday_first_date_id := (
  SELECT id FROM program_date
  WHERE program_session_id = @spring_saturday_session_id
    AND lesson_number = 1
  LIMIT 1
);
SET @fall_thursday_first_date_id := (
  SELECT id FROM program_date
  WHERE program_session_id = @fall_thursday_session_id
    AND lesson_number = 1
  LIMIT 1
);
SET @fall_saturday_first_date_id := (
  SELECT id FROM program_date
  WHERE program_session_id = @fall_saturday_session_id
    AND lesson_number = 1
  LIMIT 1
);
SET @fall_sunday_first_date_id := (
  SELECT id FROM program_date
  WHERE program_session_id = @fall_sunday_session_id
    AND lesson_number = 1
  LIMIT 1
);

-- ============================================================================
-- Initial group assignments and one demonstration group move
-- ============================================================================

INSERT INTO group_assignment
  (
    skater_enrollment_id,
    program_group_id,
    effective_program_date_id,
    assigned_by_coach_id,
    notes
  )
SELECT
  enrollment.id,
  CASE MOD(
    CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED) - 1,
    3
  )
    WHEN 0 THEN @spring_thursday_red_id
    WHEN 1 THEN @spring_thursday_green_id
    ELSE @spring_thursday_orange_id
  END,
  @spring_thursday_first_date_id,
  @coach_1_id,
  'Initial demo group assignment'
FROM skater_enrollment AS enrollment
INNER JOIN skater AS sk
  ON sk.id = enrollment.skater_id
WHERE enrollment.program_session_id = @spring_thursday_session_id
ON DUPLICATE KEY UPDATE
  program_group_id = VALUES(program_group_id),
  assigned_by_coach_id = VALUES(assigned_by_coach_id),
  notes = VALUES(notes);

INSERT INTO group_assignment
  (
    skater_enrollment_id,
    program_group_id,
    effective_program_date_id,
    assigned_by_coach_id,
    notes
  )
SELECT
  enrollment.id,
  CASE MOD(
    CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED) - 1,
    2
  )
    WHEN 0 THEN @spring_saturday_blue_id
    ELSE @spring_saturday_yellow_id
  END,
  @spring_saturday_first_date_id,
  @coach_1_id,
  'Initial demo group assignment'
FROM skater_enrollment AS enrollment
INNER JOIN skater AS sk
  ON sk.id = enrollment.skater_id
WHERE enrollment.program_session_id = @spring_saturday_session_id
ON DUPLICATE KEY UPDATE
  program_group_id = VALUES(program_group_id),
  assigned_by_coach_id = VALUES(assigned_by_coach_id),
  notes = VALUES(notes);

INSERT INTO group_assignment
  (
    skater_enrollment_id,
    program_group_id,
    effective_program_date_id,
    assigned_by_coach_id,
    notes
  )
SELECT
  enrollment.id,
  CASE MOD(
    CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED) - 1,
    3
  )
    WHEN 0 THEN @fall_thursday_red_id
    WHEN 1 THEN @fall_thursday_green_id
    ELSE @fall_thursday_orange_id
  END,
  @fall_thursday_first_date_id,
  @coach_1_id,
  'Initial demo group assignment'
FROM skater_enrollment AS enrollment
INNER JOIN skater AS sk
  ON sk.id = enrollment.skater_id
WHERE enrollment.program_session_id = @fall_thursday_session_id
ON DUPLICATE KEY UPDATE
  program_group_id = VALUES(program_group_id),
  assigned_by_coach_id = VALUES(assigned_by_coach_id),
  notes = VALUES(notes);

INSERT INTO group_assignment
  (
    skater_enrollment_id,
    program_group_id,
    effective_program_date_id,
    assigned_by_coach_id,
    notes
  )
SELECT
  enrollment.id,
  CASE MOD(
    CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED) - 1,
    2
  )
    WHEN 0 THEN @fall_saturday_blue_id
    ELSE @fall_saturday_yellow_id
  END,
  @fall_saturday_first_date_id,
  @coach_1_id,
  'Initial demo group assignment'
FROM skater_enrollment AS enrollment
INNER JOIN skater AS sk
  ON sk.id = enrollment.skater_id
WHERE enrollment.program_session_id = @fall_saturday_session_id
ON DUPLICATE KEY UPDATE
  program_group_id = VALUES(program_group_id),
  assigned_by_coach_id = VALUES(assigned_by_coach_id),
  notes = VALUES(notes);

INSERT INTO group_assignment
  (
    skater_enrollment_id,
    program_group_id,
    effective_program_date_id,
    assigned_by_coach_id,
    notes
  )
SELECT
  enrollment.id,
  @fall_sunday_purple_id,
  @fall_sunday_first_date_id,
  @coach_1_id,
  'Initial demo group assignment'
FROM skater_enrollment AS enrollment
INNER JOIN skater AS sk
  ON sk.id = enrollment.skater_id
WHERE enrollment.program_session_id = @fall_sunday_session_id
ON DUPLICATE KEY UPDATE
  program_group_id = VALUES(program_group_id),
  assigned_by_coach_id = VALUES(assigned_by_coach_id),
  notes = VALUES(notes);

-- Demonstrates append-only group history: demo skater 004 moves in lesson 3.
INSERT INTO group_assignment
  (
    skater_enrollment_id,
    program_group_id,
    effective_program_date_id,
    assigned_by_coach_id,
    notes
  )
SELECT
  enrollment.id,
  @spring_thursday_orange_id,
  @spring_thursday_third_date_id,
  @coach_1_id,
  'Demo move to Orange beginning with lesson 3'
FROM skater_enrollment AS enrollment
INNER JOIN skater AS sk
  ON sk.id = enrollment.skater_id
WHERE enrollment.program_session_id = @spring_thursday_session_id
  AND sk.parent_guardian_email = 'guardian04@example.invalid'
ON DUPLICATE KEY UPDATE
  program_group_id = VALUES(program_group_id),
  assigned_by_coach_id = VALUES(assigned_by_coach_id),
  notes = VALUES(notes);

-- ============================================================================
-- Coach session assignments
-- ============================================================================

INSERT INTO coach_session_assignment
  (
    coach_id,
    program_session_id,
    coach_role_id,
    default_program_group_id,
    active,
    notes
  )
VALUES
  (@coach_1_id, @spring_thursday_session_id, @head_coach_role_id,
   NULL, 1, 'Demo head coach and floater'),
  (@coach_2_id, @spring_thursday_session_id, @coach_role_id,
   @spring_thursday_red_id, 1, 'Demo Red coach'),
  (@coach_3_id, @spring_thursday_session_id, @coach_role_id,
   @spring_thursday_green_id, 1, 'Demo Green coach'),
  (@coach_1_id, @spring_saturday_session_id, @head_coach_role_id,
   NULL, 1, 'Demo head coach and floater'),
  (@coach_4_id, @spring_saturday_session_id, @coach_role_id,
   @spring_saturday_blue_id, 1, 'Demo Blue coach'),
  (@coach_5_id, @spring_saturday_session_id, @program_assistant_role_id,
   @spring_saturday_yellow_id, 1, 'Demo Yellow program assistant'),
  (@coach_1_id, @fall_thursday_session_id, @head_coach_role_id,
   NULL, 1, 'Demo head coach and floater'),
  (@coach_2_id, @fall_thursday_session_id, @coach_role_id,
   @fall_thursday_red_id, 1, 'Demo Red coach'),
  (@coach_3_id, @fall_thursday_session_id, @coach_role_id,
   @fall_thursday_green_id, 1, 'Demo Green coach'),
  (@coach_4_id, @fall_thursday_session_id, @coach_role_id,
   @fall_thursday_orange_id, 1, 'Demo Orange coach'),
  (@coach_1_id, @fall_saturday_session_id, @head_coach_role_id,
   NULL, 1, 'Demo head coach and floater'),
  (@coach_4_id, @fall_saturday_session_id, @coach_role_id,
   @fall_saturday_blue_id, 1, 'Demo Blue coach'),
  (@coach_5_id, @fall_saturday_session_id, @program_assistant_role_id,
   @fall_saturday_yellow_id, 1, 'Demo Yellow program assistant'),
  (@coach_1_id, @fall_sunday_session_id, @head_coach_role_id,
   NULL, 1, 'Demo head coach and floater'),
  (@coach_2_id, @fall_sunday_session_id, @coach_role_id,
   @fall_sunday_purple_id, 1, 'Demo Purple coach'),
  (@coach_3_id, @fall_sunday_session_id, @coach_role_id,
   NULL, 1, 'Demo coach')
ON DUPLICATE KEY UPDATE
  coach_role_id = VALUES(coach_role_id),
  default_program_group_id = VALUES(default_program_group_id),
  active = VALUES(active),
  notes = VALUES(notes),
  deleted_at = NULL;

-- Create one lesson assignment from every season-level assignment and date.
INSERT INTO coach_assignment
  (
    program_date_id,
    coach_session_assignment_id,
    program_group_id,
    assignment_sequence,
    start_time,
    end_time,
    notes
  )
SELECT
  lesson.id,
  session_assignment.id,
  session_assignment.default_program_group_id,
  1,
  session_data.start_time,
  session_data.end_time,
  'Generated demo lesson assignment'
FROM coach_session_assignment AS session_assignment
INNER JOIN program_session AS session_data
  ON session_data.id = session_assignment.program_session_id
INNER JOIN program_date AS lesson
  ON lesson.program_session_id = session_data.id
WHERE session_data.club_id = @demo_club_id
  AND session_data.season_id IN (@spring_season_id, @fall_season_id)
  AND session_assignment.active = 1
ON DUPLICATE KEY UPDATE
  program_group_id = VALUES(program_group_id),
  start_time = VALUES(start_time),
  end_time = VALUES(end_time),
  notes = VALUES(notes);

-- ============================================================================
-- Attendance for completed Spring demo lessons
-- ============================================================================

INSERT INTO attendance
  (
    program_date_id,
    skater_id,
    attendance_status_id,
    notes
  )
SELECT
  lesson.id,
  enrollment.skater_id,
  CASE
    WHEN MOD(
      CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED),
      7
    ) = 0
      AND lesson.lesson_number = 2
      THEN @absent_status_id
    WHEN MOD(
      CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED),
      5
    ) = 0
      AND lesson.lesson_number = 3
      THEN @excused_status_id
    WHEN MOD(
      CAST(SUBSTRING(sk.parent_guardian_email, 9, 2) AS UNSIGNED),
      4
    ) = 0
      AND lesson.lesson_number = 1
      THEN @late_status_id
    ELSE @present_status_id
  END,
  'Generated demo attendance'
FROM skater_enrollment AS enrollment
INNER JOIN skater AS sk
  ON sk.id = enrollment.skater_id
INNER JOIN program_date AS lesson
  ON lesson.program_session_id = enrollment.program_session_id
INNER JOIN program_session AS session_data
  ON session_data.id = enrollment.program_session_id
WHERE session_data.season_id = @spring_season_id
ON DUPLICATE KEY UPDATE
  attendance_status_id = VALUES(attendance_status_id),
  notes = VALUES(notes);

COMMIT;

-- ============================================================================
-- Demo data summary displayed by phpMyAdmin after import
-- ============================================================================

SELECT
  c.club_name,
  (
    SELECT COUNT(*)
    FROM season AS s
    WHERE s.club_id = c.id
      AND s.name LIKE '% Demo'
  ) AS demo_season_count,
  (
    SELECT COUNT(*)
    FROM program_session AS ps
    INNER JOIN season AS s
      ON s.id = ps.season_id
    WHERE s.club_id = c.id
      AND s.name LIKE '% Demo'
  ) AS demo_session_count,
  (
    SELECT COUNT(*)
    FROM program_session AS ps
    WHERE ps.season_id = @fall_season_id
      AND ps.active = 1
      AND ps.deleted_at IS NULL
  ) AS fall_session_count,
  (
    SELECT COUNT(*)
    FROM coach AS co
    WHERE co.club_id = c.id
      AND co.email LIKE '%@example.invalid'
  ) AS demo_coach_count,
  (
    SELECT COUNT(*)
    FROM skater AS sk
    WHERE sk.club_id = c.id
      AND sk.parent_guardian_email REGEXP
          '^guardian[0-9]{2}@example[.]invalid$'
  ) AS demo_skater_count
FROM club AS c
WHERE c.id = @demo_club_id;

-- Expected Fall registration distribution:
--   36 skaters in exactly 1 session
--    6 skaters in exactly 2 sessions
--    3 skaters in exactly 3 sessions
SELECT
  fall_registration.session_count,
  COUNT(*) AS skater_count
FROM (
  SELECT
    enrollment.skater_id,
    COUNT(*) AS session_count
  FROM skater_enrollment AS enrollment
  INNER JOIN program_session AS session_data
    ON session_data.id = enrollment.program_session_id
  INNER JOIN skater AS sk
    ON sk.id = enrollment.skater_id
  WHERE session_data.season_id = @fall_season_id
    AND enrollment.active = 1
    AND enrollment.deleted_at IS NULL
    AND sk.club_id = @demo_club_id
    AND sk.parent_guardian_email REGEXP
        '^guardian[0-9]{2}@example[.]invalid$'
  GROUP BY enrollment.skater_id
) AS fall_registration
GROUP BY fall_registration.session_count
ORDER BY fall_registration.session_count;

-- ============================================================================
-- End of 03_SampleData.sql
-- ============================================================================

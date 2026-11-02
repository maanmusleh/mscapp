-- Different SKUs may share a season, day, time, and rink.
-- Retain a non-unique schedule index for lookups and keep SKU uniqueness.
ALTER TABLE program_session
  DROP INDEX uq_program_session_schedule,
  ADD KEY idx_program_session_schedule
    (season_id, day_of_week, start_time, end_time, location);

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.26', 'Allow sessions with different SKUs to share a rink and schedule');

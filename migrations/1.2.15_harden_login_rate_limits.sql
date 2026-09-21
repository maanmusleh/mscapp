-- Support database-backed password and authenticator throttling.
-- Apply once to existing CAT databases before deploying this release.

ALTER TABLE user_login_activity
  ADD KEY idx_user_login_activity_user_ip_created (app_user_id, source_ip, event_type, created_at),
  ADD KEY idx_user_login_activity_ip_created (source_ip, event_type, created_at),
  ADD KEY idx_user_login_activity_identity_ip_created (attempted_identity, source_ip, event_type, created_at);

INSERT INTO schema_migration (version_number, description)
VALUES ('1.2.15', 'Add indexes for database-backed login throttling');

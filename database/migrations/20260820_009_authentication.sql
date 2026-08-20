USE merchant_performance_report;

CREATE TABLE IF NOT EXISTS roles (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB;

INSERT INTO roles (code, name, description) VALUES
  ('super_admin', 'Super Admin', 'Akses penuh termasuk pengelolaan pengguna.'),
  ('admin', 'Admin', 'Mengimpor data, mengelola master data, dan membuat laporan.'),
  ('viewer', 'Viewer', 'Akses baca dashboard, laporan, dan ringkasan riwayat import.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) NOT NULL,
  username VARCHAR(50) NOT NULL,
  email VARCHAR(190) NULL,
  full_name VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role_id SMALLINT UNSIGNED NOT NULL COMMENT 'FK -> roles.id',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_public_id (public_id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_active (role_id, is_active),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_key CHAR(64) NOT NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts_key_time (attempt_key, attempted_at),
  KEY idx_login_attempts_time (attempted_at)
) ENGINE=InnoDB;

ALTER TABLE import_batches
  ADD COLUMN imported_by_user_id BIGINT UNSIGNED NULL COMMENT 'FK -> users.id' AFTER imported_by,
  ADD KEY idx_import_batches_imported_by_user (imported_by_user_id),
  ADD CONSTRAINT fk_import_batches_imported_by_user FOREIGN KEY (imported_by_user_id) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE transaction_change_history
  ADD COLUMN confirmed_by_user_id BIGINT UNSIGNED NULL COMMENT 'FK -> users.id' AFTER confirmed_by,
  ADD KEY idx_transaction_history_confirmed_by_user (confirmed_by_user_id),
  ADD CONSTRAINT fk_transaction_history_confirmed_by_user FOREIGN KEY (confirmed_by_user_id) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE payment_channel_change_history
  ADD COLUMN changed_by_user_id BIGINT UNSIGNED NULL COMMENT 'FK -> users.id' AFTER changed_by,
  ADD KEY idx_payment_channel_history_changed_by_user (changed_by_user_id),
  ADD CONSTRAINT fk_payment_channel_history_changed_by_user FOREIGN KEY (changed_by_user_id) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE incidents
  ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL COMMENT 'FK -> users.id' AFTER created_by,
  ADD KEY idx_incidents_created_by_user (created_by_user_id),
  ADD CONSTRAINT fk_incidents_created_by_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE report_runs
  ADD COLUMN generated_by_user_id BIGINT UNSIGNED NULL COMMENT 'FK -> users.id' AFTER generated_by,
  ADD KEY idx_report_runs_generated_by_user (generated_by_user_id),
  ADD CONSTRAINT fk_report_runs_generated_by_user FOREIGN KEY (generated_by_user_id) REFERENCES users (id) ON DELETE SET NULL;

INSERT INTO schema_migrations (version, description)
VALUES ('20260820_009', 'Add authentication, roles, users, login attempts, and user audit foreign keys')
ON DUPLICATE KEY UPDATE description = VALUES(description);

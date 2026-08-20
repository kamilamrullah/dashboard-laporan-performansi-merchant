-- Initial development schema for Merchant Performance Reporting.
-- Business status mappings are intentionally not seeded.
CREATE DATABASE IF NOT EXISTS merchant_performance_report
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE merchant_performance_report;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(64) NOT NULL,
  description VARCHAR(255) NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS merchants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  merchant_code VARCHAR(64) NOT NULL,
  merchant_name VARCHAR(160) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_merchants_code (merchant_code),
  UNIQUE KEY uq_merchants_name (merchant_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS import_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  merchant_id BIGINT UNSIGNED NULL COMMENT 'FK -> merchants.id',
  data_type VARCHAR(32) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  file_sha256 CHAR(64) NOT NULL,
  detected_period_start DATE NULL,
  detected_period_end DATE NULL,
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  valid_rows INT UNSIGNED NOT NULL DEFAULT 0,
  inserted_rows INT UNSIGNED NOT NULL DEFAULT 0,
  updated_rows INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_rows INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_rows INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'UPLOADED',
  failure_message VARCHAR(1000) NULL,
  confirmation_token_hash CHAR(64) NULL,
  confirmation_expires_at DATETIME NULL,
  imported_by VARCHAR(100) NULL,
  confirmed_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_import_batches_file_hash (file_sha256),
  KEY idx_import_batches_type_status (data_type, status),
  KEY idx_import_batches_preview_cleanup (data_type, status, created_at),
  KEY idx_import_batches_period (detected_period_start, detected_period_end),
  CONSTRAINT fk_import_batches_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_channels (
  sic_code VARCHAR(32) NOT NULL,
  channel_name VARCHAR(160) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  source_batch_id BIGINT UNSIGNED NULL COMMENT 'FK -> import_batches.id',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (sic_code),
  KEY idx_payment_channels_name (channel_name),
  CONSTRAINT fk_payment_channels_batch FOREIGN KEY (source_batch_id) REFERENCES import_batches (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS response_code_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  response_code VARCHAR(32) NOT NULL,
  transaction_type VARCHAR(64) NOT NULL DEFAULT '',
  status_group VARCHAR(24) NOT NULL,
  description VARCHAR(255) NULL,
  effective_from DATE NOT NULL,
  effective_until DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_response_rule_period (response_code, transaction_type, effective_from),
  KEY idx_response_rules_lookup (response_code, transaction_type, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transaction_aggregates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  merchant_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> merchants.id',
  transaction_date DATE NOT NULL,
  datasource VARCHAR(100) NOT NULL DEFAULT '',
  transaction_type VARCHAR(64) NOT NULL DEFAULT '',
  ca_id VARCHAR(64) NOT NULL DEFAULT '',
  partner_channel VARCHAR(160) NOT NULL DEFAULT '',
  biller VARCHAR(64) NOT NULL DEFAULT '',
  sic_code VARCHAR(32) NOT NULL DEFAULT '',
  response_code VARCHAR(32) NOT NULL DEFAULT '',
  total_trx BIGINT UNSIGNED NOT NULL,
  total_amount DECIMAL(20,2) NOT NULL,
  source_batch_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> import_batches.id',
  source_row_number INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transaction_natural_key (
    merchant_id, transaction_date, datasource, transaction_type, ca_id,
    partner_channel, biller, sic_code, response_code
  ),
  KEY idx_transactions_date_type (transaction_date, transaction_type),
  KEY idx_transactions_partner_channel (partner_channel),
  KEY idx_transactions_sic_code (sic_code),
  KEY idx_transactions_response_code (response_code),
  KEY idx_transactions_batch (source_batch_id),
  CONSTRAINT fk_transactions_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
  CONSTRAINT fk_transactions_batch FOREIGN KEY (source_batch_id) REFERENCES import_batches (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transaction_import_rows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> import_batches.id',
  source_row_number INT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NULL COMMENT 'FK -> transaction_aggregates.id',
  row_fingerprint CHAR(64) NOT NULL,
  outcome VARCHAR(24) NOT NULL,
  validation_errors JSON NULL,
  normalized_data JSON NULL,
  existing_data JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transaction_import_source (batch_id, source_row_number),
  KEY idx_transaction_import_outcome (batch_id, outcome),
  KEY idx_transaction_import_target (transaction_id),
  CONSTRAINT fk_transaction_import_batch FOREIGN KEY (batch_id) REFERENCES import_batches (id) ON DELETE CASCADE,
  CONSTRAINT fk_transaction_import_target FOREIGN KEY (transaction_id) REFERENCES transaction_aggregates (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transaction_change_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> transaction_aggregates.id',
  batch_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> import_batches.id',
  source_row_number INT UNSIGNED NOT NULL,
  old_data JSON NOT NULL,
  new_data JSON NOT NULL,
  confirmed_by VARCHAR(100) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_transaction_history_target (transaction_id, created_at),
  KEY idx_transaction_history_batch (batch_id),
  CONSTRAINT fk_transaction_history_target FOREIGN KEY (transaction_id) REFERENCES transaction_aggregates (id),
  CONSTRAINT fk_transaction_history_batch FOREIGN KEY (batch_id) REFERENCES import_batches (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS complaint_tickets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  merchant_id BIGINT UNSIGNED NULL COMMENT 'FK -> merchants.id',
  ticket_number VARCHAR(100) NOT NULL,
  status VARCHAR(64) NOT NULL DEFAULT '',
  product VARCHAR(160) NOT NULL DEFAULT '',
  service VARCHAR(160) NOT NULL DEFAULT '',
  complaint_segment VARCHAR(160) NOT NULL DEFAULT '',
  category VARCHAR(160) NOT NULL DEFAULT '',
  opened_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  duration_minutes INT UNSIGNED NULL,
  response_time_minutes INT UNSIGNED NULL,
  type_description VARCHAR(160) NOT NULL DEFAULT '',
  classification_flag VARCHAR(64) NOT NULL DEFAULT '',
  responsible_unit VARCHAR(160) NOT NULL DEFAULT '',
  source_batch_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> import_batches.id',
  source_row_number INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_complaint_tickets_number (ticket_number),
  KEY idx_complaint_tickets_opened (opened_at),
  KEY idx_complaint_tickets_status (status),
  KEY idx_complaint_tickets_segment (complaint_segment),
  KEY idx_complaint_tickets_flag (classification_flag),
  KEY idx_complaint_tickets_batch (source_batch_id),
  CONSTRAINT fk_complaint_tickets_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
  CONSTRAINT fk_complaint_tickets_batch FOREIGN KEY (source_batch_id) REFERENCES import_batches (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_import_rows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> import_batches.id',
  source_row_number INT UNSIGNED NOT NULL,
  ticket_id BIGINT UNSIGNED NULL COMMENT 'FK -> complaint_tickets.id',
  row_fingerprint CHAR(64) NOT NULL,
  outcome VARCHAR(24) NOT NULL,
  validation_errors JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_import_source (batch_id, source_row_number),
  KEY idx_ticket_import_outcome (batch_id, outcome),
  KEY idx_ticket_import_target (ticket_id),
  CONSTRAINT fk_ticket_import_batch FOREIGN KEY (batch_id) REFERENCES import_batches (id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_import_target FOREIGN KEY (ticket_id) REFERENCES complaint_tickets (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS incidents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  merchant_id BIGINT UNSIGNED NULL COMMENT 'FK -> merchants.id',
  report_period DATE NOT NULL,
  incident_date DATETIME NULL,
  title VARCHAR(255) NOT NULL,
  summary TEXT NULL,
  business_impact TEXT NULL,
  root_cause TEXT NULL,
  follow_up TEXT NULL,
  source_type VARCHAR(32) NOT NULL DEFAULT 'MANUAL',
  source_ticket_id BIGINT UNSIGNED NULL COMMENT 'FK -> complaint_tickets.id',
  created_by VARCHAR(100) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_incidents_period (merchant_id, report_period),
  CONSTRAINT fk_incidents_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id),
  CONSTRAINT fk_incidents_ticket FOREIGN KEY (source_ticket_id) REFERENCES complaint_tickets (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS report_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  merchant_id BIGINT UNSIGNED NULL COMMENT 'FK -> merchants.id',
  report_period DATE NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'PENDING',
  output_filename VARCHAR(255) NULL,
  output_sha256 CHAR(64) NULL,
  transaction_cutoff_at DATETIME NULL,
  ticket_cutoff_at DATETIME NULL,
  options_json JSON NULL,
  failure_message VARCHAR(1000) NULL,
  generated_by VARCHAR(100) NULL,
  generated_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_report_runs_period (merchant_id, report_period),
  KEY idx_report_runs_status (status),
  CONSTRAINT fk_report_runs_merchant FOREIGN KEY (merchant_id) REFERENCES merchants (id)
) ENGINE=InnoDB;

INSERT INTO schema_migrations (version, description)
VALUES
  ('20260819_001', 'Initial merchant performance schema'),
  ('20260819_002', 'Include merchant in transaction natural key'),
  ('20260819_003', 'Seed success rules verified from report template'),
  ('20260820_004', 'Add transaction preview staging and change history'),
  ('20260820_005', 'Prevent duplicate merchant names'),
  ('20260820_006', 'Document foreign key source columns'),
  ('20260820_007', 'Index expired transaction preview cleanup')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO response_code_rules
  (response_code, transaction_type, status_group, description, effective_from, is_active)
VALUES
  ('0', 'INQUIRY', 'SUCCESS', 'Inquiry sukses berdasarkan template laporan lama', '1900-01-01', 1),
  ('0', 'PAYMENT', 'SUCCESS', 'Payment sukses berdasarkan template laporan lama', '1900-01-01', 1)
ON DUPLICATE KEY UPDATE
  status_group = VALUES(status_group),
  description = VALUES(description),
  is_active = VALUES(is_active);

USE merchant_performance_report;

ALTER TABLE import_batches
  ADD COLUMN confirmation_token_hash CHAR(64) NULL AFTER failure_message,
  ADD COLUMN confirmation_expires_at DATETIME NULL AFTER confirmation_token_hash;

ALTER TABLE transaction_import_rows
  ADD COLUMN normalized_data JSON NULL AFTER validation_errors,
  ADD COLUMN existing_data JSON NULL AFTER normalized_data;

CREATE TABLE IF NOT EXISTS transaction_change_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NOT NULL,
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

INSERT INTO schema_migrations (version, description)
VALUES ('20260820_004', 'Add transaction preview staging and change history')
ON DUPLICATE KEY UPDATE description = VALUES(description);

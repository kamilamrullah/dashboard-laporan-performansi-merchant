USE merchant_performance_report;

CREATE TABLE IF NOT EXISTS payment_channel_change_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sic_code VARCHAR(32) NOT NULL COMMENT 'FK -> payment_channels.sic_code',
  source_batch_id BIGINT UNSIGNED NULL COMMENT 'FK -> import_batches.id',
  action VARCHAR(24) NOT NULL,
  old_channel_name VARCHAR(160) NULL,
  new_channel_name VARCHAR(160) NULL,
  old_is_active TINYINT(1) NULL,
  new_is_active TINYINT(1) NULL,
  changed_by VARCHAR(100) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payment_channel_history_sic (sic_code, created_at),
  KEY idx_payment_channel_history_batch (source_batch_id),
  CONSTRAINT fk_payment_channel_history_sic FOREIGN KEY (sic_code) REFERENCES payment_channels (sic_code),
  CONSTRAINT fk_payment_channel_history_batch FOREIGN KEY (source_batch_id) REFERENCES import_batches (id)
) ENGINE=InnoDB;

INSERT INTO schema_migrations (version, description)
VALUES ('20260820_008', 'Add payment channel change history')
ON DUPLICATE KEY UPDATE description = VALUES(description);

USE merchant_performance_report;

ALTER TABLE ticket_import_rows
  ADD COLUMN normalized_data JSON NULL AFTER validation_errors,
  ADD COLUMN existing_data JSON NULL AFTER normalized_data;

CREATE TABLE IF NOT EXISTS ticket_change_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> complaint_tickets.id',
  batch_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> import_batches.id',
  source_row_number INT UNSIGNED NOT NULL,
  old_data JSON NOT NULL,
  new_data JSON NOT NULL,
  confirmed_by_user_id BIGINT UNSIGNED NULL COMMENT 'FK -> users.id',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ticket_history_target (ticket_id, created_at),
  KEY idx_ticket_history_batch (batch_id),
  KEY idx_ticket_history_confirmed_by_user (confirmed_by_user_id),
  CONSTRAINT fk_ticket_history_target FOREIGN KEY (ticket_id) REFERENCES complaint_tickets (id),
  CONSTRAINT fk_ticket_history_batch FOREIGN KEY (batch_id) REFERENCES import_batches (id),
  CONSTRAINT fk_ticket_history_confirmed_by_user FOREIGN KEY (confirmed_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO schema_migrations (version, description)
VALUES ('20260821_010', 'Add ticket import preview data and ticket change history')
ON DUPLICATE KEY UPDATE description = VALUES(description);

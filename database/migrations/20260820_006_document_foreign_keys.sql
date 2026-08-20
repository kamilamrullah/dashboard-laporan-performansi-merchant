USE merchant_performance_report;

ALTER TABLE import_batches
  MODIFY merchant_id BIGINT UNSIGNED NULL COMMENT 'FK -> merchants.id';

ALTER TABLE payment_channels
  MODIFY source_batch_id BIGINT UNSIGNED NULL COMMENT 'FK -> import_batches.id';

ALTER TABLE transaction_aggregates
  MODIFY merchant_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> merchants.id',
  MODIFY source_batch_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> import_batches.id';

ALTER TABLE transaction_import_rows
  MODIFY batch_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> import_batches.id',
  MODIFY transaction_id BIGINT UNSIGNED NULL COMMENT 'FK -> transaction_aggregates.id';

ALTER TABLE transaction_change_history
  MODIFY transaction_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> transaction_aggregates.id',
  MODIFY batch_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> import_batches.id';

ALTER TABLE complaint_tickets
  MODIFY merchant_id BIGINT UNSIGNED NULL COMMENT 'FK -> merchants.id',
  MODIFY source_batch_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> import_batches.id';

ALTER TABLE ticket_import_rows
  MODIFY batch_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> import_batches.id',
  MODIFY ticket_id BIGINT UNSIGNED NULL COMMENT 'FK -> complaint_tickets.id';

ALTER TABLE incidents
  MODIFY merchant_id BIGINT UNSIGNED NULL COMMENT 'FK -> merchants.id',
  MODIFY source_ticket_id BIGINT UNSIGNED NULL COMMENT 'FK -> complaint_tickets.id';

ALTER TABLE report_runs
  MODIFY merchant_id BIGINT UNSIGNED NULL COMMENT 'FK -> merchants.id';

INSERT INTO schema_migrations (version, description)
VALUES ('20260820_006', 'Document foreign key source columns')
ON DUPLICATE KEY UPDATE description = VALUES(description);

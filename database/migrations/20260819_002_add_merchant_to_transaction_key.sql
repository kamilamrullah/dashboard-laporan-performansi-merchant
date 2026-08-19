USE merchant_performance_report;

ALTER TABLE transaction_aggregates
  DROP INDEX uq_transaction_natural_key,
  MODIFY merchant_id BIGINT UNSIGNED NOT NULL,
  ADD UNIQUE KEY uq_transaction_natural_key (
    merchant_id, transaction_date, datasource, transaction_type, ca_id,
    partner_channel, biller, sic_code, response_code
  );

INSERT INTO schema_migrations (version, description)
VALUES ('20260819_002', 'Include merchant in transaction natural key')
ON DUPLICATE KEY UPDATE description = VALUES(description);


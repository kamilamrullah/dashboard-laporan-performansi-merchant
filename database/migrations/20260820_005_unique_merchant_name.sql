USE merchant_performance_report;

ALTER TABLE merchants
  ADD UNIQUE KEY uq_merchants_name (merchant_name);

INSERT INTO schema_migrations (version, description)
VALUES ('20260820_005', 'Prevent duplicate merchant names')
ON DUPLICATE KEY UPDATE description = VALUES(description);

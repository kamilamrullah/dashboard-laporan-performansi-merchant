USE merchant_performance_report;

ALTER TABLE import_batches
  ADD KEY idx_import_batches_preview_cleanup (data_type, status, created_at);

INSERT INTO schema_migrations (version, description)
VALUES ('20260820_007', 'Index expired transaction preview cleanup')
ON DUPLICATE KEY UPDATE description = VALUES(description);

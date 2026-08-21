USE merchant_performance_report;

ALTER TABLE complaint_tickets
  ADD COLUMN duration_raw VARCHAR(64) NULL AFTER closed_at;

INSERT INTO schema_migrations (version, description)
VALUES ('20260821_011', 'Preserve raw ticket Duration value from source workbook')
ON DUPLICATE KEY UPDATE description = VALUES(description);

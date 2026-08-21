USE merchant_performance_report;

ALTER TABLE complaint_tickets
  ADD COLUMN last_updated_at DATETIME NULL AFTER closed_at;

INSERT INTO schema_migrations (version, description)
VALUES ('20260821_012', 'Store ticket last update time for verified elapsed-time calculations')
ON DUPLICATE KEY UPDATE description = VALUES(description);

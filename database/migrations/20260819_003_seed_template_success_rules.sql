USE merchant_performance_report;

INSERT INTO response_code_rules
  (response_code, transaction_type, status_group, description, effective_from, is_active)
VALUES
  ('0', 'INQUIRY', 'SUCCESS', 'Inquiry sukses berdasarkan template laporan lama', '1900-01-01', 1),
  ('0', 'PAYMENT', 'SUCCESS', 'Payment sukses berdasarkan template laporan lama', '1900-01-01', 1)
ON DUPLICATE KEY UPDATE
  status_group = VALUES(status_group),
  description = VALUES(description),
  is_active = VALUES(is_active);

INSERT INTO schema_migrations (version, description)
VALUES ('20260819_003', 'Seed success rules verified from report template')
ON DUPLICATE KEY UPDATE description = VALUES(description);


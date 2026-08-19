# Database development

Schema ini dirancang untuk MariaDB/MySQL pada XAMPP dan menggunakan database `merchant_performance_report`.

## Struktur utama

- `import_batches`: metadata file, hash SHA-256, periode terdeteksi, statistik hasil, dan status import.
- `schema_migrations`: versi migration database yang sudah diterapkan.
- `transaction_aggregates`: data transaksi agregat. Jumlah transaksi harus dihitung dengan `SUM(total_trx)`.
- `transaction_import_rows`: audit setiap baris sumber tanpa menyimpan ulang data mentah.
- `payment_channels`: mapping `sic_code` ke nama payment channel dari sheet `kode biller`.
- `response_code_rules`: konfigurasi aturan sukses, gagal, timeout, atau status lain. Tabel sengaja dibiarkan kosong.
- `complaint_tickets`: subset minimal tiket yang diperlukan untuk laporan.
- `ticket_import_rows`: audit hasil pemrosesan baris tiket.
- `incidents`: insiden manual; tiket tidak otomatis dianggap sebagai insiden.
- `report_runs`: audit proses pembuatan DOCX.

Kolom PII sumber seperti nama customer, nomor telepon, subject, pembuat tiket, dan petugas terakhir tidak disimpan.

Natural key transaksi menyertakan `merchant_id`, sehingga dimensi transaksi yang sama milik merchant berbeda tidak dianggap duplikat.

## Menjalankan schema

Dari PowerShell:

```powershell
Get-Content database\schema.sql | C:\xampp\mysql\bin\mysql.exe -u root -p
```

Atau jalankan migration awal:

```powershell
Get-Content database\migrations\20260819_001_initial_schema.sql | C:\xampp\mysql\bin\mysql.exe -u root -p
```

Jangan menaruh password pada file SQL, source code, atau command history. Sesuaikan akun database development lokal jika akun `root` tidak digunakan.

## Keputusan yang masih diperlukan

Sebelum agregasi produksi dibuat, aturan berikut perlu disetujui:

- response code untuk transaksi sukses;
- response code untuk gagal final;
- response code untuk timeout;
- hubungan antara jenis transaksi dan aturan response code;
- nilai `Flag` yang mengklasifikasikan tiket sebagai kandidat insiden.

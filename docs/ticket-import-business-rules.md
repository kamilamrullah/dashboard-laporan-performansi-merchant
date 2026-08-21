# Aturan Bisnis Import Tiket Aduan

## Sumber dan periode

- Sumber sementara adalah workbook XLSX hasil tarikan database.
- Periode tiket ditentukan dari `Open Time`; kolom `Month` bukan sumber kebenaran.
- File sumber diperlakukan read-only dan tidak disimpan ke repository.

## Identitas dan duplikasi

- `Ticket No` adalah unique key tiket.
- SHA-256 mendeteksi file identik.
- Ticket No berulang dengan isi sama diklasifikasikan sebagai duplikat.
- Ticket No berulang dengan isi berbeda ditampilkan sebagai perubahan dan hanya diperbarui setelah konfirmasi pengguna.

## Field laporan

- Ringkasan laporan menggunakan `Segmentasi Keluhan` dan jumlah Ticket No unik.
- Detail laporan menggunakan Segmentasi Keluhan, Open Time, Close Time, Last Update Time, Duration, Response Time, dan Total Keluhan.
- `Duration` dihitung sebagai total waktu kalender `Last Update Time - Open Time`.
- `Response Time` dihitung sebagai total waktu kalender `Close Time - Open Time`.
- Response Time menit adalah total menit termasuk bagian hari; kolom sumber `respon time menit` tidak dipakai karena membuang bagian hari pada durasi lebih dari 24 jam.
- Format tampilan durasi adalah `Hari:Jam:Menit`, misalnya `5:21:25`.
- Nilai mentah kolom `Duration` tetap disimpan untuk audit, tetapi tidak menggantikan kalkulasi timestamp.

## Kualitas data

- Warning tidak otomatis menolak baris; pengguna tetap dapat meninjau dan mengimpor data.
- Warning diberikan untuk status atau segmentasi kosong, tiket tanpa Close Time, Last Update Time kosong, Last Update Time setelah Close Time, serta ketidaksesuaian nilai durasi sumber dengan kalkulasi timestamp.
- Close Time kosong berarti tiket masih open dan Response Time belum dapat dihitung.

## Data minimization dan audit

- Nama pelanggan, nomor telepon, subject bebas, dan identitas petugas tidak disimpan karena tidak diperlukan laporan.
- Batch ID dan nomor baris sumber dipertahankan untuk troubleshooting.
- Perubahan tiket menyimpan snapshot lama/baru dan user yang mengonfirmasi.

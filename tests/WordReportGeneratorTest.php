<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/Report/DocxPackage.php';
require_once __DIR__ . '/../backend/Report/SummaryChartRenderer.php';
require_once __DIR__ . '/../backend/Report/WordReportGenerator.php';

use App\Report\WordReportGenerator;

/** Menghentikan test generator laporan dengan pesan yang mudah ditelusuri. */
function assert_word_report(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** Membaca satu bagian XML dari DOCX untuk verifikasi isi paket. */
function read_docx_part(string $path, string $part): string
{
    $archive = new ZipArchive();
    if ($archive->open($path) !== true) throw new RuntimeException('DOCX hasil test tidak dapat dibuka.');
    try {
        $contents = $archive->getFromName($part);
        if ($contents === false) throw new RuntimeException("Bagian {$part} tidak ditemukan.");
        return $contents;
    } finally { $archive->close(); }
}

$output = tempnam(sys_get_temp_dir(), 'report-test-');
if ($output === false) throw new RuntimeException('File test sementara gagal dibuat.');
unlink($output);
$output .= '.docx';
$logo = tempnam(sys_get_temp_dir(), 'report-logo-');
if ($logo === false) throw new RuntimeException('File logo test sementara gagal dibuat.');
$logoTemporary = $logo;
$logo .= '.png';
unlink($logoTemporary);
file_put_contents($logo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL3WQAAAABJRU5ErkJggg==', true));
try {
    $result = (new WordReportGenerator())->generateInitialPages($output, [
        'merchant_name' => 'Merchant <A>',
        'report_period' => '2026-01-01',
        'company_address' => 'Jl. Contoh & Aman',
        'company_phone' => '021-123',
        'company_fax' => '021-456',
        'company_logo_path' => $logo,
        'issued_at' => '2026-08-21',
        'summary' => [
            'rows' => [['partner_channel' => 'CHANNEL & A', 'inquiry_success' => 10, 'payment_success' => 8, 'payment_amount' => 125000.0]],
            'totals' => ['inquiry_success' => 10, 'payment_success' => 8, 'payment_amount' => 125000.0],
            'metrics' => ['top_inquiry' => ['partner_channel' => 'CHANNEL & A', 'total' => 10], 'top_payment' => ['partner_channel' => 'CHANNEL & A', 'total' => 8], 'top_payment_amount' => ['partner_channel' => 'CHANNEL & A', 'total' => 125000.0], 'payment_to_inquiry_percentage' => 80.0, 'payment_comparison' => ['current_label' => 'JANUARI 2026', 'current_total' => 8, 'previous_label' => 'DESEMBER 2025', 'previous_total' => 10, 'difference' => -2, 'percentage_change' => -20.0, 'direction' => 'decrease']],
            'performance' => [
                'rows' => [['partner_channel' => 'CHANNEL & A', 'success' => 8, 'rc_68' => 1, 'rc_82' => 1, 'total' => 10, 'success_rate' => 80.0]],
                'totals' => ['success' => 8, 'rc_68' => 1, 'rc_82' => 1, 'total' => 10, 'success_rate' => 80.0],
            ],
            'payment_channel_performance' => [
                'rows' => [['payment_channel' => 'Auto Deposit Mobile', 'success' => 8, 'rc_68' => 0, 'rc_82' => 0, 'total' => 8, 'success_rate' => 100.0]],
                'totals' => ['success' => 8, 'rc_68' => 0, 'rc_82' => 0, 'total' => 8, 'success_rate' => 100.0],
            ],
            'top_payment_channels' => [['payment_channel' => 'Auto Deposit Mobile', 'total' => 8, 'percentage' => 100.0]],
            'daily_trend' => [
                'rows' => [
                    ['date' => '2026-01-01', 'success' => 3, 'rc_68' => 0, 'rc_82' => 0, 'success_rate' => 100.0],
                    ['date' => '2026-01-02', 'success' => 5, 'rc_68' => 1, 'rc_82' => 0, 'success_rate' => 83.333333],
                ],
                'metrics' => ['highest' => ['date' => '2026-01-02', 'total' => 5], 'lowest' => ['date' => '2026-01-01', 'total' => 3], 'largest_increase' => ['from_date' => '2026-01-01', 'to_date' => '2026-01-02', 'difference' => 2], 'average_success' => 4, 'total_success' => 8],
            ],
            'ticket_summary' => [
                'segments' => [['complaint_segment' => 'Pengecekan Dana', 'total' => 1], ['complaint_segment' => 'Permohonan Refund', 'total' => 2]],
                'statuses' => [['status' => 'Close', 'total' => 3]],
                'total' => 3,
            ],
        ],
    ]);
    assert_word_report(is_file($output) && filesize($output) > 0, 'File DOCX harus dihasilkan.');
    assert_word_report(strlen((string) $result['sha256']) === 64, 'Hash SHA-256 keluaran harus tersedia.');
    $document = read_docx_part($output, 'word/document.xml');
    assert_word_report(str_contains($document, 'LAPORAN PERFORMANSI BULANAN'), 'Judul cover harus mengikuti template.');
    assert_word_report(str_contains($document, 'PERIODE JANUARI 2026'), 'Periode cover harus menggunakan nama bulan Indonesia.');
    assert_word_report(str_contains($document, 'Jl. Contoh &amp; Aman'), 'Teks khusus harus diamankan sebagai XML.');
    $coverXml = explode('<w:br w:type="page"/>', $document, 2)[0];
    assert_word_report(!str_contains($coverXml, 'Merchant &lt;A&gt;'), 'Nama merchant tidak boleh ditambahkan ke cover karena tidak ada pada cover template.');
    assert_word_report(str_contains($document, 'w:w="11906" w:h="16838"'), 'Ukuran halaman cover harus A4.');
    assert_word_report(str_contains($document, '<w:br w:type="page"/>'), 'Halaman judul harus diawali page break eksplisit.');
    assert_word_report(str_contains($document, 'LAPORAN PERFORMANSI MERCHANT'), 'Judul halaman judul harus mengikuti template.');
    assert_word_report(str_contains($document, 'MERCHANT &lt;A&gt;'), 'Nama merchant harus ditampilkan aman dengan huruf kapital pada halaman judul.');
    assert_word_report(str_contains($document, 'Jakarta, 21 Agustus 2026'), 'Tanggal penerbitan harus menggunakan format Indonesia.');
    assert_word_report(str_contains($document, 'DAFTAR ISI'), 'Halaman daftar isi harus tersedia.');
    assert_word_report(str_contains($document, 'TOC \\o "1-3" \\h \\z \\u'), 'Daftar isi harus berupa field TOC level satu sampai tiga.');
    assert_word_report(str_contains($document, 'CHANNEL &amp; A'), 'Nama channel pada tabel harus diamankan sebagai XML.');
    assert_word_report(str_contains($document, '125.000'), 'Nominal tabel harus menggunakan format Indonesia.');
    assert_word_report(str_contains($document, 'GRAND TOTAL'), 'Tabel ringkasan harus memiliki grand total.');
    assert_word_report(str_contains($document, 'Dokumen ini menyajikan laporan performansi biller tersebut untuk periode JANUARI 2026.'), 'Paragraf pengantar harus menghindari pengulangan nama merchant.');
    assert_word_report(str_contains($document, '10 transaksi sukses inquiry.'), 'Narasi harus memuat total inquiry sukses.');
    assert_word_report(str_contains($document, '8 transaksi sukses payment.'), 'Narasi harus memuat total payment sukses.');
    assert_word_report(str_contains($document, 'sebesar 80% dibandingkan dengan total inquiry.'), 'Narasi harus memuat rasio payment terhadap inquiry.');
    assert_word_report(str_contains($document, 'Terjadi penurunan sebesar 2 transaksi atau 20%.'), 'Narasi perbandingan bulan harus dihitung dari metrik service.');
    assert_word_report(str_contains($document, '<w:tblW w:w="9700" w:type="dxa"/>'), 'Grafik dan narasi perbandingan harus memakai layout tabel dua kolom.');
    assert_word_report(str_contains($document, '<w:gridCol w:w="5600"/><w:gridCol w:w="4100"/>'), 'Proporsi kolom grafik dan narasi harus sesuai layout yang disepakati.');
    assert_word_report(str_contains($document, 'PERFORMANCE'), 'Bab performance harus tersedia.');
    assert_word_report(str_contains($document, 'TINGKAT KEBERHASILAN TRANSAKSI TERHADAP TRANSAKSI GAGAL'), 'Subbab tingkat keberhasilan transaksi harus tersedia.');
    assert_word_report(str_contains($document, 'Response Code'), 'Header tabel performance harus mengikuti template.');
    assert_word_report(str_contains($document, '>68<') && str_contains($document, '>82<'), 'Tabel performance harus memuat RC 68 dan RC 82 seperti template.');
    assert_word_report(str_contains($document, 'sukses rate transaksi MERCHANT &lt;A&gt; adalah sebesar 80.00%'), 'Narasi success rate harus mengikuti template dan memakai hasil agregasi.');
    assert_word_report(str_contains($document, '2 transaksi gagal yang disebabkan oleh response code time out RC 82 dan RC 68'), 'Narasi grafik harus memakai total RC 68 dan RC 82.');
    assert_word_report(str_contains($document, 'TINGKAT KEBERHASILAN TRANSAKSI BERDASARKAN CHANNEL'), 'Subbab performance berdasarkan channel harus tersedia.');
    assert_word_report((bool) preg_match('/<w:br w:type="page"\/><\/w:r><\/w:p><w:p><w:pPr><w:pStyle w:val="Heading2"\/><\/w:pPr><w:r><w:t xml:space="preserve">TINGKAT KEBERHASILAN TRANSAKSI BERDASARKAN CHANNEL/', $document), 'Judul performance berdasarkan channel harus didahului page break eksplisit.');
    assert_word_report(str_contains($document, 'Berikut adalah jumlah transaksi berdasarkan pada channel pembayaran'), 'Teks pembuka performance channel harus mengikuti template.');
    assert_word_report(str_contains($document, 'Auto Deposit Mobile'), 'Tabel performance harus menampilkan payment channel hasil mapping SIC_CODE.');
    assert_word_report(str_contains($document, 'sebesar 100.00% dari keseluruhan total transaksi'), 'Narasi persentase top payment channel harus mengikuti format template.');
    assert_word_report(str_contains($document, 'Pada tabel di atas terlihat bahwa'), 'Narasi payment channel harus merujuk pada tabel, bukan grafik.');
    assert_word_report((bool) preg_match('/<w:br w:type="page"\/><\/w:r><\/w:p><w:p><w:pPr><w:pStyle w:val="Heading2"\/><\/w:pPr><w:r><w:t xml:space="preserve">TINGKAT KEBERHASILAN TRANSAKSI BERDASARKAN TOP CHANNEL/', $document), 'Judul top channel harus dimulai pada halaman baru.');
    assert_word_report(str_contains($document, 'Berikut adalah ratio yang membandingkan jumlah transaksi sukses payment dan transaksi gagal timeout'), 'Teks pembuka top channel harus mengikuti template.');
    assert_word_report(str_contains($document, 'Terlihat bahwa ratio terhadap TOP pembayaran berdasarkan channel pembayaran yang digunakan adalah sebagai berikut :'), 'Teks pengantar narasi top channel harus mengikuti template.');
    assert_word_report(str_contains($document, 'Jumlah transaksi pada channel Auto Deposit Mobile adalah 8 transaksi atau 100%.'), 'Narasi top channel harus memakai hasil agregasi service.');
    assert_word_report(str_contains($document, '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>'), 'Narasi top channel harus menggunakan native numbering Word.');
    assert_word_report(!str_contains($document, '• Jumlah transaksi pada channel Auto Deposit Mobile'), 'Narasi top channel tidak boleh memakai karakter bullet manual.');
    assert_word_report(str_contains($document, 'TREND TRANSAKSI HARIAN'), 'Subbab trend transaksi harian harus tersedia.');
    assert_word_report(str_contains($document, 'Peningkatan transaksi yang signifikan terjadi pada 01 ke 02 JANUARI 2026 dengan total 2 transaksi.'), 'Narasi tren harus memakai kenaikan harian terbesar.');
    assert_word_report(str_contains($document, 'Transaksi tertinggi terjadi pada 02 Januari dengan 5 transaksi'), 'Narasi tren harus memuat tanggal tertinggi.');
    assert_word_report(str_contains($document, 'Tanggal') && str_contains($document, 'RC 68') && str_contains($document, 'RC 82'), 'Tabel tren harian harus mengikuti kolom template.');
    assert_word_report(str_contains($document, 'ADUAN DAN INSIDEN') && str_contains($document, 'TIKET ADUAN'), 'Bab aduan dan subbab tiket aduan harus tersedia.');
    assert_word_report(str_contains($document, 'Total tiket laporan aduan sejumlah 3 tiket'), 'Narasi tiket harus memakai jumlah Ticket No unik.');
    assert_word_report(str_contains($document, 'Pengecekan Dana sejumlah 1 tiket, dan Permohonan Refund sejumlah 2 tiket'), 'Narasi tiket harus memuat komposisi segmentasi.');
    assert_word_report(str_contains($document, 'keseluruhan tiket aduan statusnya sudah closed'), 'Narasi tiket harus menyesuaikan status keseluruhan tiket.');
    assert_word_report(str_contains($document, 'Segmentasi Keluhan') && str_contains($document, 'Total Keluhan'), 'Tabel ringkasan tiket harus mengikuti template.');
    assert_word_report((bool) preg_match('/<w:br w:type="page"\/><\/w:r><\/w:p><w:p><w:pPr><w:pStyle w:val="Heading2"\/><\/w:pPr><w:r><w:t xml:space="preserve">LAPORAN INSIDEN/', $document), 'Judul laporan insiden harus dimulai pada halaman baru.');
    foreach (['Tanggal Kendala', 'Kendala', 'Penyebab Kendala', 'Kategori Kendala', 'Penyelesaian', 'Durasi'] as $incidentHeader) assert_word_report(str_contains($document, $incidentHeader), "Header insiden {$incidentHeader} harus tersedia.");
    assert_word_report(str_contains($document, 'Pada bulan JANUARI 2026 diterima laporan Insiden baik dari Internal maupun Eksternal yang dapat mempengaruhi Success Rate transaksi MERCHANT &lt;A&gt;.'), 'Paragraf pembuka laporan insiden harus menyesuaikan merchant dan periode.');
    assert_word_report(str_contains($document, 'KESIMPULAN'), 'Bagian kesimpulan harus tersedia.');
    assert_word_report(str_contains($document, 'Transaksi sukses purchase MERCHANT &lt;A&gt; pada bulan JANUARI 2026 tercatat mencapai 8 transaksi payment.'), 'Kesimpulan payment sukses harus dinamis.');
    assert_word_report(str_contains($document, 'Inquiry tertinggi berjumlah 10 dari channel CHANNEL &amp; A dan purchase tertinggi berjumlah 8 transaksi dengan nominal Rp 125.000'), 'Kesimpulan channel tertinggi dan nominal harus dinamis.');
    assert_word_report(str_contains($document, 'Rata-rata transaksi MERCHANT &lt;A&gt; periode JANUARI 2026 per hari adalah 4 transaksi sukses per hari.'), 'Kesimpulan rata-rata harian harus dinamis.');
    assert_word_report(str_contains($document, 'Demikian laporan ini kami sampaikan'), 'Paragraf penutup laporan harus tersedia.');
    assert_word_report(!str_contains($document, 'w:orient="landscape"') && str_contains($document, '<w:pgSz w:w="11906" w:h="16838"/>'), 'Seluruh halaman termasuk penutup harus berorientasi portrait.');
    assert_word_report(str_contains($document, 'Get In Touch with Us'), 'Area kontak halaman penutup harus tersedia.');
    foreach (['@akunX', '@akunIG', '@akunFB', 'website'] as $contactLabel) assert_word_report(str_contains($document, $contactLabel), "Label kontak {$contactLabel} harus tersedia.");
    assert_word_report(str_contains($document, 'w:top="1440" w:right="1080" w:bottom="1440" w:left="1080"'), 'Margin halaman harus memakai preset Moderate Word.');
    $styles = read_docx_part($output, 'word/styles.xml');
    assert_word_report(str_contains($styles, 'w:ascii="Calibri"'), 'Font isi default harus Calibri.');
    assert_word_report(str_contains($styles, 'w:ascii="Calisto MT"'), 'Font judul dan heading harus Calisto MT.');
    $titlePageMerchantStyle = strstr($styles, '<w:style w:type="paragraph" w:styleId="TitlePageMerchant">');
    assert_word_report($titlePageMerchantStyle !== false && !str_contains(substr($titlePageMerchantStyle, 0, (int) strpos($titlePageMerchantStyle, '</w:style>')), '<w:ind'), 'Style nama merchant tidak boleh memiliki indentasi.');
    assert_word_report(read_docx_part($output, 'word/media/company-logo.png') !== '', 'Logo perusahaan harus tertanam di dalam DOCX.');
    assert_word_report(read_docx_part($output, 'word/media/summary-payment-comparison.png') !== '', 'Grafik perbandingan payment harus tertanam di dalam DOCX.');
    assert_word_report(read_docx_part($output, 'word/media/payment-status-composition.png') !== '', 'Grafik komposisi status payment harus tertanam di dalam DOCX.');
    assert_word_report(read_docx_part($output, 'word/media/top-payment-channels.png') !== '', 'Grafik top payment channel harus tertanam di dalam DOCX.');
    assert_word_report(read_docx_part($output, 'word/media/daily-payment-trend.png') !== '', 'Grafik tren harian harus tertanam di dalam DOCX.');
    assert_word_report(read_docx_part($output, 'word/media/ticket-segments.png') !== '', 'Grafik segmentasi tiket harus tertanam di dalam DOCX.');
    foreach (['certificates.png', 'licenses.png', 'contact-x.png', 'contact-instagram.jpeg', 'contact-facebook.png', 'contact-website.png'] as $closingAsset) assert_word_report(read_docx_part($output, 'word/media/' . $closingAsset) !== '', "Aset penutup {$closingAsset} harus tertanam di dalam DOCX.");
    assert_word_report(str_contains(read_docx_part($output, 'word/_rels/document.xml.rels'), 'Target="media/company-logo.png"'), 'Relasi logo DOCX harus tersedia.');
    assert_word_report(str_contains(read_docx_part($output, 'word/_rels/document.xml.rels'), 'Target="media/payment-status-composition.png"'), 'Relasi grafik performance DOCX harus tersedia.');
    assert_word_report(str_contains(read_docx_part($output, 'word/_rels/document.xml.rels'), 'Target="media/top-payment-channels.png"'), 'Relasi grafik top channel DOCX harus tersedia.');
    assert_word_report(str_contains(read_docx_part($output, 'word/_rels/document.xml.rels'), 'Target="media/daily-payment-trend.png"'), 'Relasi grafik tren harian DOCX harus tersedia.');
    assert_word_report(str_contains(read_docx_part($output, 'word/_rels/document.xml.rels'), 'Target="media/ticket-segments.png"'), 'Relasi grafik segmentasi tiket DOCX harus tersedia.');
    assert_word_report(str_contains(read_docx_part($output, 'word/_rels/document.xml.rels'), 'Target="media/certificates.png"') && str_contains(read_docx_part($output, 'word/_rels/document.xml.rels'), 'Target="media/licenses.png"'), 'Relasi halaman Sertifikat dan Lisensi harus tersedia.');
    assert_word_report(str_contains(read_docx_part($output, 'word/settings.xml'), '<w:updateFields w:val="true"/>'), 'Word harus diminta memperbarui daftar isi saat dokumen dibuka.');
    assert_word_report(str_contains(read_docx_part($output, 'word/numbering.xml'), '<w:numFmt w:val="bullet"/>'), 'Paket DOCX harus mendefinisikan native bullet list Word.');
    assert_word_report(str_contains(read_docx_part($output, 'word/numbering.xml'), '<w:rFonts w:ascii="Arial" w:hAnsi="Arial"') && str_contains(read_docx_part($output, 'word/numbering.xml'), '<w:color w:val="000000"/>'), 'Glyph bullet harus memakai font yang mendukung lingkaran hitam.');
    assert_word_report(str_contains(read_docx_part($output, 'word/_rels/document.xml.rels'), 'Target="numbering.xml"'), 'Relasi numbering Word harus tersedia.');

    try { (new WordReportGenerator())->generateInitialPages($output, ['merchant_name' => 'A', 'report_period' => '2026-01-02', 'issued_at' => '2026-08-21', 'summary' => ['rows' => [], 'totals' => ['inquiry_success' => 0, 'payment_success' => 0, 'payment_amount' => 0], 'metrics' => ['top_inquiry' => null, 'top_payment' => null, 'top_payment_amount' => null, 'payment_to_inquiry_percentage' => 0, 'payment_comparison' => []], 'performance' => ['rows' => [], 'totals' => []], 'payment_channel_performance' => ['rows' => [], 'totals' => []], 'top_payment_channels' => [], 'daily_trend' => ['rows' => [], 'metrics' => []], 'ticket_summary' => ['segments' => [], 'statuses' => [], 'total' => 0]]]); throw new RuntimeException('Periode bukan tanggal pertama seharusnya ditolak.'); }
    catch (RuntimeException $error) { assert_word_report(str_contains($error->getMessage(), 'tanggal pertama'), 'Pesan validasi periode tidak sesuai.'); }
    echo "WordReportGeneratorTest: OK\n";
} finally {
    if (is_file($output)) unlink($output);
    if (is_file($logo)) unlink($logo);
}

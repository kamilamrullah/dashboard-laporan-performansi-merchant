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
            'ticket_details' => [[
                'complaint_segment' => 'Permohonan Refund', 'opened_at' => '2026-01-14 12:11:51',
                'closed_at' => '2026-01-15 16:27:50', 'duration_raw' => '5:33', 'duration_minutes' => 333, 'response_time_minutes' => 1695, 'total' => 1,
            ]],
        ],
    ]);
    assert_word_report(is_file($output) && filesize($output) > 0, 'File DOCX harus dihasilkan.');
    assert_word_report(strlen((string) $result['sha256']) === 64, 'Hash SHA-256 keluaran harus tersedia.');
    $document = read_docx_part($output, 'word/document.xml');
    $documentText = html_entity_decode(strip_tags($document), ENT_QUOTES | ENT_XML1, 'UTF-8');
    $documentDom = new DOMDocument();
    assert_word_report($documentDom->loadXML($document, LIBXML_NONET), 'XML dokumen hasil harus valid.');
    $documentXpath = new DOMXPath($documentDom);
    $documentXpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $documentXpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
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
    $monthlySummaryTable = $documentXpath->query('//w:tbl[.//w:t[text()="NAMA BANK"]]')->item(0);
    assert_word_report($monthlySummaryTable !== null && $documentXpath->query('./w:tr[1]/w:tc[1]/w:tcPr/w:vMerge[@w:val="restart"]', $monthlySummaryTable)->length === 1, 'Header NAMA BANK harus di-merge vertikal dengan baris di bawahnya.');
    assert_word_report($monthlySummaryTable !== null && $documentXpath->query('./w:tr[2]/w:tc[1]/w:tcPr/w:vMerge[not(@w:val)]', $monthlySummaryTable)->length === 1, 'Merge vertikal NAMA BANK harus memiliki sel lanjutan yang valid.');
    assert_word_report($monthlySummaryTable !== null && $documentXpath->query('./w:tr[1]/w:tc[2]/w:tcPr/w:gridSpan[@w:val="3"]', $monthlySummaryTable)->length === 1, 'Header periode harus di-merge horizontal sepanjang tiga kolom metrik.');
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
    assert_word_report(str_contains($document, 'TINGKAT KEBERHASILAN TRANSAKSI BERDASARKAN CHANNEL'), 'Judul performance berdasarkan channel harus dipertahankan dari template.');
    assert_word_report(str_contains($documentText, 'Berikut adalah jumlah transaksi berdasarkan pada channel pembayaran'), 'Teks pembuka performance channel harus mengikuti template.');
    assert_word_report(str_contains($document, 'Auto Deposit Mobile'), 'Tabel performance harus menampilkan payment channel hasil mapping SIC_CODE.');
    assert_word_report(str_contains($document, 'sebesar 100.00% dari keseluruhan total transaksi'), 'Narasi persentase top payment channel harus mengikuti format template.');
    assert_word_report(str_contains($document, 'Pada tabel di atas terlihat bahwa'), 'Narasi payment channel harus merujuk pada tabel, bukan grafik.');
    assert_word_report(str_contains($document, 'TINGKAT KEBERHASILAN TRANSAKSI BERDASARKAN TOP CHANNEL'), 'Judul top channel harus dipertahankan dari template.');
    assert_word_report(str_contains($documentText, 'Berikut adalah ratio yang membandingkan jumlah transaksi sukses payment dan transaksi gagal timeout'), 'Teks pembuka top channel harus mengikuti template.');
    assert_word_report(str_contains($documentText, 'Terlihat bahwa ratio terhadap TOP pembayaran berdasarkan channel pembayaran yang digunakan adalah sebagai berikut :'), 'Teks pengantar narasi top channel harus mengikuti template.');
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
    assert_word_report(str_contains($document, 'LAPORAN INSIDEN'), 'Judul laporan insiden harus dipertahankan dari template.');
    foreach (['Tanggal Kendala', 'Kendala', 'Penyebab Kendala', 'Kategori Kendala', 'Penyelesaian', 'Durasi'] as $incidentHeader) assert_word_report(str_contains($document, $incidentHeader), "Header insiden {$incidentHeader} harus tersedia.");
    assert_word_report(str_contains($document, 'Pada bulan JANUARI 2026 diterima laporan Insiden baik dari Internal maupun Eksternal yang dapat mempengaruhi Success Rate transaksi MERCHANT &lt;A&gt;.'), 'Paragraf pembuka laporan insiden harus menyesuaikan merchant dan periode.');
    assert_word_report(str_contains($document, 'DETAIL TIKET'), 'Bagian Detail Tiket dari template terbaru harus tersedia.');
    foreach (['Segmentasi Keluhan', 'Open Time', 'Close Time', 'Durasi (Jam:Menit)', 'Total Keluhan'] as $ticketHeader) assert_word_report(str_contains($documentText, $ticketHeader), "Header detail tiket {$ticketHeader} harus tersedia.");
    assert_word_report(str_contains($document, '2026-01-14 12:11:51') && str_contains($document, '2026-01-15 16:27:50'), 'Tabel detail tiket harus memakai waktu tiket dari service.');
    assert_word_report(str_contains($document, '>28:15<'), 'Durasi detail tiket harus memakai response time Close Time dikurangi Open Time.');
    assert_word_report(!str_contains($document, 'JANUARI 20261'), 'Nomor halaman cache Daftar Isi tidak boleh menempel pada tahun periode.');
    assert_word_report($documentXpath->query('//w:hyperlink[.//w:instrText[contains(., "PAGEREF")]]//w:t[text()="JANUARI 2026"]')->length >= 1, 'Teks periode pada cache Daftar Isi harus tetap terpisah dari nomor halaman.');
    $detailTicketTable = $documentXpath->query('//w:tbl[.//w:t[text()="Durasi (Jam:Menit)"]]')->item(0);
    assert_word_report($detailTicketTable !== null && $documentXpath->query('.//w:r[not(w:rPr/w:sz[@w:val="20"] and w:rPr/w:szCs[@w:val="20"])]', $detailTicketTable)->length === 0, 'Seluruh teks tabel Detail Tiket harus berukuran 10 pt.');
    assert_word_report(!str_contains($document, '{{'), 'Seluruh anchor template harus sudah diganti.');
    assert_word_report($documentXpath->query('//w:body//w:shd[@w:fill="FFF2CC"]')->length === 0, 'Narasi hasil generate tidak boleh memiliki shading kuning anchor.');
    assert_word_report($documentXpath->query('//w:p[w:pPr/w:pStyle[@w:val="Heading1" or @w:val="Heading2"]]/w:pPr/w:ind')->length === 0, 'Heading hasil generate tidak boleh memiliki indentasi langsung.');
    assert_word_report($documentXpath->query('//w:p[.//w:drawing and w:pPr/w:jc[@w:val="center"]]')->length >= 5, 'Seluruh grafik dinamis harus rata tengah.');
    assert_word_report($documentXpath->query('//w:fldChar[@w:fldCharType="begin" and @w:dirty]')->length === 0, 'TOC dan PAGEREF tidak boleh diperbarui sebelum Word selesai menghitung layout halaman.');
    assert_word_report($documentXpath->query('//w:sectPr')->length >= 2, 'Dokumen harus memiliki section awal dan section laporan.');
    assert_word_report($documentXpath->query('//w:sectPr/w:footerReference')->length >= 1, 'Section laporan harus memakai footer nomor halaman.');
    assert_word_report($documentXpath->query('//w:sectPr/w:pgNumType[@w:start="1"]')->length === 1, 'Nomor halaman hanya boleh dimulai ulang satu kali setelah Daftar Isi.');
    $firstSection = $documentXpath->query('//w:sectPr')->item(0);
    assert_word_report($firstSection !== null && $documentXpath->query('./w:footerReference', $firstSection)->length === 0, 'Section cover sampai Daftar Isi tidak boleh menampilkan footer nomor halaman.');
    assert_word_report($documentXpath->query('//w:tbl//w:p[not(w:pPr/w:spacing[@w:before="0" and @w:after="0"])]')->length === 0, 'Paragraf dalam tabel tidak boleh memiliki space before atau after.');
    assert_word_report($documentXpath->query('//w:tbl//w:tc[w:tcPr/w:tcMar/w:top/@w:w != "0" or w:tcPr/w:tcMar/w:bottom/@w:w != "0"]')->length === 0, 'Margin vertikal seluruh sel tabel harus minimum.');
    foreach ($documentXpath->query('//w:tbl[.//w:t[contains(., "NAMA BANK") or contains(., "CHANNEL") or contains(., "Tanggal") or contains(., "Segmentasi Keluhan") or contains(., "Tanggal Kendala")]]') as $dataTable) {
        assert_word_report($documentXpath->query('./w:tblPr/w:jc[@w:val="center"]', $dataTable)->length === 1, 'Setiap tabel data harus rata tengah.');
    }
    foreach ($documentXpath->query('//w:tc[w:tcPr/w:shd[@w:fill="C00000"]]') as $headerCell) {
        assert_word_report($documentXpath->query('.//w:rPr/w:color[@w:val="FFFFFF"]', $headerCell)->length > 0, 'Header merah harus menggunakan teks putih.');
    }
    assert_word_report($documentXpath->query('//w:tc[w:tcPr/w:shd[@w:fill="C00000"]]')->length >= 20, 'Semua tabel dinamis harus menggunakan header merah seperti Detail Tiket.');
    assert_word_report(str_contains($document, 'KESIMPULAN'), 'Bagian kesimpulan harus tersedia.');
    assert_word_report(str_contains($document, 'Transaksi sukses purchase MERCHANT &lt;A&gt; pada bulan JANUARI 2026 tercatat mencapai 8 transaksi payment.'), 'Kesimpulan payment sukses harus dinamis.');
    assert_word_report(str_contains($document, 'Inquiry tertinggi berjumlah 10 dari channel CHANNEL &amp; A dan purchase tertinggi berjumlah 8 transaksi dengan nominal Rp 125.000'), 'Kesimpulan channel tertinggi dan nominal harus dinamis.');
    assert_word_report(str_contains($document, 'Rata-rata transaksi MERCHANT &lt;A&gt; periode JANUARI 2026 per hari adalah 4 transaksi sukses per hari.'), 'Kesimpulan rata-rata harian harus dinamis.');
    assert_word_report(str_contains($document, 'Demikian laporan ini kami sampaikan'), 'Paragraf penutup laporan harus tersedia.');
    assert_word_report(!str_contains($document, 'w:orient="landscape"') && str_contains($document, '<w:pgSz w:w="11906" w:h="16838"/>'), 'Seluruh halaman termasuk penutup harus berorientasi portrait.');
    assert_word_report(str_contains($documentText, 'Get In Touch with Us'), 'Area kontak halaman penutup harus tersedia.');
    assert_word_report(str_contains($documentText, '@finpaypromo') && str_contains($documentText, 'finpay.id'), 'Label kontak hasil koreksi template harus dipertahankan.');
    assert_word_report(read_docx_part($output, 'word/styles.xml') === read_docx_part(__DIR__ . '/../backend/Report/templates/laporan-performansi-template.docx', 'word/styles.xml'), 'Style hasil harus dipertahankan dari template terbaru.');
    foreach (['generated-summary-payment-comparison.png', 'generated-payment-status-composition.png', 'generated-top-payment-channels.png', 'generated-daily-payment-trend.png', 'generated-ticket-segments.png'] as $chartAsset) assert_word_report(read_docx_part($output, 'word/media/' . $chartAsset) !== '', "Grafik {$chartAsset} harus tertanam di dalam DOCX.");
    $relationships = read_docx_part($output, 'word/_rels/document.xml.rels');
    foreach (range(20, 24) as $relationshipId) assert_word_report(str_contains($relationships, 'Id="rId' . $relationshipId . '"'), "Relationship grafik rId{$relationshipId} harus tersedia.");
    assert_word_report(str_contains($relationships, 'relationships/footer'), 'Relationship footer nomor halaman harus tersedia.');
    assert_word_report(str_contains($relationships, 'Id="rId26"') && str_contains($relationships, 'Target="generated-report-frame.xml"'), 'Section laporan harus memakai header bingkai merah berulang.');
    assert_word_report(str_contains($relationships, 'Id="rId27"') && str_contains($relationships, 'Target="generated-blank-header.xml"'), 'Section halaman terakhir harus memakai header kosong.');
    assert_word_report(str_contains(read_docx_part($output, 'word/generated-report-frame.xml'), 'GeneratedReportFrame') && str_contains(read_docx_part($output, 'word/generated-report-frame.xml'), 'strokecolor="#c00000"'), 'Header laporan harus memuat kotak merah.');
    assert_word_report(!str_contains(read_docx_part($output, 'word/generated-blank-header.xml'), '<v:rect'), 'Header halaman terakhir tidak boleh memiliki kotak merah.');
    assert_word_report($documentXpath->query('(//w:sectPr)[2]/w:headerReference[@w:type="default" and @r:id="rId26"]')->length === 1, 'Bingkai berulang harus diterapkan pada section isi laporan.');
    assert_word_report($documentXpath->query('(//w:sectPr)[3]/w:headerReference[@w:type="default" and @r:id="rId27"]')->length === 1, 'Section terakhir harus memutus pewarisan header bingkai.');
    assert_word_report($documentXpath->query('//w:p[normalize-space(string(.))="ADUAN DAN INSIDEN"]/preceding-sibling::*[1][self::w:p and not(normalize-space(string(.))) and not(.//w:br or .//w:drawing or .//w:pict or ./w:pPr/w:sectPr)]')->length === 0, 'Heading ADUAN DAN INSIDEN tidak boleh didahului paragraf kosong sisa anchor.');
    assert_word_report($documentXpath->query('//w:p[normalize-space(string(.))="ADUAN DAN INSIDEN"]/w:pPr/w:pageBreakBefore')->length === 1, 'Heading ADUAN DAN INSIDEN harus dimulai pada halaman baru tanpa paragraf kosong tambahan.');
    $footer = read_docx_part($output, 'word/footer1.xml');
    assert_word_report(str_contains($footer, '<w:jc w:val="right"/>') && str_contains($footer, ' PAGE '), 'Footer harus menampilkan field nomor halaman di kanan bawah.');
    assert_word_report(!str_contains(read_docx_part($output, 'word/settings.xml'), '<w:updateFields'), 'Dokumen tidak boleh memicu pembaruan field prematur saat dibuka.');
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

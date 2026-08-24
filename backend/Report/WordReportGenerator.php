<?php
declare(strict_types=1);

namespace App\Report;

use DateTimeImmutable;
use RuntimeException;

/** Menghasilkan laporan DOCX bertahap berdasarkan isi template laporan performansi lama. */
final class WordReportGenerator
{
    private const MONTHS = [1 => 'JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI', 'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER'];

    /** Menerima pembentuk paket DOCX agar generator mudah diuji secara terisolasi. */
    public function __construct(private readonly DocxPackage $package = new DocxPackage(), private readonly SummaryChartRenderer $chartRenderer = new SummaryChartRenderer()) {}

    /** Menghasilkan cover, halaman judul, dan daftar isi sebagai fondasi awal laporan. */
    public function generateInitialPages(string $outputPath, array $report): array
    {
        $merchant = $this->requiredText($report, 'merchant_name', 160);
        $period = $this->period($report['report_period'] ?? null);
        $issuedAt = $this->date($report['issued_at'] ?? null, 'Tanggal penerbitan');
        $address = $this->optionalText($report, 'company_address', 500);
        $phone = $this->optionalText($report, 'company_phone', 80);
        $fax = $this->optionalText($report, 'company_fax', 80);
        $logo = $this->logo($report['company_logo_path'] ?? null);
        $summary = $this->summary($report['summary'] ?? null);
        $periodLabel = self::MONTHS[(int) $period->format('n')] . ' ' . $period->format('Y');

        $chartPath = tempnam(sys_get_temp_dir(), 'summary-chart-');
        $performanceChartPath = tempnam(sys_get_temp_dir(), 'performance-chart-');
        $topChannelChartPath = tempnam(sys_get_temp_dir(), 'top-channel-chart-');
        $dailyTrendChartPath = tempnam(sys_get_temp_dir(), 'daily-trend-chart-');
        $ticketChartPath = tempnam(sys_get_temp_dir(), 'ticket-chart-');
        if ($chartPath === false || $performanceChartPath === false || $topChannelChartPath === false || $dailyTrendChartPath === false || $ticketChartPath === false) {
            if (is_string($chartPath) && is_file($chartPath)) unlink($chartPath);
            if (is_string($performanceChartPath) && is_file($performanceChartPath)) unlink($performanceChartPath);
            if (is_string($topChannelChartPath) && is_file($topChannelChartPath)) unlink($topChannelChartPath);
            if (is_string($dailyTrendChartPath) && is_file($dailyTrendChartPath)) unlink($dailyTrendChartPath);
            if (is_string($ticketChartPath) && is_file($ticketChartPath)) unlink($ticketChartPath);
            throw new RuntimeException('File sementara grafik laporan gagal dibuat.');
        }
        try {
            $this->chartRenderer->renderPaymentComparison($summary['metrics']['payment_comparison'], $chartPath);
            $this->chartRenderer->renderPaymentStatusComposition($summary['performance']['totals'], $performanceChartPath);
            $this->chartRenderer->renderTopPaymentChannels($summary['top_payment_channels'], $topChannelChartPath);
            $this->chartRenderer->renderDailyPaymentTrend($summary['daily_trend']['rows'], $dailyTrendChartPath);
            $this->chartRenderer->renderTicketSegments($summary['ticket_summary']['segments'], $periodLabel, $ticketChartPath);
            $document = $this->documentXml($periodLabel, $address, $phone, $fax, $logo, $merchant, $this->indonesianDate($issuedAt), $summary);
            $media = ['summary-payment-comparison.png' => $chartPath, 'payment-status-composition.png' => $performanceChartPath, 'top-payment-channels.png' => $topChannelChartPath, 'daily-payment-trend.png' => $dailyTrendChartPath, 'ticket-segments.png' => $ticketChartPath];
            $media += [
                'certificates.png' => __DIR__ . '/assets/certificates.png',
                'licenses.png' => __DIR__ . '/assets/licenses.png',
                'contact-x.png' => __DIR__ . '/assets/contact-x.png',
                'contact-instagram.jpeg' => __DIR__ . '/assets/contact-instagram.jpeg',
                'contact-facebook.png' => __DIR__ . '/assets/contact-facebook.png',
                'contact-website.png' => __DIR__ . '/assets/contact-website.png',
            ];
            if ($logo !== null) $media = ['company-logo.png' => $logo['path']] + $media;
            $this->package->write($outputPath, $document, $this->stylesXml(), [
                'title' => "Laporan Performansi Bulanan {$periodLabel}",
                'subject' => "Laporan Performansi Merchant {$merchant}",
            ], $media);
        } finally {
            if (is_file($chartPath)) unlink($chartPath);
            if (is_file($performanceChartPath)) unlink($performanceChartPath);
            if (is_file($topChannelChartPath)) unlink($topChannelChartPath);
            if (is_file($dailyTrendChartPath)) unlink($dailyTrendChartPath);
            if (is_file($ticketChartPath)) unlink($ticketChartPath);
        }
        return ['path' => $outputPath, 'filename' => basename($outputPath), 'sha256' => hash_file('sha256', $outputPath), 'report_period' => $period->format('Y-m-01')];
    }

    /** Membentuk XML dokumen cover berukuran A4 sesuai urutan teks pada template. */
    private function documentXml(string $period, string $address, string $phone, string $fax, ?array $logo, string $merchant, string $issuedAt, array $summary): string
    {
        $paragraphs = [
            $this->paragraph('', 'CoverSpacer'),
            $logo === null ? '' : $this->logoDrawing((int) $logo['width'], (int) $logo['height']),
            $this->paragraph('LAPORAN PERFORMANSI BULANAN', 'CoverTitle'),
            $this->paragraph('PERIODE ' . $period, 'CoverPeriod'),
            $this->paragraph('', 'CoverContactSpacer'),
        ];
        if ($address !== '') $paragraphs[] = $this->paragraph($address, 'CoverContact');
        if ($phone !== '') $paragraphs[] = $this->paragraph('Phone : ' . $phone, 'CoverContact');
        if ($fax !== '') $paragraphs[] = $this->paragraph('Fax : ' . $fax, 'CoverContact');
        $paragraphs[] = $this->pageBreak();
        $paragraphs[] = $this->paragraph('', 'TitlePageSpacer');
        $paragraphs[] = $this->paragraph('LAPORAN PERFORMANSI MERCHANT', 'TitlePageHeading');
        $paragraphs[] = $this->paragraph('UNTUK', 'TitlePageFor');
        $paragraphs[] = $this->paragraph(mb_strtoupper($merchant, 'UTF-8'), 'TitlePageMerchant');
        $paragraphs[] = $this->paragraph('Jakarta, ' . $issuedAt, 'TitlePageDate');
        $paragraphs[] = $this->pageBreak();
        $paragraphs[] = $this->paragraph('DAFTAR ISI', 'TableOfContentsTitle');
        $paragraphs[] = $this->tableOfContents();
        $paragraphs[] = $this->pageBreak();
        $paragraphs[] = $this->paragraph($period, 'Heading1');
        $paragraphs[] = $this->paragraph($this->summaryIntroduction($merchant, $period), 'BodyText');
        $paragraphs[] = $this->summaryTable($summary, $period);
        $paragraphs[] = $this->paragraph('Berdasarkan tabel di atas, total transaksi pada periode ' . $period . ' adalah sebagai berikut:', 'SummaryLead');
        foreach ($this->summaryNarratives($summary) as $narrative) $paragraphs[] = $this->paragraph("\u{2022} " . $narrative, 'SummaryBullet');
        $paragraphs[] = $this->comparisonLayout($summary['metrics']['payment_comparison']);
        $paragraphs[] = $this->pageBreak();
        $paragraphs[] = $this->paragraph('PERFORMANCE', 'Heading1');
        $paragraphs[] = $this->paragraph('Performance memperlihatkan tingkat keberhasilan transaksi pada sistem dengan ' . mb_strtoupper($merchant, 'UTF-8') . ' sebagai Biller-nya.', 'BodyText');
        $paragraphs[] = $this->paragraph('TINGKAT KEBERHASILAN TRANSAKSI TERHADAP TRANSAKSI GAGAL', 'Heading2');
        $paragraphs[] = $this->paragraph('Berikut ini ditampilkan performansi pembayaran ' . mb_strtoupper($merchant, 'UTF-8') . ' berdasarkan response code timeout.', 'BodyText');
        $paragraphs[] = $this->performanceTable($summary['performance']);
        $paragraphs[] = $this->paragraph('Pada grafik di atas menginformasikan bahwa pada bulan ' . $period . ' sukses rate transaksi ' . mb_strtoupper($merchant, 'UTF-8') . ' adalah sebesar ' . $this->percentageTwoDecimals((float) $summary['performance']['totals']['success_rate']) . '.', 'BodyText');
        $paragraphs[] = $this->paragraph('Berikut adalah ratio yang membandingkan jumlah transaksi sukses payment dan transaksi gagal timeout,', 'BodyText');
        $paragraphs[] = $this->performanceComparisonLayout($summary['performance']);
        $paragraphs[] = $this->pageBreak();
        $paragraphs[] = $this->paragraph('TINGKAT KEBERHASILAN TRANSAKSI BERDASARKAN CHANNEL', 'Heading2');
        $paragraphs[] = $this->paragraph('Berikut adalah jumlah transaksi berdasarkan pada channel pembayaran', 'BodyText');
        $paragraphs[] = $this->paymentChannelPerformanceTable($summary['payment_channel_performance']);
        $paragraphs[] = $this->paragraph($this->paymentChannelPerformanceNarrative($merchant, $period, $summary['payment_channel_performance']), 'BodyText');
        $paragraphs[] = $this->pageBreak();
        $paragraphs[] = $this->paragraph('TINGKAT KEBERHASILAN TRANSAKSI BERDASARKAN TOP CHANNEL', 'Heading2');
        $paragraphs[] = $this->paragraph('Berikut adalah ratio yang membandingkan jumlah transaksi sukses payment dan transaksi gagal timeout', 'BodyText');
        $paragraphs[] = $this->topPaymentChannelChartDrawing();
        $paragraphs[] = $this->paragraph('Terlihat bahwa ratio terhadap TOP pembayaran berdasarkan channel pembayaran yang digunakan adalah sebagai berikut :', 'BodyText');
        foreach ($this->topPaymentChannelNarratives($summary['top_payment_channels']) as $narrative) $paragraphs[] = $this->bulletListParagraph($narrative);
        $paragraphs[] = $this->pageBreak();
        $paragraphs[] = $this->paragraph('TREND TRANSAKSI HARIAN', 'Heading2');
        $paragraphs[] = $this->paragraph('Berikut ini adalah trend transaksi sukses harian pada ' . mb_strtoupper($merchant, 'UTF-8') . ' yang terjadi di bulan ' . $period . '.', 'BodyText');
        $paragraphs[] = $this->dailyTrendChartDrawing();
        $paragraphs[] = $this->paragraph($this->dailyTrendNarrative($merchant, $period, $summary['daily_trend']), 'BodyText');
        $paragraphs[] = $this->dailyTrendTable($summary['daily_trend']);
        $paragraphs[] = $this->pageBreak();
        $paragraphs[] = $this->paragraph('ADUAN DAN INSIDEN', 'Heading1');
        $paragraphs[] = $this->paragraph($period, 'Heading2');
        $paragraphs[] = $this->paragraph('TIKET ADUAN', 'Heading2');
        $paragraphs[] = $this->paragraph($this->ticketSummaryNarrative($merchant, $period, $summary['ticket_summary']), 'BodyText');
        $paragraphs[] = $this->ticketSummaryTable($summary['ticket_summary']);
        $paragraphs[] = $this->ticketChartDrawing();
        $paragraphs[] = $this->pageBreak();
        $paragraphs[] = $this->paragraph('LAPORAN INSIDEN', 'Heading2');
        $paragraphs[] = $this->paragraph('Pada bulan ' . $period . ' diterima laporan Insiden baik dari Internal maupun Eksternal yang dapat mempengaruhi Success Rate transaksi ' . mb_strtoupper($merchant, 'UTF-8') . '.', 'BodyText');
        $paragraphs[] = $this->incidentManualTable();
        $paragraphs[] = $this->pageBreak();
        $paragraphs[] = $this->paragraph('KESIMPULAN', 'Heading1');
        foreach ($this->conclusionNarratives($merchant, $period, $summary) as $narrative) $paragraphs[] = $this->bulletListParagraph($narrative);
        $paragraphs[] = $this->paragraph('Demikian laporan ini kami sampaikan dan selanjutnya laporan ini dapat digunakan sebagai kelengkapan dokumen lain sebagaimana mestinya.', 'BodyText');
        $paragraphs[] = $this->pageBreak();
        $paragraphs[] = $this->closingStaticPages();

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . implode('', $paragraphs)
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1080" w:bottom="1440" w:left="1080" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>'
            . '</w:body></w:document>';
    }

    /** Membentuk satu paragraf Word menggunakan style terdaftar dan teks aman. */
    private function paragraph(string $text, string $style): string
    {
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<w:p><w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr><w:r><w:t xml:space="preserve">' . $escaped . '</w:t></w:r></w:p>';
    }

    /** Membentuk item native bullet list Word memakai definisi numbering paket DOCX. */
    private function bulletListParagraph(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<w:p><w:pPr><w:pStyle w:val="BodyText"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>'
            . '<w:r><w:t xml:space="preserve">' . $escaped . '</w:t></w:r></w:p>';
    }

    /** Membentuk page break eksplisit agar bagian berikutnya selalu dimulai di halaman baru. */
    private function pageBreak(): string
    {
        return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    /** Membentuk field TOC Word yang mengambil heading level satu sampai tiga. */
    private function tableOfContents(): string
    {
        return '<w:p><w:r><w:fldChar w:fldCharType="begin" w:dirty="true"/></w:r>'
            . '<w:r><w:instrText xml:space="preserve"> TOC \\o "1-3" \\h \\z \\u </w:instrText></w:r>'
            . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            . '<w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>';
    }

    /** Membentuk paragraf pengantar ringkasan menggunakan substansi dari template lama. */
    private function summaryIntroduction(string $merchant, string $period): string
    {
        $name = mb_strtoupper($merchant, 'UTF-8');
        return "{$name} merupakan salah satu biller yang saat ini bekerja sama dengan Finnet dalam penyediaan layanan switching pembayaran melalui berbagai channel Bank dan NonBank, menggunakan metode pembayaran berupa virtual account maupun uang elektronik. Dokumen ini menyajikan laporan performansi biller tersebut untuk periode {$period}.";
    }

    /** Membentuk tabel Word asli berisi inquiry, payment, nominal, dan grand total. */
    private function summaryTable(array $summary, string $period): string
    {
        $rows = [$this->tableRow(['NAMA BANK', $period, '', ''], true), $this->tableRow(['', 'Inquiry Success', 'Payment Success', 'Amount'], true)];
        foreach ($summary['rows'] as $row) {
            $rows[] = $this->tableRow([
                (string) $row['partner_channel'],
                $this->integer((int) $row['inquiry_success']),
                $this->integer((int) $row['payment_success']),
                $this->amount((float) $row['payment_amount']),
            ]);
        }
        $rows[] = $this->tableRow([
            'GRAND TOTAL',
            $this->integer((int) $summary['totals']['inquiry_success']),
            $this->integer((int) $summary['totals']['payment_success']),
            $this->amount((float) $summary['totals']['payment_amount']),
        ], true);
        return '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblLayout w:type="fixed"/><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="808080"/><w:left w:val="single" w:sz="4" w:color="808080"/><w:bottom w:val="single" w:sz="4" w:color="808080"/><w:right w:val="single" w:sz="4" w:color="808080"/><w:insideH w:val="single" w:sz="4" w:color="BFBFBF"/><w:insideV w:val="single" w:sz="4" w:color="BFBFBF"/>'
            . '</w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="3300"/><w:gridCol w:w="1900"/><w:gridCol w:w="1900"/><w:gridCol w:w="2600"/></w:tblGrid>' . implode('', $rows) . '</w:tbl>';
    }

    /** Membentuk satu baris tabel dengan opsi format tebal untuk header dan total. */
    private function tableRow(array $values, bool $bold = false): string
    {
        $cells = '';
        foreach ($values as $index => $value) $cells .= $this->tableCell((string) $value, [3300, 1900, 1900, 2600][$index], $bold, $index === 0 ? 'left' : 'right');
        return '<w:tr>' . $cells . '</w:tr>';
    }

    /** Membentuk sel tabel aman dengan lebar, alignment, dan format teks terkontrol. */
    private function tableCell(string $value, int $width, bool $bold, string $alignment): string
    {
        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return "<w:tc><w:tcPr><w:tcW w:w=\"{$width}\" w:type=\"dxa\"/><w:tcMar><w:top w:w=\"80\" w:type=\"dxa\"/><w:left w:w=\"100\" w:type=\"dxa\"/><w:bottom w:w=\"80\" w:type=\"dxa\"/><w:right w:w=\"100\" w:type=\"dxa\"/></w:tcMar></w:tcPr><w:p><w:pPr><w:jc w:val=\"{$alignment}\"/></w:pPr><w:r><w:rPr>" . ($bold ? '<w:b/>' : '') . "</w:rPr><w:t xml:space=\"preserve\">{$escaped}</w:t></w:r></w:p></w:tc>";
    }

    /** Memformat bilangan transaksi dengan pemisah ribuan Indonesia. */
    private function integer(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    /** Memformat nominal tanpa desimal sesuai tampilan tabel laporan lama. */
    private function amount(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    /** Membentuk narasi faktual ringkasan tanpa menambahkan interpretasi bisnis. */
    private function summaryNarratives(array $summary): array
    {
        $topInquiry = $summary['metrics']['top_inquiry'];
        $topPayment = $summary['metrics']['top_payment'];
        $inquiryNarrative = $this->integer((int) $summary['totals']['inquiry_success']) . ' transaksi sukses inquiry.';
        if ($topInquiry !== null) $inquiryNarrative .= ' Inquiry tertinggi terdapat pada channel ' . $topInquiry['partner_channel'] . ' sebesar ' . $this->integer((int) $topInquiry['total']) . ' transaksi.';
        $paymentNarrative = $this->integer((int) $summary['totals']['payment_success']) . ' transaksi sukses payment.';
        if ($topPayment !== null) $paymentNarrative .= ' Payment tertinggi terdapat pada channel ' . $topPayment['partner_channel'] . ' sebesar ' . $this->integer((int) $topPayment['total']) . ' transaksi.';
        return [
            $inquiryNarrative,
            $paymentNarrative,
            'Jumlah transaksi payment adalah sebesar ' . $this->percentage((float) $summary['metrics']['payment_to_inquiry_percentage']) . ' dibandingkan dengan total inquiry.',
        ];
    }

    /** Memformat persentase maksimal dua desimal dan menghapus angka nol yang tidak diperlukan. */
    private function percentage(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') . '%';
    }

    /** Membentuk narasi perbandingan payment sukses bulan laporan dengan bulan sebelumnya. */
    private function paymentComparisonNarrative(array $comparison): string
    {
        $current = $this->integer((int) $comparison['current_total']);
        $previous = $this->integer((int) $comparison['previous_total']);
        if ($comparison['direction'] === 'stable') return "Payment sukses pada {$comparison['current_label']} tercatat {$current} transaksi, sama dengan {$comparison['previous_label']}.";
        if ($comparison['percentage_change'] === null) return "Payment sukses pada {$comparison['current_label']} tercatat {$current} transaksi, sedangkan pada {$comparison['previous_label']} belum terdapat transaksi sukses.";
        $direction = $comparison['direction'] === 'increase' ? 'peningkatan' : 'penurunan';
        return "Payment sukses pada {$comparison['current_label']} tercatat {$current} transaksi, dibandingkan {$previous} transaksi pada {$comparison['previous_label']}. Terjadi {$direction} sebesar " . $this->integer(abs((int) $comparison['difference'])) . ' transaksi atau ' . $this->percentage(abs((float) $comparison['percentage_change'])) . '.';
    }

    /** Menempatkan grafik dan narasi perbandingan sejajar dalam tabel dua kolom tanpa border. */
    private function comparisonLayout(array $comparison): string
    {
        $narrative = $this->paragraph($this->paymentComparisonNarrative($comparison), 'BodyText');
        return '<w:tbl><w:tblPr><w:tblW w:w="9700" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>'
            . '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/>'
            . '</w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="5600"/><w:gridCol w:w="4100"/></w:tblGrid><w:tr>'
            . '<w:tc><w:tcPr><w:tcW w:w="5600" w:type="dxa"/><w:vAlign w:val="center"/><w:tcMar><w:right w:w="180" w:type="dxa"/></w:tcMar></w:tcPr>' . $this->chartDrawing() . '</w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="4100" w:type="dxa"/><w:vAlign w:val="center"/><w:tcMar><w:left w:w="180" w:type="dxa"/></w:tcMar></w:tcPr>' . $narrative . '</w:tc>'
            . '</w:tr></w:tbl>';
    }

    /** Membentuk tabel RC 0, RC 68, RC 82, dan success rate sesuai susunan template laporan. */
    private function performanceTable(array $performance): string
    {
        $rows = [
            '<w:tr>'
                . $this->performanceTableCell('NAMA BANK', 2800, true, 'center', 1, 'restart')
                . $this->performanceTableCell('Response Code', 4500, true, 'center', 3)
                . $this->performanceTableCell('SR', 1600, true, 'center', 1, 'restart')
                . '</w:tr>',
            '<w:tr>'
                . $this->performanceTableCell('', 2800, true, 'center', 1, 'continue')
                . $this->performanceTableCell('0', 1500, true, 'center')
                . $this->performanceTableCell('68', 1500, true, 'center')
                . $this->performanceTableCell('82', 1500, true, 'center')
                . $this->performanceTableCell('', 1600, true, 'center', 1, 'continue')
                . '</w:tr>',
        ];
        foreach ($performance['rows'] as $row) {
            $rows[] = $this->performanceTableRow([
                (string) $row['partner_channel'],
                $this->integer((int) $row['success']),
                $this->performanceCount((int) $row['rc_68']),
                $this->performanceCount((int) $row['rc_82']),
                $this->percentageTwoDecimals((float) $row['success_rate']),
            ]);
        }
        $totals = $performance['totals'];
        $rows[] = $this->performanceTableRow([
            'GRAND TOTAL',
            $this->integer((int) $totals['success']),
            $this->performanceCount((int) $totals['rc_68']),
            $this->performanceCount((int) $totals['rc_82']),
            $this->percentageTwoDecimals((float) $totals['success_rate']),
        ], true);
        return '<w:tbl><w:tblPr><w:tblW w:w="9700" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="808080"/><w:left w:val="single" w:sz="4" w:color="808080"/><w:bottom w:val="single" w:sz="4" w:color="808080"/><w:right w:val="single" w:sz="4" w:color="808080"/><w:insideH w:val="single" w:sz="4" w:color="BFBFBF"/><w:insideV w:val="single" w:sz="4" w:color="BFBFBF"/>'
            . '</w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="2800"/><w:gridCol w:w="1500"/><w:gridCol w:w="1500"/><w:gridCol w:w="1500"/><w:gridCol w:w="1600"/></w:tblGrid>' . implode('', $rows) . '</w:tbl>';
    }

    /** Membentuk satu baris tabel performance dengan lima kolom berlebar tetap. */
    private function performanceTableRow(array $values, bool $bold = false): string
    {
        $widths = [2800, 1500, 1500, 1500, 1600];
        $cells = '';
        foreach ($values as $index => $value) $cells .= $this->performanceTableCell((string) $value, $widths[$index], $bold, $index === 0 ? 'left' : 'right');
        return '<w:tr>' . $cells . '</w:tr>';
    }

    /** Membentuk tabel performansi berdasarkan payment channel dengan susunan yang sama seperti template. */
    private function paymentChannelPerformanceTable(array $performance): string
    {
        $rows = [
            '<w:tr>'
                . $this->performanceTableCell('CHANNEL', 2800, true, 'center', 1, 'restart')
                . $this->performanceTableCell('Response Code', 4500, true, 'center', 3)
                . $this->performanceTableCell('SR', 1600, true, 'center', 1, 'restart')
                . '</w:tr>',
            '<w:tr>'
                . $this->performanceTableCell('', 2800, true, 'center', 1, 'continue')
                . $this->performanceTableCell('0', 1500, true, 'center')
                . $this->performanceTableCell('68', 1500, true, 'center')
                . $this->performanceTableCell('82', 1500, true, 'center')
                . $this->performanceTableCell('', 1600, true, 'center', 1, 'continue')
                . '</w:tr>',
        ];
        foreach ($performance['rows'] as $row) {
            $rows[] = $this->performanceTableRow([
                (string) $row['payment_channel'],
                $this->integer((int) $row['success']),
                $this->performanceCount((int) $row['rc_68']),
                $this->performanceCount((int) $row['rc_82']),
                $this->percentageTwoDecimals((float) $row['success_rate']),
            ]);
        }
        $totals = $performance['totals'];
        $rows[] = $this->performanceTableRow([
            'GRAND TOTAL',
            $this->integer((int) $totals['success']),
            $this->performanceCount((int) $totals['rc_68']),
            $this->performanceCount((int) $totals['rc_82']),
            $this->percentageTwoDecimals((float) $totals['success_rate']),
        ], true);
        return '<w:tbl><w:tblPr><w:tblW w:w="9700" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="808080"/><w:left w:val="single" w:sz="4" w:color="808080"/><w:bottom w:val="single" w:sz="4" w:color="808080"/><w:right w:val="single" w:sz="4" w:color="808080"/><w:insideH w:val="single" w:sz="4" w:color="BFBFBF"/><w:insideV w:val="single" w:sz="4" w:color="BFBFBF"/>'
            . '</w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="2800"/><w:gridCol w:w="1500"/><w:gridCol w:w="1500"/><w:gridCol w:w="1500"/><w:gridCol w:w="1600"/></w:tblGrid>' . implode('', $rows) . '</w:tbl>';
    }

    /** Membentuk narasi channel pembayaran memakai channel dengan transaksi sukses terbesar. */
    private function paymentChannelPerformanceNarrative(string $merchant, string $period, array $performance): string
    {
        if ($performance['rows'] === []) return 'Pada periode ' . $period . ' belum terdapat transaksi pada channel pembayaran.';
        $top = $performance['rows'][0];
        $successTotal = (int) $performance['totals']['success'];
        $share = $successTotal > 0 ? ((int) $top['success'] / $successTotal) * 100 : 0.0;
        $narrative = 'Pada tabel di atas terlihat bahwa pada bulan ' . $period . ' channel pembayaran untuk transaksi ' . mb_strtoupper($merchant, 'UTF-8')
            . ' masih dengan channel ' . $top['payment_channel'] . ' dengan jumlah transaksi sukses sebesar ' . $this->integer((int) $top['success'])
            . ' transaksi atau sebesar ' . $this->percentageTwoDecimals($share) . ' dari keseluruhan total transaksi.';
        if ((int) $performance['totals']['rc_68'] === 0 && (int) $performance['totals']['rc_82'] === 0) {
            $narrative .= ' Dan berdasarkan tabel di atas tidak ditemukan kegagal transkasi yang disebabkan oleh RC 68 atau RC 82.';
        }
        return $narrative;
    }

    /** Membentuk sel tabel performance termasuk merge header vertikal dan horizontal. */
    private function performanceTableCell(string $value, int $width, bool $bold, string $alignment, int $gridSpan = 1, ?string $verticalMerge = null): string
    {
        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $merge = $verticalMerge === null ? '' : '<w:vMerge' . ($verticalMerge === 'restart' ? ' w:val="restart"' : '') . '/>';
        $span = $gridSpan > 1 ? '<w:gridSpan w:val="' . $gridSpan . '"/>' : '';
        return '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/>' . $span . $merge . '<w:vAlign w:val="center"/></w:tcPr>'
            . '<w:p><w:pPr><w:jc w:val="' . $alignment . '"/></w:pPr><w:r><w:rPr>' . ($bold ? '<w:b/>' : '') . '</w:rPr><w:t xml:space="preserve">' . $escaped . '</w:t></w:r></w:p></w:tc>';
    }

    /** Menampilkan nol RC timeout sebagai tanda strip seperti tabel pada template. */
    private function performanceCount(int $value): string
    {
        return $value === 0 ? '-' : $this->integer($value);
    }

    /** Memformat success rate dengan dua angka desimal seperti tampilan tabel pada template. */
    private function percentageTwoDecimals(float $value): string
    {
        return number_format($value, 2, '.', ',') . '%';
    }

    /** Menempatkan pie chart dan narasi response code timeout berdampingan seperti template. */
    private function performanceComparisonLayout(array $performance): string
    {
        $failed = (int) $performance['totals']['rc_68'] + (int) $performance['totals']['rc_82'];
        $narrative = $failed === 0
            ? 'Pada grafik disamping terlihat bahwa tidak ada transaksi gagal yang disebabkan oleh response code time out atau RC 82 dan RC 68.'
            : 'Pada grafik disamping terlihat terdapat ' . $this->integer($failed) . ' transaksi gagal yang disebabkan oleh response code time out RC 82 dan RC 68.';
        return '<w:tbl><w:tblPr><w:tblW w:w="9700" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="5700"/><w:gridCol w:w="4000"/></w:tblGrid><w:tr>'
            . '<w:tc><w:tcPr><w:tcW w:w="5700" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' . $this->performanceChartDrawing() . '</w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="4000" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' . $this->paragraph($narrative, 'BodyText') . '</w:tc>'
            . '</w:tr></w:tbl>';
    }

    /** Membentuk narasi nilai dan porsi setiap payment channel yang masuk lima terbesar. */
    private function topPaymentChannelNarratives(array $channels): array
    {
        return array_map(fn (array $channel): string => 'Jumlah transaksi pada channel ' . $channel['payment_channel'] . ' adalah '
            . $this->integer((int) $channel['total']) . ' transaksi atau ' . $this->percentage((float) $channel['percentage']) . '.', $channels);
    }

    /** Menanam pie chart top payment channel pada subbab sesuai template. */
    private function topPaymentChannelChartDrawing(): string
    {
        return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="120" w:after="240"/></w:pPr><w:r><w:drawing>'
            . '<wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="5029200" cy="3462000"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:docPr id="4" name="Top Channel Pembayaran"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="4" name="top-payment-channels.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId6"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="5029200" cy="3462000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    /** Menanam grafik komposisi status payment pada halaman performance. */
    private function performanceChartDrawing(): string
    {
        return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="240" w:after="240"/></w:pPr><w:r><w:drawing>'
            . '<wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="3556000" cy="2766000"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:docPr id="3" name="Ration Sukses dan Gagal Payment"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="3" name="payment-status-composition.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId5"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="3556000" cy="2766000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    /** Membentuk narasi tren harian berdasarkan kenaikan terbesar serta tanggal transaksi tertinggi dan terendah. */
    private function dailyTrendNarrative(string $merchant, string $period, array $dailyTrend): string
    {
        $metrics = $dailyTrend['metrics'];
        $increase = $metrics['largest_increase'];
        $narrative = 'Pada grafik di atas, terlihat bahwa transaksi sukses ' . mb_strtoupper($merchant, 'UTF-8') . ' pada bulan ' . $period
            . ' tercatat beberapa kali mengalami peningkatan dan penurunan transaksi, namun tidak disertai dengan peningkatan transaksi gagal yang disebabkan oleh response code 68 dan 82.';
        if ($increase['from_date'] !== null && $increase['to_date'] !== null && (int) $increase['difference'] > 0) {
            $narrative .= ' Peningkatan transaksi yang signifikan terjadi pada ' . (new DateTimeImmutable($increase['from_date']))->format('d') . ' ke '
                . (new DateTimeImmutable($increase['to_date']))->format('d') . ' ' . $period . ' dengan total ' . $this->integer((int) $increase['difference']) . ' transaksi.';
        }
        if ($metrics['highest'] !== null) $narrative .= ' Transaksi tertinggi terjadi pada ' . $this->dayMonth((string) $metrics['highest']['date']) . ' dengan ' . $this->integer((int) $metrics['highest']['total']) . ' transaksi,';
        if ($metrics['lowest'] !== null) $narrative .= ' sedang transaksi terendah terjadi pada tanggal ' . $this->dayMonth((string) $metrics['lowest']['date']) . ' dengan ' . $this->integer((int) $metrics['lowest']['total']) . ' transaksi.';
        return $narrative . ' Tabel di bawah ini menginformasikan terkait detail transaksi sukses dan gagal per tanggal periode ' . $period . '.';
    }

    /** Membentuk tabel detail transaksi harian dengan kolom yang sama seperti template. */
    private function dailyTrendTable(array $dailyTrend): string
    {
        $rows = ['<w:tr><w:trPr><w:tblHeader/></w:trPr>' . $this->dailyTableRowCells(['Tanggal', 'Sukses', 'RC 68', 'RC 82', 'SR'], true) . '</w:tr>'];
        foreach ($dailyTrend['rows'] as $row) {
            $rows[] = '<w:tr>' . $this->dailyTableRowCells([
                (string) $row['date'],
                $this->integer((int) $row['success']),
                $this->performanceCount((int) $row['rc_68']),
                $this->performanceCount((int) $row['rc_82']),
                $this->percentageTwoDecimals((float) $row['success_rate']),
            ]) . '</w:tr>';
        }
        $totalSuccess = (int) $dailyTrend['metrics']['total_success'];
        $total68 = array_sum(array_column($dailyTrend['rows'], 'rc_68'));
        $total82 = array_sum(array_column($dailyTrend['rows'], 'rc_82'));
        $total = $totalSuccess + $total68 + $total82;
        $rows[] = '<w:tr>' . $this->dailyTableRowCells(['Grand Total', $this->integer($totalSuccess), $this->performanceCount($total68), $this->performanceCount($total82), $this->percentageTwoDecimals($total > 0 ? ($totalSuccess / $total) * 100 : 0.0)], true) . '</w:tr>';
        return '<w:tbl><w:tblPr><w:tblW w:w="8500" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="808080"/><w:left w:val="single" w:sz="4" w:color="808080"/><w:bottom w:val="single" w:sz="4" w:color="808080"/><w:right w:val="single" w:sz="4" w:color="808080"/><w:insideH w:val="single" w:sz="4" w:color="BFBFBF"/><w:insideV w:val="single" w:sz="4" w:color="BFBFBF"/></w:tblBorders></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="2500"/><w:gridCol w:w="1500"/><w:gridCol w:w="1500"/><w:gridCol w:w="1500"/><w:gridCol w:w="1500"/></w:tblGrid>' . implode('', $rows) . '</w:tbl>';
    }

    /** Membentuk kumpulan lima sel untuk satu baris tabel tren harian. */
    private function dailyTableRowCells(array $values, bool $bold = false): string
    {
        $widths = [2500, 1500, 1500, 1500, 1500];
        $cells = '';
        foreach ($values as $index => $value) $cells .= $this->tableCell((string) $value, $widths[$index], $bold, $index === 0 ? 'left' : 'right');
        return $cells;
    }

    /** Menanam grafik tren transaksi payment harian pada dokumen Word. */
    private function dailyTrendChartDrawing(): string
    {
        return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="120" w:after="240"/></w:pPr><w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="6096000" cy="2275840"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:docPr id="5" name="Trend Transaksi Payment"/><wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="5" name="daily-payment-trend.png"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId8"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="6096000" cy="2275840"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    /** Memformat tanggal ISO menjadi tanggal dan nama bulan Indonesia tanpa tahun. */
    private function dayMonth(string $date): string
    {
        $value = new DateTimeImmutable($date);
        return $value->format('d') . ' ' . ucfirst(strtolower(self::MONTHS[(int) $value->format('n')]));
    }

    /** Membentuk narasi tiket berdasarkan total unik, segmentasi, dan komposisi status periode laporan. */
    private function ticketSummaryNarrative(string $merchant, string $period, array $summary): string
    {
        $text = 'Berikut adalah tiket aduan yang diterima Finnet dari ' . mb_strtoupper($merchant, 'UTF-8') . ' selama bulan ' . $period . '. Total tiket laporan aduan sejumlah '
            . $this->integer((int) $summary['total']) . ' tiket';
        if ($summary['segments'] !== []) {
            $parts = array_map(fn (array $row): string => $row['complaint_segment'] . ' sejumlah ' . $this->integer((int) $row['total']) . ' tiket', $summary['segments']);
            $text .= ', dengan segmentasi yang dibagi menjadi beberapa kriteria di antaranya adalah ' . $this->indonesianList($parts) . '.';
        } else {
            $text .= '.';
        }
        if (count($summary['statuses']) === 1 && (int) $summary['statuses'][0]['total'] === (int) $summary['total'] && (int) $summary['total'] > 0) {
            $status = strtolower((string) $summary['statuses'][0]['status']) === 'close' ? 'closed' : strtolower((string) $summary['statuses'][0]['status']);
            $text .= ' Dan saat ini keseluruhan tiket aduan statusnya sudah ' . $status . '.';
        }
        return $text;
    }

    /** Menggabungkan daftar frasa menggunakan koma dan kata “dan” sesuai narasi Bahasa Indonesia. */
    private function indonesianList(array $parts): string
    {
        if (count($parts) < 2) return $parts[0] ?? '';
        $last = array_pop($parts);
        return implode(', ', $parts) . ', dan ' . $last;
    }

    /** Membentuk tabel ringkasan jumlah Ticket No unik per segmentasi keluhan. */
    private function ticketSummaryTable(array $summary): string
    {
        $rows = ['<w:tr>' . $this->ticketTableCells(['Segmentasi Keluhan', 'Total Keluhan'], true) . '</w:tr>'];
        foreach ($summary['segments'] as $row) $rows[] = '<w:tr>' . $this->ticketTableCells([(string) $row['complaint_segment'], $this->integer((int) $row['total'])]) . '</w:tr>';
        $rows[] = '<w:tr>' . $this->ticketTableCells(['Grand Total', $this->integer((int) $summary['total'])], true) . '</w:tr>';
        return '<w:tbl><w:tblPr><w:tblW w:w="6500" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="808080"/><w:left w:val="single" w:sz="4" w:color="808080"/><w:bottom w:val="single" w:sz="4" w:color="808080"/><w:right w:val="single" w:sz="4" w:color="808080"/><w:insideH w:val="single" w:sz="4" w:color="BFBFBF"/><w:insideV w:val="single" w:sz="4" w:color="BFBFBF"/></w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="4700"/><w:gridCol w:w="1800"/></w:tblGrid>' . implode('', $rows) . '</w:tbl>';
    }

    /** Membentuk dua sel untuk satu baris tabel ringkasan tiket. */
    private function ticketTableCells(array $values, bool $bold = false): string
    {
        return $this->tableCell((string) $values[0], 4700, $bold, 'left') . $this->tableCell((string) $values[1], 1800, $bold, 'right');
    }

    /** Menanam pie chart segmentasi tiket aduan ke dokumen Word. */
    private function ticketChartDrawing(): string
    {
        return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="240" w:after="240"/></w:pPr><w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" distT="0" distB="0" distL="0" distR="0"><wp:extent cx="4876800" cy="3149600"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:docPr id="6" name="Tiket Aduan per Segmentasi"/><wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="6" name="ticket-segments.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId9"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="4876800" cy="3149600"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    /** Membentuk tabel insiden manual yang hanya berisi header dan dapat ditambah baris langsung di Word. */
    private function incidentManualTable(): string
    {
        $headers = ['Tanggal Kendala', 'Kendala', 'Penyebab Kendala', 'Kategori Kendala', 'Penyelesaian', 'Durasi'];
        $widths = [1400, 1800, 1800, 1600, 1800, 1200];
        $cells = '';
        foreach ($headers as $index => $header) $cells .= $this->tableCell($header, $widths[$index], true, 'center');
        return '<w:tbl><w:tblPr><w:tblW w:w="9600" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="808080"/><w:left w:val="single" w:sz="4" w:color="808080"/><w:bottom w:val="single" w:sz="4" w:color="808080"/><w:right w:val="single" w:sz="4" w:color="808080"/><w:insideH w:val="single" w:sz="4" w:color="BFBFBF"/><w:insideV w:val="single" w:sz="4" w:color="BFBFBF"/></w:tblBorders></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="1400"/><w:gridCol w:w="1800"/><w:gridCol w:w="1800"/><w:gridCol w:w="1600"/><w:gridCol w:w="1800"/><w:gridCol w:w="1200"/></w:tblGrid>'
            . '<w:tr><w:trPr><w:tblHeader/></w:trPr>' . $cells . '</w:tr>'
            . '<w:tr>' . $this->incidentBlankRowCells($widths) . '</w:tr></w:tbl>';
    }

    /** Membentuk satu baris kosong editable pada tabel insiden untuk pengisian manual pengguna. */
    private function incidentBlankRowCells(array $widths): string
    {
        $cells = '';
        foreach ($widths as $width) $cells .= $this->tableCell('', (int) $width, false, 'left');
        return $cells;
    }

    /** Membentuk butir kesimpulan dinamis dari metrik transaksi dan channel periode laporan. */
    private function conclusionNarratives(string $merchant, string $period, array $summary): array
    {
        $name = mb_strtoupper($merchant, 'UTF-8');
        $totals = $summary['totals'];
        $metrics = $summary['metrics'];
        $performance = $summary['performance']['totals'];
        $daily = $summary['daily_trend']['metrics'];
        $topInquiry = $metrics['top_inquiry'];
        $topPayment = $metrics['top_payment'];
        $topPaymentRows = $topPayment === null ? [] : array_values(array_filter($summary['rows'], static fn (array $row): bool => $row['partner_channel'] === $topPayment['partner_channel']));
        $topPaymentRow = $topPaymentRows[0] ?? null;
        $topPaymentChannel = $summary['top_payment_channels'][0] ?? null;
        $conclusions = [
            'Transaksi sukses purchase ' . $name . ' pada bulan ' . $period . ' tercatat mencapai ' . $this->integer((int) $totals['payment_success']) . ' transaksi payment.',
            'Jumlah inquiry pada transaksi ' . $name . ' cukup besar namun tidak berbanding dengan jumlah purchase. Jumlah transaksi inquiry pada bulan ' . $period . ' adalah sebanyak ' . $this->integer((int) $totals['inquiry_success']) . ' transaksi dan transaksi purchase sebanyak ' . $this->integer((int) $totals['payment_success']) . ' transaksi atau sekitar ' . number_format((float) $metrics['payment_to_inquiry_percentage'], 0, ',', '.') . '% dari total keseluruhan inquiry yang dilakukan.',
        ];
        if ($topInquiry !== null && $topPayment !== null && $topPaymentRow !== null) {
            $conclusions[] = 'Inquiry tertinggi berjumlah ' . $this->integer((int) $topInquiry['total']) . ' dari channel ' . $topInquiry['partner_channel']
                . ' dan purchase tertinggi berjumlah ' . $this->integer((int) $topPayment['total']) . ' transaksi dengan nominal Rp ' . $this->amount((float) $topPaymentRow['payment_amount']) . ' dari channel ' . $topPayment['partner_channel'] . '.';
        }
        $failed = (int) $performance['rc_68'] + (int) $performance['rc_82'];
        $conclusions[] = $failed === 0
            ? 'Pada periode ' . $period . ' tidak diterima laporan transaksi gagal yang disebabkan oleh RC 82 dan RC 68 (response code Time Out).'
            : 'Pada periode ' . $period . ' terdapat ' . $this->integer($failed) . ' transaksi gagal yang disebabkan oleh RC 82 dan RC 68 (response code Time Out).';
        if ($topPaymentChannel !== null) $conclusions[] = 'Metode bayar yang digunakan pada transaksi ' . $name . ' periode ' . $period . ' adalah ' . $topPaymentChannel['payment_channel'] . ' yang mencapai ' . $this->integer((int) $topPaymentChannel['total']) . ' atau ' . $this->percentage((float) $topPaymentChannel['percentage']) . ' dari total transaksi.';
        $trend = 'Trend transaksi untuk sukses ' . $name . ' pada bulan ' . $period . ' tercatat beberapa kali mengalami peningkatan dan penurunan transaksi';
        if ($failed === 0) $trend .= ', tetapi tidak disertai dengan peningkatan transaksi gagal yang disebabkan oleh response code 68 dan 82';
        $increase = $daily['largest_increase'];
        if ($increase['from_date'] !== null && $increase['to_date'] !== null) $trend .= '. Peningkatan transaksi yang signifikan terjadi pada ' . (new DateTimeImmutable($increase['from_date']))->format('d') . ' ke ' . (new DateTimeImmutable($increase['to_date']))->format('d') . ' ' . $period . ' dengan total ' . $this->integer((int) $increase['difference']) . ' transaksi';
        if ($daily['highest'] !== null) $trend .= '. Transaksi tertinggi terjadi pada ' . $this->dayMonth((string) $daily['highest']['date']) . ' dengan ' . $this->integer((int) $daily['highest']['total']) . ' transaksi';
        if ($daily['lowest'] !== null) $trend .= ', sedang transaksi terendah terjadi pada tanggal ' . $this->dayMonth((string) $daily['lowest']['date']) . ' dengan ' . $this->integer((int) $daily['lowest']['total']) . ' transaksi';
        $conclusions[] = $trend . '. Rata-rata transaksi ' . $name . ' periode ' . $period . ' per hari adalah ' . $this->integer((int) $daily['average_success']) . ' transaksi sukses per hari.';
        $conclusions[] = 'Secara keseluruhan Success Rate transaksi ' . $name . ' pada bulan ' . $period . ' adalah ' . $this->percentage((float) $performance['success_rate']) . '.';
        return $conclusions;
    }

    /** Membentuk halaman portrait Sertifikat, Lisensi, dan kontak menggunakan aset valid dari template mentor. */
    private function closingStaticPages(): string
    {
        $contacts = [
            ['rId12', 'contact-x.png', '@akunX', 7],
            ['rId13', 'contact-instagram.jpeg', '@akunIG', 8],
            ['rId14', 'contact-facebook.png', '@akunFB', 9],
            ['rId15', 'contact-website.png', 'website', 10],
        ];
        $cells = '';
        foreach ($contacts as [$relationship, $name, $label, $docId]) {
            $cells .= '<w:tc><w:tcPr><w:tcW w:w="2425" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>'
                . $this->staticImageDrawing($relationship, $name, 347472, 347472, $docId)
                . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>' . htmlspecialchars($label, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r></w:p></w:tc>';
        }
        return $this->staticImageDrawing('rId10', 'certificates.png', 5943600, 910000, 11)
            . $this->staticImageDrawing('rId11', 'licenses.png', 5943600, 1910000, 12)
            . $this->paragraph('Get In Touch with Us', 'TableOfContentsTitle')
            . '<w:tbl><w:tblPr><w:tblW w:w="9700" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="2425"/><w:gridCol w:w="2425"/><w:gridCol w:w="2425"/><w:gridCol w:w="2425"/></w:tblGrid><w:tr>' . $cells . '</w:tr></w:tbl>';
    }

    /** Membentuk drawing inline untuk aset statis halaman penutup. */
    private function staticImageDrawing(string $relationshipId, string $name, int $width, int $height, int $docId): string
    {
        $safeName = htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="120"/></w:pPr><w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $width . '" cy="' . $height . '"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:docPr id="' . $docId . '" name="' . $safeName . '"/><wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="' . $docId . '" name="' . $safeName . '"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="' . $relationshipId . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $width . '" cy="' . $height . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    /** Membentuk drawing logo perusahaan yang tertanam dan berada di tengah halaman cover. */
    private function logoDrawing(int $pixelWidth, int $pixelHeight): string
    {
        $width = 3840480;
        $height = (int) round($width * $pixelHeight / $pixelWidth);
        return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="480"/></w:pPr><w:r><w:drawing>'
            . '<wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" distT="0" distB="0" distL="0" distR="0">'
            . "<wp:extent cx=\"{$width}\" cy=\"{$height}\"/><wp:effectExtent l=\"0\" t=\"0\" r=\"0\" b=\"0\"/><wp:docPr id=\"1\" name=\"Logo Perusahaan\" descr=\"Logo Finnet\"/>"
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="1" name="company-logo.png"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . "<pic:spPr><a:xfrm><a:off x=\"0\" y=\"0\"/><a:ext cx=\"{$width}\" cy=\"{$height}\"/></a:xfrm><a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom></pic:spPr>"
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    /** Membentuk drawing grafik perbandingan payment sukses dengan rasio gambar tetap. */
    private function chartDrawing(): string
    {
        $width = 3383280;
        $height = 1578864;
        return '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:drawing>'
            . '<wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" distT="0" distB="0" distL="0" distR="0">'
            . "<wp:extent cx=\"{$width}\" cy=\"{$height}\"/><wp:effectExtent l=\"0\" t=\"0\" r=\"0\" b=\"0\"/><wp:docPr id=\"2\" name=\"Grafik Perbandingan Payment Sukses\"/>"
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="2" name="summary-payment-comparison.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId4"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . "<pic:spPr><a:xfrm><a:off x=\"0\" y=\"0\"/><a:ext cx=\"{$width}\" cy=\"{$height}\"/></a:xfrm><a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom></pic:spPr>"
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    /** Membentuk kumpulan style dasar serta style khusus cover yang dapat diedit di Word. */
    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri"/><w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="id-ID" w:eastAsia="id-ID"/></w:rPr></w:rPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="CoverSpacer"><w:name w:val="Cover Spacer"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="240" w:after="0"/></w:pPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="CoverTitle"><w:name w:val="Cover Title"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="240"/><w:ind w:left="709"/></w:pPr><w:rPr><w:rFonts w:ascii="Calisto MT" w:hAnsi="Calisto MT" w:eastAsia="Calisto MT"/><w:b/><w:sz w:val="44"/><w:szCs w:val="44"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="CoverPeriod"><w:name w:val="Cover Period"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="240"/><w:ind w:left="709"/></w:pPr><w:rPr><w:rFonts w:ascii="Calisto MT" w:hAnsi="Calisto MT" w:eastAsia="Calisto MT"/><w:b/><w:sz w:val="44"/><w:szCs w:val="44"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="CoverContactSpacer"><w:name w:val="Cover Contact Spacer"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="1200"/></w:pPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="CoverContact"><w:name w:val="Cover Contact"/><w:basedOn w:val="Normal"/><w:pPr><w:ind w:left="709"/><w:jc w:val="both"/></w:pPr><w:rPr><w:i/><w:noProof/><w:lang w:val="id-ID" w:eastAsia="id-ID"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="TitlePageSpacer"><w:name w:val="Title Page Spacer"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="2160"/></w:pPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="TitlePageHeading"><w:name w:val="Title Page Heading"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:line="240" w:lineRule="atLeast" w:after="360"/><w:jc w:val="center"/></w:pPr><w:rPr><w:rFonts w:ascii="Calisto MT" w:hAnsi="Calisto MT"/><w:b/><w:sz w:val="48"/><w:szCs w:val="48"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="TitlePageFor"><w:name w:val="Title Page For"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:line="240" w:lineRule="atLeast" w:after="360"/><w:jc w:val="center"/></w:pPr><w:rPr><w:rFonts w:ascii="Calisto MT" w:hAnsi="Calisto MT"/><w:b/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="TitlePageMerchant"><w:name w:val="Title Page Merchant"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:line="240" w:lineRule="atLeast" w:after="720"/><w:jc w:val="center"/></w:pPr><w:rPr><w:rFonts w:ascii="Calisto MT" w:hAnsi="Calisto MT"/><w:b/><w:color w:val="C00000"/><w:sz w:val="72"/><w:szCs w:val="72"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="TitlePageDate"><w:name w:val="Title Page Date"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:line="240" w:lineRule="atLeast"/><w:jc w:val="center"/></w:pPr><w:rPr><w:rFonts w:ascii="Calisto MT" w:hAnsi="Calisto MT"/><w:b/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="TableOfContentsTitle"><w:name w:val="Table of Contents Title"/><w:basedOn w:val="Normal"/><w:pPr><w:jc w:val="center"/><w:spacing w:after="480"/></w:pPr><w:rPr><w:rFonts w:ascii="Calisto MT" w:hAnsi="Calisto MT"/><w:b/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:keepLines/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:rFonts w:ascii="Calisto MT" w:hAnsi="Calisto MT"/><w:b/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:keepLines/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:rFonts w:ascii="Calisto MT" w:hAnsi="Calisto MT"/><w:b/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:keepLines/><w:outlineLvl w:val="2"/></w:pPr><w:rPr><w:rFonts w:ascii="Calisto MT" w:hAnsi="Calisto MT"/><w:b/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="BodyText"><w:name w:val="Body Text"/><w:basedOn w:val="Normal"/><w:pPr><w:jc w:val="both"/><w:spacing w:after="240" w:line="276" w:lineRule="auto"/></w:pPr><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="SummaryLead"><w:name w:val="Summary Lead"/><w:basedOn w:val="BodyText"/><w:pPr><w:spacing w:before="240" w:after="120"/></w:pPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="SummaryBullet"><w:name w:val="Summary Bullet"/><w:basedOn w:val="BodyText"/><w:pPr><w:ind w:left="360" w:hanging="240"/><w:spacing w:after="100"/></w:pPr></w:style>'
            . '</w:styles>';
    }

    /** Memvalidasi periode dan menormalisasikannya ke tanggal pertama bulan laporan. */
    private function period(mixed $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('d') !== '01') throw new RuntimeException('Periode laporan harus berupa tanggal pertama bulan dalam format YYYY-MM-01.');
        return $date;
    }

    /** Memvalidasi tanggal ISO untuk informasi tanggal penerbitan laporan. */
    private function date(mixed $value, string $label): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) throw new RuntimeException("{$label} harus berformat YYYY-MM-DD.");
        return $date;
    }

    /** Memformat tanggal dengan nama bulan Indonesia sesuai placeholder halaman judul. */
    private function indonesianDate(DateTimeImmutable $date): string
    {
        $month = ucfirst(strtolower(self::MONTHS[(int) $date->format('n')]));
        return $date->format('d') . ' ' . $month . ' ' . $date->format('Y');
    }

    /** Mengambil teks wajib dengan normalisasi whitespace dan batas panjang. */
    private function requiredText(array $report, string $key, int $maximum): string
    {
        $value = $this->optionalText($report, $key, $maximum);
        if ($value === '') throw new RuntimeException("Field {$key} wajib diisi.");
        return $value;
    }

    /** Mengambil teks opsional, membersihkan whitespace, dan membatasi panjang input. */
    private function optionalText(array $report, string $key, int $maximum): string
    {
        $value = preg_replace('/\s+/u', ' ', trim((string) ($report[$key] ?? ''))) ?? '';
        if (mb_strlen($value) > $maximum) throw new RuntimeException("Field {$key} terlalu panjang.");
        return $value;
    }

    /** Memvalidasi logo PNG lokal dan mengambil dimensinya untuk mempertahankan rasio asli. */
    private function logo(mixed $value): ?array
    {
        $path = trim((string) $value);
        if ($path === '') return null;
        if (!is_file($path) || !is_readable($path) || strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'png') throw new RuntimeException('Logo perusahaan harus berupa file PNG yang dapat dibaca.');
        $image = getimagesize($path);
        if ($image === false || ($image['mime'] ?? '') !== 'image/png') throw new RuntimeException('Isi file logo perusahaan bukan PNG yang valid.');
        return ['path' => $path, 'width' => (int) $image[0], 'height' => (int) $image[1]];
    }

    /** Memvalidasi struktur ringkasan yang disuplai service sebelum dirender ke DOCX. */
    private function summary(mixed $value): array
    {
        if (!is_array($value) || !isset($value['rows'], $value['totals'], $value['metrics'], $value['performance'], $value['payment_channel_performance'], $value['top_payment_channels'], $value['daily_trend'], $value['ticket_summary']) || !is_array($value['rows']) || !is_array($value['totals']) || !is_array($value['metrics']) || !is_array($value['performance']) || !is_array($value['payment_channel_performance']) || !is_array($value['top_payment_channels']) || !is_array($value['daily_trend']) || !is_array($value['ticket_summary'])) throw new RuntimeException('Data ringkasan laporan tidak valid.');
        foreach ($value['rows'] as $row) {
            if (!is_array($row) || !array_key_exists('partner_channel', $row) || !array_key_exists('inquiry_success', $row) || !array_key_exists('payment_success', $row) || !array_key_exists('payment_amount', $row)) throw new RuntimeException('Baris ringkasan laporan tidak valid.');
        }
        foreach (['inquiry_success', 'payment_success', 'payment_amount'] as $key) if (!array_key_exists($key, $value['totals'])) throw new RuntimeException('Total ringkasan laporan tidak lengkap.');
        foreach (['top_inquiry', 'top_payment', 'top_payment_amount', 'payment_to_inquiry_percentage', 'payment_comparison'] as $key) if (!array_key_exists($key, $value['metrics'])) throw new RuntimeException('Metrik ringkasan laporan tidak lengkap.');
        if (!isset($value['performance']['rows'], $value['performance']['totals']) || !is_array($value['performance']['rows']) || !is_array($value['performance']['totals'])) throw new RuntimeException('Data performance laporan tidak valid.');
        if (!isset($value['payment_channel_performance']['rows'], $value['payment_channel_performance']['totals']) || !is_array($value['payment_channel_performance']['rows']) || !is_array($value['payment_channel_performance']['totals'])) throw new RuntimeException('Data performance payment channel tidak valid.');
        if ($value['top_payment_channels'] === [] || count($value['top_payment_channels']) > 5) throw new RuntimeException('Data top payment channel laporan tidak valid.');
        if (!isset($value['daily_trend']['rows'], $value['daily_trend']['metrics']) || !is_array($value['daily_trend']['rows']) || !is_array($value['daily_trend']['metrics']) || $value['daily_trend']['rows'] === []) throw new RuntimeException('Data tren harian laporan tidak valid.');
        if (!isset($value['ticket_summary']['segments'], $value['ticket_summary']['statuses'], $value['ticket_summary']['total']) || !is_array($value['ticket_summary']['segments']) || !is_array($value['ticket_summary']['statuses'])) throw new RuntimeException('Data ringkasan tiket laporan tidak valid.');
        return $value;
    }
}

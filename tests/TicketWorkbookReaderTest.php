<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/Import/TicketWorkbookReader.php';
require_once __DIR__ . '/fixtures/TicketWorkbookFactory.php';

use App\Import\TicketWorkbookReader;

/** Menghentikan test dengan pesan yang jelas ketika kondisi tidak terpenuhi. */
function assert_ticket_reader(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** Membuat satu baris template tiket lengkap dengan data pribadi dummy yang harus diabaikan parser. */
function ticket_row(array $overrides = []): array
{
    $row = ['1', '000123', ' Close ', 'Rahasia Corp', 'Nama Rahasia', '08123456789', ' Payment   Gateway ', 'Hosted', 'Permohonan Refund', 'Permintaan', 'Jan', '2026-01-14 12:11:51', '2026-01-15 16:27:50', '2026-01-15 16:27:50', '1.5', '1, 12:00', '1-2 hari', 'Agent Rahasia', '0.1', '255', '5:33', 'Email', 'Not Incident', 'Subject sensitif', 'Pembuat Rahasia', ''];
    foreach ($overrides as $index => $value) $row[$index] = $value;
    return $row;
}

$reader = new TicketWorkbookReader();
$paths = [];
try {
    $valid = TicketWorkbookFactory::create([ticket_row(), array_fill(0, 26, '')]);
    $paths[] = $valid;
    $inspection = $reader->inspectTickets($valid);
    assert_ticket_reader($inspection['total_rows'] === 1, 'Baris formatting kosong seharusnya diabaikan.');
    assert_ticket_reader($inspection['invalid_rows'] === [], 'Baris valid tidak boleh ditolak.');
    $ticket = $inspection['rows'][0];
    assert_ticket_reader($ticket['ticket_number'] === '000123', 'Leading zero Ticket No harus dipertahankan.');
    assert_ticket_reader($ticket['complaint_segment'] === 'Permohonan Refund', 'Segmentasi keluhan harus dipertahankan.');
    assert_ticket_reader($ticket['duration_raw'] === '5:33', 'Nilai Duration mentah harus dipertahankan persis dari workbook.');
    assert_ticket_reader($ticket['duration_minutes'] === 1695, 'Duration harus dihitung dari Last Update Time dikurangi Open Time.');
    assert_ticket_reader($ticket['response_time_minutes'] === 1695, 'Response Time harus berupa total menit Close Time dikurangi Open Time.');
    assert_ticket_reader(!array_key_exists('retail_phone', $ticket) && !array_key_exists('subject', $ticket), 'Data pribadi yang tidak diperlukan tidak boleh masuk hasil parser.');
    assert_ticket_reader(!array_key_exists('product', $ticket) && !array_key_exists('service', $ticket), 'Field yang tidak dipakai laporan tidak boleh masuk hasil parser.');

    $invalidDate = TicketWorkbookFactory::create([ticket_row([11 => '2026-02-30 10:00:00'])]);
    $paths[] = $invalidDate;
    $inspection = $reader->inspectTickets($invalidDate);
    assert_ticket_reader(count($inspection['invalid_rows']) === 1, 'Tanggal kalender tidak valid harus ditolak per baris.');

    $warningFile = TicketWorkbookFactory::create([ticket_row([2 => '', 8 => '', 12 => '', 13 => '', 14 => '', 18 => ''])]);
    $paths[] = $warningFile;
    $warningInspection = $reader->inspectTickets($warningFile);
    assert_ticket_reader($warningInspection['invalid_rows'] === [] && count($warningInspection['rows'][0]['validation_warnings']) >= 4, 'Masalah kualitas nonfatal harus menjadi warning, bukan baris invalid.');

    $mismatchFile = TicketWorkbookFactory::create([ticket_row([14 => '2', 18 => '2'])]);
    $paths[] = $mismatchFile;
    $mismatchWarnings = $reader->inspectTickets($mismatchFile)['rows'][0]['validation_warnings'];
    assert_ticket_reader(count(array_filter($mismatchWarnings, static fn (string $warning): bool => str_contains($warning, 'berbeda'))) === 2, 'Ketidaksesuaian Durasi dan respon time sumber harus menghasilkan warning.');

    $wrongHeader = TicketWorkbookFactory::create([], ['Ticket No']);
    $paths[] = $wrongHeader;
    try {
        $reader->inspectTickets($wrongHeader);
        throw new RuntimeException('Header tidak valid seharusnya ditolak.');
    } catch (RuntimeException $error) {
        assert_ticket_reader(str_contains($error->getMessage(), 'Header tiket aduan'), 'Pesan header tidak valid tidak sesuai.');
    }

    $samplePath = __DIR__ . '/../samples/Tiket Aduan Jan - Mei 2026.xlsx';
    if (is_file($samplePath)) {
        $sample = $reader->inspectTickets($samplePath);
        assert_ticket_reader($sample['total_rows'] === 30, 'Jumlah baris data workbook sampel tidak sesuai hasil inspeksi awal.');
        assert_ticket_reader(count($sample['rows']) === 30 && $sample['invalid_rows'] === [], 'Workbook sampel harus menghasilkan 30 tiket valid tanpa menampilkan data sensitifnya.');
        $openedDates = array_column($sample['rows'], 'opened_at');
        assert_ticket_reader(str_starts_with(min($openedDates), '2026-01-') && str_starts_with(max($openedDates), '2026-05-'), 'Periode workbook sampel harus berasal dari Open Time Januari sampai Mei 2026.');
    }
    echo "TicketWorkbookReaderTest: OK\n";
} finally {
    foreach ($paths as $path) @unlink($path);
}

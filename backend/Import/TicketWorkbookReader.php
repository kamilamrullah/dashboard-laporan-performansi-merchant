<?php
declare(strict_types=1);

namespace App\Import;

use DateTimeImmutable;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class TicketWorkbookReader
{
    private const REQUIRED_HEADERS = [
        'NO', 'TICKET NO', 'STATUS', 'CORPORATE CUSTOMER', 'RETAIL NAME', 'RETAIL PHONE',
        'PRODUK', 'LAYANAN', 'SEGMENTASI KELUHAN', 'KATEGORI', 'MONTH', 'OPEN TIME',
        'CLOSE TIME', 'LAST UPDATE TIME', 'DURASI', 'HARI, JAM', 'KATEGORI HARI, JAM',
        'LAST UPDATE BY', 'RESPON TIME', 'RESPON TIME MENIT', 'DURATION', 'TYPE DESC',
        'FLAG', 'SUBJECT', 'CREATEDBY', 'UNIT',
    ];
    private const MAX_ROWS = 100000;
    private const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

    /** Membaca workbook tiket untuk preview dan mengumpulkan error per baris tanpa menyimpan data sensitif yang tidak diperlukan. */
    public function inspectTickets(string $filePath): array
    {
        $zip = $this->openWorkbook($filePath);
        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheet = $this->readXmlEntry($zip, 'xl/worksheets/sheet1.xml');
            $result = [];
            $invalidRows = [];
            $totalRows = 0;
            $headers = [];
            $isHeaderRow = true;
            foreach ($sheet->sheetData->row ?? [] as $row) {
                $values = $this->readRow($row, $sharedStrings);
                if ($isHeaderRow) {
                    $headers = $this->validateHeaders($values);
                    $isHeaderRow = false;
                    continue;
                }
                if ($this->isEmptyRow($values)) continue;
                $totalRows++;
                if ($totalRows > self::MAX_ROWS) throw new RuntimeException('Workbook melebihi batas 100.000 baris tiket.');
                try {
                    $result[] = $this->normalizeTicket($values, $headers, (int) $row['r']);
                } catch (RuntimeException $error) {
                    $invalidRows[] = ['source_row_number' => (int) $row['r'], 'message' => $error->getMessage()];
                }
            }
            return ['rows' => $result, 'invalid_rows' => $invalidRows, 'total_rows' => $totalRows];
        } finally {
            $zip->close();
        }
    }

    /** Menghasilkan fingerprint stabil hanya dari data tiket yang memang disimpan aplikasi. */
    public function fingerprint(array $row): string
    {
        $businessData = $row;
        unset($businessData['source_row_number']);
        return hash('sha256', json_encode($businessData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Membuka XLSX sebagai ZIP dan membatasi ukuran dekompresi untuk mencegah zip bomb. */
    private function openWorkbook(string $filePath): ZipArchive
    {
        if (!is_file($filePath)) throw new RuntimeException('File XLSX tidak ditemukan.');
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) throw new RuntimeException('Workbook tidak dapat dibuka.');
        $uncompressedBytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            $uncompressedBytes += (int) ($entry['size'] ?? 0);
            if ($uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                $zip->close();
                throw new RuntimeException('Isi workbook setelah dekompresi melebihi batas 100 MB.');
            }
        }
        return $zip;
    }

    /** Membaca entry XML dengan akses entity jaringan dinonaktifkan. */
    private function readXmlEntry(ZipArchive $zip, string $entry): SimpleXMLElement
    {
        $contents = $zip->getFromName($entry);
        if ($contents === false) throw new RuntimeException("Bagian workbook tidak ditemukan: {$entry}");
        $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($xml === false) throw new RuntimeException("XML workbook tidak valid: {$entry}");
        return $xml;
    }

    /** Membaca shared string OpenXML agar nilai teks dapat diambil berdasarkan indeks cell. */
    private function readSharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) return [];
        $xml = $this->readXmlEntry($zip, 'xl/sharedStrings.xml');
        $strings = [];
        foreach ($xml->si as $item) {
            $parts = $item->xpath('.//*[local-name()="t"]') ?: [];
            $strings[] = implode('', array_map(static fn (SimpleXMLElement $part): string => (string) $part, $parts));
        }
        return $strings;
    }

    /** Mengubah satu row XML menjadi pasangan huruf kolom dan nilai mentah. */
    private function readRow(SimpleXMLElement $row, array $sharedStrings): array
    {
        $values = [];
        foreach ($row->c as $cell) {
            preg_match('/^[A-Z]+/', (string) $cell['r'], $match);
            $column = $match[0] ?? '';
            $type = (string) $cell['t'];
            $rawValue = (string) $cell->v;
            $values[$column] = $type === 's' ? ($sharedStrings[(int) $rawValue] ?? '') : ($type === 'inlineStr' ? (string) $cell->is->t : $rawValue);
        }
        return $values;
    }

    /** Memastikan urutan header identik dengan template tiket agar kolom tidak tertukar diam-diam. */
    private function validateHeaders(array $values): array
    {
        $headers = [];
        foreach ($values as $column => $value) $headers[$column] = strtoupper(trim((string) $value));
        if (array_values($headers) !== self::REQUIRED_HEADERS) throw new RuntimeException('Header tiket aduan tidak sesuai template yang diharapkan.');
        return $headers;
    }

    /** Menentukan apakah row hanya berisi cell kosong atau formatting tanpa data. */
    private function isEmptyRow(array $values): bool
    {
        return count(array_filter($values, static fn ($value): bool => trim((string) $value) !== '')) === 0;
    }

    /** Menormalisasi dan meminimalkan satu baris tiket menjadi field yang diperlukan laporan. */
    private function normalizeTicket(array $values, array $headers, int $sourceRow): array
    {
        $source = [];
        foreach ($headers as $column => $header) $source[$header] = trim((string) ($values[$column] ?? ''));
        $ticketNumber = $this->requiredText($source['TICKET NO'], 100, $sourceRow, 'Ticket No');
        $openedAt = $this->normalizeDateTime($source['OPEN TIME'], $sourceRow, 'Open Time', false);
        $closedAt = $this->normalizeDateTime($source['CLOSE TIME'], $sourceRow, 'Close Time', true);
        $lastUpdatedAt = $this->normalizeDateTime($source['LAST UPDATE TIME'], $sourceRow, 'Last Update Time', true);
        if ($closedAt !== null && $openedAt > $closedAt) throw new RuntimeException("Close Time lebih awal dari Open Time pada baris {$sourceRow}.");
        if ($lastUpdatedAt !== null && $openedAt > $lastUpdatedAt) throw new RuntimeException("Last Update Time lebih awal dari Open Time pada baris {$sourceRow}.");
        return [
            'source_row_number' => $sourceRow,
            'ticket_number' => $ticketNumber,
            'status' => $this->normalizedText($source['STATUS'], 64, $sourceRow, 'Status'),
            'complaint_segment' => $this->normalizedText($source['SEGMENTASI KELUHAN'], 160, $sourceRow, 'Segmentasi Keluhan'),
            'opened_at' => $openedAt,
            'closed_at' => $closedAt,
            'last_updated_at' => $lastUpdatedAt,
            'duration_raw' => $this->normalizedText($source['DURATION'], 64, $sourceRow, 'Duration'),
            'duration_minutes' => $this->elapsedMinutes($openedAt, $lastUpdatedAt),
            'response_time_minutes' => $this->elapsedMinutes($openedAt, $closedAt),
            'classification_flag' => $this->normalizedText($source['FLAG'], 64, $sourceRow, 'Flag'),
        ];
    }

    /** Memvalidasi teks wajib dan menerapkan normalisasi whitespace. */
    private function requiredText(string $value, int $maximumLength, int $sourceRow, string $field): string
    {
        $normalized = $this->normalizedText($value, $maximumLength, $sourceRow, $field);
        if ($normalized === '') throw new RuntimeException("{$field} wajib diisi pada baris {$sourceRow}.");
        return $normalized;
    }

    /** Menormalkan whitespace teks dan menolak nilai yang melebihi kapasitas database. */
    private function normalizedText(string $value, int $maximumLength, int $sourceRow, string $field): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        if (mb_strlen($normalized) > $maximumLength) throw new RuntimeException("{$field} melebihi {$maximumLength} karakter pada baris {$sourceRow}.");
        return $normalized;
    }

    /** Menormalisasi datetime template, dengan dukungan serial Excel sebagai fallback. */
    private function normalizeDateTime(string $value, int $sourceRow, string $field, bool $optional): ?string
    {
        if ($value === '') {
            if ($optional) return null;
            throw new RuntimeException("{$field} wajib diisi pada baris {$sourceRow}.");
        }
        if (is_numeric($value)) {
            $serial = (float) $value;
            if (!is_finite($serial) || $serial < 1) throw new RuntimeException("{$field} tidak valid pada baris {$sourceRow}.");
            return (new DateTimeImmutable('1899-12-30 00:00:00'))->modify('+' . (string) round($serial * 86400) . ' seconds')->format('Y-m-d H:i:s');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) throw new RuntimeException("{$field} tidak valid pada baris {$sourceRow}.");
        return $date->format('Y-m-d H:i:s');
    }

    /** Menghitung total menit kalender dari waktu awal ke waktu akhir dan membuang sisa detik seperti representasi menit sumber. */
    private function elapsedMinutes(string $start, ?string $end): ?int
    {
        if ($end === null) return null;
        $seconds = strtotime($end) - strtotime($start);
        if ($seconds < 0) throw new RuntimeException('Rentang waktu tiket tidak valid.');
        return intdiv($seconds, 60);
    }
}

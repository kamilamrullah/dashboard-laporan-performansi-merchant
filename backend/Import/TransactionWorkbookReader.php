<?php
declare(strict_types=1);

namespace App\Import;

use DateTimeImmutable;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class TransactionWorkbookReader
{
    private const REQUIRED_HEADERS = ['TGL', 'DATASOURCE', 'TYPE', 'CA_ID', 'CHANNEL_NAME', 'BILLER', 'SIC_CODE', 'RC', 'TOTAL_TRX', 'TOTAL_AMOUNT'];
    private const MAX_ROWS = 250000;
    private const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

    /** Membaca dan menormalisasi seluruh baris transaksi dari workbook XLSX tanpa mengubah file. */
    public function readTransactions(string $filePath): array
    {
        $inspection = $this->inspectTransactions($filePath);
        if ($inspection['invalid_rows'] !== []) {
            $first = $inspection['invalid_rows'][0];
            throw new RuntimeException("Baris {$first['source_row_number']} tidak valid: {$first['message']}");
        }
        return $inspection['rows'];
    }

    /** Membaca workbook untuk preview dan mengumpulkan error per baris tanpa menghentikan seluruh file. */
    public function inspectTransactions(string $filePath): array
    {
        $zip = $this->openWorkbook($filePath);
        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheet = $this->readXmlEntry($zip, 'xl/worksheets/sheet1.xml');
            $rows = $sheet->sheetData->row ?? [];
            $result = [];
            $invalidRows = [];
            $totalRows = 0;
            $headers = [];
            $isHeaderRow = true;

            foreach ($rows as $row) {
                $values = $this->readRow($row, $sharedStrings);
                if ($isHeaderRow) {
                    $headers = $this->validateHeaders($values);
                    $isHeaderRow = false;
                    continue;
                }
                if ($this->isEmptyRow($values)) {
                    continue;
                }
                $totalRows++;
                if ($totalRows > self::MAX_ROWS) {
                    throw new RuntimeException('Workbook melebihi batas 250.000 baris transaksi.');
                }
                try {
                    $result[] = $this->normalizeTransaction($values, $headers, (int) $row['r']);
                } catch (RuntimeException $error) {
                    $invalidRows[] = ['source_row_number' => (int) $row['r'], 'message' => $error->getMessage()];
                }
            }
            return ['rows' => $result, 'invalid_rows' => $invalidRows, 'total_rows' => $totalRows];
        } finally {
            $zip->close();
        }
    }

    /** Menghasilkan fingerprint stabil dari nilai bisnis dan tidak memasukkan nomor baris sumber. */
    public function fingerprint(array $row): string
    {
        $businessData = $row;
        unset($businessData['source_row_number']);
        return hash('sha256', json_encode($businessData, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /** Menghasilkan kunci natural stabil untuk mendeteksi benturan dalam satu workbook. */
    public function naturalKey(array $row): string
    {
        $fields = ['transaction_date', 'datasource', 'transaction_type', 'ca_id', 'partner_channel', 'biller', 'sic_code', 'response_code'];
        return hash('sha256', json_encode(array_intersect_key($row, array_flip($fields)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Membaca mapping kode payment channel dari sheet kode biller pada workbook referensi. */
    public function readPaymentChannels(string $filePath): array
    {
        $zip = $this->openWorkbook($filePath);
        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetPath = $this->findSheetPath($zip, 'kode biller');
            $sheet = $this->readXmlEntry($zip, $sheetPath);
            $mapping = [];
            foreach ($sheet->sheetData->row ?? [] as $row) {
                $values = $this->readRow($row, $sharedStrings);
                $populatedValues = array_values(array_filter($values, static fn ($value): bool => trim((string) $value) !== ''));
                $code = trim((string) ($populatedValues[0] ?? ''));
                $name = trim((string) ($populatedValues[1] ?? ''));
                if ($code !== '' && $name !== '') {
                    $mapping[$code] = $name;
                }
            }
            return $mapping;
        } finally {
            $zip->close();
        }
    }

    /** Membuka file sebagai workbook ZIP; ekstensi nama asli divalidasi pada batas upload atau CLI. */
    private function openWorkbook(string $filePath): ZipArchive
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('File XLSX tidak ditemukan.');
        }
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Workbook tidak dapat dibuka.');
        }
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

    /** Membaca satu entry XML workbook dengan entity loader jaringan dinonaktifkan. */
    private function readXmlEntry(ZipArchive $zip, string $entry): SimpleXMLElement
    {
        $contents = $zip->getFromName($entry);
        if ($contents === false) {
            throw new RuntimeException("Bagian workbook tidak ditemukan: {$entry}");
        }
        $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($xml === false) {
            throw new RuntimeException("XML workbook tidak valid: {$entry}");
        }
        return $xml;
    }

    /** Menghasilkan daftar shared string berdasarkan indeks OpenXML. */
    private function readSharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $xml = $this->readXmlEntry($zip, 'xl/sharedStrings.xml');
        $strings = [];
        foreach ($xml->si as $item) {
            $parts = $item->xpath('.//*[local-name()="t"]') ?: [];
            $strings[] = implode('', array_map(static fn (SimpleXMLElement $part): string => (string) $part, $parts));
        }
        return $strings;
    }

    /** Mengubah cell pada satu baris menjadi pasangan huruf kolom dan nilai. */
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

    /** Memvalidasi urutan header wajib dan membuat mapping huruf kolom ke nama domain. */
    private function validateHeaders(array $values): array
    {
        $headers = [];
        foreach ($values as $column => $value) {
            $headers[$column] = strtoupper(trim((string) $value));
        }
        $actual = array_values($headers);
        if ($actual !== self::REQUIRED_HEADERS) {
            throw new RuntimeException('Header transaksi tidak sesuai template yang diharapkan.');
        }
        return $headers;
    }

    /** Menentukan apakah baris workbook tidak memiliki nilai sama sekali. */
    private function isEmptyRow(array $values): bool
    {
        return count(array_filter($values, static fn ($value): bool => trim((string) $value) !== '')) === 0;
    }

    /** Menormalisasi tanggal, angka, whitespace, dan identifier pada satu baris transaksi. */
    private function normalizeTransaction(array $values, array $headers, int $sourceRow): array
    {
        $row = [];
        foreach ($headers as $column => $header) {
            $row[$header] = trim((string) ($values[$column] ?? ''));
        }
        $date = DateTimeImmutable::createFromFormat('!Ymd', $row['TGL']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            throw new RuntimeException("Tanggal tidak valid pada baris {$sourceRow}.");
        }
        $totalTrx = $this->normalizeWholeNumber($row['TOTAL_TRX'], $sourceRow, 'TOTAL_TRX');
        $totalAmount = $this->normalizeDecimal($row['TOTAL_AMOUNT'], $sourceRow, 'TOTAL_AMOUNT');
        return [
            'source_row_number' => $sourceRow,
            'transaction_date' => $date->format('Y-m-d'),
            'datasource' => $row['DATASOURCE'],
            'transaction_type' => preg_replace('/\s+/', ' ', strtoupper($row['TYPE'])) ?? '',
            'ca_id' => $row['CA_ID'],
            'partner_channel' => preg_replace('/\s+/', ' ', $row['CHANNEL_NAME']) ?? '',
            'biller' => $row['BILLER'],
            'sic_code' => $row['SIC_CODE'],
            'response_code' => $row['RC'],
            'total_trx' => $totalTrx,
            'total_amount' => $totalAmount,
        ];
    }

    /** Menormalisasi angka bulat Excel seperti 5 atau 5.0 dan menolak nilai pecahan, negatif, atau non-finite. */
    private function normalizeWholeNumber(string $value, int $sourceRow, string $field): int
    {
        if (!is_numeric($value)) {
            throw new RuntimeException("{$field} tidak valid pada baris {$sourceRow}.");
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < 0 || floor($number) !== $number || $number > PHP_INT_MAX) {
            throw new RuntimeException("{$field} harus berupa angka bulat non-negatif pada baris {$sourceRow}.");
        }
        return (int) $number;
    }

    /** Menormalisasi nominal Excel termasuk notasi ilmiah secara presisi tanpa konversi floating-point. */
    private function normalizeDecimal(string $value, int $sourceRow, string $field): string
    {
        $value = trim($value);
        if (!preg_match('/^\+?(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $value, $matches)) {
            throw new RuntimeException("{$field} tidak valid pada baris {$sourceRow}.");
        }
        $integerDigits = $matches[1];
        $fractionDigits = $matches[2] ?? '';
        $exponent = isset($matches[3]) ? (int) $matches[3] : 0;
        if (abs($exponent) > 1000) {
            throw new RuntimeException("{$field} memiliki eksponen di luar batas pada baris {$sourceRow}.");
        }
        $digits = $integerDigits . $fractionDigits;
        $decimalPosition = strlen($integerDigits) + $exponent;
        $scaledPosition = $decimalPosition + 2;
        if ($scaledPosition <= 0) {
            $scaled = '0';
            $roundingDigit = $scaledPosition === 0 ? ($digits[0] ?? '0') : '0';
        } elseif ($scaledPosition >= strlen($digits)) {
            $scaled = $digits . str_repeat('0', $scaledPosition - strlen($digits));
            $roundingDigit = '0';
        } else {
            $scaled = substr($digits, 0, $scaledPosition);
            $roundingDigit = $digits[$scaledPosition] ?? '0';
        }
        $scaled = ltrim($scaled, '0');
        if ($scaled === '') $scaled = '0';
        if ($roundingDigit >= '5') $scaled = $this->incrementDigits($scaled);
        $scaled = str_pad($scaled, 3, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($scaled, 0, -2), '0');
        if ($whole === '') $whole = '0';
        if (strlen($whole) > 18) {
            throw new RuntimeException("{$field} melebihi kapasitas DECIMAL(20,2) pada baris {$sourceRow}.");
        }
        return $whole . '.' . substr($scaled, -2);
    }

    /** Menambahkan satu pada string digit desimal tanpa bergantung pada batas integer PHP. */
    private function incrementDigits(string $digits): string
    {
        $result = $digits;
        $carry = 1;
        for ($index = strlen($result) - 1; $index >= 0 && $carry === 1; $index--) {
            $next = ((int) $result[$index]) + $carry;
            $result[$index] = (string) ($next % 10);
            $carry = intdiv($next, 10);
        }
        return $carry === 1 ? '1' . $result : $result;
    }

    /** Menemukan path worksheet berdasarkan nama sheet melalui relationship OpenXML. */
    private function findSheetPath(ZipArchive $zip, string $sheetName): string
    {
        $workbook = $this->readXmlEntry($zip, 'xl/workbook.xml');
        $relationships = $this->readXmlEntry($zip, 'xl/_rels/workbook.xml.rels');
        $relationshipMap = [];
        foreach ($relationships->Relationship as $relationship) {
            $relationshipMap[(string) $relationship['Id']] = (string) $relationship['Target'];
        }
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        foreach ($workbook->sheets->sheet as $sheet) {
            if (strcasecmp(trim((string) $sheet['name']), $sheetName) === 0) {
                $attributes = $sheet->attributes('r', true);
                $target = $relationshipMap[(string) $attributes['id']] ?? null;
                if ($target !== null) {
                    return 'xl/' . ltrim(str_replace('../', '', $target), '/');
                }
            }
        }
        throw new RuntimeException("Sheet {$sheetName} tidak ditemukan.");
    }
}

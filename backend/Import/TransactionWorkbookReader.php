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

    /** Membaca dan menormalisasi seluruh baris transaksi dari workbook XLSX tanpa mengubah file. */
    public function readTransactions(string $filePath): array
    {
        $zip = $this->openWorkbook($filePath);
        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheet = $this->readXmlEntry($zip, 'xl/worksheets/sheet1.xml');
            $rows = $sheet->sheetData->row ?? [];
            $result = [];
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
                $result[] = $this->normalizeTransaction($values, $headers, (int) $row['r']);
            }
            return $result;
        } finally {
            $zip->close();
        }
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

    /** Membuka XLSX sebagai ZIP dan menolak file yang tidak dapat dibaca. */
    private function openWorkbook(string $filePath): ZipArchive
    {
        if (!is_file($filePath) || strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('File XLSX tidak ditemukan atau ekstensi tidak valid.');
        }
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Workbook tidak dapat dibuka.');
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
        if (!ctype_digit($row['TOTAL_TRX']) || !is_numeric($row['TOTAL_AMOUNT'])) {
            throw new RuntimeException("Nilai transaksi tidak valid pada baris {$sourceRow}.");
        }
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
            'total_trx' => (int) $row['TOTAL_TRX'],
            'total_amount' => number_format((float) $row['TOTAL_AMOUNT'], 2, '.', ''),
        ];
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

<?php
declare(strict_types=1);

final class TransactionWorkbookFactory
{
    private const HEADERS = ['TGL', 'DATASOURCE', 'TYPE', 'CA_ID', 'CHANNEL_NAME', 'BILLER', 'SIC_CODE', 'RC', 'TOTAL_TRX', 'TOTAL_AMOUNT'];

    /** Membuat workbook XLSX minimum dari array baris transaksi untuk kebutuhan integration test. */
    public static function create(array $rows, string $marker): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'merchant-import-test-');
        if ($temporary === false) throw new RuntimeException('File fixture sementara tidak dapat dibuat.');
        $path = $temporary . '.xlsx';
        @unlink($temporary);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Fixture XLSX tidak dapat dibuat.');
        }
        try {
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('xl/worksheets/sheet1.xml', self::worksheetXml($rows));
            $zip->addFromString('test-marker.txt', $marker);
        } finally {
            $zip->close();
        }
        return $path;
    }

    /** Menyusun XML worksheet dengan header wajib dan seluruh nilai sebagai inline string. */
    private static function worksheetXml(array $rows): string
    {
        $xmlRows = [self::rowXml(1, self::HEADERS)];
        foreach ($rows as $index => $row) {
            $xmlRows[] = self::rowXml($index + 2, array_values($row));
        }
        return '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . implode('', $xmlRows) . '</sheetData></worksheet>';
    }

    /** Menyusun satu elemen row XLSX dan mengubah indeks kolom menjadi huruf A sampai J. */
    private static function rowXml(int $number, array $values): string
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $column = chr(ord('A') + $index);
            $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $cells[] = "<c r=\"{$column}{$number}\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>";
        }
        return "<row r=\"{$number}\">" . implode('', $cells) . '</row>';
    }
}

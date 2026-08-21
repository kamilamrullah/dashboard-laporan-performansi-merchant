<?php
declare(strict_types=1);

final class TicketWorkbookFactory
{
    private const HEADERS = ['No', 'Ticket No', 'Status', 'Corporate Customer', 'Retail Name', 'Retail Phone', 'Produk', 'Layanan', 'Segmentasi Keluhan', 'Kategori', 'Month', 'Open Time', 'Close Time', 'Last Update Time', 'Durasi', 'Hari, Jam', 'Kategori Hari, Jam', 'Last Update By', 'respon time', 'respon time menit', 'Duration', 'Type Desc', 'Flag', 'Subject', 'Createdby', 'Unit'];

    /** Membuat workbook XLSX minimal dengan seluruh header template untuk pengujian parser tiket. */
    public static function create(array $rows, ?array $headers = null): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'ticket-reader-test-');
        if ($temporary === false) throw new RuntimeException('File fixture sementara tidak dapat dibuat.');
        $path = $temporary . '.xlsx';
        @unlink($temporary);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Fixture tiket tidak dapat dibuat.');
        try {
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('xl/worksheets/sheet1.xml', self::worksheetXml($headers ?? self::HEADERS, $rows));
        } finally {
            $zip->close();
        }
        return $path;
    }

    /** Menyusun XML worksheet dengan cell inline string agar fixture tidak memerlukan sharedStrings. */
    private static function worksheetXml(array $headers, array $rows): string
    {
        $xmlRows = [self::rowXml(1, $headers)];
        foreach ($rows as $index => $row) $xmlRows[] = self::rowXml($index + 2, $row);
        return '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . implode('', $xmlRows) . '</sheetData></worksheet>';
    }

    /** Menyusun row XML dan mendukung lebih dari 26 kolom melalui konversi indeks ke huruf Excel. */
    private static function rowXml(int $number, array $values): string
    {
        $cells = [];
        foreach (array_values($values) as $index => $value) {
            $column = self::columnName($index + 1);
            $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $cells[] = "<c r=\"{$column}{$number}\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>";
        }
        return "<row r=\"{$number}\">" . implode('', $cells) . '</row>';
    }

    /** Mengubah nomor kolom satu-based menjadi nama kolom Excel seperti A atau AA. */
    private static function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }
}

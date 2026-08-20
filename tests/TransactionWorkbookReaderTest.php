<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/Import/TransactionWorkbookReader.php';

use App\Import\TransactionWorkbookReader;

/** Menghentikan test dengan pesan yang mudah ditelusuri ketika kondisi tidak terpenuhi. */
function assert_test(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Membuat fixture XLSX minimum tanpa menyimpan data bisnis atau membutuhkan dependency tambahan. */
function create_transaction_fixture(string $path): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Fixture XLSX tidak dapat dibuat.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>
      <row r="1"><c r="A1" t="inlineStr"><is><t>TGL</t></is></c><c r="B1" t="inlineStr"><is><t>DATASOURCE</t></is></c><c r="C1" t="inlineStr"><is><t>TYPE</t></is></c><c r="D1" t="inlineStr"><is><t>CA_ID</t></is></c><c r="E1" t="inlineStr"><is><t>CHANNEL_NAME</t></is></c><c r="F1" t="inlineStr"><is><t>BILLER</t></is></c><c r="G1" t="inlineStr"><is><t>SIC_CODE</t></is></c><c r="H1" t="inlineStr"><is><t>RC</t></is></c><c r="I1" t="inlineStr"><is><t>TOTAL_TRX</t></is></c><c r="J1" t="inlineStr"><is><t>TOTAL_AMOUNT</t></is></c></row>
      <row r="2"><c r="A2" t="inlineStr"><is><t>20260801</t></is></c><c r="B2" t="inlineStr"><is><t>DB</t></is></c><c r="C2" t="inlineStr"><is><t> payment  </t></is></c><c r="D2" t="inlineStr"><is><t>0012</t></is></c><c r="E2" t="inlineStr"><is><t>Partner   A</t></is></c><c r="F2" t="inlineStr"><is><t>0007</t></is></c><c r="G2" t="inlineStr"><is><t>0042</t></is></c><c r="H2" t="inlineStr"><is><t>00</t></is></c><c r="I2"><v>10.0</v></c><c r="J2"><v>1.2505E3</v></c></row>
      <row r="3"><c r="A3" t="inlineStr"><is><t>20260801</t></is></c><c r="B3" t="inlineStr"><is><t>DB</t></is></c><c r="C3" t="inlineStr"><is><t> payment  </t></is></c><c r="D3" t="inlineStr"><is><t>0012</t></is></c><c r="E3" t="inlineStr"><is><t>Partner   A</t></is></c><c r="F3" t="inlineStr"><is><t>0007</t></is></c><c r="G3" t="inlineStr"><is><t>0042</t></is></c><c r="H3" t="inlineStr"><is><t>00</t></is></c><c r="I3"><v>10.0</v></c><c r="J3"><v>1.2505E3</v></c></row>
      <row r="4"><c r="A4" t="inlineStr"><is><t>20260231</t></is></c><c r="B4" t="inlineStr"><is><t>DB</t></is></c><c r="C4" t="inlineStr"><is><t>PAYMENT</t></is></c><c r="D4" t="inlineStr"><is><t>0012</t></is></c><c r="E4" t="inlineStr"><is><t>Partner A</t></is></c><c r="F4" t="inlineStr"><is><t>0007</t></is></c><c r="G4" t="inlineStr"><is><t>0042</t></is></c><c r="H4" t="inlineStr"><is><t>00</t></is></c><c r="I4"><v>10</v></c><c r="J4"><v>1250.5</v></c></row>
    </sheetData></worksheet>');
    $zip->close();
}

/** Menjalankan seluruh pemeriksaan reader dan membersihkan fixture meskipun test gagal. */
function run_reader_tests(): void
{
    $path = tempnam(sys_get_temp_dir(), 'transaction-reader-');
    if ($path === false) throw new RuntimeException('File temporary tidak dapat dibuat.');
    $xlsxPath = $path;
    try {
        create_transaction_fixture($xlsxPath);
        $reader = new TransactionWorkbookReader();
        $inspection = $reader->inspectTransactions($xlsxPath);
        assert_test($inspection['total_rows'] === 3, 'Jumlah baris sumber tidak sesuai.');
        assert_test(count($inspection['rows']) === 2, 'Jumlah baris valid tidak sesuai.');
        assert_test(count($inspection['invalid_rows']) === 1, 'Baris invalid harus dikumpulkan tanpa menghentikan preview.');
        assert_test($inspection['rows'][0]['transaction_type'] === 'PAYMENT', 'Whitespace TYPE tidak ternormalisasi.');
        assert_test($inspection['rows'][0]['partner_channel'] === 'Partner A', 'Whitespace channel tidak ternormalisasi.');
        assert_test($inspection['rows'][0]['ca_id'] === '0012' && $inspection['rows'][0]['sic_code'] === '0042', 'Leading zero identifier hilang.');
        assert_test($inspection['rows'][0]['total_amount'] === '1250.50', 'Nominal tidak ternormalisasi ke dua desimal.');
        assert_test($reader->fingerprint($inspection['rows'][0]) === $reader->fingerprint($inspection['rows'][1]), 'Baris identik harus memiliki fingerprint yang sama.');
        assert_test($reader->naturalKey($inspection['rows'][0]) === $reader->naturalKey($inspection['rows'][1]), 'Natural key identik tidak terdeteksi.');
    } finally {
        @unlink($xlsxPath);
    }
}

run_reader_tests();
echo "TransactionWorkbookReaderTest: OK\n";

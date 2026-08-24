<?php
declare(strict_types=1);

namespace App\Report;

use RuntimeException;
use ZipArchive;

/** Membentuk paket Open XML minimum yang dapat dibuka sebagai dokumen Microsoft Word. */
final class DocxPackage
{
    /** Menulis bagian-bagian XML dokumen ke file DOCX secara atomik. */
    public function write(string $outputPath, string $documentXml, string $stylesXml, array $metadata, array $media = []): void
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('Ekstensi PHP ZipArchive diperlukan untuk membuat laporan Word.');
        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Folder keluaran laporan tidak dapat dibuat.');

        $temporaryPath = tempnam($directory, '.docx-');
        if ($temporaryPath === false) throw new RuntimeException('File sementara laporan tidak dapat dibuat.');
        $archive = new ZipArchive();
        try {
            if ($archive->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Paket laporan Word tidak dapat dibuat.');
            $this->add($archive, '[Content_Types].xml', $this->contentTypes());
            $this->add($archive, '_rels/.rels', $this->packageRelationships());
            $this->add($archive, 'docProps/core.xml', $this->coreProperties($metadata));
            $this->add($archive, 'docProps/app.xml', $this->applicationProperties());
            $this->add($archive, 'word/document.xml', $documentXml);
            $this->add($archive, 'word/styles.xml', $stylesXml);
            $this->add($archive, 'word/numbering.xml', $this->numbering());
            $this->add($archive, 'word/settings.xml', $this->settings());
            $this->add($archive, 'word/_rels/document.xml.rels', $this->documentRelationships($media));
            foreach ($media as $name => $path) {
                if (!is_string($name) || !preg_match('/\A[a-zA-Z0-9._-]+\.(?:png|jpe?g)\z/i', $name) || !is_string($path) || !is_file($path) || !is_readable($path)) throw new RuntimeException('Aset gambar laporan tidak valid.');
                if (!$archive->addFile($path, 'word/media/' . $name)) throw new RuntimeException("Aset gambar {$name} gagal ditulis.");
            }
            if (!$archive->close()) throw new RuntimeException('Paket laporan Word gagal diselesaikan.');
            if (!rename($temporaryPath, $outputPath)) throw new RuntimeException('Laporan Word gagal dipindahkan ke lokasi keluaran.');
        } finally {
            if ($archive->status !== ZipArchive::ER_OK) $archive->close();
            if (is_file($temporaryPath)) unlink($temporaryPath);
        }
    }

    /** Menambahkan satu bagian XML dan memastikan proses penulisan berhasil. */
    private function add(ZipArchive $archive, string $name, string $contents): void
    {
        if (!$archive->addFromString($name, $contents)) throw new RuntimeException("Bagian DOCX {$name} gagal ditulis.");
    }

    /** Mendeklarasikan tipe konten seluruh bagian dalam paket DOCX. */
    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/>'
            . '<Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
            . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    /** Mendeklarasikan relasi tingkat paket menuju dokumen dan metadata. */
    private function packageRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    /** Mendeklarasikan relasi dokumen menuju kumpulan style Word. */
    private function documentRelationships(array $media): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . (isset($media['company-logo.png']) ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/company-logo.png"/>' : '')
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
            . (isset($media['summary-payment-comparison.png']) ? '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/summary-payment-comparison.png"/>' : '')
            . (isset($media['payment-status-composition.png']) ? '<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/payment-status-composition.png"/>' : '')
            . (isset($media['top-payment-channels.png']) ? '<Relationship Id="rId6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/top-payment-channels.png"/>' : '')
            . '<Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
            . (isset($media['daily-payment-trend.png']) ? '<Relationship Id="rId8" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/daily-payment-trend.png"/>' : '')
            . (isset($media['ticket-segments.png']) ? '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/ticket-segments.png"/>' : '')
            . (isset($media['certificates.png']) ? '<Relationship Id="rId10" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/certificates.png"/>' : '')
            . (isset($media['licenses.png']) ? '<Relationship Id="rId11" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/licenses.png"/>' : '')
            . (isset($media['contact-x.png']) ? '<Relationship Id="rId12" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/contact-x.png"/>' : '')
            . (isset($media['contact-instagram.jpeg']) ? '<Relationship Id="rId13" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/contact-instagram.jpeg"/>' : '')
            . (isset($media['contact-facebook.png']) ? '<Relationship Id="rId14" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/contact-facebook.png"/>' : '')
            . (isset($media['contact-website.png']) ? '<Relationship Id="rId15" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/contact-website.png"/>' : '')
            . '</Relationships>';
    }

    /** Mendefinisikan native bullet list Word yang digunakan oleh narasi laporan. */
    private function numbering(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:abstractNum w:abstractNumId="1"><w:multiLevelType w:val="hybridMultilevel"/>'
            . '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="•"/><w:lvlJc w:val="left"/>'
            . '<w:pPr><w:tabs><w:tab w:val="num" w:pos="720"/></w:tabs><w:ind w:left="720" w:hanging="360"/></w:pPr>'
            . '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:hint="default"/><w:color w:val="000000"/></w:rPr></w:lvl>'
            . '</w:abstractNum><w:num w:numId="1"><w:abstractNumId w:val="1"/></w:num>'
            . '</w:numbering>';
    }

    /** Meminta Microsoft Word memperbarui field dinamis seperti daftar isi saat dokumen dibuka. */
    private function settings(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:updateFields w:val="true"/></w:settings>';
    }

    /** Membentuk metadata inti dokumen dengan karakter XML yang sudah diamankan. */
    private function coreProperties(array $metadata): string
    {
        $title = $this->escape((string) ($metadata['title'] ?? 'Laporan Performansi Bulanan'));
        $subject = $this->escape((string) ($metadata['subject'] ?? 'Laporan Performansi Merchant'));
        $creator = $this->escape((string) ($metadata['creator'] ?? 'Dashboard Laporan Performansi Merchant'));
        $createdAt = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . "<dc:title>{$title}</dc:title><dc:subject>{$subject}</dc:subject><dc:creator>{$creator}</dc:creator>"
            . "<dcterms:created xsi:type=\"dcterms:W3CDTF\">{$createdAt}</dcterms:created>"
            . '</cp:coreProperties>';
    }

    /** Membentuk metadata aplikasi pembuat dokumen. */
    private function applicationProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Dashboard Laporan Performansi Merchant</Application><AppVersion>1.0</AppVersion>'
            . '</Properties>';
    }

    /** Mengamankan teks agar valid ketika ditempatkan dalam XML. */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

<?php
declare(strict_types=1);

namespace App\Report;

use RuntimeException;

/** Membuat grafik perbandingan payment sukses sebagai PNG untuk ditanam ke DOCX. */
final class SummaryChartRenderer
{
    /** Menggambar pie chart tiga dimensi komposisi tiket aduan berdasarkan segmentasi. */
    public function renderTicketSegments(array $segments, string $periodLabel, string $outputPath): void
    {
        if (!extension_loaded('gd')) throw new RuntimeException('Ekstensi GD diperlukan untuk membuat grafik laporan.');
        $image = imagecreatetruecolor(960, 620);
        if ($image === false) throw new RuntimeException('Kanvas grafik tiket aduan gagal dibuat.');
        try {
            $white = imagecolorallocate($image, 255, 255, 255);
            $text = imagecolorallocate($image, 56, 73, 98);
            $border = imagecolorallocate($image, 185, 185, 185);
            $palette = [[68, 114, 196], [255, 192, 0], [255, 0, 0], [112, 173, 71], [112, 48, 160]];
            $colors = $depthColors = [];
            foreach ($palette as [$red, $green, $blue]) {
                $colors[] = imagecolorallocate($image, $red, $green, $blue);
                $depthColors[] = imagecolorallocate($image, (int) ($red * 0.58), (int) ($green * 0.58), (int) ($blue * 0.58));
            }
            imagefill($image, 0, 0, $white);
            imagerectangle($image, 0, 0, 959, 619, $border);
            imageantialias($image, true);
            $regularFont = $this->fontPath('calibri.ttf');
            $boldFont = $this->fontPath('calibrib.ttf');
            $this->centeredText($image, 'TIKET ADUAN PERIODE ' . $periodLabel, 28, 480, 58, $text, $boldFont);
            if ($segments === []) $this->centeredText($image, 'Tidak terdapat tiket aduan pada periode laporan', 22, 480, 320, $text, $regularFont);
            $total = max(1, array_sum(array_column($segments, 'total')));
            $start = 270;
            $items = [];
            foreach (array_values($segments) as $index => $segment) {
                $end = $index === count($segments) - 1 ? 630 : $start + (int) round(((int) $segment['total'] / $total) * 360);
                $items[] = ['start' => $start, 'end' => $end, 'index' => $index % count($colors), 'segment' => $segment];
                $start = $end;
            }
            for ($depth = 35; $depth >= 1; $depth--) foreach ($items as $item) imagefilledarc($image, 480, 305 + $depth, 560, 270, $item['start'], $item['end'], $depthColors[$item['index']], IMG_ARC_PIE);
            foreach ($items as $item) imagefilledarc($image, 480, 305, 560, 270, $item['start'], $item['end'], $colors[$item['index']], IMG_ARC_PIE);
            foreach ($items as $item) {
                $middle = deg2rad(($item['start'] + $item['end']) / 2);
                $anchorX = (int) round(480 + cos($middle) * 210);
                $anchorY = (int) round(305 + sin($middle) * 90);
                $labelX = (int) round(480 + cos($middle) * 340);
                $labelY = (int) round(305 + sin($middle) * 175);
                $boxLeft = max(8, min(772, $labelX - 90));
                $boxTop = max(70, min(445, $labelY - 35));
                imageline($image, $anchorX, $anchorY, $boxLeft + 90, $boxTop + 35, $border);
                imagefilledrectangle($image, $boxLeft, $boxTop, $boxLeft + 180, $boxTop + 70, $white);
                imagerectangle($image, $boxLeft, $boxTop, $boxLeft + 180, $boxTop + 70, $border);
                $this->centeredText($image, (string) $item['segment']['complaint_segment'], 12, $boxLeft + 90, $boxTop + 28, $text, $regularFont);
                $this->centeredText($image, (string) $item['segment']['total'], 14, $boxLeft + 90, $boxTop + 56, $text, $boldFont);
            }
            $legendCount = count($segments);
            $slotWidth = (int) floor(900 / max(1, $legendCount));
            foreach (array_values($segments) as $index => $segment) {
                $center = 30 + (int) ($slotWidth / 2) + ($index * $slotWidth);
                imagefilledrectangle($image, $center - 85, 572, $center - 73, 584, $colors[$index % count($colors)]);
                $this->centeredText($image, (string) $segment['complaint_segment'], 12, $center + 15, 585, $text, $regularFont);
            }
            if (!imagepng($image, $outputPath, 6)) throw new RuntimeException('Grafik tiket aduan gagal disimpan.');
        } finally {
            imagedestroy($image);
        }
    }

    /** Menggambar grafik garis tren payment harian untuk sukses dan gabungan RC 68/82. */
    public function renderDailyPaymentTrend(array $rows, string $outputPath): void
    {
        if (!extension_loaded('gd')) throw new RuntimeException('Ekstensi GD diperlukan untuk membuat grafik laporan.');
        if ($rows === []) throw new RuntimeException('Data tren transaksi harian tidak tersedia.');
        $image = imagecreatetruecolor(1500, 560);
        if ($image === false) throw new RuntimeException('Kanvas grafik tren harian gagal dibuat.');
        try {
            $white = imagecolorallocate($image, 255, 255, 255);
            $text = imagecolorallocate($image, 89, 89, 89);
            $grid = imagecolorallocate($image, 215, 215, 215);
            $blue = imagecolorallocate($image, 68, 114, 196);
            $orange = imagecolorallocate($image, 237, 125, 49);
            imagefill($image, 0, 0, $white);
            imageantialias($image, true);
            $regularFont = $this->fontPath('calibri.ttf');
            $this->centeredText($image, 'TREND TRANSAKSI PAYMENT', 27, 750, 44, $text, $regularFont);
            $maximum = max(1, ...array_map(static fn (array $row): int => max((int) $row['success'], (int) $row['rc_68'] + (int) $row['rc_82']), $rows));
            $axisMaximum = max(5, (int) ceil($maximum / 5) * 5);
            for ($step = 0; $step <= 5; $step++) {
                $value = (int) round($axisMaximum * (5 - $step) / 5);
                $y = 70 + ($step * 70);
                imageline($image, 55, $y, 1460, $y, $grid);
                $this->rightAlignedText($image, (string) $value, 13, 48, $y + 5, $text, $regularFont);
            }
            $count = count($rows);
            $previousSuccess = null;
            $previousFailed = null;
            foreach (array_values($rows) as $index => $row) {
                $x = 65 + (int) round($index * (1380 / max(1, $count - 1)));
                $successY = 420 - (int) round(((int) $row['success'] / $axisMaximum) * 350);
                $failed = (int) $row['rc_68'] + (int) $row['rc_82'];
                $failedY = 420 - (int) round(($failed / $axisMaximum) * 350);
                if ($previousSuccess !== null) imageline($image, $previousSuccess[0], $previousSuccess[1], $x, $successY, $blue);
                if ($previousFailed !== null) imageline($image, $previousFailed[0], $previousFailed[1], $x, $failedY, $orange);
                imagefilledellipse($image, $x, $successY, 6, 6, $blue);
                imagefilledellipse($image, $x, $failedY, 5, 5, $orange);
                $previousSuccess = [$x, $successY];
                $previousFailed = [$x, $failedY];
                if ($regularFont !== null && function_exists('imagettftext')) imagettftext($image, 11, 45, $x - 8, 492, $text, $regularFont, (string) $row['date']);
            }
            imagefilledrectangle($image, 610, 530, 625, 540, $orange);
            $this->centeredText($image, 'gagal', 14, 655, 541, $text, $regularFont);
            imageline($image, 710, 535, 735, 535, $blue);
            $this->centeredText($image, 'Sukses', 14, 775, 541, $text, $regularFont);
            if (!imagepng($image, $outputPath, 6)) throw new RuntimeException('Grafik tren harian gagal disimpan.');
        } finally {
            imagedestroy($image);
        }
    }

    /** Menggambar pie chart tiga dimensi untuk maksimal lima payment channel sukses terbesar. */
    public function renderTopPaymentChannels(array $channels, string $outputPath): void
    {
        if (!extension_loaded('gd')) throw new RuntimeException('Ekstensi GD diperlukan untuk membuat grafik laporan.');
        if ($channels === []) throw new RuntimeException('Data top payment channel tidak tersedia.');
        $image = imagecreatetruecolor(900, 620);
        if ($image === false) throw new RuntimeException('Kanvas grafik top channel gagal dibuat.');
        try {
            $white = imagecolorallocate($image, 255, 255, 255);
            $text = imagecolorallocate($image, 89, 89, 89);
            $border = imagecolorallocate($image, 205, 205, 205);
            $palette = [[68, 114, 196], [237, 125, 49], [165, 165, 165], [255, 192, 0], [91, 155, 213]];
            $colors = [];
            $depthColors = [];
            foreach ($palette as [$red, $green, $blue]) {
                $colors[] = imagecolorallocate($image, $red, $green, $blue);
                $depthColors[] = imagecolorallocate($image, (int) ($red * 0.62), (int) ($green * 0.62), (int) ($blue * 0.62));
            }
            imagefill($image, 0, 0, $white);
            imagerectangle($image, 0, 0, 899, 619, $border);
            imageantialias($image, true);
            $regularFont = $this->fontPath('calibri.ttf');
            $this->centeredText($image, 'TOP CHANNEL PEMBAYARAN', 28, 450, 68, $text, $regularFont);
            $total = max(1, array_sum(array_map(static fn (array $row): int => (int) $row['total'], $channels)));
            $segments = [];
            $start = 0;
            foreach (array_values($channels) as $index => $channel) {
                $end = $index === count($channels) - 1 ? 360 : $start + (int) round(((int) $channel['total'] / $total) * 360);
                $segments[] = ['start' => $start, 'end' => $end, 'index' => $index, 'channel' => $channel];
                $start = $end;
            }
            for ($depth = 38; $depth >= 1; $depth--) {
                foreach ($segments as $segment) imagefilledarc($image, 450, 315 + $depth, 570, 275, $segment['start'], $segment['end'], $depthColors[$segment['index']], IMG_ARC_PIE);
            }
            foreach ($segments as $segment) {
                imagefilledarc($image, 450, 315, 570, 275, $segment['start'], $segment['end'], $colors[$segment['index']], IMG_ARC_PIE);
                $middle = deg2rad(($segment['start'] + $segment['end']) / 2);
                $labelX = count($segments) === 1 ? 450 : (int) round(450 + cos($middle) * 155);
                $labelY = count($segments) === 1 ? 330 : (int) round(315 + sin($middle) * 70);
                $valueColor = $segment['index'] === 3 ? $text : $white;
                $this->centeredText($image, number_format((int) $segment['channel']['total'], 0, ',', '.'), 18, $labelX, $labelY, $valueColor, $regularFont);
            }
            $legendWidth = min(760, count($channels) * 210);
            $legendStart = (int) round((900 - $legendWidth) / 2);
            foreach (array_values($channels) as $index => $channel) {
                $x = $legendStart + ($index * (int) floor($legendWidth / count($channels)));
                imagefilledrectangle($image, $x, 550, $x + 12, 562, $colors[$index]);
                $this->centeredText($image, (string) $channel['payment_channel'], 14, $x + 100, 562, $text, $regularFont);
            }
            if (!imagepng($image, $outputPath, 6)) throw new RuntimeException('Grafik top channel gagal disimpan.');
        } finally {
            imagedestroy($image);
        }
    }

    /** Menggambar pie chart rasio sukses dan gagal payment mengikuti tampilan grafik pada template lama. */
    public function renderPaymentStatusComposition(array $totals, string $outputPath): void
    {
        if (!extension_loaded('gd')) throw new RuntimeException('Ekstensi GD diperlukan untuk membuat grafik laporan.');
        $image = imagecreatetruecolor(900, 700);
        if ($image === false) throw new RuntimeException('Kanvas grafik performance gagal dibuat.');
        try {
            $white = imagecolorallocate($image, 255, 255, 255);
            $text = imagecolorallocate($image, 89, 89, 89);
            $border = imagecolorallocate($image, 190, 190, 190);
            $successColor = imagecolorallocate($image, 206, 0, 0);
            $successDepth = imagecolorallocate($image, 128, 0, 0);
            $failedColor = imagecolorallocate($image, 145, 145, 145);
            $failedDepth = imagecolorallocate($image, 88, 88, 88);
            imagefill($image, 0, 0, $white);
            imagerectangle($image, 0, 0, 899, 699, $border);
            imageantialias($image, true);
            $regularFont = $this->fontPath('calibri.ttf');
            $this->centeredText($image, 'RATION SUKSES & GAGAL PAYMENT', 28, 450, 70, $text, $regularFont);
            $success = (int) ($totals['success'] ?? 0);
            $failed = (int) ($totals['rc_68'] ?? 0) + (int) ($totals['rc_82'] ?? 0);
            $total = max(1, $success + $failed);
            $successEnd = (int) round(($success / $total) * 360);
            for ($depth = 34; $depth >= 1; $depth--) {
                if ($successEnd > 0) imagefilledarc($image, 430, 390 + $depth, 420, 210, 0, $successEnd, $successDepth, IMG_ARC_PIE);
                if ($successEnd < 360) imagefilledarc($image, 430, 390 + $depth, 420, 210, $successEnd, 360, $failedDepth, IMG_ARC_PIE);
            }
            if ($successEnd > 0) imagefilledarc($image, 430, 390, 420, 210, 0, $successEnd, $successColor, IMG_ARC_PIE);
            if ($successEnd < 360) imagefilledarc($image, 430, 390, 420, 210, $successEnd, 360, $failedColor, IMG_ARC_PIE);
            imagefilledrectangle($image, 120, 165, 390, 285, $white);
            imagerectangle($image, 120, 165, 390, 285, $border);
            imageline($image, 390, 225, 470, 180, $border);
            $this->centeredText($image, 'JUMLAH, TRANSAKSI', 18, 255, 215, $text, $regularFont);
            $this->centeredText($image, 'GAGAL, ' . ($failed > 0 ? number_format($failed, 0, ',', '.') : '-'), 18, 255, 252, $text, $regularFont);
            imagefilledrectangle($image, 570, 420, 835, 565, $white);
            imagerectangle($image, 570, 420, 835, 565, $border);
            imageline($image, 570, 470, 535, 450, $border);
            $this->centeredText($image, 'JUMLAH,', 18, 702, 462, $text, $regularFont);
            $this->centeredText($image, 'TRANSAKSI SUKSES,', 18, 702, 502, $text, $regularFont);
            $this->centeredText($image, number_format($success, 0, ',', '.'), 18, 702, 542, $text, $regularFont);
            if (!imagepng($image, $outputPath, 6)) throw new RuntimeException('Grafik performance gagal disimpan.');
        } finally {
            imagedestroy($image);
        }
    }

    /** Menggambar dua batang perbandingan dengan label dan nilai transaksi. */
    public function renderPaymentComparison(array $comparison, string $outputPath): void
    {
        if (!extension_loaded('gd')) throw new RuntimeException('Ekstensi GD diperlukan untuk membuat grafik laporan.');
        $image = imagecreatetruecolor(1200, 560);
        if ($image === false) throw new RuntimeException('Kanvas grafik laporan gagal dibuat.');
        try {
            $white = imagecolorallocate($image, 255, 255, 255);
            $text = imagecolorallocate($image, 45, 55, 72);
            $muted = imagecolorallocate($image, 105, 115, 130);
            $grid = imagecolorallocate($image, 226, 230, 236);
            $previousColor = imagecolorallocate($image, 133, 141, 153);
            $currentColor = imagecolorallocate($image, 238, 49, 36);
            $changeColor = imagecolorallocate($image, $comparison['direction'] === 'increase' ? 31 : 190, $comparison['direction'] === 'increase' ? 145 : 55, $comparison['direction'] === 'increase' ? 84 : 55);
            imagefill($image, 0, 0, $white);
            imageantialias($image, true);
            $regularFont = $this->fontPath('calibri.ttf');
            $boldFont = $this->fontPath('calibrib.ttf');
            $this->centeredText($image, 'PERBANDINGAN PAYMENT SUKSES', 28, 600, 50, $text, $boldFont);
            $this->centeredText($image, 'Jumlah transaksi sukses per bulan', 15, 600, 76, $muted, $regularFont);
            $maximum = max(1, (int) $comparison['previous_total'], (int) $comparison['current_total']);
            $axisMaximum = (int) (ceil($maximum / 100) * 100);
            $this->grid($image, $axisMaximum, $grid, $muted, $regularFont);
            $this->bar($image, 340, (int) $comparison['previous_total'], $axisMaximum, (string) $comparison['previous_label'], $previousColor, $text, $regularFont, $boldFont);
            $this->bar($image, 720, (int) $comparison['current_total'], $axisMaximum, (string) $comparison['current_label'], $currentColor, $text, $regularFont, $boldFont);
            $change = (int) $comparison['difference'];
            $changeText = ($change > 0 ? '+' : '') . number_format($change, 0, ',', '.') . ' transaksi';
            if ($comparison['percentage_change'] !== null) $changeText .= '  (' . ($change > 0 ? '+' : '') . number_format((float) $comparison['percentage_change'], 2, ',', '.') . '%)';
            $this->centeredText($image, $changeText, 14, 600, 530, $changeColor, $boldFont);
            if (!imagepng($image, $outputPath, 6)) throw new RuntimeException('Grafik laporan gagal disimpan.');
        } finally {
            imagedestroy($image);
        }
    }

    /** Menggambar satu batang, nilai, dan label periode pada posisi horizontal tertentu. */
    private function bar(\GdImage $image, int $x, int $value, int $maximum, string $label, int $color, int $textColor, ?string $regularFont, ?string $boldFont): void
    {
        $height = (int) round(($value / $maximum) * 320);
        $top = 440 - $height;
        imagefilledrectangle($image, $x, $top, $x + 180, 439, $color);
        $this->centeredText($image, number_format($value, 0, ',', '.'), 22, $x + 90, max(116, $top - 16), $textColor, $boldFont);
        $this->centeredText($image, $label, 17, $x + 90, 484, $textColor, $regularFont);
    }

    /** Menggambar garis bantu dan label skala transaksi pada sumbu vertikal. */
    private function grid(\GdImage $image, int $maximum, int $gridColor, int $textColor, ?string $font): void
    {
        for ($step = 0; $step <= 4; $step++) {
            $value = (int) round($maximum * (4 - $step) / 4);
            $y = 120 + ($step * 80);
            imageline($image, 150, $y, 1080, $y, $gridColor);
            $this->rightAlignedText($image, number_format($value, 0, ',', '.'), 14, 135, $y + 6, $textColor, $font);
        }
    }

    /** Menggambar teks rata tengah dengan TrueType dan fallback font bawaan GD. */
    private function centeredText(\GdImage $image, string $text, int $size, int $centerX, int $baselineY, int $color, ?string $font): void
    {
        if ($font !== null && function_exists('imagettftext')) {
            $box = imagettfbbox($size, 0, $font, $text);
            $width = $box === false ? 0 : $box[2] - $box[0];
            imagettftext($image, $size, 0, (int) round($centerX - ($width / 2)), $baselineY, $color, $font, $text);
            return;
        }
        imagestring($image, 5, $centerX - (int) round(strlen($text) * imagefontwidth(5) / 2), $baselineY - imagefontheight(5), $text, $color);
    }

    /** Menggambar teks rata kanan untuk label sumbu grafik. */
    private function rightAlignedText(\GdImage $image, string $text, int $size, int $rightX, int $baselineY, int $color, ?string $font): void
    {
        if ($font !== null && function_exists('imagettftext')) {
            $box = imagettfbbox($size, 0, $font, $text);
            $width = $box === false ? 0 : $box[2] - $box[0];
            imagettftext($image, $size, 0, $rightX - $width, $baselineY, $color, $font, $text);
            return;
        }
        imagestring($image, 3, $rightX - strlen($text) * imagefontwidth(3), $baselineY - imagefontheight(3), $text, $color);
    }

    /** Mencari font Windows yang diminta dan mengizinkan fallback bila tidak tersedia. */
    private function fontPath(string $filename): ?string
    {
        $windowsDirectory = getenv('WINDIR');
        if ($windowsDirectory === false || $windowsDirectory === '') return null;
        $path = rtrim($windowsDirectory, '\\/') . DIRECTORY_SEPARATOR . 'Fonts' . DIRECTORY_SEPARATOR . $filename;
        return is_file($path) && is_readable($path) ? $path : null;
    }
}

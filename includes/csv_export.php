<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

/**
 * Stream a CSV file download with UTF-8 BOM so Excel opens Persian text correctly.
 *
 * @param string                  $filename Output filename (e.g. 'tuition-export-1405-05.csv').
 * @param array<int, string>      $headers  Column header titles.
 * @param iterable<array<int, mixed>> $rows Data rows.
 */
function streamCsvDownload(string $filename, array $headers, iterable $rows): never
{
    if (headers_sent()) {
        die('تولید فایل خروجی به‌دلیل ارسال پیش‌فرض هدرها متوقف شد.');
    }

    // Clean output buffer to prevent any previous HTML/whitespace output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $safeFilename = rawurlencode($filename);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"; filename*=UTF-8\'\'' . $safeFilename);
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'wb');

    if ($output === false) {
        die('امکان باز کردن خروجی استاندارد وجود ندارد.');
    }

    // Write UTF-8 BOM for Microsoft Excel compatibility with Persian characters
    fwrite($output, "\xEF\xBB\xBF");

    // Write headers
    fputcsv($output, $headers);

    // Write rows streamingly
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

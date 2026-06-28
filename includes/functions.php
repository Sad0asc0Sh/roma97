<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * Build a fully-qualified URL for a static asset (CSS, JS, image, font).
 *
 * Currently equivalent to url(), but kept as a separate helper so that
 * asset-specific logic (e.g. cache-busting, CDN switching) can be added
 * later without touching every call site.
 */
function asset(string $path = ''): string
{
    return url($path);
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

function isPostRequest(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function getSetting(string $key, string $default = ''): string
{
    static $settings = null;

    if ($settings === null) {
        $settings = [];

        try {
            require_once __DIR__ . '/db.php';

            $pdo = getDb();
            $statement = $pdo->prepare('SELECT meta_key, meta_value FROM settings');
            $statement->execute();

            while ($row = $statement->fetch()) {
                $settings[(string) $row['meta_key']] = (string) $row['meta_value'];
            }
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $settings = [];
        }
    }

    return $settings[$key] ?? $default;
}

function siteName(): string
{
    return getSetting('site_name', 'Rooma');
}

function siteLogo(): string
{
    return getSetting('logo');
}

function siteDescription(): string
{
    return getSetting('site_description', 'Welcome to Rooma Daycare');
}

function siteContactPhone(): string
{
    return getSetting('contact_phone', '+98 21 1234 5678');
}

function setFlash(string $key, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION['flash'][$key] = $message;
}

function getFlash(string $key): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = (string) $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return $message;
}

/**
 * Send security headers
 */
function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Prevent clickjacking
    header('X-Frame-Options: DENY');
    
    // XSS Protection (legacy, but still sent)
    header('X-XSS-Protection: 0');
    
    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // HSTS (only if HTTPS is enforced)
    if (defined('FORCE_HTTPS') && FORCE_HTTPS) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    
    // Content Security Policy
    // Note: the Vazirmatn font is loaded from cdn.jsdelivr.net (see header
    // templates), so that origin must be allowed in style-src and font-src.
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline'; " .
           "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; " .
           "img-src 'self' data:; " .
           "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; " .
           "connect-src 'self'; " .
           "frame-ancestors 'none'; " .
           "base-uri 'self'; " .
           "form-action 'self';";
    
    header("Content-Security-Policy: $csp");
}

/**
 * Secure uploaded file permissions
 */
function secureUploadedFile(string $filePath): bool
{
    if (!file_exists($filePath)) {
        return false;
    }
    
    $permissions = defined('UPLOAD_PERMISSIONS') ? UPLOAD_PERMISSIONS : 0644;
    
    return chmod($filePath, $permissions);
}

/**
 * Check if request is over HTTPS
 */
function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/**
 * Enforce HTTPS if configured
 */
function enforceHttps(): void
{
    if (!defined('FORCE_HTTPS') || !FORCE_HTTPS) {
        return;
    }
    
    if (!isHttps()) {
        $redirectUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $redirectUrl, true, 301);
        exit;
    }
}

/**
 * Validate file upload security.
 *
 * Returns an empty array when the upload passes all checks.  Each element is a
 * human-readable error string when something is wrong.
 *
 * NOTE: This helper is deliberately conservative. It verifies extension, MIME
 * type (via finfo), image content (via getimagesize), and ensures the file was
 * produced by an HTTP POST upload.  Individual upload handlers may add further
 * constraints (e.g. max dimensions, PDF-only, etc.).
 */
function validateUploadSecurity(array $file, array $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif']): array
{
    $errors = [];

    // Check for upload errors (UPLOAD_ERR_NO_FILE is allowed — caller decides)
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK && $errorCode !== UPLOAD_ERR_NO_FILE) {
        $errors[] = 'File upload failed with error code: ' . $errorCode;
        return $errors;
    }

    // No file uploaded at all — return without errors so the caller can handle
    // the "no file" case (e.g. skip photo update).
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return $errors;
    }

    // Check file size (512 KB default, override with MAX_UPLOAD_SIZE constant)
    $maxSize = defined('MAX_UPLOAD_SIZE') ? (int) MAX_UPLOAD_SIZE : 512000;
    if (($file['size'] ?? 0) > $maxSize) {
        $errors[] = 'File size exceeds maximum allowed size.';
    }

    // Check file extension
    $fileName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        $errors[] = 'File type not allowed. Allowed types: ' . implode(', ', $allowedExtensions);
    }

    // Verify it's a genuine uploaded file (prevents directory-traversal attacks)
    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $errors[] = 'Invalid file upload.';
        return $errors;
    }

    // Verify MIME type if finfo is available
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);

        $allowedMimes = [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
        ];

        if ($extension !== '' && isset($allowedMimes[$extension]) && !in_array($mimeType, $allowedMimes[$extension], true)) {
            $errors[] = 'File MIME type does not match extension.';
        }
    }

    // Cross-validate with getimagesize for image files
    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
        if (getimagesize($tmpName) === false) {
            $errors[] = 'File is not a valid image.';
        }
    }

    return $errors;
}

/**
 * Short Persian day names.
 */
function persianDayNameShort(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $shortDays = [
        'Saturday' => 'شنبه',
        'Sunday' => 'یکشنبه',
        'Monday' => 'دوشنبه',
        'Tuesday' => 'سه‌شنبه',
        'Wednesday' => 'چهارشنبه',
        'Thursday' => 'پنج‌شنبه',
        'Friday' => 'جمعه',
    ];

    $dayEn = date('l', $timestamp);

    return $shortDays[$dayEn] ?? $dayEn;
}

/**
 * Convert English digits to Persian digits.
 */
function persianNumber(string|int|float $value): string
{
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    return str_replace($english, $persian, (string) $value);
}

/**
 * Persian day names.
 */
function persianDayName(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $days = [
        'Saturday' => 'شنبه',
        'Sunday' => 'یکشنبه',
        'Monday' => 'دوشنبه',
        'Tuesday' => 'سه‌شنبه',
        'Wednesday' => 'چهارشنبه',
        'Thursday' => 'پنج‌شنبه',
        'Friday' => 'جمعه',
    ];

    $dayEn = date('l', $timestamp);

    return $days[$dayEn] ?? $dayEn;
}

/**
 * Persian month names.
 */
function persianMonthName(int $month): string
{
    $months = [
        1 => 'ژانویه',
        2 => 'فوریه',
        3 => 'مارس',
        4 => 'آوریل',
        5 => 'می',
        6 => 'ژوئن',
        7 => 'ژوئیه',
        8 => 'اوت',
        9 => 'سپتامبر',
        10 => 'اکتبر',
        11 => 'نوامبر',
        12 => 'دسامبر',
    ];

    return $months[$month] ?? '';
}

/**
 * Format date with Persian month names and Persian digits.
 */
function formatPersianDate(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $day = (int) date('j', $timestamp);
    $month = (int) date('n', $timestamp);
    $year = (int) date('Y', $timestamp);

    return persianNumber($day . ' ' . persianMonthName($month) . ' ' . $year);
}

/**
 * Convert a Gregorian date to Jalali (Shamsi / Persian) date.
 */
function gregorianToJalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + (int) (($gy2 + 3) / 4) - (int) (($gy2 + 99) / 100)
        + (int) (($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * (int) ($days / 12053));
    $days %= 12053;
    $jy += 4 * (int) ($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += (int) (($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $jm = ($days < 186) ? (1 + (int) ($days / 31)) : (7 + (int) (($days - 186) / 30));
    $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));

    return [$jy, $jm, $jd];
}

/**
 * Format a date as Shamsi (Jalali) date with Persian digits.
 */
function shamsiDate(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    [$jy, $jm, $jd] = gregorianToJalali(
        (int) date('Y', $timestamp),
        (int) date('n', $timestamp),
        (int) date('j', $timestamp)
    );

    $months = [
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    ];

    return persianNumber($jd . ' ' . ($months[$jm] ?? '') . ' ' . $jy);
}

/**
 * Resolve and validate the current page number from the query string.
 */
function currentPageNumber(string $param = 'p'): int
{
    $value = filter_var($_GET[$param] ?? 1, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'default' => 1],
    ]);

    return is_int($value) && $value >= 1 ? $value : 1;
}

/**
 * Build pagination metadata for a result set.
 *
 * @return array{page:int,perPage:int,total:int,totalPages:int,offset:int,hasPrev:bool,hasNext:bool,from:int,to:int}
 */
function paginate(int $total, int $page = 1, int $perPage = 20): array
{
    $perPage = max(1, $perPage);
    $total = max(0, $total);
    $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    return [
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'totalPages' => $totalPages,
        'offset' => $offset,
        'hasPrev' => $page > 1,
        'hasNext' => $page < $totalPages,
        'from' => $total === 0 ? 0 : $offset + 1,
        'to' => min($offset + $perPage, $total),
    ];
}

/**
 * Render an accessible RTL pagination control.
 * Existing query-string parameters in $query are preserved across page links.
 *
 * @param array<string,mixed> $meta  Result of paginate()
 * @param array<string,scalar> $query Extra query params to retain (filters, etc.)
 */
function renderPagination(array $meta, string $baseUrl, array $query = [], string $param = 'p'): string
{
    $totalPages = (int) ($meta['totalPages'] ?? 1);
    if ($totalPages <= 1) {
        return '';
    }

    $page = (int) ($meta['page'] ?? 1);

    $linkFor = static function (int $target) use ($baseUrl, $query, $param): string {
        $params = $query;
        $params[$param] = $target;
        $queryString = http_build_query($params);

        return e($baseUrl . ($queryString !== '' ? '?' . $queryString : ''));
    };

    $window = 2;
    $start = max(1, $page - $window);
    $end = min($totalPages, $page + $window);

    $html = '<nav class="pagination" role="navigation" aria-label="صفحه‌بندی">';
    $html .= '<ul class="pagination-list">';

    if ($page > 1) {
        $html .= '<li><a class="pagination-link pagination-prev" rel="prev" href="' . $linkFor($page - 1) . '">قبلی</a></li>';
    } else {
        $html .= '<li><span class="pagination-link pagination-prev is-disabled" aria-disabled="true">قبلی</span></li>';
    }

    if ($start > 1) {
        $html .= '<li><a class="pagination-link" href="' . $linkFor(1) . '">' . e(persianNumber(1)) . '</a></li>';
        if ($start > 2) {
            $html .= '<li><span class="pagination-ellipsis" aria-hidden="true">…</span></li>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        if ($i === $page) {
            $html .= '<li><span class="pagination-link is-current" aria-current="page">' . e(persianNumber($i)) . '</span></li>';
        } else {
            $html .= '<li><a class="pagination-link" href="' . $linkFor($i) . '">' . e(persianNumber($i)) . '</a></li>';
        }
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<li><span class="pagination-ellipsis" aria-hidden="true">…</span></li>';
        }
        $html .= '<li><a class="pagination-link" href="' . $linkFor($totalPages) . '">' . e(persianNumber($totalPages)) . '</a></li>';
    }

    if ($page < $totalPages) {
        $html .= '<li><a class="pagination-link pagination-next" rel="next" href="' . $linkFor($page + 1) . '">بعدی</a></li>';
    } else {
        $html .= '<li><span class="pagination-link pagination-next is-disabled" aria-disabled="true">بعدی</span></li>';
    }

    $html .= '</ul></nav>';

    return $html;
}

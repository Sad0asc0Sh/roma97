<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

/**
 * Standardizes Iranian phone numbers to 11 digits format (e.g. 09123456789).
 */
function normalizeIranianPhone(string $phone): string
{
    // Convert Persian / Arabic digits to English
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    $phone = str_replace($persian, $english, $phone);
    $phone = str_replace($arabic, $english, $phone);
    $phone = preg_replace('/[^0-9]/', '', $phone) ?? '';

    // Convert +989... or 00989... or 989... to 09...
    if (str_starts_with($phone, '0098')) {
        $phone = '0' . substr($phone, 4);
    } elseif (str_starts_with($phone, '98') && strlen($phone) === 12) {
        $phone = '0' . substr($phone, 2);
    } elseif (str_starts_with($phone, '9') && strlen($phone) === 10) {
        $phone = '0' . $phone;
    }

    return $phone;
}

/**
 * Validates whether a phone number matches Iranian mobile format (09xxxxxxxxx).
 */
function isValidIranianMobile(string $phone): bool
{
    $normalized = normalizeIranianPhone($phone);
    return preg_match('/^09[0-9]{9}$/', $normalized) === 1;
}

/**
 * Retrieves SMS configuration settings from database.
 */
function getSmsSettings(?PDO $pdo = null): array
{
    $pdo = $pdo ?? getDb();

    $defaults = [
        'sms_enabled'                     => '0',
        'sms_api_key'                     => '',
        'sms_detailed_report_enabled'     => '0', // global daily-report mode 0=brief for all 1=detailed for all
        'sms_inline_budget_seconds'       => '8',     // Max seconds a web request may spend flushing the queue
        'sms_pattern_otp'                 => '',
        'sms_pattern_welcome'             => '',
        'sms_pattern_daily_report_brief'  => '', // Short daily report pattern (portal referral)
        'sms_pattern_daily_report'        => '', // Detailed daily report pattern
        'sms_pattern_attendance'          => '',
        'sms_pattern_tuition_reminder'    => '',
        'sms_pattern_payment_receipt'     => '', // Receipt sent when a tuition payment is recorded
        'sms_pattern_announcement'        => '',
        'sms_pattern_child_status'        => '', // Enrolment status changes (approved / graduated / withdrawn)
    ];

    try {
        $keys = array_keys($defaults);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT meta_key, meta_value FROM settings WHERE meta_key IN ($placeholders)");
        $stmt->execute($keys);

        while ($row = $stmt->fetch()) {
            if (array_key_exists($row['meta_key'], $defaults)) {
                $defaults[$row['meta_key']] = (string) $row['meta_value'];
            }
        }
    } catch (Throwable $e) {
        error_log('getSmsSettings error: ' . $e->getMessage());
    }

    return $defaults;
}

/**
 * Global daily-report mode: true = all parents get the detailed report SMS.
 * False (default) = all parents get the brief portal-referral SMS.
 */
function smsDetailedReportEnabled(?PDO $pdo = null): bool
{
    if ($pdo !== null) {
        return getSmsSettings($pdo)['sms_detailed_report_enabled'] === '1';
    }
    return getSetting('sms_detailed_report_enabled', '0') === '1';
}

/**
 * Sends a patterned/service SMS via MeliPayamak Shared Pattern API.
 *
 * @param string $to Recipient phone number (e.g. 09123456789)
 * @param int $bodyId Template / Pattern ID approved in MeliPayamak console
 * @param array<int, string> $args Dynamic variables to fill in template
 * @param string $eventType Context tag (otp, daily_report, attendance, tuition, etc.)
 * @param int|null $parentId Optional linked parent id for audit log
 * @return array{success: bool, recId: int|null, status: string}
 */
function sendMeliPayamakPattern(
    string $to,
    int $bodyId,
    array $args = [],
    string $eventType = 'general',
    ?int $parentId = null
): array {
    $normalizedPhone = normalizeIranianPhone($to);

    if (!isValidIranianMobile($normalizedPhone)) {
        return ['success' => false, 'recId' => null, 'status' => 'شماره موبایل گیرنده نامعتبر است.'];
    }

    if ($bodyId <= 0) {
        return ['success' => false, 'recId' => null, 'status' => 'شناسه الگوی پیامک (bodyId) تنظیم نشده است.'];
    }

    $pdo = getDb();
    $settings = getSmsSettings($pdo);

    if (($settings['sms_enabled'] ?? '0') !== '1') {
        // SMS is disabled in admin settings; record log and return gracefully
        logSmsDelivery($pdo, $normalizedPhone, $bodyId, $args, null, 'ارسال پیامک در تنظیمات سیستم غیرفعال است.', false, $eventType, $parentId);
        return ['success' => false, 'recId' => null, 'status' => 'ارسال پیامک در سیستم غیرفعال است.'];
    }

    $apiKey = trim((string) ($settings['sms_api_key'] ?? ''));
    if ($apiKey === '') {
        logSmsDelivery($pdo, $normalizedPhone, $bodyId, $args, null, 'کلید وب‌سرویس ملی‌پیامک تنظیم نشده است.', false, $eventType, $parentId);
        return ['success' => false, 'recId' => null, 'status' => 'کلید وب‌سرویس ملی‌پیامک تنظیم نشده است.'];
    }

    $url = "https://console.melipayamak.com/api/send/shared/{$apiKey}";
    $payload = [
        'bodyId' => $bodyId,
        'to'     => $normalizedPhone,
        'args'   => array_values(array_map('strval', $args)),
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen((string) $payloadJson),
        ]);

        $responseRaw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseRaw === false || $curlError !== '') {
            $errorMsg = 'خطای اتصال به سرور پیامک: ' . ($curlError ?: 'بدون پاسخ');
            logSmsDelivery($pdo, $normalizedPhone, $bodyId, $args, null, $errorMsg, false, $eventType, $parentId);
            return ['success' => false, 'recId' => null, 'status' => $errorMsg];
        }

        $resData = json_decode((string) $responseRaw, true);
        $recId = isset($resData['recId']) ? (int) $resData['recId'] : null;
        $status = (string) ($resData['status'] ?? '');

        // In MeliPayamak shared pattern API, a successful delivery returns a numeric recId > 0
        $isSuccess = ($httpCode >= 200 && $httpCode < 300) && ($recId !== null && $recId > 0);

        logSmsDelivery($pdo, $normalizedPhone, $bodyId, $args, $recId, $status !== '' ? $status : ($isSuccess ? 'ارسال شد' : "HTTP {$httpCode}"), $isSuccess, $eventType, $parentId);

        return [
            'success' => $isSuccess,
            'recId'   => $recId,
            'status'  => $status !== '' ? $status : ($isSuccess ? 'پیامک با موفقیت ارسال شد.' : 'خطا در وب‌سرویس پیامک'),
        ];
    } catch (Throwable $e) {
        error_log('sendMeliPayamakPattern exception: ' . $e->getMessage());
        logSmsDelivery($pdo, $normalizedPhone, $bodyId, $args, null, $e->getMessage(), false, $eventType, $parentId);
        return ['success' => false, 'recId' => null, 'status' => $e->getMessage()];
    }
}

/**
 * Logs SMS delivery attempt to database for audit and troubleshooting.
 */
function logSmsDelivery(
    PDO $pdo,
    string $phone,
    int $bodyId,
    array $args,
    ?int $recId,
    string $status,
    bool $success,
    string $eventType,
    ?int $parentId
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sms_logs (recipient_phone, pattern_body_id, args_json, rec_id, status, message_type, error_message, parent_id)
            VALUES (:phone, :body_id, :args_json, :rec_id, :status, :event_type, :err, :parent_id)
        ");
        $stmt->execute([
            ':phone'      => $phone,
            ':body_id'    => $bodyId,
            ':args_json'  => json_encode($args, JSON_UNESCAPED_UNICODE),
            ':rec_id'     => $recId !== null ? (string) $recId : null,
            ':status'     => $success ? 'sent' : 'failed',
            ':event_type' => $eventType,
            ':err'        => $success ? null : mb_substr($status, 0, 500, 'UTF-8'),
            ':parent_id'  => $parentId,
        ]);
    } catch (Throwable $e) {
        error_log('logSmsDelivery error: ' . $e->getMessage());
    }
}

/**
 * Generates and sends a 5-digit verification code (OTP) for registration or password reset.
 * Rate-limited to at most 1 code per 60 seconds per phone number.
 */
function generateAndSendSmsOtp(
    string $phone,
    string $actionType = 'register',
    array $payload = []
): array {
    $normalized = normalizeIranianPhone($phone);
    if (!isValidIranianMobile($normalized)) {
        return ['success' => false, 'message' => 'شماره موبایل وارد شده نامعتبر است (مثال: 09123456789).'];
    }

    $pdo = getDb();
    initializeFinancialTables();

    // Check rate limit: 1 code every 60 seconds
    $stmt = $pdo->prepare("
        SELECT created_at FROM sms_verification_codes
        WHERE phone = :phone AND action_type = :action AND is_used = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':phone' => $normalized, ':action' => $actionType]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'کد تایید به‌تازگی ارسال شده است. لطفاً ۶۰ ثانیه بعد دوباره درخواست دهید.'];
    }

    // Generate 5-digit cryptographically secure OTP code
    $code = (string) random_int(10000, 99999);
    $expiresAt = date('Y-m-d H:i:s', time() + 180); // 3 minutes validity
    $clientIp = bruteForceClientIp();

    // Store in DB
    $ins = $pdo->prepare("
        INSERT INTO sms_verification_codes (phone, code, action_type, payload_json, expires_at, is_used, ip_address)
        VALUES (:phone, :code, :action, :payload, :exp, 0, :ip)
    ");
    $ins->execute([
        ':phone'   => $normalized,
        ':code'    => $code,
        ':action'  => $actionType,
        ':payload' => $payload !== [] ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        ':exp'     => $expiresAt,
        ':ip'      => $clientIp,
    ]);

    // Fetch pattern ID for OTP
    $settings = getSmsSettings($pdo);
    $otpBodyId = (int) ($settings['sms_pattern_otp'] ?? 0);
    $gatewayEnabled = ($settings['sms_enabled'] ?? '0') === '1';
    $isDevMode = defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE;

    if ($otpBodyId > 0 && $gatewayEnabled) {
        // OTP is time-critical: send it inline, never through the queue.
        $smsResult = sendMeliPayamakPattern($normalized, $otpBodyId, [$code], 'otp');
        if (!$smsResult['success']) {
            return [
                'success' => false,
                'message' => 'ارسال پیامک با مشکل مواجه شد: ' . $smsResult['status'],
                'code_debug' => $isDevMode ? $code : null,
            ];
        }

        return [
            'success' => true,
            'message' => 'کد تایید ۵ رقمی به شماره موبایل شما ارسال شد.',
            'code_debug' => $isDevMode ? $code : null,
        ];
    }

    // The gateway is not usable for OTP. In development we surface the code so
    // the flow stays testable, but in production we MUST fail loudly: silently
    // reporting "code sent" when no SMS left the server locks every new parent
    // out of registration and password recovery with no visible cause.
    if ($isDevMode) {
        return [
            'success' => true,
            'message' => 'حالت توسعه: پیامک ارسال نشد و کد تایید در ادامه نمایش داده می‌شود.',
            'code_debug' => $code,
        ];
    }

    $reason = !$gatewayEnabled
        ? 'سامانه پیامک مهدکودک در حال حاضر غیرفعال است.'
        : 'الگوی پیامک کد تایید هنوز تنظیم نشده است.';

    error_log('generateAndSendSmsOtp blocked: ' . $reason);

    return [
        'success' => false,
        'message' => $reason . ' لطفاً برای تکمیل ثبت‌نام با مدیریت مهدکودک تماس بگیرید.',
        'code_debug' => null,
    ];
}

/**
 * Validates an OTP code entered by the user.
 *
 * @return array{valid: bool, message: string, payload: array|null}
 */
function verifySmsOtp(string $phone, string $code, string $actionType = 'register'): array
{
    $normalized = normalizeIranianPhone($phone);
    $cleanCode  = trim($code);

    if (!isValidIranianMobile($normalized) || $cleanCode === '') {
        return ['valid' => false, 'message' => 'شماره موبایل یا کد تایید وارد شده نامعتبر است.', 'payload' => null];
    }

    $pdo = getDb();
    initializeFinancialTables();

    $stmt = $pdo->prepare("
        SELECT id, code, payload_json, attempts, expires_at, is_used
        FROM sms_verification_codes
        WHERE phone = :phone AND action_type = :action AND is_used = 0
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':phone' => $normalized, ':action' => $actionType]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['valid' => false, 'message' => 'کد تایید یافت نشد یا قبلاً استفاده شده است.', 'payload' => null];
    }

    $id = (int) $row['id'];
    $attempts = (int) $row['attempts'];

    // Check maximum attempts (max 5 failed tries)
    if ($attempts >= 5) {
        $pdo->prepare("UPDATE sms_verification_codes SET is_used = 1 WHERE id = :id")->execute([':id' => $id]);
        return ['valid' => false, 'message' => 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً کد جدید دریافت کنید.', 'payload' => null];
    }

    // Check expiration
    if (strtotime((string) $row['expires_at']) < time()) {
        $pdo->prepare("UPDATE sms_verification_codes SET is_used = 1 WHERE id = :id")->execute([':id' => $id]);
        return ['valid' => false, 'message' => 'کد تایید منقضی شده است. لطفاً کد جدید دریافت کنید.', 'payload' => null];
    }

    // Check code match
    if (!hash_equals((string) $row['code'], $cleanCode)) {
        $pdo->prepare("UPDATE sms_verification_codes SET attempts = attempts + 1 WHERE id = :id")->execute([':id' => $id]);
        $remaining = 4 - $attempts;
        return [
            'valid' => false,
            'message' => 'کد تایید وارد شده نادرست است.' . ($remaining > 0 ? " ({$remaining} تلاش باقی‌مانده)" : ''),
            'payload' => null,
        ];
    }

    // Mark as used
    $pdo->prepare("UPDATE sms_verification_codes SET is_used = 1 WHERE id = :id")->execute([':id' => $id]);

    $payload = null;
    if (!empty($row['payload_json'])) {
        $payload = json_decode((string) $row['payload_json'], true);
    }

    return [
        'valid'   => true,
        'message' => 'کد تایید با موفقیت تایید شد.',
        'payload' => $payload,
    ];
}

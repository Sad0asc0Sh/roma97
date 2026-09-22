<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth_flow.php';

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
        // Legacy single-key field (kept as fallback for both Console keys).
        'sms_api_key'                     => '',
        // Console keys are SEPARATE per endpoint (official docs):
        //   shared/pattern  → «کلید خدماتی»  → POST /api/send/shared/{key}
        //   OTP (one-time)  → «کلید یکبارمصرف» → POST /api/send/otp/{key}
        'sms_api_key_shared'              => '',
        'sms_api_key_otp'                 => '',
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
 * Normalize a Console API path key (strip copy-paste whitespace).
 */
function melipayamakCleanKey(string $key): string
{
    return preg_replace('/\s+/', '', trim($key)) ?? '';
}

/**
 * Resolve Console key for a given endpoint family.
 * shared/pattern → service key (کلید خدماتی); otp → one-time key (کلید یکبارمصرف).
 * Falls back to legacy sms_api_key when the dedicated field is empty.
 */
function melipayamakConsoleKey(array $settings, string $kind): string
{
    if ($kind === 'otp') {
        $key = melipayamakCleanKey((string) ($settings['sms_api_key_otp'] ?? ''));
        return $key !== '' ? $key : melipayamakCleanKey((string) ($settings['sms_api_key'] ?? ''));
    }

    $key = melipayamakCleanKey((string) ($settings['sms_api_key_shared'] ?? ''));
    return $key !== '' ? $key : melipayamakCleanKey((string) ($settings['sms_api_key'] ?? ''));
}

/**
 * POST JSON to MeliPayamak Console REST API.
 *
 * Official Console contract (from console.melipayamak.com samples):
 *   POST https://console.melipayamak.com/api/send/{otp|shared}/{key}
 *   Content-Type: application/json
 *   Body is a flat JSON object (NOT wrapped in "model").
 *   Wrong HTTP verb → 405 (Allow: GET, HEAD, POST). Form body → 415.
 *
 * @return array{http:int, body:array, raw:string, error:string}
 */
function melipayamakConsolePost(string $url, array $payload): array
{
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=utf-8',
        'Accept: application/json',
    ]);

    $responseRaw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseRaw === false) {
        $responseRaw = '';
    }

    $decoded = json_decode((string) $responseRaw, true);

    return [
        'http'  => $httpCode,
        'body'  => is_array($decoded) ? $decoded : [],
        'raw'   => (string) $responseRaw,
        'error' => (string) $curlError,
    ];
}

/**
 * Human-readable Console failure message (official status strings + HTTP codes).
 */
function melipayamakExplainFailure(int $httpCode, string $status, string $raw): string
{
    $status = trim($status);
    if ($status !== '') {
        // Console returns Persian business errors in "status" (e.g. invalid key).
        return $status;
    }

    return match (true) {
        $httpCode === 405 => 'HTTP 405: متد درخواست پذیرفته نیست (باید POST باشد).',
        $httpCode === 415 => 'HTTP 415: نوع محتوا باید application/json باشد.',
        $httpCode === 401, $httpCode === 403 => 'HTTP ' . $httpCode . ': کلید کنسول پذیرفته نشد (کلید خدماتی/یکبارمصرف را جدا وارد کنید).',
        $httpCode === 400 => 'HTTP 400: درخواست نامعتبر است (کلید، bodyId یا تعداد متغیرها را بررسی کنید). ' . mb_substr($raw, 0, 200, 'UTF-8'),
        default => sprintf('خطا در وب‌سرویس پیامک (HTTP %d). %s', $httpCode, mb_substr($raw, 0, 200, 'UTF-8')),
    };
}

/**
 * Sends a patterned/service SMS via MeliPayamak Shared Pattern API
 * («ارسال از خط خدماتی» — POST /api/send/shared/{کلید خدماتی}).
 *
 * Official sample body: {"bodyId":524,"to":"09123456789","args":["arg1","arg2"]}
 * Official sample response: {"recId":3741437414,"status":"..."}
 * bodyId MUST be the real approved «کد متن» — 524 in docs is only an example.
 *
 * @param string $to Recipient phone number (e.g. 09123456789)
 * @param int $bodyId Template / Pattern ID approved in MeliPayamak console
 * @param array<int, string> $args Dynamic variables to fill in template ({0}, {1}, …)
 * @param string $eventType Context tag (otp, daily_report, attendance, tuition, etc.)
 * @param int|null $parentId Optional linked parent id for audit log
 * @return array{success: bool, recId: int|null, status: string}
 */
function sendMeliPayamakPattern(
    string $to,
    int $bodyId,
    array $args = [],
    string $eventType = 'general',
    ?int $parentId = null,
    bool $bypassEnabledCheck = false
): array {
    $normalizedPhone = normalizeIranianPhone($to);

    if (!isValidIranianMobile($normalizedPhone)) {
        return ['success' => false, 'recId' => null, 'status' => 'شماره موبایل گیرنده نامعتبر است.'];
    }

    if ($bodyId <= 0) {
        return ['success' => false, 'recId' => null, 'status' => 'شناسه الگوی پیامک (bodyId) تنظیم نشده است.'];
    }

    try {
        $pdo = getDb();
        $settings = getSmsSettings($pdo);
    } catch (Throwable $e) {
        error_log('SMS storage unavailable.');
        return ['success' => false, 'recId' => null, 'status' => 'ارسال پیامک موقتاً در دسترس نیست.'];
    }

    $isTest = $bypassEnabledCheck || str_starts_with($eventType, 'test');

    if (!$isTest && ($settings['sms_enabled'] ?? '0') !== '1') {
        // SMS is disabled in admin settings; record log and return gracefully
        logSmsDelivery($pdo, $normalizedPhone, $bodyId, $args, null, 'ارسال پیامک در تنظیمات سیستم غیرفعال است.', false, $eventType, $parentId);
        return ['success' => false, 'recId' => null, 'status' => 'ارسال پیامک در سیستم غیرفعال است.'];
    }

    $apiKey = melipayamakConsoleKey($settings, 'shared');
    if ($apiKey === '') {
        $keyMsg = 'کلید خدماتی کنسول ملی‌پیامک تنظیم نشده است. در کنسول، بخش «ارسال از خط خدماتی» کلید خدماتی را کپی کنید.';
        logSmsDelivery($pdo, $normalizedPhone, $bodyId, $args, null, $keyMsg, false, $eventType, $parentId);
        return ['success' => false, 'recId' => null, 'status' => $keyMsg];
    }

    // Official Console shared-pattern API:
    //   POST /api/send/shared/{کلید خدماتی}
    //   {"bodyId":int,"to":"09...","args":["..."]}
    //   → {"recId":int,"status":"..."}
    $url = 'https://console.melipayamak.com/api/send/shared/' . rawurlencode($apiKey);
    $payload = [
        'bodyId' => $bodyId,
        'to'     => $normalizedPhone,
        'args'   => array_values(array_map('strval', $args)),
    ];

    try {
        $http = melipayamakConsolePost($url, $payload);

        if ($http['error'] !== '') {
            $errorMsg = 'خطای اتصال به سرور پیامک: ' . $http['error'];
            logSmsDelivery($pdo, $normalizedPhone, $bodyId, $args, null, $errorMsg, false, $eventType, $parentId);
            return ['success' => false, 'recId' => null, 'status' => $errorMsg];
        }

        $resData = $http['body'];
        $recId = null;
        foreach (['recId', 'recid', 'RecId', 'value', 'Value', 'id'] as $recKey) {
            if (isset($resData[$recKey]) && $resData[$recKey] !== '' && $resData[$recKey] !== null) {
                $recId = (int) $resData[$recKey];
                break;
            }
        }

        $status = (string) ($resData['status']
            ?? $resData['message']
            ?? $resData['StrRetStatus']
            ?? '');

        $retStatus = isset($resData['RetStatus']) ? (int) $resData['RetStatus'] : null;
        $isSuccess = ($http['http'] >= 200 && $http['http'] < 300)
            && (($recId !== null && $recId > 0) || $retStatus === 1);

        $logStatus = $status;
        if ($logStatus === '') {
            $logStatus = $isSuccess
                ? 'ارسال شد'
                : sprintf('HTTP %d %s', $http['http'], mb_substr($http['raw'], 0, 300, 'UTF-8'));
        } elseif (!$isSuccess) {
            $logStatus = sprintf('HTTP %d | %s | %s', $http['http'], $status, mb_substr($http['raw'], 0, 300, 'UTF-8'));
        }

        logSmsDelivery($pdo, $normalizedPhone, $bodyId, $args, $recId, $logStatus, $isSuccess, $eventType, $parentId);

        return [
            'success' => $isSuccess,
            'recId'   => $recId,
            'status'  => $isSuccess
                ? 'پیامک با موفقیت ارسال شد.'
                : melipayamakExplainFailure($http['http'], $status, $http['raw']),
        ];
    } catch (Throwable $e) {
        error_log('sendMeliPayamakPattern exception: ' . $e->getMessage());
        logSmsDelivery($pdo, $normalizedPhone, $bodyId, $args, null, $e->getMessage(), false, $eventType, $parentId);
        return ['success' => false, 'recId' => null, 'status' => $e->getMessage()];
    }
}

/**
 * Sends a one-time password via MeliPayamak Console native OTP API
 * («ارسال پیامک رمز یکبار مصرف» — POST /api/send/otp/{کلید یکبارمصرف}).
 *
 * Official sample:
 *   Request:  {"to":"09123456789"}
 *   Response: {"code":"3741437414","status":"..."}
 * The provider generates and texts the code — do NOT send bodyId/args here.
 *
 * @return array{success: bool, code: string|null, status: string}
 */
function sendMeliPayamakOtp(string $to, string $eventType = 'otp', ?int $parentId = null): array
{
    $normalizedPhone = normalizeIranianPhone($to);
    if (!isValidIranianMobile($normalizedPhone)) {
        return ['success' => false, 'code' => null, 'status' => 'شماره موبایل گیرنده نامعتبر است.'];
    }

    try {
        $pdo = getDb();
        $settings = getSmsSettings($pdo);
    } catch (Throwable $e) {
        error_log('sendMeliPayamakOtp storage unavailable.');
        return ['success' => false, 'code' => null, 'status' => 'ارسال پیامک موقتاً در دسترس نیست.'];
    }

    $isTest = str_starts_with($eventType, 'test');
    if (!$isTest && ($settings['sms_enabled'] ?? '0') !== '1') {
        logSmsDelivery($pdo, $normalizedPhone, 0, [], null, 'ارسال پیامک در تنظیمات سیستم غیرفعال است.', false, $eventType, $parentId);
        return ['success' => false, 'code' => null, 'status' => 'ارسال پیامک در سیستم غیرفعال است.'];
    }

    $apiKey = melipayamakConsoleKey($settings, 'otp');
    if ($apiKey === '') {
        $keyMsg = 'کلید یکبارمصرف کنسول ملی‌پیامک تنظیم نشده است. در کنسول، بخش «ارسال پیامک رمز یکبار مصرف» کلید یکبارمصرف را کپی کنید.';
        logSmsDelivery($pdo, $normalizedPhone, 0, [], null, $keyMsg, false, $eventType, $parentId);
        return ['success' => false, 'code' => null, 'status' => $keyMsg];
    }

    $url = 'https://console.melipayamak.com/api/send/otp/' . rawurlencode($apiKey);
    $payload = ['to' => $normalizedPhone];

    try {
        $http = melipayamakConsolePost($url, $payload);

        if ($http['error'] !== '') {
            $errorMsg = 'خطای اتصال به سرور پیامک: ' . $http['error'];
            logSmsDelivery($pdo, $normalizedPhone, 0, [], null, $errorMsg, false, $eventType, $parentId);
            return ['success' => false, 'code' => null, 'status' => $errorMsg];
        }

        $resData = $http['body'];
        $code = isset($resData['code']) ? trim((string) $resData['code']) : '';
        $status = (string) ($resData['status'] ?? $resData['message'] ?? '');
        $isSuccess = ($http['http'] >= 200 && $http['http'] < 300) && $code !== '';

        $logStatus = $status;
        if ($logStatus === '') {
            $logStatus = $isSuccess
                ? 'ارسال شد'
                : sprintf('HTTP %d %s', $http['http'], mb_substr($http['raw'], 0, 300, 'UTF-8'));
        } elseif (!$isSuccess) {
            $logStatus = sprintf('HTTP %d | %s | %s', $http['http'], $status, mb_substr($http['raw'], 0, 300, 'UTF-8'));
        }

        logSmsDelivery($pdo, $normalizedPhone, 0, [$code !== '' ? '(code-returned)' : ''], $code !== '' ? (int) $code : null, $logStatus, $isSuccess, $eventType, $parentId);

        return [
            'success' => $isSuccess,
            'code'    => $isSuccess ? $code : null,
            'status'  => $isSuccess
                ? 'کد تایید ارسال شد.'
                : melipayamakExplainFailure($http['http'], $status, $http['raw']),
        ];
    } catch (Throwable $e) {
        error_log('sendMeliPayamakOtp exception: ' . $e->getMessage());
        logSmsDelivery($pdo, $normalizedPhone, 0, [], null, $e->getMessage(), false, $eventType, $parentId);
        return ['success' => false, 'code' => null, 'status' => $e->getMessage()];
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
function smsOtpResendAvailableAt(PDO $pdo, string $phone, string $actionType): int
{
    $stmt = $pdo->prepare("SELECT GREATEST(0, 60 - TIMESTAMPDIFF(SECOND, created_at, NOW())) AS remaining
        FROM sms_verification_codes
        WHERE phone = :phone AND action_type = :action
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':phone' => normalizeIranianPhone($phone), ':action' => $actionType]);
    $row = $stmt->fetch();
    $remaining = $row ? max(0, (int) $row['remaining']) : 0;
    return $remaining > 0 ? time() + $remaining : 0;
}

function generateAndSendSmsOtp(
    string $phone,
    string $actionType = 'register',
    array $payload = []
): array {
    $normalized = normalizeIranianPhone($phone);
    if (!isValidIranianMobile($normalized)) {
        return ['success' => false, 'message' => 'شماره موبایل وارد شده نامعتبر است (مثال: 09123456789).'];
    }

    try {
        $pdo = getDb();
        initializeFinancialTables();
    } catch (Throwable $e) {
        error_log('generateAndSendSmsOtp storage unavailable.');
        return ['success' => false, 'message' => 'ارسال کد تایید موقتاً در دسترس نیست. لطفاً بعداً تلاش کنید.'];
    }

    if (smsOtpResendAvailableAt($pdo, $normalized, $actionType) > time()) {
        return ['success' => false, 'message' => 'کد تایید به‌تازگی ارسال شده است. لطفاً ۶۰ ثانیه بعد دوباره درخواست دهید.'];
    }

    $settings = getSmsSettings($pdo);
    $gatewayEnabled = ($settings['sms_enabled'] ?? '0') === '1';
    $isDevMode = defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE;
    $otpBodyId = (int) ($settings['sms_pattern_otp'] ?? 0);
    $otpKey = melipayamakConsoleKey($settings, 'otp');
    $sharedKey = melipayamakConsoleKey($settings, 'shared');

    $expiresAt = date('Y-m-d H:i:s', time() + 180); // 3 minutes validity
    $clientIp = bruteForceClientIp();

    $storeCode = static function (string $code) use ($pdo, $normalized, $actionType, $payload, $expiresAt, $clientIp): void {
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
    };

    // Preferred: Console native OTP API («کلید یکبارمصرف»). Body is only {"to":"..."}.
    if ($gatewayEnabled && $otpKey !== '') {
        $smsResult = sendMeliPayamakOtp($normalized, 'otp');
        if ($smsResult['success'] && $smsResult['code'] !== '') {
            $storeCode((string) $smsResult['code']);
            $digits = (int) strlen((string) $smsResult['code']);
            return [
                'success' => true,
                'message' => "کد تایید {$digits} رقمی به شماره موبایل شما ارسال شد.",
                'code_debug' => $isDevMode ? (string) $smsResult['code'] : null,
            ];
        }

        if ($otpBodyId <= 0 || $sharedKey === '') {
            return [
                'success' => false,
                'message' => 'ارسال پیامک با مشکل مواجه شد: ' . $smsResult['status'],
                'code_debug' => null,
            ];
        }
    }

    // Fallback: shared/pattern API with a locally generated 5-digit code.
    if ($gatewayEnabled && $otpBodyId > 0 && $sharedKey !== '') {
        $code = (string) random_int(10000, 99999);
        $storeCode($code);

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

    if ($isDevMode) {
        $code = (string) random_int(10000, 99999);
        $storeCode($code);
        return [
            'success' => true,
            'message' => 'حالت توسعه: پیامک ارسال نشد و کد تایید در ادامه نمایش داده می‌شود.',
            'code_debug' => $code,
        ];
    }

    $reason = !$gatewayEnabled
        ? 'سامانه پیامک مهدکودک در حال حاضر غیرفعال است.'
        : 'برای ارسال کد تایید، «کلید یکبارمصرف» کنسول (یا کلید خدماتی + الگوی OTP) تنظیم نشده است.';

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
function verifySmsOtp(string $phone, string $code, string $actionType = 'register', ?string $challenge = null): array
{
    $normalized = normalizeIranianPhone($phone);
    $cleanCode  = authNormalizeOtp($code);

    // Native Console OTP may return 5–10 digit codes (sample is 10 digits).
    if (!isValidIranianMobile($normalized) || preg_match('/\A[0-9]{4,10}\z/', $cleanCode) !== 1) {
        return ['valid' => false, 'message' => 'شماره موبایل یا کد تایید وارد شده نامعتبر است.', 'payload' => null];
    }

    try {
        $pdo = getDb();
        initializeFinancialTables();
    } catch (Throwable $e) {
        error_log('verifySmsOtp storage unavailable.');
        return ['valid' => false, 'message' => 'تایید کد موقتاً در دسترس نیست. لطفاً بعداً تلاش کنید.', 'payload' => null];
    }

    $stmt = $pdo->prepare("
        SELECT id, code, payload_json, attempts, expires_at, is_used
        FROM sms_verification_codes
        WHERE phone = :phone AND action_type = :action AND is_used = 0
        ORDER BY id DESC LIMIT 1 FOR UPDATE
    ");
    $stmt->execute([':phone' => $normalized, ':action' => $actionType]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['valid' => false, 'message' => 'کد تایید یافت نشد یا قبلاً استفاده شده است.', 'payload' => null];
    }

    $payload = null;
    if (!empty($row['payload_json'])) {
        $payload = json_decode((string) $row['payload_json'], true);
    }

    if (is_string($challenge) && $challenge !== ''
        && (!is_array($payload) || !is_string($payload['challenge'] ?? null)
            || !hash_equals($challenge, $payload['challenge']))) {
        return ['valid' => false, 'message' => 'کد تایید نامعتبر است. لطفاً دوباره درخواست دهید.', 'payload' => null];
    }

    $id = (int) $row['id'];
    $attempts = (int) $row['attempts'];

    if ($attempts >= 5) {
        $pdo->prepare("UPDATE sms_verification_codes SET is_used = 1 WHERE id = :id")->execute([':id' => $id]);
        return ['valid' => false, 'message' => 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً کد جدید دریافت کنید.', 'payload' => null];
    }

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

    // Mark as used (atomic, prevents double-consume race)
    $consume = $pdo->prepare("UPDATE sms_verification_codes SET is_used = 1
        WHERE id = :id AND is_used = 0");
    $consume->execute([':id' => $id]);
    if ($consume->rowCount() !== 1) {
        return ['valid' => false, 'message' => 'کد تایید قبلاً استفاده شده است. لطفاً دوباره درخواست دهید.', 'payload' => null];
    }

    return [
        'valid'   => true,
        'message' => 'کد تایید با موفقیت تایید شد.',
        'payload' => $payload,
    ];
}

<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/auth_flow.php';
require_once __DIR__ . '/../includes/unified_auth.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
};
$check(normalizeIranianPhone('۰۹۱۲۳۴۵۶۷۸۹') === '09123456789', 'Persian phone normalization');
$check(normalizeIranianPhone('+98 912 345 6789') === '09123456789', 'International phone normalization');
$check(authNormalizeOtp('١٢٣٤٥') === '12345', 'Arabic OTP normalization');
$check(authStrongPassword('Garden123'), 'Valid password');
$check(!authStrongPassword('12345678'), 'Password requires a letter');
$check(!authStrongPassword('GardenDay'), 'Password requires a digit');
$check(!authStrongPassword('Aa12345'), 'Password minimum length');
$check(!authStrongPassword(str_repeat('a', 72) . '1'), 'Password byte limit');
$check(authInput(['phone' => ['09123456789']], 'phone') === '', 'Scalar input contract');
$_SESSION['registration_pending'] = ['phone' => '09123456789', 'challenge' => str_repeat('a', 64), 'expires_at' => time() + 600];
$check(authPending('registration_pending', '09123456789') !== null, 'Pending session available');
$check(authPending('registration_pending', '09121111111') === null, 'Pending phone association');
unset($_SESSION['registration_pending']);

foreach ([['login', 'form'], ['register', 'form'], ['register', 'verify'], ['forgot', 'request'], ['forgot', 'verify'], ['reset', 'form']] as [$authMode, $step]) {
    $pageTitle = 'Auth smoke';
    $phone = $verifyPhone = '09123456789';
    $old = ['first_name' => 'آزمایش', 'last_name' => 'والد', 'phone' => $phone];
    $errors = $fieldErrors = [];
    $error = '';
    $successMessage = $successFlash = null;
    $resendAvailableAt = time() + 42;
    $tokenValid = true;
    $rawToken = str_repeat('a', 64);
    $teacherRecovery = false;
    ob_start();
    require __DIR__ . '/../templates/unified-auth-page.php';
    $html = ob_get_clean();
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $context = $authMode . '/' . $step;
    $check($xpath->query('//h1')->length === 1, "$context: one heading");
    $check($xpath->query('//html[@lang="fa-IR" and @dir="rtl"]')->length === 1, "$context: Persian RTL");
    $check($xpath->query('//form//form')->length === 0, "$context: no nested forms");
    $check($xpath->query('//form')->length === ($step === 'verify' ? 2 : 1), "$context: separate resend form");
    foreach ($xpath->query('//form') as $form) {
        $check($xpath->query('.//input[@name="csrf_token"]', $form)->length === 1, "$context: CSRF field");
    }
    foreach ($xpath->query('//input[not(@type="hidden")]') as $input) {
        $id = $input->getAttribute('id');
        $check($xpath->query('//label[@for="' . $id . '"]')->length === 1, "$context: label $id");
    }
    if ($step === 'verify') {
        $check($xpath->query('//input[@name="otp_code" and @maxlength="5" and @autocomplete="one-time-code"]')->length === 1, "$context: OTP attributes");
        $check($xpath->query('//button[@data-resend and @data-wait="42"]')->length === 1, "$context: server cooldown");
    }
    if ($authMode === 'login') {
        $check($xpath->query('//input[@name="identifier" and @autocomplete="username"]')->length === 1, "$context: unified identifier field");
        $check($xpath->query('//input[@name="panel" and @type="hidden"]')->length === 1, "$context: panel hint hidden field");
    }
}

// ── Unified routing: identifier classification (pure, no DB) ──
$check(unifiedIdentifierKind('09123456789') === 'phone', 'unified: parent/teacher phone routed as phone');
$check(unifiedIdentifierKind('۰۹۱۲۳۴۵۶۷۸۹') === 'phone', 'unified: Persian phone routed as phone');
$check(unifiedIdentifierKind('+989123456789') === 'phone', 'unified: international phone routed as phone');
$check(unifiedIdentifierKind('admin_user1') === 'username', 'unified: admin username routed as username');
$check(unifiedIdentifierKind('ab') === 'invalid', 'unified: short username rejected');
$check(unifiedIdentifierKind('') === 'invalid', 'unified: empty identifier rejected');
$check(unifiedIsValidPanelHint('auto') === true, 'unified: auto hint accepted');
$check(unifiedIsValidPanelHint('admin') === true, 'unified: admin hint accepted');
$check(unifiedIsValidPanelHint('superuser') === false, 'unified: invented hint rejected');
fwrite(STDOUT, "PASS: $checks authentication helper and template checks. No database writes or SMS requests.\n");

<?php
declare(strict_types=1);

/**
 * ROMA — SMS System & Pattern Verification Test Suite
 */

define('ROOMA_APP', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/notifications.php';

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $description): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$description}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$description}\n";
    }
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "1. Testing Phone Normalization & Validation\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

assertTest(normalizeIranianPhone('09123456789') === '09123456789', 'Plain 11-digit Iranian mobile stays intact');
assertTest(normalizeIranianPhone('+989123456789') === '09123456789', '+98 prefix converted to 09');
assertTest(normalizeIranianPhone('00989123456789') === '09123456789', '0098 prefix converted to 09');
assertTest(normalizeIranianPhone('۹۱۲۳۴۵۶۷۸۹') === '09123456789', 'Persian digits converted to English 09');
assertTest(normalizeIranianPhone('٠٩١٢٣٤٥٦٧٨٩') === '09123456789', 'Arabic digits converted to English 09');
assertTest(isValidIranianMobile('09123456789') === true, 'Valid Iranian mobile passes validation');
assertTest(isValidIranianMobile('02188888888') === false, 'Landline number fails mobile validation');
assertTest(isValidIranianMobile('12345') === false, 'Short number fails mobile validation');

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "2. Testing Single API Key Resolution & Normalization\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$settingsWithSingleKey = [
    'sms_enabled' => '1',
    'sms_api_key' => 'my-single-secret-key',
    'sms_api_key_shared' => '',
    'sms_api_key_otp' => '',
];

assertTest(melipayamakCleanKey('https://console.melipayamak.com/api/send/shared/f95527ef2fa7498dbad9a0d09f2bbad2') === 'f95527ef2fa7498dbad9a0d09f2bbad2', 'Full URL stripped to token');
assertTest(melipayamakCleanKey('https://console.melipayamak.com/api/send/otp/f95527ef2fa7498dbad9a0d09f2bbad2/') === 'f95527ef2fa7498dbad9a0d09f2bbad2', 'URL with trailing slash stripped to token');
assertTest(melipayamakCleanKey('  f95527ef2fa7498dbad9a0d09f2bbad2  ') === 'f95527ef2fa7498dbad9a0d09f2bbad2', 'Whitespace stripped from token');

assertTest(melipayamakConsoleKey($settingsWithSingleKey, 'shared') === 'my-single-secret-key', 'Single API key resolved for shared pattern');
assertTest(melipayamakConsoleKey($settingsWithSingleKey, 'otp') === 'my-single-secret-key', 'Single API key resolved as default for OTP');

$settingsWithSharedKeyOnly = [
    'sms_enabled' => '1',
    'sms_api_key' => '',
    'sms_api_key_shared' => 'legacy-shared-key',
    'sms_api_key_otp' => '',
];

assertTest(melipayamakConsoleKey($settingsWithSharedKeyOnly, 'shared') === 'legacy-shared-key', 'Legacy shared key backward compatibility');

$settingsWithDedicatedOtp = [
    'sms_enabled' => '1',
    'sms_api_key' => 'primary-key',
    'sms_api_key_shared' => '',
    'sms_api_key_otp' => 'special-otp-key',
];

assertTest(melipayamakConsoleKey($settingsWithDedicatedOtp, 'otp') === 'special-otp-key', 'Dedicated OTP key used when explicitly set');
assertTest(melipayamakConsoleKey($settingsWithDedicatedOtp, 'shared') === 'primary-key', 'Primary key used for shared pattern even when dedicated OTP key exists');

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "3. Testing Notification Catalog & Pattern Argument Counts\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$catalog = notificationCatalog();

assertTest(count($catalog) === 9, 'Catalog contains exactly 9 notification events');

$expectedArgs = [
    'otp'                 => 1,
    'welcome'             => 1,
    'daily_report_brief'  => 2,
    'daily_report_vip'    => 3,
    'attendance'          => 3,
    'tuition_reminder'    => 4,
    'payment_receipt'     => 4,
    'announcement'        => 2,
    'child_status'        => 3,
];

foreach ($expectedArgs as $eventKey => $count) {
    assertTest(isset($catalog[$eventKey]), "Event '{$eventKey}' exists in catalog");
    $actualCount = count($catalog[$eventKey]['args'] ?? []);
    assertTest($actualCount === $count, "Event '{$eventKey}' expects exactly {$count} arguments (found {$actualCount})");
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "4. Testing Gateway Status with Single Key\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Mock DB-less test of smsGatewayStatus logic:
$status = [
    'enabled' => true,
    'apiKeySet' => melipayamakConsoleKey($settingsWithSingleKey, 'shared') !== '',
    'otpConfigured' => true,
];

assertTest($status['apiKeySet'] === true, 'Single key marks gateway as having API key set');

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "5. Testing MeliPayamak Connection Verifier & Main Panel\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$emptyKeyRes = checkMelipayamakConnection('');
assertTest($emptyKeyRes['success'] === false, 'Empty API key fails immediately without network request');

$testKeyRes = checkMelipayamakConnection('invalid-dummy-key');
assertTest(is_array($testKeyRes) && isset($testKeyRes['success'], $testKeyRes['message']), 'Connection check returns standard result structure');

assertTest(isPayamakPanelActive(['sms_provider' => 'melipayamak_main']) === true, 'Explicit melipayamak_main provider active');
assertTest(isPayamakPanelActive(['sms_provider' => 'melipayamak_console']) === false, 'Explicit melipayamak_console provider active');
assertTest(isPayamakPanelActive(['sms_provider' => '', 'sms_username' => 'testuser', 'sms_api_key' => '']) === true, 'Auto-detect main panel from username');

assertTest(str_contains(explainPayamakPanelStatus(0, '', ''), 'نادرست است'), 'Status 0 explains invalid credentials');
assertTest(str_contains(explainPayamakPanelStatus(14, '', ''), 'شناسه الگو'), 'Status 14 explains missing bodyId');
assertTest(str_contains(explainPayamakPanelStatus(35, '', ''), 'متغیرها'), 'Status 35 explains argument mismatch');

$mainEmptyCreds = checkPayamakPanelCredit('', '');
assertTest($mainEmptyCreds['success'] === false, 'Main panel fails gracefully with empty credentials');

echo "\n════════════════════════════════════════════════════\n";
echo "TEST SUMMARY: {$passed} passed, {$failed} failed\n";
echo "════════════════════════════════════════════════════\n";

exit($failed === 0 ? 0 : 1);

<?php
declare(strict_types=1);

/**
 * Tests for the parent phone-number fixes:
 *  1. includes/db.php — parents.phone has a UNIQUE index (no two parents can
 *     share the login identifier); the runtime DDL is idempotent and widens
 *     the sms_verification_codes.action_type ENUM with 'phone_change' on
 *     existing installs.
 *  2. includes/sms.php — OTP generate/verify round-trip works for the new
 *     'phone_change' action type (dev mode: no real SMS is sent).
 *
 * Needs: PHP CLI + MySQL/MariaDB (see tests/README.md).
 * Run: php tests/parent-phone-test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-bootstrap.php';
require dirname(__DIR__) . '/includes/db.php';
require dirname(__DIR__) . '/includes/auth_flow.php';
require dirname(__DIR__) . '/includes/sms.php';

echo "Parent phone-number tests\n";

$pdo = testDbConnect();
initializeFinancialTables(); // runs the new DDL (unique phone index, ENUM widening)

// ─── 1. UNIQUE index on parents.phone ───────────────────────────────────────
$uniqueIdx = $pdo->query("
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = 'parents'
      AND index_name = 'idx_parent_phone' AND non_unique = 0
")->fetchColumn();
testAssert((int) $uniqueIdx === 1, 'UNIQUE index idx_parent_phone exists on parents.phone');

$insertParent = function (string $phone) use ($pdo): int {
    $stmt = $pdo->prepare(
        "INSERT INTO parents (first_name, last_name, email, phone, password, status)
         VALUES ('Test', 'User', :email, :phone, 'x', 'active')"
    );
    $stmt->execute([
        ':email' => 'test+' . bin2hex(random_bytes(4)) . '@example.com',
        ':phone' => $phone,
    ]);
    return (int) $pdo->lastInsertId();
};

$parentA = $insertParent('09120000001');
testAssert($parentA > 0, 'first parent with phone 09120000001 inserted');

$duplicateRejected = false;
try {
    $insertParent('09120000001');
} catch (PDOException $e) {
    $duplicateRejected = $e->getCode() === '23000';
}
testAssert($duplicateRejected, 'duplicate phone rejected by UNIQUE constraint (SQLSTATE 23000)');

$parentB = $insertParent('09120000002');
testAssert($parentB > 0, 'different phone 09120000002 still accepted');

$nullPhoneOk = true;
try {
    $insertParentNull = function () use ($pdo): void {
        $stmt = $pdo->prepare(
            "INSERT INTO parents (first_name, last_name, email, phone, password, status)
             VALUES ('Test', 'Null', :email, NULL, 'x', 'active')"
        );
        $stmt->execute([':email' => 'test+null' . bin2hex(random_bytes(4)) . '@example.com']);
    };
    $insertParentNull();
    $insertParentNull();
} catch (PDOException $e) {
    $nullPhoneOk = false;
}
testAssert($nullPhoneOk, 'multiple NULL phones allowed (UNIQUE ignores NULLs)');

// ─── 2. ENUM widening for existing installs ──────────────────────────────────
// Simulate a legacy install whose ENUM lacks 'phone_change', then run the
// runtime DDL in a FRESH PHP process (like a real web request, where the
// static $initialized guard starts unset) and confirm it widens the column.
$pdo->exec("ALTER TABLE sms_verification_codes
    MODIFY COLUMN action_type ENUM('register','login','reset_password') NOT NULL DEFAULT 'register'");
$probeCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/helpers/enum-widen-probe.php');
$probeOutput = shell_exec($probeCmd);
testAssert(
    is_string($probeOutput) && strpos($probeOutput, "'phone_change'") !== false,
    'runtime DDL widens legacy action_type ENUM with phone_change'
);

// ─── 3. OTP round-trip for the phone_change action ──────────────────────────
$challenge = bin2hex(random_bytes(32));
$otp = generateAndSendSmsOtp('09120000003', 'phone_change', [
    'parent_id' => $parentA,
    'challenge' => $challenge,
]);
testAssert($otp['success'] === true, 'phone_change OTP generated (dev mode, no SMS sent)');
$code = (string) ($otp['code_debug'] ?? '');
testAssert(preg_match('/\A[0-9]{5}\z/', $code) === 1, 'dev OTP code is 5 digits');

$wrong = verifySmsOtp('09120000003', '00000', 'phone_change', $challenge);
testAssert($wrong['valid'] === false, 'wrong OTP code rejected');

$ok = verifySmsOtp('09120000003', $code, 'phone_change', $challenge);
testAssert($ok['valid'] === true, 'correct OTP code accepted for phone_change');

$reuse = verifySmsOtp('09120000003', $code, 'phone_change', $challenge);
testAssert($reuse['valid'] === false, 'OTP cannot be reused (single-use)');

$badChallenge = verifySmsOtp('09120000004', '00000', 'phone_change', 'wrong-challenge');
testAssert($badChallenge['valid'] === false, 'OTP bound to challenge (wrong challenge rejected)');

// Persian-digit OTP codes are normalized on verify.
$otp2 = generateAndSendSmsOtp('09120000005', 'phone_change', ['challenge' => $challenge]);
$code2 = (string) ($otp2['code_debug'] ?? '');
$faCode = strtr($code2, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
$faOk = verifySmsOtp('09120000005', $faCode, 'phone_change', $challenge);
testAssert($faOk['valid'] === true, 'Persian-digit OTP code accepted');

exit(testSummary('parent-phone-test'));

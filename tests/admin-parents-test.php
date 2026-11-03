<?php
declare(strict_types=1);

/**
 * Admin manual parent/child registration — helpers, permissions, and the
 * transactional data layer (SQLite, portable SQL).
 *
 * Covers the new admin pages' core logic in includes/admin_family_helpers.php:
 *  - parent/child validation mirrors register.php + parent/add-child.php
 *  - phone normalisation (Persian digits / +98 / 0098) via includes/sms.php
 *  - duplicate phone/email rejection, NULL-email coexistence
 *  - parent + children + classroom enrolment commits atomically, and a
 *    mid-transaction failure rolls the parent back too
 *  - edit-parent uniqueness excludes self
 *  - permissions matrix: new pages for owner/manager/receptionist only
 *
 * Run: php tests/admin-parents-test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-bootstrap.php';

defined('ROOMA_APP') || define('ROOMA_APP', true);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/auth.php';
require_once $projectRoot . '/includes/auth_flow.php';
require_once $projectRoot . '/includes/sms.php';
require_once $projectRoot . '/includes/permissions.php';
require_once $projectRoot . '/includes/admin_family_helpers.php';

echo "Admin manual parents/children tests\n";

// ── A. Parent validation (register.php parity) ─────────────────────────────
testAssert(adminFamilyIsValidParentName('علی') === true, 'parent name: Persian name accepted');
testAssert(adminFamilyIsValidParentName('علی رضایی') === true, 'parent name: name with space accepted');
testAssert(adminFamilyIsValidParentName('') === false, 'parent name: empty rejected');
testAssert(adminFamilyIsValidParentName('Ali123') === false, 'parent name: digits rejected');
testAssert(adminFamilyIsStrongPassword('abcd1234') === true, 'password: letter+digit 8 chars accepted');
testAssert(adminFamilyIsStrongPassword('abcdefgh') === false, 'password: no digit rejected');
testAssert(adminFamilyIsStrongPassword('12345678') === false, 'password: no letter rejected');
testAssert(adminFamilyIsStrongPassword('abc123') === false, 'password: too short rejected');

// ── B. Phone normalisation (the login identifier) ──────────────────────────
testAssert(normalizeIranianPhone('۰۹۱۲۳۴۵۶۷۸۹') === '09123456789', 'phone: Persian digits normalize');
testAssert(normalizeIranianPhone('+98 912 345 6789') === '09123456789', 'phone: +98 normalize');
testAssert(normalizeIranianPhone('00989123456789') === '09123456789', 'phone: 0098 normalize');
testAssert(isValidIranianMobile(normalizeIranianPhone('۰۹۱۲۳۴۵۶۷۸۹')) === true, 'phone: normalized Persian input is a valid mobile');
testAssert(isValidIranianMobile('02112345678') === false, 'phone: landline rejected');

// ── C. Child validation ────────────────────────────────────────────────────
testAssert(adminFamilyIsValidChildName('سارا') === true, 'child name: valid accepted');
testAssert(adminFamilyIsValidChildName('', 100, false) === true, 'child name: optional empty accepted');
testAssert(adminFamilyIsValidChildName('', 100, true) === false, 'child name: required empty rejected');
testAssert(adminFamilyIsValidChildPhone('') === true, 'child guardian phone: empty accepted');
testAssert(adminFamilyIsValidChildPhone('09123456789') === true, 'child guardian phone: valid accepted');
testAssert(adminFamilyIsValidChildPhone('abc') === false, 'child guardian phone: letters rejected');

$dobTodayJalali = null;
[$jy, $jm, $jd] = gregorianToJalali((int) date('Y') - 3, (int) date('n'), (int) date('j'));
$jalaliDob3yo = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
$dobParsed = adminFamilyValidChildDob($jalaliDob3yo);
testAssert($dobParsed !== null && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $dobParsed) === 1, 'child DOB: Jalali input converts to Gregorian');
testAssert(adminFamilyValidChildDob(date('Y-m-d', strtotime('+1 day'))) === null, 'child DOB: future rejected');
testAssert(adminFamilyValidChildDob('1300/01/01') === null, 'child DOB: older than 15 years rejected (typo guard)');
testAssert(adminFamilyValidChildDob('not-a-date') === null, 'child DOB: garbage rejected');

$emptyBlock = adminFamilyNormalizeChildInput([]);
testAssert(adminFamilyChildBlockIsEmpty($emptyBlock) === true, 'child block: fully empty block detected');
$filledBlock = adminFamilyNormalizeChildInput(['first_name' => 'سارا']);
testAssert(adminFamilyChildBlockIsEmpty($filledBlock) === false, 'child block: partially filled block is not empty');
$badStatus = adminFamilyNormalizeChildInput(['status' => 'hacked']);
testAssert($badStatus['status'] === 'active', 'child block: unknown status falls back to active');

[$errsValid, $dobValid] = adminFamilyValidateChild(adminFamilyNormalizeChildInput([
    'first_name' => 'سارا', 'last_name' => 'احمدی', 'date_of_birth' => $jalaliDob3yo, 'gender' => 'female', 'status' => 'active',
]), 'کودک');
testAssert($errsValid === [] && $dobValid !== null, 'child validation: complete valid child passes');
[$errsInvalid] = adminFamilyValidateChild(adminFamilyNormalizeChildInput([
    'first_name' => '', 'last_name' => '', 'date_of_birth' => '',
]), 'کودک');
testAssert(count($errsInvalid) >= 3, 'child validation: empty required fields produce errors');

// ── D. SQLite data layer: schema mirrors schema.sql ────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("CREATE TABLE parents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE DEFAULT NULL, phone VARCHAR(20) UNIQUE DEFAULT NULL,
    phone_verified INTEGER NOT NULL DEFAULT 0, password VARCHAR(255) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending', email_verified INTEGER DEFAULT 0,
    sms_pref_daily_report INTEGER NOT NULL DEFAULT 1, sms_pref_attendance INTEGER NOT NULL DEFAULT 1,
    sms_pref_tuition INTEGER NOT NULL DEFAULT 1, sms_pref_announcements INTEGER NOT NULL DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE children (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER NOT NULL REFERENCES parents(id) ON DELETE CASCADE,
    first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL,
    preferred_name VARCHAR(100) DEFAULT NULL, date_of_birth DATE NOT NULL,
    gender VARCHAR(10) DEFAULT '', allergies TEXT DEFAULT NULL, medical_notes TEXT DEFAULT NULL,
    second_guardian_name VARCHAR(200) DEFAULT NULL, second_guardian_phone VARCHAR(20) DEFAULT NULL,
    photo VARCHAR(255) DEFAULT NULL, status VARCHAR(20) DEFAULT 'pending',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE classrooms (id INTEGER PRIMARY KEY, name VARCHAR(100) NOT NULL, capacity INTEGER DEFAULT 15)");
$pdo->exec("CREATE TABLE child_classroom (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id INTEGER NOT NULL UNIQUE REFERENCES children(id) ON DELETE CASCADE,
    classroom_id INTEGER NOT NULL REFERENCES classrooms(id) ON DELETE CASCADE,
    enrollment_date DATE NOT NULL
)");
$pdo->exec("INSERT INTO classrooms (id, name) VALUES (1, 'شکوفه‌ها')");

$mkChild = static function (array $over = []) use ($jalaliDob3yo): array {
    $base = adminFamilyNormalizeChildInput(array_merge([
        'first_name' => 'سارا', 'last_name' => 'احمدی', 'date_of_birth' => $jalaliDob3yo,
        'gender' => 'female', 'status' => 'active', 'classroom_id' => 1,
    ], $over));
    [$e, $d] = adminFamilyValidateChild($base, 'کودک');
    $base['dob_gregorian'] = $d ?? '';
    return $base;
};
$hash = password_hash('abcd1234', PASSWORD_DEFAULT);

// Parent + 2 children + classroom in ONE transaction.
$normalizedPhone = normalizeIranianPhone('۰۹۱۲۳۴۵۶۷۸۹');
[$parentId, $childIds] = adminFamilyCreateParentWithChildren($pdo, [
    'first_name' => 'علی', 'last_name' => 'احمدی', 'email' => null,
    'phone' => $normalizedPhone, 'phone_verified' => 1,
    'password_hash' => $hash, 'status' => 'active',
], [
    $mkChild(),
    $mkChild(['first_name' => 'رضا', 'gender' => 'male', 'status' => 'pending', 'classroom_id' => 1]),
]);
testAssert($parentId > 0 && count($childIds) === 2, 'tx: parent + 2 children created atomically');
$storedPhone = $pdo->query("SELECT phone FROM parents WHERE id = {$parentId}")->fetchColumn();
testAssert($storedPhone === '09123456789', 'tx: normalized phone stored (Persian-digit input)');
$storedVerified = (int) $pdo->query("SELECT phone_verified FROM parents WHERE id = {$parentId}")->fetchColumn();
testAssert($storedVerified === 1, 'tx: admin-created parent stored phone_verified=1');
testAssert((int) $pdo->query('SELECT COUNT(*) FROM children')->fetchColumn() === 2, 'tx: both children in DB');
testAssert((int) $pdo->query('SELECT COUNT(*) FROM child_classroom')->fetchColumn() === 1, 'tx: only the ACTIVE child is enrolled (pending child not enrolled)');

// NULL-email parents coexist.
[$p2] = adminFamilyCreateParentWithChildren($pdo, [
    'first_name' => 'مریم', 'last_name' => 'رضایی', 'email' => null,
    'phone' => '09351112233', 'phone_verified' => 1, 'password_hash' => $hash, 'status' => 'active',
], []);
testAssert($p2 > 0, 'null-email: second NULL-email parent coexists');

// Duplicate phone / email rejected (application check + UNIQUE constraint).
testAssert(adminFamilyParentPhoneExists($pdo, '09123456789', 0) === true, 'duplicate check: existing phone detected');
testAssert(adminFamilyParentEmailExists($pdo, 'dup@example.com', 0) === false, 'duplicate check: unused email not flagged');
[$p3] = adminFamilyCreateParentWithChildren($pdo, [
    'first_name' => 'test', 'last_name' => 'user', 'email' => 'dup@example.com',
    'phone' => '09120000001', 'phone_verified' => 1, 'password_hash' => $hash, 'status' => 'active',
], []);
testAssert(adminFamilyParentEmailExists($pdo, 'dup@example.com', 0) === true, 'duplicate check: existing email detected');
$dupPhoneRejected = false;
try {
    adminFamilyCreateParentWithChildren($pdo, [
        'first_name' => 'x', 'last_name' => 'y', 'email' => null,
        'phone' => '09123456789', 'phone_verified' => 1, 'password_hash' => $hash, 'status' => 'active',
    ], []);
} catch (Throwable) { $dupPhoneRejected = true; }
testAssert($dupPhoneRejected, 'duplicate phone rejected by UNIQUE constraint');
$dupEmailRejected = false;
try {
    adminFamilyCreateParentWithChildren($pdo, [
        'first_name' => 'x', 'last_name' => 'y', 'email' => 'dup@example.com',
        'phone' => '09129998877', 'phone_verified' => 1, 'password_hash' => $hash, 'status' => 'active',
    ], []);
} catch (Throwable) { $dupEmailRejected = true; }
testAssert($dupEmailRejected, 'duplicate email rejected by UNIQUE constraint');

// Forced failure mid-transaction rolls back the parent too.
$parentsBefore = (int) $pdo->query('SELECT COUNT(*) FROM parents')->fetchColumn();
$childrenBefore = (int) $pdo->query('SELECT COUNT(*) FROM children')->fetchColumn();
$rolledBack = false;
try {
    adminFamilyCreateParentWithChildren($pdo, [
        'first_name' => 'rollback', 'last_name' => 'test', 'email' => null,
        'phone' => '09127776655', 'phone_verified' => 1, 'password_hash' => $hash, 'status' => 'active',
    ], [
        $mkChild(['first_name' => 'اول']),
        $mkChild(['first_name' => 'دوم', 'classroom_id' => 999]), // FK failure on enrolment
    ]);
} catch (Throwable) { $rolledBack = true; }
testAssert($rolledBack, 'tx rollback: invalid classroom raises failure');
testAssert((int) $pdo->query('SELECT COUNT(*) FROM parents')->fetchColumn() === $parentsBefore, 'tx rollback: parent was NOT kept');
testAssert((int) $pdo->query('SELECT COUNT(*) FROM children')->fetchColumn() === $childrenBefore, 'tx rollback: children were NOT kept');

// Standalone child creation for an existing parent.
$singleId = adminFamilyCreateChild($pdo, $parentId, $mkChild(['first_name' => 'تکی']), null);
testAssert($singleId > 0, 'add-child: standalone child created for existing parent');
testAssert((int) $pdo->query("SELECT COUNT(*) FROM child_classroom WHERE child_id = {$singleId}")->fetchColumn() === 1, 'add-child: active standalone child enrolled');

// Edit-parent uniqueness excludes self.
testAssert(adminFamilyParentPhoneExists($pdo, '09123456789', $parentId) === false, 'edit: own phone passes (excluded self)');
testAssert(adminFamilyParentPhoneExists($pdo, '09123456789', $p2) === true, 'edit: another parent phone still conflicts');
testAssert(adminFamilyParentEmailExists($pdo, 'dup@example.com', $p3) === false, 'edit: own email passes (excluded self)');
testAssert(adminFamilyParentEmailExists($pdo, 'dup@example.com', $parentId) === true, 'edit: another parent email still conflicts');

// ── E. Permissions matrix for the new pages ────────────────────────────────
foreach (['admin/add-parent.php', 'admin/edit-parent.php', 'admin/add-child.php'] as $page) {
    testAssert(isPageAllowedForRole($page, 'owner') === true, "matrix: owner allowed {$page}");
    testAssert(isPageAllowedForRole($page, 'manager') === true, "matrix: manager allowed {$page}");
    testAssert(isPageAllowedForRole($page, 'receptionist') === true, "matrix: receptionist allowed {$page}");
    testAssert(isPageAllowedForRole($page, 'accountant') === false, "matrix: accountant denied {$page}");
    testAssert(is_file($projectRoot . '/' . $page), "matrix: page exists on disk {$page}");
}

exit(testSummary('admin-parents-test'));

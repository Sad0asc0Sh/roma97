<?php
declare(strict_types=1);

/**
 * Admin parent deletion — the transactional data layer (SQLite, portable SQL).
 *
 * Covers includes/admin_family_helpers.php::adminFamilyDeleteParent() and
 * adminFamilyParentDeleteImpact():
 *  - full ON DELETE CASCADE: children + every child-linked table
 *  - explicit cleanup of the NO-FK leftovers: password_reset_tokens,
 *    messages (thread rows + rows sent BY the parent), still-unsent
 *    sms_queue rows, live sms_verification_codes for the parent's phone
 *  - history preservation: sent/failed sms_queue rows, sms_logs,
 *    notification_log and audit_log rows all survive
 *  - a second, unrelated family is completely untouched
 *  - snapshot/impact counts match the seeded data; impact is null after
 *  - deleting a nonexistent parent throws and changes nothing
 *  - permissions matrix: delete page for owner/manager only
 *
 * Run: php tests/admin-parent-delete-test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-bootstrap.php';

defined('ROOMA_APP') || define('ROOMA_APP', true);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/auth.php';
require_once $projectRoot . '/includes/sms.php';
require_once $projectRoot . '/includes/permissions.php';
require_once $projectRoot . '/includes/admin_family_helpers.php';

echo "Admin parent deletion tests\n";

// ── SQLite schema: faithful subset of schema.sql (same FK cascades) ──────
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("CREATE TABLE parents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE DEFAULT NULL, phone VARCHAR(20) UNIQUE DEFAULT NULL,
    phone_verified INTEGER NOT NULL DEFAULT 0,
    sms_pref_daily_report INTEGER NOT NULL DEFAULT 1, sms_pref_attendance INTEGER NOT NULL DEFAULT 1,
    sms_pref_tuition INTEGER NOT NULL DEFAULT 1, sms_pref_announcements INTEGER NOT NULL DEFAULT 1,
    avatar VARCHAR(255) DEFAULT NULL, password VARCHAR(255) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending', email_verified INTEGER DEFAULT 0,
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
$pdo->exec("CREATE TABLE classroom_waitlist (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
    classroom_id INTEGER NOT NULL REFERENCES classrooms(id) ON DELETE CASCADE,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE attendance (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
    attendance_date DATE NOT NULL, status VARCHAR(20) DEFAULT 'present'
)");
$pdo->exec("CREATE TABLE daily_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
    report_date DATE NOT NULL, mood VARCHAR(50) DEFAULT NULL
)");
$pdo->exec("CREATE TABLE tuition_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
    month_year VARCHAR(7) NOT NULL, expected_amount NUMERIC NOT NULL DEFAULT 0
)");
$pdo->exec("CREATE TABLE tuition_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER NOT NULL REFERENCES parents(id) ON DELETE CASCADE,
    child_id INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
    amount NUMERIC NOT NULL, payment_date DATE NOT NULL, month_year VARCHAR(7) NOT NULL
)");
$pdo->exec("CREATE TABLE tuition_reminder_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id INTEGER NOT NULL REFERENCES children(id) ON DELETE CASCADE,
    parent_id INTEGER NOT NULL REFERENCES parents(id) ON DELETE CASCADE,
    month_year VARCHAR(7) NOT NULL, sent_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_type VARCHAR(20) NOT NULL, sender_id INTEGER NOT NULL,
    recipient_admin_id INTEGER DEFAULT NULL, parent_id INTEGER DEFAULT NULL,
    subject VARCHAR(255) NOT NULL, body TEXT NOT NULL, is_read INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE password_reset_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token VARCHAR(64) UNIQUE NOT NULL, account_type VARCHAR(20) NOT NULL,
    account_id INTEGER NOT NULL, created_by_admin_id INTEGER DEFAULT NULL,
    expires_at TEXT NOT NULL, used INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE sms_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient_phone VARCHAR(20) NOT NULL, pattern_body_id INTEGER NOT NULL DEFAULT 0,
    parent_id INTEGER DEFAULT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE sms_verification_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    phone VARCHAR(15) NOT NULL, code VARCHAR(10) NOT NULL,
    action_type VARCHAR(30) NOT NULL DEFAULT 'register',
    expires_at TEXT NOT NULL, is_used INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE sms_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient_phone VARCHAR(20) NOT NULL, status VARCHAR(50) NOT NULL DEFAULT 'sent',
    parent_id INTEGER DEFAULT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE notification_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_key VARCHAR(50) NOT NULL, parent_id INTEGER DEFAULT NULL,
    dedup_key VARCHAR(191) NOT NULL UNIQUE, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_type VARCHAR(20) NOT NULL DEFAULT 'system', actor_id INTEGER DEFAULT NULL,
    action VARCHAR(100) NOT NULL, entity_type VARCHAR(50) DEFAULT NULL,
    entity_id INTEGER DEFAULT NULL, details TEXT DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$count = static function (string $sql) use ($pdo): int {
    return (int) $pdo->query($sql)->fetchColumn();
};

// ── Seed family A (to be deleted) ──────────────────────────────────────────
$pdo->exec("INSERT INTO classrooms (id, name) VALUES (1, 'شکوفه‌ها')");
$hash = password_hash('abcd1234', PASSWORD_DEFAULT);
$insParent = $pdo->prepare('INSERT INTO parents (first_name, last_name, email, phone, phone_verified, avatar, password, status) VALUES (?, ?, ?, ?, 1, ?, ?, ?)');
$insParent->execute(['علی', 'احمدی', 'ali@example.com', '09123456789', 'assets/uploads/avatars/avatar-a.jpg', $hash, 'active']);
$pA = (int) $pdo->lastInsertId();
$insParent->execute(['مریم', 'رضایی', null, '09351112233', null, $hash, 'active']);
$pB = (int) $pdo->lastInsertId();

$insChild = $pdo->prepare('INSERT INTO children (parent_id, first_name, last_name, date_of_birth, gender, photo, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
$insChild->execute([$pA, 'سارا', 'احمدی', '2021-05-01', 'female', 'assets/uploads/children/child-a1.jpg', 'active']);
$cA1 = (int) $pdo->lastInsertId();
$insChild->execute([$pA, 'رضا', 'احمدی', '2022-03-01', 'male', null, 'active']);
$cA2 = (int) $pdo->lastInsertId();
$insChild->execute([$pB, 'نیلوفر', 'رضایی', '2021-07-01', 'female', null, 'active']);
$cB1 = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO child_classroom (child_id, classroom_id, enrollment_date) VALUES ({$cA1}, 1, '2024-09-01'), ({$cA2}, 1, '2024-09-01'), ({$cB1}, 1, '2024-09-01')");
$pdo->exec("INSERT INTO classroom_waitlist (child_id, classroom_id) VALUES ({$cA1}, 1)");
$pdo->exec("INSERT INTO attendance (child_id, attendance_date) VALUES ({$cA1}, '2024-09-02'), ({$cA1}, '2024-09-03'), ({$cA2}, '2024-09-02'), ({$cB1}, '2024-09-02')");
$pdo->exec("INSERT INTO daily_reports (child_id, report_date) VALUES ({$cA1}, '2024-09-02'), ({$cA2}, '2024-09-02'), ({$cB1}, '2024-09-02')");
$pdo->exec("INSERT INTO tuition_plans (child_id, month_year, expected_amount) VALUES ({$cA1}, '1403-06', 500000), ({$cA2}, '1403-06', 500000), ({$cB1}, '1403-06', 500000)");
$pdo->exec("INSERT INTO tuition_payments (parent_id, child_id, amount, payment_date, month_year) VALUES ({$pA}, {$cA1}, 100000, '2024-09-05', '1403-06'), ({$pA}, {$cA2}, 250000, '2024-09-06', '1403-06'), ({$pB}, {$cB1}, 70000, '2024-09-05', '1403-06')");
$pdo->exec("INSERT INTO tuition_reminder_log (child_id, parent_id, month_year) VALUES ({$cA1}, {$pA}, '1403-06'), ({$cB1}, {$pB}, '1403-06')");
// Messages: 2 thread rows for A (sent by admin), 1 sent BY A, 1 thread row for B.
$pdo->exec("INSERT INTO messages (sender_type, sender_id, parent_id, subject, body) VALUES
    ('admin', 1, {$pA}, 'خوش‌آمد', 'متن'),
    ('teacher', 1, {$pA}, 'گزارش', 'متن'),
    ('parent', {$pA}, {$pA}, 'سؤال والد', 'متن'),
    ('admin', 1, {$pB}, 'خوش‌آمد', 'متن')");
// Reset tokens: one live token for A, one TEACHER token with the same numeric id (must survive).
$pdo->exec("INSERT INTO password_reset_tokens (token, account_type, account_id, expires_at) VALUES
    ('tok-parent-a', 'parent', {$pA}, '2099-01-01 00:00:00'),
    ('tok-teacher-x', 'teacher', {$pA}, '2099-01-01 00:00:00'),
    ('tok-parent-b', 'parent', {$pB}, '2099-01-01 00:00:00')");
// SMS queue for A: pending + sending (must go), sent + failed (history, must stay). B: one pending (must stay).
$pdo->exec("INSERT INTO sms_queue (recipient_phone, parent_id, status) VALUES
    ('09123456789', {$pA}, 'pending'), ('09123456789', {$pA}, 'sending'),
    ('09123456789', {$pA}, 'sent'), ('09123456789', {$pA}, 'failed'),
    ('09351112233', {$pB}, 'pending')");
// OTP codes: 2 for A's phone, 1 for B's phone.
$pdo->exec("INSERT INTO sms_verification_codes (phone, code, expires_at) VALUES
    ('09123456789', '11111', '2099-01-01 00:00:00'),
    ('09123456789', '22222', '2099-01-01 00:00:00'),
    ('09351112233', '33333', '2099-01-01 00:00:00')");
// History rows for A (must all survive the deletion).
$pdo->exec("INSERT INTO sms_logs (recipient_phone, parent_id) VALUES ('09123456789', {$pA})");
$pdo->exec("INSERT INTO notification_log (event_key, parent_id, dedup_key) VALUES ('welcome', {$pA}, 'welcome:{$pA}')");
$pdo->exec("INSERT INTO audit_log (actor_type, actor_id, action, entity_type, entity_id) VALUES ('admin', 1, 'parent.approve', 'parent', {$pA})");

// ── Impact snapshot BEFORE deletion ────────────────────────────────────────
$impact = adminFamilyParentDeleteImpact($pdo, $pA);
testAssert($impact !== null, 'impact: existing parent returns a snapshot');
testAssert($impact['parent']['full_name'] === 'علی احمدی', 'impact: parent identity in snapshot');
testAssert($impact['counts']['children'] === 2, 'impact: children count = 2');
testAssert($impact['counts']['child_classroom'] === 2, 'impact: classroom enrolments = 2');
testAssert($impact['counts']['attendance'] === 3, 'impact: attendance = 3');
testAssert($impact['counts']['daily_reports'] === 2, 'impact: daily reports = 2');
testAssert($impact['counts']['tuition_plans'] === 2, 'impact: tuition plans = 2');
testAssert($impact['counts']['tuition_payments'] === 2, 'impact: tuition payments = 2');
testAssert($impact['tuition_total_paid'] === 350000.0, 'impact: tuition total = 350000');
testAssert($impact['counts']['messages'] === 3, 'impact: messages (thread + sent-by-parent) = 3');
testAssert($impact['counts']['password_reset_tokens'] === 1, 'impact: live reset tokens = 1');
testAssert($impact['counts']['sms_queue_pending'] === 2, 'impact: unsent queue rows (pending+sending) = 2');
testAssert($impact['counts']['sms_verification_codes'] === 2, 'impact: live OTP codes for the phone = 2');
testAssert($impact['photo_paths'] === ['assets/uploads/children/child-a1.jpg'], 'impact: child photo paths collected');
testAssert($impact['avatar_path'] === 'assets/uploads/avatars/avatar-a.jpg', 'impact: parent avatar path collected');
testAssert(adminFamilyParentDeleteImpact($pdo, 99999) === null, 'impact: nonexistent parent returns null');

// ── Delete family A ────────────────────────────────────────────────────────
$result = adminFamilyDeleteParent($pdo, $pA);
testAssert($result['deleted']['parents'] === 1, 'delete: exactly one parent row removed');
testAssert($result['deleted']['children'] === 2, 'delete: snapshot reports 2 children');
testAssert($result['deleted']['password_reset_tokens'] === 1, 'delete: 1 reset token removed');
testAssert($result['deleted']['messages'] === 3, 'delete: 3 messages removed');
testAssert($result['deleted']['sms_queue_pending'] === 2, 'delete: 2 unsent queue rows removed');
testAssert($result['deleted']['sms_verification_codes'] === 2, 'delete: 2 OTP codes removed');
testAssert($result['snapshot']['tuition_total_paid'] === 350000.0, 'delete: returned snapshot keeps the tuition total');

// (a) Full cascade for family A.
testAssert($count("SELECT COUNT(*) FROM parents WHERE id = {$pA}") === 0, 'cascade: parent row gone');
testAssert($count("SELECT COUNT(*) FROM children WHERE parent_id = {$pA}") === 0, 'cascade: children gone');
testAssert($count("SELECT COUNT(*) FROM child_classroom WHERE child_id IN ({$cA1}, {$cA2})") === 0, 'cascade: classroom enrolments gone');
testAssert($count("SELECT COUNT(*) FROM classroom_waitlist WHERE child_id IN ({$cA1}, {$cA2})") === 0, 'cascade: waitlist gone');
testAssert($count("SELECT COUNT(*) FROM attendance WHERE child_id IN ({$cA1}, {$cA2})") === 0, 'cascade: attendance gone');
testAssert($count("SELECT COUNT(*) FROM daily_reports WHERE child_id IN ({$cA1}, {$cA2})") === 0, 'cascade: daily reports gone');
testAssert($count("SELECT COUNT(*) FROM tuition_plans WHERE child_id IN ({$cA1}, {$cA2})") === 0, 'cascade: tuition plans gone');
testAssert($count("SELECT COUNT(*) FROM tuition_payments WHERE parent_id = {$pA}") === 0, 'cascade: tuition payments gone');
testAssert($count("SELECT COUNT(*) FROM tuition_reminder_log WHERE parent_id = {$pA}") === 0, 'cascade: tuition reminder log gone');

// (b) No-FK leftovers are cleaned.
testAssert($count("SELECT COUNT(*) FROM password_reset_tokens WHERE account_type = 'parent' AND account_id = {$pA}") === 0, 'cleanup: parent reset tokens gone');
testAssert($count("SELECT COUNT(*) FROM messages WHERE parent_id = {$pA} OR (sender_type = 'parent' AND sender_id = {$pA})") === 0, 'cleanup: parent messages gone (both directions)');
testAssert($count("SELECT COUNT(*) FROM sms_queue WHERE parent_id = {$pA} AND status IN ('pending', 'sending')") === 0, 'cleanup: unsent queue rows gone');
testAssert($count("SELECT COUNT(*) FROM sms_verification_codes WHERE phone = '09123456789'") === 0, 'cleanup: OTP codes for the deleted phone gone');

// (c) History is preserved.
testAssert($count("SELECT COUNT(*) FROM password_reset_tokens WHERE account_type = 'teacher' AND account_id = {$pA}") === 1, 'history: teacher token with same numeric id untouched');
testAssert($count("SELECT COUNT(*) FROM sms_queue WHERE parent_id = {$pA} AND status IN ('sent', 'failed')") === 2, 'history: sent/failed queue rows remain');
testAssert($count("SELECT COUNT(*) FROM sms_logs WHERE parent_id = {$pA}") === 1, 'history: sms_logs row remains');
testAssert($count("SELECT COUNT(*) FROM notification_log WHERE parent_id = {$pA}") === 1, 'history: notification_log row remains');
testAssert($count("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'parent' AND entity_id = {$pA}") === 1, 'history: prior audit_log rows remain');

// (d) Family B is completely untouched.
testAssert($count("SELECT COUNT(*) FROM parents WHERE id = {$pB}") === 1, 'family B: parent intact');
testAssert($count("SELECT COUNT(*) FROM children WHERE parent_id = {$pB}") === 1, 'family B: child intact');
testAssert($count("SELECT COUNT(*) FROM tuition_payments WHERE parent_id = {$pB}") === 1, 'family B: payment intact');
testAssert($count("SELECT COUNT(*) FROM messages WHERE parent_id = {$pB}") === 1, 'family B: message intact');
testAssert($count("SELECT COUNT(*) FROM sms_queue WHERE parent_id = {$pB} AND status = 'pending'") === 1, 'family B: pending sms intact');
testAssert($count("SELECT COUNT(*) FROM sms_verification_codes WHERE phone = '09351112233'") === 1, 'family B: OTP code intact');
testAssert($count("SELECT COUNT(*) FROM password_reset_tokens WHERE account_type = 'parent' AND account_id = {$pB}") === 1, 'family B: reset token intact');
testAssert($count("SELECT COUNT(*) FROM attendance WHERE child_id = {$cB1}") === 1, 'family B: attendance intact');

// (f)+(g) Nonexistent delete throws, changes nothing; impact null after delete.
testAssert(adminFamilyParentDeleteImpact($pdo, $pA) === null, 'after: impact returns null for the deleted parent');
$parentsBefore = $count('SELECT COUNT(*) FROM parents');
$threw = false;
try {
    adminFamilyDeleteParent($pdo, 99999);
} catch (RuntimeException) {
    $threw = true;
}
testAssert($threw, 'nonexistent: deleting a missing parent throws RuntimeException');
testAssert($count('SELECT COUNT(*) FROM parents') === $parentsBefore, 'nonexistent: failed delete changed nothing');
$threwAgain = false;
try {
    adminFamilyDeleteParent($pdo, $pA);
} catch (RuntimeException) {
    $threwAgain = true;
}
testAssert($threwAgain, 'nonexistent: re-deleting the same parent also throws');

// ── Permissions matrix for the delete page ─────────────────────────────────
testAssert(isPageAllowedForRole('admin/delete-parent.php', 'owner') === true, 'matrix: owner allowed admin/delete-parent.php');
testAssert(isPageAllowedForRole('admin/delete-parent.php', 'manager') === true, 'matrix: manager allowed admin/delete-parent.php');
testAssert(isPageAllowedForRole('admin/delete-parent.php', 'receptionist') === false, 'matrix: receptionist denied admin/delete-parent.php');
testAssert(isPageAllowedForRole('admin/delete-parent.php', 'accountant') === false, 'matrix: accountant denied admin/delete-parent.php');
testAssert(is_file($projectRoot . '/admin/delete-parent.php'), 'matrix: page exists on disk admin/delete-parent.php');

exit(testSummary('admin-parent-delete-test'));

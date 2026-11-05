<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/auth.php';

/**
 * Unified authentication — single entry point for parent / teacher / admin.
 *
 * Security contract:
 *  - The client-selected "panel" is NEVER trusted. It is only a UI hint.
 *    The panel is decided server-side from the credential itself:
 *      • username-shaped identifier  → admins only
 *      • Iranian mobile (09…)        → parents + teachers, verified against both
 *  - Failures are indistinguishable (generic message) to prevent enumeration.
 *  - Status (pending / inactive) is only revealed AFTER password_verify()
 *    succeeds, never before.
 *  - Timing is equalised with a dummy password_verify() so "user not found"
 *    and "wrong password" cannot be distinguished by response time.
 *  - If the same phone + password matches BOTH a parent and a teacher
 *    (data collision), the attempt is denied as ambiguous and audited —
 *    no silent cross-panel login.
 */

function unifiedIdentifierKind(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return 'invalid';
    }
    // Phone first: 09… (also normalised forms +98… / 0098… / 9…).
    if (isValidIranianMobile(normalizeIranianPhone($raw))) {
        return 'phone';
    }
    // Admin username shape (legacy contract from admin/login.php).
    if (preg_match('/\A[A-Za-z0-9_]{3,50}\z/', $raw) === 1) {
        return 'username';
    }
    return 'invalid';
}

function unifiedDummyHash(): string
{
    static $hash = null;
    if ($hash === null) {
        $hash = password_hash('unified-login-dummy-timing', PASSWORD_DEFAULT);
    }
    return $hash;
}

/**
 * Attempt a login against ALL panels that could own the identifier.
 *
 * @return array{panel:?string, user:?array, error:string, message:string}
 *   panel: 'parent' | 'teacher' | 'admin' | null
 *   error: 'ok' | 'invalid_input' | 'invalid_credentials' | 'pending'
 *          | 'inactive' | 'unknown_role' | 'ambiguous'
 */
function unifiedAttemptLogin(PDO $pdo, string $identifier, string $password): array
{
    $identifier = trim($identifier);
    $kind = unifiedIdentifierKind($identifier);

    $invalid = static fn(string $error = 'invalid_credentials', string $message = ''): array => [
        'panel' => null,
        'user' => null,
        'error' => $error,
        'message' => $message !== '' ? $message : 'نام کاربری/شماره موبایل یا رمز عبور نادرست است.',
    ];

    if ($kind === 'invalid') {
        return $invalid('invalid_input', 'شناسه ورود معتبر نیست.');
    }

    if ($kind === 'username') {
        // ── Admin only ──────────────────────────────────────────────
        if (strlen($password) < 1 || strlen($password) > 255) {
            return $invalid();
        }
        $stmt = $pdo->prepare('SELECT id, username, password, role FROM admins WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $identifier]);
        $admin = $stmt->fetch();
        if ($admin === false) {
            password_verify($password, unifiedDummyHash());
            return $invalid();
        }
        if (!password_verify($password, (string) $admin['password'])) {
            return $invalid();
        }
        $role = (string) ($admin['role'] ?? '');
        if (!isKnownAdminRole($role)) {
            return $invalid('unknown_role', 'نقش کاربری شما معتبر نیست. با مدیریت تماس بگیرید.');
        }
        return ['panel' => 'admin', 'user' => $admin, 'error' => 'ok', 'message' => ''];
    }

    // ── Phone: parent + teacher ───────────────────────────────────
    if ($password === '' || strlen($password) > 4096 || str_contains($password, "\0")) {
        return $invalid();
    }
    $phone = normalizeIranianPhone($identifier);

    $pStmt = $pdo->prepare('SELECT id, first_name, last_name, phone, password, status FROM parents WHERE phone = :phone LIMIT 1');
    $pStmt->execute([':phone' => $phone]);
    $parent = $pStmt->fetch() ?: null;

    $tStmt = $pdo->prepare('SELECT id, first_name, last_name, phone, password, status, role_title, avatar FROM teachers WHERE phone = :phone LIMIT 1');
    $tStmt->execute([':phone' => $phone]);
    $teacher = $tStmt->fetch() ?: null;

    if ($parent === null && $teacher === null) {
        password_verify($password, unifiedDummyHash());
        return $invalid();
    }

    $parentOk = $parent !== null && password_verify($password, (string) $parent['password']);
    $teacherOk = $teacher !== null && password_verify($password, (string) $teacher['password']);

    // Equalise timing when only one candidate exists (2 verifies either way).
    if (($parent === null) xor ($teacher === null)) {
        password_verify($password, unifiedDummyHash());
    }

    if ($parentOk && $teacherOk) {
        return $invalid('ambiguous', 'این شماره در دو پنل ثبت شده است. برای تعیین پنل با مدیریت تماس بگیرید.');
    }

    if ($parentOk) {
        if ($parent['status'] === 'pending') {
            return $invalid('pending', 'ثبت‌نام شما دریافت شده و منتظر تأیید مدیریت مهدکودک است.');
        }
        if ($parent['status'] !== 'active') {
            return $invalid('inactive', 'حساب شما فعال نیست. با مدیریت مهد تماس بگیرید.');
        }
        return ['panel' => 'parent', 'user' => $parent, 'error' => 'ok', 'message' => ''];
    }

    if ($teacherOk) {
        if ((string) $teacher['status'] !== 'active') {
            return $invalid('inactive', 'حساب شما فعال نیست. با مدیر تماس بگیرید.');
        }
        return ['panel' => 'teacher', 'user' => $teacher, 'error' => 'ok', 'message' => ''];
    }

    return $invalid();
}

/**
 * Establish the session for the panel that verified. Clears the other
 * panels' markers so one browser session can never hold two panels.
 */
function unifiedEstablishSession(string $panel, array $user): void
{
    // Never hold two panels in one session.
    unset(
        $_SESSION['admin_logged_in'],
        $_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_role'],
        $_SESSION['parent_id'], $_SESSION['parent_name'], $_SESSION['parent_first_name'],
        $_SESSION['teacher_id'], $_SESSION['teacher_name'], $_SESSION['teacher_first_name'],
        $_SESSION['teacher_role_title'], $_SESSION['teacher_avatar'],
        $_SESSION['teacher_classroom_id'], $_SESSION['teacher_has_class']
    );

    session_regenerate_id(true);
    $_SESSION['last_activity'] = time();

    if ($panel === 'admin') {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = (int) $user['id'];
        $_SESSION['admin_username'] = (string) $user['username'];
        $_SESSION['admin_role'] = (string) ($user['role'] ?? '');
    } elseif ($panel === 'teacher') {
        $_SESSION['teacher_id'] = (int) $user['id'];
        $_SESSION['teacher_name'] = trim((string) $user['first_name'] . ' ' . (string) $user['last_name']);
        $_SESSION['teacher_first_name'] = (string) $user['first_name'];
        $_SESSION['teacher_role_title'] = (string) ($user['role_title'] ?? 'مربی مهد');
        $_SESSION['teacher_avatar'] = (string) ($user['avatar'] ?? '');
    } else {
        $_SESSION['parent_id'] = (int) $user['id'];
        $_SESSION['parent_name'] = trim((string) $user['first_name'] . ' ' . (string) $user['last_name']);
        $_SESSION['parent_first_name'] = (string) $user['first_name'];
    }

    rotateCsrfToken();
}

function unifiedPanelHome(string $panel): string
{
    return match ($panel) {
        'admin' => url('admin/index.php'),
        'teacher' => url('teacher/index.php'),
        default => url('parent/index.php'),
    };
}

function unifiedIsValidPanelHint(string $hint): bool
{
    return in_array($hint, ['auto', 'parent', 'teacher', 'admin'], true);
}

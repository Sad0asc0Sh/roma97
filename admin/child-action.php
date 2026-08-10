<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

/**
 * @return non-empty-string
 */
function adminChildActionRedirect(string $fallback): string
{
    $candidates = [
        $_POST['redirect'] ?? null,
        $_SERVER['HTTP_REFERER'] ?? null,
    ];

    $siteParts = parse_url(SITE_URL);
    $siteHost = $siteParts['host'] ?? '';

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }

        $refParts = parse_url($candidate);
        if ($refParts === false || ($refParts['scheme'] ?? '') === '') {
            continue;
        }

        if (($refParts['host'] ?? '') !== $siteHost) {
            continue;
        }

        $basePath = rtrim($siteParts['path'] ?? '', '/');
        $refPath = $refParts['path'] ?? '/';

        if ($basePath !== '' && !str_starts_with($refPath, $basePath . '/') && $refPath !== $basePath) {
            continue;
        }

        $rel = $basePath !== ''
            ? (string) preg_replace('#\A' . preg_quote($basePath, '#') . '/?#', '', $refPath)
            : ltrim($refPath, '/');

        if (
            preg_match('#\Aadmin/children\.php(\?.*)?\z#', $rel) === 1
            || preg_match('#\Aadmin/child-detail\.php(\?.*)?\z#', $rel) === 1
        ) {
            return $candidate;
        }
    }

    return $fallback;
}

if (!isPostRequest()) {
    redirect(url('admin/children.php'));
}

$csrfToken = $_POST['csrf_token'] ?? '';
$childIdRaw = $_POST['child_id'] ?? null;
$childId = filter_var($childIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$childId = is_int($childId) ? $childId : 0;
$action = (string) ($_POST['action'] ?? '');
$destination = adminChildActionRedirect(url('admin/children.php'));

if (!validateCsrfToken($csrfToken)) {
    setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
    redirect($destination);
}

$statusUpdates = [
    'approve'   => 'active',
    'activate'  => 'active',
    'reject'    => 'inactive',
    'deactivate'=> 'inactive',
    'graduate'  => 'graduated',
    'withdraw'  => 'withdrawn',
];

if ($action === 'add_waitlist') {
    $classroomIdRaw = $_POST['classroom_id'] ?? null;
    $classroomId = filter_var($classroomIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $classroomId = is_int($classroomId) ? $classroomId : 0;

    if ($childId === 0 || $classroomId === 0) {
        setFlash('error', 'افزودن به لیست انتظار امکان‌پذیر نیست.');
        redirect($destination);
    }

    try {
        require_once __DIR__ . '/../includes/db.php';
        initializeFinancialTables();
        $pdo = getDb();

        $insWl = $pdo->prepare(
            'INSERT INTO classroom_waitlist (child_id, classroom_id) VALUES (:cid, :clid)
             ON DUPLICATE KEY UPDATE requested_at = CURRENT_TIMESTAMP'
        );
        $insWl->execute([':cid' => $childId, ':clid' => $classroomId]);
        recordAudit('classroom.add_waitlist', 'child', (int) $childId, ['classroom_id' => $classroomId]);
        setFlash('success', 'کودک با موفقیت به لیست انتظار این کلاس اضافه شد.');
    } catch (Throwable $e) {
        error_log($e->getMessage());
        setFlash('error', 'افزودن به لیست انتظار با مشکل مواجه شد.');
    }
    redirect($destination);
}

if ($action === 'assign_classroom') {
    $classroomIdRaw = $_POST['classroom_id'] ?? null;
    $classroomId = filter_var($classroomIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $classroomId = is_int($classroomId) ? $classroomId : null;

    if ($childId === 0 || $classroomId === null) {
        setFlash('error', 'تکمیل اختصاص کلاس امکان‌پذیر نیست.');
        redirect($destination);
    }

    $forceOverCapacity = isset($_POST['force_over_capacity']) && (string) $_POST['force_over_capacity'] === '1';

    try {
        require_once __DIR__ . '/../includes/db.php';
        initializeTeachersTables();
        $pdo = getDb();

        if ($classroomId > 0) {
            // Verify classroom exists & check capacity
            $clStmt = $pdo->prepare('SELECT capacity FROM classrooms WHERE id = :clid LIMIT 1');
            $clStmt->execute([':clid' => $classroomId]);
            $clRow = $clStmt->fetch();

            if (!$clRow) {
                setFlash('error', 'کلاس انتخاب‌شده معتبر نیست.');
                redirect($destination);
            }

            $capacity = (int) $clRow['capacity'];

            // Count current enrolled children excluding the current child
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM child_classroom WHERE classroom_id = :clid AND child_id != :cid');
            $countStmt->execute([':clid' => $classroomId, ':cid' => $childId]);
            $enrolledCount = (int) $countStmt->fetchColumn();

            if ($enrolledCount >= $capacity && !$forceOverCapacity) {
                $_SESSION['waitlist_offer'] = [
                    'child_id' => $childId,
                    'classroom_id' => $classroomId,
                ];
                setFlash('error', "ظرفیت این کلاس تکمیل است ({$enrolledCount} از {$capacity} نفر). می‌توانید کودک را به لیست انتظار اضافه کنید یا تیک تخصیص خارج از ظرفیت را بزنید.");
                redirect($destination);
            }
        }

        $pdo->beginTransaction();

        $del = $pdo->prepare('DELETE FROM child_classroom WHERE child_id = :cid');
        $del->execute([':cid' => $childId]);

        if ($classroomId > 0) {
            // enrollment_date is NOT NULL with no default — must be supplied explicitly.
            $ins = $pdo->prepare(
                'INSERT INTO child_classroom (child_id, classroom_id, enrollment_date)
                 VALUES (:cid, :clid, CURDATE())'
            );
            $ins->execute([':cid' => $childId, ':clid' => $classroomId]);
        }

        $pdo->commit();
        $auditDetails = ['classroom_id' => $classroomId];
        if ($forceOverCapacity) {
            $auditDetails['over_capacity'] = true;
        }
        recordAudit('child.assign_classroom', 'child', (int) $childId, $auditDetails);
        setFlash('success', 'اختصاص کلاس با موفقیت ذخیره شد.');
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        setFlash('error', 'ذخیره اختصاص کلاس امکان‌پذیر نیست. لطفاً دوباره تلاش کنید.');
    }

    redirect($destination);
}

if ($childId === 0 || !array_key_exists($action, $statusUpdates)) {
    setFlash('error', 'تکمیل این عملیات ثبت‌نام امکان‌پذیر نیست.');
    redirect($destination);
}

$newStatus = $statusUpdates[$action];

try {
    initializeParentTables();
    $pdo = getDb();
    $exists = $pdo->prepare('SELECT id FROM children WHERE id = :id LIMIT 1');
    $exists->execute([':id' => $childId]);

    if ($exists->fetchColumn() === false) {
        setFlash('error', 'سابقه این کودک پیدا نشد.');
        redirect($destination);
    }

    $statement = $pdo->prepare(
        'UPDATE children SET status = :status WHERE id = :id'
    );
    $statement->execute([
        ':status' => $newStatus,
        ':id' => $childId,
    ]);

    $messages = [
        'approve'   => 'ثبت‌نام تأیید شد. کودک اکنون فعال است.',
        'activate'  => 'وضعیت به فعال تغییر کرد.',
        'reject'    => 'ثبت‌نام رد شد. وضعیت به غیرفعال تغییر کرد.',
        'deactivate'=> 'وضعیت به غیرفعال تغییر کرد.',
        'graduate'  => 'وضعیت کودک به فارغ‌التحصیل تغییر یافت.',
        'withdraw'  => 'وضعیت کودک به انصراف‌داده تغییر یافت.',
    ];
    recordAudit('child.' . $action, 'child', (int) $childId, ['status' => $newStatus]);
    setFlash('success', $messages[$action]);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    setFlash('error', 'به‌روزرسانی ثبت‌نام امکان‌پذیر نیست. لطفاً دوباره تلاش کنید.');
}

redirect($destination);

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
    'approve' => 'active',
    'activate' => 'active',
    'reject' => 'inactive',
    'deactivate' => 'inactive',
];

if ($action === 'assign_classroom') {
    $classroomIdRaw = $_POST['classroom_id'] ?? null;
    $classroomId = filter_var($classroomIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $classroomId = is_int($classroomId) ? $classroomId : null;

    if ($childId === 0 || $classroomId === null) {
        setFlash('error', 'تکمیل اختصاص کلاس امکان‌پذیر نیست.');
        redirect($destination);
    }

    try {
        require_once __DIR__ . '/../includes/db.php';
        initializeTeachersTables();
        $pdo = getDb();
        
        $pdo->beginTransaction();

        $del = $pdo->prepare('DELETE FROM child_classroom WHERE child_id = :cid');
        $del->execute([':cid' => $childId]);

        if ($classroomId > 0) {
            // Verify the classroom exists before assigning (defensive: avoids an FK
            // error surfacing as a confusing generic message if an invalid id is posted).
            $existsCl = $pdo->prepare('SELECT id FROM classrooms WHERE id = :clid LIMIT 1');
            $existsCl->execute([':clid' => $classroomId]);

            if ($existsCl->fetchColumn() === false) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                setFlash('error', 'کلاس انتخاب‌شده معتبر نیست.');
                redirect($destination);
            }

            // enrollment_date is NOT NULL with no default — must be supplied explicitly.
            $ins = $pdo->prepare(
                'INSERT INTO child_classroom (child_id, classroom_id, enrollment_date)
                 VALUES (:cid, :clid, CURDATE())'
            );
            $ins->execute([':cid' => $childId, ':clid' => $classroomId]);
        }

        $pdo->commit();
        recordAudit('child.assign_classroom', 'child', (int) $childId, ['classroom_id' => $classroomId]);
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
        'approve' => 'ثبت‌نام تأیید شد. کودک اکنون فعال است.',
        'activate' => 'وضعیت به فعال تغییر کرد.',
        'reject' => 'ثبت‌نام رد شد. وضعیت به غیرفعال تغییر کرد.',
        'deactivate' => 'وضعیت به غیرفعال تغییر کرد.',
    ];
    recordAudit('child.' . $action, 'child', (int) $childId, ['status' => $newStatus]);
    setFlash('success', $messages[$action]);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    setFlash('error', 'به‌روزرسانی ثبت‌نام امکان‌پذیر نیست. لطفاً دوباره تلاش کنید.');
}

redirect($destination);

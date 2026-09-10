<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/notifications.php';
// parentChildEnrollmentLabel() is used to name the new status in notifications.
require_once __DIR__ . '/../includes/parent_children_helpers.php';

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

    // Accept same-host redirects; the app path prefix is stripped so any host
    // the site is served under (not just SITE_URL's configured host) works.
    $siteBasePath = basePath();
    $selfHost = (string) parse_url((string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }

        $refParts = parse_url($candidate);
        if ($refParts === false || ($refParts['scheme'] ?? '') === '') {
            continue;
        }

        if (($refParts['host'] ?? '') !== $selfHost) {
            continue;
        }

        $basePath = $siteBasePath;
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
            // Verify classroom exists & check capacity. Only children actually
            // attending the class (status = active) count toward capacity —
            // graduated/withdrawn rows must not block new enrolments.
            $capacityRow = $pdo->prepare(
                "SELECT COUNT(*) AS enrolled
                 FROM child_classroom cc
                 INNER JOIN children c ON c.id = cc.child_id
                 WHERE cc.classroom_id = :clid AND c.status = 'active' AND cc.child_id != :cid"
            );
            $capacityRow->execute([':clid' => $classroomId, ':cid' => $childId]);
            $enrolledCount = (int) $capacityRow->fetch()['enrolled'];

            $capStmt = $pdo->prepare('SELECT capacity FROM classrooms WHERE id = :clid LIMIT 1');
            $capStmt->execute([':clid' => $classroomId]);
            $clRow = $capStmt->fetch();

            if (!$clRow) {
                setFlash('error', 'کلاس انتخاب‌شده معتبر نیست.');
                redirect($destination);
            }

            $capacity = (int) $clRow['capacity'];

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

    // Load the child together with the previous status and the parent, so we can
    // both skip no-op updates and personalise the notification.
    $exists = $pdo->prepare(
        'SELECT c.id, c.status, c.first_name, c.last_name, c.parent_id,
                p.first_name AS p_first, p.last_name AS p_last
         FROM children c
         INNER JOIN parents p ON p.id = c.parent_id
         WHERE c.id = :id LIMIT 1'
    );
    $exists->execute([':id' => $childId]);
    $childRow = $exists->fetch();

    if (!$childRow) {
        setFlash('error', 'سابقه این کودک پیدا نشد.');
        redirect($destination);
    }

    $previousStatus = (string) $childRow['status'];

    $statement = $pdo->prepare(
        'UPDATE children SET status = :status WHERE id = :id'
    );
    $statement->execute([
        ':status' => $newStatus,
        ':id' => $childId,
    ]);

    // A child leaving the daycare (rejected/inactive/graduated/withdrawn) can no
    // longer occupy a classroom seat — otherwise graduated ghosts quietly ate
    // capacity and the class showed as "full" with nobody present.
    if (in_array($newStatus, ['inactive', 'graduated', 'withdrawn'], true)) {
        try {
            $pdo->prepare('DELETE FROM child_classroom WHERE child_id = :cid')->execute([':cid' => $childId]);
        } catch (Throwable $cleanupError) {
            error_log('child-action seat cleanup failed: ' . $cleanupError->getMessage());
        }
    }


    $messages = [
        'approve'   => 'ثبت‌نام تأیید شد. کودک اکنون فعال است.',
        'activate'  => 'وضعیت به فعال تغییر کرد.',
        'reject'    => 'ثبت‌نام رد شد. وضعیت به غیرفعال تغییر کرد.',
        'deactivate'=> 'وضعیت به غیرفعال تغییر کرد.',
        'graduate'  => 'وضعیت کودک به فارغ‌التحصیل تغییر یافت.',
        'withdraw'  => 'وضعیت کودک به انصراف‌داده تغییر یافت.',
    ];
    recordAudit('child.' . $action, 'child', (int) $childId, [
        'status'          => $newStatus,
        'previous_status' => $previousStatus,
    ]);

    $flashMessage = $messages[$action];

    // Tell the family their child's enrolment status changed. Previously this
    // happened entirely silently — parents had to log in and notice by chance.
    if ($previousStatus !== $newStatus) {
        $childName  = trim((string) $childRow['first_name'] . ' ' . (string) $childRow['last_name']);
        $parentName = trim((string) $childRow['p_first'] . ' ' . (string) $childRow['p_last']);
        $statusLabel = parentChildEnrollmentLabel($newStatus);

        $portalBody = "{$parentName} عزیز؛\n\n"
            . "وضعیت ثبت‌نام «{$childName}» در مهدکودک " . siteName() . " به‌روزرسانی شد.\n\n"
            . "• وضعیت جدید: {$statusLabel}\n\n"
            . match ($newStatus) {
                'active'    => "از این پس گزارش روزانه، حضور و غیاب و وضعیت شهریه فرزندتان در پنل شما در دسترس است. 🌟\n",
                'graduated' => "از اینکه این مسیر قشنگ را با ما طی کردید سپاسگزاریم؛ برای دلبندتان آرزوی موفقیت داریم. 🎓\n",
                'withdrawn' => "امیدواریم به‌زودی دوباره در کنار شما باشیم. برای هرگونه هماهنگی با مدیریت در تماس باشید. 🌸\n",
                default     => "برای اطلاعات بیشتر لطفاً با مدیریت مهدکودک در تماس باشید.\n",
            }
            . "\nبا مهر،\nمدیریت مهدکودک " . siteName() . ' 💖';

        $statusResult = notifyParent((int) $childRow['parent_id'], 'child_status', [
            'args'           => [$parentName, $childName, $statusLabel],
            'portal_subject' => "به‌روزرسانی وضعیت ثبت‌نام {$childName}",
            'portal_body'    => $portalBody,
            'dedup_key'      => "child_status:{$childId}:{$newStatus}:" . date('Y-m-d'),
            'sender_id'      => (int) ($_SESSION['admin_id'] ?? 0),
            // Suspended/pending parents still deserve to hear about their child.
            'allow_inactive' => true,
        ]);

        smsQueueFlushInline($pdo);

        $summary = summarizeNotificationResults([$statusResult]);
        if ($summary !== '') {
            $flashMessage .= ' ' . $summary;
        }
    }

    setFlash('success', $flashMessage);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    setFlash('error', 'به‌روزرسانی ثبت‌نام امکان‌پذیر نیست. لطفاً دوباره تلاش کنید.');
}

redirect($destination);

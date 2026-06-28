<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

$currentAdminId = (int) ($_SESSION['admin_id'] ?? 0);

// ─── Upload helper ────────────────────────────────────────────────────────────
function handleTeacherUpload(string $fileKey, string $subDir): ?string
{
    if (!isset($_FILES[$fileKey]) || (int) $_FILES[$fileKey]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$fileKey];

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('بارگذاری ناموفق بود (کد خطا: ' . $file['error'] . ').');
    }

    if ((int) $file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('حجم فایل بیشتر از ۲ مگابایت است.');
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowed  = $subDir === 'certificates'
        ? ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf']
        : ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    if (!array_key_exists($mimeType, $allowed)) {
        throw new RuntimeException($subDir === 'certificates'
            ? 'فقط فایل‌های JPG، PNG یا PDF مجاز هستند.'
            : 'فقط فایل‌های JPG یا PNG مجاز هستند.');
    }

    $ext      = $allowed[$mimeType];
    $dir      = __DIR__ . '/../assets/uploads/' . $subDir . '/';

    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('ایجاد پوشه بارگذاری ممکن نیست.');
    }

    // Ensure .htaccess blocks direct PHP execution in the uploads folder
    $htaccess = dirname($dir) . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.php$\">\n  Deny from all\n</FilesMatch>\n");
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        throw new RuntimeException('ذخیره فایل بارگذاری‌شده ممکن نیست.');
    }

    return 'assets/uploads/' . $subDir . '/' . $filename;
}

// ─── POST handling ────────────────────────────────────────────────────────────
$successMessage = getFlash('success');
$errorMessage   = getFlash('error');
$formErrors     = [];
$formData       = [];

if (isPostRequest()) {
    $postAction = (string) ($_POST['action'] ?? '');
    $csrfToken  = (string) ($_POST['csrf_token'] ?? '');

    if (!validateCsrfToken($csrfToken)) {
        setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        redirect(url('admin/teachers.php'));
    }

    initializeTeachersTables();
    $pdo = getDb();

    // ── Status change (approve/activate/deactivate) ──────────────────────────
    if (in_array($postAction, ['approve', 'activate', 'deactivate', 'delete', 'login_as'], true)) {
        $teacherId = filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $teacherId = is_int($teacherId) ? $teacherId : 0;

        if ($teacherId === 0) {
            setFlash('error', 'سابقه معلم نامعتبر است.');
            redirect(url('admin/teachers.php'));
        }

        try {
            match ($postAction) {
                'approve'    => $pdo->prepare('UPDATE teachers SET status = :s WHERE id = :id')
                                    ->execute([':s' => 'active',   ':id' => $teacherId]),
                'activate'   => $pdo->prepare('UPDATE teachers SET status = :s WHERE id = :id')
                                    ->execute([':s' => 'active',   ':id' => $teacherId]),
                'deactivate' => $pdo->prepare('UPDATE teachers SET status = :s WHERE id = :id')
                                    ->execute([':s' => 'inactive', ':id' => $teacherId]),
                'delete'     => $pdo->prepare('DELETE FROM teachers WHERE id = :id')
                                    ->execute([':id' => $teacherId]),
                'login_as'   => (function () use ($pdo, $teacherId, $currentAdminId): never {
                    // Check teacher is active
                    $check = $pdo->prepare('SELECT status FROM teachers WHERE id = :id LIMIT 1');
                    $check->execute([':id' => $teacherId]);
                    $row = $check->fetch();
                    if (!$row || $row['status'] !== 'active') {
                        setFlash('error', 'برای ورود به‌جای معلم، حساب او باید فعال باشد.');
                        redirect(url('admin/teachers.php'));
                    }

                    // Generate token
                    $token   = bin2hex(random_bytes(32)); // 64 chars
                    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                    $ins = $pdo->prepare(
                        'INSERT INTO login_tokens (token, teacher_id, created_by_admin_id, expires_at)
                         VALUES (:token, :tid, :aid, :exp)'
                    );
                    $ins->execute([
                        ':token' => $token,
                        ':tid'   => $teacherId,
                        ':aid'   => $currentAdminId,
                        ':exp'   => $expires,
                    ]);

                    // Redirect to auto-login URL (intended to open in new tab via JS below)
                    recordAudit('teacher.login_as', 'teacher', (int) $teacherId);
                    setFlash('login_as_url', url('teacher/auto-login.php?token=' . urlencode($token)));
                    redirect(url('admin/teachers.php'));
                })(),
            };

            $messages = [
                'approve'    => 'معلم تأیید و فعال شد.',
                'activate'   => 'وضعیت معلم فعال شد.',
                'deactivate' => 'معلم غیرفعال شد.',
                'delete'     => 'معلم حذف شد.',
            ];
            recordAudit('teacher.' . $postAction, 'teacher', (int) $teacherId);
            setFlash('success', $messages[$postAction] ?? 'Done.');
        } catch (Throwable $e) {
            error_log($e->getMessage());
            setFlash('error', 'اجرای عملیات ممکن نیست. لطفاً دوباره تلاش کنید.');
        }
        redirect(url('admin/teachers.php'));
    }

    // ── Add / Edit teacher ───────────────────────────────────────────────────
    if (in_array($postAction, ['add', 'edit'], true)) {
        $teacherId = ($postAction === 'edit')
            ? (int) filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : 0;

        $fields = [
            'first_name'      => trim((string) ($_POST['first_name'] ?? '')),
            'last_name'       => trim((string) ($_POST['last_name'] ?? '')),
            'email'           => trim((string) ($_POST['email'] ?? '')),
            'phone'           => trim((string) ($_POST['phone'] ?? '')),
            'national_id'     => trim((string) ($_POST['national_id'] ?? '')),
            'education_level' => trim((string) ($_POST['education_level'] ?? '')),
            'major'           => trim((string) ($_POST['major'] ?? '')),
            'hire_date'       => trim((string) ($_POST['hire_date'] ?? '')),
            'salary'          => trim((string) ($_POST['salary'] ?? '')),
            'password'        => (string) ($_POST['password'] ?? ''),
            'status'          => in_array($_POST['status'] ?? '', ['pending', 'active', 'inactive'], true)
                                    ? (string) $_POST['status'] : 'pending',
        ];
        $formData = $fields;

        // Validate
        if ($fields['first_name'] === '') $formErrors[] = 'نام الزامی است.';
        if ($fields['last_name'] === '')  $formErrors[] = 'نام خانوادگی الزامی است.';
        if ($fields['email'] === '' || !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            $formErrors[] = 'ایمیل معتبر الزامی است.';
        }
        if ($postAction === 'add' && $fields['password'] === '') {
            $formErrors[] = 'هنگام افزودن معلم، رمز عبور الزامی است.';
        }
        if ($fields['password'] !== '' && strlen($fields['password']) < 8) {
            $formErrors[] = 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
        }

        if (empty($formErrors)) {
            try {
                // Ensure email is unique (exclude the current teacher when editing).
                $emailCheck = $pdo->prepare(
                    'SELECT id FROM teachers WHERE email = :email AND id != :tid LIMIT 1'
                );
                $emailCheck->execute([
                    ':email' => $fields['email'],
                    ':tid'   => $teacherId > 0 ? $teacherId : -1,
                ]);
                if ($emailCheck->fetchColumn() !== false) {
                    $formErrors[] = 'معلم دیگری با این ایمیل قبلاً ثبت شده است.';
                    goto skipSave;
                }

                $avatarPath      = handleTeacherUpload('avatar', 'avatars');
                $certPath        = handleTeacherUpload('certificate_file', 'certificates');

                $salaryVal = $fields['salary'] !== '' ? (float) $fields['salary'] : null;
                $hireDateVal = $fields['hire_date'] !== '' ? $fields['hire_date'] : null;

                if ($postAction === 'add') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO teachers
                            (first_name, last_name, email, phone, password, avatar,
                             national_id, education_level, major, certificate_file,
                             hire_date, salary, status)
                         VALUES
                            (:fn, :ln, :em, :ph, :pw, :av,
                             :ni, :el, :mj, :cf,
                             :hd, :sl, :st)'
                    );
                    $stmt->execute([
                        ':fn' => $fields['first_name'],
                        ':ln' => $fields['last_name'],
                        ':em' => $fields['email'],
                        ':ph' => $fields['phone'] ?: null,
                        ':pw' => password_hash($fields['password'], PASSWORD_DEFAULT),
                        ':av' => $avatarPath,
                        ':ni' => $fields['national_id'] ?: null,
                        ':el' => $fields['education_level'] ?: null,
                        ':mj' => $fields['major'] ?: null,
                        ':cf' => $certPath,
                        ':hd' => $hireDateVal,
                        ':sl' => $salaryVal,
                        ':st' => $fields['status'],
                    ]);
                    recordAudit('teacher.create', 'teacher', (int) $pdo->lastInsertId());
                    setFlash('success', 'حساب معلم با موفقیت ایجاد شد.');
                } else {
                    // Edit: fetch existing for avatar/cert preservation
                    $existing = $pdo->prepare('SELECT avatar, certificate_file FROM teachers WHERE id = :id LIMIT 1');
                    $existing->execute([':id' => $teacherId]);
                    $old = $existing->fetch();

                    $finalAvatar = $avatarPath ?? ($old['avatar'] ?? null);
                    $finalCert   = $certPath   ?? ($old['certificate_file'] ?? null);

                    $pwClause = '';
                    $params   = [
                        ':fn' => $fields['first_name'],
                        ':ln' => $fields['last_name'],
                        ':em' => $fields['email'],
                        ':ph' => $fields['phone'] ?: null,
                        ':av' => $finalAvatar,
                        ':ni' => $fields['national_id'] ?: null,
                        ':el' => $fields['education_level'] ?: null,
                        ':mj' => $fields['major'] ?: null,
                        ':cf' => $finalCert,
                        ':hd' => $hireDateVal,
                        ':sl' => $salaryVal,
                        ':st' => $fields['status'],
                        ':id' => $teacherId,
                    ];
                    if ($fields['password'] !== '') {
                        $pwClause     = ', password = :pw';
                        $params[':pw'] = password_hash($fields['password'], PASSWORD_DEFAULT);
                    }

                    $stmt = $pdo->prepare(
                        "UPDATE teachers SET
                            first_name = :fn, last_name = :ln, email = :em, phone = :ph,
                            avatar = :av, national_id = :ni, education_level = :el,
                            major = :mj, certificate_file = :cf, hire_date = :hd,
                            salary = :sl, status = :st{$pwClause}
                         WHERE id = :id"
                    );
                    $stmt->execute($params);
                    recordAudit('teacher.update', 'teacher', (int) $teacherId);
                    setFlash('success', 'اطلاعات معلم با موفقیت به‌روزرسانی شد.');
                }
            } catch (Throwable $e) {
                error_log($e->getMessage());
                setFlash('error', 'ذخیره معلم ممکن نیست. لطفاً دوباره تلاش کنید.');
            }

            skipSave:
            redirect(url('admin/teachers.php'));
        }
        // Fall through to show form with errors
        $errorMessage = implode(' ', $formErrors);
    }
}

// ─── Load data ────────────────────────────────────────────────────────────────
$filterStatus = in_array($_GET['status'] ?? '', ['pending', 'active', 'inactive'], true)
    ? (string) $_GET['status'] : '';

$editTeacher = null;
$editId      = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$pagination  = paginate(0, 1, 20);

try {
    initializeTeachersTables();
    $pdo = getDb();

    if ($editId) {
        $eStmt = $pdo->prepare('SELECT * FROM teachers WHERE id = :id LIMIT 1');
        $eStmt->execute([':id' => $editId]);
        $editTeacher = $eStmt->fetch() ?: null;
    }

    $sql = 'SELECT id, first_name, last_name, email, phone, education_level, hire_date, status, avatar
            FROM teachers';
    $countSql = 'SELECT COUNT(*) FROM teachers';
    $params = [];
    if ($filterStatus !== '') {
        $sql      .= ' WHERE status = :status';
        $countSql .= ' WHERE status = :status';
        $params[':status'] = $filterStatus;
    }

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $pagination = paginate((int) $countStmt->fetchColumn(), currentPageNumber(), 20);

    $sql .= ' ORDER BY created_at DESC LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'];
    $tStmt = $pdo->prepare($sql);
    $tStmt->execute($params);
    $teachers = $tStmt->fetchAll();
} catch (Throwable $e) {
    error_log($e->getMessage());
    $teachers    = [];
    $errorMessage = 'بارگذاری فهرست معلمان ممکن نیست.';
}

// Login-As URL from flash
$loginAsUrl = getFlash('login_as_url');

$pageTitle = 'معلمان | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<?php if ($loginAsUrl !== null): ?>
<script>
    window.open(<?= json_encode($loginAsUrl) ?>, '_blank');
</script>
<div class="notice" role="status">
    لینک ورود معلم در تب جدیدی باز شد.
    <a href="<?= e($loginAsUrl) ?>" target="_blank">اگر باز نشد اینجا کلیک کنید.</a>
</div>
<?php endif; ?>

<section class="dashboard">
    <h1>مدیریت معلمان</h1>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errorMessage !== null): ?>
        <div class="alert" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <!-- ── Add / Edit Form ───────────────────────────────────────────────── -->
    <div class="admin-form-card">
        <h2><?= $editTeacher ? 'ویرایش معلم' : 'افزودن معلم جدید' ?></h2>
        <form method="post" action="<?= e(url('admin/teachers.php')) ?>" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="<?= $editTeacher ? 'edit' : 'add' ?>">
            <?php if ($editTeacher): ?>
                <input type="hidden" name="teacher_id" value="<?= e((string) $editTeacher['id']) ?>">
            <?php endif; ?>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="tf_first_name">نام <span class="req">*</span></label>
                    <input type="text" id="tf_first_name" name="first_name" class="form-control"
                           value="<?= e((string) ($editTeacher['first_name'] ?? $formData['first_name'] ?? '')) ?>" required>
                </div>
                <div class="form-group">
                    <label for="tf_last_name">نام خانوادگی <span class="req">*</span></label>
                    <input type="text" id="tf_last_name" name="last_name" class="form-control"
                           value="<?= e((string) ($editTeacher['last_name'] ?? $formData['last_name'] ?? '')) ?>" required>
                </div>
                <div class="form-group">
                    <label for="tf_email">ایمیل <span class="req">*</span></label>
                    <input type="email" id="tf_email" name="email" class="form-control"
                           value="<?= e((string) ($editTeacher['email'] ?? $formData['email'] ?? '')) ?>" required>
                </div>
                <div class="form-group">
                    <label for="tf_phone">تلفن</label>
                    <input type="tel" id="tf_phone" name="phone" class="form-control"
                           value="<?= e((string) ($editTeacher['phone'] ?? $formData['phone'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label for="tf_password">رمز عبور <?= $editTeacher ? '(خالی بگذارید تا تغییر نکند)' : '<span class="req">*</span>' ?></label>
                    <input type="password" id="tf_password" name="password" class="form-control"
                           autocomplete="new-password" minlength="8">
                </div>
                <div class="form-group">
                    <label for="tf_national_id">کد ملی (کد ملی)</label>
                    <input type="text" id="tf_national_id" name="national_id" class="form-control"
                           value="<?= e((string) ($editTeacher['national_id'] ?? $formData['national_id'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label for="tf_education_level">مدرک تحصیلی (مدرک تحصیلی)</label>
                    <input type="text" id="tf_education_level" name="education_level" class="form-control"
                           value="<?= e((string) ($editTeacher['education_level'] ?? $formData['education_level'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label for="tf_major">رشته تحصیلی (رشته)</label>
                    <input type="text" id="tf_major" name="major" class="form-control"
                           value="<?= e((string) ($editTeacher['major'] ?? $formData['major'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label for="tf_hire_date">تاریخ استخدام (تاریخ استخدام)</label>
                    <input type="date" id="tf_hire_date" name="hire_date" class="form-control"
                           value="<?= e((string) ($editTeacher['hire_date'] ?? $formData['hire_date'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label for="tf_salary">حقوق (حقوق)</label>
                    <input type="number" id="tf_salary" name="salary" class="form-control" step="0.01" min="0"
                           value="<?= e((string) ($editTeacher['salary'] ?? $formData['salary'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label for="tf_status">وضعیت</label>
                    <select id="tf_status" name="status" class="form-control">
                        <?php foreach (['pending', 'active', 'inactive'] as $s): ?>
                            <option value="<?= e($s) ?>"
                                <?= ($editTeacher['status'] ?? $formData['status'] ?? 'pending') === $s ? 'selected' : '' ?>>
                                <?= ucfirst($s) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="tf_avatar">تصویر پروفایل (JPG/PNG، حداکثر ۲ مگابایت)</label>
                    <input type="file" id="tf_avatar" name="avatar" class="form-control" accept="image/jpeg,image/png">
                    <?php if (!empty($editTeacher['avatar'])): ?>
                        <img src="<?= e(url((string) $editTeacher['avatar'])) ?>" alt="تصویر فعلی" class="teacher-avatar-sm">
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="tf_certificate">مدرک (PDF/JPG/PNG، حداکثر ۲ مگابایت)</label>
                    <input type="file" id="tf_certificate" name="certificate_file" class="form-control"
                           accept="image/jpeg,image/png,application/pdf">
                    <?php if (!empty($editTeacher['certificate_file'])): ?>
                        <a href="<?= e(url((string) $editTeacher['certificate_file'])) ?>" target="_blank" class="teacher-cert-link">📄 مشاهده گواهی فعلی</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?= $editTeacher ? 'ذخیره تغییرات' : 'ایجاد حساب معلم' ?>
                </button>
                <?php if ($editTeacher): ?>
                    <a href="<?= e(url('admin/teachers.php')) ?>" class="btn btn-secondary">انصراف</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ── Filters ───────────────────────────────────────────────────────── -->
    <div class="teacher-filters">
        <strong>فیلتر:</strong>
        <?php foreach (['', 'pending', 'active', 'inactive'] as $fs): ?>
            <a href="<?= e(url('admin/teachers.php' . ($fs ? '?status=' . $fs : ''))) ?>"
               class="filter-chip <?= $filterStatus === $fs ? 'active' : '' ?>">
                <?= $fs === '' ? 'همه' : ('pending' === $fs ? 'در انتظار' : ('active' === $fs ? 'فعال' : 'غیرفعال')) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- ── Teacher Table ─────────────────────────────────────────────────── -->
    <?php if (empty($teachers)): ?>
        <p class="muted">هیچ معلمی یافت نشد<?= $filterStatus ? ' با وضعیت «' . e($filterStatus) . '"' : '' ?>.</p>
    <?php else: ?>
        <!-- Desktop table -->
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>تصویر</th>
                        <th>نام</th>
                        <th>ایمیل</th>
                        <th>تلفن</th>
                        <th>تحصیلات</th>
                        <th>تاریخ استخدام</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($teachers as $t): ?>
                    <?php
                    $fullName = trim($t['first_name'] . ' ' . $t['last_name']);
                    $initial  = strtoupper(substr((string) $t['first_name'], 0, 1));
                    $tStatus  = (string) $t['status'];
                    $badgeClass = match ($tStatus) {
                        'active'   => 'badge-active',
                        'inactive' => 'badge-inactive',
                        default    => 'badge-pending',
                    };
                    ?>
                    <tr>
                        <td>
                            <?php if (!empty($t['avatar'])): ?>
                                <img src="<?= e(url((string) $t['avatar'])) ?>" alt="<?= e($fullName) ?>"
                                     class="teacher-avatar-thumb">
                            <?php else: ?>
                                <div class="teacher-avatar-placeholder"><?= e($initial ?: '?') ?></div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= e($fullName) ?></strong></td>
                        <td><?= e((string) $t['email']) ?></td>
                        <td><?= e((string) ($t['phone'] ?? '—')) ?></td>
                        <td><?= e((string) ($t['education_level'] ?? '—')) ?></td>
                        <td><?= e((string) ($t['hire_date'] ?? '—')) ?></td>
                        <td><span class="badge <?= e($badgeClass) ?>"><?= ucfirst($tStatus) ?></span></td>
                        <td class="teacher-actions-cell">
                            <a href="<?= e(url('admin/teachers.php?edit=' . (int) $t['id'])) ?>"
                               class="btn btn-sm btn-secondary">ویرایش</a>

                            <?php if ($tStatus === 'pending'): ?>
                                <form method="post" action="<?= e(url('admin/teachers.php')) ?>" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="teacher_id" value="<?= (int) $t['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-approve">تأیید</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($tStatus === 'inactive'): ?>
                                <form method="post" action="<?= e(url('admin/teachers.php')) ?>" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="teacher_id" value="<?= (int) $t['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-approve">فعال‌سازی</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($tStatus === 'active'): ?>
                                <form method="post" action="<?= e(url('admin/teachers.php')) ?>" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="deactivate">
                                    <input type="hidden" name="teacher_id" value="<?= (int) $t['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-secondary">غیرفعال‌سازی</button>
                                </form>

                                <!-- Login As Teacher -->
                                <form method="post" action="<?= e(url('admin/teachers.php')) ?>" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="login_as">
                                    <input type="hidden" name="teacher_id" value="<?= (int) $t['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-login-as"
                                            title="ایجاد لینک ورود یکباره ۱۵ دقیقه‌ای برای این معلم">
                                        🔑 ورود به‌عنوان معلم
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="post" action="<?= e(url('admin/teachers.php')) ?>" class="inline-form"
                                  onsubmit="return confirm('این معلم حذف شود؟ این عملیات قابل بازگشت نیست.');">
                                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="teacher_id" value="<?= (int) $t['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-reject">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile cards -->
        <div class="teacher-mobile-cards">
            <?php foreach ($teachers as $t): ?>
                <?php
                $fullName   = trim($t['first_name'] . ' ' . $t['last_name']);
                $initial    = strtoupper(substr((string) $t['first_name'], 0, 1));
                $tStatus    = (string) $t['status'];
                $badgeClass = match ($tStatus) {
                    'active'   => 'badge-active',
                    'inactive' => 'badge-inactive',
                    default    => 'badge-pending',
                };
                ?>
                <div class="teacher-mobile-card">
                    <div class="tmc-header">
                        <?php if (!empty($t['avatar'])): ?>
                            <img src="<?= e(url((string) $t['avatar'])) ?>" alt="<?= e($fullName) ?>"
                                 class="teacher-avatar-thumb">
                        <?php else: ?>
                            <div class="teacher-avatar-placeholder"><?= e($initial ?: '?') ?></div>
                        <?php endif; ?>
                        <div>
                            <strong><?= e($fullName) ?></strong>
                            <span class="badge <?= e($badgeClass) ?>"><?= ucfirst($tStatus) ?></span>
                        </div>
                    </div>
                    <p><?= e((string) $t['email']) ?></p>
                    <?php if (!empty($t['phone'])): ?><p>📞 <?= e((string) $t['phone']) ?></p><?php endif; ?>
                    <?php if (!empty($t['education_level'])): ?><p>🎓 <?= e((string) $t['education_level']) ?></p><?php endif; ?>
                    <div class="tmc-actions">
                        <a href="<?= e(url('admin/teachers.php?edit=' . (int) $t['id'])) ?>" class="btn btn-sm btn-secondary">ویرایش</a>
                        <?php if ($tStatus === 'active'): ?>
                            <form method="post" action="<?= e(url('admin/teachers.php')) ?>" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                <input type="hidden" name="action" value="login_as">
                                <input type="hidden" name="teacher_id" value="<?= (int) $t['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-login-as">🔑 ورود به‌جای معلم</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($pagination['total'] > $pagination['perPage']): ?>
            <p class="pagination-summary">
                نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> معلم
            </p>
            <?= renderPagination($pagination, url('admin/teachers.php'), $filterStatus !== '' ? ['status' => $filterStatus] : []) ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>

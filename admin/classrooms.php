<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

$successMessage = getFlash('success');
$errorMessage   = getFlash('error');
$formErrors     = [];
$formData       = [];

if (isPostRequest()) {
    $postAction = (string) ($_POST['action'] ?? '');
    $csrfToken  = (string) ($_POST['csrf_token'] ?? '');

    if (!validateCsrfToken($csrfToken)) {
        setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        redirect(url('admin/classrooms.php'));
    }

    initializeTeachersTables();
    $pdo = getDb();

    // ── Delete classroom ─────────────────────────────────────────────────────
    if ($postAction === 'delete') {
        $classroomId = filter_var($_POST['classroom_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $classroomId = is_int($classroomId) ? $classroomId : 0;

        if ($classroomId > 0) {
            try {
                $pdo->prepare('DELETE FROM classrooms WHERE id = :id')->execute([':id' => $classroomId]);
                recordAudit('classroom.delete', 'classroom', (int) $classroomId);
                setFlash('success', 'کلاس با موفقیت حذف شد.');
            } catch (Throwable $e) {
                error_log($e->getMessage());
                setFlash('error', 'حذف کلاس امکان‌پذیر نیست.');
            }
        } else {
            setFlash('error', 'کلاس نامعتبر است.');
        }
        redirect(url('admin/classrooms.php'));
    }

    // ── Add / Edit classroom ─────────────────────────────────────────────────
    if (in_array($postAction, ['add', 'edit'], true)) {
        $classroomId = ($postAction === 'edit')
            ? (int) filter_var($_POST['classroom_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : 0;

        $fields = [
            'name'        => trim((string) ($_POST['name'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'capacity'    => trim((string) ($_POST['capacity'] ?? '15')),
            'schedule'    => trim((string) ($_POST['schedule'] ?? '')),
            'teacher_id'  => trim((string) ($_POST['teacher_id'] ?? '')),
        ];
        $formData = $fields;

        if ($fields['name'] === '') $formErrors[] = 'نام کلاس الزامی است.';

        $capacityVal  = filter_var($fields['capacity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $capacityVal  = $capacityVal !== false ? (int) $capacityVal : 15;
        $teacherIdVal = filter_var($fields['teacher_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $teacherIdVal = $teacherIdVal !== false ? (int) $teacherIdVal : null;

        if (empty($formErrors)) {
            try {
                if ($postAction === 'add') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO classrooms (name, description, capacity, schedule, teacher_id)
                         VALUES (:nm, :ds, :cp, :sc, :tid)'
                    );
                    $stmt->execute([
                        ':nm'  => $fields['name'],
                        ':ds'  => $fields['description'] ?: null,
                        ':cp'  => $capacityVal,
                        ':sc'  => $fields['schedule'] ?: null,
                        ':tid' => $teacherIdVal,
                    ]);
                    recordAudit('classroom.create', 'classroom', (int) $pdo->lastInsertId());
                    setFlash('success', 'کلاس ایجاد شد.');
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE classrooms SET name = :nm, description = :ds, capacity = :cp,
                          schedule = :sc, teacher_id = :tid WHERE id = :id'
                    );
                    $stmt->execute([
                        ':nm'  => $fields['name'],
                        ':ds'  => $fields['description'] ?: null,
                        ':cp'  => $capacityVal,
                        ':sc'  => $fields['schedule'] ?: null,
                        ':tid' => $teacherIdVal,
                        ':id'  => $classroomId,
                    ]);
                    recordAudit('classroom.update', 'classroom', (int) $classroomId);
                    setFlash('success', 'کلاس به‌روزرسانی شد.');
                }
            } catch (Throwable $e) {
                error_log($e->getMessage());
                setFlash('error', 'ذخیره کلاس امکان‌پذیر نیست. لطفاً دوباره تلاش کنید.');
            }
            redirect(url('admin/classrooms.php'));
        }
        $errorMessage = implode(' ', $formErrors);
    }
}

// ─── Load data ────────────────────────────────────────────────────────────────
$editClassroom = null;
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

try {
    initializeTeachersTables();
    $pdo = getDb();

    if ($editId) {
        $eStmt = $pdo->prepare('SELECT * FROM classrooms WHERE id = :id LIMIT 1');
        $eStmt->execute([':id' => $editId]);
        $editClassroom = $eStmt->fetch() ?: null;
    }

    // Classrooms with teacher name + enrolled count
    $pagination = paginate(
        (int) $pdo->query('SELECT COUNT(*) FROM classrooms')->fetchColumn(),
        currentPageNumber(),
        20
    );
    $classrooms = $pdo->query(
        'SELECT cl.id, cl.name, cl.capacity, cl.schedule,
                cl.teacher_id,
                CONCAT(t.first_name, " ", t.last_name) AS teacher_name,
                COUNT(cc.id) AS enrolled_count
         FROM classrooms cl
         LEFT JOIN teachers t ON t.id = cl.teacher_id
         LEFT JOIN child_classroom cc ON cc.classroom_id = cl.id
         GROUP BY cl.id
         ORDER BY cl.created_at DESC'
        . ' LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset']
    )->fetchAll();

    // Active teachers for the select dropdown
    $activeTeachers = $pdo->query(
        "SELECT id, first_name, last_name FROM teachers WHERE status = 'active' ORDER BY last_name, first_name"
    )->fetchAll();
} catch (Throwable $e) {
    error_log($e->getMessage());
    $classrooms     = [];
    $activeTeachers = [];
    $pagination     = paginate(0, 1, 20);
    $errorMessage   = 'Could not load classroom data.';
}

$pageTitle = 'کلاس‌ها | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <h1>مدیریت کلاس‌ها</h1>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errorMessage !== null): ?>
        <div class="alert" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <!-- ── Add / Edit Form ── -->
    <div class="admin-form-card">
        <h2><?= $editClassroom ? 'ویرایش کلاس' : 'افزودن کلاس جدید' ?></h2>
        <form method="post" action="<?= e(url('admin/classrooms.php')) ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="<?= $editClassroom ? 'edit' : 'add' ?>">
            <?php if ($editClassroom): ?>
                <input type="hidden" name="classroom_id" value="<?= (int) $editClassroom['id'] ?>">
            <?php endif; ?>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="cl_name">نام کلاس <span class="req">*</span></label>
                    <input type="text" id="cl_name" name="name" class="form-control"
                           value="<?= e((string) ($editClassroom['name'] ?? $formData['name'] ?? '')) ?>" required>
                </div>
                <div class="form-group">
                    <label for="cl_capacity">ظرفیت</label>
                    <input type="number" id="cl_capacity" name="capacity" class="form-control" min="1"
                           value="<?= e((string) ($editClassroom['capacity'] ?? $formData['capacity'] ?? '15')) ?>">
                </div>
                <div class="form-group form-group-full">
                    <label for="cl_description">توضیحات</label>
                    <textarea id="cl_description" name="description" class="form-control" rows="2"><?= e((string) ($editClassroom['description'] ?? $formData['description'] ?? '')) ?></textarea>
                </div>
                <div class="form-group">
                    <label for="cl_teacher_id">اختصاص معلم</label>
                    <select id="cl_teacher_id" name="teacher_id" class="form-control">
                        <option value="">— بدون معلم —</option>
                        <?php foreach ($activeTeachers as $at): ?>
                            <?php
                            $selectedTid = (int) ($editClassroom['teacher_id'] ?? $formData['teacher_id'] ?? 0);
                            ?>
                            <option value="<?= (int) $at['id'] ?>"
                                <?= $selectedTid === (int) $at['id'] ? 'selected' : '' ?>>
                                <?= e(trim($at['first_name'] . ' ' . $at['last_name'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group form-group-full">
                    <label for="cl_schedule">برنامه هفتگی</label>
                    <textarea id="cl_schedule" name="schedule" class="form-control" rows="4"
                              placeholder="مثال: شنبه تا چهارشنبه ۸ تا ۱۶–Fri 8:00–16:00, Lunch 12:00–13:00..."><?= e((string) ($editClassroom['schedule'] ?? $formData['schedule'] ?? '')) ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?= $editClassroom ? 'ذخیره تغییرات' : 'ایجاد کلاس' ?>
                </button>
                <?php if ($editClassroom): ?>
                    <a href="<?= e(url('admin/classrooms.php')) ?>" class="btn btn-secondary">انصراف</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ── Classrooms List ── -->
    <?php if (empty($classrooms)): ?>
        <p class="muted">هنوز کلاسی وجود ندارد. اولین کلاس را در بالا اضافه کنید.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>نام</th>
                        <th>معلم اختصاص‌یافته</th>
                        <th>ثبت‌نام شده / ظرفیت</th>
                        <th>برنامه</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($classrooms as $cl): ?>
                    <tr>
                        <td><strong><?= e((string) $cl['name']) ?></strong></td>
                        <td>
                            <?php if (!empty($cl['teacher_name'])): ?>
                                <?= e((string) $cl['teacher_name']) ?>
                            <?php else: ?>
                                <span class="muted">بدون معلم</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="enrolled-count"><?= (int) $cl['enrolled_count'] ?></span>
                            / <?= (int) $cl['capacity'] ?>
                        </td>
                        <td class="schedule-cell">
                            <?= $cl['schedule'] ? nl2br(e((string) $cl['schedule'])) : '<span class="muted">—</span>' ?>
                        </td>
                        <td class="teacher-actions-cell">
                            <a href="<?= e(url('admin/classrooms.php?edit=' . (int) $cl['id'])) ?>"
                               class="btn btn-sm btn-secondary">ویرایش</a>
                            <form method="post" action="<?= e(url('admin/classrooms.php')) ?>" class="inline-form"
                                  onsubmit="return confirm('این کلاس حذف شود؟');">
                                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="classroom_id" value="<?= (int) $cl['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-reject">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pagination['total'] > $pagination['perPage']): ?>
            <p class="pagination-summary">
                نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> کلاس
            </p>
            <?= renderPagination($pagination, url('admin/classrooms.php')) ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>

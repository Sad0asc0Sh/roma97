<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/auth_flow.php';
require_once __DIR__ . '/../includes/admin_family_helpers.php';

requireLogin();

$errors = [];
$oldParent = [
    'first_name' => '',
    'last_name' => '',
    'phone' => '',
    'email' => '',
    'status' => 'active',
];
/** @var list<array<string,mixed>> $oldChildren */
$oldChildren = [];
$allClassrooms = [];

try {
    initializeParentTables();
    initializeTeachersTables();
    $pdo = getDb();
    try {
        $clStmt = $pdo->query(
            'SELECT cl.id, cl.name, CONCAT(t.first_name, " ", t.last_name) AS teacher_name
             FROM classrooms cl LEFT JOIN teachers t ON t.id = cl.teacher_id ORDER BY cl.name'
        );
        $allClassrooms = $clStmt ? $clStmt->fetchAll() : [];
    } catch (Throwable) {
        $allClassrooms = [];
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    setFlash('error', 'بارگذاری فرم ثبت والد در حال حاضر امکان‌پذیر نیست.');
    redirect(url('admin/parents.php'));
}

if (isPostRequest()) {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $oldParent = [
        'first_name' => trim((string) ($_POST['first_name'] ?? '')),
        'last_name' => trim((string) ($_POST['last_name'] ?? '')),
        'phone' => normalizeIranianPhone(trim((string) ($_POST['phone'] ?? ''))),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'status' => in_array($_POST['status'] ?? '', ['active', 'pending'], true) ? (string) $_POST['status'] : 'active',
    ];
    $password = (string) ($_POST['password'] ?? '');
    $submitAction = (string) ($_POST['submit_action'] ?? 'save');

    // Dynamic children blocks: children[<i>][<field>] — blank blocks ignored.
    $rawChildren = is_array($_POST['children'] ?? null) ? $_POST['children'] : [];
    $oldChildren = [];
    foreach ($rawChildren as $rawChild) {
        if (!is_array($rawChild)) {
            continue;
        }
        $norm = adminFamilyNormalizeChildInput($rawChild);
        if (adminFamilyChildBlockIsEmpty($norm)) {
            continue;
        }
        $oldChildren[] = $norm;
    }

    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }
    if (!adminFamilyIsValidParentName($oldParent['first_name'])) {
        $errors[] = 'لطفاً نام معتبر وارد کنید.';
    }
    if (!adminFamilyIsValidParentName($oldParent['last_name'])) {
        $errors[] = 'لطفاً نام خانوادگی معتبر وارد کنید.';
    }
    if (!isValidIranianMobile($oldParent['phone'])) {
        $errors[] = 'لطفاً شماره موبایل معتبر وارد کنید (مثال: 09123456789). شماره موبایل شناسه ورود والد است.';
    }
    if ($oldParent['email'] !== '') {
        if (adminFamilyStringLength($oldParent['email']) > 150 || !filter_var($oldParent['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'لطفاً یک آدرس ایمیل معتبر وارد کنید یا فیلد ایمیل را خالی بگذارید.';
        }
    }
    if (!adminFamilyIsStrongPassword($password)) {
        $errors[] = 'رمز عبور باید ۸ تا ۷۲ بایت و شامل حرف انگلیسی و عدد باشد.';
    }

    // Validate children and resolve Jalali DOBs.
    $preparedChildren = [];
    foreach ($oldChildren as $idx => $child) {
        $label = 'کودک ' . persianNumber($idx + 1);
        [$childErrors, $dob] = adminFamilyValidateChild($child, $label);
        $errors = array_merge($errors, $childErrors);
        $child['dob_gregorian'] = $dob ?? '';
        // Classroom must exist when one was chosen.
        $cid = (int) ($child['classroom_id'] ?? 0);
        if ($cid > 0) {
            $found = false;
            foreach ($allClassrooms as $cl) {
                if ((int) $cl['id'] === $cid) { $found = true; break; }
            }
            if (!$found) {
                $errors[] = "{$label}: کلاس انتخاب‌شده یافت نشد.";
            }
        }
        $preparedChildren[] = $child;
    }

    if ($errors === []) {
        try {
            if (adminFamilyParentPhoneExists($pdo, $oldParent['phone'], 0)) {
                $errors[] = 'والد دیگری با این شماره موبایل قبلاً ثبت شده است.';
            } elseif ($oldParent['email'] !== '' && adminFamilyParentEmailExists($pdo, $oldParent['email'], 0)) {
                $errors[] = 'والد دیگری با این ایمیل قبلاً ثبت شده است.';
            } else {
                // Admin registers the family in person: the number was
                // provided face-to-face, so no OTP round-trip is needed and
                // phone_verified is set to 1 (documented in the report).
                [$newParentId, $childIds] = adminFamilyCreateParentWithChildren($pdo, [
                    'first_name' => $oldParent['first_name'],
                    'last_name' => $oldParent['last_name'],
                    'email' => $oldParent['email'] !== '' ? $oldParent['email'] : null,
                    'phone' => $oldParent['phone'],
                    'phone_verified' => 1,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'status' => $oldParent['status'],
                ], $preparedChildren);

                recordAudit('parent.create', 'parent', $newParentId, [
                    'source' => 'admin_manual',
                    'phone_verified' => 1,
                    'children_count' => count($childIds),
                    'child_ids' => $childIds,
                ]);
                foreach ($childIds as $cidCreated) {
                    recordAudit('child.create_admin', 'child', $cidCreated, [
                        'source' => 'admin_manual_add_parent',
                        'parent_id' => $newParentId,
                    ]);
                }

                $fullName = trim($oldParent['first_name'] . ' ' . $oldParent['last_name']);
                $summary = 'والد «' . $fullName . '» با موفقیت ثبت شد.';
                if (count($childIds) > 0) {
                    $summary .= ' ' . persianNumber(count($childIds)) . ' کودک نیز همراه او ثبت شد.';
                }
                setFlash('success', $summary);
                if ($submitAction === 'save_and_new') {
                    redirect(url('admin/add-parent.php'));
                }
                redirect(url('admin/edit-parent.php?id=' . $newParentId));
            }
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $errors[] = 'ثبت والد با مشکل مواجه شد. لطفاً دوباره تلاش کنید.';
        }
    }
}

$pageTitle = 'ثبت دستی والد جدید | ' . siteName();
require_once __DIR__ . '/header.php';

$flashSuccess = getFlash('success');
$flashError = getFlash('error');
$childStatusOptions = [
    'active' => 'فعال',
    'pending' => 'در انتظار',
    'inactive' => 'غیرفعال',
    'graduated' => 'فارغ‌التحصیل',
    'withdrawn' => 'انصراف‌داده',
];
?>
<section class="dashboard">
    <h1>ثبت دستی والد جدید</h1>
    <p><a href="<?= e(url('admin/parents.php')) ?>">→ بازگشت به مدیریت والدین</a></p>
    <p style="color:var(--adm-text-muted);line-height:1.9;">والد را مستقیم توسط مدیریت ثبت کنید؛ نیازی به ثبت‌نام و کد پیامکی توسط خود والد نیست. در همین فرم می‌توانید یک یا چند کودک او را هم‌زمان ثبت و به کلاس اختصاص دهید — والد و همه کودکان در یک تراکنش ذخیره می‌شوند و اگر خطایی رخ دهد هیچ‌کدام ثبت نمی‌شوند. عکس کودک را بعداً از صفحه «ویرایش کودک» می‌توانید اضافه کنید.</p>

    <?php if ($flashSuccess !== null): ?><div class="notice" role="status"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError !== null): ?><div class="alert" role="alert"><?= e($flashError) ?></div><?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert" role="alert">
            <?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="form-container">
        <form method="post" action="<?= e(url('admin/add-parent.php')) ?>" novalidate id="addParentForm" class="child-form">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

            <div class="form-card">
                <h2>اطلاعات والد</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="first_name">نام <span class="req">*</span></label>
                        <input type="text" id="first_name" name="first_name" maxlength="100" value="<?= e($oldParent['first_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">نام خانوادگی <span class="req">*</span></label>
                        <input type="text" id="last_name" name="last_name" maxlength="100" value="<?= e($oldParent['last_name']) ?>" required>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="phone">شماره موبایل (شناسه ورود) <span class="req">*</span></label>
                        <input type="tel" id="phone" name="phone" dir="ltr" placeholder="09123456789" value="<?= e($oldParent['phone']) ?>" required>
                        <small>اعداد فارسی، ‎+98‎ یا ‎0098‎ هم پذیرفته می‌شود و به قالب استاندارد تبدیل می‌گردد. چون ثبت توسط مدیریت انجام می‌شود، شماره «تأییدشده» ذخیره می‌شود و کد پیامکی لازم نیست.</small>
                    </div>
                    <div class="form-group">
                        <label for="email">ایمیل (اختیاری)</label>
                        <input type="email" id="email" name="email" dir="ltr" placeholder="اختیاری" value="<?= e($oldParent['email']) ?>">
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="password">رمز عبور <span class="req">*</span></label>
                        <input type="password" id="password" name="password" autocomplete="new-password">
                        <small>حداقل ۸ کاراکتر شامل حرف انگلیسی و عدد؛ توسط مدیریت تعیین و به والد تحویل داده می‌شود.</small>
                    </div>
                    <div class="form-group">
                        <label for="status">وضعیت حساب</label>
                        <select id="status" name="status">
                            <option value="active" <?= $oldParent['status'] === 'active' ? 'selected' : '' ?>>فعال</option>
                            <option value="pending" <?= $oldParent['status'] === 'pending' ? 'selected' : '' ?>>در انتظار تأیید</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-card">
                <h2>کودکان این والد</h2>
                <p style="color:var(--adm-text-muted);line-height:1.8;">بدون افزودن کودک هم می‌توانید فقط والد را ثبت کنید. برای هر کودک یک بلوک اضافه کنید. تخصیص کلاس فقط برای کودک با وضعیت «فعال» انجام می‌شود (مانند ویرایش کودک).</p>
                <div id="childrenContainer">
                    <?php foreach ($oldChildren as $idx => $child): ?>
                        <?php $ci = $idx; require __DIR__ . '/partials/admin-child-block.php'; ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-secondary" id="addChildBlockBtn">➕ افزودن کودک</button>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit" name="submit_action" value="save">ثبت والد و کودکان</button>
                <button class="btn btn-secondary" type="submit" name="submit_action" value="save_and_new">ثبت و افزودن والد بعدی</button>
                <a class="btn btn-outline" href="<?= e(url('admin/parents.php')) ?>">انصراف</a>
            </div>
        </form>
    </div>
</section>

<template id="childBlockTemplate">
    <?php $ci = '__INDEX__'; $child = ['first_name'=>'','last_name'=>'','preferred_name'=>'','date_of_birth'=>'','gender'=>'','allergies'=>'','medical_notes'=>'','second_guardian_name'=>'','second_guardian_phone'=>'','status'=>'active','classroom_id'=>0]; require __DIR__ . '/partials/admin-child-block.php'; ?>
</template>

<script>
(function () {
    var container = document.getElementById('childrenContainer');
    var template = document.getElementById('childBlockTemplate');
    var addBtn = document.getElementById('addChildBlockBtn');
    if (!container || !template || !addBtn) { return; }
    var nextIndex = container.querySelectorAll('[data-child-block]').length;
    function renumberTitles() {
        var blocks = container.querySelectorAll('[data-child-block]');
        blocks.forEach(function (block, i) {
            var title = block.querySelector('[data-child-title]');
            if (title) { title.textContent = 'کودک ' + (i + 1); }
        });
    }
    addBtn.addEventListener('click', function () {
        var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
        nextIndex += 1;
        var wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        var block = wrapper.firstElementChild;
        if (block) { container.appendChild(block); }
        renumberTitles();
    });
    container.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-remove-child]');
        if (!btn) { return; }
        var block = btn.closest('[data-child-block]');
        if (block) { block.remove(); }
        renumberTitles();
    });
    renumberTitles();
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

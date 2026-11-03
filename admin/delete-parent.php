<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/admin_family_helpers.php';

// Permanent deletion of a whole family account: restricted to the two most
// trusted roles for BOTH the confirmation view and the action itself
// (mirrors the generate_reset_link trust tier in admin/parents.php).
requireLogin();
requireAdminRole(['owner', 'manager']);

function parseDeleteParentId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return is_int($id) ? $id : 0;
}

$parentId = parseDeleteParentId($_GET['id'] ?? $_POST['parent_id'] ?? null);
if ($parentId === 0) {
    setFlash('error', 'شناسه والد مشخص نشده است.');
    redirect(url('admin/parents.php'));
}

$errors = [];
$impact = null;

try {
    $pdo = getDb();
    $impact = adminFamilyParentDeleteImpact($pdo, $parentId);
} catch (Throwable $e) {
    error_log($e->getMessage());
    setFlash('error', 'بارگذاری اطلاعات والد امکان‌پذیر نیست.');
    redirect(url('admin/parents.php'));
}

if ($impact === null) {
    setFlash('error', 'والد یافت نشد.');
    redirect(url('admin/parents.php'));
}

$parentRow = $impact['parent'];
$storedPhone = (string) $parentRow['phone'];
$hasPhone = $storedPhone !== '';

if (isPostRequest()) {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }

    // Strong confirmation: the admin must re-type the parent's mobile
    // number (any digit script / +98 form — both sides are normalised).
    // Parents without a stored phone fall back to typing the full
    // last name exactly as stored.
    $typed = trim((string) ($_POST['confirm_phone'] ?? ''));
    if ($errors === []) {
        if ($hasPhone) {
            $typedNorm = normalizeIranianPhone($typed);
            if ($typed === '' || $typedNorm === '' || $typedNorm !== normalizeIranianPhone($storedPhone)) {
                $errors[] = 'شماره موبایل واردشده با شماره این والد مطابقت ندارد؛ حذف انجام نشد.';
            }
        } else {
            if ($typed === '' || $typed !== trim((string) $parentRow['last_name'])) {
                $errors[] = 'نام خانوادگی واردشده با نام این والد مطابقت ندارد؛ حذف انجام نشد.';
            }
        }
    }

    if ($errors === []) {
        try {
            $result = adminFamilyDeleteParent($pdo, $parentId);
            $snap = $result['snapshot'];

            // Audit AFTER the deletion has committed: recordAudit()
            // resolves its own connection via getDb(), so it cannot be
            // part of the helper's transaction; it never throws, and the
            // full impact snapshot is preserved in the audit details.
            recordAudit('parent.delete', 'parent', $parentId, [
                'first_name' => $snap['parent']['first_name'],
                'last_name' => $snap['parent']['last_name'],
                'phone' => $snap['parent']['phone'],
                'email' => $snap['parent']['email'],
                'status' => $snap['parent']['status'],
                'counts' => $snap['counts'],
                'tuition_total_paid' => $snap['tuition_total_paid'],
                'deleted' => $result['deleted'],
            ]);

            // Files are removed only now — after a successful commit.
            foreach ($result['photo_paths'] as $photoPath) {
                adminFamilyDeleteChildPhoto($photoPath);
            }
            adminFamilyDeleteParentAvatar($result['avatar_path']);

            $childrenCount = (int) $snap['counts']['children'];
            setFlash(
                'success',
                'والد «' . $snap['parent']['full_name'] . '»'
                . ($childrenCount > 0 ? ' و ' . persianNumber($childrenCount) . ' کودک او' : '')
                . ' به‌طور کامل و دائمی حذف شدند.'
            );
            redirect(url('admin/parents.php'));
        } catch (Throwable $e) {
            error_log($e->getMessage());
            setFlash('error', 'حذف والد با مشکل مواجه شد؛ هیچ داده‌ای حذف نشده است.');
            redirect(url('admin/parents.php'));
        }
    }
}

$pageTitle = 'حذف والد | ' . siteName();
require_once __DIR__ . '/header.php';
$flashSuccess = getFlash('success');
$flashError = getFlash('error');
$counts = $impact['counts'];
$statusLabel = match ((string) $parentRow['status']) {
    'active' => 'فعال',
    'pending' => 'در انتظار تأیید',
    'suspended' => 'مسدود',
    default => (string) $parentRow['status'],
};
$childStatusLabels = [
    'active' => 'فعال', 'pending' => 'در انتظار', 'inactive' => 'غیرفعال',
    'graduated' => 'فارغ‌التحصیل', 'withdrawn' => 'انصراف‌داده',
];
?>
<section class="dashboard">
    <h1>حذف دائمی والد</h1>
    <p><a href="<?= e(url('admin/parents.php')) ?>">→ بازگشت به مدیریت والدین</a></p>

    <?php if ($flashSuccess !== null): ?><div class="notice" role="status"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError !== null): ?><div class="alert" role="alert"><?= e($flashError) ?></div><?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert" role="alert">
            <?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="form-container">
        <div class="form-card">
            <h2>مشخصات والد</h2>
            <div class="form-group">
                <label>نام و نام خانوادگی</label>
                <p style="margin:0;font-weight:700;"><?= e($parentRow['full_name']) ?></p>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>شماره موبایل</label>
                    <p style="margin:0;" dir="ltr"><?= $hasPhone ? e(persianNumber($storedPhone)) : '—' ?></p>
                </div>
                <div class="form-group">
                    <label>ایمیل</label>
                    <p style="margin:0;"><?= !empty($parentRow['email']) ? e((string) $parentRow['email']) : '—' ?></p>
                </div>
            </div>
            <div class="form-group">
                <label>وضعیت حساب</label>
                <p style="margin:0;"><?= e($statusLabel) ?></p>
            </div>
        </div>

        <div class="form-card">
            <h2>کودکان این والد (<?= e(persianNumber($counts['children'])) ?>)</h2>
            <?php if ($impact['children'] === []): ?>
                <p style="color:var(--adm-text-muted);margin:0;">کودکی برای این والد ثبت نشده است.</p>
            <?php else: ?>
                <ul style="margin:0;line-height:2;">
                    <?php foreach ($impact['children'] as $child): ?>
                        <li><?= e($child['full_name']) ?> — <?= e($childStatusLabels[$child['status']] ?? $child['status']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="form-card">
            <h2>چه داده‌هایی حذف می‌شود؟</h2>
            <ul style="margin:0;line-height:2.1;">
                <li>کودکان: <?= e(persianNumber($counts['children'])) ?> کودک، به‌همراه ثبت‌نام کلاس (<?= e(persianNumber($counts['child_classroom'])) ?>) و فهرست انتظار (<?= e(persianNumber($counts['classroom_waitlist'])) ?>)</li>
                <li>حضور و غیاب: <?= e(persianNumber($counts['attendance'])) ?> رکورد</li>
                <li>گزارش‌های روزانه: <?= e(persianNumber($counts['daily_reports'])) ?> رکورد</li>
                <li>برنامه‌های شهریه: <?= e(persianNumber($counts['tuition_plans'])) ?> مورد</li>
                <li>پرداخت‌های شهریه: <?= e(persianNumber($counts['tuition_payments'])) ?> پرداخت، به مبلغ کل <?= e(persianNumber(number_format($impact['tuition_total_paid']))) ?> تومان</li>
                <li>پیام‌های پرتال والد: <?= e(persianNumber($counts['messages'])) ?> پیام</li>
                <li>لینک‌های بازیابی رمز عبور فعال: <?= e(persianNumber($counts['password_reset_tokens'])) ?> مورد</li>
                <li>پیامک‌های در صف ارسال (ارسال‌نشده): <?= e(persianNumber($counts['sms_queue_pending'])) ?> مورد و کدهای تأیید فعال این شماره: <?= e(persianNumber($counts['sms_verification_codes'])) ?> مورد</li>
                <li>تصویر پروفایل والد و عکس کودکان از روی سرور</li>
            </ul>
            <p style="color:var(--adm-text-muted);line-height:1.9;margin-top:12px;">
                توجه: سابقه ارسال پیامک‌ها (sms_logs)، لاگ اعلان‌ها و لاگ حسابرسی (audit_log) جزو تاریخچه سیستم‌اند و با حذف حساب پاک نمی‌شوند؛ حذف این والد نیز با همین جزئیات در لاگ حسابرسی ثبت می‌شود.
            </p>
        </div>

        <form method="post" action="<?= e(url('admin/delete-parent.php?id=' . $parentId)) ?>" novalidate class="child-form">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="parent_id" value="<?= $parentId ?>">

            <div class="form-card" style="border-color:var(--adm-danger);">
                <h2 style="color:var(--adm-danger);">⚠️ حذف دائمی و غیرقابل بازگشت</h2>
                <p style="color:var(--adm-danger);line-height:1.9;font-weight:600;">
                    این عملیات قابل بازگشت نیست. والد، همه کودکان او و تمام سوابق بالا (از جمله سوابق مالی شهریه) برای همیشه از پایگاه داده حذف می‌شوند.
                </p>
                <?php if ($hasPhone): ?>
                    <div class="form-group">
                        <label for="confirm_phone">برای تأیید نهایی، شماره موبایل این والد را دوباره وارد کنید <span class="req">*</span></label>
                        <input type="tel" id="confirm_phone" name="confirm_phone" dir="ltr" autocomplete="off" placeholder="09123456789">
                        <small>شماره ثبت‌شده: <span dir="ltr"><?= e(persianNumber($storedPhone)) ?></span></small>
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label for="confirm_phone">این والد شماره موبایل ثبت‌شده ندارد؛ برای تأیید نهایی، نام خانوادگی او را دقیقاً وارد کنید <span class="req">*</span></label>
                        <input type="text" id="confirm_phone" name="confirm_phone" autocomplete="off">
                        <small>نام خانوادگی ثبت‌شده: <?= e((string) $parentRow['last_name']) ?></small>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <button class="btn btn-danger" type="submit">🗑 حذف دائمی والد و کودکان</button>
                <a class="btn btn-outline" href="<?= e(url('admin/parents.php')) ?>">انصراف</a>
            </div>
        </form>
    </div>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';

requireParentLogin();

function childFormStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function isValidChildName(string $name, int $maxLength = 100, bool $required = true): bool
{
    if ($name === '') {
        return !$required;
    }

    // Allow Persian/Arabic/English letters, spaces, hyphens
    return childFormStringLength($name) <= $maxLength
        && preg_match("/^[\p{L}\p{Z}\s'\-]+$/u", $name) === 1;
}

function isValidChildPhone(string $phone): bool
{
    return $phone === ''
        || (childFormStringLength($phone) <= 20 && preg_match('/\A[0-9+\-\s]{7,20}\z/', $phone) === 1);
}

function validChildDateOfBirth(string $value): ?string
{
    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        return null;
    }

    $today = new DateTimeImmutable('today');

    if ($date >= $today) {
        return null;
    }

    return $value;
}

function uploadChildPhoto(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('بارگذاری تصویر کودک با مشکل مواجه شد.');
    }

    if (($file['size'] ?? 0) > 512000) {
        throw new RuntimeException('حجم تصویر کودک باید ۵۰۰ کیلوبایت یا کمتر باشد.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('بارگذاری تصویر کودک نامعتبر است.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedTypes = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
    ];

    if (!array_key_exists($extension, $allowedTypes)) {
        throw new RuntimeException('تصویر کودک باید با فرمت JPG، PNG یا GIF باشد.');
    }

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);

        if (!is_string($mimeType) || !in_array($mimeType, $allowedTypes[$extension], true)) {
            throw new RuntimeException('نوع تصویر کودک نامعتبر است.');
        }
    }

    if (getimagesize($tmpName) === false) {
        throw new RuntimeException('تصویر کودک نامعتبر است.');
    }

    $uploadDir = __DIR__ . '/../assets/uploads/children';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('بارگذاری تصویر در دسترس نیست.');
    }

    $indexFile = $uploadDir . DIRECTORY_SEPARATOR . 'index.html';

    if (!is_file($indexFile)) {
        @touch($indexFile);
    }

    $fileName = uniqid('child_', true) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('بارگذاری تصویر در دسترس نیست.');
    }

    return 'assets/uploads/children/' . $fileName;
}

function deleteChildPhoto(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === '') {
        return;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    $childrenRoot = realpath(__DIR__ . '/../assets/uploads/children');

    if ($projectRoot === false || $childrenRoot === false) {
        return;
    }

    $candidate = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));

    if (
        $candidate !== false
        && str_starts_with($candidate, $childrenRoot . DIRECTORY_SEPARATOR)
        && is_file($candidate)
    ) {
        @unlink($candidate);
    }
}

$parentId = (int) $_SESSION['parent_id'];
$errors = [];
$old = [
    'first_name' => '',
    'last_name' => '',
    'preferred_name' => '',
    'date_of_birth' => '',
    'gender' => '',
    'allergies' => '',
    'medical_notes' => '',
    'second_guardian_name' => '',
    'second_guardian_phone' => '',
];

try {
    initializeParentTables();
    $pdo = getDb();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    setFlash('error', 'ثبت کودک موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.');
    redirect(url('parent/index.php'));
}

if (isPostRequest()) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $old = [
        'first_name' => trim((string) ($_POST['first_name'] ?? '')),
        'last_name' => trim((string) ($_POST['last_name'] ?? '')),
        'preferred_name' => trim((string) ($_POST['preferred_name'] ?? '')),
        'date_of_birth' => trim((string) ($_POST['date_of_birth'] ?? '')),
        'gender' => (string) ($_POST['gender'] ?? ''),
        'allergies' => trim((string) ($_POST['allergies'] ?? '')),
        'medical_notes' => trim((string) ($_POST['medical_notes'] ?? '')),
        'second_guardian_name' => trim((string) ($_POST['second_guardian_name'] ?? '')),
        'second_guardian_phone' => trim((string) ($_POST['second_guardian_phone'] ?? '')),
    ];
    $allowedGenders = ['', 'male', 'female', 'other'];
    $photoPath = null;

    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }

    if (!isValidChildName($old['first_name'])) {
        $errors[] = 'لطفاً نام معتبر وارد کنید.';
    }

    if (!isValidChildName($old['last_name'])) {
        $errors[] = 'لطفاً نام خانوادگی معتبر وارد کنید.';
    }

    if (!isValidChildName($old['preferred_name'], 100, false)) {
        $errors[] = 'لطفاً نام مستعار معتبر وارد کنید.';
    }

    $dateOfBirth = validChildDateOfBirth($old['date_of_birth']);

    if ($dateOfBirth === null) {
        $errors[] = 'لطفاً تاریخ تولد معتبر و قبل از امروز وارد کنید.';
    }

    if (!in_array($old['gender'], $allowedGenders, true)) {
        $errors[] = 'لطفاً جنسیت معتبر انتخاب کنید.';
    }

    if (childFormStringLength($old['allergies']) > 5000) {
        $errors[] = 'توضیحات حساسیت‌ها باید حداکثر ۵۰۰۰ کاراکتر باشد.';
    }

    if (childFormStringLength($old['medical_notes']) > 5000) {
        $errors[] = 'توضیحات پزشکی باید حداکثر ۵۰۰۰ کاراکتر باشد.';
    }

    if (!isValidChildName($old['second_guardian_name'], 200, false)) {
        $errors[] = 'لطفاً نام معتبر برای ولی دوم وارد کنید.';
    }

    if (!isValidChildPhone($old['second_guardian_phone'])) {
        $errors[] = 'لطفاً شماره تلفن معتبر برای ولی دوم وارد کنید.';
    }

    if ($errors === []) {
        try {
            $photoPath = uploadChildPhoto($_FILES['photo'] ?? []);

            $statement = $pdo->prepare(
                'INSERT INTO children (
                    parent_id,
                    first_name,
                    last_name,
                    preferred_name,
                    date_of_birth,
                    gender,
                    allergies,
                    medical_notes,
                    second_guardian_name,
                    second_guardian_phone,
                    photo,
                    status
                ) VALUES (
                    :parent_id,
                    :first_name,
                    :last_name,
                    :preferred_name,
                    :date_of_birth,
                    :gender,
                    :allergies,
                    :medical_notes,
                    :second_guardian_name,
                    :second_guardian_phone,
                    :photo,
                    :status
                )'
            );
            $statement->execute([
                ':parent_id' => $parentId,
                ':first_name' => $old['first_name'],
                ':last_name' => $old['last_name'],
                ':preferred_name' => $old['preferred_name'] === '' ? null : $old['preferred_name'],
                ':date_of_birth' => $dateOfBirth,
                ':gender' => $old['gender'],
                ':allergies' => $old['allergies'] === '' ? null : $old['allergies'],
                ':medical_notes' => $old['medical_notes'] === '' ? null : $old['medical_notes'],
                ':second_guardian_name' => $old['second_guardian_name'] === '' ? null : $old['second_guardian_name'],
                ':second_guardian_phone' => $old['second_guardian_phone'] === '' ? null : $old['second_guardian_phone'],
                ':photo' => $photoPath,
                ':status' => 'pending',
            ]);

            setFlash('success', 'کودک با موفقیت ثبت شد! پس از تأیید مدیر، ثبت‌نام فعال خواهد شد.');
            redirect(url('parent/index.php'));
        } catch (Throwable $exception) {
            if ($photoPath !== null) {
                deleteChildPhoto($photoPath);
            }

            error_log($exception->getMessage());
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'ثبت کودک با مشکل مواجه شد. لطفاً دوباره تلاش کنید.';
        }
    }
}

$pageTitle = 'افزودن کودک';
require_once __DIR__ . '/header.php';
?>

<p class="parent-back-link">
    <a href="<?= e(url('parent/index.php')) ?>">← بازگشت به داشبورد</a>
</p>

<div class="page-header">
    <h1>افزودن کودک ➕</h1>
    <p class="page-subtitle">اطلاعات فرزند خود را برای بررسی مدیر ثبت کنید</p>
</div>

<?php if ($errors !== []): ?>
    <div class="alert" role="alert">
        <?php foreach ($errors as $error): ?>
            <p><?= e($error)?></p>
        <?php endforeach; ?>
   </div>
<?php endif; ?>

<div class="form-container">
    <form class="child-form" method="post" action="<?= e(url('parent/add-child.php')) ?>" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

        <div class="form-card">
            <h2>اطلاعات کودک</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label for="first_name">نام</label>
                    <input type="text" id="first_name" name="first_name" maxlength="100" value="<?= e($old['first_name']) ?>" required>
             </div>

                <div class="form-group">
                    <label for="last_name">نام خانوادگی</label>
                    <input type="text" id="last_name" name="last_name" maxlength="100" value="<?= e($old['last_name']) ?>" required>
             </div>
         </div>

            <div class="form-grid">
                <div class="form-group">
                    <label for="preferred_name">نام مستعار (اختیاری)</label>
                    <input type="text" id="preferred_name" name="preferred_name" maxlength="100" value="<?= e($old['preferred_name']) ?>" placeholder="اختیاری">
             </div>

                <div class="form-group">
                    <label for="date_of_birth">تاریخ تولد</label>
                    <input type="date" id="date_of_birth" name="date_of_birth" value="<?= e($old['date_of_birth']) ?>" required>
             </div>
         </div>

            <div class="form-group">
                <label for="gender">جنسیت</label>
                <select id="gender" name="gender">
                    <option value="" <?= $old['gender'] === '' ? 'selected' : '' ?>>نامشخص</option>
                    <option value="male" <?= $old['gender'] === 'male' ? 'selected' : '' ?>>پسر</option>
                    <option value="female" <?= $old['gender'] === 'female' ? 'selected' : '' ?>>دختر</option>
                    <option value="other" <?= $old['gender'] === 'other' ? 'selected' : '' ?>>سایر</option>
               </select>
           </div>
       </div>

        <div class="form-card">
            <h2>اطلاعات سلامت</h2>
            <div class="form-group">
                <label for="allergies">حساسیت‌ها</label>
                <textarea id="allergies" name="allergies" maxlength="5000" rows="4"><?= e($old['allergies'])?></textarea>
                <small>هر نوع حساسیت شناخته‌شده‌ای که کودک شما دارد را بنویسید</small>
         </div>

            <div class="form-group">
                <label for="medical_notes">توضیحات پزشکی</label>
                <textarea id="medical_notes" name="medical_notes" maxlength="5000" rows="4"><?= e($old['medical_notes'])?></textarea>
                <small>بیماری‌ها یا نیازهای ویژه‌ای که باید از آن‌ها مطلع باشیم</small>
         </div>
     </div>

        <div class="form-card">
            <h2>ولی دوم (اختیاری)</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label for="second_guardian_name">نام و نام خانوادگی</label>
                    <input type="text" id="second_guardian_name" name="second_guardian_name" maxlength="200" value="<?= e($old['second_guardian_name']) ?>" placeholder="اختیاری">
             </div>

                <div class="form-group">
                    <label for="second_guardian_phone">شماره تماس</label>
                    <input type="tel" id="second_guardian_phone" name="second_guardian_phone" maxlength="20" value="<?= e($old['second_guardian_phone']) ?>" placeholder="اختیاری">
             </div>
         </div>
     </div>

        <div class="form-card">
            <h2>تصویر کودک (اختیاری)</h2>
            <div class="form-group">
                <label for="photo">بارگذاری تصویر</label>
                <input type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif">
                <small>فرمت‌های مجاز: JPG، PNG یا GIF. حداکثر حجم ۵۰۰ کیلوبایت</small>
         </div>
     </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">ارسال اطلاعات کودک</button>
            <a class="btn btn-outline" href="<?= e(url('parent/index.php')) ?>">انصراف</a>
     </div>
   </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

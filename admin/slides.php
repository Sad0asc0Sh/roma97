<?php
declare(strict_types=1);

// Increase memory and execution time limits for large slide processing (up to 50MB)
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', '300');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

function slideStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function findSlide(PDO $pdo, int $id): ?array
{
    try {
        $statement = $pdo->prepare('SELECT id, title, subtitle, image, image_mobile, sort_order, created_at FROM slides WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $slide = $statement->fetch();

        return $slide ?: null;
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'image_mobile')) {
            $statement = $pdo->prepare('SELECT id, title, subtitle, image, sort_order, created_at FROM slides WHERE id = :id LIMIT 1');
            $statement->execute([':id' => $id]);
            $slide = $statement->fetch();
            if ($slide) {
                $slide['image_mobile'] = null;
                return $slide;
            }
            return null;
        }
        throw $e;
    }
}

function getAllSlides(PDO $pdo, int $limit = 20, int $offset = 0): array
{
    try {
        $statement = $pdo->prepare(
            'SELECT id, title, subtitle, image, image_mobile, sort_order, created_at FROM slides ORDER BY sort_order ASC, created_at DESC'
            . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $statement->execute();

        return $statement->fetchAll();
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'image_mobile')) {
            $statement = $pdo->prepare(
                'SELECT id, title, subtitle, image, sort_order, created_at FROM slides ORDER BY sort_order ASC, created_at DESC'
                . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
            );
            $statement->execute();
            $slides = $statement->fetchAll();
            foreach ($slides as &$slide) {
                $slide['image_mobile'] = null;
            }
            unset($slide);
            return $slides;
        }
        throw $e;
    }
}

function countSlides(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM slides')->fetchColumn();
}

function parseSlideId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    return is_int($id) ? $id : 0;
}

function parseSortOrder(mixed $value): ?int
{
    $sortOrder = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0],
    ]);

    return is_int($sortOrder) ? $sortOrder : null;
}

function deleteSlideImage(string $relativePath): void
{
    if ($relativePath === '') {
        return;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    $uploadRoot = realpath(__DIR__ . '/../assets/uploads');

    if ($projectRoot === false || $uploadRoot === false) {
        return;
    }

    $candidate = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));

    if (
        $candidate !== false
        && str_starts_with($candidate, $uploadRoot . DIRECTORY_SEPARATOR)
        && is_file($candidate)
    ) {
        @unlink($candidate);
    }
}

/**
 * Safely processes, optimizes and stores an uploaded slide image.
 *
 * Supports files up to 50MB. If GD is available, oversized images (e.g. 6000x4000)
 * are downscaled smoothly to a reasonable resolution (max width 2560px for desktop,
 * 1200px for mobile) and saved as optimized WebP (or high-quality JPEG/PNG) to conserve
 * disk space and dramatically speed up hero slider rendering for website visitors.
 *
 * If GD is unavailable or encounters memory/parsing constraints, it gracefully falls back
 * to storing the original file directly.
 */
function processAndSaveSlideImage(string $tmpName, string $uploadDir, string $extension, bool $isMobile): string
{
    // Check if GD is available for server-side optimization
    if (extension_loaded('gd') && function_exists('imagecreatefromstring')) {
        try {
            @ini_set('memory_limit', '256M');
            $fileData = @file_get_contents($tmpName);

            if ($fileData !== false && $fileData !== '') {
                $img = @imagecreatefromstring($fileData);
                unset($fileData);

                if ($img !== false) {
                    $origWidth = imagesx($img);
                    $origHeight = imagesy($img);

                    $maxWidth = $isMobile ? 1200 : 2560;
                    $maxHeight = $isMobile ? 1600 : 1440;

                    $targetWidth = $origWidth;
                    $targetHeight = $origHeight;

                    if ($origWidth > $maxWidth || $origHeight > $maxHeight) {
                        $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
                        $targetWidth = (int) max(1, round($origWidth * $ratio));
                        $targetHeight = (int) max(1, round($origHeight * $ratio));
                    }

                    // Create truecolor canvas with transparency support
                    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);
                    $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
                    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);

                    imagecopyresampled(
                        $canvas,
                        $img,
                        0,
                        0,
                        0,
                        0,
                        $targetWidth,
                        $targetHeight,
                        $origWidth,
                        $origHeight
                    );
                    imagedestroy($img);

                    // Prefer WebP if supported, otherwise fallback to JPEG / PNG
                    $randomHex = bin2hex(random_bytes(16));
                    if (function_exists('imagewebp')) {
                        $targetFileName = 'slide-' . $randomHex . '.webp';
                        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetFileName;
                        if (@imagewebp($canvas, $targetPath, 85)) {
                            imagedestroy($canvas);
                            @chmod($targetPath, 0644);
                            return 'assets/uploads/' . $targetFileName;
                        }
                    } elseif ($extension === 'png' && function_exists('imagepng')) {
                        $targetFileName = 'slide-' . $randomHex . '.png';
                        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetFileName;
                        if (@imagepng($canvas, $targetPath, 8)) {
                            imagedestroy($canvas);
                            @chmod($targetPath, 0644);
                            return 'assets/uploads/' . $targetFileName;
                        }
                    } elseif (function_exists('imagejpeg')) {
                        $targetFileName = 'slide-' . $randomHex . '.jpg';
                        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetFileName;
                        if (@imagejpeg($canvas, $targetPath, 88)) {
                            imagedestroy($canvas);
                            @chmod($targetPath, 0644);
                            return 'assets/uploads/' . $targetFileName;
                        }
                    }

                    imagedestroy($canvas);
                }
            }
        } catch (Throwable $e) {
            error_log('Slide image optimization notice: ' . $e->getMessage());
        }
    }

    // Direct move fallback
    $fileName = 'slide-' . bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!@move_uploaded_file($tmpName, $destination)) {
        if (!@copy($tmpName, $destination)) {
            throw new RuntimeException('امکان ذخیره‌سازی تصویر در پوشه uploads وجود ندارد (بررسی دسترسی نوشتن).');
        }
        @unlink($tmpName);
    }

    @chmod($destination, 0644);
    return 'assets/uploads/' . $fileName;
}

function uploadSlideImage(array $file, bool $required, bool $isMobile = false): ?string
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            throw new RuntimeException('لطفاً یک تصویر برای اسلاید انتخاب کنید.');
        }

        return null;
    }

    if ($errorCode !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'حجم فایل بیشتر از محدودیت مجاز سرور (upload_max_filesize) است. حداکثر ۵۰ مگابایت مجاز است.',
            UPLOAD_ERR_FORM_SIZE  => 'حجم فایل بیشتر از حد مجاز فرم است.',
            UPLOAD_ERR_PARTIAL    => 'فایل فقط به صورت ناقص آپلود شد.',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت سرور برای آپلود فایل یافت نشد (upload_tmp_dir).',
            UPLOAD_ERR_CANT_WRITE => 'نوشتن فایل روی دیسک سرور با خطا مواجه شد.',
            UPLOAD_ERR_EXTENSION  => 'آپلود فایل توسط یک افزونه سرور متوقف شد.',
        ];
        throw new RuntimeException($uploadErrors[$errorCode] ?? ('خطای آپلود فایل (کد ' . $errorCode . ')'));
    }

    // Increase limit to 50 MB (52,428,800 bytes)
    $maxBytes = 50 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('حجم تصویر بیش از حد مجاز (حداکثر ۵۰ مگابایت) است.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || (!is_uploaded_file($tmpName) && !is_file($tmpName))) {
        throw new RuntimeException('فایل آپلود شده در مسیر موقت سرور یافت نشد.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedTypes = [
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png'  => ['image/png', 'image/x-png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    if (!array_key_exists($extension, $allowedTypes)) {
        throw new RuntimeException('فرمت تصویر مجاز نیست. لطفاً فرمت JPG، PNG، GIF یا WebP انتخاب کنید.');
    }

    if (class_exists('finfo')) {
        try {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($tmpName);

            if (is_string($mimeType) && $mimeType !== '' && $mimeType !== 'application/octet-stream') {
                if (!in_array($mimeType, $allowedTypes[$extension], true)) {
                    throw new RuntimeException('نوع فایل ارسالی با پسوند آن همخوانی ندارد.');
                }
            }
        } catch (Throwable $e) {
            // Fall back to getimagesize if finfo db is not available in environment
        }
    }

    if (@getimagesize($tmpName) === false) {
        throw new RuntimeException('فایل انتخاب‌شده یک تصویر معتبر نیست.');
    }

    $uploadDir = __DIR__ . '/../assets/uploads';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('پوشه ذخیره‌سازی assets/uploads در دسترس نیست.');
    }

    return processAndSaveSlideImage($tmpName, $uploadDir, $extension, $isMobile);
}

try {
    initializeCmsTables();
    $pdo = getDb();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    setFlash('error', 'اسلایدها موقتاً در دسترس نیستند. لطفاً بعداً دوباره تلاش کنید.');
    redirect(url('admin/index.php'));
}

if (isPostRequest()) {
    // Check if post_max_size was exceeded (causing PHP to drop $_POST and $_FILES)
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        setFlash('error', 'حجم فایل ارسالی بیشتر از محدودیت مجاز سرور (post_max_size) است. حداکثر حجم مجاز ۵۰ مگابایت می‌باشد.');
        redirect(url('admin/slides.php'));
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = (string) ($_POST['action'] ?? '');

    if (!validateCsrfToken($csrfToken)) {
        setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        redirect(url('admin/slides.php'));
    }

    try {
        if ($action === 'save_slide') {
            $slideId = parseSlideId($_POST['slide_id'] ?? null);
            $title = trim((string) ($_POST['title'] ?? ''));
            $subtitle = trim((string) ($_POST['subtitle'] ?? ''));
            $sortOrder = parseSortOrder($_POST['sort_order'] ?? null);
            $isEdit = $slideId > 0;
            $currentSlide = null;
            $newImage = null;
            $newImageMobile = null;

            if ($title === '' || slideStringLength($title) > 255 || $sortOrder === null) {
                setFlash('error', 'لطفاً عنوان و ترتیب نمایش معتبر وارد کنید.');
                redirect($isEdit ? url('admin/slides.php?edit=' . $slideId) : url('admin/slides.php'));
            }

            if ($isEdit) {
                $currentSlide = findSlide($pdo, $slideId);

                if ($currentSlide === null) {
                    setFlash('error', 'اسلاید پیدا نشد.');
                    redirect(url('admin/slides.php'));
                }
            }

            $newImage = uploadSlideImage($_FILES['image'] ?? [], !$isEdit, false);
            $imagePath = $newImage ?? (string) ($currentSlide['image'] ?? '');

            $newImageMobile = uploadSlideImage($_FILES['image_mobile'] ?? [], false, true);
            $imageMobilePath = $newImageMobile ?? (string) ($currentSlide['image_mobile'] ?? '');
            $imageMobilePath = $imageMobilePath !== '' ? $imageMobilePath : null;

            if ($imagePath === '') {
                setFlash('error', 'لطفاً تصویر معتبر اسلاید را بارگذاری کنید.');
                redirect($isEdit ? url('admin/slides.php?edit=' . $slideId) : url('admin/slides.php'));
            }

            if ($isEdit) {
                try {
                    $statement = $pdo->prepare(
                        'UPDATE slides SET title = :title, subtitle = :subtitle, image = :image, image_mobile = :image_mobile, sort_order = :sort_order WHERE id = :id'
                    );
                    $statement->execute([
                        ':title' => $title,
                        ':subtitle' => $subtitle !== '' ? $subtitle : null,
                        ':image' => $imagePath,
                        ':image_mobile' => $imageMobilePath,
                        ':sort_order' => $sortOrder,
                        ':id' => $slideId,
                    ]);
                } catch (PDOException $e) {
                    if (str_contains($e->getMessage(), 'image_mobile')) {
                        $statement = $pdo->prepare(
                            'UPDATE slides SET title = :title, subtitle = :subtitle, image = :image, sort_order = :sort_order WHERE id = :id'
                        );
                        $statement->execute([
                            ':title' => $title,
                            ':subtitle' => $subtitle !== '' ? $subtitle : null,
                            ':image' => $imagePath,
                            ':sort_order' => $sortOrder,
                            ':id' => $slideId,
                        ]);
                    } else {
                        throw $e;
                    }
                }

                if ($newImage !== null) {
                    deleteSlideImage((string) $currentSlide['image']);
                }
                if ($newImageMobile !== null && !empty($currentSlide['image_mobile'])) {
                    deleteSlideImage((string) $currentSlide['image_mobile']);
                }

                recordAudit('slide.update', 'slide', (int) $slideId);
                setFlash('success', 'اسلاید با موفقیت بهروزرسانی شد.');
                redirect(url('admin/slides.php'));
            }

            try {
                $statement = $pdo->prepare(
                    'INSERT INTO slides (title, subtitle, image, image_mobile, sort_order) VALUES (:title, :subtitle, :image, :image_mobile, :sort_order)'
                );
                $statement->execute([
                    ':title' => $title,
                    ':subtitle' => $subtitle !== '' ? $subtitle : null,
                    ':image' => $imagePath,
                    ':image_mobile' => $imageMobilePath,
                    ':sort_order' => $sortOrder,
                ]);
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'image_mobile')) {
                    $statement = $pdo->prepare(
                        'INSERT INTO slides (title, subtitle, image, sort_order) VALUES (:title, :subtitle, :image, :sort_order)'
                    );
                    $statement->execute([
                        ':title' => $title,
                        ':subtitle' => $subtitle !== '' ? $subtitle : null,
                        ':image' => $imagePath,
                        ':sort_order' => $sortOrder,
                    ]);
                } else {
                    throw $e;
                }
            }

            recordAudit('slide.create', 'slide', (int) $pdo->lastInsertId());
            setFlash('success', 'اسلاید با موفقیت ایجاد شد.');
            redirect(url('admin/slides.php'));
        }

        if ($action === 'delete_slide') {
            $slideId = parseSlideId($_POST['slide_id'] ?? null);
            $slide = $slideId > 0 ? findSlide($pdo, $slideId) : null;

            if ($slide === null) {
                setFlash('error', 'اسلاید پیدا نشد.');
                redirect(url('admin/slides.php'));
            }

            $statement = $pdo->prepare('DELETE FROM slides WHERE id = :id');
            $statement->execute([':id' => $slideId]);
            deleteSlideImage((string) $slide['image']);
            if (!empty($slide['image_mobile'])) {
                deleteSlideImage((string) $slide['image_mobile']);
            }

            recordAudit('slide.delete', 'slide', (int) $slideId);
            setFlash('success', 'اسلاید با موفقیت حذف شد.');
            redirect(url('admin/slides.php'));
        }

        if ($action === 'update_sort') {
            $sortOrders = $_POST['sort_order'] ?? [];

            if (!is_array($sortOrders)) {
                setFlash('error', 'مقادیر ترتیب نمایش نامعتبر است.');
                redirect(url('admin/slides.php'));
            }

            $pdo->beginTransaction();
            $statement = $pdo->prepare('UPDATE slides SET sort_order = :sort_order WHERE id = :id');

            foreach ($sortOrders as $id => $value) {
                $slideId = parseSlideId($id);
                $sortOrder = parseSortOrder($value);

                if ($slideId === 0 || $sortOrder === null) {
                    $pdo->rollBack();
                    setFlash('error', 'مقادیر ترتیب نمایش باید اعداد صحیح از ۰ باشند.');
                    redirect(url('admin/slides.php'));
                }

                $statement->execute([
                    ':sort_order' => $sortOrder,
                    ':id' => $slideId,
                ]);
            }

            $pdo->commit();
            recordAudit('slide.reorder', 'slide');
            setFlash('success', 'ترتیب نمایش اسلایدها با موفقیت بهروزرسانی شد.');
            redirect(url('admin/slides.php'));
        }

        setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        redirect(url('admin/slides.php'));
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (isset($newImage) && is_string($newImage)) {
            deleteSlideImage($newImage);
        }

        if (isset($newImageMobile) && is_string($newImageMobile)) {
            deleteSlideImage($newImageMobile);
        }

        error_log($exception->getMessage());
        $msg = $exception->getMessage() !== ''
            ? 'اسلاید ذخیره نشد: ' . $exception->getMessage()
            : 'اسلایدها ذخیره نشدند. لطفاً فرم را بررسی کرده و دوباره تلاش کنید.';
        setFlash('error', $msg);
        redirect(url('admin/slides.php'));
    }
}

$editId = parseSlideId($_GET['edit'] ?? null);
$deleteId = parseSlideId($_GET['delete'] ?? null);
$editSlide = $editId > 0 ? findSlide($pdo, $editId) : null;
$deleteSlide = $deleteId > 0 ? findSlide($pdo, $deleteId) : null;
$perPage = 20;
$pagination = paginate(countSlides($pdo), currentPageNumber(), $perPage);
$slides = getAllSlides($pdo, $pagination['perPage'], $pagination['offset']);
$successMessage = getFlash('success');
$errorMessage = getFlash('error');

$pageTitle = 'مدیریت اسلایدها | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <div class="app-toolbar">
        <h1 class="m-0 font-bold" style="font-size:1.5rem;color:var(--adm-text);">مدیریت اسلایدها</h1>
        <div class="app-toolbar-actions">
            <button type="button" class="app-btn app-btn-primary" onclick="openSlideDrawer()">
                + افزودن اسلاید جدید
            </button>
        </div>
    </div>

    <?php if ($successMessage !== null): ?>
        <script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($successMessage) ?>, 'success'));</script>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($errorMessage) ?>, 'danger'));</script>
        <div class="alert alert-danger" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <template id="slideFormTemplate">
        <div class="slide-guide-card">
            <div class="slide-guide-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <span>راهنما و مشخصات بارگذاری بنر اسلایدر</span>
            </div>
            <div class="slide-guide-grid">
                <div class="slide-guide-item">
                    <span class="slide-guide-label">سقف حجم مجاز:</span>
                    <span class="slide-guide-val highlight">۵۰ مگابایت (همراه با بهینه‌سازی خودکار)</span>
                </div>
                <div class="slide-guide-item">
                    <span class="slide-guide-label">ابعاد دسکتاپ:</span>
                    <span class="slide-guide-val">۱۹۲۰×۶۰۰ پیکسل (نسبت عریض ۱۶:۵)</span>
                </div>
                <div class="slide-guide-item">
                    <span class="slide-guide-label">تصویر موبایل (اختیاری):</span>
                    <span class="slide-guide-val">۱۰۰۰×۷۵۰ پیکسل (نسبت ۴:۳)</span>
                </div>
                <div class="slide-guide-item">
                    <span class="slide-guide-label">فرمت‌های مجاز:</span>
                    <span class="slide-guide-val">WebP, JPG, PNG, GIF</span>
                </div>
            </div>
        </div>

        <form id="slideUploadForm" method="post" action="<?= e(url('admin/slides.php')) ?>" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_slide">
            <?php if ($editSlide): ?>
                <input type="hidden" name="slide_id" value="<?= e((string) $editSlide['id']) ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="title" class="form-label">عنوان اسلاید <span class="req">*</span></label>
                <input type="text" id="title" name="title" class="form-control"
                    maxlength="255"
                    placeholder="عنوان اسلاید را وارد کنید..."
                    value="<?= e($editSlide['title'] ?? '') ?>"
                    required>
            </div>

            <div class="form-group">
                <label for="subtitle" class="form-label">زیرعنوان <span style="color:var(--adm-text-muted);font-weight:400">(اختیاری)</span></label>
                <input type="text" id="subtitle" name="subtitle" class="form-control"
                    maxlength="500"
                    placeholder="متن توضیحی زیر عنوان بنر"
                    value="<?= e($editSlide['subtitle'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="sort_order" class="form-label">ترتیب نمایش <span class="req">*</span></label>
                <input type="number" id="sort_order" name="sort_order" class="form-control"
                    style="max-width:150px;"
                    min="0" step="1"
                    value="<?= e((string) ($editSlide['sort_order'] ?? '0')) ?>"
                    required>
            </div>

            <!-- DESKTOP SLIDE UPLOAD ZONE -->
            <div class="form-group">
                <label class="form-label">
                    تصویر بنر دسکتاپ <?= $editSlide ? '<span style="color:var(--adm-text-muted);font-weight:400">(اختیاری - برای تغییر)</span>' : '<span class="req">* (الزامی)</span>' ?>
                </label>

                <?php if ($editSlide): ?>
                    <div id="desktopCurrentPreview" class="slide-preview-card" style="margin-bottom:12px;">
                        <div class="slide-preview-header">
                            <span class="slide-preview-name">تصویر دسکتاپ فعال فعلی</span>
                            <span class="slide-stat-chip success">فعال در سایت</span>
                        </div>
                        <div class="slide-preview-img-box">
                            <img src="<?= e(url($editSlide['image'])) ?>" alt="<?= e($editSlide['title']) ?>" class="slide-preview-img">
                        </div>
                        <small style="color:var(--adm-text-muted);font-size:0.8rem;">در صورت تمایل به تغییر، از کادر زیر تصویر جدید تا سقف ۵۰ مگابایت بارگذاری کنید.</small>
                    </div>
                <?php endif; ?>

                <div id="desktopDropzone" class="slide-dropzone" tabindex="0" role="button" aria-label="انتخاب تصویر دسکتاپ">
                    <input type="file" id="image" name="image" class="slide-file-input" style="display:none;"
                        accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif"
                        <?= $editSlide ? '' : 'required' ?>>

                    <div class="slide-dropzone-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                    </div>
                    <div class="slide-dropzone-title">تصویر بنر دسکتاپ را به اینجا بکشید یا کلیک کنید</div>
                    <div class="slide-dropzone-sub">تصویر اصلی با کیفیت بالا برای نمایش در رایانه و لپ‌تاپ</div>
                    <div class="slide-dropzone-badges">
                        <span class="slide-badge primary">حداکثر ۵۰ مگابایت</span>
                        <span class="slide-badge">پیشنهادی: ۱۹۲۰×۶۰۰ px</span>
                        <span class="slide-badge">WebP, JPG, PNG</span>
                    </div>
                </div>

                <!-- Live Preview Card for Desktop (hidden by default) -->
                <div id="desktopLivePreview" class="slide-preview-card" style="display:none;margin-top:10px;">
                    <div class="slide-preview-header">
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span class="slide-stat-chip success">تصویر انتخاب‌شده</span>
                            <span id="desktopFileName" class="slide-preview-name"></span>
                        </div>
                    </div>
                    <div class="slide-preview-img-box">
                        <img id="desktopPreviewImg" src="" alt="پیش‌نمایش دسکتاپ" class="slide-preview-img">
                    </div>
                    <div class="slide-preview-meta">
                        <div class="slide-preview-stats">
                            <span class="slide-stat-chip">حجم فایل: <strong id="desktopFileSize">-</strong></span>
                            <span class="slide-stat-chip">ابعاد: <strong id="desktopFileDimensions">-</strong></span>
                            <span id="desktopRatioChip" class="slide-stat-chip"></span>
                        </div>
                        <div id="desktopOptBanner" class="slide-opt-banner" style="display:none;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                            <span id="desktopOptText">بهینه‌سازی هوشمند خودکار آماده اجراست.</span>
                        </div>
                    </div>
                    <div class="slide-preview-actions">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="triggerFileInput('image')">تغییر تصویر</button>
                        <button type="button" class="btn btn-sm btn-reject" onclick="clearSelectedFile('image', 'desktop')">حذف انتخاب</button>
                    </div>
                </div>
            </div>

            <!-- MOBILE SLIDE UPLOAD ZONE -->
            <div class="form-group" style="margin-top:1.5rem;">
                <label class="form-label">
                    تصویر اختصاصی موبایل <span style="color:var(--adm-text-muted);font-weight:400">(اختیاری - برای نمایش بهینه در گوشی)</span>
                </label>

                <?php if ($editSlide && !empty($editSlide['image_mobile'])): ?>
                    <div id="mobileCurrentPreview" class="slide-preview-card" style="margin-bottom:12px;">
                        <div class="slide-preview-header">
                            <span class="slide-preview-name">تصویر موبایل فعلی</span>
                            <span class="slide-stat-chip success">فعال روی گوشی</span>
                        </div>
                        <div class="slide-preview-img-box">
                            <img src="<?= e(url($editSlide['image_mobile'])) ?>" alt="موبایل - <?= e($editSlide['title']) ?>" class="slide-preview-img mobile">
                        </div>
                        <small style="color:var(--adm-text-muted);font-size:0.8rem;">در صورت تمایل به تغییر، از کادر زیر تصویر جدید تا سقف ۵۰ مگابایت بارگذاری کنید.</small>
                    </div>
                <?php endif; ?>

                <div id="mobileDropzone" class="slide-dropzone" tabindex="0" role="button" aria-label="انتخاب تصویر موبایل">
                    <input type="file" id="image_mobile" name="image_mobile" class="slide-file-input" style="display:none;"
                        accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">

                    <div class="slide-dropzone-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="5" y="2" width="14" height="20" rx="2" ry="2"/>
                            <line x1="12" y1="18" x2="12.01" y2="18"/>
                        </svg>
                    </div>
                    <div class="slide-dropzone-title">تصویر موبایل را به اینجا بکشید یا کلیک کنید</div>
                    <div class="slide-dropzone-sub">جلوگیری از برش تصویر در صفحات باریک گوشی‌های هوشمند (اختیاری)</div>
                    <div class="slide-dropzone-badges">
                        <span class="slide-badge primary">حداکثر ۵۰ مگابایت</span>
                        <span class="slide-badge">پیشنهادی: ۱۰۰۰×۷۵۰ px (۴:۳)</span>
                        <span class="slide-badge">WebP, JPG, PNG</span>
                    </div>
                </div>

                <!-- Live Preview Card for Mobile (hidden by default) -->
                <div id="mobileLivePreview" class="slide-preview-card" style="display:none;margin-top:10px;">
                    <div class="slide-preview-header">
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span class="slide-stat-chip success">تصویر موبایل انتخاب‌شده</span>
                            <span id="mobileFileName" class="slide-preview-name"></span>
                        </div>
                    </div>
                    <div class="slide-preview-img-box">
                        <img id="mobilePreviewImg" src="" alt="پیش‌نمایش موبایل" class="slide-preview-img mobile">
                    </div>
                    <div class="slide-preview-meta">
                        <div class="slide-preview-stats">
                            <span class="slide-stat-chip">حجم فایل: <strong id="mobileFileSize">-</strong></span>
                            <span class="slide-stat-chip">ابعاد: <strong id="mobileFileDimensions">-</strong></span>
                            <span id="mobileRatioChip" class="slide-stat-chip"></span>
                        </div>
                        <div id="mobileOptBanner" class="slide-opt-banner" style="display:none;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                            <span id="mobileOptText">بهینه‌سازی هوشمند خودکار آماده اجراست.</span>
                        </div>
                    </div>
                    <div class="slide-preview-actions">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="triggerFileInput('image_mobile')">تغییر تصویر</button>
                        <button type="button" class="btn btn-sm btn-reject" onclick="clearSelectedFile('image_mobile', 'mobile')">حذف انتخاب</button>
                    </div>
                </div>
            </div>

            <div class="form-actions" style="margin-top:2rem;">
                <button type="submit" id="slideSubmitBtn" class="app-btn app-btn-primary btn btn-primary" style="width:100%">
                    <span id="slideSubmitBtnText"><?= $editSlide ? 'به‌روزرسانی اسلاید' : 'افزودن اسلاید' ?></span>
                </button>
            </div>
        </form>
    </template>

    <div class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title">همه اسلایدها</h2>
        </div>

        <?php if ($slides === []): ?>
            <div class="empty-state empty-state-sm">
                <div class="empty-state-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </div>
                <h3>هنوز اسلایدی اضافه نشده</h3>
                <p>از فرم بالا اولین اسلاید خود را اضافه کنید.</p>
            </div>
        <?php else: ?>
            <form method="post" action="<?= e(url('admin/slides.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_sort">

                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>تصویر</th>
                                <th>عنوان</th>
                                <th>ترتیب نمایش</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($slides as $slide): ?>
                                <tr>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <img src="<?= e(url($slide['image'])) ?>" alt="<?= e($slide['title']) ?>" class="admin-slide-thumb" title="تصویر دسکتاپ">
                                            <?php if (!empty($slide['image_mobile'])): ?>
                                                <img src="<?= e(url($slide['image_mobile'])) ?>" alt="تصویر موبایل" class="admin-slide-thumb" style="width:36px;height:36px;border:2px solid #3D8B63;border-radius:6px;object-fit:cover;" title="دارای تصویر اختصاصی موبایل">
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="font-weight:600;"><?= e($slide['title']) ?></td>
                                    <td>
                                        <input type="number"
                                            name="sort_order[<?= e($slide['id']) ?>]"
                                            min="0" step="1"
                                            value="<?= e($slide['sort_order']) ?>"
                                            class="admin-sort-input"
                                            required>
                                    </td>
                                    <td>
                                        <a href="<?= e(url('admin/slides.php?edit=' . $slide['id'])) ?>" class="btn btn-sm btn-secondary">ویرایش</a>
                                        <form method="post" action="<?= e(url('admin/slides.php')) ?>" class="inline-form" data-confirm="آیا از حذف اسلاید «<?= e($slide['title']) ?>» اطمینان دارید؟ این عملیات قابل بازگشت نیست.">
                                            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete_slide">
                                            <input type="hidden" name="slide_id" value="<?= e((string) $slide['id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-reject">حذف</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-sm">بهروزرسانی ترتیب نمایش</button>
                </div>
            </form>
            <?php if ($pagination['total'] > $pagination['perPage']): ?>
                <p class="pagination-summary">
                    نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> اسلاید
                </p>
                <?= renderPagination($pagination, url('admin/slides.php')) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<script>
function openSlideDrawer() {
    const tmpl = document.getElementById('slideFormTemplate');
    if (tmpl) {
        openDrawer(<?= $editSlide ? json_encode('ویرایش اسلاید «' . $editSlide['title'] . '»') : json_encode('افزودن اسلاید جدید') ?>, tmpl.innerHTML);
        initSlideUploadUI();
    }
}

function toPersianDigits(str) {
    const pDigits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return String(str).replace(/[0-9]/g, w => pDigits[+w]);
}

function formatPersianFileSize(bytes) {
    if (!bytes || bytes === 0) return '۰ بایت';
    const k = 1024;
    const sizes = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    const val = (bytes / Math.pow(k, i)).toFixed(i > 1 ? 1 : 0);
    return toPersianDigits(val) + ' ' + sizes[i];
}

function triggerFileInput(id) {
    const input = document.getElementById(id);
    if (input) input.click();
}

function clearSelectedFile(inputId, prefix) {
    const input = document.getElementById(inputId);
    if (input) {
        input.value = '';
    }
    const previewCard = document.getElementById(prefix + 'LivePreview');
    const dropzone = document.getElementById(prefix + 'Dropzone');
    if (previewCard) previewCard.style.display = 'none';
    if (dropzone) {
        dropzone.style.display = '';
        delete dropzone._optimizedBlob;
        delete dropzone._optimizedFileName;
    }
}

function initSlideUploadUI() {
    setupSlideDropzone('desktop', 'image', false);
    setupSlideDropzone('mobile', 'image_mobile', true);
    setupSlideFormSubmit();
}

function setupSlideDropzone(prefix, inputId, isMobile) {
    const dropzone = document.getElementById(prefix + 'Dropzone');
    const input = document.getElementById(inputId);
    const previewCard = document.getElementById(prefix + 'LivePreview');
    if (!dropzone || !input || !previewCard) return;

    // Click to open file chooser
    dropzone.addEventListener('click', () => input.click());
    dropzone.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            input.click();
        }
    });

    // Drag and Drop listeners
    ['dragenter', 'dragover'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('is-dragover');
        });
    });

    ['dragleave', 'dragend'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove('is-dragover');
        });
    });

    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('is-dragover');
        if (e.dataTransfer && e.dataTransfer.files.length > 0) {
            handleFileSelection(e.dataTransfer.files[0], prefix, input, isMobile);
        }
    });

    input.addEventListener('change', () => {
        if (input.files && input.files.length > 0) {
            handleFileSelection(input.files[0], prefix, input, isMobile);
        }
    });
}

function handleFileSelection(file, prefix, input, isMobile) {
    const maxBytes = 50 * 1024 * 1024; // 50MB
    const dropzone = document.getElementById(prefix + 'Dropzone');
    const previewCard = document.getElementById(prefix + 'LivePreview');
    const previewImg = document.getElementById(prefix + 'PreviewImg');
    const nameEl = document.getElementById(prefix + 'FileName');
    const sizeEl = document.getElementById(prefix + 'FileSize');
    const dimEl = document.getElementById(prefix + 'FileDimensions');
    const ratioEl = document.getElementById(prefix + 'RatioChip');
    const optBanner = document.getElementById(prefix + 'OptBanner');
    const optText = document.getElementById(prefix + 'OptText');

    if (!file) return;

    // Validate mime type & extension
    const validExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    const ext = file.name.split('.').pop().toLowerCase();
    const isImageMime = file.type.startsWith('image/');
    if (!isImageMime && !validExts.includes(ext)) {
        if (typeof showToast === 'function') {
            showToast('فرمت فایل مجاز نیست. لطفاً تصویر JPG، PNG، WebP یا GIF انتخاب کنید.', 'danger');
        } else {
            alert('فرمت فایل مجاز نیست. لطفاً تصویر JPG، PNG، WebP یا GIF انتخاب کنید.');
        }
        input.value = '';
        return;
    }

    // Validate size limit (50MB)
    if (file.size > maxBytes) {
        const mbSize = (file.size / (1024 * 1024)).toFixed(1);
        if (typeof showToast === 'function') {
            showToast(`حجم فایل (${toPersianDigits(mbSize)} مگابایت) بیشتر از سقف مجاز ۵۰ مگابایت است.`, 'danger');
        } else {
            alert(`حجم فایل (${toPersianDigits(mbSize)} مگابایت) بیشتر از سقف مجاز ۵۰ مگابایت است.`);
        }
        input.value = '';
        return;
    }

    // Sync input files if set via drag & drop
    if (window.DataTransfer && (!input.files || input.files[0] !== file)) {
        try {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
        } catch (e) {}
    }

    const objectUrl = URL.createObjectURL(file);
    previewImg.src = objectUrl;
    nameEl.textContent = file.name;
    sizeEl.textContent = formatPersianFileSize(file.size);
    dimEl.textContent = 'در حال بررسی ابعاد...';

    // Show preview card, hide dropzone
    dropzone.style.display = 'none';
    previewCard.style.display = 'flex';

    // Read natural dimensions
    const imgObj = new Image();
    imgObj.onload = function() {
        const w = imgObj.naturalWidth;
        const h = imgObj.naturalHeight;
        dimEl.textContent = `${toPersianDigits(w)} × ${toPersianDigits(h)} پیکسل`;

        // Aspect ratio analysis
        const ratio = w / (h || 1);
        if (!isMobile) {
            if (ratio >= 2.4) {
                ratioEl.textContent = 'نسبت عریض ایده‌آل (۱۶:۵)';
                ratioEl.className = 'slide-stat-chip success';
            } else if (ratio >= 1.6) {
                ratioEl.textContent = 'نسبت مناسب';
                ratioEl.className = 'slide-stat-chip';
            } else {
                ratioEl.textContent = 'نسبت عمودی یا مربعی (پیشنهاد: تصویر عریض)';
                ratioEl.className = 'slide-stat-chip';
            }
        } else {
            if (ratio <= 1.5 && ratio >= 0.7) {
                ratioEl.textContent = 'نسبت مناسب موبایل (~۴:۳)';
                ratioEl.className = 'slide-stat-chip success';
            } else {
                ratioEl.textContent = `نسبت: ${toPersianDigits(ratio.toFixed(2))}`;
                ratioEl.className = 'slide-stat-chip';
            }
        }

        // Smart client-side pre-optimization for large files
        const maxWidth = isMobile ? 1200 : 2560;
        const shouldOptimize = file.size > 2 * 1024 * 1024 || w > maxWidth;

        if (shouldOptimize && window.HTMLCanvasElement) {
            try {
                let targetW = w;
                let targetH = h;
                if (w > maxWidth) {
                    const scale = maxWidth / w;
                    targetW = Math.round(w * scale);
                    targetH = Math.round(h * scale);
                }

                const canvas = document.createElement('canvas');
                canvas.width = targetW;
                canvas.height = targetH;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(imgObj, 0, 0, targetW, targetH);

                const mimeType = 'image/webp';
                canvas.toBlob((blob) => {
                    if (blob && blob.size < file.size) {
                        dropzone._optimizedBlob = blob;
                        dropzone._optimizedFileName = file.name.replace(/\.[^.]+$/, '') + '.webp';

                        const savings = Math.max(1, Math.round((1 - (blob.size / file.size)) * 100));
                        optText.textContent = `بهینه‌سازی هوشمند: حجم اصلی ${formatPersianFileSize(file.size)} به ${formatPersianFileSize(blob.size)} فشرده می‌شود (${toPersianDigits(savings)}٪ صرفه‌جویی در ترافیک)`;
                        optBanner.style.display = 'flex';
                    } else {
                        optBanner.style.display = 'none';
                        delete dropzone._optimizedBlob;
                    }
                }, mimeType, 0.90);
            } catch (err) {
                optBanner.style.display = 'none';
            }
        } else {
            optBanner.style.display = 'none';
            delete dropzone._optimizedBlob;
        }
    };
    imgObj.src = objectUrl;
}

function setupSlideFormSubmit() {
    const form = document.getElementById('slideUploadForm');
    const submitBtn = document.getElementById('slideSubmitBtn');
    const btnText = document.getElementById('slideSubmitBtnText');
    if (!form || !submitBtn) return;

    form.addEventListener('submit', function(e) {
        const titleInput = document.getElementById('title');
        const imageInput = document.getElementById('image');
        const isEdit = form.querySelector('input[name="slide_id"]') !== null;

        if (titleInput && titleInput.value.trim() === '') {
            e.preventDefault();
            if (typeof showToast === 'function') showToast('لطفاً عنوان اسلاید را وارد کنید.', 'danger');
            titleInput.focus();
            return;
        }

        if (!isEdit && imageInput && (!imageInput.files || imageInput.files.length === 0)) {
            e.preventDefault();
            if (typeof showToast === 'function') showToast('لطفاً تصویر دسکتاپ اسلاید را انتخاب کنید.', 'danger');
            return;
        }

        // Apply pre-optimized blobs if DataTransfer is supported
        if (window.DataTransfer) {
            ['desktop', 'mobile'].forEach((prefix) => {
                const dropzone = document.getElementById(prefix + 'Dropzone');
                const inputId = prefix === 'desktop' ? 'image' : 'image_mobile';
                const input = document.getElementById(inputId);
                if (dropzone && dropzone._optimizedBlob && input && input.files && input.files.length > 0) {
                    try {
                        const optFile = new File([dropzone._optimizedBlob], dropzone._optimizedFileName || 'slide.webp', {
                            type: dropzone._optimizedBlob.type,
                            lastModified: Date.now()
                        });
                        const dt = new DataTransfer();
                        dt.items.add(optFile);
                        input.files = dt.files;
                    } catch (err) {}
                }
            });
        }

        // Show spinner and disable button
        submitBtn.disabled = true;
        btnText.innerHTML = '<span class="slide-spinner"></span> در حال ذخیره‌سازی و بهینه‌سازی اسلاید...';
    });
}

<?php if ($editSlide): ?>
document.addEventListener('DOMContentLoaded', () => {
    openSlideDrawer();
});
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
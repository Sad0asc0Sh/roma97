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

            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1');

            if (!is_array($sortOrders)) {
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'مقادیر ترتیب نمایش نامعتبر است.']);
                    exit;
                }
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
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error' => 'مقادیر ترتیب نمایش باید اعداد صحیح از ۰ باشند.']);
                        exit;
                    }
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

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => 'ترتیب نمایش اسلایدها با موفقیت به‌روزرسانی شد.']);
                exit;
            }

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

<div class="admin-content">
    <div class="app-toolbar">
        <div style="display:flex;align-items:center;gap:12px;">
            <h1 class="m-0 font-bold" style="font-size:1.5rem;color:var(--adm-text);">مدیریت اسلایدها</h1>
            <span style="background:var(--adm-bg);padding:4px 12px;border-radius:20px;border:1px solid var(--adm-border);font-size:0.8rem;font-weight:600;color:var(--adm-text-secondary);">
                <?= persianNumber((string) countSlides($pdo)) ?> اسلاید
            </span>
        </div>
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
        <form id="slideUploadForm" method="post" action="<?= e(url('admin/slides.php')) ?>" enctype="multipart/form-data" novalidate style="padding: 6px 0 0;">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_slide">
            <?php if ($editSlide): ?>
                <input type="hidden" name="slide_id" value="<?= e((string) $editSlide['id']) ?>">
            <?php endif; ?>

            <div class="news-banner-header" style="margin-bottom:18px;">
                <div class="news-banner-title">
                    <span><?= $editSlide ? 'ویرایش اسلاید' : 'افزودن اسلاید جدید' ?></span>
                    <div class="news-banner-plus-icon">
                        <?= $editSlide ? '✎' : '+' ?>
                    </div>
                </div>
            </div>

            <div class="slide-form-grid">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="title" class="form-label">عنوان اسلاید <span style="color:#EF4444;">*</span></label>
                    <input type="text" id="title" name="title" class="form-control"
                        maxlength="255"
                        placeholder="عنوان اصلی اسلاید..."
                        value="<?= e($editSlide['title'] ?? '') ?>"
                        required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="sort_order" class="form-label">ترتیب نمایش <span style="color:#EF4444;">*</span></label>
                    <input type="number" id="sort_order" name="sort_order" class="form-control"
                        min="0" step="1"
                        value="<?= e((string) ($editSlide['sort_order'] ?? '0')) ?>"
                        required>
                </div>
            </div>

            <div class="form-group" style="margin-top:14px;">
                <label for="subtitle" class="form-label">زیرعنوان <span style="color:var(--adm-text-muted);font-weight:400">(اختیاری)</span></label>
                <input type="text" id="subtitle" name="subtitle" class="form-control"
                    maxlength="500"
                    placeholder="توضیح مختصر زیر عنوان بنر..."
                    value="<?= e($editSlide['subtitle'] ?? '') ?>">
            </div>

            <!-- Desktop Banner Studio Canvas -->
            <div class="form-group" style="margin-top:16px;">
                <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="display:inline-flex;align-items:center;gap:6px;">
                        <span>تصویر بنر دسکتاپ <?= $editSlide ? '<span style="color:var(--adm-text-muted);font-weight:400">(اختیاری)</span>' : '<span style="color:#EF4444;">*</span>' ?></span>
                        <button type="button" class="slide-prompt-btn" onclick="togglePromptBox('desktop-prompt-box')" title="مشخصات تصویر">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        </button>
                    </span>
                    <span style="font-size:0.78rem;font-weight:500;color:var(--adm-text-muted);">۱۹۲۰×۶۰۰ px — تا ۵۰ مگابایت</span>
                </label>

                <div id="desktop-prompt-box" class="slide-prompt-popover" style="display:none;">
                    <div class="slide-prompt-popover-top">
                        <span class="slide-prompt-popover-tag">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            مشخصات تصویر بنر دسکتاپ
                        </span>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <button type="button" class="slide-copy-btn" onclick="copyPromptText('desktop-prompt-text', this)">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                <span>کپی</span>
                            </button>
                            <button type="button" class="slide-prompt-close" onclick="togglePromptBox('desktop-prompt-box')">&times;</button>
                        </div>
                    </div>
                    <div class="slide-prompt-text" id="desktop-prompt-text">Wide panoramic website hero banner, 16:5 aspect ratio (1920x600). A bright, joyful, and modern Scandinavian-style daycare and preschool classroom with soft natural sunlight streaming through large floor-to-ceiling windows. Happy toddler children (ages 2 to 6) engaged in cheerful Montessori wooden educational activities, colorful learning toys, cozy soft reading nook, warm natural wood textures, lush indoor green plants. Clean commercial interior photography, soft depth of field, wide panoramic negative space in the center for clear text readability, 8k resolution, cinematic soft lighting.</div>
                </div>

                <div id="desktopCanvas" class="slide-canvas-wrap slide-canvas-desktop" onclick="handleCanvasClick('desktop')">
                    <input type="file" id="image" name="image" style="display:none;"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        <?= $editSlide ? '' : 'required' ?>>

                    <div id="desktopEmpty" class="slide-canvas-empty" style="<?= $editSlide ? 'display:none;' : '' ?>">
                        <div class="slide-canvas-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                <circle cx="8.5" cy="8.5" r="1.5"/>
                                <polyline points="21 15 16 10 5 21"/>
                            </svg>
                        </div>
                        <div class="slide-canvas-text">انتخاب یا رهاسازی بنر دسکتاپ</div>
                        <div class="slide-canvas-meta">نسبت ۱۶:۵ • ۱۹۲۰×۶۰۰ پیکسل • حداکثر ۵۰ مگابایت</div>
                    </div>

                    <div id="desktopFilled" class="slide-canvas-filled" style="<?= $editSlide ? '' : 'display:none;' ?>">
                        <img id="desktopImg" src="<?= $editSlide ? e(url($editSlide['image'])) : '' ?>" alt="پیش‌نمایش بنر" class="slide-canvas-img">
                        <span class="slide-canvas-badge">۱۹۲۰ × ۶۰۰ px</span>
                        <div class="slide-canvas-overlay">
                            <button type="button" class="slide-canvas-action-btn change" onclick="event.stopPropagation(); triggerFileInput('image')">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                                <span>تغییر تصویر</span>
                            </button>
                            <button type="button" class="slide-canvas-action-btn remove" onclick="event.stopPropagation(); resetDesktopCanvas()">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                <span>حذف</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mobile Banner Studio Canvas -->
            <div class="form-group" style="margin-top:16px;">
                <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="display:inline-flex;align-items:center;gap:6px;">
                        <span>تصویر اختصاصی موبایل <span style="font-size:0.8rem;font-weight:400;color:var(--adm-text-muted);">(اختیاری)</span></span>
                        <button type="button" class="slide-prompt-btn" onclick="togglePromptBox('mobile-prompt-box')" title="مشخصات تصویر">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        </button>
                    </span>
                    <span style="font-size:0.78rem;font-weight:500;color:var(--adm-text-muted);">۱۰۰۰×۷۵۰ px — نسبت ۴:۳</span>
                </label>

                <div id="mobile-prompt-box" class="slide-prompt-popover" style="display:none;">
                    <div class="slide-prompt-popover-top">
                        <span class="slide-prompt-popover-tag">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            مشخصات تصویر بنر موبایل
                        </span>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <button type="button" class="slide-copy-btn" onclick="copyPromptText('mobile-prompt-text', this)">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                <span>کپی</span>
                            </button>
                            <button type="button" class="slide-prompt-close" onclick="togglePromptBox('mobile-prompt-box')">&times;</button>
                        </div>
                    </div>
                    <div class="slide-prompt-text" id="mobile-prompt-text">Compact 4:3 aspect ratio (1000x750) mobile hero banner. Close-up, warm, and joyful portrait of smiling toddler children playing with colorful wooden educational building blocks in a bright, modern nursery classroom. Natural soft sunlight, vibrant pastel colors, clean minimalist background, professional studio childcare photography, crisp focus, 8k resolution, authentic happy expressions.</div>
                </div>

                <div id="mobileCanvas" class="slide-canvas-wrap slide-canvas-mobile" onclick="handleCanvasClick('mobile')">
                    <input type="file" id="image_mobile" name="image_mobile" style="display:none;"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">

                    <?php $hasMobileImg = $editSlide && !empty($editSlide['image_mobile']); ?>
                    <div id="mobileEmpty" class="slide-canvas-empty" style="<?= $hasMobileImg ? 'display:none;' : '' ?>">
                        <div class="slide-canvas-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="5" y="2" width="14" height="20" rx="2" ry="2"/>
                                <line x1="12" y1="18" x2="12.01" y2="18"/>
                            </svg>
                        </div>
                        <div class="slide-canvas-text" style="font-size:0.84rem;">تصویر موبایل (اختیاری)</div>
                        <div class="slide-canvas-meta" style="font-size:0.7rem;">نسبت ۴:۳ • ۱۰۰۰×۷۵۰ px</div>
                    </div>

                    <div id="mobileFilled" class="slide-canvas-filled" style="<?= $hasMobileImg ? '' : 'display:none;' ?>">
                        <img id="mobileImg" src="<?= $hasMobileImg ? e(url((string)$editSlide['image_mobile'])) : '' ?>" alt="پیش‌نمایش موبایل" class="slide-canvas-img">
                        <span class="slide-canvas-badge">۱۰۰۰ × ۷۵۰ px</span>
                        <div class="slide-canvas-overlay">
                            <button type="button" class="slide-canvas-action-btn change" onclick="event.stopPropagation(); triggerFileInput('image_mobile')">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                                <span>تغییر</span>
                            </button>
                            <button type="button" class="slide-canvas-action-btn remove" onclick="event.stopPropagation(); resetMobileCanvas()">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                <span>حذف</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions" style="margin-top:24px;">
                <button type="submit" id="slideSubmitBtn" class="btn btn-primary btn-block" style="width:100%; border:none; padding:14px; font-size:1rem; font-weight:700; border-radius:12px; display:flex; align-items:center; justify-content:center; gap:8px;">
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
                <p>از دکمه بالای صفحه اولین اسلاید خود را اضافه کنید.</p>
            </div>
        <?php else: ?>
            <form id="slideReorderForm" method="post" action="<?= e(url('admin/slides.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_sort">

                <div class="admin-table-wrap">
                    <table class="admin-table" id="slidesSortTable">
                        <thead>
                            <tr>
                                <th style="width:50px;text-align:center;">ترتیب</th>
                                <th style="width:120px;">تصویر بنر</th>
                                <th>عنوان و زیرعنوان</th>
                                <th style="width:100px;text-align:center;">اولویت</th>
                                <th style="width:130px;text-align:center;">عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="slidesSortTbody">
                            <?php foreach ($slides as $slide): ?>
                                <tr class="slide-row" data-id="<?= e((string) $slide['id']) ?>" draggable="true">
                                    <td style="text-align:center;">
                                        <span class="slide-drag-grip" title="برای جابجایی بکشید">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                                <circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/>
                                                <circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/>
                                                <circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/>
                                            </svg>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="slide-thumb-container">
                                            <img src="<?= e(url($slide['image'])) ?>" alt="<?= e($slide['title']) ?>" class="slide-table-thumb" title="تصویر دسکتاپ">
                                            <?php if (!empty($slide['image_mobile'])): ?>
                                                <span class="slide-mobile-badge" title="دارای نسخه اختصاصی موبایل">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:700;color:var(--adm-text);font-size:0.95rem;margin-bottom:3px;">
                                            <?= e($slide['title']) ?>
                                        </div>
                                        <?php if (!empty($slide['subtitle'])): ?>
                                            <div style="font-size:0.8rem;color:var(--adm-text-muted);line-height:1.4;">
                                                <?= e($slide['subtitle']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <input type="number"
                                            name="sort_order[<?= e($slide['id']) ?>]"
                                            min="0" step="1"
                                            value="<?= e($slide['sort_order']) ?>"
                                            class="admin-sort-input slide-sort-num"
                                            style="width:70px;text-align:center;display:inline-block;"
                                            required>
                                    </td>
                                    <td style="text-align:center;">
                                        <div style="display:inline-flex;gap:6px;align-items:center;">
                                            <a href="<?= e(url('admin/slides.php?edit=' . $slide['id'])) ?>" class="btn btn-sm btn-secondary">ویرایش</a>
                                            <form method="post" action="<?= e(url('admin/slides.php')) ?>" class="inline-form" data-confirm="آیا از حذف اسلاید «<?= e($slide['title']) ?>» اطمینان دارید؟ این عملیات قابل بازگشت نیست.">
                                                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                                <input type="hidden" name="action" value="delete_slide">
                                                <input type="hidden" name="slide_id" value="<?= e((string) $slide['id']) ?>">
                                                <button type="submit" class="btn btn-sm btn-reject">حذف</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-actions" style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                    <button type="submit" id="saveSortBtn" class="btn btn-primary btn-sm">
                        به‌روزرسانی ترتیب نمایش
                    </button>
                    <span class="slide-reorder-notice">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                        می‌توانید ردیف‌ها را با گرفتن آیکون ⠿ به بالا و پایین بکشید (Drag &amp; Drop).
                    </span>
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
</div>

<script>
function openSlideDrawer() {
    const tmpl = document.getElementById('slideFormTemplate');
    if (tmpl) {
        openDrawer(<?= $editSlide ? json_encode('ویرایش اسلاید «' . $editSlide['title'] . '»') : json_encode('افزودن اسلاید جدید') ?>, tmpl.innerHTML);
        initSlideUploadUI();
    }
}

function togglePromptBox(boxId) {
    const box = document.getElementById(boxId);
    if (!box) return;
    box.style.display = box.style.display === 'none' ? 'block' : 'none';
}

function copyPromptText(textId, btn) {
    const el = document.getElementById(textId);
    if (!el) return;
    const text = el.textContent || el.innerText;

    const onDone = () => {
        const span = btn.querySelector('span');
        const prev = span ? span.textContent : 'کپی متن';
        if (span) span.textContent = 'کپی شد ✓';
        if (typeof showToast === 'function') {
            showToast('مشخصات تصویر کپی شد.', 'success');
        }
        setTimeout(() => {
            if (span) span.textContent = prev;
        }, 2000);
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(onDone).catch(() => {
            fallbackCopy(text, onDone);
        });
    } else {
        fallbackCopy(text, onDone);
    }
}

function fallbackCopy(text, onDone) {
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    onDone();
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

function handleCanvasClick(prefix) {
    const inputId = prefix === 'desktop' ? 'image' : 'image_mobile';
    const input = document.getElementById(inputId);
    if (input) input.click();
}

function triggerFileInput(id) {
    const input = document.getElementById(id);
    if (input) input.click();
}

function resetDesktopCanvas() {
    const input = document.getElementById('image');
    if (input) input.value = '';
    const emptyBox = document.getElementById('desktopEmpty');
    const filledBox = document.getElementById('desktopFilled');
    const img = document.getElementById('desktopImg');
    if (emptyBox) emptyBox.style.display = 'flex';
    if (filledBox) filledBox.style.display = 'none';
    if (img) img.src = '';
}

function resetMobileCanvas() {
    const input = document.getElementById('image_mobile');
    if (input) input.value = '';
    const emptyBox = document.getElementById('mobileEmpty');
    const filledBox = document.getElementById('mobileFilled');
    const img = document.getElementById('mobileImg');
    if (emptyBox) emptyBox.style.display = 'flex';
    if (filledBox) filledBox.style.display = 'none';
    if (img) img.src = '';
}

function initSlideUploadUI() {
    setupSlideCanvas('desktop', 'image');
    setupSlideCanvas('mobile', 'image_mobile');
    setupSlideFormSubmit();
}

function setupSlideCanvas(prefix, inputId) {
    const canvas = document.getElementById(prefix + 'Canvas');
    const input = document.getElementById(inputId);
    if (!canvas || !input) return;

    ['dragenter', 'dragover'].forEach(name => {
        canvas.addEventListener(name, (e) => {
            e.preventDefault();
            e.stopPropagation();
            canvas.classList.add('is-dragover');
        });
    });

    ['dragleave', 'dragend'].forEach(name => {
        canvas.addEventListener(name, (e) => {
            e.preventDefault();
            e.stopPropagation();
            canvas.classList.remove('is-dragover');
        });
    });

    canvas.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();
        canvas.classList.remove('is-dragover');

        if (e.dataTransfer && e.dataTransfer.files.length > 0) {
            handleCanvasFile(e.dataTransfer.files[0], prefix, input);
        }
    });

    input.addEventListener('change', () => {
        if (input.files && input.files.length > 0) {
            handleCanvasFile(input.files[0], prefix, input);
        }
    });
}

function handleCanvasFile(file, prefix, input) {
    const maxBytes = 50 * 1024 * 1024; // 50MB
    const emptyBox = document.getElementById(prefix + 'Empty');
    const filledBox = document.getElementById(prefix + 'Filled');
    const img = document.getElementById(prefix + 'Img');

    if (!file) return;

    if (file.size > maxBytes) {
        const mb = (file.size / (1024 * 1024)).toFixed(1);
        if (typeof showToast === 'function') {
            showToast(`حجم تصویر (${toPersianDigits(mb)} مگابایت) بیشتر از ۵۰ مگابایت است.`, 'danger');
        } else {
            alert(`حجم تصویر (${toPersianDigits(mb)} مگابایت) بیشتر از ۵۰ مگابایت است.`);
        }
        input.value = '';
        return;
    }

    if (window.DataTransfer && (!input.files || input.files[0] !== file)) {
        try {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
        } catch (e) {}
    }

    if (img && emptyBox && filledBox) {
        img.src = URL.createObjectURL(file);
        emptyBox.style.display = 'none';
        filledBox.style.display = 'block';
    }
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

        submitBtn.disabled = true;
        btnText.innerHTML = '<span class="slide-spinner"></span> در حال ذخیره‌سازی...';
    });
}

// ── TABLE DRAG & DROP REORDERING ────────────────────────────
function initTableDragAndDrop() {
    const tbody = document.getElementById('slidesSortTbody');
    if (!tbody) return;

    let draggedRow = null;

    tbody.querySelectorAll('.slide-row').forEach(row => {
        row.addEventListener('dragstart', (e) => {
            draggedRow = row;
            row.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', row.dataset.id);
        });

        row.addEventListener('dragend', () => {
            row.classList.remove('is-dragging');
            tbody.querySelectorAll('.slide-row').forEach(r => {
                r.classList.remove('drag-over-top', 'drag-over-bottom');
            });
            draggedRow = null;
        });

        row.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (!draggedRow || draggedRow === row) return;

            const rect = row.getBoundingClientRect();
            const midpoint = rect.top + rect.height / 2;

            if (e.clientY < midpoint) {
                row.classList.add('drag-over-top');
                row.classList.remove('drag-over-bottom');
            } else {
                row.classList.add('drag-over-bottom');
                row.classList.remove('drag-over-top');
            }
        });

        row.addEventListener('dragleave', () => {
            row.classList.remove('drag-over-top', 'drag-over-bottom');
        });

        row.addEventListener('drop', (e) => {
            e.preventDefault();
            row.classList.remove('drag-over-top', 'drag-over-bottom');
            if (!draggedRow || draggedRow === row) return;

            const rect = row.getBoundingClientRect();
            const midpoint = rect.top + rect.height / 2;

            if (e.clientY < midpoint) {
                tbody.insertBefore(draggedRow, row);
            } else {
                tbody.insertBefore(draggedRow, row.nextSibling);
            }

            // Recalculate sequential sort orders in inputs
            const form = document.getElementById('slideReorderForm');
            const formData = new FormData();
            formData.append('action', 'update_sort');
            formData.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
            formData.append('ajax', '1');

            tbody.querySelectorAll('.slide-row').forEach((r, idx) => {
                const sortInput = r.querySelector('.slide-sort-num');
                if (sortInput) sortInput.value = idx;
                formData.append(`sort_order[${r.dataset.id}]`, idx);
            });

            // Save order via AJAX
            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    if (typeof showToast === 'function') showToast(data.message || 'ترتیب اسلایدها با موفقیت ذخیره شد.', 'success');
                } else if (data && data.error) {
                    if (typeof showToast === 'function') showToast(data.error, 'danger');
                }
            })
            .catch(() => {
                // If AJAX fails, user can still click save button
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initTableDragAndDrop();
    <?php if ($editSlide): ?>
        openSlideDrawer();
    <?php endif; ?>
});
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
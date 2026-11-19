<?php
declare(strict_types=1);

// Increase memory and execution time limits for large image processing (up to 50MB)
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', '300');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

// ─── Categories Configuration ─────────────────────────────────────────────────

const GALLERY_CATEGORIES = [
    'activities' => 'فعالیت‌ها و کارگاه‌ها',
    'space'      => 'فضا و کلاس‌ها',
    'events'     => 'جشن‌ها و رویدادها',
];

// ─── Helper Functions ─────────────────────────────────────────────────────────

function galleryStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function initializeGalleryTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM gallery_images")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('category', $cols, true)) {
            $pdo->exec("ALTER TABLE gallery_images ADD COLUMN category VARCHAR(50) DEFAULT NULL AFTER image");
        }
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
}

function findGalleryImage(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM gallery_images WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $row = $statement->fetch();

    return $row ?: null;
}

function getAllGalleryImages(PDO $pdo, int $limit = 20, int $offset = 0, ?string $category = null): array
{
    $sql = 'SELECT * FROM gallery_images';
    $params = [];

    if ($category !== null && array_key_exists($category, GALLERY_CATEGORIES)) {
        $sql .= ' WHERE category = :cat';
        $params[':cat'] = $category;
    }

    $sql .= ' ORDER BY sort_order ASC, created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function countGalleryImages(PDO $pdo, ?string $category = null): int
{
    if ($category !== null && array_key_exists($category, GALLERY_CATEGORIES)) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM gallery_images WHERE category = :cat');
        $statement->execute([':cat' => $category]);
        return (int) $statement->fetchColumn();
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM gallery_images')->fetchColumn();
}

function parseGalleryId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return is_int($id) ? $id : 0;
}

function parseGallerySortOrder(mixed $value): ?int
{
    $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

    return is_int($n) ? $n : null;
}

/**
 * Safely delete a gallery image file from the uploads directory.
 */
function deleteGalleryFile(string $relativePath): void
{
    if ($relativePath === '') {
        return;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    $uploadRoot  = realpath(__DIR__ . '/../assets/uploads');

    if ($projectRoot === false || $uploadRoot === false) {
        return;
    }

    $candidate = realpath(
        $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath)
    );

    if (
        $candidate !== false
        && str_starts_with($candidate, $uploadRoot . DIRECTORY_SEPARATOR)
        && is_file($candidate)
    ) {
        @unlink($candidate);
    }
}

/**
 * Safely processes, optimizes and stores an uploaded gallery image.
 * Supports files up to 50MB and downsizes oversized images gracefully.
 */
function processAndSaveGalleryImage(string $tmpName, string $uploadDir, string $extension): string
{
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

                    $maxWidth = 2000;
                    $maxHeight = 1600;

                    $targetWidth = $origWidth;
                    $targetHeight = $origHeight;

                    if ($origWidth > $maxWidth || $origHeight > $maxHeight) {
                        $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
                        $targetWidth = (int) max(1, round($origWidth * $ratio));
                        $targetHeight = (int) max(1, round($origHeight * $ratio));
                    }

                    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);
                    $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
                    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);

                    imagecopyresampled(
                        $canvas,
                        $img,
                        0, 0, 0, 0,
                        $targetWidth,
                        $targetHeight,
                        $origWidth,
                        $origHeight
                    );
                    imagedestroy($img);

                    $randomHex = bin2hex(random_bytes(16));
                    if (function_exists('imagewebp')) {
                        $targetFileName = 'gallery_' . $randomHex . '.webp';
                        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetFileName;
                        if (@imagewebp($canvas, $targetPath, 85)) {
                            imagedestroy($canvas);
                            @chmod($targetPath, 0644);
                            return 'assets/uploads/gallery/' . $targetFileName;
                        }
                    } elseif ($extension === 'png' && function_exists('imagepng')) {
                        $targetFileName = 'gallery_' . $randomHex . '.png';
                        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetFileName;
                        if (@imagepng($canvas, $targetPath, 8)) {
                            imagedestroy($canvas);
                            @chmod($targetPath, 0644);
                            return 'assets/uploads/gallery/' . $targetFileName;
                        }
                    } elseif (function_exists('imagejpeg')) {
                        $targetFileName = 'gallery_' . $randomHex . '.jpg';
                        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetFileName;
                        if (@imagejpeg($canvas, $targetPath, 88)) {
                            imagedestroy($canvas);
                            @chmod($targetPath, 0644);
                            return 'assets/uploads/gallery/' . $targetFileName;
                        }
                    }

                    imagedestroy($canvas);
                }
            }
        } catch (Throwable $e) {
            error_log('Gallery image optimization notice: ' . $e->getMessage());
        }
    }

    $fileName = 'gallery_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!@move_uploaded_file($tmpName, $destination)) {
        if (!@copy($tmpName, $destination)) {
            throw new RuntimeException('ذخیره فایل آپلود شده در پوشه uploads ممکن نیست.');
        }
        @unlink($tmpName);
    }

    @chmod($destination, 0644);
    return 'assets/uploads/gallery/' . $fileName;
}

/**
 * Handle gallery image upload. Returns the relative path on success, null if no file uploaded.
 */
function uploadGalleryImage(array $file, bool $required): ?string
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            throw new RuntimeException('بارگذاری تصویر الزامی است.');
        }

        return null;
    }

    if ($errorCode !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'حجم تصویر بیشتر از محدودیت مجاز سرور است (حداکثر ۵۰ مگابایت).',
            UPLOAD_ERR_FORM_SIZE  => 'حجم تصویر بیشتر از حد مجاز فرم است.',
            UPLOAD_ERR_PARTIAL    => 'تصویر فقط به صورت ناقص آپلود شد.',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت سرور برای آپلود یافت نشد.',
            UPLOAD_ERR_CANT_WRITE => 'نوشتن فایل روی دیسک سرور با خطا مواجه شد.',
            UPLOAD_ERR_EXTENSION  => 'آپلود توسط یک افزونه سرور متوقف شد.',
        ];
        throw new RuntimeException($uploadErrors[$errorCode] ?? ('خطای آپلود (کد ' . $errorCode . ')'));
    }

    $maxBytes = 50 * 1024 * 1024; // 50MB
    if (($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('حجم تصویر بیشتر از سقف مجاز (۵۰ مگابایت) است.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || (!is_uploaded_file($tmpName) && !is_file($tmpName))) {
        throw new RuntimeException('فایل آپلود شده نامعتبر است.');
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
        throw new RuntimeException('فرمت فایل مجاز نیست. فقط JPG، PNG، GIF و WebP پشتیبانی می‌شود.');
    }

    if (class_exists('finfo')) {
        try {
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($tmpName);

            if (is_string($mimeType) && $mimeType !== '' && $mimeType !== 'application/octet-stream') {
                if (!in_array($mimeType, $allowedTypes[$extension], true)) {
                    throw new RuntimeException('نوع فایل ارسالی با پسوند آن همخوانی ندارد.');
                }
            }
        } catch (Throwable $e) {}
    }

    if (@getimagesize($tmpName) === false) {
        throw new RuntimeException('فایل انتخاب‌شده یک تصویر معتبر نیست.');
    }

    $uploadDir = __DIR__ . '/../assets/uploads/gallery';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('ایجاد پوشه ذخیره‌سازی assets/uploads/gallery ممکن نیست.');
    }

    return processAndSaveGalleryImage($tmpName, $uploadDir, $extension);
}

// ─── Database connection ──────────────────────────────────────────────────────

try {
    $pdo = getDb();
    initializeGalleryTable($pdo);
} catch (Throwable $e) {
    error_log($e->getMessage());
    setFlash('error', 'گالری در دسترس نیست.');
    redirect(url('admin/index.php'));
}

// ─── POST handling ────────────────────────────────────────────────────────────

if (isPostRequest()) {
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        setFlash('error', 'حجم فایل ارسالی بیشتر از محدودیت مجاز سرور است (حداکثر ۵۰ مگابایت).');
        redirect(url('admin/gallery.php'));
    }

    $csrf = $_POST['csrf_token'] ?? '';
    $action = (string) ($_POST['action'] ?? '');

    if (!validateCsrfToken($csrf)) {
        setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        redirect(url('admin/gallery.php'));
    }

    $newImagePath = null;

    try {
        // ─── Save (create/update) image ───────────────────────────────────
        if ($action === 'save_image') {
            $imageId    = parseGalleryId($_POST['image_id'] ?? null);
            $title      = trim((string) ($_POST['title'] ?? ''));
            $caption    = trim((string) ($_POST['caption'] ?? ''));
            $category   = trim((string) ($_POST['category'] ?? ''));
            $category   = array_key_exists($category, GALLERY_CATEGORIES) ? $category : null;
            $sortOrder  = parseGallerySortOrder($_POST['sort_order'] ?? null);
            $isActive   = isset($_POST['is_active']) ? 1 : 0;
            $isEditing  = $imageId > 0;
            $current    = null;

            if ($sortOrder === null) {
                setFlash('error', 'ترتیب نمایش نامعتبر است.');
                redirect(url('admin/gallery.php'));
            }

            if ($isEditing) {
                $current = findGalleryImage($pdo, $imageId);
                if (!$current) {
                    setFlash('error', 'تصویر یافت نشد.');
                    redirect(url('admin/gallery.php'));
                }
            }

            $newImagePath = uploadGalleryImage($_FILES['image'] ?? [], !$isEditing);
            $imagePath    = $newImagePath ?? (string) ($current['image'] ?? '');

            if ($imagePath === '') {
                setFlash('error', 'بارگذاری تصویر الزامی است.');
                redirect(url('admin/gallery.php'));
            }

            if ($isEditing) {
                $pdo->prepare(
                    'UPDATE gallery_images SET title = :t, caption = :c, category = :cat, image = :i, sort_order = :s, is_active = :a WHERE id = :id'
                )->execute([
                    ':t'   => $title !== '' ? $title : null,
                    ':c'   => $caption !== '' ? $caption : null,
                    ':cat' => $category,
                    ':i'   => $imagePath,
                    ':s'   => $sortOrder,
                    ':a'   => $isActive,
                    ':id'  => $imageId,
                ]);

                if ($newImagePath !== null) {
                    deleteGalleryFile((string) $current['image']);
                }

                recordAudit('gallery.update', 'gallery', $imageId);
                setFlash('success', 'تصویر با موفقیت بروزرسانی شد.');
                redirect(url('admin/gallery.php'));
            }

            // Create new image
            $pdo->prepare(
                'INSERT INTO gallery_images (title, caption, category, image, sort_order, is_active) VALUES (:t, :c, :cat, :i, :s, :a)'
            )->execute([
                ':t'   => $title !== '' ? $title : null,
                ':c'   => $caption !== '' ? $caption : null,
                ':cat' => $category,
                ':i'   => $imagePath,
                ':s'   => $sortOrder,
                ':a'   => $isActive,
            ]);

            recordAudit('gallery.create', 'gallery', (int) $pdo->lastInsertId());
            setFlash('success', 'تصویر با موفقیت به گالری اضافه شد.');
            redirect(url('admin/gallery.php'));
        }

        // ─── Delete image ─────────────────────────────────────────────────
        if ($action === 'delete_image') {
            $imageId = parseGalleryId($_POST['image_id'] ?? null);
            $image   = $imageId > 0 ? findGalleryImage($pdo, $imageId) : null;

            if (!$image) {
                setFlash('error', 'تصویر یافت نشد.');
                redirect(url('admin/gallery.php'));
            }

            $pdo->prepare('DELETE FROM gallery_images WHERE id = :id')->execute([':id' => $imageId]);
            deleteGalleryFile((string) $image['image']);
            recordAudit('gallery.delete', 'gallery', $imageId);
            setFlash('success', 'تصویر با موفقیت حذف شد.');
            redirect(url('admin/gallery.php'));
        }

        // ─── Toggle active status ─────────────────────────────────────────
        if ($action === 'toggle_active') {
            $imageId = parseGalleryId($_POST['image_id'] ?? null);
            $image   = $imageId > 0 ? findGalleryImage($pdo, $imageId) : null;

            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1');

            if (!$image) {
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'تصویر یافت نشد.']);
                    exit;
                }
                setFlash('error', 'تصویر یافت نشد.');
                redirect(url('admin/gallery.php'));
            }

            $newStatus = ((int) $image['is_active'] === 1) ? 0 : 1;
            $pdo->prepare('UPDATE gallery_images SET is_active = :a WHERE id = :id')
                ->execute([':a' => $newStatus, ':id' => $imageId]);

            recordAudit('gallery.toggle', 'gallery', $imageId);

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'newStatus' => $newStatus,
                    'message' => $newStatus === 1 ? 'تصویر فعال شد.' : 'تصویر غیرفعال شد.'
                ]);
                exit;
            }

            setFlash('success', 'وضعیت تصویر تغییر کرد.');
            redirect(url('admin/gallery.php'));
        }

        // ─── Bulk sort order update ───────────────────────────────────────
        if ($action === 'update_sort') {
            $orders = $_POST['sort_order'] ?? [];

            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1');

            if (!is_array($orders)) {
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'داده‌های ترتیب نامعتبر است.']);
                    exit;
                }
                setFlash('error', 'داده‌های ترتیب نامعتبر است.');
                redirect(url('admin/gallery.php'));
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE gallery_images SET sort_order = :s WHERE id = :id');

            foreach ($orders as $key => $value) {
                $gid = parseGalleryId($key);
                $gso = parseGallerySortOrder($value);

                if ($gid === 0 || $gso === null) {
                    $pdo->rollBack();
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error' => 'ترتیب نامعتبر است.']);
                        exit;
                    }
                    setFlash('error', 'ترتیب نامعتبر است.');
                    redirect(url('admin/gallery.php'));
                }

                $stmt->execute([':s' => $gso, ':id' => $gid]);
            }

            $pdo->commit();
            recordAudit('gallery.reorder', 'gallery');

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => 'ترتیب تصاویر با موفقیت بروزرسانی شد.']);
                exit;
            }

            setFlash('success', 'ترتیب تصاویر بروزرسانی شد.');
            redirect(url('admin/gallery.php'));
        }

        setFlash('error', 'عملیات نامعتبر است.');
        redirect(url('admin/gallery.php'));
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newImagePath !== null) {
            deleteGalleryFile($newImagePath);
        }
        error_log($e->getMessage());
        setFlash('error', 'ذخیره ناموفق بود: ' . $e->getMessage());
        redirect(url('admin/gallery.php'));
    }
}

// ─── Page data ────────────────────────────────────────────────────────────────

$editId        = parseGalleryId($_GET['edit'] ?? null);
$editImage     = $editId > 0 ? findGalleryImage($pdo, $editId) : null;
$filterCat     = trim((string) ($_GET['cat'] ?? ''));
$filterCat     = array_key_exists($filterCat, GALLERY_CATEGORIES) ? $filterCat : null;

$perPage       = 20;
$currentPage   = max(1, (int) ($_GET['page'] ?? 1));
$total         = countGalleryImages($pdo, $filterCat);
$totalPages    = max(1, (int) ceil($total / $perPage));
$currentPage   = min($currentPage, $totalPages);
$offset        = ($currentPage - 1) * $perPage;
$galleryImages = getAllGalleryImages($pdo, $perPage, $offset, $filterCat);

$pagi = [
    'current'    => $currentPage,
    'totalPages' => $totalPages,
    'total'      => $total,
    'perPage'    => $perPage,
    'from'       => $total > 0 ? $offset + 1 : 0,
    'to'         => min($offset + $perPage, $total),
];

$pageTitle = 'مدیریت گالری تصاویر | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <div class="adm-page-head">
        <div class="adm-page-head-main">
            <div class="adm-page-title-row">
                <h1>مدیریت گالری تصاویر</h1>
                <span class="adm-count-pill"><?= persianNumber((string) $total) ?> تصویر</span>
            </div>
            <p class="adm-page-subtitle">انتشار و دسته‌بندی تصاویر فعالیت‌ها و فضای آموزشی مهدکودک</p>
        </div>
        <div class="adm-page-actions">
            <button type="button" class="app-btn app-btn-primary" onclick="openGalleryDrawer()">
                + افزودن تصویر جدید
            </button>
        </div>
    </div>

    <?php $flashError = getFlash('error'); $flashSuccess = getFlash('success'); ?>
    <?php if ($flashSuccess): ?>
        <script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($flashSuccess) ?>, 'success'));</script>
        <div class="notice" role="status"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($flashError) ?>, 'danger'));</script>
        <div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
    <?php endif; ?>

    <!-- CATEGORY FILTER CHIPS -->
    <nav class="filter-nav" aria-label="فیلتر بر اساس دسته‌بندی گالری">
        <a href="<?= e(url('admin/gallery.php')) ?>" class="<?= $filterCat === null ? 'is-active' : '' ?>">
            همه تصاویر (<?= persianNumber((string) countGalleryImages($pdo)) ?>)
        </a>
        <?php foreach (GALLERY_CATEGORIES as $key => $label): ?>
            <?php $catCount = countGalleryImages($pdo, $key); ?>
            <a href="<?= e(url('admin/gallery.php?cat=' . urlencode($key))) ?>" class="<?= $filterCat === $key ? 'is-active' : '' ?>">
                <?= e($label) ?> (<?= persianNumber((string) $catCount) ?>)
            </a>
        <?php endforeach; ?>
    </nav>

    <template id="galleryFormTemplate">
        <form id="galleryUploadForm" method="post" enctype="multipart/form-data" action="<?= e(url('admin/gallery.php')) ?>" novalidate style="padding: 6px 0 0;">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_image">
            <?php if ($editImage): ?>
                <input type="hidden" name="image_id" value="<?= e((string) $editImage['id']) ?>">
            <?php endif; ?>

            <div class="news-banner-header" style="margin-bottom:18px;">
                <div class="news-banner-title">
                    <span><?= $editImage ? 'ویرایش تصویر گالری' : 'افزودن تصویر به گالری' ?></span>
                    <div class="news-banner-plus-icon">
                        <?= $editImage ? '✎' : '+' ?>
                    </div>
                </div>
            </div>

            <!-- GALLERY IMAGE UPLOAD SECTION -->
            <div class="form-group">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <label for="image" class="form-label" style="margin-bottom:0;display:inline-flex;align-items:center;gap:6px;">
                        <span>تصویر گالری <?= $editImage ? '<span style="color:var(--adm-text-muted);font-weight:400">(اختیاری)</span>' : '<span style="color:#EF4444;">*</span>' ?></span>
                        <button type="button" class="slide-prompt-btn" onclick="togglePromptBox('gallery-prompt-box', event)" title="مشخصات استاندارد تصویر">?</button>
                    </label>
                    <span style="font-size:0.78rem;color:var(--adm-text-muted);">حداکثر ۵۰ مگابایت</span>
                </div>

                <div id="gallery-prompt-box" class="slide-prompt-popover" style="display:none;">
                    <div class="slide-prompt-popover-top">
                        <span class="slide-prompt-popover-tag">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                            مشخصات تصویر استاندارد گالری
                        </span>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <button type="button" class="slide-copy-btn" onclick="copyPromptText('gallery-prompt-text', this)">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                <span>کپی</span>
                            </button>
                            <button type="button" class="slide-prompt-close" onclick="togglePromptBox('gallery-prompt-box', event)">&times;</button>
                        </div>
                    </div>
                    <div id="gallery-prompt-text" class="slide-prompt-text">Natural and authentic daycare and preschool photography. Cheerful toddler children having fun in a bright, modern Montessori classroom. Warm natural lighting, clean composition, vibrant pastel colors, candid smiles, engaging sensory and educational play activities, professional interior childcare photography, crisp focus, 4k high quality.</div>
                </div>

                <?php if ($editImage): ?>
                    <div style="margin-bottom:10px;">
                        <div style="font-size:0.8rem;color:var(--adm-text-muted);margin-bottom:4px;">تصویر فعلی:</div>
                        <img src="<?= e(url($editImage['image'])) ?>" alt="<?= e($editImage['title'] ?? '') ?>" style="max-height:120px;width:100%;object-fit:cover;border-radius:12px;border:1px solid var(--adm-border);">
                    </div>
                <?php endif; ?>

                <div class="news-dropzone-box" id="dropzone-gallery" onclick="document.getElementById('image').click()">
                    <input type="file" id="image" name="image" class="news-dropzone-input" style="display:none;"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        <?= $editImage ? '' : 'required' ?>>

                    <div class="news-dropzone-icon" style="display:flex;justify-content:center;margin-bottom:8px;">
                        <svg width="60" height="45" viewBox="0 0 100 75" fill="none" xmlns="http://www.w3.org/2000/svg" style="opacity:.75;">
                            <path d="M25 60 C12 60 5 49 10 37 C13 27 25 25 30 27 C34 15 50 11 62 19 C70 13 84 17 86 29 C94 31 98 43 90 55 C86 60 78 60 75 60 Z" fill="currentColor" opacity="0.25"/>
                            <path d="M62 56 L62 44 M56 49 L62 44 L68 49" stroke="var(--adm-primary)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                            <circle cx="62" cy="42" r="6" fill="var(--adm-primary)"/>
                            <rect x="30" y="30" width="26" height="18" rx="3" stroke="var(--adm-primary)" stroke-width="2.5" fill="none"/>
                        </svg>
                    </div>

                    <div id="gallery-filename" class="news-dropzone-filename" style="font-size:0.9rem;font-weight:600;margin-bottom:4px;">فایلی انتخاب نشده — برای انتخاب یا رهاسازی کلیک کنید</div>
                    <div class="news-dropzone-hint" style="font-size:0.8rem;color:var(--adm-text-muted);">فرمت‌های مجاز: <strong>JPG, PNG, WebP</strong> — حداکثر حجم: <strong>۵۰ مگابایت</strong></div>
                </div>

                <div id="gallery-preview-box" class="slide-preview-box" style="display:none;">
                    <img id="gallery-preview-img" src="" alt="پیش‌نمایش" style="width:72px;height:48px;object-fit:cover;border-radius:8px;border:1px solid var(--adm-border);">
                    <div style="flex:1;min-width:0;">
                        <div id="gallery-preview-name" style="font-size:0.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--adm-text);"></div>
                        <div id="gallery-preview-meta" style="font-size:0.75rem;color:var(--adm-text-muted);"></div>
                    </div>
                    <button type="button" class="btn btn-xs btn-reject" onclick="clearGalleryFile(event)" style="padding:4px 8px;font-size:0.75rem;">حذف</button>
                </div>
            </div>

            <div class="form-group">
                <label for="title" class="form-label">عنوان تصویر <span style="color:var(--adm-text-muted);font-weight:400">(اختیاری)</span></label>
                <input type="text" id="title" name="title" class="form-control" maxlength="255" placeholder="مثلاً: کارگاه سفالگری کودکان" value="<?= e($editImage['title'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="category" class="form-label">دسته‌بندی <span style="color:var(--adm-text-muted);font-weight:400">(اختیاری)</span></label>
                <select id="category" name="category" class="form-control">
                    <option value="">بدون دسته‌بندی خاص</option>
                    <?php foreach (GALLERY_CATEGORIES as $catKey => $catLabel): ?>
                        <option value="<?= e($catKey) ?>" <?= (($editImage['category'] ?? '') === $catKey) ? 'selected' : '' ?>>
                            <?= e($catLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="caption" class="form-label">توضیحات کوتاه <span style="color:var(--adm-text-muted);font-weight:400">(اختیاری)</span></label>
                <textarea id="caption" name="caption" class="form-control" rows="2" maxlength="500" placeholder="توضیحات مختصری درباره این تصویر..."><?= e($editImage['caption'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label for="sort_order" class="form-label">ترتیب نمایش <span style="color:#EF4444;">*</span></label>
                <input type="number" id="sort_order" name="sort_order" class="form-control" style="max-width:140px;" min="0" step="1" value="<?= e((string) ($editImage['sort_order'] ?? '0')) ?>" required>
            </div>

            <div class="form-group" style="margin-top:14px;">
                <label class="form-check" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1" <?= ($editImage === null || (int) $editImage['is_active'] === 1) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:#3D8B63;">
                    <span class="form-check-label" style="font-weight:600;color:var(--adm-text);font-size:0.9rem;">نمایش در سایت (فعال)</span>
                </label>
            </div>

            <div class="form-actions" style="margin-top:24px;">
                <button type="submit" id="gallerySubmitBtn" class="btn btn-primary btn-block" style="width:100%; border:none; padding:14px; font-size:1rem; font-weight:700; border-radius:12px; display:flex; align-items:center; justify-content:center; gap:8px;">
                    <span id="gallerySubmitBtnText"><?= $editImage ? 'به‌روزرسانی تصویر' : 'افزودن به گالری' ?></span>
                </button>
            </div>
        </form>
    </template>

    <div class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title">تصاویر گالری</h2>
        </div>

        <?php if ($galleryImages === []): ?>
            <div class="empty-state empty-state-sm">
                <div class="empty-state-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </div>
                <h3>هنوز تصویری در این بخش اضافه نشده</h3>
                <p>از دکمه بالای صفحه اولین تصویر خود را به گالری اضافه کنید.</p>
            </div>
        <?php else: ?>
            <form id="galleryReorderForm" method="post" action="<?= e(url('admin/gallery.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_sort">

                <div class="admin-table-wrap">
                    <table class="admin-table" id="gallerySortTable">
                        <thead>
                            <tr>
                                <th style="width:50px;text-align:center;">ترتیب</th>
                                <th style="width:110px;">تصویر</th>
                                <th>عنوان و توضیحات</th>
                                <th style="width:140px;">دسته‌بندی</th>
                                <th style="width:90px;text-align:center;">اولویت</th>
                                <th style="width:100px;text-align:center;">وضعیت</th>
                                <th style="width:130px;text-align:center;">عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="gallerySortTbody">
                            <?php foreach ($galleryImages as $gi): ?>
                                <tr class="slide-row" data-id="<?= e((string) $gi['id']) ?>" draggable="true">
                                    <td style="text-align:center;">
                                        <span class="slide-drag-grip" title="برای تغییر ترتیب بکشید">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                                <circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/>
                                                <circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/>
                                                <circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/>
                                            </svg>
                                        </span>
                                    </td>
                                    <td>
                                        <img src="<?= e(url($gi['image'])) ?>" alt="<?= e($gi['title'] ?? '') ?>" class="admin-gallery-thumb" onclick="previewImageModal('<?= e(url($gi['image'])) ?>', '<?= e($gi['title'] ?? '') ?>')">
                                    </td>
                                    <td>
                                        <div style="font-weight:700;color:var(--adm-text);font-size:0.95rem;margin-bottom:3px;">
                                            <?= e($gi['title'] ?: 'بدون عنوان') ?>
                                        </div>
                                        <?php if (!empty($gi['caption'])): ?>
                                            <div style="font-size:0.8rem;color:var(--adm-text-muted);line-height:1.4;">
                                                <?= e($gi['caption']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($gi['category']) && isset(GALLERY_CATEGORIES[$gi['category']])): ?>
                                            <span class="badge badge-secondary" style="font-size:0.75rem;">
                                                <?= e(GALLERY_CATEGORIES[$gi['category']]) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:var(--adm-text-muted);font-size:0.8rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <input type="number"
                                            name="sort_order[<?= e($gi['id']) ?>]"
                                            min="0" step="1"
                                            value="<?= e($gi['sort_order']) ?>"
                                            class="admin-sort-input slide-sort-num"
                                            style="width:65px;text-align:center;display:inline-block;"
                                            required>
                                    </td>
                                    <td style="text-align:center;">
                                        <button type="button" class="gallery-status-btn <?= (int) $gi['is_active'] === 1 ? 'active' : 'inactive' ?>" onclick="toggleGalleryActive(<?= (int)$gi['id'] ?>, this)">
                                            <?= (int) $gi['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
                                        </button>
                                    </td>
                                    <td style="text-align:center;">
                                        <div style="display:inline-flex;gap:6px;align-items:center;">
                                            <a href="<?= e(url('admin/gallery.php?edit=' . $gi['id'])) ?>" class="btn btn-sm btn-secondary">ویرایش</a>
                                            <form method="post" action="<?= e(url('admin/gallery.php')) ?>" class="inline-form" data-confirm="آیا از حذف تصویر «<?= e($gi['title'] ?? 'بدون عنوان') ?>» اطمینان دارید؟ این عملیات قابل بازگشت نیست.">
                                                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                                <input type="hidden" name="action" value="delete_image">
                                                <input type="hidden" name="image_id" value="<?= e((string) $gi['id']) ?>">
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
                        می‌توانید ردیف‌ها را با کشیدن آیکون ⠿ به بالا و پایین جابجا کنید.
                    </span>
                </div>
            </form>

            <?php if ($pagi['total'] > $pagi['perPage']): ?>
                <p class="pagination-summary">
                    نمایش <?= e(persianNumber($pagi['from'])) ?> تا <?= e(persianNumber($pagi['to'])) ?> از <?= e(persianNumber($pagi['total'])) ?> تصویر
                </p>
                <?= renderPagination($pagi, url('admin/gallery.php' . ($filterCat ? '?cat=' . urlencode($filterCat) : ''))) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<!-- IMAGE LIGHTBOX PREVIEW MODAL -->
<div id="galleryModal" class="adm-lightbox" onclick="closeImageModal()">
    <div class="adm-lightbox-dialog" onclick="event.stopPropagation()">
        <button type="button" class="adm-lightbox-close" onclick="closeImageModal()" aria-label="بستن پیش‌نمایش">&times;</button>
        <img id="galleryModalImg" class="adm-lightbox-img" src="" alt="">
        <div id="galleryModalTitle" class="adm-lightbox-title"></div>
    </div>
</div>

<script>
function openGalleryDrawer() {
    const tmpl = document.getElementById('galleryFormTemplate');
    if (tmpl) {
        openDrawer(<?= $editImage ? json_encode('ویرایش تصویر «' . ($editImage['title'] ?? 'بدون عنوان') . '»') : json_encode('افزودن تصویر به گالری') ?>, tmpl.innerHTML);
        initGalleryUploadUI();
    }
}

function togglePromptBox(boxId, e) {
    if (e) e.stopPropagation();
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
        const prev = span ? span.textContent : 'کپی';
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

function clearGalleryFile(e) {
    if (e) e.stopPropagation();
    const input = document.getElementById('image');
    if (input) input.value = '';
    const previewBox = document.getElementById('gallery-preview-box');
    const filenameEl = document.getElementById('gallery-filename');
    if (previewBox) previewBox.style.display = 'none';
    if (filenameEl) filenameEl.textContent = 'فایلی انتخاب نشده — برای انتخاب یا رهاسازی کلیک کنید';
}

function initGalleryUploadUI() {
    const dropzone = document.getElementById('dropzone-gallery');
    const input = document.getElementById('image');
    if (!dropzone || !input) return;

    ['dragenter', 'dragover'].forEach(name => {
        dropzone.addEventListener(name, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.style.borderColor = '#3D8B63';
            dropzone.style.background = 'rgba(61, 139, 99, 0.06)';
        });
    });

    ['dragleave', 'dragend'].forEach(name => {
        dropzone.addEventListener(name, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.style.borderColor = '#CBD5E1';
            dropzone.style.background = '';
        });
    });

    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.style.borderColor = '#CBD5E1';
        dropzone.style.background = '';

        if (e.dataTransfer && e.dataTransfer.files.length > 0) {
            handleGalleryFile(e.dataTransfer.files[0], input);
        }
    });

    input.addEventListener('change', () => {
        if (input.files && input.files.length > 0) {
            handleGalleryFile(input.files[0], input);
        }
    });

    setupGalleryFormSubmit();
}

function handleGalleryFile(file, input) {
    const maxBytes = 50 * 1024 * 1024; // 50MB
    const filenameEl = document.getElementById('gallery-filename');
    const previewBox = document.getElementById('gallery-preview-box');
    const previewImg = document.getElementById('gallery-preview-img');
    const previewName = document.getElementById('gallery-preview-name');
    const previewMeta = document.getElementById('gallery-preview-meta');

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

    if (filenameEl) filenameEl.textContent = file.name;

    if (previewBox && previewImg && previewName) {
        const objUrl = URL.createObjectURL(file);
        previewImg.src = objUrl;
        previewName.textContent = file.name;
        if (previewMeta) previewMeta.textContent = formatPersianFileSize(file.size);

        const img = new Image();
        img.onload = function() {
            if (previewMeta) {
                previewMeta.textContent = `${formatPersianFileSize(file.size)} — ${toPersianDigits(img.naturalWidth)}×${toPersianDigits(img.naturalHeight)} px`;
            }
        };
        img.src = objUrl;

        previewBox.style.display = 'flex';
    }
}

function setupGalleryFormSubmit() {
    const form = document.getElementById('galleryUploadForm');
    const submitBtn = document.getElementById('gallerySubmitBtn');
    const btnText = document.getElementById('gallerySubmitBtnText');
    if (!form || !submitBtn) return;

    form.addEventListener('submit', function(e) {
        const imageInput = document.getElementById('image');
        const isEdit = form.querySelector('input[name="image_id"]') !== null;

        if (!isEdit && imageInput && (!imageInput.files || imageInput.files.length === 0)) {
            e.preventDefault();
            if (typeof showToast === 'function') showToast('لطفاً تصویر گالری را انتخاب کنید.', 'danger');
            return;
        }

        submitBtn.disabled = true;
        btnText.innerHTML = '<span class="slide-spinner"></span> در حال ذخیره‌سازی...';
    });
}

function previewImageModal(src, title) {
    const modal = document.getElementById('galleryModal');
    const img = document.getElementById('galleryModalImg');
    const titleEl = document.getElementById('galleryModalTitle');
    if (modal && img) {
        img.src = src;
        if (titleEl) titleEl.textContent = title || '';
        modal.classList.add('is-open');
    }
}

function closeImageModal() {
    const modal = document.getElementById('galleryModal');
    if (modal) modal.classList.remove('is-open');
}

function toggleGalleryActive(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_active');
    formData.append('image_id', id);
    formData.append('csrf_token', '<?= e(generateCsrfToken()) ?>');
    formData.append('ajax', '1');

    fetch('<?= e(url('admin/gallery.php')) ?>', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => {
        if (data && data.success) {
            if (data.newStatus === 1) {
                btn.className = 'gallery-status-btn active';
                btn.textContent = 'فعال';
            } else {
                btn.className = 'gallery-status-btn inactive';
                btn.textContent = 'غیرفعال';
            }
            if (typeof showToast === 'function') showToast(data.message, 'success');
        } else if (data && data.error) {
            if (typeof showToast === 'function') showToast(data.error, 'danger');
        }
    })
    .catch(() => {
        window.location.reload();
    });
}

// ── TABLE DRAG & DROP REORDERING ────────────────────────────
function initGalleryDragAndDrop() {
    const tbody = document.getElementById('gallerySortTbody');
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

            const form = document.getElementById('galleryReorderForm');
            const formData = new FormData();
            formData.append('action', 'update_sort');
            formData.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
            formData.append('ajax', '1');

            tbody.querySelectorAll('.slide-row').forEach((r, idx) => {
                const sortInput = r.querySelector('.slide-sort-num');
                if (sortInput) sortInput.value = idx;
                formData.append(`sort_order[${r.dataset.id}]`, idx);
            });

            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    if (typeof showToast === 'function') showToast(data.message || 'ترتیب تصاویر ذخیره شد.', 'success');
                } else if (data && data.error) {
                    if (typeof showToast === 'function') showToast(data.error, 'danger');
                }
            })
            .catch(() => {});
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initGalleryDragAndDrop();
    <?php if ($editImage): ?>
        openGalleryDrawer();
    <?php endif; ?>
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const modal = document.getElementById('galleryModal');
        if (modal && modal.classList.contains('is-open')) {
            closeImageModal();
        }
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

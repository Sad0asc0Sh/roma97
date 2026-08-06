<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

// ─── Helper Functions ─────────────────────────────────────────────────────────

function galleryStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function findGalleryImage(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM gallery_images WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $row = $statement->fetch();

    return $row ?: null;
}

function getAllGalleryImages(PDO $pdo, int $limit = 20, int $offset = 0): array
{
    $statement = $pdo->prepare(
        'SELECT * FROM gallery_images ORDER BY sort_order ASC, created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
    );
    $statement->execute();

    return $statement->fetchAll();
}

function countGalleryImages(PDO $pdo): int
{
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
 * Handle gallery image upload. Returns the relative path on success, null if no file uploaded.
 *
 * @param bool $required If true, throws when no file is uploaded (used for new images).
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
        throw new RuntimeException('بارگذاری تصویر با خطا مواجه شد (کد: ' . $errorCode . ').');
    }

    if (($file['size'] ?? 0) > 2048000) {
        throw new RuntimeException('حجم تصویر بیشتر از ۲ مگابایت است.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('فایل آپلود نامعتبر است.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedTypes = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    if (!array_key_exists($extension, $allowedTypes)) {
        throw new RuntimeException('فرمت فایل مجاز نیست. فقط JPG، PNG، GIF و WebP پشتیبانی می‌شود.');
    }

    // Verify MIME type
    if (class_exists('finfo')) {
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);

        if (!is_string($mimeType) || !in_array($mimeType, $allowedTypes[$extension], true)) {
            throw new RuntimeException('نوع MIME فایل با پسوند مطابقت ندارد.');
        }
    }

    // Verify actual image content
    if (getimagesize($tmpName) === false) {
        throw new RuntimeException('فایل یک تصویر معتبر نیست.');
    }

    $uploadDir = __DIR__ . '/../assets/uploads/gallery';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('ایجاد پوشه آپلود ممکن نیست.');
    }

    $fileName    = uniqid('gallery_', true) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('ذخیره فایل آپلود شده ممکن نیست.');
    }

    return 'assets/uploads/gallery/' . $fileName;
}

// ─── Database connection ──────────────────────────────────────────────────────
// Table gallery_images is created at install time via schema.sql / setup.php.

try {
    $pdo = getDb();
} catch (Throwable $e) {
    error_log($e->getMessage());
    setFlash('error', 'گالری در دسترس نیست.');
    redirect(url('admin/index.php'));
}

// ─── POST handling ────────────────────────────────────────────────────────────

if (isPostRequest()) {
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
                    'UPDATE gallery_images SET title = :t, caption = :c, image = :i, sort_order = :s, is_active = :a WHERE id = :id'
                )->execute([
                    ':t'  => $title !== '' ? $title : null,
                    ':c'  => $caption !== '' ? $caption : null,
                    ':i'  => $imagePath,
                    ':s'  => $sortOrder,
                    ':a'  => $isActive,
                    ':id' => $imageId,
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
                'INSERT INTO gallery_images (title, caption, image, sort_order, is_active) VALUES (:t, :c, :i, :s, :a)'
            )->execute([
                ':t' => $title !== '' ? $title : null,
                ':c' => $caption !== '' ? $caption : null,
                ':i' => $imagePath,
                ':s' => $sortOrder,
                ':a' => $isActive,
            ]);

            recordAudit('gallery.create', 'gallery', (int) $pdo->lastInsertId());
            setFlash('success', 'تصویر با موفقیت اضافه شد.');
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

            if (!$image) {
                setFlash('error', 'تصویر یافت نشد.');
                redirect(url('admin/gallery.php'));
            }

            $newStatus = ((int) $image['is_active'] === 1) ? 0 : 1;
            $pdo->prepare('UPDATE gallery_images SET is_active = :a WHERE id = :id')
                ->execute([':a' => $newStatus, ':id' => $imageId]);

            recordAudit('gallery.toggle', 'gallery', $imageId);
            setFlash('success', 'وضعیت تصویر تغییر کرد.');
            redirect(url('admin/gallery.php'));
        }

        // ─── Bulk sort order update ───────────────────────────────────────
        if ($action === 'update_sort') {
            $orders = $_POST['sort_order'] ?? [];
            if (!is_array($orders)) {
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
                    setFlash('error', 'ترتیب نامعتبر است.');
                    redirect(url('admin/gallery.php'));
                }

                $stmt->execute([':s' => $gso, ':id' => $gid]);
            }

            $pdo->commit();
            recordAudit('gallery.reorder', 'gallery');
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

$editId    = parseGalleryId($_GET['edit'] ?? null);
$editImage = $editId > 0 ? findGalleryImage($pdo, $editId) : null;

$perPage       = 20;
$currentPage   = max(1, (int) ($_GET['page'] ?? 1));
$total         = countGalleryImages($pdo);
$totalPages    = max(1, (int) ceil($total / $perPage));
$currentPage   = min($currentPage, $totalPages);
$offset        = ($currentPage - 1) * $perPage;
$galleryImages = getAllGalleryImages($pdo, $perPage, $offset);

$pagi = [
    'current'    => $currentPage,
    'totalPages' => $totalPages,
    'total'      => $total,
    'perPage'    => $perPage,
    'from'       => $total > 0 ? $offset + 1 : 0,
    'to'         => min($offset + $perPage, $total),
];

$pageTitle = 'گالری تصاویر | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="admin-page">
    <div class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title"><?= $editImage ? 'ویرایش تصویر' : 'افزودن تصویر جدید' ?></h2>
        </div>
        <?php $flashError = getFlash('error'); $flashSuccess = getFlash('success'); ?>
        <?php if ($flashError !== null && $flashError !== ''): ?>
            <div class="alert alert-error"><?= e($flashError) ?></div>
        <?php endif; ?>
        <?php if ($flashSuccess !== null && $flashSuccess !== ''): ?>
            <div class="alert alert-success"><?= e($flashSuccess) ?></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" action="<?= e(url('admin/gallery.php')) ?>" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_image">
            <?php if ($editImage): ?>
                <input type="hidden" name="image_id" value="<?= e($editImage['id']) ?>">
            <?php endif; ?>
            <div class="form-grid form-grid-2">
                <div class="form-group">
                    <label for="gtitle" class="form-label">عنوان (اختیاری)</label>
                    <input type="text" id="gtitle" name="title" class="form-control" value="<?= e($editImage['title'] ?? '') ?>" maxlength="255">
                </div>
                <div class="form-group">
                    <label for="gso" class="form-label">ترتیب نمایش</label>
                    <input type="number" id="gso" name="sort_order" class="form-control" value="<?= e($editImage['sort_order'] ?? '0') ?>" min="0" step="1" required>
                </div>
            </div>
            <div class="form-group">
                <label for="gcap" class="form-label">توضیحات (اختیاری)</label>
                <textarea id="gcap" name="caption" class="form-control" rows="2" maxlength="500"><?= e($editImage['caption'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label for="gimg" class="form-label">تصویر <?= $editImage ? '<span class="text-muted">(برای تغییر جدید)</span>' : '' ?></label>
                <input type="file" id="gimg" name="image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" <?= $editImage ? '' : 'required' ?>>
                <small class="text-muted">JPG, PNG, GIF, WebP — max 2MB</small>
            </div>
            <?php if ($editImage): ?>
                <div class="form-group">
                    <label class="form-label">تصویر فعلی</label>
                    <div class="admin-image-preview-wrap">
                        <img src="<?= e(url($editImage['image'])) ?>" alt="" class="admin-image-preview">
                    </div>
                </div>
            <?php endif; ?>
            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="is_active" value="1" <?= ($editImage === null || (int) $editImage['is_active'] === 1) ? 'checked' : '' ?>>
                    <span class="form-check-label">فعال</span>
                </label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $editImage ? 'بروزرسانی' : 'افزودن' ?></button>
                <?php if ($editImage): ?>
                    <a href="<?= e(url('admin/gallery.php')) ?>" class="btn btn-outline">لغو</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title">همه تصاویر</h2>
        </div>
        <?php if ($galleryImages === []): ?>
            <div class="empty-state empty-state-sm">
                <div class="empty-state-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </div>
                <h3>هنوز تصویری نیست</h3>
                <p>از فرم بالا اضافه کنید.</p>
            </div>
        <?php else: ?>
            <form method="post" action="<?= e(url('admin/gallery.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_sort">
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>تصویر</th>
                                <th>عنوان</th>
                                <th>ترتیب</th>
                                <th>وضعیت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($galleryImages as $gi): ?>
                            <tr>
                                <td><img src="<?= e(url($gi['image'])) ?>" alt="" class="admin-gallery-thumb"></td>
                                <td style="font-weight:600"><?= e($gi['title'] ?? '—') ?></td>
                                <td><input type="number" name="sort_order[<?= e($gi['id']) ?>]" min="0" value="<?= e($gi['sort_order']) ?>" class="admin-sort-input" required></td>
                                <td>
                                    <form method="post" action="<?= e(url('admin/gallery.php')) ?>" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="image_id" value="<?= e($gi['id']) ?>">
                                        <button type="submit" class="btn btn-xs <?= (int) $gi['is_active'] === 1 ? 'btn-success' : 'btn-muted' ?>">
                                            <?= (int) $gi['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <a href="<?= e(url('admin/gallery.php?edit=' . $gi['id'])) ?>" class="btn btn-sm btn-secondary">ویرایش</a>
                                    <form method="post" action="<?= e(url('admin/gallery.php')) ?>" style="display:inline" onsubmit="return confirm('حذف شود؟')">
                                        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_image">
                                        <input type="hidden" name="image_id" value="<?= e($gi['id']) ?>">
                                        <button type="submit" class="btn btn-sm btn-reject">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-sm">بروزرسانی ترتیب</button>
                </div>
            </form>
            <?php if ($pagi['total'] > $pagi['perPage']): ?>
                <p class="pagination-summary">نمایش <?= e(persianNumber($pagi['from'])) ?> تا <?= e(persianNumber($pagi['to'])) ?> از <?= e(persianNumber($pagi['total'])) ?> تصویر</p>
                <?= renderPagination($pagi, url('admin/gallery.php')) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
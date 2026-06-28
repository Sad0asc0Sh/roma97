<?php
declare(strict_types=1);

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
    $statement = $pdo->prepare('SELECT id, title, subtitle, image, sort_order, created_at FROM slides WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $slide = $statement->fetch();

    return $slide ?: null;
}

function getAllSlides(PDO $pdo, int $limit = 20, int $offset = 0): array
{
    $statement = $pdo->prepare(
        'SELECT id, title, subtitle, image, sort_order, created_at FROM slides ORDER BY sort_order ASC, created_at DESC'
        . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
    );
    $statement->execute();

    return $statement->fetchAll();
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

function uploadSlideImage(array $file, bool $required): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            throw new RuntimeException('تصویر اسلاید نامعتبر است.');
        }

        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('تصویر اسلاید نامعتبر است.');
    }

    if (($file['size'] ?? 0) > 2048000) {
        throw new RuntimeException('تصویر اسلاید نامعتبر است.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('تصویر اسلاید نامعتبر است.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedTypes = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    if (!array_key_exists($extension, $allowedTypes)) {
        throw new RuntimeException('تصویر اسلاید نامعتبر است.');
    }

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);

        if (!is_string($mimeType) || !in_array($mimeType, $allowedTypes[$extension], true)) {
            throw new RuntimeException('تصویر اسلاید نامعتبر است.');
        }
    }

    if (getimagesize($tmpName) === false) {
        throw new RuntimeException('تصویر اسلاید نامعتبر است.');
    }

    $uploadDir = __DIR__ . '/../assets/uploads';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('بارگذاری تصویر اسلاید در دسترس نیست.');
    }

    $fileName = uniqid('slide_', true) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('بارگذاری تصویر اسلاید در دسترس نیست.');
    }

    return 'assets/uploads/' . $fileName;
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

            $newImage = uploadSlideImage($_FILES['image'] ?? [], !$isEdit);
            $imagePath = $newImage ?? (string) ($currentSlide['image'] ?? '');

            if ($imagePath === '') {
                setFlash('error', 'لطفاً تصویر معتبر اسلاید را بارگذاری کنید.');
                redirect($isEdit ? url('admin/slides.php?edit=' . $slideId) : url('admin/slides.php'));
            }

            if ($isEdit) {
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

                if ($newImage !== null) {
                    deleteSlideImage((string) $currentSlide['image']);
                }

                recordAudit('slide.update', 'slide', (int) $slideId);
                setFlash('success', 'اسلاید با موفقیت بهروزرسانی شد.');
                redirect(url('admin/slides.php'));
            }

            $statement = $pdo->prepare(
                'INSERT INTO slides (title, subtitle, image, sort_order) VALUES (:title, :subtitle, :image, :sort_order)'
            );
            $statement->execute([
                ':title' => $title,
                ':subtitle' => $subtitle !== '' ? $subtitle : null,
                ':image' => $imagePath,
                ':sort_order' => $sortOrder,
            ]);

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

        error_log($exception->getMessage());
        setFlash('error', 'اسلایدها ذخیره نشدند. لطفاً فرم را بررسی کرده و دوباره تلاش کنید.');
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
    <h1>&#127912; اسلایدها</h1>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status">&#9989; <?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger" role="alert">&#10060; <?= e($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($deleteSlide !== null): ?>
        <div class="alert alert-danger" role="alert">
            <p>&#9888;&#65039; آیا اسلاید «<?= e($deleteSlide['title']) ?>» حذف شود؟</p>
            <form method="post" action="<?= e(url('admin/slides.php')) ?>" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="delete_slide">
                <input type="hidden" name="slide_id" value="<?= e($deleteSlide['id']) ?>">
                <button type="submit" class="btn btn-danger btn-sm">&#128465; حذف اسلاید</button>
                <a href="<?= e(url('admin/slides.php')) ?>" class="btn btn-outline btn-sm" style="margin-inline-start:8px;">انصراف</a>
            </form>
        </div>
    <?php endif; ?>

    <div class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title" id="slide-form-title">&#10010; <?= $editSlide ? 'ویرایش اسلاید' : 'افزودن اسلاید' ?></h2>
        </div>
        <div style="margin-bottom:16px;padding:12px 16px;background:#f0f9f4;border-right:3px solid #3D8B63;border-radius:8px;font-size:0.875rem;line-height:2"><strong>&#128161; راهنمای ابعاد بنر:</strong> دسکتاپ: ۱۹۲۰×۶۰۰ | تبلت: ۱۰۲۴×۴۵۰ | موبایل: ۷۵۰×۱۰۰۰ — فرمت: JPG/PNG/WebP — حداکثر ۲MB — نسبت ایده‌آل: <strong>۱۶:۵</strong></div>
        <form method="post" action="<?= e(url('admin/slides.php')) ?>" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_slide">
            <?php if ($editSlide): ?>
                <input type="hidden" name="slide_id" value="<?= e($editSlide['id']) ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="title" class="form-label">عنوان اسلاید</label>
                <input type="text" id="title" name="title" class="form-control"
                    maxlength="255"
                    placeholder="عنوان اسلاید را وارد کنید..."
                    value="<?= e($editSlide['title'] ?? '') ?>"
                    required>
            </div>

            <div class="form-group">
                <label for="subtitle" class="form-label">زیرعنوان <span style="color:var(--muted);font-weight:400">(اختیاری)</span></label>
                <input type="text" id="subtitle" name="subtitle" class="form-control"
                    maxlength="500"
                    placeholder="متن توضیحی زیر عنوان بنر"
                    value="<?= e($editSlide['subtitle'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="sort_order" class="form-label">ترتیب نمایش</label>
                <input type="number" id="sort_order" name="sort_order" class="form-control"
                    style="max-width:150px;"
                    min="0" step="1"
                    value="<?= e($editSlide['sort_order'] ?? '0') ?>"
                    required>
            </div>

            <?php if ($editSlide): ?>
                <div class="form-group">
                    <label class="form-label">تصویر فعلی</label>
                    <img src="<?= e(url($editSlide['image'])) ?>" alt="<?= e($editSlide['title']) ?>" class="admin-image-preview">
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="image" class="form-label">تصویر <?= $editSlide ? '(اختیاری - برای تغییر)' : '' ?></label>
                <input type="file" id="image" name="image" class="form-control"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    <?= $editSlide ? '' : 'required' ?>>
                <small style="color:var(--muted);font-size:0.85rem;">فرمت: JPG, PNG, WebP — حداکثر ۲ مگابایت — ابعاد پیشنهادی: ۱۹۲۰×۶۰۰ px</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    &#128190; <?= $editSlide ? 'بهروزرسانی اسلاید' : 'افزودن اسلاید' ?>
                </button>
                <?php if ($editSlide): ?>
                    <a href="<?= e(url('admin/slides.php')) ?>" class="btn btn-outline">&#10006; لغو ویرایش</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title">&#128196; همه اسلایدها</h2>
        </div>

        <?php if ($slides === []): ?>
            <div class="empty-state empty-state-sm">
                <div class="empty-state-icon">&#128444;</div>
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
                                        <img src="<?= e(url($slide['image'])) ?>" alt="<?= e($slide['title']) ?>" class="admin-slide-thumb">
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
                                        <a href="<?= e(url('admin/slides.php?edit=' . $slide['id'])) ?>" class="btn btn-sm btn-secondary">&#9998; ویرایش</a>
                                        <a href="<?= e(url('admin/slides.php?delete=' . $slide['id'])) ?>" class="btn btn-sm btn-reject">&#128465; حذف</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-sm">&#128260; بهروزرسانی ترتیب نمایش</button>
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

<?php require_once __DIR__ . '/footer.php'; ?>
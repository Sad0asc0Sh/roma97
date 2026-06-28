<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

function newsStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function parseNewsId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    return is_int($id) ? $id : 0;
}

function findNewsItem(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, title, content, image, created_at FROM news WHERE id = :id LIMIT 1'
    );
    $statement->execute([':id' => $id]);
    $newsItem = $statement->fetch();

    return $newsItem ?: null;
}

function getAllNewsItems(PDO $pdo, int $limit = 20, int $offset = 0): array
{
    $statement = $pdo->prepare(
        'SELECT id, title, content, image, created_at FROM news ORDER BY created_at DESC'
        . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
    );
    $statement->execute();

    return $statement->fetchAll();
}

function countNewsItems(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM news')->fetchColumn();
}

function deleteNewsImage(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === '') {
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

function uploadNewsImage(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('تصویر خبر نامعتبر است.');
    }

    if (($file['size'] ?? 0) > 512000) {
        throw new RuntimeException('تصویر خبر نامعتبر است.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('تصویر خبر نامعتبر است.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedTypes = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
    ];

    if (!array_key_exists($extension, $allowedTypes)) {
        throw new RuntimeException('تصویر خبر نامعتبر است.');
    }

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);

        if (!is_string($mimeType) || !in_array($mimeType, $allowedTypes[$extension], true)) {
            throw new RuntimeException('تصویر خبر نامعتبر است.');
        }
    }

    if (getimagesize($tmpName) === false) {
        throw new RuntimeException('تصویر خبر نامعتبر است.');
    }

    $uploadDir = __DIR__ . '/../assets/uploads';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('بارگذاری تصویر خبر در دسترس نیست.');
    }

    $fileName = 'news-' . bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('بارگذاری تصویر خبر در دسترس نیست.');
    }

    return 'assets/uploads/' . $fileName;
}

function formatAdminNewsDate(string $date): string
{
    $timestamp = strtotime($date);

    return $timestamp === false ? $date : shamsiDate(date('Y-m-d', $timestamp));
}

try {
    initializeCmsTables();
    $pdo = getDb();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    setFlash('error', 'اخبار موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.');
    redirect(url('admin/index.php'));
}

if (isPostRequest()) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = (string) ($_POST['action'] ?? '');

    if (!validateCsrfToken($csrfToken)) {
        setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        redirect(url('admin/news.php'));
    }

    try {
        if ($action === 'save_news') {
            $newsId = parseNewsId($_POST['news_id'] ?? null);
            $title = trim((string) ($_POST['title'] ?? ''));
            $content = trim((string) ($_POST['content'] ?? ''));
            $isEdit = $newsId > 0;
            $currentNewsItem = null;
            $newImage = null;

            if ($title === '' || newsStringLength($title) > 255 || $content === '') {
                setFlash('error', 'لطفاً عنوان و محتوای معتبر وارد کنید.');
                redirect($isEdit ? url('admin/news.php?edit=' . $newsId) : url('admin/news.php'));
            }

            if ($isEdit) {
                $currentNewsItem = findNewsItem($pdo, $newsId);

                if ($currentNewsItem === null) {
                    setFlash('error', 'خبر پیدا نشد.');
                    redirect(url('admin/news.php'));
                }
            }

            $newImage = uploadNewsImage($_FILES['image'] ?? []);
            $imagePath = $newImage ?? ($currentNewsItem['image'] ?? null);

            if ($isEdit) {
                $statement = $pdo->prepare(
                    'UPDATE news SET title = :title, content = :content, image = :image WHERE id = :id'
                );
                $statement->execute([
                    ':title' => $title,
                    ':content' => $content,
                    ':image' => $imagePath,
                    ':id' => $newsId,
                ]);

                if ($newImage !== null) {
                    deleteNewsImage($currentNewsItem['image'] ?? null);
                }

                recordAudit('news.update', 'news', (int) $newsId);
                setFlash('success', 'خبر با موفقیت به‌روزرسانی شد.');
                redirect(url('admin/news.php'));
            }

            $statement = $pdo->prepare(
                'INSERT INTO news (title, content, image) VALUES (:title, :content, :image)'
            );
            $statement->execute([
                ':title' => $title,
                ':content' => $content,
                ':image' => $imagePath,
            ]);

            recordAudit('news.create', 'news', (int) $pdo->lastInsertId());
            setFlash('success', 'خبر با موفقیت ایجاد شد.');
            redirect(url('admin/news.php'));
        }

        if ($action === 'delete_news') {
            $newsId = parseNewsId($_POST['news_id'] ?? null);
            $newsItem = $newsId > 0 ? findNewsItem($pdo, $newsId) : null;

            if ($newsItem === null) {
                setFlash('error', 'خبر پیدا نشد.');
                redirect(url('admin/news.php'));
            }

            $statement = $pdo->prepare('DELETE FROM news WHERE id = :id');
            $statement->execute([':id' => $newsId]);
            deleteNewsImage($newsItem['image'] ?? null);

            recordAudit('news.delete', 'news', (int) $newsId);
            setFlash('success', 'خبر با موفقیت حذف شد.');
            redirect(url('admin/news.php'));
        }

        setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        redirect(url('admin/news.php'));
    } catch (Throwable $exception) {
        if (isset($newImage) && is_string($newImage)) {
            deleteNewsImage($newImage);
        }

        error_log($exception->getMessage());
        setFlash('error', 'خبر ذخیره نشد. لطفاً فرم را بررسی کرده و دوباره تلاش کنید.');
        redirect(url('admin/news.php'));
    }
}

$editId = parseNewsId($_GET['edit'] ?? null);
$editNewsItem = $editId > 0 ? findNewsItem($pdo, $editId) : null;

$perPage = 20;
$pagination = paginate(countNewsItems($pdo), currentPageNumber(), $perPage);
$newsItems = getAllNewsItems($pdo, $pagination['perPage'], $pagination['offset']);
$successMessage = getFlash('success');
$errorMessage = getFlash('error');

$pageTitle = 'مدیریت اخبار | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <h1>اخبار</h1>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <section class="form-card" aria-labelledby="news-form-title">
        <h2 id="news-form-title"><?= $editNewsItem ? 'ویرایش خبر' : 'افزودن خبر' ?></h2>
        <form method="post" action="<?= e(url('admin/news.php')) ?>" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_news">
            <?php if ($editNewsItem): ?>
                <input type="hidden" name="news_id" value="<?= e($editNewsItem['id']) ?>">
            <?php endif; ?>

            <label for="title">عنوان</label>
            <input
                type="text"
                id="title"
                name="title"
                maxlength="255"
                value="<?= e($editNewsItem['title'] ?? '') ?>"
                required
            >

            <label for="content">محتوا</label>
            <textarea id="content" name="content" rows="8" required><?= e($editNewsItem['content'] ?? '') ?></textarea>

            <?php if ($editNewsItem && !empty($editNewsItem['image'])): ?>
                <div>
                    <p>تصویر فعلی</p>
                    <img src="<?= e(url($editNewsItem['image'])) ?>" alt="<?= e($editNewsItem['title']) ?>" class="admin-image-preview">
                </div>
            <?php endif; ?>

            <label for="image">تصویر (اختیاری)</label>
            <input
                type="file"
                id="image"
                name="image"
                accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif"
            >
            <p>فرمت‌های مجاز: JPG، PNG، GIF. حداکثر حجم: ۵۰۰ کیلوبایت.</p>

            <button type="submit"><?= $editNewsItem ? 'به‌روزرسانی خبر' : 'افزودن خبر' ?></button>
            <?php if ($editNewsItem): ?>
                <a href="<?= e(url('admin/news.php')) ?>">لغو ویرایش</a>
            <?php endif; ?>
        </form>
    </section>

    <section aria-labelledby="news-list-title" class="margin-top-xl">
        <h2 id="news-list-title">همه اخبار</h2>

        <?php if ($newsItems === []): ?>
            <p>هنوز هیچ خبری اضافه نشده است.</p>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>عنوان</th>
                            <th>تاریخ</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($newsItems as $newsItem): ?>
                            <tr>
                                <td><?= e($newsItem['title']) ?></td>
                                <td><?= e(formatAdminNewsDate($newsItem['created_at'])) ?></td>
                                <td>
                                    <a href="<?= e(url('admin/news.php?edit=' . $newsItem['id'])) ?>">ویرایش</a>
                                    |
                                    <form method="post" action="<?= e(url('admin/news.php')) ?>" class="form-inline" onsubmit="return confirm('این خبر حذف شود؟');">
                                        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_news">
                                        <input type="hidden" name="news_id" value="<?= e($newsItem['id']) ?>">
                                        <button type="submit" class="btn-reset">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pagination['total'] > $pagination['perPage']): ?>
                <p class="pagination-summary">
                    نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> خبر
                </p>
                <?= renderPagination($pagination, url('admin/news.php')) ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>

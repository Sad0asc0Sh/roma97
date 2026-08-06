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
                setFlash('success', 'خبر با موفقیت بهروزرسانی شد.');
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

<div class="admin-content">
    <div class="admin-page-header">
        <h1>مدیریت اخبار</h1>
    </div>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <div class="admin-section">
        <div style="background: #E8F3EE; border-radius: 16px; padding: 16px 24px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
            <div style="display: flex; align-items: center;">
                <svg width="100" height="60" viewBox="0 0 160 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Plant in Pot -->
                    <path d="M25 80 L35 80 L37 92 L23 92 Z" fill="#A8D5BA"/>
                    <ellipse cx="30" cy="80" rx="6" ry="2" fill="#81C784"/>
                    <path d="M30 80 Q25 65 20 60 Q26 62 30 70 Q34 60 40 58 Q35 68 30 80 Z" fill="#4CAF50"/>
                    <path d="M30 70 Q20 72 15 76 Q22 78 30 73 Q38 75 45 72 Q38 70 30 70 Z" fill="#66BB6A"/>
                    <!-- Books -->
                    <rect x="42" y="82" width="22" height="10" rx="2" fill="#FFB74D"/>
                    <rect x="44" y="74" width="18" height="8" rx="2" fill="#64B5F6"/>
                    <rect x="45" y="67" width="16" height="7" rx="2" fill="#E1BEE7"/>
                    <!-- Teddy Bear -->
                    <circle cx="82" cy="46" r="9" fill="#D7CCC8"/>
                    <circle cx="82" cy="46" r="5" fill="#F8BBD0"/>
                    <circle cx="118" cy="46" r="9" fill="#D7CCC8"/>
                    <circle cx="118" cy="46" r="5" fill="#F8BBD0"/>
                    <circle cx="100" cy="58" r="22" fill="#D7CCC8"/>
                    <ellipse cx="100" cy="63" rx="9" ry="7" fill="#EFEBE9"/>
                    <ellipse cx="100" cy="60" rx="3.5" ry="2.5" fill="#5D4037"/>
                    <path d="M100 62.5 L100 66 Q97 68 95 67 M100 66 Q103 68 105 67" stroke="#5D4037" stroke-width="1.5" stroke-linecap="round"/>
                    <circle cx="92" cy="55" r="2.5" fill="#3E2723"/>
                    <circle cx="108" cy="55" r="2.5" fill="#3E2723"/>
                    <circle cx="93" cy="54" r="0.8" fill="#FFFFFF"/>
                    <circle cx="109" cy="54" r="0.8" fill="#FFFFFF"/>
                    <ellipse cx="87" cy="61" rx="3.5" ry="2" fill="#FF8A80" opacity="0.6"/>
                    <ellipse cx="113" cy="61" rx="3.5" ry="2" fill="#FF8A80" opacity="0.6"/>
                    <path d="M83 75 C80 88 120 88 117 75 Z" fill="#D7CCC8"/>
                    <path d="M93 74 L100 77 L107 74 L100 79 Z" fill="#FF8A65"/>
                    <circle cx="100" cy="76" r="2" fill="#E64A19"/>
                    <ellipse cx="78" cy="80" rx="5" ry="7" fill="#D7CCC8"/>
                    <ellipse cx="122" cy="80" rx="5" ry="7" fill="#D7CCC8"/>
                </svg>
            </div>
            <div style="display: flex; align-items: center; gap: 12px; font-size: 1.3rem; font-weight: 800; color: #1E293B;">
                <span><?= $editNewsItem ? 'ویرایش خبر' : 'افزودن خبر' ?></span>
                <div style="width: 36px; height: 36px; border-radius: 50%; background: #FFFFFF; color: #4EAB87; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; font-weight: 700; box-shadow: 0 2px 6px rgba(0,0,0,0.06);">+</div>
            </div>
        </div>

        <form method="post" action="<?= e(url('admin/news.php')) ?>" enctype="multipart/form-data" novalidate style="padding: 10px 0 0;">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_news">
            <?php if ($editNewsItem): ?>
                <input type="hidden" name="news_id" value="<?= e($editNewsItem['id']) ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="title" class="form-label">عنوان خبر <span style="color:#EF4444;">*</span></label>
                <input type="text" id="title" name="title" class="form-control"
                    maxlength="255"
                    placeholder="عنوان خبر را وارد کنید..."
                    value="<?= e($editNewsItem['title'] ?? '') ?>"
                    required>
            </div>

            <div class="form-group">
                <label for="content" class="form-label">محتوای خبر <span style="color:#EF4444;">*</span></label>
                <textarea id="content" name="content" class="form-control" rows="6"
                    placeholder="متن خبر را اینجا بنویسید..."
                    required><?= e($editNewsItem['content'] ?? '') ?></textarea>
            </div>

            <?php if ($editNewsItem && !empty($editNewsItem['image'])): ?>
                <div class="form-group">
                    <label class="form-label">تصویر فعلی</label>
                    <div class="admin-image-preview-wrap">
                        <img src="<?= e(url($editNewsItem['image'])) ?>" alt="<?= e($editNewsItem['title']) ?>" class="admin-image-preview">
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="image" class="form-label" style="font-weight:700; font-size:0.95rem; color:#475569; margin-bottom:8px; display:block;">تصویر (اختیاری)</label>
                <div onclick="document.getElementById('image').click()" style="border: 2px dashed #CBD5E1; border-radius: 16px; background: #F8FAFC; padding: 32px 20px; text-align: center; cursor: pointer; transition: all 0.2s ease;">
                    <input type="file" id="image" name="image" style="display: none !important;"
                        accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif"
                        onchange="document.getElementById('filename-text').textContent = this.files[0] ? this.files[0].name : 'No file chosen'">

                    <!-- Cloud with Image & Green Arrow Icon -->
                    <div style="margin-bottom: 12px; display: flex; justify-content: center;">
                        <svg width="100" height="75" viewBox="0 0 100 75" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <!-- Soft Purple Cloud -->
                            <path d="M25 60 C12 60 5 49 10 37 C13 27 25 25 30 27 C34 15 50 11 62 19 C70 13 84 17 86 29 C94 31 98 43 90 55 C86 60 78 60 75 60 Z" fill="#EEF2FF"/>
                            <!-- Inner Picture Frame -->
                            <rect x="36" y="28" width="28" height="20" rx="4" fill="#818CF8"/>
                            <path d="M39 44 L46 36 L52 42 L56 38 L61 44 Z" fill="#C7D2FE"/>
                            <circle cx="43" cy="33" r="2.5" fill="#FEF08A"/>
                            <!-- Green Upload Circle Button with White Up Arrow -->
                            <circle cx="62" cy="50" r="14" fill="#22C55E"/>
                            <path d="M62 56 L62 44 M56 49 L62 44 L68 49" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>

                    <div id="filename-text" style="font-size: 0.95rem; font-weight: 500; color: #475569; margin-bottom: 8px;">No file chosen</div>
                    <div style="font-size: 0.82rem; color: #94A3B8;">فرمت‌های مجاز: <span style="color: #6366F1; font-weight: 600;">JPG, PNG, GIF</span>. حداکثر حجم: ۵۰۰ کیلوبایت.</div>
                </div>
            </div>

            <div class="form-actions" style="margin-top:20px;">
                <button type="submit" class="btn btn-primary btn-block" style="background:#4EAB87; border:none; padding:14px; font-size:1rem; font-weight:700; border-radius:12px; display:flex; align-items:center; justify-content:center; gap:8px;">
                    <?= $editNewsItem ? 'به‌روزرسانی خبر' : 'افزودن خبر' ?>
                </button>
                <?php if ($editNewsItem): ?>
                    <a href="<?= e(url('admin/news.php')) ?>" class="btn btn-outline" style="border-radius:12px;">لغو ویرایش</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title">همه اخبار</h2>
        </div>

        <?php if ($newsItems === []): ?>
            <div class="empty-state empty-state-sm">
                <div class="empty-state-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2"/></svg>
                </div>
                <h3>هنوز خبری اضافه نشده</h3>
                <p>از فرم بالا اولین خبر خود را اضافه کنید.</p>
            </div>
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
                                <td style="font-weight:600;"><?= e($newsItem['title']) ?></td>
                                <td><?= e(formatAdminNewsDate($newsItem['created_at'])) ?></td>
                                <td>
                                    <a href="<?= e(url('admin/news.php?edit=' . $newsItem['id'])) ?>" class="btn btn-sm btn-secondary">ویرایش</a>
                                    <form method="post" action="<?= e(url('admin/news.php')) ?>" class="form-inline" onsubmit="return confirm('آیا این خبر حذف شود؟');">
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
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
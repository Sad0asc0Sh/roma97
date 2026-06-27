<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

function pageStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function parsePageId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    return is_int($id) ? $id : 0;
}

function isValidPageSlug(string $slug): bool
{
    return pageStringLength($slug) <= 100 && preg_match('/\A[a-z0-9-]+\z/', $slug) === 1;
}

function findPage(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, slug, title, content, created_at FROM pages WHERE id = :id LIMIT 1'
    );
    $statement->execute([':id' => $id]);
    $page = $statement->fetch();

    return $page ?: null;
}

function getAllPages(PDO $pdo, int $limit = 20, int $offset = 0): array
{
    $statement = $pdo->prepare(
        'SELECT id, slug, title, content, created_at FROM pages ORDER BY created_at DESC'
        . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
    );
    $statement->execute();

    return $statement->fetchAll();
}

function countPages(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn();
}

function pageSlugExists(PDO $pdo, string $slug, int $exceptId = 0): bool
{
    $sql = 'SELECT id FROM pages WHERE slug = :slug';
    $params = [':slug' => $slug];

    if ($exceptId > 0) {
        $sql .= ' AND id <> :id';
        $params[':id'] = $exceptId;
    }

    $sql .= ' LIMIT 1';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (bool) $statement->fetch();
}

function seedDefaultPages(PDO $pdo): void
{
    $countPages = $pdo->prepare('SELECT COUNT(*) FROM pages');
    $countPages->execute();

    if ((int) $countPages->fetchColumn() > 0) {
        return;
    }

    $defaults = [
        [
            'slug' => 'about',
            'title' => 'درباره ما',
            'content' => 'به مهد کودک روما خوش آمدید. ما محیطی گرم، امن و شاد را فراهم میکنیم تا کودکان بتوانند یاد بگیرند، بازی کنند و رشد کنند.',
        ],
        [
            'slug' => 'services',
            'title' => 'خدمات ما',
            'content' => 'ما برنامههای مراقبت روزانه، فعالیتهای یادگیری اولیه، بازیهای خلاقانه و حمایت دلسوزانه از کودکان و خانوادهها را ارائه میدهیم.',
        ],
    ];

    $statement = $pdo->prepare(
        'INSERT INTO pages (slug, title, content) VALUES (:slug, :title, :content)'
    );

    foreach ($defaults as $page) {
        $statement->execute([
            ':slug' => $page['slug'],
            ':title' => $page['title'],
            ':content' => $page['content'],
        ]);
    }
}

function formatAdminPageDate(string $date): string
{
    $timestamp = strtotime($date);

    return $timestamp === false ? $date : shamsiDate(date('Y-m-d', $timestamp));
}

try {
    initializeCmsTables();
    $pdo = getDb();
    seedDefaultPages($pdo);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    setFlash('error', 'صفحات موقتاً در دسترس نیستند. لطفاً بعداً دوباره تلاش کنید.');
    redirect(url('admin/index.php'));
}

if (isPostRequest()) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = (string) ($_POST['action'] ?? '');

    if (!validateCsrfToken($csrfToken)) {
        setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        redirect(url('admin/pages.php'));
    }

    try {
        if ($action === 'save_page') {
            $pageId = parsePageId($_POST['page_id'] ?? null);
            $title = trim((string) ($_POST['title'] ?? ''));
            $slug = trim((string) ($_POST['slug'] ?? ''));
            $content = trim((string) ($_POST['content'] ?? ''));
            $isEdit = $pageId > 0;
            $currentPage = null;

            if ($isEdit) {
                $currentPage = findPage($pdo, $pageId);

                if ($currentPage === null) {
                    setFlash('error', 'صفحه پیدا نشد.');
                    redirect(url('admin/pages.php'));
                }

                $slug = (string) $currentPage['slug'];
            }

            if (
                $title === ''
                || pageStringLength($title) > 255
                || !isValidPageSlug($slug)
                || $content === ''
            ) {
                setFlash('error', 'لطفاً عنوان، نامک و محتوای معتبر وارد کنید.');
                redirect($isEdit ? url('admin/pages.php?edit=' . $pageId) : url('admin/pages.php'));
            }

            if (pageSlugExists($pdo, $slug, $pageId)) {
                setFlash('error', 'این نامک صفحه قبلاً استفاده شده است.');
                redirect($isEdit ? url('admin/pages.php?edit=' . $pageId) : url('admin/pages.php'));
            }

            if ($isEdit) {
                $statement = $pdo->prepare(
                    'UPDATE pages SET title = :title, content = :content WHERE id = :id'
                );
                $statement->execute([
                    ':title' => $title,
                    ':content' => $content,
                    ':id' => $pageId,
                ]);

                recordAudit('page.update', 'page', (int) $pageId);
                setFlash('success', 'صفحه با موفقیت بهروزرسانی شد.');
                redirect(url('admin/pages.php'));
            }

            $statement = $pdo->prepare(
                'INSERT INTO pages (slug, title, content) VALUES (:slug, :title, :content)'
            );
            $statement->execute([
                ':slug' => $slug,
                ':title' => $title,
                ':content' => $content,
            ]);

            recordAudit('page.create', 'page', (int) $pdo->lastInsertId());
            setFlash('success', 'صفحه با موفقیت ایجاد شد.');
            redirect(url('admin/pages.php'));
        }

        if ($action === 'delete_page') {
            $pageId = parsePageId($_POST['page_id'] ?? null);
            $page = $pageId > 0 ? findPage($pdo, $pageId) : null;

            if ($page === null) {
                setFlash('error', 'صفحه پیدا نشد.');
                redirect(url('admin/pages.php'));
            }

            $statement = $pdo->prepare('DELETE FROM pages WHERE id = :id');
            $statement->execute([':id' => $pageId]);

            recordAudit('page.delete', 'page', (int) $pageId);
            setFlash('success', 'صفحه با موفقیت حذف شد.');
            redirect(url('admin/pages.php'));
        }

        setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        redirect(url('admin/pages.php'));
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        setFlash('error', 'صفحه ذخیره نشد. لطفاً فرم را بررسی کرده و دوباره تلاش کنید.');
        redirect(url('admin/pages.php'));
    }
}

$editId = parsePageId($_GET['edit'] ?? null);
$editPage = $editId > 0 ? findPage($pdo, $editId) : null;

$perPage = 20;
$pagination = paginate(countPages($pdo), currentPageNumber(), $perPage);
$pages = getAllPages($pdo, $pagination['perPage'], $pagination['offset']);
$successMessage = getFlash('success');
$errorMessage = getFlash('error');

$pageTitle = 'مدیریت صفحات | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <h1>&#128196; صفحات</h1>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status">&#9989; <?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger" role="alert">&#10060; <?= e($errorMessage) ?></div>
    <?php endif; ?>

    <div class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title" id="page-form-title">&#10010; <?= $editPage ? 'ویرایش صفحه' : 'افزودن صفحه' ?></h2>
        </div>
        <form method="post" action="<?= e(url('admin/pages.php')) ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_page">
            <?php if ($editPage): ?>
                <input type="hidden" name="page_id" value="<?= e($editPage['id']) ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="title" class="form-label">عنوان صفحه</label>
                <input type="text" id="title" name="title" class="form-control"
                    maxlength="255"
                    placeholder="عنوان صفحه را وارد کنید..."
                    value="<?= e($editPage['title'] ?? '') ?>"
                    required>
            </div>

            <div class="form-group">
                <label for="slug" class="form-label">نامک (آدرس صفحه)</label>
                <?php if ($editPage): ?>
                    <input type="text" id="slug" class="form-control" value="<?= e($editPage['slug']) ?>" disabled>
                    <small style="color:var(--muted);font-size:0.85rem;">نامک بعد از ایجاد صفحه قابل تغییر نیست.</small>
                <?php else: ?>
                    <input type="text" id="slug" name="slug" class="form-control"
                        maxlength="100"
                        pattern="[a-z0-9-]+"
                        placeholder="مثال: about-us"
                        required>
                    <small style="color:var(--muted);font-size:0.85rem;">از حروف کوچک انگلیسی، اعداد و خط تیره استفاده کنید.</small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="content" class="form-label">محتوای صفحه</label>
                <textarea id="content" name="content" class="form-control" rows="10"
                    placeholder="محتوای صفحه را اینجا بنویسید..."
                    required><?= e($editPage['content'] ?? '') ?></textarea>
                <small style="color:var(--muted);font-size:0.85rem;">محتوا به صورت متن ساده با خطوط جدید نمایش داده می‌شود.</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    &#128190; <?= $editPage ? 'بهروزرسانی صفحه' : 'افزودن صفحه' ?>
                </button>
                <?php if ($editPage): ?>
                    <a href="<?= e(url('admin/pages.php')) ?>" class="btn btn-outline">&#10006; لغو ویرایش</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title">&#128196; همه صفحات</h2>
        </div>

        <?php if ($pages === []): ?>
            <div class="empty-state empty-state-sm">
                <div class="empty-state-icon">&#128196;</div>
                <h3>هنوز صفحه‌ای اضافه نشده</h3>
                <p>از فرم بالا اولین صفحه خود را اضافه کنید.</p>
            </div>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>عنوان</th>
                            <th>نامک</th>
                            <th>تاریخ</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pages as $page): ?>
                            <tr>
                                <td style="font-weight:600;"><?= e($page['title']) ?></td>
                                <td><code style="background:var(--bg-lavender);padding:3px 8px;border-radius:6px;font-size:0.85rem;"><?= e($page['slug']) ?></code></td>
                                <td><?= e(formatAdminPageDate($page['created_at'])) ?></td>
                                <td>
                                    <a href="<?= e(url('page.php?slug=' . $page['slug'])) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">&#128065; مشاهده</a>
                                    <a href="<?= e(url('admin/pages.php?edit=' . $page['id'])) ?>" class="btn btn-sm btn-secondary">&#9998; ویرایش</a>
                                    <form method="post" action="<?= e(url('admin/pages.php')) ?>" class="form-inline" onsubmit="return confirm('آیا این صفحه حذف شود؟');">
                                        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_page">
                                        <input type="hidden" name="page_id" value="<?= e($page['id']) ?>">
                                        <button type="submit" class="btn-reset">&#128465; حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pagination['total'] > $pagination['perPage']): ?>
                <p class="pagination-summary">
                    نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> صفحه
                </p>
                <?= renderPagination($pagination, url('admin/pages.php')) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
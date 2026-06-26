<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/error_handler.php';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

function isValidPublicPageSlug(string $slug): bool
{
    return preg_match('/\A[a-z0-9-]+\z/', $slug) === 1 && strlen($slug) <= 100;
}

$slug = trim((string) ($_GET['slug'] ?? ''));
$page = null;
$notFound = false;

try {
    initializeCmsTables();

    if ($slug === '' || !isValidPublicPageSlug($slug)) {
        $notFound = true;
    } else {
        $pdo = getDb();
        $statement = $pdo->prepare(
            'SELECT id, slug, title, content, created_at FROM pages WHERE slug = :slug LIMIT 1'
        );
        $statement->execute([':slug' => $slug]);
        $page = $statement->fetch() ?: null;
        $notFound = $page === null;
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $notFound = true;
}

if ($notFound) {
    http_response_code(404);
}

$pageTitle = $page ? $page['title'] . ' | ' . siteName() : 'صفحه یافت نشد | ' . siteName();
require_once __DIR__ . '/templates/header.php';
?>

<section class="section cms-page">
    <div class="container">
        <?php if ($notFound): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🔍</div>
                <h1>صفحه یافت نشد</h1>
                <p>صفحه‌ای که جستجو کردید در سیستم موجود نیست.</p>
                <a href="<?= e(url('index.php')) ?>" class="btn btn-primary">بازگشت به خانه</a>
            </div>
        <?php else: ?>
            <div class="breadcrumb">
                <a href="<?= e(url('index.php')) ?>">خانه</a>
                <span class="breadcrumb-sep">‹</span>
                <span class="breadcrumb-current"><?= e($page['title']) ?></span>
            </div>

            <article class="cms-content">
                <header class="cms-content-header">
                    <h1><?= e($page['title']) ?></h1>
                </header>
                <div class="cms-content-body">
                    <?= nl2br(e($page['content'])) ?>
                </div>
            </article>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/templates/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/error_handler.php';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

function parsePublicNewsId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    return is_int($id) ? $id : 0;
}

function formatPublicNewsDate(string $date): string
{
    return shamsiDate($date);
}

function publicNewsExcerpt(string $content, int $limit = 180): string
{
    $plainText = trim(strip_tags($content));

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($plainText, 'UTF-8') > $limit
            ? mb_substr($plainText, 0, $limit, 'UTF-8') . '...'
            : $plainText;
    }

    return strlen($plainText) > $limit ? substr($plainText, 0, $limit) . '...' : $plainText;
}

$singleNewsItem = null;
$newsItems = [];
$notFound = false;
$hasId = array_key_exists('id', $_GET);
$newsId = $hasId ? parsePublicNewsId($_GET['id']) : 0;

try {
    initializeCmsTables();
    $pdo = getDb();

    if ($hasId) {
        if ($newsId === 0) {
            $notFound = true;
        } else {
            $statement = $pdo->prepare(
                'SELECT id, title, content, image, created_at FROM news WHERE id = :id LIMIT 1'
            );
            $statement->execute([':id' => $newsId]);
            $singleNewsItem = $statement->fetch() ?: null;
            $notFound = $singleNewsItem === null;
        }
    } else {
        $statement = $pdo->prepare(
            'SELECT id, title, content, image, created_at FROM news ORDER BY created_at DESC'
        );
        $statement->execute();
        $newsItems = $statement->fetchAll();
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $notFound = $hasId;
    $newsItems = [];
}

if ($notFound) {
    http_response_code(404);
}

$pageTitle = $singleNewsItem ? $singleNewsItem['title'] . ' | ' . siteName() : 'اخبار | ' . siteName();
require_once __DIR__ . '/templates/header.php';
?>

<?php if ($notFound): ?>
    <section class="section page-404">
        <div class="container">
            <div class="empty-state">
                <div class="empty-state-icon">📭</div>
                <h1>خبری یافت نشد</h1>
                <p>خبری که جستجو کردید در سیستم موجود نیست.</p>
                <a href="<?= e(url('news.php')) ?>" class="btn btn-primary">بازگشت به اخبار</a>
            </div>
        </div>
    </section>
<?php elseif ($singleNewsItem): ?>
    <section class="section news-detail">
        <div class="container">
            <div class="breadcrumb">
                <a href="<?= e(url('index.php')) ?>">خانه</a>
                <span class="breadcrumb-sep">‹</span>
                <a href="<?= e(url('news.php')) ?>">اخبار</a>
                <span class="breadcrumb-sep">‹</span>
                <span class="breadcrumb-current"><?= e($singleNewsItem['title']) ?></span>
            </div>

            <article class="news-article">
                <header class="news-article-header">
                    <h1><?= e($singleNewsItem['title']) ?></h1>
                    <time class="news-article-date" datetime="<?= e($singleNewsItem['created_at']) ?>">
                        <span>📅</span> <?= e(formatPublicNewsDate($singleNewsItem['created_at'])) ?>
                    </time>
                </header>

                <?php if (!empty($singleNewsItem['image'])): ?>
                    <div class="news-article-image">
                        <img src="<?= e($singleNewsItem['image']) ?>" alt="<?= e($singleNewsItem['title']) ?>">
                    </div>
                <?php endif; ?>

                <div class="news-article-body">
                    <?= nl2br(e($singleNewsItem['content'])) ?>
                </div>
            </article>

            <div class="news-detail-back">
                <a href="<?= e(url('news.php')) ?>">← بازگشت به اخبار</a>
            </div>
        </div>
    </section>
<?php else: ?>
    <section class="section news-list-section">
        <div class="container">
            <div class="section-header">
                <span class="section-icon">📰</span>
                <h2>اخبار <?= e(siteName()) ?></h2>
                <p class="section-subtitle">آخرین اخبار و رویدادهای مهد کودک</p>
            </div>

            <?php if ($newsItems === []): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <h3>هنوز خبری منتشر نشده است</h3>
                    <p>به زودی اخبار جدید منتشر خواهد شد.</p>
                </div>
            <?php else: ?>
                <div class="news-list-grid">
                    <?php foreach ($newsItems as $newsItem): ?>
                        <article class="news-list-card fade-in">
                            <?php if (!empty($newsItem['image'])): ?>
                                <a href="<?= e(url('news.php?id=' . $newsItem['id'])) ?>" class="news-list-image">
                                    <img src="<?= e($newsItem['image']) ?>" alt="<?= e($newsItem['title']) ?>" loading="lazy">
                                </a>
                            <?php endif; ?>
                            <div class="news-list-content">
                                <time class="news-list-date" datetime="<?= e($newsItem['created_at']) ?>">
                                    <?= e(formatPublicNewsDate($newsItem['created_at'])) ?>
                                </time>
                                <h3><a href="<?= e(url('news.php?id=' . $newsItem['id'])) ?>"><?= e($newsItem['title']) ?></a></h3>
                                <p><?= e(publicNewsExcerpt($newsItem['content'])) ?></p>
                                <a href="<?= e(url('news.php?id=' . $newsItem['id'])) ?>" class="news-read-more">ادامه مطلب <span>←</span></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/templates/footer.php'; ?>

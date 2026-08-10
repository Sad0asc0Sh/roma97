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
$relatedNews = [];
$newsItems = [];
$notFound = false;
$hasId = array_key_exists('id', $_GET);
$newsId = $hasId ? parsePublicNewsId($_GET['id']) : 0;
$catFilter = trim((string) ($_GET['cat'] ?? ''));

try {
    initializeCmsTables();
    $pdo = getDb();

    if ($hasId) {
        if ($newsId === 0) {
            $notFound = true;
        } else {
            $statement = $pdo->prepare(
                'SELECT id, title, content, image, category, created_at FROM news WHERE id = :id LIMIT 1'
            );
            $statement->execute([':id' => $newsId]);
            $singleNewsItem = $statement->fetch() ?: null;
            $notFound = $singleNewsItem === null;

            if ($singleNewsItem) {
                $relStmt = $pdo->prepare(
                    'SELECT id, title, content, image, created_at FROM news WHERE id != :id ORDER BY created_at DESC LIMIT 3'
                );
                $relStmt->execute([':id' => $newsId]);
                $relatedNews = $relStmt->fetchAll();
            }
        }
    } else {
        if ($catFilter !== '') {
            $statement = $pdo->prepare(
                'SELECT id, title, content, image, category, created_at FROM news WHERE category = :cat ORDER BY created_at DESC'
            );
            $statement->execute([':cat' => $catFilter]);
        } else {
            $statement = $pdo->prepare(
                'SELECT id, title, content, image, category, created_at FROM news ORDER BY created_at DESC'
            );
            $statement->execute();
        }
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
        <div class="container" style="text-align: center; padding: var(--space-2xl) 0;">
            <div class="empty-state">
                <div class="empty-state-icon" style="width: 100px; height: 100px; margin: 0 auto var(--space-md) auto;">
                    <svg viewBox="0 0 100 100" style="width:100%; height:100%;"><use href="<?= asset('assets/img/bargoo.svg#bargoo-surprised') ?>"/></svg>
                </div>
                <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); margin-bottom: var(--space-xs);">خبری یافت نشد</h1>
                <p style="color: var(--muted); margin-bottom: var(--space-lg);">خبری که به دنبال آن بودید یافت نشد یا حذف شده است.</p>
                <a href="<?= e(url('news.php')) ?>" class="btn btn-primary">بازگشت به لیست اخبار</a>
            </div>
        </div>
    </section>
<?php elseif ($singleNewsItem): ?>
    <section class="section news-detail" style="padding: var(--space-2xl) 0;">
        <div class="container" style="max-width: 860px;">
            <div class="breadcrumb" style="margin-bottom: var(--space-lg);">
                <a href="<?= e(url('index.php')) ?>">خانه</a>
                <span class="breadcrumb-sep">‹</span>
                <a href="<?= e(url('news.php')) ?>">اخبار</a>
                <span class="breadcrumb-sep">‹</span>
                <span class="breadcrumb-current"><?= e($singleNewsItem['title']) ?></span>
            </div>

            <article class="news-article" style="background: var(--white); padding: var(--space-2xl); border-radius: var(--radius-xl); border: 1px solid var(--border); box-shadow: var(--shadow-sm); margin-bottom: var(--space-2xl);">
                <header class="news-article-header" style="margin-bottom: var(--space-lg); border-bottom: 1px solid var(--border); padding-bottom: var(--space-md);">
                    <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); color: var(--neutral-dark); margin-bottom: var(--space-sm);"><?= e($singleNewsItem['title']) ?></h1>

                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-sm);">
                        <time class="news-article-date" datetime="<?= e($singleNewsItem['created_at']) ?>" style="color: var(--muted); font-size: var(--text-sm);">
                            <svg width="14" height="14" aria-hidden="true" style="vertical-align:middle;margin-inline-end:4px;"><use href="<?= e(asset('assets/img/icons.svg#icon-calendar')) ?>"/></svg> <?= e(formatPublicNewsDate($singleNewsItem['created_at'])) ?>
                        </time>

                        <!-- Share Buttons -->
                        <div class="news-share-buttons" style="display: flex; gap: 8px; align-items: center;">
                            <span style="font-size: var(--text-xs); color: var(--muted); font-weight: 600;">اشتراک‌گذاری:</span>
                            <a href="https://t.me/share/url?url=<?= urlencode(url('news.php?id=' . $singleNewsItem['id'])) ?>&text=<?= urlencode($singleNewsItem['title']) ?>" target="_blank" rel="noopener" class="btn btn-outline" style="padding: 4px 10px; font-size: var(--text-xs);" title="تلگرام">
                                تلگرام
                            </a>
                            <a href="https://api.whatsapp.com/send?text=<?= urlencode($singleNewsItem['title'] . ' ' . url('news.php?id=' . $singleNewsItem['id'])) ?>" target="_blank" rel="noopener" class="btn btn-outline" style="padding: 4px 10px; font-size: var(--text-xs); color: #25D366; border-color: #25D366;" title="واتس‌اپ">
                                واتس‌اپ
                            </a>
                        </div>
                    </div>
                </header>

                <?php if (!empty($singleNewsItem['image'])): ?>
                    <div class="news-article-image" style="margin-bottom: var(--space-lg); border-radius: var(--radius-lg); overflow: hidden;">
                        <img src="<?= e(url($singleNewsItem['image'])) ?>" alt="<?= e($singleNewsItem['title']) ?>" style="width: 100%; max-height: 480px; object-fit: cover;">
                    </div>
                <?php endif; ?>

                <div class="news-article-body" style="font-size: var(--text-base); line-height: 1.9; color: var(--neutral-dark);">
                    <?= nl2br(e($singleNewsItem['content'])) ?>
                </div>
            </article>

            <!-- Related News Section -->
            <?php if (!empty($relatedNews)): ?>
                <div class="related-news-section" style="margin-bottom: var(--space-2xl);">
                    <h3 style="font-family: var(--font-display); font-size: var(--text-xl); color: var(--neutral-dark); margin-bottom: var(--space-md);">
                        اخبار و اطلاعیه‌های مرتبط
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: var(--space-md);">
                        <?php foreach ($relatedNews as $rel): ?>
                            <div style="background: var(--white); border-radius: var(--radius-md); padding: var(--space-md); border: 1px solid var(--border);">
                                <h4 style="font-size: var(--text-base); margin-bottom: 6px;">
                                    <a href="<?= e(url('news.php?id=' . $rel['id'])) ?>" style="color: var(--neutral-dark); text-decoration: none;"><?= e($rel['title']) ?></a>
                                </h4>
                                <span style="font-size: var(--text-xs); color: var(--muted);"><?= e(formatPublicNewsDate($rel['created_at'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="news-detail-back" style="text-align: center;">
                <a href="<?= e(url('news.php')) ?>" class="btn btn-outline">← بازگشت به لیست اخبار</a>
            </div>
        </div>
    </section>
<?php else: ?>
    <section class="section news-list-section" style="padding: var(--space-2xl) 0;">
        <div class="container">
            <div class="section-header text-center" style="margin-bottom: var(--space-lg);">
                <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); color: var(--neutral-dark); margin-bottom: var(--space-xs);">
                    اخبار و رویدادهای <?= e(siteName()) ?>
                </h1>
                <p class="section-subtitle" style="color: var(--muted);">آخرین خبرها، اطلاعیه‌ها و مقالات آموزشی مهدکودک</p>
            </div>

            <!-- Category Filter Pills -->
            <div class="category-filters" style="display: flex; gap: var(--space-xs); justify-content: center; flex-wrap: wrap; margin-bottom: var(--space-xl);">
                <a href="<?= e(url('news.php')) ?>" class="btn <?= $catFilter === '' ? 'btn-primary' : 'btn-outline' ?> btn-sm">همه اخبار</a>
                <a href="<?= e(url('news.php?cat=announcement')) ?>" class="btn <?= $catFilter === 'announcement' ? 'btn-primary' : 'btn-outline' ?> btn-sm">اطلاعیه‌ها</a>
                <a href="<?= e(url('news.php?cat=event')) ?>" class="btn <?= $catFilter === 'event' ? 'btn-primary' : 'btn-outline' ?> btn-sm">رویدادها</a>
                <a href="<?= e(url('news.php?cat=educational')) ?>" class="btn <?= $catFilter === 'educational' ? 'btn-primary' : 'btn-outline' ?> btn-sm">مقالات آموزشی</a>
            </div>

            <?php if ($newsItems === []): ?>
                <div class="empty-state" style="text-align: center; padding: var(--space-2xl) 0;">
                    <div class="empty-state-icon" style="width: 90px; height: 90px; margin: 0 auto var(--space-md) auto;">
                        <svg viewBox="0 0 100 100" style="width:100%; height:100%;"><use href="<?= asset('assets/img/bargoo.svg#bargoo-sleeping') ?>"/></svg>
                    </div>
                    <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); color: var(--neutral-dark);">هنوز خبری منتشر نشده است</h3>
                    <p style="color: var(--muted);">به زودی اخبار جدید روما در این بخش منتشر خواهد شد.</p>
                </div>
            <?php else: ?>
                <div class="news-list-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--space-lg);">
                    <?php foreach ($newsItems as $newsItem): ?>
                        <article class="news-list-card fade-in" style="background: var(--white); border-radius: var(--radius-lg); border: 1px solid var(--border); overflow: hidden; box-shadow: var(--shadow-sm); display: flex; flex-direction: column;">
                            <?php if (!empty($newsItem['image'])): ?>
                                <a href="<?= e(url('news.php?id=' . $newsItem['id'])) ?>" class="news-list-image" style="display: block; height: 200px; overflow: hidden;">
                                    <img src="<?= e(url($newsItem['image'])) ?>" alt="<?= e($newsItem['title']) ?>" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; transition: transform var(--transition-base);">
                                </a>
                            <?php endif; ?>
                            <div class="news-list-content" style="padding: var(--space-md); flex: 1; display: flex; flex-direction: column;">
                                <time class="news-list-date" datetime="<?= e($newsItem['created_at']) ?>" style="font-size: var(--text-xs); color: var(--muted); margin-bottom: 6px;">
                                    <svg width="14" height="14" aria-hidden="true" style="vertical-align:middle;margin-inline-end:4px;"><use href="<?= e(asset('assets/img/icons.svg#icon-calendar')) ?>"/></svg> <?= e(formatPublicNewsDate($newsItem['created_at'])) ?>
                                </time>
                                <h3 style="font-size: var(--text-lg); margin-bottom: var(--space-xs); line-height: 1.5;">
                                    <a href="<?= e(url('news.php?id=' . $newsItem['id'])) ?>" style="color: var(--neutral-dark); text-decoration: none;"><?= e($newsItem['title']) ?></a>
                                </h3>
                                <p style="font-size: var(--text-sm); color: var(--neutral-medium); margin-bottom: var(--space-md); flex: 1; line-height: 1.7;"><?= e(publicNewsExcerpt($newsItem['content'])) ?></p>
                                <a href="<?= e(url('news.php?id=' . $newsItem['id'])) ?>" class="news-read-more" style="font-size: var(--text-sm); font-weight: 700; color: var(--primary); text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                    ادامه مطلب ←
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/templates/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/error_handler.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

/**
 * Validate an incoming news id.
 */
function parsePublicNewsId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return is_int($id) ? $id : 0;
}

/**
 * Plain-text excerpt for a news teaser.
 */
function publicNewsExcerpt(string $content, int $limit = 165): string
{
    $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($content)) ?? '');

    if (function_exists('mb_strlen')) {
        return mb_strlen($plain, 'UTF-8') > $limit
            ? rtrim(mb_substr($plain, 0, $limit, 'UTF-8')) . '…'
            : $plain;
    }

    return strlen($plain) > $limit ? rtrim(substr($plain, 0, $limit)) . '…' : $plain;
}

/**
 * Estimated reading time in Persian, based on ~200 words per minute.
 */
function publicNewsReadingTime(string $content): string
{
    $plain = trim(strip_tags($content));
    $words = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $minutes = max(1, (int) ceil(count($words) / 200));

    return persianNumber((string) $minutes) . ' دقیقه مطالعه';
}

$singleNewsItem = null;
$relatedNews    = [];
$newsItems      = [];
$newsPagination = [];
$availableCats  = [];
$notFound       = false;

$hasId     = array_key_exists('id', $_GET);
$newsId    = $hasId ? parsePublicNewsId($_GET['id']) : 0;
$catFilter = trim((string) ($_GET['cat'] ?? ''));

try {
    $pdo = getDb();

    // Which categories actually exist? Drives the filter chips, so a chip can
    // never point at an empty result set.
    $catStmt = $pdo->query(
        "SELECT category, COUNT(*) AS total
         FROM news
         WHERE category IS NOT NULL AND category <> ''
         GROUP BY category
         ORDER BY total DESC"
    );
    $availableCats = $catStmt->fetchAll();

    $knownCats = array_column($availableCats, 'category');
    if ($catFilter !== '' && !in_array($catFilter, $knownCats, true)) {
        $catFilter = '';
    }

    if ($hasId) {
        if ($newsId === 0) {
            $notFound = true;
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, title, content, image, category, created_at FROM news WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $newsId]);
            $singleNewsItem = $stmt->fetch() ?: null;
            $notFound = $singleNewsItem === null;

            if ($singleNewsItem !== null) {
                // Prefer same-category articles, then fall back to recency.
                $relStmt = $pdo->prepare(
                    'SELECT id, title, image, category, created_at
                     FROM news
                     WHERE id <> :id
                     ORDER BY (category = :cat) DESC, created_at DESC
                     LIMIT 3'
                );
                $relStmt->execute([
                    ':id'  => $newsId,
                    ':cat' => (string) ($singleNewsItem['category'] ?? ''),
                ]);
                $relatedNews = $relStmt->fetchAll();
            }
        }
    } else {
        $newsPage = max(1, (int) ($_GET['p'] ?? 1));
        $perPage  = 9;

        if ($catFilter !== '') {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM news WHERE category = :cat');
            $countStmt->execute([':cat' => $catFilter]);
        } else {
            $countStmt = $pdo->query('SELECT COUNT(*) FROM news');
        }
        $totalNews      = (int) $countStmt->fetchColumn();
        $newsPagination = paginate($totalNews, $newsPage, $perPage);

        $sql = 'SELECT id, title, content, image, category, created_at FROM news'
            . ($catFilter !== '' ? ' WHERE category = :cat' : '')
            . ' ORDER BY created_at DESC LIMIT :lim OFFSET :off';
        $stmt = $pdo->prepare($sql);
        if ($catFilter !== '') {
            $stmt->bindValue(':cat', $catFilter, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $newsPagination['offset'], PDO::PARAM_INT);
        $stmt->execute();
        $newsItems = $stmt->fetchAll();
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $notFound  = $hasId;
    $newsItems = [];
}

if ($notFound) {
    http_response_code(404);
}

$siteNameV = siteName();

if ($singleNewsItem !== null) {
    $pageTitle = (string) $singleNewsItem['title'] . ' | ' . $siteNameV;
    $pageDescription = publicNewsExcerpt((string) $singleNewsItem['content'], 155);
} elseif ($notFound) {
    $pageTitle = 'خبر یافت نشد | ' . $siteNameV;
} else {
    $pageTitle = 'اخبار و رویدادها | ' . $siteNameV;
    $pageDescription = 'آخرین اخبار، اطلاعیه‌ها، جشن‌ها و مقالات تربیتی مهدکودک ' . $siteNameV . '.';
}

require_once __DIR__ . '/templates/header.php';
?>

<?php if ($notFound): ?>

    <section class="section">
        <div class="container">
            <div class="empty">
                <?php echo bargooSvg('surprised'); ?>
                <h1 class="empty__title">این خبر پیدا نشد</h1>
                <p class="empty__text">
                    خبری که به دنبالش بودید یافت نشد یا حذف شده است.
                </p>
                <a class="btn btn-primary" href="<?php echo e(url('news.php')); ?>">
                    <?php echo icon('arrow-left', 17); ?>
                    بازگشت به همه اخبار
                </a>
            </div>
        </div>
    </section>

<?php elseif ($singleNewsItem !== null): ?>

    <?php
    $articleUrl   = absoluteUrl('news.php?id=' . (int) $singleNewsItem['id']);
    $articleTitle = (string) $singleNewsItem['title'];
    $articleCat   = (string) ($singleNewsItem['category'] ?? '');
    ?>
    <section class="section">
        <div class="container">
            <article class="article">

                <nav class="crumbs crumbs--page" aria-label="مسیر صفحه">
                    <a href="<?php echo e(url('index.php')); ?>">خانه</a>
                    <span class="crumbs__sep" aria-hidden="true">‹</span>
                    <a href="<?php echo e(url('news.php')); ?>">اخبار</a>
                    <span class="crumbs__sep" aria-hidden="true">‹</span>
                    <span class="crumbs__current" aria-current="page"><?php echo e($articleTitle); ?></span>
                </nav>

                <header class="article__head">
                    <span class="badge <?php echo e(newsCategoryBadge($articleCat)); ?>">
                        <?php echo e(newsCategoryLabel($articleCat)); ?>
                    </span>
                    <h1 class="article__title"><?php echo e($articleTitle); ?></h1>
                    <div class="article__meta">
                        <time class="article__meta-item" datetime="<?php echo e((string) $singleNewsItem['created_at']); ?>">
                            <?php echo icon('calendar', 14); ?>
                            <?php echo e(shamsiDate((string) $singleNewsItem['created_at'])); ?>
                        </time>
                        <span class="article__meta-item">
                            <?php echo icon('clock', 14); ?>
                            <?php echo e(publicNewsReadingTime((string) $singleNewsItem['content'])); ?>
                        </span>
                    </div>
                </header>

                <?php if (!empty($singleNewsItem['image'])): ?>
                    <figure class="article__media">
                        <img src="<?php echo e(url((string) $singleNewsItem['image'])); ?>"
                             alt="<?php echo e($articleTitle); ?>"
                             loading="eager" fetchpriority="high" decoding="async">
                    </figure>
                <?php endif; ?>

                <div class="article__body">
                    <?php echo nl2br(e((string) $singleNewsItem['content'])); ?>
                </div>

                <div class="share">
                    <span class="share__label">اشتراک‌گذاری:</span>
                    <a class="share__btn"
                       href="https://t.me/share/url?url=<?php echo urlencode($articleUrl); ?>&amp;text=<?php echo urlencode($articleTitle); ?>"
                       target="_blank" rel="noopener noreferrer" aria-label="اشتراک در تلگرام">
                        <?php echo brandIcon('telegram', 17); ?>
                    </a>
                    <a class="share__btn"
                       href="https://api.whatsapp.com/send?text=<?php echo urlencode($articleTitle . ' ' . $articleUrl); ?>"
                       target="_blank" rel="noopener noreferrer" aria-label="اشتراک در واتس‌اپ">
                        <?php echo brandIcon('whatsapp', 17); ?>
                    </a>
                    <button type="button" class="share__btn"
                            data-share="native"
                            data-share-url="<?php echo e($articleUrl); ?>"
                            data-share-title="<?php echo e($articleTitle); ?>"
                            aria-label="کپی لینک / اشتراک‌گذاری">
                        <?php echo icon('share', 17); ?>
                    </button>
                </div>
            </article>
        </div>
    </section>

    <?php if ($relatedNews !== []): ?>
    <section class="section section-paper">
        <div class="container">
            <div class="section-head" data-reveal="up">
                <span class="eyebrow">
                    <?php echo icon('news', 14); ?>
                    ادامه بخوانید
                </span>
                <h2 class="section-head__title">
                    مطالب <span class="u-mark">مرتبط</span>
                </h2>
            </div>

            <div class="news-grid" data-reveal-group data-reveal-step="90">
                <?php foreach ($relatedNews as $rel): ?>
                    <?php $relUrl = url('news.php?id=' . (int) $rel['id']); ?>
                    <article class="card card-hover news-card" data-reveal="up">
                        <a class="news-card__media" href="<?php echo e($relUrl); ?>" aria-hidden="true" tabindex="-1">
                            <img src="<?php echo e(url((string) ($rel['image'] ?: 'assets/img/about-story.jpg'))); ?>"
                                 alt="<?php echo e((string) $rel['title']); ?>"
                                 loading="lazy" decoding="async">
                            <span class="news-card__cat">
                                <span class="badge <?php echo e(newsCategoryBadge((string) ($rel['category'] ?? ''))); ?>">
                                    <?php echo e(newsCategoryLabel((string) ($rel['category'] ?? ''))); ?>
                                </span>
                            </span>
                        </a>
                        <div class="news-card__body">
                            <span class="news-card__date">
                                <?php echo icon('calendar', 14); ?>
                                <?php echo e(shamsiDate((string) $rel['created_at'])); ?>
                            </span>
                            <h3 class="news-card__title">
                                <a href="<?php echo e($relUrl); ?>"><?php echo e((string) $rel['title']); ?></a>
                            </h3>
                            <span class="news-card__more">
                                ادامه مطلب
                                <?php echo icon('arrow-left', 16); ?>
                            </span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="text-center mt-xl">
                <a class="btn btn-ghost btn-lg" href="<?php echo e(url('news.php')); ?>">
                    مشاهده همه اخبار
                    <?php echo icon('arrow-left', 19); ?>
                </a>
            </div>
        </div>
    </section>
    <?php endif; ?>

<?php else: ?>

    <?php /* ── Listing ── */ ?>
    <section class="page-hero">
        <div class="page-hero__scrim"></div>
        <div class="container page-hero__inner">
            <nav class="crumbs" aria-label="مسیر صفحه">
                <a href="<?php echo e(url('index.php')); ?>">خانه</a>
                <span class="crumbs__sep" aria-hidden="true">‹</span>
                <span class="crumbs__current" aria-current="page">اخبار</span>
            </nav>
            <h1 class="page-hero__title">اخبار و رویدادهای <?php echo e($siteNameV); ?></h1>
            <p class="page-hero__sub">
                جشن‌ها، اردوها، کارگاه‌های والدین و اطلاعیه‌های مهم مجموعه.
            </p>
        </div>
    </section>

    <section class="section">
        <div class="container">

            <?php if ($availableCats !== []): ?>
            <div class="gallery-filters" role="group" aria-label="فیلتر دسته‌بندی اخبار">
                <a class="chip <?php echo $catFilter === '' ? 'is-active' : ''; ?>"
                   href="<?php echo e(url('news.php')); ?>">همه اخبار</a>
                <?php foreach ($availableCats as $cat): ?>
                    <?php $slug = (string) $cat['category']; ?>
                    <a class="chip <?php echo $catFilter === $slug ? 'is-active' : ''; ?>"
                       href="<?php echo e(url('news.php?cat=' . urlencode($slug))); ?>">
                        <?php echo e(newsCategoryLabel($slug)); ?>
                        <?php echo e('(' . persianNumber((string) $cat['total']) . ')'); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($newsItems === []): ?>
                <div class="empty">
                    <?php echo bargooSvg('sleeping'); ?>
                    <h2 class="empty__title">هنوز خبری در این بخش نیست</h2>
                    <p class="empty__text">
                        به‌زودی تازه‌های روما را همین‌جا منتشر می‌کنیم.
                    </p>
                    <?php if ($catFilter !== ''): ?>
                        <a class="btn btn-primary" href="<?php echo e(url('news.php')); ?>">مشاهده همه اخبار</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="news-grid" data-reveal-group data-reveal-step="80">
                    <?php foreach ($newsItems as $newsItem): ?>
                        <?php
                        $itemUrl = url('news.php?id=' . (int) $newsItem['id']);
                        $itemCat = (string) ($newsItem['category'] ?? '');
                        ?>
                        <article class="card card-hover news-card" data-reveal="up">
                            <a class="news-card__media" href="<?php echo e($itemUrl); ?>" aria-hidden="true" tabindex="-1">
                                <img src="<?php echo e(url((string) ($newsItem['image'] ?: 'assets/img/about-story.jpg'))); ?>"
                                     alt="<?php echo e((string) $newsItem['title']); ?>"
                                     loading="lazy" decoding="async">
                                <span class="news-card__cat">
                                    <span class="badge <?php echo e(newsCategoryBadge($itemCat)); ?>">
                                        <?php echo e(newsCategoryLabel($itemCat)); ?>
                                    </span>
                                </span>
                            </a>
                            <div class="news-card__body">
                                <span class="news-card__date">
                                    <?php echo icon('calendar', 14); ?>
                                    <?php echo e(shamsiDate((string) $newsItem['created_at'])); ?>
                                </span>
                                <h2 class="news-card__title">
                                    <a href="<?php echo e($itemUrl); ?>"><?php echo e((string) $newsItem['title']); ?></a>
                                </h2>
                                <p class="news-card__excerpt">
                                    <?php echo e(publicNewsExcerpt((string) $newsItem['content'])); ?>
                                </p>
                                <span class="news-card__more">
                                    ادامه مطلب
                                    <?php echo icon('arrow-left', 16); ?>
                                </span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($newsPagination) && (int) $newsPagination['totalPages'] > 1): ?>
                    <?php echo renderPagination(
                        $newsPagination,
                        url('news.php'),
                        $catFilter !== '' ? ['cat' => $catFilter] : []
                    ); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

<?php endif; ?>

<?php require __DIR__ . '/templates/cta-section.php'; ?>

<?php require_once __DIR__ . '/templates/footer.php'; ?>

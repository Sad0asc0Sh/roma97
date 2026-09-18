<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/error_handler.php';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/db.php';

function isValidPublicPageSlug(string $slug): bool
{
    return preg_match('/\A[a-z0-9-]+\z/', $slug) === 1 && strlen($slug) <= 100;
}

$slug = trim((string) ($_GET['slug'] ?? ''));

// Custom Dedicated Page Templates Router
$customTemplates = [
    'about' => 'templates/pages/about.php',
    'classes' => 'templates/pages/classes.php',
    'contact' => 'templates/pages/contact.php'
];

// Contact form POST handler (page.php?slug=contact).
// Persists the submission; the template reads $contactFormStatus to show feedback.
$contactFormStatus = null;

if (($_GET['slug'] ?? '') === 'contact' && isPostRequest()) {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $name  = trim((string) ($_POST['name'] ?? ''));
    $phone = preg_replace('/[\s-]/', '', (string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $subject = (string) ($_POST['subject'] ?? 'other');
    $message = trim((string) ($_POST['message'] ?? ''));

    $allowedSubjects = ['visit', 'register', 'consult', 'other'];

    if (!checkBruteForce('contact_form')) {
        $contactFormStatus = ['ok' => false, 'text' => 'تعداد درخواست‌های ارسالی زیاد است. لطفاً کمی بعد دوباره تلاش کنید.'];
    } elseif (!validateCsrfToken($csrfToken)) {
        $contactFormStatus = ['ok' => false, 'text' => 'درخواست نامعتبر است. لطفاً صفحه را دوباره بارگذاری کنید.'];
    } elseif ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
        $contactFormStatus = ['ok' => false, 'text' => 'لطفاً نام و نام خانوادگی را وارد کنید.'];
    } elseif (!preg_match('/^\+?[0-9]{8,15}\z/', (string) $phone)) {
        $contactFormStatus = ['ok' => false, 'text' => 'شماره تماس معتبر وارد کنید.'];
    } elseif ($email !== '' && (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $contactFormStatus = ['ok' => false, 'text' => 'آدرس ایمیل معتبر وارد کنید.'];
    } elseif (!in_array($subject, $allowedSubjects, true)) {
        $contactFormStatus = ['ok' => false, 'text' => 'موضوع انتخابی نامعتبر است.'];
    } elseif ($message === '' || mb_strlen($message, 'UTF-8') > 5000) {
        $contactFormStatus = ['ok' => false, 'text' => 'متن پیام الزامی است (حداکثر ۵۰۰۰ نویسه).'];
    } else {
        try {
            $pdoContact = getDb();
            ensureContactMessagesTable($pdoContact);
            $insStmt = $pdoContact->prepare(
                'INSERT INTO contact_messages (name, phone, email, subject, message, ip_address)
                 VALUES (:name, :phone, :email, :subject, :message, :ip)'
            );
            $insStmt->execute([
                ':name' => $name,
                ':phone' => substr((string) $phone, 0, 20),
                ':email' => $email === '' ? null : $email,
                ':subject' => $subject,
                ':message' => $message,
                ':ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]);
            recordAudit('contact.message', 'contact_message', (int) $pdoContact->lastInsertId());
            $contactFormStatus = ['ok' => true, 'text' => 'پیام شما با موفقیت ثبت شد. کارشناسان روما به زودی با شما تماس خواهند گرفت.'];
            // Prevent duplicate submit on refresh.
            header('Location: ' . url('page.php?slug=contact&sent=1'));
            exit;
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $contactFormStatus = ['ok' => false, 'text' => 'ارسال پیام با مشکل مواجه شد. لطفاً بعداً تلاش کنید.'];
        }
    }
} elseif (($_GET['slug'] ?? '') === 'contact' && ($_GET['sent'] ?? '') === '1') {
    $contactFormStatus = ['ok' => true, 'text' => 'پیام شما با موفقیت ثبت شد. کارشناسان روما به زودی با شما تماس خواهند گرفت.'];
}

if (isset($customTemplates[$slug]) && is_file(__DIR__ . '/' . $customTemplates[$slug])) {
    $customPageTitles = [
        'about' => 'درباره ما | ' . siteName(),
        'classes' => 'کلاس‌ها و برنامه‌های آموزشی | ' . siteName(),
        'contact' => 'تماس با ما و بازدید حضوری | ' . siteName()
    ];
    $pageTitle = $customPageTitles[$slug] ?? siteName();
    require_once __DIR__ . '/templates/header.php';
    require_once __DIR__ . '/' . $customTemplates[$slug];
    require_once __DIR__ . '/templates/footer.php';
    exit;
}

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

<section class="section">
    <div class="container">
        <?php if ($notFound): ?>
            <div class="empty">
                <?= bargooSvg('surprised') ?>
                <h1 class="empty__title">این صفحه پیدا نشد</h1>
                <p class="empty__text">
                    صفحه‌ای که به دنبالش بودید وجود ندارد یا حذف شده است.
                </p>
                <div class="row" style="justify-content:center">
                    <a class="btn btn-primary" href="<?= e(url('index.php')) ?>">
                        <?= icon('home', 17) ?>
                        بازگشت به صفحه اصلی
                    </a>
                    <a class="btn btn-ghost" href="<?= e(url('page.php?slug=contact')) ?>">تماس با ما</a>
                </div>
            </div>
        <?php else: ?>
            <nav class="crumbs crumbs--page" aria-label="مسیر صفحه">
                <a href="<?= e(url('index.php')) ?>">خانه</a>
                <span class="crumbs__sep" aria-hidden="true">‹</span>
                <span class="crumbs__current" aria-current="page"><?= e($page['title']) ?></span>
            </nav>

            <article class="article">
                <header class="article__head">
                    <h1 class="article__title"><?= e($page['title']) ?></h1>
                </header>
                <div class="prose">
                    <?= nl2br(e($page['content'])) ?>
                </div>
            </article>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/templates/footer.php'; ?>

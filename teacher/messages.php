<?php
declare(strict_types=1);

/**
 * ROMA — Teacher Supportive Parent Communication Center
 * (مرکز ارتباط هم‌دلانه و روان‌شناختی با والدین کلاس بر پایه ارتباط بدون خشونت NVC)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/teacher_portal_helpers.php';

// Access gate FIRST: an unauthenticated request must never reach
// table initialisation or any other database work (the pre-refactor
// page already had this order; keep it).
requireTeacherLogin();

initializeMessagingTable();
initializeNotificationTables();

$teacherId   = (int) ($_SESSION['teacher_id'] ?? 0);
$pdo         = getDb();
$error       = getFlash('error') ?? '';
$success     = getFlash('success') ?? '';

$ctx         = teacherResolveClassroomContext($pdo, $teacherId);
$classrooms  = $ctx['classrooms'];
$classroom   = $ctx['active'];
$classroomId = $ctx['active_id'];

$preselectedRecipient = filter_var($_GET['recipient_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$preselectedRecipient = is_int($preselectedRecipient) ? (string) $preselectedRecipient : 'classroom';

if (isPostRequest() && isset($_POST['send_message'])) {
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'توکن امنیتی نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif ($classroom === null) {
        $error = 'برای ارسال پیام، ابتدا باید کلاسی به شما اختصاص داده شده باشد.';
    } else {
        $recipientRaw = (string) ($_POST['recipient_id'] ?? '');
        $isBroadcast  = ($recipientRaw === 'classroom');
        $recipientId  = $isBroadcast ? null : (int) $recipientRaw;
        $subject      = trim((string) ($_POST['subject'] ?? ''));
        $body         = trim((string) ($_POST['body'] ?? ''));

        if ($subject === '' || $body === '') {
            $error = 'موضوع و متن پیام الزامی است.';
        } else {
            try {
                $recipientIds = [];

                if ($isBroadcast) {
                    $classParentsStmt = $pdo->prepare('
                        SELECT DISTINCT p.id
                        FROM parents p
                        INNER JOIN children c ON p.id = c.parent_id
                        INNER JOIN child_classroom cc ON c.id = cc.child_id
                        INNER JOIN classrooms cl ON cc.classroom_id = cl.id
                        WHERE cl.teacher_id = ? AND c.status = \'active\'
                        ORDER BY p.id
                    ');
                    $classParentsStmt->execute([$teacherId]);

                    while (($pid = $classParentsStmt->fetchColumn()) !== false) {
                        $recipientIds[] = (int) $pid;
                    }

                    if ($recipientIds === []) {
                        throw new RuntimeException('هنوز والدی در کلاس شما ثبت‌نام نکرده است.');
                    }
                } else {
                    $verifyStmt = $pdo->prepare('
                        SELECT COUNT(*)
                        FROM child_classroom cc
                        INNER JOIN children c ON cc.child_id = c.id
                        INNER JOIN classrooms cl ON cc.classroom_id = cl.id
                        WHERE cl.teacher_id = ? AND c.parent_id = ?
                    ');
                    $verifyStmt->execute([$teacherId, $recipientId]);
                    if ((int) $verifyStmt->fetchColumn() === 0) {
                        throw new RuntimeException('دریافت‌کننده انتخاب‌شده معتبر نیست یا در کلاس شما قرار ندارد.');
                    }

                    $recipientIds[] = (int) $recipientId;
                }

                $batchToken    = bin2hex(random_bytes(8));
                $notifyResults = [];

                foreach ($recipientIds as $targetParentId) {
                    $notifyResults[] = notifyParent($targetParentId, 'announcement', [
                        'args'           => [parentDisplayNameForMessage($pdo, $targetParentId), $subject],
                        'portal_subject' => $subject,
                        'portal_body'    => $body,
                        'dedup_key'      => "announcement:{$batchToken}:{$targetParentId}",
                        'sender_type'    => 'teacher',
                        'sender_id'      => $teacherId,
                    ]);
                }

                $sentCount = count($recipientIds);

                recordAudit('message.send', 'message', null, [
                    'broadcast'  => $isBroadcast,
                    'recipients' => $sentCount,
                ]);

                smsQueueFlushInline($pdo);

                $success = $isBroadcast
                    ? 'پیام با موفقیت به ' . persianNumber((string) $sentCount) . ' والد کلاس ارسال شد.'
                    : 'پیام با موفقیت به والد ارسال شد.';

                $summary = summarizeNotificationResults($notifyResults);
                if ($summary !== '') {
                    $success .= ' ' . $summary;
                }
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            } catch (Throwable $e) {
                error_log('teacher/messages send failed: ' . $e->getMessage());
                $error = 'ارسال پیام ناموفق بود. لطفاً دوباره تلاش کنید.';
            }
        }
    }
}

$parentsStmt = $pdo->prepare('
    SELECT
        p.id,
        p.first_name,
        p.last_name,
        p.phone,
        p.email,
        GROUP_CONCAT(CONCAT(c.first_name, \' \', c.last_name) SEPARATOR \'، \') AS children_names
    FROM parents p
    INNER JOIN children c ON p.id = c.parent_id
    INNER JOIN child_classroom cc ON c.id = cc.child_id
    INNER JOIN classrooms cl ON cc.classroom_id = cl.id
    WHERE cl.teacher_id = ? AND c.status = \'active\'
    GROUP BY p.id, p.first_name, p.last_name, p.phone, p.email
    ORDER BY p.last_name, p.first_name
');
$parentsStmt->execute([$teacherId]);
$parents = $parentsStmt->fetchAll();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_type = 'teacher' AND sender_id = ?");
$countStmt->execute([$teacherId]);
$pagination = paginate((int) $countStmt->fetchColumn(), currentPageNumber(), 15);

$messagesStmt = $pdo->prepare('
    SELECT m.*, p.first_name, p.last_name
    FROM messages m
    LEFT JOIN parents p ON m.parent_id = p.id
    WHERE m.sender_type = \'teacher\' AND m.sender_id = ?
    ORDER BY m.created_at DESC
    LIMIT ' . $pagination['perPage'] . ' OFFSET ' . $pagination['offset'] . '
');
$messagesStmt->execute([$teacherId]);
$sentMessages = $messagesStmt->fetchAll();

$viewMessage = null;
if (isset($_GET['view'])) {
    $viewId = (int) $_GET['view'];
    $viewStmt = $pdo->prepare('
        SELECT m.*, p.first_name, p.last_name
        FROM messages m
        LEFT JOIN parents p ON m.parent_id = p.id
        WHERE m.id = ? AND m.sender_type = \'teacher\' AND m.sender_id = ?
        LIMIT 1
    ');
    $viewStmt->execute([$viewId, $teacherId]);
    $viewMessage = $viewStmt->fetch() ?: null;
}

$messageTemplates = [
    [
        'title'   => '🤗 هم‌سویی در دلبستگی و جدایی صبحگاهی',
        'subject' => 'همراهی صمیمانه برای آرامش بیشتر در جدایی صبحگاهی',
        'body'    => "والد گرامی سلام و وقت بخیر 🌱\nامروز فرزند دلبندتان هنگام ورود به کلاس کمی دلتنگ شما بود که با حضور آرام، پذیرش احساسات و درگیر شدن در بازی مورد علاقه‌اش، خیلی زود به آرامش و شادابی رسید.\nپیشنهاد روان‌شناختی برای خانه: یک آیین خداحافظی کوتاه، ثابت و پیش‌بینی‌پذیر (مثلاً یک آغوش و گفتن جمله «بعد از ناهار دنبالت می‌آیم») حس امنیت عاطفی کودک را در لحظه جدایی بسیار تقویت می‌کند.",
    ],
    [
        'title'   => '🌟 بازخورد توانمندی و خودکارآمدی کودک',
        'subject' => 'گزارش پیشرفت مهارتی و حس خودکارآمدی در کلاس',
        'body'    => "والد گرامی سلام 🌸\nامروز شاهد تلاش، تمرکز و استقلال بسیار زیبای فرزند عزیزتان در فعالیت‌های کلاسی و مشارکت همدلانه با دوستانش بودیم.\nپیشنهاد هم‌افزایی خانه و مهد: تحسین توصیفی تلاش کودک در خانه (توجه به فرآیند تلاش به‌جای نتیجه) انگیزه درونی و اعتمادبه‌نفس او را پایدار می‌سازد.",
    ],
    [
        'title'   => '🌱 هماهنگی در تنظیم هیجان و مدیریت ناکامی',
        'subject' => 'هم‌افزایی خانه و مهد در تمرین مهارت ابراز کلامی هیجان‌ها',
        'body'    => "والد گرامی سلام و احترام 🌿\nدر این سن، کودکان در حال یادگیری جایگزین کردن واکنش‌های هیجانی با کلمات هستند. امروز در کلاس با فرزند عزیزتان تمرین کردیم که هنگام خستگی یا نوبت‌گیری، احساس خود را با جملات ساده بیان کند و پاسخ بسیار خوبی دریافت کردیم.\nهمراهی شما در نام‌گذاری احساسات در خانه («می‌بینم الان خسته یا ناراحتی، بیا با هم درباره‌اش حرف بزنیم») به تثبیت این مهارت کمک شایانی می‌کند.",
    ],
    [
        'title'   => '🍎 رویکرد تغذیه خودمختار و بدون فشار',
        'subject' => 'گزارش وضعیت تغذیه و احترام به حس سیری و گرسنگی کودک',
        'body'    => "والد گرامی سلام 🍎\nامروز فرزند دلبندتان در محیطی آرام و شاد در کنار همسالان، بخشی از وعده غذایی خود را با انتخاب آزادانه میل کرد. در رویکرد تربیتی مهد، ما بدون هیچ‌گونه اصرار یا فشار روانی، تجربه‌ای مثبت از غذا خوردن برای کودک فراهم می‌کنیم تا رابطه سالم او با تغذیه حفظ شود.",
    ],
    [
        'title'   => '🎒 آمادگی ذهنی و پیش‌آگهی برای برنامه فردا',
        'subject' => 'پیش‌آگهی و آمادگی کودک برای فعالیت ویژه فردا',
        'body'    => "والد گرامی سلام و وقت بخیر 🌈\nاز آنجا که پیش‌بینی‌پذیر بودن محیط باعث کاهش اضطراب و افزایش اشتیاق کودکان می‌شود، لطفاً امشب در گفتگویی کوتاه و شاد، فرزند دلبندتان را برای کارگاه فردای کلاس آماده فرمایید و یک دست لباس راحت همراه او قرار دهید.",
    ],
];

$pageTitle = 'ارتباط روان‌شناختی با والدین | پنل مربیان | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<div class="tp-page-header">
    <div>
        <h1>💬 مرکز ارتباط هم‌دلانه و روان‌شناختی با والدین</h1>
        <p class="tp-page-subtitle">
            بر پایه الگوی ارتباط بدون برچسب: ۱. شروع با توانمندی کودک ← ۲. مشاهده عینی ← ۳. اقدام حمایتی در مهد ← ۴. پیشنهاد هم‌سویی برای خانه
        </p>
    </div>
    <div class="tp-page-actions">
        <a href="<?= e(url('teacher/index.php')) ?>" class="tp-btn tp-btn-secondary tp-btn-sm">← بازگشت به داشبورد</a>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success !== ''): ?>
    <div class="notice" role="status"><?= e($success) ?></div>
<?php endif; ?>

<?php if ($classroom === null): ?>
    <div class="tp-empty-state">
        <h2>ارسال پیام نیازمند کلاس فعال است</h2>
        <p>شما تنها به والدین کودکانی که در کلاس شما ثبت‌نام شده‌اند می‌توانید پیام ارسال کنید. لطفاً منتظر اختصاص کلاس توسط مدیریت بمانید.</p>
    </div>
<?php else: ?>

    <div class="tp-messages-grid">
        <!-- Compose Card -->
        <section class="tp-card">
            <div class="tp-card-header">
                <h2>✉️ نگارش پیام تربیتی و هم‌افزایی با خانواده</h2>
                <span class="tp-badge tp-badge-present"><?= e(persianNumber((string) count($parents))) ?> خانواده در کلاس</span>
            </div>

            <!-- Psychological Message Templates -->
            <div style="margin-bottom:16px;">
                <span style="font-size:0.78rem;font-weight:700;color:var(--tp-text-secondary);display:block;margin-bottom:6px;">
                    🌱 قالب‌های استاندارد روان‌شناسی ارتباط با والدین (کلیک برای درج):
                </span>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <?php foreach ($messageTemplates as $tpl): ?>
                        <button type="button" class="tp-template-chip"
                                onclick="fillMessageTemplate(<?= e(json_encode($tpl['subject'], JSON_UNESCAPED_UNICODE)) ?>, <?= e(json_encode($tpl['body'], JSON_UNESCAPED_UNICODE)) ?>)">
                            <?= e($tpl['title']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <form method="post" action="<?= e(url('teacher/messages.php')) ?>" class="message-form">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">

                <div class="tp-form-group">
                    <label for="recipient_id" class="tp-label">دریافت‌کننده</label>
                    <select name="recipient_id" id="recipient_id" class="tp-select" required>
                        <option value="classroom" <?= $preselectedRecipient === 'classroom' ? 'selected' : '' ?>>
                            📢 همه والدین کلاس من (<?= e(persianNumber((string) count($parents))) ?> خانواده)
                        </option>
                        <?php foreach ($parents as $parent): ?>
                            <?php $pidStr = (string) $parent['id']; ?>
                            <option value="<?= e($pidStr) ?>" <?= $preselectedRecipient === $pidStr ? 'selected' : '' ?>>
                                👤 <?= e($parent['first_name'] . ' ' . $parent['last_name']) ?>
                                — والدِ <?= e((string) ($parent['children_names'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="tp-form-group">
                    <label for="subject" class="tp-label">موضوع پیام</label>
                    <input type="text" name="subject" id="subject" class="tp-input" maxlength="255" placeholder="مثال: هم‌افزایی خانه و مهد در تقویت مهارت‌های ارتباطی" required>
                </div>

                <div class="tp-form-group">
                    <label for="body" class="tp-label">متن کامل پیام (با رویکرد توصیفی، محترمانه و بدون برچسب‌زنی)</label>
                    <textarea name="body" id="body" class="tp-textarea" rows="7" placeholder="۱. اشاره به نقطه قوت کودک...&#10;۲. توصیف عینی مشاهده امروز...&#10;۳. پیشنهاد آرامش‌بخش برای همراهی در خانه..." required></textarea>
                </div>

                <button type="submit" name="send_message" class="tp-btn tp-btn-primary" style="width:100%;">
                    🚀 ارسال پیام هم‌دلانه به والدین
                </button>
            </form>
        </section>

        <!-- Sent Messages History -->
        <section class="tp-card">
            <div class="tp-card-header">
                <h2>📤 آرشیو پیام‌های ارسالی شما</h2>
                <span class="tp-badge tp-badge-excused"><?= e(persianNumber((string) $pagination['total'])) ?> پیام</span>
            </div>

            <?php if ($sentMessages === []): ?>
                <div class="tp-empty-state" style="padding:32px 20px;margin:0;">
                    <h3>هنوز پیامی ارسال نکرده‌اید</h3>
                    <p style="margin-bottom:0;">پیام‌هایی که برای والدین کلاس ارسال کنید در این فهرست ذخیره و نمایش داده می‌شوند.</p>
                </div>
            <?php else: ?>
                <div class="tp-msg-list">
                    <?php foreach ($sentMessages as $msg): ?>
                        <?php
                        $recipientName = !empty($msg['parent_id'])
                            ? trim((string) $msg['first_name'] . ' ' . (string) $msg['last_name'])
                            : 'همه والدین کلاس';
                        ?>
                        <article class="tp-msg-item">
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                                    <span class="tp-badge <?= !empty($msg['parent_id']) ? 'tp-badge-excused' : 'tp-badge-present' ?>">
                                        گیرنده: <?= e($recipientName) ?>
                                    </span>
                                    <span style="font-size:0.75rem;color:var(--tp-text-muted);">
                                        <?= e(shamsiDate((string) $msg['created_at'], 'with_time')) ?>
                                    </span>
                                </div>
                                <h3 style="margin:0 0 4px;font-size:0.98rem;"><?= e((string) $msg['subject']) ?></h3>
                                <p style="margin:0;font-size:0.82rem;color:var(--tp-text-secondary);">
                                    <?= e(mb_substr((string) $msg['body'], 0, 110, 'UTF-8')) ?><?= mb_strlen((string) $msg['body'], 'UTF-8') > 110 ? '…' : '' ?>
                                </p>
                            </div>
                            <a href="<?= e(url('teacher/messages.php?view=' . (int) $msg['id'] . ($pagination['page'] > 1 ? '&p=' . $pagination['page'] : ''))) ?>"
                               class="tp-btn tp-btn-secondary tp-btn-sm">
                                مشاهده
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($pagination['total'] > $pagination['perPage']): ?>
                    <p class="pagination-summary">
                        نمایش <?= e(persianNumber($pagination['from'])) ?> تا <?= e(persianNumber($pagination['to'])) ?> از <?= e(persianNumber($pagination['total'])) ?> پیام
                    </p>
                    <?= renderPagination($pagination, url('teacher/messages.php')) ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <script>
    function fillMessageTemplate(subject, body) {
        var subEl = document.getElementById('subject');
        var bodyEl = document.getElementById('body');
        if (subEl) subEl.value = subject;
        if (bodyEl) {
            bodyEl.value = body;
            bodyEl.focus();
        }
    }
    </script>

<?php endif; ?>

<?php if ($viewMessage): ?>
    <div class="modal-overlay" id="msgDetailModal" onclick="if(event.target===this)window.location.href='<?= e(url('teacher/messages.php')) ?>'">
        <div class="modal-content tp-card" style="max-width:560px;width:100%;margin:0;">
            <div class="tp-card-header">
                <h3>جزئیات پیام ارسالی</h3>
                <a href="<?= e(url('teacher/messages.php')) ?>" class="tp-btn tp-btn-secondary tp-btn-sm" aria-label="بستن">✕</a>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:0.88rem;margin-bottom:16px;">
                <div><strong>گیرنده:</strong> <?= $viewMessage['parent_id'] ? e($viewMessage['first_name'] . ' ' . $viewMessage['last_name']) : 'همه والدین کلاس' ?></div>
                <div><strong>تاریخ ارسال:</strong> <?= e(shamsiDate((string) $viewMessage['created_at'], 'with_day_time')) ?></div>
                <div><strong>موضوع:</strong> <?= e((string) $viewMessage['subject']) ?></div>
            </div>
            <div style="background:var(--tp-surface-soft);border:1px solid var(--tp-border-light);border-radius:var(--tp-radius-sm);padding:16px;line-height:2;font-size:0.92rem;margin-bottom:18px;">
                <?= nl2br(e((string) $viewMessage['body'])) ?>
            </div>
            <div style="display:flex;justify-content:flex-end;">
                <a href="<?= e(url('teacher/messages.php')) ?>" class="tp-btn tp-btn-secondary">بستن پنجره</a>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            window.location.href = <?= json_encode(url('teacher/messages.php')) ?>;
        }
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>

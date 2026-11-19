<?php
declare(strict_types=1);

/**
 * ROMA — SMS Center (مرکز پیامک)
 * ------------------------------------------------------------------
 * The single operational cockpit for everything the notification engine
 * does. Before this page existed the SMS state of the platform was
 * scattered across `admin/settings.php` (patterns), the database
 * (`sms_logs`), and nowhere at all (the queue), which meant nobody could
 * answer basic questions like "did the parents actually get today's
 * reports?" or "why is registration failing?".
 *
 * This page answers them:
 *   • Gateway health    — an honest ready / not-ready verdict with the
 *                         exact blocking reason and a fix link.
 *   • Delivery KPIs     — queued, failed, sent today, 30-day volume.
 *   • Queue management  — retry a single message, retry all failures,
 *                         cancel, or drain the queue on demand.
 *   • Event coverage    — which of the 9 catalog events are live, which
 *                         are silently disabled because a pattern id is
 *                         missing, and who each one reaches.
 *   • Full delivery log — paginated, filterable by status and event.
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

requireLogin();

initializeNotificationTables();

$pdo = getDb();

// ─────────────────────────────────────────────────────────────────────────────
// Actions (POST → redirect → GET, so a refresh never re-fires a send)
// ─────────────────────────────────────────────────────────────────────────────

/** Preserve the current filters across the redirect. */
function smsCenterReturnUrl(): string
{
    $query = [];
    foreach (['status', 'event', 'p'] as $key) {
        $value = trim((string) ($_POST['return_' . $key] ?? $_GET[$key] ?? ''));
        if ($value !== '') {
            $query[$key] = $value;
        }
    }

    $base = url('admin/sms-center.php');

    return $query === [] ? $base : $base . '?' . http_build_query($query);
}

if (isPostRequest()) {
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        setFlash('error', 'توکن امنیتی نامعتبر است. لطفاً دوباره تلاش کنید.');
        redirect(smsCenterReturnUrl());
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'retry_one') {
            $queueId = (int) ($_POST['queue_id'] ?? 0);

            // Reset to pending so the normal worker path picks it up, then try
            // it immediately so the admin gets instant feedback.
            $reset = $pdo->prepare(
                "UPDATE sms_queue
                 SET status = 'pending', attempts = 0, last_error = NULL, updated_at = NOW()
                 WHERE id = :id AND status IN ('failed', 'cancelled', 'sending')"
            );
            $reset->execute([':id' => $queueId]);

            if ($reset->rowCount() === 0) {
                setFlash('error', 'این پیامک قابل ارسال مجدد نیست (یا قبلاً ارسال شده است).');
            } else {
                $res = smsQueueProcess($pdo, 1, 15, $queueId);
                recordAudit('sms.queue_retry', 'sms_queue', $queueId);

                if ($res['sent'] > 0) {
                    setFlash('success', 'پیامک با موفقیت ارسال شد.');
                } else {
                    setFlash('error', 'ارسال مجدد ناموفق بود. جزئیات خطا در جدول صف قابل مشاهده است.');
                }
            }
        } elseif ($action === 'cancel_one') {
            $queueId = (int) ($_POST['queue_id'] ?? 0);

            $cancel = $pdo->prepare(
                "UPDATE sms_queue
                 SET status = 'cancelled', updated_at = NOW()
                 WHERE id = :id AND status IN ('pending', 'failed', 'sending')"
            );
            $cancel->execute([':id' => $queueId]);

            recordAudit('sms.queue_cancel', 'sms_queue', $queueId);
            setFlash(
                $cancel->rowCount() > 0 ? 'success' : 'error',
                $cancel->rowCount() > 0 ? 'پیامک از صف ارسال حذف شد.' : 'این پیامک قابل لغو نیست.'
            );
        } elseif ($action === 'retry_all_failed') {
            $reset = $pdo->exec(
                "UPDATE sms_queue
                 SET status = 'pending', attempts = 0, last_error = NULL, updated_at = NOW()
                 WHERE status = 'failed'"
            );
            $count = (int) $reset;

            recordAudit('sms.queue_retry_all', 'sms_queue', null, ['count' => $count]);

            if ($count === 0) {
                setFlash('success', 'هیچ پیامک ناموفقی برای ارسال مجدد وجود ندارد.');
            } else {
                $res = smsQueueProcess($pdo, 50, 15);
                setFlash(
                    'success',
                    persianNumber((string) $count) . ' پیامک به صف بازگشت؛ '
                    . persianNumber((string) $res['sent']) . ' مورد در همین لحظه ارسال شد. '
                    . ($res['remaining'] > 0
                        ? 'باقی‌مانده صف: ' . persianNumber((string) $res['remaining']) . ' مورد (توسط کرون ارسال می‌شود).'
                        : '')
                );
            }
        } elseif ($action === 'flush_queue') {
            $res = smsQueueProcess($pdo, 100, 20);
            recordAudit('sms.queue_flush', 'sms_queue', null, $res);

            setFlash(
                'success',
                'پردازش صف انجام شد — ارسال‌شده: ' . persianNumber((string) $res['sent'])
                . ' · ناموفق: ' . persianNumber((string) $res['failed'])
                . ' · باقی‌مانده: ' . persianNumber((string) $res['remaining'])
            );
        } elseif ($action === 'check_sms_connection') {
            $currentSettings = getSmsSettings($pdo);
            $keyToCheck = melipayamakConsoleKey($currentSettings, 'shared');

            if ($keyToCheck === '') {
                setFlash('error', 'کلید وب‌سرویس ملی‌پیامک وارد نشده است. لطفاً ابتدا در صفحه تنظیمات کلید را ذخیره کنید.');
            } else {
                $connRes = checkMelipayamakConnection($keyToCheck);
                if ($connRes['success']) {
                    setFlash('success', '✅ ' . $connRes['message']);
                } else {
                    setFlash('error', '❌ خطا در اتصال به ملی‌پیامک: ' . $connRes['message']);
                }
            }
        } elseif ($action === 'test_otp_fast') {
            $testPhone = trim((string) ($_POST['test_phone'] ?? ''));
            if (!isValidIranianMobile($testPhone)) {
                setFlash('error', 'شماره موبایل گیرنده تست نامعتبر است.');
            } else {
                $res = sendMeliPayamakOtp($testPhone, 'test_otp');
                if ($res['success']) {
                    setFlash('success', "⚡ کد تایید یکبارمصرف (OTP) از طریق مسیر پرسرعت ارسال شد (کد ارسالی: {$res['code']}). معمولاً ظرف چند ثانیه دریافت می‌شود.");
                } else {
                    setFlash('error', "خطا در ارسال سریع OTP: " . $res['status']);
                }
            }
        } elseif ($action === 'send_test') {
            $testPhone = trim((string) ($_POST['test_phone'] ?? ''));
            $eventKey  = trim((string) ($_POST['test_event'] ?? ''));
            $event     = notificationEvent($eventKey);

            if (!isValidIranianMobile($testPhone)) {
                setFlash('error', 'شماره موبایل گیرنده نامعتبر است (نمونه صحیح: ۰۹۱۲۳۴۵۶۷۸۹).');
            } elseif ($event === null) {
                setFlash('error', 'رویداد انتخاب‌شده معتبر نیست.');
            } else {
                $settings = getSmsSettings($pdo);
                $bodyId = (int) ($settings[$event['pattern_key']] ?? 0);

                if ($bodyId <= 0) {
                    setFlash('error', 'الگوی این رویداد هنوز در تنظیمات ثبت نشده است.');
                } else {
                    // Fill each template variable with its own Persian label so the
                    // admin can see exactly which slot maps to which value.
                    $args = [];
                    foreach ($event['args'] as $index => $label) {
                        $posted = trim((string) ($_POST['test_arg_' . $index] ?? ''));
                        $args[] = $posted !== '' ? $posted : $label;
                    }

                    $res = sendMeliPayamakPattern($testPhone, $bodyId, $args, 'test_' . $eventKey);

                    if ($res['success']) {
                        $msg = 'پیامک تست ارسال شد. کد پیگیری: ' . persianNumber((string) ($res['recId'] ?? '-'));
                        if (($settings['sms_enabled'] ?? '0') !== '1') {
                            $msg .= ' (توجه: ارسال پیامک سیستمی هنوز خاموش است؛ پس از اطمینان، آن را در صفحه تنظیمات فعال کنید).';
                        }
                        setFlash('success', $msg);
                    } else {
                        setFlash('error', 'ارسال پیامک تست ناموفق بود: ' . $res['status']);
                    }

                    recordAudit('sms.test_send', 'sms', null, ['event' => $eventKey, 'success' => $res['success']]);
                }
            }
        } else {
            setFlash('error', 'درخواست نامعتبر است.');
        }
    } catch (Throwable $exception) {
        error_log('sms-center action failed: ' . $exception->getMessage());
        setFlash('error', 'انجام عملیات ممکن نشد. لطفاً دوباره تلاش کنید.');
    }

    redirect(smsCenterReturnUrl());
}

// ─────────────────────────────────────────────────────────────────────────────
// Read model
// ─────────────────────────────────────────────────────────────────────────────

$gateway  = smsGatewayStatus($pdo);
$stats    = smsQueueStats($pdo);
$catalog  = notificationCatalog();
$settings = getSmsSettings($pdo);

$statusFilter = (string) ($_GET['status'] ?? '');
$eventFilter  = (string) ($_GET['event'] ?? '');

$allowedStatuses = ['sent', 'failed'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$queueRows      = [];
$logRows        = [];
$logPagination  = paginate(0, 1, 25);
$eventVolume    = [];
$errorMessage   = '';

try {
    // Live queue: everything still in flight or parked after 3 failures.
    $queueRows = $pdo->query(
        "SELECT q.id, q.recipient_phone, q.pattern_body_id, q.event_type, q.status,
                q.attempts, q.priority, q.last_error, q.created_at,
                p.first_name AS p_first, p.last_name AS p_last
         FROM sms_queue q
         LEFT JOIN parents p ON p.id = q.parent_id
         WHERE q.status IN ('pending', 'sending', 'failed')
         ORDER BY FIELD(q.status, 'failed', 'sending', 'pending'), q.priority ASC, q.id ASC
         LIMIT 60"
    )->fetchAll();

    // 30-day volume per event, so the admin can see where the credit goes.
    $volumeStmt = $pdo->query(
        "SELECT message_type,
                SUM(status = 'sent')   AS sent,
                SUM(status <> 'sent')  AS failed
         FROM sms_logs
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY message_type"
    );
    while ($row = $volumeStmt->fetch()) {
        $eventVolume[(string) $row['message_type']] = [
            'sent'   => (int) $row['sent'],
            'failed' => (int) $row['failed'],
        ];
    }

    // Delivery log with filters.
    $where = [];
    $params = [];

    if ($statusFilter === 'sent') {
        $where[] = "l.status = 'sent'";
    } elseif ($statusFilter === 'failed') {
        $where[] = "l.status <> 'sent'";
    }

    if ($eventFilter !== '') {
        $where[] = 'l.message_type = :etype';
        $params[':etype'] = $eventFilter;
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM sms_logs l' . $whereSql);
    $countStmt->execute($params);
    $logPagination = paginate((int) $countStmt->fetchColumn(), currentPageNumber(), 25);

    $logStmt = $pdo->prepare(
        'SELECT l.id, l.recipient_phone, l.pattern_body_id, l.rec_id, l.status,
                l.message_type, l.error_message, l.created_at,
                p.first_name AS p_first, p.last_name AS p_last
         FROM sms_logs l
         LEFT JOIN parents p ON p.id = l.parent_id'
        . $whereSql .
        ' ORDER BY l.id DESC
         LIMIT ' . $logPagination['perPage'] . ' OFFSET ' . $logPagination['offset']
    );
    $logStmt->execute($params);
    $logRows = $logStmt->fetchAll();
} catch (Throwable $exception) {
    error_log('sms-center read failed: ' . $exception->getMessage());
    $errorMessage = 'برخی از اطلاعات مرکز پیامک موقتاً در دسترس نیست.';
}

/** Distinct message types present in the log, for the filter bar. */
$logEventTypes = [];
try {
    $logEventTypes = $pdo->query('SELECT DISTINCT message_type FROM sms_logs ORDER BY message_type')
        ->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable) {
    $logEventTypes = [];
}

$filterQuery = array_filter([
    'status' => $statusFilter,
    'event'  => $eventFilter,
]);

$successMessage = getFlash('success');
$flashError     = getFlash('error');

$pageTitle = 'مرکز پیامک | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <div class="adm-page-head">
        <div class="adm-page-head-main">
            <h1>مرکز پیامک و اعلان‌ها</h1>
            <p class="adm-page-subtitle">وضعیت سامانه پیامک، صف ارسال، پوشش رویدادها و گزارش کامل تحویل — همه در یک صفحه</p>
        </div>
        <div class="adm-page-actions">
            <form method="post" action="<?= e(url('admin/sms-center.php')) ?>" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="check_sms_connection">
                <button type="submit" class="btn btn-secondary">استعلام موجودی و اتصال</button>
            </form>
            <a href="<?= e(url('admin/settings.php#sms-section')) ?>" class="btn btn-primary">تنظیمات و الگوها</a>
        </div>
    </div>

    <nav class="adm-section-nav" aria-label="بخش‌های مرکز پیامک">
        <a href="#sms-health" class="adm-section-nav-link is-active">سلامت سامانه</a>
        <a href="#sms-stats" class="adm-section-nav-link">آمار تحویل</a>
        <a href="#sms-queue" class="adm-section-nav-link">صف ارسال زنده</a>
        <a href="#sms-coverage" class="adm-section-nav-link">پوشش رویدادها</a>
        <a href="#sms-test" class="adm-section-nav-link">تست ارسال</a>
        <a href="#sms-log" class="adm-section-nav-link">گزارش تحویل</a>
    </nav>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($flashError !== null): ?>
        <div class="alert alert-error" role="alert"><?= e($flashError) ?></div>
    <?php endif; ?>

    <!-- ── Gateway health ─────────────────────────────────────────── -->
    <div id="sms-health" class="admin-section" style="border-inline-start:5px solid <?= $gateway['ready'] ? 'var(--adm-success)' : ($gateway['critical_issues'] === [] ? 'var(--adm-warning)' : 'var(--adm-danger)') ?>;">
        <div class="admin-section-header">
            <h2 class="admin-section-title m-0">سلامت سامانه پیامک</h2>
            <span class="badge <?= $gateway['ready'] ? 'badge-success' : 'badge-danger' ?>">
                <?= $gateway['ready'] ? 'آماده ارسال' : 'ارسال پیامک انجام نمی‌شود' ?>
            </span>
        </div>

        <div class="adm-kpi-grid">
            <div class="adm-kpi-card <?= $gateway['api_key_set'] ? 'adm-kpi-card--success' : 'adm-kpi-card--danger' ?>">
                <div class="adm-kpi-label">کلید وب‌سرویس</div>
                <div class="adm-kpi-value"><?= $gateway['api_key_set'] ? 'ثبت شده' : 'ثبت نشده' ?></div>
            </div>
            <div class="adm-kpi-card <?= $gateway['enabled'] ? 'adm-kpi-card--success' : 'adm-kpi-card--warning' ?>">
                <div class="adm-kpi-label">ارسال سیستمی</div>
                <div class="adm-kpi-value"><?= $gateway['enabled'] ? 'فعال' : 'غیرفعال' ?></div>
            </div>
            <div class="adm-kpi-card adm-kpi-card--primary">
                <div class="adm-kpi-label">رویدادهای آماده</div>
                <div class="adm-kpi-value">
                    <?= e(persianNumber((string) count($gateway['configured_events']))) ?>
                    <span class="adm-kpi-unit">از <?= e(persianNumber((string) count($catalog))) ?></span>
                </div>
            </div>
            <div class="adm-kpi-card adm-kpi-card--info">
                <div class="adm-kpi-label">زمان ارسال درون‌خطی</div>
                <div class="adm-kpi-value">
                    <?= e(persianNumber((string) smsInlineTimeBudgetSeconds())) ?>
                    <span class="adm-kpi-unit">ثانیه</span>
                </div>
            </div>
        </div>

        <?php foreach ($gateway['critical_issues'] as $issue): ?>
            <div class="adm-callout adm-callout--danger">
                <div class="adm-callout-body"><?= e($issue) ?></div>
            </div>
        <?php endforeach; ?>

        <?php foreach ($gateway['warnings'] as $warning): ?>
            <div class="adm-callout adm-callout--warning">
                <div class="adm-callout-body"><?= e($warning) ?></div>
            </div>
        <?php endforeach; ?>

        <?php if ($gateway['ready'] && $gateway['warnings'] === []): ?>
            <p class="muted m-0">
                همه الگوها تنظیم شده‌اند و سامانه آماده ارسال است. اعلان‌های پورتال والدین مستقل از پیامک همیشه ثبت می‌شوند.
            </p>
        <?php endif; ?>
    </div>

    <!-- ── Delivery KPIs + queue actions ──────────────────────────── -->
    <div id="sms-stats" class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title m-0">آمار تحویل</h2>
            <div class="adm-filter-actions">
                <form method="post" action="<?= e(url('admin/sms-center.php')) ?>" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="flush_queue">
                    <button type="submit" class="btn btn-sm btn-secondary">پردازش صف الان</button>
                </form>
                <?php if ($stats['failed'] > 0): ?>
                    <form method="post" action="<?= e(url('admin/sms-center.php')) ?>" class="inline-form"
                        data-confirm="آیا همه پیامک‌های ناموفق مجدداً ارسال شوند؟">
                        <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                        <input type="hidden" name="action" value="retry_all_failed">
                        <button type="submit" class="btn btn-sm btn-primary">ارسال مجدد همه ناموفق‌ها</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="adm-kpi-grid">
            <div class="adm-kpi-card adm-kpi-card--warning">
                <div class="adm-kpi-label">در صف ارسال</div>
                <div class="adm-kpi-value"><?= e(persianNumber((string) $stats['pending'])) ?></div>
            </div>
            <div class="adm-kpi-card adm-kpi-card--danger">
                <div class="adm-kpi-label">ناموفق (نیاز به بررسی)</div>
                <div class="adm-kpi-value"><?= e(persianNumber((string) $stats['failed'])) ?></div>
            </div>
            <div class="adm-kpi-card adm-kpi-card--success">
                <div class="adm-kpi-label">ارسال‌شده امروز</div>
                <div class="adm-kpi-value"><?= e(persianNumber((string) $stats['sent_today'])) ?></div>
            </div>
            <div class="adm-kpi-card">
                <div class="adm-kpi-label">خطای امروز</div>
                <div class="adm-kpi-value"><?= e(persianNumber((string) $stats['failed_today'])) ?></div>
            </div>
            <div class="adm-kpi-card adm-kpi-card--primary">
                <div class="adm-kpi-label">۳۰ روز گذشته</div>
                <div class="adm-kpi-value"><?= e(persianNumber((string) $stats['sent_month'])) ?></div>
            </div>
        </div>

        <div class="adm-callout adm-callout--info m-0">
            <div class="adm-callout-body">
                برای ارسال کاملاً خودکار صف، این دستور را در Cron Job هاست (هر ۵ دقیقه) قرار دهید:
                <code class="adm-code-chip">php <?= e(dirname(__DIR__)) ?>/bin/process-sms-queue.php</code>
            </div>
        </div>
    </div>

    <!-- ── Live queue ─────────────────────────────────────────────── -->
    <div id="sms-queue" class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title m-0">صف ارسال زنده</h2>
        </div>

        <?php if ($queueRows === []): ?>
            <div class="empty-state empty-state-sm">
                <div class="empty-state-icon">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <h3>صف خالی است — همه پیامک‌ها تحویل داده شده‌اند</h3>
            </div>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>گیرنده</th>
                            <th>والد</th>
                            <th>رویداد</th>
                            <th>وضعیت</th>
                            <th>تلاش</th>
                            <th>خطا</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($queueRows as $row): ?>
                            <?php
                            $qStatus = (string) $row['status'];
                            $statusMeta = match ($qStatus) {
                                'pending' => ['در انتظار', 'badge-warning'],
                                'sending' => ['در حال ارسال', 'badge-info'],
                                'failed'  => ['ناموفق', 'badge-danger'],
                                default   => [$qStatus, 'badge-secondary'],
                            };
                            $eventLabel = (string) ($catalog[(string) $row['event_type']]['label'] ?? $row['event_type']);
                            ?>
                            <tr>
                                <td><?= e(persianNumber((string) $row['id'])) ?></td>
                                <td dir="ltr" style="text-align:right;font-family:monospace;"><?= e((string) $row['recipient_phone']) ?></td>
                                <td>
                                    <?php $pName = trim((string) ($row['p_first'] ?? '') . ' ' . (string) ($row['p_last'] ?? '')); ?>
                                    <?= $pName !== '' ? e($pName) : '<span class="muted">—</span>' ?>
                                </td>
                                <td><?= e($eventLabel) ?></td>
                                <td><span class="badge <?= e($statusMeta[1]) ?>"><?= e($statusMeta[0]) ?></span></td>
                                <td><?= e(persianNumber((string) $row['attempts'])) ?></td>
                                <td class="muted" style="color:var(--adm-danger);max-width:220px;">
                                    <?= $row['last_error'] !== null && $row['last_error'] !== '' ? e((string) $row['last_error']) : '<span class="muted">—</span>' ?>
                                </td>
                                <td>
                                    <div class="adm-row-actions">
                                        <form method="post" action="<?= e(url('admin/sms-center.php')) ?>" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="retry_one">
                                            <input type="hidden" name="queue_id" value="<?= e((string) $row['id']) ?>">
                                            <input type="hidden" name="return_status" value="<?= e($statusFilter) ?>">
                                            <input type="hidden" name="return_event" value="<?= e($eventFilter) ?>">
                                            <button type="submit" class="btn btn-xs btn-secondary">ارسال</button>
                                        </form>
                                        <form method="post" action="<?= e(url('admin/sms-center.php')) ?>" class="inline-form"
                                            data-confirm="آیا این پیامک از صف ارسال حذف شود؟">
                                            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="cancel_one">
                                            <input type="hidden" name="queue_id" value="<?= e((string) $row['id']) ?>">
                                            <input type="hidden" name="return_status" value="<?= e($statusFilter) ?>">
                                            <input type="hidden" name="return_event" value="<?= e($eventFilter) ?>">
                                            <button type="submit" class="btn btn-xs btn-reject">لغو</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Event coverage ─────────────────────────────────────────── -->
    <div id="sms-coverage" class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title m-0">پوشش رویدادهای اعلان</h2>
        </div>
        <p class="muted mb-md">
            هر رویداد بدون «شناسه الگو» به‌صورت خودکار غیرفعال است و والدین آن اعلان را با پیامک دریافت نمی‌کنند (پیام پورتال همچنان ثبت می‌شود).
        </p>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>رویداد</th>
                        <th>دسته</th>
                        <th>شناسه الگو</th>
                        <th>وضعیت</th>
                        <th>قابل انصراف والد</th>
                        <th>ارسال ۳۰ روز</th>
                        <th>متغیرهای الگو</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($catalog as $key => $event): ?>
                        <?php
                        $bodyId = (int) ($settings[$event['pattern_key']] ?? 0);
                        $vol = $eventVolume[$key] ?? ['sent' => 0, 'failed' => 0];
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($event['label']) ?></strong>
                                <div class="muted" style="font-size:0.76rem;margin-top:2px;"><?= e($event['description']) ?></div>
                            </td>
                            <td><?= e($event['group']) ?></td>
                            <td dir="ltr" style="text-align:right;font-family:monospace;">
                                <?= $bodyId > 0 ? e((string) $bodyId) : '<span class="muted">—</span>' ?>
                            </td>
                            <td>
                                <?php if (!$gateway['enabled']): ?>
                                    <span class="badge badge-warning">پیامک غیرفعال</span>
                                <?php elseif ($bodyId > 0): ?>
                                    <span class="badge badge-success">فعال</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">الگو تنظیم نشده</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $event['pref_column'] === null
                                    ? '<span class="muted">خیر (پیام ضروری)</span>'
                                    : 'بله' ?>
                            </td>
                            <td>
                                <span style="color:var(--adm-success);font-weight:700;"><?= e(persianNumber((string) $vol['sent'])) ?></span>
                                <?php if ($vol['failed'] > 0): ?>
                                    <span style="color:var(--adm-danger);"> / <?= e(persianNumber((string) $vol['failed'])) ?> خطا</span>
                                <?php endif; ?>
                            </td>
                            <td class="muted" style="font-size:0.76rem;">
                                <?php foreach ($event['args'] as $i => $argLabel): ?>
                                    <code dir="ltr">{<?= (int) $i ?>}</code>=<?= e($argLabel) ?><?= $i < count($event['args']) - 1 ? '، ' : '' ?>
                                <?php endforeach; ?>
                                <?php if ($event['args'] === []): ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Live test ──────────────────────────────────────────────── -->
    <div id="sms-test" class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title m-0">تست زنده ارسال</h2>
        </div>
        <p class="muted mb-md">
            رویداد را انتخاب کنید؛ سیستم شناسه الگوی همان رویداد را از تنظیمات می‌خواند و پیامک آزمایشی می‌فرستد.
        </p>

        <form method="post" action="<?= e(url('admin/sms-center.php')) ?>" class="sms-test-form">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="send_test">

            <div class="sms-test-field sms-test-field--phone">
                <label for="test_phone" class="form-label">شماره گیرنده</label>
                <input type="text" id="test_phone" name="test_phone" class="form-control" dir="ltr" placeholder="09123456789" required>
            </div>

            <div class="sms-test-field" style="flex:1.4 1 220px;">
                <label for="test_event" class="form-label">رویداد</label>
                <select id="test_event" name="test_event" class="form-control" required>
                    <?php foreach ($catalog as $key => $event): ?>
                        <?php $bodyId = (int) ($settings[$event['pattern_key']] ?? 0); ?>
                        <option value="<?= e($key) ?>" <?= $bodyId <= 0 ? 'disabled' : '' ?>>
                            <?= e($event['label']) ?><?= $bodyId <= 0 ? ' — الگو تنظیم نشده' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sms-test-field">
                <label for="test_arg_0" class="form-label">متغیر اول ({0})</label>
                <input type="text" id="test_arg_0" name="test_arg_0" class="form-control" placeholder="خالی = پیش‌فرض">
            </div>

            <div class="sms-test-field">
                <label for="test_arg_1" class="form-label">متغیر دوم ({1})</label>
                <input type="text" id="test_arg_1" name="test_arg_1" class="form-control" placeholder="اختیاری">
            </div>

            <div class="sms-test-field">
                <label for="test_arg_2" class="form-label">متغیر سوم ({2})</label>
                <input type="text" id="test_arg_2" name="test_arg_2" class="form-control" placeholder="اختیاری">
            </div>

            <div class="sms-test-field">
                <label for="test_arg_3" class="form-label">متغیر چهارم ({3})</label>
                <input type="text" id="test_arg_3" name="test_arg_3" class="form-control" placeholder="اختیاری">
            </div>

            <div class="adm-filter-actions">
                <button type="submit" class="btn btn-primary">ارسال تست با الگو</button>
                <button type="submit" name="action" value="test_otp_fast" class="btn adm-btn-fast" title="ارسال کد از طریق خط مستقیم اختصاصی در ۲ تا ۵ ثانیه">تست سریع OTP</button>
            </div>
        </form>
    </div>

    <!-- ── Full delivery log ──────────────────────────────────────── -->
    <div id="sms-log" class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title m-0">گزارش کامل تحویل پیامک</h2>
            <span class="adm-count-pill"><?= e(persianNumber((string) $logPagination['total'])) ?> رکورد</span>
        </div>

        <form method="get" action="<?= e(url('admin/sms-center.php')) ?>" class="adm-filter-panel">
            <div class="adm-filter-field">
                <label for="filter_status" class="form-label">وضعیت</label>
                <select id="filter_status" name="status" class="form-control" onchange="this.form.submit()">
                    <option value="">همه</option>
                    <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>موفق</option>
                    <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>ناموفق</option>
                </select>
            </div>
            <div class="adm-filter-field">
                <label for="filter_event" class="form-label">نوع رویداد</label>
                <select id="filter_event" name="event" class="form-control" onchange="this.form.submit()">
                    <option value="">همه</option>
                    <?php foreach ($logEventTypes as $type): ?>
                        <option value="<?= e((string) $type) ?>" <?= $eventFilter === (string) $type ? 'selected' : '' ?>>
                            <?= e((string) ($catalog[(string) $type]['label'] ?? $type)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filterQuery !== []): ?>
                <div class="adm-filter-actions">
                    <a href="<?= e(url('admin/sms-center.php')) ?>" class="btn btn-secondary">حذف فیلترها</a>
                </div>
            <?php endif; ?>
        </form>

        <?php if ($logRows === []): ?>
            <div class="empty-state empty-state-sm">
                <h3>هیچ رکوردی با این فیلترها یافت نشد</h3>
            </div>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>گیرنده</th>
                            <th>والد</th>
                            <th>رویداد</th>
                            <th>الگو</th>
                            <th>کد پیگیری</th>
                            <th>وضعیت</th>
                            <th>زمان</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logRows as $log): ?>
                            <tr>
                                <td><?= e(persianNumber((string) $log['id'])) ?></td>
                                <td dir="ltr" style="text-align:right;font-family:monospace;"><?= e((string) $log['recipient_phone']) ?></td>
                                <td>
                                    <?php $pName = trim((string) ($log['p_first'] ?? '') . ' ' . (string) ($log['p_last'] ?? '')); ?>
                                    <?= $pName !== '' ? e($pName) : '<span class="muted">—</span>' ?>
                                </td>
                                <td>
                                    <?= e((string) ($catalog[(string) $log['message_type']]['label'] ?? $log['message_type'])) ?>
                                </td>
                                <td dir="ltr" style="text-align:right;"><?= e((string) ($log['pattern_body_id'] ?? '—')) ?></td>
                                <td dir="ltr" style="text-align:right;font-family:monospace;font-size:0.8rem;"><?= e((string) ($log['rec_id'] ?? '—')) ?></td>
                                <td>
                                    <?php if ((string) ($log['status'] ?? '') === 'sent'): ?>
                                        <span class="badge badge-success">موفق</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger" title="<?= e((string) ($log['error_message'] ?? '')) ?>">
                                            <?= e((string) ($log['error_message'] ?: ($log['status'] ?? 'ناموفق'))) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="muted" style="white-space:nowrap;">
                                    <?= e(shamsiDbTimestamp((string) $log['created_at'], 'with_time')) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($logPagination['total'] > $logPagination['perPage']): ?>
                <p class="pagination-summary">
                    نمایش <?= e(persianNumber($logPagination['from'])) ?> تا <?= e(persianNumber($logPagination['to'])) ?>
                    از <?= e(persianNumber($logPagination['total'])) ?> رکورد
                </p>
                <?= renderPagination($logPagination, url('admin/sms-center.php'), $filterQuery) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>

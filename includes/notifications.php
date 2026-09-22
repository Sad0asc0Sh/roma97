<?php
declare(strict_types=1);

/**
 * ROMA — Unified Notification & Automation Engine
 * ------------------------------------------------------------------
 * Single source of truth for every outbound notification in the system
 * (parent portal message + patterned SMS), for all three panels
 * (admin, teacher, parent) and for the CLI/cron automations.
 *
 * Why this file exists
 * --------------------
 * Previously each page re-implemented the same five steps by hand
 * (read settings → read pattern id → read parent preference → send →
 * hope for the best). That produced three classes of production bugs:
 *
 *   1. Duplicate/spam SMS — re-saving an attendance sheet or a daily
 *      report re-sent an SMS to every parent, burning real credit.
 *   2. Inconsistent gating — some flows checked `sms_enabled`, some
 *      checked the pattern id, some checked neither.
 *   3. Blocking requests — sending N synchronous cURL calls inside the
 *      request meant a 30-child classroom could time out the page.
 *
 * The engine below fixes all three: every notification goes through
 * `notifyParent()`, is de-duplicated by an idempotency key, and is
 * delivered through a persisted queue with a strict time budget.
 */

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/sms.php';

// ─────────────────────────────────────────────────────────────────────────────
// 1. EVENT CATALOG
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Every notification the platform can emit, declared in one place.
 *
 * pattern_key  Settings key holding the MeliPayamak approved pattern id.
 * pref_column  parents.* opt-out column (null = transactional, always sent).
 * args         Human-readable description of the SMS template variables, used to
 *              render the admin SMS Center help text and the live test form.
 * inline       true  = must reach the handset immediately (OTP);
 *              false = may be queued and drained by the worker.
 * portal       whether this event also writes a parent-portal inbox message.
 *
 * @return array<string, array{
 *     label:string, group:string, pattern_key:string, pref_column:?string,
 *     args:list<string>, inline:bool, portal:bool, description:string
 * }>
 */
function notificationCatalog(): array
{
    static $catalog = null;

    if ($catalog !== null) {
        return $catalog;
    }

    $catalog = [
        'otp' => [
            'label'       => 'کد تایید ورود و ثبت‌نام (OTP)',
            'group'       => 'حساب کاربری',
            'pattern_key' => 'sms_pattern_otp',
            'pref_column' => null,
            'args'        => ['کد تایید ۵ رقمی'],
            'inline'      => true,
            'portal'      => false,
            'description' => 'برای ثبت‌نام والدین و بازیابی رمز عبور ضروری است. بدون تنظیم این الگو، ثبت‌نام والدین کار نمی‌کند.',
        ],
        'welcome' => [
            'label'       => 'خوش‌آمدگویی و تایید حساب والد',
            'group'       => 'حساب کاربری',
            'pattern_key' => 'sms_pattern_welcome',
            'pref_column' => null,
            'args'        => ['نام والد'],
            'inline'      => false,
            'portal'      => true,
            'description' => 'هنگام تأیید یا فعال‌سازی حساب والد توسط مدیر ارسال می‌شود.',
        ],
        'attendance' => [
            'label'       => 'اعلام حضور / غیبت کودک',
            'group'       => 'مراقبت روزانه',
            'pattern_key' => 'sms_pattern_attendance',
            'pref_column' => 'sms_pref_attendance',
            'args'        => ['نام کودک', 'تاریخ', 'وضعیت'],
            'inline'      => false,
            'portal'      => false,
            'description' => 'تنها زمانی ارسال می‌شود که وضعیت حضور کودک تغییر کرده باشد (ذخیره مجدد فرم پیامک تکراری نمی‌فرستد).',
        ],
        'daily_report_brief' => [
            'label'       => 'گزارش روزانه خلاصه',
            'group'       => 'مراقبت روزانه',
            'pattern_key' => 'sms_pattern_daily_report_brief',
            'pref_column' => 'sms_pref_daily_report',
            'args'        => ['نام کودک', 'نام مهد'],
            'inline'      => false,
            'portal'      => false,
            'description' => 'پیامک کوتاه ارجاع به پنل روما.',
        ],
        'daily_report_vip' => [
            'label'       => 'گزارش روزانه تفصیلی',
            'group'       => 'مراقبت روزانه',
            'pattern_key' => 'sms_pattern_daily_report',
            'pref_column' => 'sms_pref_daily_report',
            'args'        => ['نام کودک', 'تاریخ', 'حال و هوا'],
            'inline'      => false,
            'portal'      => false,
            'description' => 'پیامک تفصیلی گزارش روزانه.',
        ],
        'tuition_reminder' => [
            'label'       => 'یادآوری دوستانه شهریه',
            'group'       => 'مالی',
            'pattern_key' => 'sms_pattern_tuition_reminder',
            'pref_column' => 'sms_pref_tuition',
            'args'        => ['نام والد', 'نام کودک', 'ماه', 'مبلغ بدهی'],
            'inline'      => false,
            'portal'      => true,
            'description' => 'توسط کرون روزانه ارسال می‌شود؛ برای هر کودک حداکثر یک‌بار در هر ماه شهریه.',
        ],
        'payment_receipt' => [
            'label'       => 'رسید ثبت پرداخت شهریه',
            'group'       => 'مالی',
            'pattern_key' => 'sms_pattern_payment_receipt',
            'pref_column' => 'sms_pref_tuition',
            'args'        => ['نام والد', 'نام کودک', 'مبلغ پرداخت', 'ماه'],
            'inline'      => false,
            'portal'      => true,
            'description' => 'به‌محض ثبت پرداخت توسط مدیر، رسید برای والد ارسال می‌شود (شفافیت مالی).',
        ],
        'announcement' => [
            'label'       => 'اطلاعیه‌ها و پیام‌های مدیریت',
            'group'       => 'ارتباطات',
            'pattern_key' => 'sms_pattern_announcement',
            'pref_column' => 'sms_pref_announcements',
            'args'        => ['نام والد', 'عنوان پیام'],
            'inline'      => false,
            'portal'      => true,
            'description' => 'هنگام ارسال پیام از پنل مدیر یا معلم به والدین.',
        ],
        'child_status' => [
            'label'       => 'تغییر وضعیت ثبت‌نام کودک',
            'group'       => 'ثبت‌نام',
            'pattern_key' => 'sms_pattern_child_status',
            'pref_column' => 'sms_pref_announcements',
            'args'        => ['نام والد', 'نام کودک', 'وضعیت جدید'],
            'inline'      => false,
            'portal'      => true,
            'description' => 'تأیید ثبت‌نام، اختصاص کلاس، فارغ‌التحصیلی یا انصراف کودک.',
        ],
    ];

    return $catalog;
}

/**
 * @return array{label:string, group:string, pattern_key:string, pref_column:?string, args:list<string>, inline:bool, portal:bool, description:string}|null
 */
function notificationEvent(string $eventKey): ?array
{
    return notificationCatalog()[$eventKey] ?? null;
}

/**
 * All settings keys the notification engine reads. Used by the settings
 * loaders so a newly added event never silently falls back to "unset".
 *
 * @return list<string>
 */
function notificationPatternKeys(): array
{
    $keys = [];

    foreach (notificationCatalog() as $event) {
        $keys[$event['pattern_key']] = true;
    }

    return array_keys($keys);
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. GATEWAY READINESS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * A single, honest answer to "is the SMS panel actually able to send?".
 *
 * Every panel and the CLI use this instead of re-deriving the answer, so an
 * admin is never shown "sent successfully" while nothing left the building.
 *
 * @return array{
 *     enabled:bool, api_key_set:bool, ready:bool,
 *     configured_events:list<string>, missing_events:list<string>,
 *     critical_issues:list<string>, warnings:list<string>,
 *     patterns:array<string,int>
 * }
 */
function smsGatewayStatus(?PDO $pdo = null): array
{
    $settings = getSmsSettings($pdo);

    $enabled    = ($settings['sms_enabled'] ?? '0') === '1';
    $sharedKey  = melipayamakConsoleKey($settings, 'shared');
    $otpKey     = melipayamakConsoleKey($settings, 'otp');
    $apiKeySet  = $sharedKey !== '' || $otpKey !== '';

    $patterns = [];
    $configured = [];
    $missing = [];

    foreach (notificationCatalog() as $key => $event) {
        $bodyId = (int) ($settings[$event['pattern_key']] ?? 0);
        $patterns[$event['pattern_key']] = $bodyId;

        if ($bodyId > 0) {
            $configured[] = $key;
        } else {
            $missing[] = $key;
        }
    }

    $critical = [];
    $warnings = [];

    if (!$enabled) {
        $warnings[] = 'ارسال پیامک در تنظیمات سیستم غیرفعال است؛ اعلان‌ها فقط در پورتال ثبت می‌شوند.';
    }

    if ($sharedKey === '') {
        $critical[] = 'کلید خدماتی کنسول ملی‌پیامک (ارسال از خط خدماتی) وارد نشده است.';
    }

    if ($otpKey === '' && (int) ($patterns['sms_pattern_otp'] ?? 0) <= 0) {
        $critical[] = 'برای کد تایید، «کلید یکبارمصرف» کنسول یا الگوی OTP (کد متن خدماتی) لازم است.';
    } elseif ($otpKey === '' && (int) ($patterns['sms_pattern_otp'] ?? 0) > 0 && $sharedKey === '') {
        $critical[] = 'الگوی OTP تنظیم شده ولی کلید خدماتی برای ارسال پترن موجود نیست.';
    }

    if ($missing !== []) {
        $labels = [];
        foreach ($missing as $key) {
            $labels[] = (string) (notificationCatalog()[$key]['label'] ?? $key);
        }
        $warnings[] = 'الگوهای تنظیم‌نشده: ' . implode('، ', $labels);
    }

    return [
        'enabled'           => $enabled,
        'api_key_set'       => $apiKeySet,
        'ready'             => $enabled && $apiKeySet && $critical === [],
        'configured_events' => $configured,
        'missing_events'    => $missing,
        'critical_issues'   => $critical,
        'warnings'          => $warnings,
        'patterns'          => $patterns,
    ];
}

/**
 * Whether a specific event is fully wired up (gateway on + pattern approved).
 */
function isNotificationEventDeliverable(string $eventKey, ?PDO $pdo = null): bool
{
    $event = notificationEvent($eventKey);

    if ($event === null) {
        return false;
    }

    $settings = getSmsSettings($pdo);

    return ($settings['sms_enabled'] ?? '0') === '1'
        && melipayamakConsoleKey($settings, 'shared') !== ''
        && (int) ($settings[$event['pattern_key']] ?? 0) > 0;
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. IDEMPOTENCY (the anti-duplicate-SMS guard)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Claim a notification exclusively. Returns false when this exact
 * notification was already emitted, which is what stops a second form
 * submission from charging the daycare for a second SMS.
 *
 * The uniqueness lives in the database (UNIQUE KEY on dedup_key) so it also
 * holds across concurrent requests and across web + cron.
 */
function claimNotification(PDO $pdo, string $eventKey, ?int $parentId, string $dedupKey, string $channels): bool
{
    if ($dedupKey === '') {
        return true; // Caller opted out of de-duplication.
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO notification_log (event_key, parent_id, dedup_key, channels)
             VALUES (:ekey, :pid, :dkey, :ch)'
        );
        $stmt->execute([
            ':ekey' => mb_substr($eventKey, 0, 50, 'UTF-8'),
            ':pid'  => $parentId,
            ':dkey' => mb_substr($dedupKey, 0, 191, 'UTF-8'),
            ':ch'   => mb_substr($channels, 0, 50, 'UTF-8'),
        ]);

        return true;
    } catch (PDOException $e) {
        // 23000 = integrity constraint violation → duplicate dedup_key.
        if ($e->getCode() === '23000') {
            return false;
        }

        error_log('claimNotification failed: ' . $e->getMessage());

        // Never block the business action because bookkeeping failed.
        return true;
    } catch (Throwable $e) {
        error_log('claimNotification failed: ' . $e->getMessage());

        return true;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. SMS QUEUE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * How many seconds a web request may spend flushing the SMS queue before it
 * hands the rest over to the cron worker. Keeps page loads snappy while still
 * delivering instantly on installations that have no cron configured.
 */
function smsInlineTimeBudgetSeconds(): int
{
    $configured = (int) getSetting('sms_inline_budget_seconds', '8');

    return $configured > 0 ? min($configured, 25) : 8;
}

/**
 * Persist an SMS for delivery.
 *
 * @param list<string> $args
 * @return int|null Queue row id, or null when it could not be stored.
 */
function smsQueueEnqueue(
    PDO $pdo,
    string $phone,
    int $bodyId,
    array $args,
    string $eventType,
    ?int $parentId = null,
    ?string $dedupKey = null,
    int $priority = 5
): ?int {
    $normalized = normalizeIranianPhone($phone);

    if (!isValidIranianMobile($normalized) || $bodyId <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO sms_queue (recipient_phone, pattern_body_id, args_json, event_type, parent_id, dedup_key, priority, status)
             VALUES (:phone, :body, :args, :etype, :pid, :dkey, :prio, :status)'
        );
        $stmt->execute([
            ':phone'  => $normalized,
            ':body'   => $bodyId,
            ':args'   => json_encode(array_values(array_map('strval', $args)), JSON_UNESCAPED_UNICODE),
            ':etype'  => mb_substr($eventType, 0, 50, 'UTF-8'),
            ':pid'    => $parentId,
            ':dkey'   => $dedupKey !== null && $dedupKey !== '' ? mb_substr($dedupKey, 0, 191, 'UTF-8') : null,
            ':prio'   => max(1, min(9, $priority)),
            ':status' => 'pending',
        ]);

        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return null; // Already queued with the same dedup key.
        }

        error_log('smsQueueEnqueue failed: ' . $e->getMessage());

        return null;
    } catch (Throwable $e) {
        error_log('smsQueueEnqueue failed: ' . $e->getMessage());

        return null;
    }
}

/**
 * Deliver pending queue rows.
 *
 * Safe to run concurrently from the web request and the cron worker: each row
 * is claimed with a conditional UPDATE before the network call is made.
 *
 * @param int      $limit          Maximum messages to attempt.
 * @param int|null $timeBudget     Wall-clock seconds allowed (null = unlimited).
 * @param int|null $onlyQueueId    Restrict to a single row (used for retries).
 * @return array{attempted:int, sent:int, failed:int, remaining:int}
 */
function smsQueueProcess(PDO $pdo, int $limit = 25, ?int $timeBudget = null, ?int $onlyQueueId = null): array
{
    $result = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'remaining' => 0];
    $startedAt = microtime(true);

    try {
        // Reaper: a worker that dies between claiming a row ('sending') and
        // marking it would otherwise leave the message invisible forever —
        // the pending SELECT never sees 'sending' rows. Return stale claims to
        // the queue after a grace period so no message is silently lost.
        // The conditional claim below (UPDATE ... WHERE status='pending')
        // keeps this race-safe across overlapping workers.
        $reaped = $pdo->prepare(
            "UPDATE sms_queue SET status = 'pending', last_error = 'reaped: worker did not finish'
             WHERE status = 'sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $reaped->execute();

        $sql = "SELECT id, recipient_phone, pattern_body_id, args_json, event_type, parent_id, attempts
                FROM sms_queue
                WHERE status = 'pending'";
        $params = [];

        if ($onlyQueueId !== null) {
            $sql .= ' AND id = :qid';
            $params[':qid'] = $onlyQueueId;
        }

        $sql .= ' ORDER BY priority ASC, id ASC LIMIT ' . max(1, min(500, $limit));

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $claim = $pdo->prepare(
            "UPDATE sms_queue
             SET status = 'sending', attempts = attempts + 1, updated_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );

        $markSent = $pdo->prepare(
            "UPDATE sms_queue
             SET status = 'sent', rec_id = :rec, last_error = NULL, sent_at = NOW(), updated_at = NOW()
             WHERE id = :id"
        );

        $markFailed = $pdo->prepare(
            "UPDATE sms_queue
             SET status = :status, last_error = :err, updated_at = NOW()
             WHERE id = :id"
        );

        foreach ($rows as $row) {
            if ($timeBudget !== null && (microtime(true) - $startedAt) >= $timeBudget) {
                break; // Hand the rest to the cron worker.
            }

            $queueId = (int) $row['id'];

            $claim->execute([':id' => $queueId]);
            if ($claim->rowCount() === 0) {
                continue; // Another worker got it first.
            }

            $args = json_decode((string) ($row['args_json'] ?? '[]'), true);
            if (!is_array($args)) {
                $args = [];
            }

            $result['attempted']++;

            $send = sendMeliPayamakPattern(
                (string) $row['recipient_phone'],
                (int) $row['pattern_body_id'],
                $args,
                (string) $row['event_type'],
                $row['parent_id'] !== null ? (int) $row['parent_id'] : null
            );

            if ($send['success']) {
                $markSent->execute([
                    ':rec' => $send['recId'] !== null ? (string) $send['recId'] : null,
                    ':id'  => $queueId,
                ]);
                $result['sent']++;
                continue;
            }

            // Give transient failures a few chances, then park the row so the
            // admin can see it in the SMS Center and retry deliberately.
            $attempts = (int) $row['attempts'] + 1;
            $status = $attempts >= 3 ? 'failed' : 'pending';

            $markFailed->execute([
                ':status' => $status,
                ':err'    => mb_substr((string) $send['status'], 0, 500, 'UTF-8'),
                ':id'     => $queueId,
            ]);
            $result['failed']++;
        }

        $remainingStmt = $pdo->query("SELECT COUNT(*) FROM sms_queue WHERE status = 'pending'");
        $result['remaining'] = (int) ($remainingStmt ? $remainingStmt->fetchColumn() : 0);
    } catch (Throwable $e) {
        error_log('smsQueueProcess failed: ' . $e->getMessage());
    }

    return $result;
}

/**
 * Flush the queue opportunistically at the end of a web request, bounded by a
 * strict time budget so the user never waits on the SMS provider.
 *
 * @return array{attempted:int, sent:int, failed:int, remaining:int}
 */
function smsQueueFlushInline(?PDO $pdo = null, int $limit = 20): array
{
    $pdo = $pdo ?? getDb();

    return smsQueueProcess($pdo, $limit, smsInlineTimeBudgetSeconds());
}

/**
 * Counts used by the admin SMS Center dashboard.
 *
 * @return array{pending:int, failed:int, sent_today:int, failed_today:int, sent_month:int}
 */
function smsQueueStats(?PDO $pdo = null): array
{
    $pdo = $pdo ?? getDb();

    $stats = ['pending' => 0, 'failed' => 0, 'sent_today' => 0, 'failed_today' => 0, 'sent_month' => 0];

    try {
        $q = $pdo->query(
            "SELECT
                SUM(status = 'pending') AS pending,
                SUM(status = 'failed')  AS failed
             FROM sms_queue"
        );
        $row = $q ? $q->fetch() : null;

        if ($row) {
            $stats['pending'] = (int) ($row['pending'] ?? 0);
            $stats['failed']  = (int) ($row['failed'] ?? 0);
        }

        $q2 = $pdo->query(
            "SELECT
                SUM(status = 'sent'   AND DATE(created_at) = CURDATE()) AS sent_today,
                SUM(status = 'failed' AND DATE(created_at) = CURDATE()) AS failed_today,
                SUM(status = 'sent'   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS sent_month
             FROM sms_logs"
        );
        $row2 = $q2 ? $q2->fetch() : null;

        if ($row2) {
            $stats['sent_today']   = (int) ($row2['sent_today'] ?? 0);
            $stats['failed_today'] = (int) ($row2['failed_today'] ?? 0);
            $stats['sent_month']   = (int) ($row2['sent_month'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('smsQueueStats failed: ' . $e->getMessage());
    }

    return $stats;
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. THE PUBLIC ENTRY POINT
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Emit one notification to one parent across every enabled channel.
 *
 * This is the ONLY function application code should call. It enforces, in
 * order: recipient validity → account status → de-duplication → portal
 * message → opt-in preference → gateway readiness → queued SMS.
 *
 * @param array{
 *     args?:list<string>,
 *     portal_subject?:string,
 *     portal_body?:string,
 *     dedup_key?:string,
 *     sender_type?:string,
 *     sender_id?:int,
 *     child_name?:string,
 *     skip_portal?:bool,
 *     skip_sms?:bool,
 *     priority?:int,
 *     allow_inactive?:bool
 * } $options
 *
 * @return array{portal:bool, sms:string, reason:string, queue_id:?int}
 */
function notifyParent(int $parentId, string $eventKey, array $options = []): array
{
    $out = ['portal' => false, 'sms' => 'skipped', 'reason' => '', 'queue_id' => null];

    $event = notificationEvent($eventKey);
    if ($event === null) {
        $out['reason'] = 'رویداد اعلان ناشناخته است.';

        return $out;
    }

    if ($parentId <= 0) {
        $out['reason'] = 'شناسه والد نامعتبر است.';

        return $out;
    }

    try {
        $pdo = getDb();

        $prefColumn = $event['pref_column'];
        $columns = 'id, first_name, last_name, phone, status';
        if ($prefColumn !== null) {
            $columns .= ', ' . $prefColumn;
        }

        $stmt = $pdo->prepare("SELECT {$columns} FROM parents WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $parentId]);
        $parent = $stmt->fetch();

        if (!$parent) {
            $out['reason'] = 'والد یافت نشد.';

            return $out;
        }

        $allowInactive = (bool) ($options['allow_inactive'] ?? false);
        if (!$allowInactive && (string) $parent['status'] !== 'active') {
            $out['reason'] = 'حساب والد فعال نیست.';

            return $out;
        }

        // ── Pre-flight gates BEFORE claiming: a failed pre-flight must stay
        // retryable. Claiming first would burn the dedup key on a permanent
        // failure (bad phone, disabled gateway) and the notification would
        // never go out even after the problem is fixed.
        if (!empty($options['skip_sms'])) {
            $out['reason'] = 'ارسال پیامک برای این اعلان درخواست نشده بود.';
        } else {
            if ($prefColumn !== null && (int) ($parent[$prefColumn] ?? 1) !== 1) {
                $out['sms'] = 'opted_out';
                $out['reason'] = 'والد دریافت پیامک این دسته را غیرفعال کرده است.';

                return $out;
            }

            $phone = (string) ($parent['phone'] ?? '');
            if (!isValidIranianMobile($phone)) {
                $out['sms'] = 'no_phone';
                $out['reason'] = 'شماره موبایل معتبری برای این والد ثبت نشده است.';

                return $out;
            }

            $settings = getSmsSettings($pdo);
            $bodyId = (int) ($settings[$event['pattern_key']] ?? 0);

            if (($settings['sms_enabled'] ?? '0') !== '1') {
                $out['sms'] = 'gateway_disabled';
                $out['reason'] = 'ارسال پیامک در تنظیمات سیستم غیرفعال است.';

                return $out;
            }

            if ($bodyId <= 0) {
                $out['sms'] = 'pattern_missing';
                $out['reason'] = 'شناسه الگوی پیامک این رویداد تنظیم نشده است.';

                return $out;
            }

            $out['reason'] = 'ارسال پیامک برای این اعلان درخواست نشده بود.';
        }

        // ── De-duplication gate (covers portal + SMS together) ──────────────
        $dedupKey = (string) ($options['dedup_key'] ?? '');
        $channels = [];
        if (empty($options['skip_portal']) && $event['portal']) {
            $channels[] = 'portal';
        }
        if (empty($options['skip_sms'])) {
            $channels[] = 'sms';
        }

        if (!claimNotification($pdo, $eventKey, $parentId, $dedupKey, implode(',', $channels))) {
            $out['reason'] = 'این اعلان قبلاً ارسال شده است (جلوگیری از پیام تکراری).';
            $out['sms'] = 'duplicate';

            return $out;
        }

        // ── Channel 1: parent portal inbox ──────────────────────────────────
        $portalSubject = trim((string) ($options['portal_subject'] ?? ''));
        $portalBody    = trim((string) ($options['portal_body'] ?? ''));

        if (
            empty($options['skip_portal'])
            && $event['portal']
            && $portalSubject !== ''
            && $portalBody !== ''
        ) {
            $senderType = (string) ($options['sender_type'] ?? 'admin');
            if (!in_array($senderType, ['admin', 'teacher'], true)) {
                $senderType = 'admin';
            }
            $senderId = (int) ($options['sender_id'] ?? 0);

            if ($senderId <= 0) {
                $senderId = notificationSystemAdminId($pdo);
            }

            try {
                $ins = $pdo->prepare(
                    'INSERT INTO messages (sender_type, sender_id, parent_id, subject, body, is_read)
                     VALUES (:stype, :sid, :pid, :sub, :body, 0)'
                );
                $ins->execute([
                    ':stype' => $senderType,
                    ':sid'   => $senderId,
                    ':pid'   => $parentId,
                    ':sub'   => mb_substr($portalSubject, 0, 255, 'UTF-8'),
                    ':body'  => $portalBody,
                ]);
                $out['portal'] = true;
            } catch (Throwable $e) {
                error_log('notifyParent portal insert failed: ' . $e->getMessage());
            }
        }

        // ── Channel 2: patterned SMS ────────────────────────────────────────
        // Pre-flight gates (opt-out, phone validity, gateway, pattern) already
        // ran before the dedup claim above.
        if (!empty($options['skip_sms'])) {
            return $out;
        }

        $args = $options['args'] ?? [];
        if (!is_array($args)) {
            $args = [];
        }

        // OTP-class messages must not wait in a queue.
        if ($event['inline']) {
            $send = sendMeliPayamakPattern($phone, $bodyId, $args, $eventKey, $parentId);
            $out['sms'] = $send['success'] ? 'sent' : 'failed';
            $out['reason'] = (string) $send['status'];

            return $out;
        }

        $queueId = smsQueueEnqueue(
            $pdo,
            $phone,
            $bodyId,
            $args,
            $eventKey,
            $parentId,
            $dedupKey !== '' ? 'sms:' . $dedupKey : null,
            (int) ($options['priority'] ?? 5)
        );

        if ($queueId === null) {
            $out['sms'] = 'skipped';
            $out['reason'] = 'پیامک در صف ثبت نشد (احتمالاً تکراری بود).';

            return $out;
        }

        $out['sms'] = 'queued';
        $out['queue_id'] = $queueId;
        $out['reason'] = 'پیامک در صف ارسال قرار گرفت.';

        return $out;
    } catch (Throwable $e) {
        error_log('notifyParent failed: ' . $e->getMessage());
        $out['reason'] = 'خطای داخلی در ارسال اعلان.';

        return $out;
    }
}

/**
 * Resolve an admin id to attribute automated portal messages to, preferring
 * the owner account. Cached per request.
 */
function notificationSystemAdminId(?PDO $pdo = null): int
{
    static $adminId = null;

    if ($adminId !== null) {
        return $adminId;
    }

    $adminId = 0;

    try {
        $pdo = $pdo ?? getDb();
        $stmt = $pdo->query("SELECT id FROM admins ORDER BY (role = 'owner') DESC, id ASC LIMIT 1");
        $adminId = (int) ($stmt ? $stmt->fetchColumn() : 0);
    } catch (Throwable $e) {
        error_log('notificationSystemAdminId failed: ' . $e->getMessage());
    }

    if ($adminId <= 0) {
        $adminId = 1;
    }

    return $adminId;
}

/**
 * Parent's display name, memoised per request. Used to personalise the first
 * SMS template variable ("{0} مهربان، ...") without issuing one query per row
 * inside a broadcast loop.
 */
function parentDisplayNameForMessage(?PDO $pdo, int $parentId): string
{
    static $cache = [];

    if (isset($cache[$parentId])) {
        return $cache[$parentId];
    }

    $name = 'والد گرامی';

    try {
        $pdo = $pdo ?? getDb();
        $stmt = $pdo->prepare('SELECT first_name, last_name FROM parents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $parentId]);
        $row = $stmt->fetch();

        if ($row) {
            $candidate = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
            if ($candidate !== '') {
                $name = $candidate;
            }
        }
    } catch (Throwable $e) {
        error_log('parentDisplayNameForMessage failed: ' . $e->getMessage());
    }

    $cache[$parentId] = $name;

    return $name;
}

/**
 * Persian label for an SMS delivery outcome, shared by every panel so the
 * same result never gets two different names.
 */
function notificationOutcomeLabel(string $outcome): string
{
    return match ($outcome) {
        'sent'             => 'ارسال شد',
        'queued'           => 'در صف ارسال',
        'duplicate'        => 'تکراری (ارسال نشد)',
        'opted_out'        => 'انصراف والد',
        'no_phone'         => 'بدون شماره معتبر',
        'gateway_disabled' => 'پیامک غیرفعال',
        'pattern_missing'  => 'الگو تنظیم نشده',
        'failed'           => 'ناموفق',
        default            => 'ارسال نشد',
    };
}

/**
 * Aggregate many notifyParent() results into one short, human sentence for a
 * flash message — so admins get real feedback instead of a silent success.
 *
 * @param list<array{portal:bool, sms:string, reason:string, queue_id:?int}> $results
 */
function summarizeNotificationResults(array $results): string
{
    if ($results === []) {
        return '';
    }

    $counts = [];
    foreach ($results as $r) {
        $key = (string) ($r['sms'] ?? 'skipped');
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    $parts = [];
    foreach (['sent', 'queued', 'failed', 'duplicate', 'opted_out', 'no_phone', 'gateway_disabled', 'pattern_missing'] as $key) {
        if (!empty($counts[$key])) {
            $parts[] = notificationOutcomeLabel($key) . ': ' . persianNumber((string) $counts[$key]);
        }
    }

    return $parts === [] ? '' : 'وضعیت پیامک‌ها — ' . implode(' · ', $parts);
}

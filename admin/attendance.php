<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();

/** @var list<string> $allowedStatuses */
$allowedStatuses = ['present', 'absent', 'late', 'excused'];

function parseAttendanceDateString(string $raw): ?string
{
    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $raw) !== 1) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

    return $date !== false && $date->format('Y-m-d') === $raw ? $raw : null;
}

function attendanceTimeForInput(?string $sqlTime): string
{
    if ($sqlTime === null || $sqlTime === '') {
        return '';
    }

    if (preg_match('/^\d{2}:\d{2}/', $sqlTime, $matches)) {
        return $matches[0];
    }

    return '';
}

function normalizeAttendanceTime(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }

    $raw = trim($raw);

    if ($raw === '') {
        return null;
    }

    if (preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $raw) === 1) {
        return $raw . ':00';
    }

    if (preg_match('/\A([01]\d|2[0-3]):[0-5]\d:[0-5]\d\z/', $raw) === 1) {
        return $raw;
    }

    return null;
}

function attendanceAgeFromDob(string $dateOfBirth): string
{
    try {
        $birthDate = new DateTimeImmutable($dateOfBirth);
        $today = new DateTimeImmutable('today');

        if ($birthDate >= $today) {
            return 'نوزاد';
        }

        $years = $birthDate->diff($today)->y;
        $months = $birthDate->diff($today)->m;

        if ($years < 1) {
            return $months . ' ماه';
        }

        return $years . ' سال';
    } catch (Throwable) {
        return '—';
    }
}

function attendanceStatusValue(array $child): string
{
    $s = (string) ($child['attendance_status'] ?? '');

    return in_array($s, ['present', 'absent', 'late', 'excused'], true) ? $s : 'present';
}

/** @param array<string,mixed> $child */
function renderAttendanceRadios(array $child, int $childId, string $groupSuffix = ''): void
{
    $current = attendanceStatusValue($child);
    $nameAttr = 'attendance[' . $childId . '][status]';
    $labels = [
        'present' => 'حاضر',
        'late' => 'تأخیر',
        'excused' => 'غیبت موجه',
        'absent' => 'غایب',
    ];

    foreach ($labels as $value => $label) {
        $id = 'att-' . $childId . '-' . $value . ($groupSuffix !== '' ? '-' . $groupSuffix : '');
        ?>
        <label class="status-radio-label" for="<?= e($id) ?>">
            <input
                type="radio"
                id="<?= e($id) ?>"
                name="<?= e($nameAttr) ?>"
                value="<?= e($value) ?>"
                <?= $current === $value ? 'checked' : '' ?>
            >
            <?= e($label) ?>
        </label>
        <?php
    }
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$selectedDate = parseAttendanceDateString((string) ($_GET['date'] ?? $today)) ?? $today;

$pdo = null;
$rows = [];
$successMessage = getFlash('success');
$errorMessage = getFlash('error');

try {
    initializeParentTables();
    $pdo = getDb();

    if (isPostRequest()) {
        $csrfToken = $_POST['csrf_token'] ?? '';
        $postDate = (string) ($_POST['attendance_date'] ?? '');
        $parsedPostDate = parseAttendanceDateString($postDate);

        if (!validateCsrfToken($csrfToken)) {
            setFlash('error', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
            redirect(url('admin/attendance.php?date=' . rawurlencode($selectedDate)));
        }

        if ($parsedPostDate === null) {
            setFlash('error', 'لطفاً تاریخ معتبری انتخاب کنید.');
            redirect(url('admin/attendance.php?date=' . rawurlencode($selectedDate)));
        }

        $activeStmt = $pdo->prepare('SELECT id FROM children WHERE status = :status');
        $activeStmt->execute([':status' => 'active']);
        $allowedIds = [];

        while ($idRow = $activeStmt->fetch()) {
            $allowedIds[(int) $idRow['id']] = true;
        }

        $attendanceData = $_POST['attendance'] ?? [];

        if (!is_array($attendanceData)) {
            $attendanceData = [];
        }

        $upsert = $pdo->prepare(
            <<<'SQL'
INSERT INTO attendance (child_id, attendance_date, status, check_in, check_out, notes)
VALUES (:child_id, :attendance_date, :status, :check_in, :check_out, :notes)
ON DUPLICATE KEY UPDATE
    status = VALUES(status),
    check_in = VALUES(check_in),
    check_out = VALUES(check_out),
    notes = VALUES(notes)
SQL
        );

        foreach ($attendanceData as $childKey => $fields) {
            if (!is_array($fields)) {
                continue;
            }

            $childId = filter_var($childKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (!is_int($childId) || !isset($allowedIds[$childId])) {
                continue;
            }

            $status = (string) ($fields['status'] ?? 'present');

            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'present';
            }

            $checkInRaw = isset($fields['check_in']) ? (string) $fields['check_in'] : '';
            $checkOutRaw = isset($fields['check_out']) ? (string) $fields['check_out'] : '';
            $notesRaw = isset($fields['notes']) ? trim((string) $fields['notes']) : '';

            if (function_exists('mb_strlen')) {
                if (mb_strlen($notesRaw, 'UTF-8') > 5000) {
                    $notesRaw = mb_substr($notesRaw, 0, 5000, 'UTF-8');
                }
            } elseif (strlen($notesRaw) > 5000) {
                $notesRaw = substr($notesRaw, 0, 5000);
            }

            $notes = $notesRaw === '' ? null : $notesRaw;
            $checkIn = normalizeAttendanceTime($checkInRaw);
            $checkOut = normalizeAttendanceTime($checkOutRaw);

            $upsert->execute([
                ':child_id' => $childId,
                ':attendance_date' => $parsedPostDate,
                ':status' => $status,
                ':check_in' => $checkIn,
                ':check_out' => $checkOut,
                ':notes' => $notes,
            ]);
        }

        $displayFlashDate = shamsiDate($parsedPostDate);
        recordAudit('attendance.save', 'attendance', null, ['date' => $parsedPostDate]);
        setFlash('success', 'حضور و غیاب برای تاریخ ' . $displayFlashDate . ' ذخیره شد.');
        redirect(url('admin/attendance.php?date=' . rawurlencode($parsedPostDate)));
    }

    $listStmt = $pdo->prepare(
        <<<'SQL'
SELECT
    c.id,
    c.first_name,
    c.last_name,
    c.date_of_birth,
    c.photo,
    p.first_name AS parent_first_name,
    p.last_name AS parent_last_name,
    a.status AS attendance_status,
    a.check_in,
    a.check_out,
    a.notes AS attendance_notes
FROM children c
INNER JOIN parents p ON p.id = c.parent_id
LEFT JOIN attendance a ON a.child_id = c.id AND a.attendance_date = :attendance_date
WHERE c.status = :child_status
ORDER BY c.last_name ASC, c.first_name ASC
SQL
    );
    $listStmt->execute([
        ':attendance_date' => $selectedDate,
        ':child_status' => 'active',
    ]);
    $rows = $listStmt->fetchAll();
} catch (Throwable $exception) {
    error_log($exception->getMessage());

    if ($errorMessage === null || $errorMessage === '') {
        $errorMessage = 'بارگذاری حضور و غیاب با مشکل مواجه شد. لطفاً بعداً دوباره تلاش کنید.';
    }
}

$displayDateLabel = shamsiDate($selectedDate);
$pageTitle = 'حضور و غیاب روزانه | ' . siteName();
require_once __DIR__ . '/header.php';
?>

<section class="dashboard">
    <h1>حضور و غیاب روزانه</h1>

    <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert" role="alert"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <form class="date-selector date-selector-form" method="get" action="<?= e(url('admin/attendance.php')) ?>">
        <label for="attendance_date_pick">تاریخ</label>
        <input type="date" id="attendance_date_pick" name="date" value="<?= e($selectedDate) ?>">
        <button type="submit" class="btn btn-secondary attendance-date-btn">بارگذاری تاریخ</button>
    </form>

    <p class="attendance-date-heading">ثبت برای تاریخ <strong><?= e($displayDateLabel) ?></strong></p>

    <?php if ($rows === []): ?>
        <p class="attendance-empty-message">هیچ کودک فعالی ثبت‌نام نشده است.</p>
    <?php else: ?>
        <form class="attendance-save-form" method="post" action="<?= e(url('admin/attendance.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="attendance_date" value="<?= e($selectedDate) ?>">

            <div class="attendance-toolbar">
                <button type="submit" class="btn save-btn">ذخیره حضور و غیاب</button>
            </div>

            <div class="attendance-container">
                <div class="attendance-table-wrap attendance-desktop">
                    <div class="attendance-table-scroll">
                        <table class="attendance-table">
                            <thead>
                                <tr>
                                    <th scope="col">تصویر</th>
                                    <th scope="col">کودک</th>
                                    <th scope="col">والدین</th>
                                    <th scope="col">وضعیت</th>
                                    <th scope="col">ورود</th>
                                    <th scope="col">خروج</th>
                                    <th scope="col">یادداشت</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $child): ?>
                                    <?php
                                    $cid = (int) $child['id'];
                                    $fullName = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
                                    $parentName = trim((string) $child['parent_first_name'] . ' ' . (string) $child['parent_last_name']);
                                    $initial = strtoupper(substr((string) ($child['first_name'] ?? ''), 0, 1));
                                    $notesVal = (string) ($child['attendance_notes'] ?? '');
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($child['photo'])): ?>
                                                <img class="child-photo-small" src="<?= e(url((string) $child['photo'])) ?>" alt="">
                                            <?php else: ?>
                                                <span class="child-photo-small child-photo-small-placeholder" aria-hidden="true"><?= e($initial ?: '?') ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e($fullName !== '' ? $fullName : 'کودک') ?></td>
                                        <td><?= e($parentName !== '' ? $parentName : '—') ?></td>
                                        <td>
                                            <div class="status-radio-group status-radio-group-table">
                                                <?php renderAttendanceRadios($child, $cid, 'tbl'); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <input
                                                class="time-input"
                                                type="time"
                                                name="attendance[<?= e((string) $cid) ?>][check_in]"
                                                value="<?= e(attendanceTimeForInput($child['check_in'] ?? null)) ?>"
                                            >
                                        </td>
                                        <td>
                                            <input
                                                class="time-input"
                                                type="time"
                                                name="attendance[<?= e((string) $cid) ?>][check_out]"
                                                value="<?= e(attendanceTimeForInput($child['check_out'] ?? null)) ?>"
                                            >
                                        </td>
                                        <td>
                                            <textarea
                                                class="notes-field notes-field-table"
                                                name="attendance[<?= e((string) $cid) ?>][notes]"
                                                rows="2"
                                                maxlength="5000"
                                            ><?= e($notesVal) ?></textarea>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="attendance-cards attendance-mobile" aria-label="حضور و غیاب بر اساس کودک" data-attendance-mobile>
                    <?php foreach ($rows as $child): ?>
                        <?php
                        $cid = (int) $child['id'];
                        $fullName = trim((string) $child['first_name'] . ' ' . (string) $child['last_name']);
                        $parentName = trim((string) $child['parent_first_name'] . ' ' . (string) $child['parent_last_name']);
                        $initial = strtoupper(substr((string) ($child['first_name'] ?? ''), 0, 1));
                        $notesVal = (string) ($child['attendance_notes'] ?? '');
                        ?>
                        <article class="attendance-card">
                            <div class="attendance-card-top">
                                <?php if (!empty($child['photo'])): ?>
                                    <img class="child-photo-small" src="<?= e(url((string) $child['photo'])) ?>" alt="">
                                <?php else: ?>
                                    <span class="child-photo-small child-photo-small-placeholder" aria-hidden="true"><?= e($initial ?: '?') ?></span>
                                <?php endif; ?>
                                <div>
                                    <h2 class="attendance-card-name"><?= e($fullName !== '' ? $fullName : 'کودک') ?></h2>
                                    <p class="attendance-card-meta"><?= e(attendanceAgeFromDob((string) ($child['date_of_birth'] ?? ''))) ?> · <?= e($parentName !== '' ? $parentName : '—') ?></p>
                                </div>
                            </div>
                            <!-- fieldset disabled by default in HTML so controls don't submit without JS;
                                 JS re-enables on mobile viewport -->
                            <fieldset class="attendance-fieldset" disabled data-mobile-fieldset>
                                <legend class="sr-only">وضعیت برای <?= e($fullName) ?></legend>
                                <p class="attendance-field-label">وضعیت</p>
                                <div class="status-radio-group">
                                    <?php renderAttendanceRadios($child, $cid, 'mob'); ?>
                                </div>
                            </fieldset>
                            <div class="time-inputs">
                                <label class="time-input-label">
                                    ورود
                                    <input
                                        class="time-input"
                                        type="time"
                                        name="attendance[<?= e((string) $cid) ?>][check_in]"
                                        value="<?= e(attendanceTimeForInput($child['check_in'] ?? null)) ?>"
                                        disabled
                                    >
                                </label>
                                <label class="time-input-label">
                                    خروج
                                    <input
                                        class="time-input"
                                        type="time"
                                        name="attendance[<?= e((string) $cid) ?>][check_out]"
                                        value="<?= e(attendanceTimeForInput($child['check_out'] ?? null)) ?>"
                                        disabled
                                    >
                                </label>
                            </div>
                            <label class="notes-field-label">
                                یادداشت
                                <textarea
                                    class="notes-field"
                                    name="attendance[<?= e((string) $cid) ?>][notes]"
                                    rows="3"
                                    maxlength="5000"
                                    disabled
                                ><?= e($notesVal) ?></textarea>
                            </label>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="attendance-toolbar attendance-toolbar-bottom">
                <button type="submit" class="btn save-btn">ذخیره حضور و غیاب</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<script>
(function () {
    var mq = window.matchMedia('(min-width: 768px)');
    var tableWrap = document.querySelector('.attendance-table-wrap');
    var cardsWrap = document.querySelector('[data-attendance-mobile]');
    if (!tableWrap || !cardsWrap) {
        return;
    }
    // Mobile card controls + fieldset are disabled in HTML by default (so they
    // don't submit without JS). JS enables the appropriate set by viewport.
    var tableControls = tableWrap.querySelectorAll('input, textarea');
    var cardControls = cardsWrap.querySelectorAll('input, textarea');
    var mobileFieldsets = cardsWrap.querySelectorAll('[data-mobile-fieldset]');
    function apply() {
        var desktop = mq.matches;
        tableControls.forEach(function (el) {
            el.disabled = !desktop;
        });
        cardControls.forEach(function (el) {
            el.disabled = desktop;
        });
        // Toggle the fieldset disabled state so its child radios follow
        mobileFieldsets.forEach(function (fs) {
            fs.disabled = desktop;
        });
    }
    if (typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', apply);
    } else if (typeof mq.addListener === 'function') {
        mq.addListener(apply);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>

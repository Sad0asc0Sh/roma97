<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/operations_pilot.php';

requireLogin();

$now = new DateTimeImmutable();
$today = $now->format('Y-m-d');
$pilotId = filter_var($_GET['pilot'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$errorMessage = getFlash('error');
$successMessage = getFlash('success');
$classrooms = $pilots = $days = $weeks = $summaries = [];
$pilot = null;
$selectedDay = null;
$dayFields = [
    'manager_minutes' => ['دقایق واقعی پیگیری مدیر', 1440],
    'open_cases' => ['موارد باز در پایان روز', 65535],
    'closed_cases' => ['کل کارهای بسته‌شده', 65535],
    'independent_cases' => ['کارهای بسته‌شده توسط مسئول بدون دخالت مدیر', 65535],
];
$phaseLabels = ['before' => 'هفتهٔ قبل', 'after' => 'هفتهٔ بعد'];

try {
    $pdo = getDb();
    ensureOperationsPilotTables($pdo);

    if (isPostRequest()) {
        $pilotId = filter_var($_POST['pilot_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new InvalidArgumentException('اعتبار فرم پایان یافته است. صفحه را دوباره باز کنید.');
        }

        $action = $_POST['action'] ?? null;
        if ($action === 'create_pilot') {
            $classroomId = filter_var($_POST['classroom_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $dateInput = $_POST['change_date'] ?? null;
            $changeDate = is_string($dateInput) ? parseIranDate($dateInput) : null;
            if ($classroomId === false || $changeDate === null) {
                throw new InvalidArgumentException('کلاس و تاریخ شروع شیوهٔ جدید را انتخاب کنید.');
            }
            $pilotId = createOperationsPilot($pdo, $classroomId, $changeDate);
            recordAudit('operations_pilot.create', 'operations_pilot', $pilotId, ['classroom_id' => $classroomId, 'change_date' => $changeDate]);
            setFlash('success', 'آزمون کلاس ثبت شد.');
        } elseif ($action === 'save_day') {
            $pilotStmt = $pdo->prepare('SELECT change_date FROM operations_pilots WHERE id = ?');
            $pilotStmt->execute([$pilotId]);
            $changeDate = $pilotStmt->fetchColumn();
            $day = $_POST['work_date'] ?? null;
            $dayKind = $_POST['day_kind'] ?? null;
            if (!is_string($changeDate) || !is_string($day) || $day > $today
                || !in_array($day, array_merge(...array_values(operationsPilotWeeks($changeDate))), true)
                || !in_array($dayKind, ['worked', 'closed'], true)) {
                throw new InvalidArgumentException('تاریخ و وضعیت یکی از روزهای آزمون را انتخاب کنید.');
            }
            $worked = $dayKind === 'worked';
            $values = [];
            foreach ($dayFields as $key => [$label, $max]) {
                $value = $worked ? filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 0, 'max_range' => $max]]) : 0;
                if ($value === false) {
                    throw new InvalidArgumentException('مقدار «' . $label . '» را وارد کنید. اگر موردی وجود نداشت، صفر بزنید.');
                }
                $values[$key] = $value;
            }
            if ($values['independent_cases'] > $values['closed_cases']) {
                throw new InvalidArgumentException('تعداد کارهای بسته‌شده توسط مسئول باید حداکثر برابر کل کارهای بسته‌شده باشد.');
            }
            saveOperationsPilotDay($pdo, $pilotId, $day, $worked, $values);
            recordAudit('operations_pilot.save_day', 'operations_pilot', $pilotId, ['date' => $day, 'worked' => $worked]);
            setFlash('success', 'نتیجهٔ روز ذخیره شد.');
        } else {
            throw new InvalidArgumentException('درخواست معتبر نیست.');
        }
        redirect(url('admin/operations-pilot.php?' . http_build_query(['pilot' => $pilotId, 'day' => $day ?? null])));
    }

    $classrooms = $pdo->query('SELECT id, name FROM classrooms ORDER BY name')->fetchAll();
    $pilots = $pdo->query(
        'SELECT p.id, p.change_date, p.created_at, c.name FROM operations_pilots p
         INNER JOIN classrooms c ON c.id = p.classroom_id ORDER BY p.created_at DESC, p.id DESC'
    )->fetchAll();
    $pilot = array_column($pilots, null, 'id')[$pilotId] ?? $pilots[0] ?? null;
    if ($pilot !== null) {
        $pilotId = (int) $pilot['id'];
        $weeks = operationsPilotWeeks($pilot['change_date']);
        $dayStmt = $pdo->prepare('SELECT * FROM operations_pilot_days WHERE pilot_id = ?');
        $dayStmt->execute([$pilotId]);
        $days = array_column($dayStmt->fetchAll(), null, 'work_date');
        $reportStmt = $pdo->prepare('SELECT published_at, work_ms, first_viewed_at FROM operations_pilot_reports WHERE pilot_id = ?');
        $reportStmt->execute([$pilotId]);
        $reports = $reportStmt->fetchAll();
        foreach ($weeks as $phase => $dates) {
            $summaries[$phase] = operationsPilotSummary($dates, $days, $reports, $now);
        }

        $passedDays = array_values(array_filter(array_merge(...array_values($weeks)), static fn (string $day): bool => $day <= $today));
        $requestedDay = $_GET['day'] ?? null;
        $selectedDay = in_array($requestedDay, $passedDays, true) ? $requestedDay : ($passedDays[count($passedDays) - 1] ?? null);
    }
} catch (Throwable $exception) {
    if (!$exception instanceof InvalidArgumentException) {
        error_log('operations pilot: ' . $exception->getMessage());
    }
    $errorMessage = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'بارگذاری یا ذخیرهٔ آزمون انجام نشد. دوباره تلاش کنید.';
    if (isPostRequest()) {
        setFlash('error', $errorMessage);
        redirect(url('admin/operations-pilot.php?' . http_build_query(['pilot' => $pilotId ?: null, 'day' => is_string($day ?? null) ? $day : null])));
    }
    $pilot = null;
}

$comparisonMetrics = [
    'manager_minutes' => ['دقایق پیگیری مدیر', 'دقیقه در هر روز کاری'],
    'open_cases' => ['موارد باز پایان روز', 'مورد در هر روز کاری'],
    'independent_cases' => ['بسته‌شده توسط مسئول بدون دخالت مدیر', '٪'],
    'report_views' => ['مشاهدهٔ گزارش روزانه توسط والدین', '٪'],
    'report_minutes' => ['زمان متوسط ثبت گزارش توسط مربی', 'دقیقه برای هر گزارش'],
];
$pageTitle = 'آزمون میدانی کلاس | ' . siteName();
require __DIR__ . '/header.php';
?>
<section class="dashboard">
    <div class="adm-page-head">
        <div class="adm-page-head-main">
            <h1>آزمون میدانی کلاس</h1>
            <p class="adm-page-subtitle">سنجش پیش و پس از تغییر شیوهٔ کاری کلاس‌ها با شاخص‌های خودکار و ثبت روزانه</p>
        </div>
    </div>
    <?php if ($errorMessage !== null): ?><p class="alert" role="alert"><?= e($errorMessage) ?></p><?php endif; ?>
    <?php if ($successMessage !== null): ?><p class="notice" role="status"><?= e($successMessage) ?></p><?php endif; ?>

    <?php if ($pilot !== null): ?>
        <section class="admin-section pilot-comparison" aria-labelledby="pilotTitle">
            <div class="admin-section-header invoice-toolbar">
                <h2 class="admin-section-title" id="pilotTitle">مقایسهٔ کلاس <?= e($pilot['name']) ?></h2>
                <form method="get" action="<?= e(url('admin/operations-pilot.php')) ?>" class="invoice-toolbar">
                    <label>آزمون
                        <select name="pilot" class="form-control">
                            <?php foreach ($pilots as $item): ?>
                                <option value="<?= e($item['id']) ?>" <?= (int) $item['id'] === $pilotId ? 'selected' : '' ?>>
                                    <?= e($item['name'] . ' · ' . shamsiDate($item['change_date'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="btn btn-secondary">نمایش</button>
                </form>
            </div>
            <?php if (substr($pilot['created_at'], 0, 10) > $weeks['before'][0]): ?>
                <p class="notice">پوشش ثبت خودکار از <?= e(shamsiDate($pilot['created_at'])) ?> آغاز شده است. شاخص‌های مشاهده و زمان ثبت بر پایهٔ گزارش‌های ثبت‌شده از آن روز محاسبه می‌شوند.</p>
            <?php endif; ?>
            <div class="admin-table-wrap">
                <table class="admin-table pilot-table">
                    <colgroup><col><col><col></colgroup>
                    <thead><tr>
                        <th scope="col">شاخص</th>
                        <?php foreach ($weeks as $phase => $dates): ?>
                            <th scope="col">
                                <?= e($phaseLabels[$phase]) ?><small><?= e(shamsiDate($dates[0], 'numeric') . ' تا ' . shamsiDate($dates[6], 'numeric')) ?></small>
                                <small><?= e(persianNumber($summaries[$phase]['days_recorded'])) ?> از ۷ روز ثبت شده</small>
                            </th>
                        <?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($comparisonMetrics as $key => [$label, $unit]): ?>
                            <tr data-pilot-metric="<?= e($key) ?>">
                                <th scope="row"><?= e($label) ?></th>
                                <?php foreach ($summaries as $phase => $summary): ?>
                                    <td data-pilot-phase="<?= e($phase) ?>">
                                        <?php if ($summary[$key] === null): ?>—<?php else: ?>
                                            <strong><?= e(persianNumber(number_format($summary[$key], 1))) ?></strong> <?= e($unit) ?>
                                        <?php endif; ?>
                                        <?php if ($key === 'manager_minutes' && $summary[$key] !== null): ?>
                                            <small>جمع ثبت‌شده <?= e(persianNumber($summary['manager_minutes_total'])) ?> دقیقه</small>
                                        <?php elseif ($key === 'independent_cases' && $summary['closed'] > 0): ?>
                                            <small><?= e(persianNumber($summary['independent'])) ?> از <?= e(persianNumber($summary['closed'])) ?> کار</small>
                                        <?php elseif ($key === 'report_views'): ?>
                                            <small><?= e(persianNumber($summary['views'])) ?> از <?= e(persianNumber($summary['measurable_views'])) ?> گزارش با مهلت ۴۸ساعتهٔ کامل</small>
                                        <?php elseif ($key === 'report_minutes'): ?>
                                            <small><?= e(persianNumber($summary['timed'])) ?> از <?= e(persianNumber($summary['published'])) ?> گزارش زمان‌سنجی شده</small>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <details class="invoice-bulk">
                <summary>روش اندازه‌گیری</summary>
                <p>ثبت خودکار این آزمون از <?= e(shamsiDate($pilot['created_at'], 'numeric')) ?> در دسترس است. نرخ مشاهده، سهم گزارش‌های فردی است که در ۴۸ ساعت اول در پنل والد دیده شده‌اند. زمان ثبت، زمان کار در فرم تا نخستین ذخیرهٔ گزارش است و وقفه‌ها بیش از یک دقیقه حساب نمی‌شوند. خط تیره نشان‌دهندهٔ دادهٔ ناکافی است.</p>
                <p>نتیجهٔ دستی را پایان هر روز ثبت کنید. در هر دو هفته، کارهای همین کلاس در پذیرش، تحویل، ارتباط با خانواده و شهریه را یکسان بشمارید. روزهای تعطیل از میانگین روز کاری کنار گذاشته می‌شوند.</p>
            </details>
        </section>

        <section class="admin-section" id="pilotDay" aria-labelledby="pilotDayTitle">
            <h2 class="admin-section-title" id="pilotDayTitle">ثبت نتیجهٔ روز</h2>
            <?php if ($selectedDay !== null): ?>
                <?php foreach ($weeks as $phase => $dates): ?>
                    <div class="pilot-days" aria-label="<?= e($phaseLabels[$phase]) ?>">
                        <strong><?= e($phaseLabels[$phase]) ?></strong>
                        <?php foreach ($dates as $day): ?>
                            <?php if ($day <= $today): ?>
                                <a class="btn <?= $day === $selectedDay ? 'btn-primary' : 'btn-outline' ?> btn-sm"
                                   href="<?= e(url('admin/operations-pilot.php?pilot=' . $pilotId . '&day=' . $day . '#pilotDay')) ?>"
                                   <?= $day === $selectedDay ? 'aria-current="date"' : '' ?>><?= e(shamsiDate($day, 'numeric')) ?><?= isset($days[$day]) ? ' ✓' : '' ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <?php $entry = $days[$selectedDay] ?? null; ?>
                <form method="post" action="<?= e(url('admin/operations-pilot.php#pilotDay')) ?>" class="pilot-day-form">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="save_day">
                    <input type="hidden" name="pilot_id" value="<?= e($pilotId) ?>">
                    <input type="hidden" name="work_date" value="<?= e($selectedDay) ?>">
                    <label>وضعیت روز
                        <select name="day_kind" class="form-control">
                            <option value="worked">روز کاری</option>
                            <option value="closed" <?= $entry !== null && !(bool) $entry['worked'] ? 'selected' : '' ?>>تعطیل</option>
                        </select>
                    </label>
                    <?php foreach ($dayFields as $name => [$label, $max]): ?>
                        <label><?= e($label) ?>
                            <input class="form-control" type="number" name="<?= e($name) ?>" min="0" max="<?= e($max) ?>" value="<?= e(!empty($entry['worked']) ? $entry[$name] : '') ?>">
                        </label>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary">ذخیره نتیجهٔ روز</button>
                </form>
            <?php else: ?>
                <p class="muted">ثبت نتیجهٔ روز از <?= e(shamsiDate($weeks['before'][0])) ?> در دسترس است.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($classrooms !== []): ?>
        <section class="admin-section" aria-labelledby="pilotNewTitle">
            <h2 class="admin-section-title" id="pilotNewTitle">شروع آزمون یک‌کلاسه</h2>
            <p class="muted">یک کلاس و روز شروع شیوهٔ جدید را انتخاب کنید. ثبت داده‌های پایه از هفت روز پیش از آن آغاز می‌شود.</p>
            <form method="post" class="invoice-toolbar">
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_pilot">
                <label>کلاس
                    <select name="classroom_id" class="form-control" required>
                        <option value="">انتخاب کلاس</option>
                        <?php foreach ($classrooms as $classroom): ?>
                            <option value="<?= e($classroom['id']) ?>"><?= e($classroom['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>روز شروع شیوهٔ جدید (شمسی)
                    <input type="text" name="change_date" class="form-control shamsi-datepicker" <?= iranPickerAttributes() ?> required value="<?= e(shamsiDate($now->modify('+7 days')->format('Y-m-d'), 'numeric')) ?>">
                </label>
                <button type="submit" class="btn btn-primary">ثبت آزمون کلاس</button>
            </form>
        </section>
    <?php elseif ($errorMessage === null): ?>
        <p class="muted">برای شروع آزمون ابتدا کلاس مورد نظر را در سامانه ثبت کنید.</p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/footer.php'; ?>

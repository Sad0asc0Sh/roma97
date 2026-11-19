<?php
declare(strict_types=1);

/**
 * Teacher portal helpers — catalogs, date/time rules, classroom context.
 *
 * Regression tests for includes/teacher_portal_helpers.php, the file
 * the coach-portal refactor's pages require: the psychological
 * catalogs must stay consistent with the daily_reports columns, the
 * operational-date window must reject future/too-old dates, and the
 * classroom context + child allow-list must scope strictly to the
 * logged-in teacher (the portal's real data boundary).
 *
 * DB parts run on in-memory SQLite; the helpers use portable SQL.
 *
 * Run: php tests/teacher-helpers-test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-bootstrap.php';

defined('ROOMA_APP') || define('ROOMA_APP', true);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/functions.php';
require_once $projectRoot . '/includes/teacher_portal_helpers.php';

echo "Teacher portal helpers tests\n";

// ── A. Mood catalog: quick keys + legacy keys, complete metadata ───────────
$moods = teacherPsychologicalMoodCatalog();
foreach (['happy', 'normal', 'tired', 'restless', 'mixed', 'challenging'] as $key) {
    testAssert(isset($moods[$key]), "mood catalog has '{$key}'");
    testAssert(
        ($moods[$key]['label'] ?? '') !== '' && ($moods[$key]['clinical_desc'] ?? '') !== ''
        && ($moods[$key]['coach_tip'] ?? '') !== '' && ($moods[$key]['sms_phrase'] ?? '') !== '',
        "mood '{$key}' carries label + clinical desc + coach tip + sms phrase"
    );
}
testAssert(teacherMoodLabel('happy') === $moods['happy']['label'], 'mood label resolves from catalog');
testAssert(teacherMoodLabel(null) === 'ثبت نشده', 'missing mood reads as unrecorded');
testAssert(str_contains(teacherMoodFullLabel('tired'), $moods['tired']['clinical_desc']), 'full mood label includes clinical description');
testAssert(teacherMoodBadgeClass('happy') === 'tp-badge-present', 'happy mood badge is the positive variant');

// ── B. Six factors: keys match the daily_reports psych columns ─────────────
$factors = teacherPsychologicalFactorsCatalog();
$expectedFactors = ['social_interaction', 'focus_level', 'communication_style', 'separation_adaptation', 'nutrition_status', 'rest_energy'];
testAssert(array_keys($factors) === $expectedFactors, 'factor catalog keys match the daily_reports columns in order');

$schema = (string) file_get_contents($projectRoot . '/schema.sql');
foreach ($expectedFactors as $fKey) {
    testAssert(count($factors[$fKey]['options']) >= 3, "factor {$fKey} offers at least 3 observations");
    testAssert(str_contains($schema, $fKey), "schema.sql defines daily_reports.{$fKey}");
    $sampleOption = array_key_first($factors[$fKey]['options']);
    testAssert(
        teacherPsychFactorLabel($fKey, $sampleOption) === $factors[$fKey]['options'][$sampleOption],
        "factor {$fKey} option label round-trips"
    );
}
testAssert(teacherPsychFactorLabel('focus_level', '') === 'ثبت نشده', 'empty factor value reads as unrecorded');

// ── C. Activities bank, note templates, non-labeling dictionary ────────────
$activities = teacherDevelopmentalActivitiesCatalog();
testAssert(count($activities) >= 3, 'activities bank spans at least 3 developmental domains');
foreach ($activities as $domain => $items) {
    testAssert(is_array($items) && count($items) >= 2, "activities domain '{$domain}' has ready items");
}
foreach (teacherStrengthBasedNoteTemplates() as $tpl) {
    testAssert(($tpl['title'] ?? '') !== '' && ($tpl['text'] ?? '') !== '', 'note template carries title + text');
}
foreach (teacherNonLabelingDictionary() as $entry) {
    testAssert(($entry['avoid'] ?? '') !== '' && ($entry['use'] ?? '') !== '' && ($entry['reason'] ?? '') !== '', 'dictionary entry carries avoid/use/reason');
}

// ── D. Operational dates: today & window ok, future & too-old rejected ─────
$today = date('Y-m-d');
testAssert(teacherValidOperationalDate($today) === $today, 'today is a valid operational date');
testAssert(teacherValidOperationalDate(date('Y-m-d', strtotime('-7 days'))) !== null, 'date 7 days back is inside the window');
testAssert(teacherValidOperationalDate(date('Y-m-d', strtotime('-8 days'))) === null, 'date 8 days back is rejected');
testAssert(teacherValidOperationalDate(date('Y-m-d', strtotime('+1 day'))) === null, 'future date is rejected');
testAssert(teacherValidOperationalDate('not-a-date') === null, 'garbage date is rejected');
testAssert(teacherValidOperationalDate('') === null, 'empty date is rejected');

[$jy, $jm, $jd] = gregorianToJalali((int) date('Y'), (int) date('n'), (int) date('j'));
$jalaliToday = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
testAssert(teacherValidOperationalDate($jalaliToday) === $today, 'Jalali form of today resolves to the same ISO date');

// ── E. Time normalisation ───────────────────────────────────────────────────
testAssert(teacherNormalizeTimeInput('07:05') === '07:05:00', 'HH:MM normalises to SQL time');
testAssert(teacherNormalizeTimeInput('07:05:30') === '07:05:30', 'HH:MM:SS passes through');
testAssert(teacherNormalizeTimeInput('24:00') === null, 'invalid hour rejected');
testAssert(teacherNormalizeTimeInput('') === null && teacherNormalizeTimeInput(null) === null, 'empty time is null');
testAssert(teacherFormatTimeShort('07:05:00') === '07:05', 'SQL time shortens to HH:MM');
testAssert(teacherFormatTimeShort(null) === '', 'missing time renders empty');

// ── F. Labels & badges ──────────────────────────────────────────────────────
testAssert(teacherAttendanceStatusLabel('present') === 'حاضر', 'attendance label: present');
testAssert(teacherAttendanceStatusLabel(null) === 'ثبت نشده', 'attendance label: unrecorded');
testAssert(teacherAttendanceBadgeClass('absent') === 'tp-badge-absent', 'attendance badge: absent');
testAssert(teacherChildGenderLabel('female') === 'دختر' && teacherChildGenderLabel('male') === 'پسر', 'gender labels');
testAssert(teacherEventCategoryLabel('celebration') === 'جشن', 'event category label');

// ── G. Classroom context & allow-list scoping (SQLite) ──────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE classrooms (id INTEGER PRIMARY KEY, teacher_id INTEGER, name TEXT, description TEXT, capacity INTEGER, schedule TEXT)");
$pdo->exec("CREATE TABLE children (id INTEGER PRIMARY KEY, status TEXT)");
$pdo->exec("CREATE TABLE child_classroom (child_id INTEGER, classroom_id INTEGER)");
$pdo->exec("INSERT INTO classrooms (id, teacher_id, name) VALUES (1, 10, 'شکوفه‌ها'), (2, 10, 'غنچه‌ها'), (3, 99, 'کلاس دیگری')");
$pdo->exec("INSERT INTO children (id, status) VALUES (100, 'active'), (101, 'active'), (102, 'inactive')");
$pdo->exec("INSERT INTO child_classroom (child_id, classroom_id) VALUES (100, 1), (101, 1), (102, 1)");

$_SESSION = [];
$_GET = [];
$ctx = teacherResolveClassroomContext($pdo, 10);
testAssert(count($ctx['classrooms']) === 2, 'context lists only the teacher’s own classrooms');
testAssert($ctx['active_id'] === 1 && (int) $ctx['active']['active_children_count'] === 2, 'default active classroom is the first, with active-child count');

$_GET['classroom_id'] = '2';
$ctx = teacherResolveClassroomContext($pdo, 10);
testAssert($ctx['active_id'] === 2, 'explicit classroom_id switches the active classroom');
testAssert((int) ($_SESSION['teacher_classroom_id'] ?? 0) === 2, 'switch is remembered in session');

$_GET['classroom_id'] = '3';
$ctx = teacherResolveClassroomContext($pdo, 10);
testAssert($ctx['active_id'] === 2, 'another teacher’s classroom_id is ignored (session choice kept)');

$_GET = [];
$_SESSION = [];
$ctx = teacherResolveClassroomContext($pdo, 77);
testAssert($ctx['active'] === null && $ctx['active_id'] === 0, 'teacher without classrooms gets an empty context');

$allowed = teacherAllowedChildIds($pdo, 1);
testAssert(isset($allowed[100]) && isset($allowed[101]) && !isset($allowed[102]), 'allow-list holds exactly the active children of the classroom');

exit(testSummary('teacher-helpers-test'));

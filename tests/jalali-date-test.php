<?php
declare(strict_types=1);

/**
 * Calendar and wall-clock regressions at Nowruz, leap days, and monthly boundaries.
 *
 * Pure test: no database needed.
 *
 * Run: php tests/jalali-date-test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-bootstrap.php';

echo "Jalali date parsing tests\n";

// ─── The reported bug: Persian digits were rejected ─────────────────────────
testAssert(parseJalaliDate('۱۴۰۵/۰۵/۱۸') !== null, 'Persian-digit date parses (was the reported bug)');
testAssert(parseJalaliDate('۱۴۰۵/۰۱/۰۱') === '2026-03-21', 'Persian digits: ۱۴۰۵/۰۱/۰۱ → 2026-03-21 (Nowruz 1405)');
testAssert(parseJalaliDate('١٤٠٥/٠١/٠١') === '2026-03-21', 'Arabic-Indic digits: ١٤٠٥/٠١/٠١ → 2026-03-21');
testAssert(parseJalaliDate('  ۱۴۰۵/۰۱/۰۱  ') === '2026-03-21', 'surrounding whitespace is trimmed');

// ─── Latin digits still work, both separators ───────────────────────────────
testAssert(parseJalaliDate('1405/01/01') === '2026-03-21', 'Latin digits with slashes');
testAssert(parseJalaliDate('1405-01-01') === '2026-03-21', 'Latin digits with dashes');
testAssert(parseJalaliDate('1405/1/1') === '2026-03-21', 'single-digit month/day accepted');
testAssert(parseJalaliDate('۱۴۰۵۰۱۰۱') === '2026-03-21', 'numeric keyboard input without separators parses');

// ─── Round-trip: parse ∘ convert is the identity on valid dates ─────────────
$validDates = ['1405/01/01', '۱۴۰۵/۰۵/۱۸', '1403/12/30', '1404/12/29', '1400/07/15', '1399/12/30'];
foreach ($validDates as $input) {
    $gregorian = parseJalaliDate($input);
    testAssert($gregorian !== null, "round-trip input parses: {$input}");
    if ($gregorian === null) {
        continue;
    }
    [$gy, $gm, $gd] = array_map('intval', explode('-', $gregorian));
    [$jy, $jm, $jd] = gregorianToJalali($gy, $gm, $gd);
    $normalized = calendarDigits($input);
    [$inJy, $inJm, $inJd] = array_map('intval', preg_split('#[/\-]#', trim($normalized)));
    testAssert(
        $jy === $inJy && $jm === $inJm && $jd === $inJd,
        "round-trip identity: {$input} → {$gregorian} → {$jy}/{$jm}/{$jd}"
    );
}

// ─── Impossible days are rejected (fail closed) ─────────────────────────────
testAssert(parseJalaliDate('1404/12/30') === null, '1404/12/30 rejected (1404 is not a leap year)');
testAssert(parseJalaliDate('۱۴۰۴/۱۲/۳۰') === null, 'Persian-digit ۱۴۰۴/۱۲/۳۰ rejected too');
testAssert(parseJalaliDate('1405/07/31') === null, '1405/07/31 rejected (Mehr has 30 days)');
testAssert(parseJalaliDate('1405/11/31') === null, '1405/11/31 rejected (Bahman has 30 days)');
testAssert(parseJalaliDate('1405/06/31') !== null, '1405/06/31 accepted (Shahrivar has 31 days)');
testAssert(parseJalaliDate('1403/12/30') !== null, '1403/12/30 accepted (1403 is a leap year)');
testAssert(parseJalaliDate('1405/13/01') === null, 'month 13 rejected');
testAssert(parseJalaliDate('1405/00/10') === null, 'month 0 rejected');
testAssert(parseJalaliDate('1405/05/00') === null, 'day 0 rejected');
testAssert(parseJalaliDate('1405/05/32') === null, 'day 32 rejected');

// ─── Garbage in → null out ──────────────────────────────────────────────────
testAssert(parseJalaliDate('') === null, 'empty string → null');
testAssert(parseJalaliDate('not-a-date') === null, 'non-date string → null');
testAssert(parseJalaliDate('1405/05') === null, 'incomplete date → null');
testAssert(parseJalaliDate('1405/05/18/20') === null, 'extra segment → null');

testAssert(parseJalaliDate('2026/03/21') === null, 'Gregorian input is outside the Persian date range');
testAssert(parseIranDate('2026-03-21') === '2026-03-21', 'ISO date from an existing portal link remains valid');
testAssert(parseIranDate('2026-02-30') === null, 'invalid ISO date is rejected');
testAssert(parseIranDate('1404-12-30') === null, 'an impossible Shamsi date cannot fall back to Gregorian');

// ─── Leap-year helpers ──────────────────────────────────────────────────────
testAssert(isJalaliLeapYear(1403) === true, '1403 is a leap year');
testAssert(isJalaliLeapYear(1404) === false, '1404 is not a leap year');
testAssert(isJalaliLeapYear(1399) === true, '1399 is a leap year');
testAssert(isJalaliLeapYear(1400) === false, '1400 is not a leap year');
testAssert(isJalaliLeapYear(1408) === true, '1408 is a leap year');
testAssert(isJalaliLeapYear(1412) === true, '1412 is a leap year');
testAssert(jalaliMonthLength(1404, 12) === 29, 'Esfand 1404 has 29 days');
testAssert(jalaliMonthLength(1403, 12) === 30, 'Esfand 1403 has 30 days');
testAssert(jalaliMonthLength(1405, 1) === 31, 'Farvardin has 31 days');
testAssert(jalaliMonthLength(1405, 6) === 31, 'Shahrivar has 31 days');
testAssert(jalaliMonthLength(1405, 7) === 30, 'Mehr has 30 days');
testAssert(jalaliMonthLength(1405, 11) === 30, 'Bahman has 30 days');

testAssert(parseJalaliDate('۱۴۰۴/۰۱/۰۱') === '2025-03-21', 'Nowruz 1404 is March 21');
testAssert(parseJalaliDate('۱۴۰۳/۱۲/۳۰') === '2025-03-20', 'leap Esfand 1403 ends on March 20');
$nowruzTehran = gmmktime(20, 30, 0, 3, 20, 2026);
testAssert(shamsiDate($nowruzTehran - 60, 'numeric') === '۱۴۰۴/۱۲/۲۹'
    && shamsiDate($nowruzTehran - 60, 'time') === '۲۳:۵۹', 'one minute before Nowruz in Tehran belongs to the old year');
testAssert(shamsiDate($nowruzTehran, 'with_day_time') === 'شنبه ۱ فروردین ۱۴۰۵ · ۰۰:۰۰', 'Tehran midnight starts the new year');

testAssert(iranWeekStart(new DateTimeImmutable('2026-03-20'))->format('Y-m-d') === '2026-03-14', 'Friday is the end of the Iranian week');
testAssert(iranWeekStart(new DateTimeImmutable('2026-03-21'))->format('Y-m-d') === '2026-03-21', 'Saturday starts the Iranian week');
testAssert(portalMonthYear('۱۴۰۵/۱') === '1405-01' && portalMonthYear('1405-13') === null, 'financial month validates and normalizes Persian digits');
testAssert(shiftJalaliMonth('1405-01', -1) === '1404-12', 'trend month steps back across Nowruz');
testAssert(jalaliMonthToGregorianRange('1403-12') === ['2025-02-19', '2025-03-20'], 'leap Esfand includes its last day');
testAssert(jalaliMonthToGregorianRange('1405-01') === ['2026-03-21', '2026-04-20'], 'Farvardin expense range is inclusive');
testAssert(formatShamsiMonthYear('2026-03') === '۱۴۰۴/۱۲/۱۰ تا ۱۴۰۵/۰۱/۱۱', 'historical billing key displays its full Shamsi span');

testAssert(parseIranTime('۰۷:۰۵') === '07:05:00', 'Persian minutes convert to SQL time');
testAssert(parseIranTime('١٦٣٠') === '16:30:00', 'Arabic-Indic digits work with mobile numeric entry');
testAssert(parseIranTime('23:59:59') === '23:59:59', 'seconds are preserved');
testAssert(parseIranTime('۲۴:۰۰') === null && parseIranTime('16:60') === null, 'invalid hours and minutes are rejected');
testAssert(parseIranTime('04:30 PM') === null && parseIranTime('۰۴:۳۰ ب.ظ') === null, '12-hour markers are rejected');
testAssert(parseIranTime('') === null && parseIranTime(null) === null, 'empty time is null');
testAssert(formatIranTime('16:30:25') === '۱۶:۳۰' && iranTimeForInput('16:30:25') === '۱۶:۳۰:۲۵', 'forms round-trip seconds and the display uses Persian digits');

exit(testSummary('jalali-date-test'));

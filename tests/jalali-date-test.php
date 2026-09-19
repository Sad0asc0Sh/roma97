<?php
declare(strict_types=1);

/**
 * Tests for the Jalali date fixes (includes/functions.php):
 *  - parseJalaliDate() accepts Persian/Arabic-Indic digits
 *  - parseJalaliDate() rejects impossible days (Esfand 30 in non-leap years, …)
 *    instead of silently rolling them into the next year
 *  - isJalaliLeapYear() / jalaliMonthLength() follow the 33-year cycle
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
    $normalized = strtr($input, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
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

// ─── Gregorian passthrough (> 1700) is validated, not blindly trusted ──────
testAssert(parseJalaliDate('2026/03/21') === '2026-03-21', 'valid Gregorian date passes through');
testAssert(parseJalaliDate('2026/02/30') === null, '2026/02/30 rejected (was silently accepted before)');
testAssert(parseJalaliDate('2026/13/01') === null, 'Gregorian month 13 rejected');

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

exit(testSummary('jalali-date-test'));

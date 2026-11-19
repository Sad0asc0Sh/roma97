<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

const IRAN_MONTHS = [
    1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
];
const IRAN_DATE_MIN_YEAR = 1000;
const IRAN_DATE_MAX_YEAR = 1700;

function calendarDigits(string $value): string
{
    return strtr(trim($value), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

/**
 * The Gregorian date of Nowruz, using Borkowski's break-year calculation.
 * UTC here is only for arithmetic on civil dates, independent of clock changes.
 * https://www.astro.uni.torun.pl/~kb/Papers/EMP/PersianC-EMP.htm
 */
function jalaliYearStart(int $year): DateTimeImmutable
{
    static $starts = [];
    if (isset($starts[$year])) {
        return $starts[$year];
    }
    if ($year < -61 || $year >= 3178) {
        throw new OutOfRangeException('Jalali year outside the calendar calculation range');
    }

    $breaks = [9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
    $gregorianYear = $year + 621;
    $leaps = -14;
    $previous = -61;
    foreach ($breaks as $break) {
        $gap = $break - $previous;
        if ($year < $break) {
            break;
        }
        $leaps += intdiv($gap, 33) * 8 + intdiv($gap % 33, 4);
        $previous = $break;
    }
    $yearsSinceBreak = $year - $previous;
    $leaps += intdiv($yearsSinceBreak, 33) * 8 + intdiv($yearsSinceBreak % 33 + 3, 4);
    if ($gap % 33 === 4 && $gap - $yearsSinceBreak === 4) {
        $leaps++;
    }
    $gregorianLeaps = intdiv($gregorianYear, 4) - intdiv((intdiv($gregorianYear, 100) + 1) * 3, 4) - 150;
    $marchDay = 20 + $leaps - $gregorianLeaps;
    return $starts[$year] = new DateTimeImmutable(sprintf('%04d-03-%02d', $gregorianYear, $marchDay), new DateTimeZone('UTC'));
}

/** @return array{int, int, int} */
function jalaliToGregorian(int $jy, int $jm, int $jd): array
{
    $offset = $jm <= 6 ? ($jm - 1) * 31 : 186 + ($jm - 7) * 30;
    $date = jalaliYearStart($jy)->modify('+' . ($offset + $jd - 1) . ' days');
    return [(int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j')];
}

/** @return array{int, int, int} */
function gregorianToJalali(int $gy, int $gm, int $gd): array
{
    $date = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $gy, $gm, $gd), new DateTimeZone('UTC'));
    $jy = $gy - 621;
    $start = jalaliYearStart($jy);
    if ($date < $start) {
        $start = jalaliYearStart(--$jy);
    }
    $offset = (int) $start->diff($date)->days;
    return $offset < 186
        ? [$jy, 1 + intdiv($offset, 31), 1 + $offset % 31]
        : [$jy, 7 + intdiv($offset - 186, 30), 1 + ($offset - 186) % 30];
}

function isJalaliLeapYear(int $year): bool
{
    return jalaliYearStart($year)->diff(jalaliYearStart($year + 1))->days === 366;
}

function jalaliMonthLength(int $year, int $month): int
{
    return match (true) {
        $month >= 1 && $month <= 6 => 31,
        $month >= 7 && $month <= 11 => 30,
        $month === 12 => isJalaliLeapYear($year) ? 30 : 29,
        default => 0,
    };
}

function shamsiMonthName(int $month): string
{
    return IRAN_MONTHS[$month] ?? '';
}

/** Convert a Persian date entered by a user to the Gregorian SQL DATE format. */
function parseJalaliDate(string $value): ?string
{
    $value = calendarDigits($value);
    if (preg_match('/\A([0-9]{4})([\/-])([0-9]{1,2})\2([0-9]{1,2})\z/', $value, $parts) === 1) {
        [, $year, , $month, $day] = $parts;
    } elseif (preg_match('/\A([0-9]{4})([0-9]{2})([0-9]{2})\z/', $value, $parts) === 1) {
        [, $year, $month, $day] = $parts;
    } else {
        return null;
    }

    $year = (int) $year;
    $month = (int) $month;
    $day = (int) $day;
    if ($year < IRAN_DATE_MIN_YEAR || $year > IRAN_DATE_MAX_YEAR || $day < 1 || $day > jalaliMonthLength($year, $month)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', ...jalaliToGregorian($year, $month, $day));
}

/** Read a date from a portal link or form, including ISO dates from older versions. */
function parseIranDate(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts) === 1
        && (int) $parts[1] > IRAN_DATE_MAX_YEAR) {
        return (int) $parts[1] <= 3798 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }
    return parseJalaliDate($value);
}

/** SQL calendar dates and wall-clock datetimes use Iran time by default. */
function iranDateTime(string|int|null $value, ?DateTimeZone $sourceZone = null): ?DateTimeImmutable
{
    $zone = new DateTimeZone(IRAN_TIMEZONE);
    if (is_int($value)) {
        return (new DateTimeImmutable('@' . $value))->setTimezone($zone);
    }
    if ($value === null || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}(?: [0-9]{2}:[0-9]{2}:[0-9]{2})?\z/', $value) !== 1) {
        return null;
    }
    $format = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $sourceZone ?? $zone);
    return $date !== false && $date->format($format) === $value ? $date->setTimezone($zone) : null;
}

function persianDayName(string|int $value): string
{
    $days = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه'];
    $date = iranDateTime($value);
    return $date === null ? '—' : $days[(int) $date->format('w')];
}

function shamsiDate(string|int|null $value, string $format = 'full'): string
{
    $date = iranDateTime($value);
    if ($date === null || (int) $date->format('Y') < 561 || (int) $date->format('Y') > 3798) {
        return '—';
    }
    [$year, $month, $day] = gregorianToJalali((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));
    $full = persianNumber($day) . ' ' . shamsiMonthName($month) . ' ' . persianNumber($year);
    $time = persianNumber($date->format('H:i'));
    return match ($format) {
        'with_time' => $full . ' · ' . $time,
        'with_day' => persianDayName($value) . ' ' . $full,
        'with_day_time' => persianDayName($value) . ' ' . $full . ' · ' . $time,
        'short', 'numeric' => persianNumber(sprintf('%04d/%02d/%02d', $year, $month, $day)),
        'time' => $time,
        'Y' => persianNumber($year),
        'Y-m-d' => sprintf('%04d-%02d-%02d', $year, $month, $day),
        'Y-m' => sprintf('%04d-%02d', $year, $month),
        default => $full,
    };
}

function iranWeekStart(DateTimeImmutable $date): DateTimeImmutable
{
    $date = $date->setTimezone(new DateTimeZone(IRAN_TIMEZONE))->setTime(0, 0);
    return $date->modify('-' . (((int) $date->format('w') + 1) % 7) . ' days');
}

function earliestChildBirthDate(): string
{
    $today = new DateTimeImmutable('today');
    [$year, $month, $day] = gregorianToJalali((int) $today->format('Y'), (int) $today->format('n'), (int) $today->format('j'));
    $year -= 15;
    return sprintf('%04d-%02d-%02d', ...jalaliToGregorian($year, $month, min($day, jalaliMonthLength($year, $month))));
}

function parseChildBirthDate(string $value, bool $limitAge = false): ?string
{
    $date = parseIranDate($value);
    if ($date === null || $date >= date('Y-m-d') || ($limitAge && $date < earliestChildBirthDate())) {
        return null;
    }
    return $date;
}

function formatIranAge(?string $birthDate, bool $adjective = false): string
{
    $birth = iranDateTime($birthDate);
    $today = new DateTimeImmutable('today');
    if ($birth === null || $birth > $today || (int) $birth->format('Y') < 561) {
        return 'نامشخص';
    }
    if ($birth == $today) {
        return 'نوزاد';
    }

    [$birthYear, $birthMonth, $birthDay] = gregorianToJalali((int) $birth->format('Y'), (int) $birth->format('n'), (int) $birth->format('j'));
    [$year, $month, $day] = gregorianToJalali((int) $today->format('Y'), (int) $today->format('n'), (int) $today->format('j'));
    $months = ($year - $birthYear) * 12 + $month - $birthMonth;
    if ($day < min($birthDay, jalaliMonthLength($year, $month))) {
        $months--;
    }
    $age = $months < 12 ? persianNumber($months) . ' ماه' : persianNumber(intdiv($months, 12)) . ' سال';
    return $adjective ? $age . 'ه' : $age;
}

function portalMonthYear(string $value): ?string
{
    $value = calendarDigits($value);
    if (preg_match('/\A([0-9]{4})[\/-](0?[1-9]|1[0-2])\z/', $value, $parts) !== 1
        || (int) $parts[1] < IRAN_DATE_MIN_YEAR || (int) $parts[1] > IRAN_DATE_MAX_YEAR) {
        return null;
    }
    return sprintf('%04d-%02d', (int) $parts[1], (int) $parts[2]);
}

/** Stored financial periods from before the transition to Shamsi billing. */
function legacyGregorianMonth(string $value): ?string
{
    return preg_match('/\A([0-9]{4})-(0[1-9]|1[0-2])\z/', $value, $parts) === 1
        && (int) $parts[1] > IRAN_DATE_MAX_YEAR && (int) $parts[1] <= 3798 ? $value : null;
}

/** Shift a canonical Shamsi month key by whole Shamsi months. */
function shiftJalaliMonth(string $monthYear, int $months): string
{
    [$year, $month] = array_map('intval', explode('-', $monthYear));
    $index = $year * 12 + $month - 1 + $months;
    return sprintf('%04d-%02d', intdiv($index, 12), $index % 12 + 1);
}

/** Inclusive SQL DATE bounds for a validated period. */
function jalaliMonthToGregorianRange(string $monthYear): array
{
    $canonical = portalMonthYear($monthYear);
    if ($canonical === null) {
        throw new InvalidArgumentException('Invalid Shamsi month');
    }
    [$year, $month] = array_map('intval', explode('-', $canonical));
    return [
        sprintf('%04d-%02d-%02d', ...jalaliToGregorian($year, $month, 1)),
        sprintf('%04d-%02d-%02d', ...jalaliToGregorian($year, $month, jalaliMonthLength($year, $month))),
    ];
}

function formatShamsiMonthYear(?string $monthYear): string
{
    $monthYear ??= '';
    if (($canonical = portalMonthYear($monthYear)) !== null) {
        [$year, $month] = explode('-', $canonical);
        return shamsiMonthName((int) $month) . ' ' . persianNumber($year);
    }
    if (legacyGregorianMonth($monthYear) !== null) {
        $start = $monthYear . '-01';
        $end = (new DateTimeImmutable($start))->format('Y-m-t');
        return shamsiDate($start, 'numeric') . ' تا ' . shamsiDate($end, 'numeric');
    }
    return '—';
}

/** @return list<array{value: string, label: string, is_current: bool}> */
function getShamsiMonthYearChoices(int $pastMonths = 24, int $futureMonths = 24): array
{
    $current = shamsiDate(time(), 'Y-m');
    $choices = [];
    for ($i = $futureMonths; $i >= -$pastMonths; $i--) {
        $value = shiftJalaliMonth($current, $i);
        $choices[] = ['value' => $value, 'label' => formatShamsiMonthYear($value), 'is_current' => $i === 0];
    }
    return $choices;
}

/** Read a wall-clock hour. Compact digits also work on mobile numeric keyboards. */
function parseIranTime(?string $value): ?string
{
    $value = calendarDigits($value ?? '');
    if (preg_match('/\A([0-9]{1,2})([0-9]{2})\z/', $value, $parts) === 1) {
        $value = $parts[1] . ':' . $parts[2];
    } elseif (preg_match('/\A([0-9]{2})([0-9]{2})([0-9]{2})\z/', $value, $parts) === 1) {
        $value = $parts[1] . ':' . $parts[2] . ':' . $parts[3];
    }
    if (preg_match('/\A([0-9]|[01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?\z/', $value, $parts) !== 1) {
        return null;
    }
    return sprintf('%02d:%s:%s', (int) $parts[1], $parts[2], $parts[3] ?? '00');
}

function optionalIranTime(mixed $value, string $error): ?string
{
    if ($value === null || (is_string($value) && trim($value) === '')) {
        return null;
    }
    if (!is_string($value) || ($time = parseIranTime($value)) === null) {
        throw new InvalidArgumentException($error);
    }
    return $time;
}

function formatIranTime(?string $value): string
{
    $time = parseIranTime($value);
    return $time === null ? '' : persianNumber(substr($time, 0, 5));
}

function iranTimeForInput(?string $value): string
{
    $time = parseIranTime($value);
    return $time === null ? '' : persianNumber(str_ends_with($time, ':00') ? substr($time, 0, 5) : $time);
}

/** The browser uses the same year starts and month lengths as the server. */
function iranCalendarData(): string
{
    $leapYears = '';
    for ($year = IRAN_DATE_MIN_YEAR; $year <= IRAN_DATE_MAX_YEAR; $year++) {
        $leapYears .= isJalaliLeapYear($year) ? '1' : '0';
    }
    return json_encode([
        'firstYear' => IRAN_DATE_MIN_YEAR,
        'firstDay' => jalaliYearStart(IRAN_DATE_MIN_YEAR)->format('Y-m-d'),
        'leapYears' => $leapYears,
        'offsetSeconds' => (new DateTimeImmutable('now', new DateTimeZone(IRAN_TIMEZONE)))->getOffset(),
        'monthNames' => array_values(IRAN_MONTHS),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

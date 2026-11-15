<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

/** Only an explicit observation may become an attendance record. */
function portalAttendanceStatus(mixed $value): ?string
{
    return is_string($value) && in_array($value, ['present', 'late', 'absent', 'excused'], true)
        ? $value : null;
}

/** Absent children have no visit times; invalid visit sequences must not be saved. */
function portalAttendanceTimes(string $status, ?string $checkIn, ?string $checkOut): array
{
    if (in_array($status, ['absent', 'excused'], true)) {
        return [null, null];
    }
    if ($checkOut !== null && ($checkIn === null || $checkOut < $checkIn)) {
        throw new InvalidArgumentException('ساعت خروج باید بعد از ساعت ورود باشد. ابتدا ساعت ورود کودک را بررسی کنید.');
    }
    return [$checkIn, $checkOut];
}

function portalMonthYear(string $raw): ?string
{
    $raw = strtr(trim($raw), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9', '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    return preg_match('/\A1[34]\d{2}-(0[1-9]|1[0-2])\z/', $raw) === 1 ? $raw : null;
}

/** Credit on one child's account does not settle another child's debt. */
function portalTuitionTotals(array $breakdowns): array
{
    $totals = ['expected' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'due' => 0.0, 'credit' => 0.0];
    foreach ($breakdowns as $item) {
        $totals['expected'] += (float) $item['expected'];
        $totals['paid'] += (float) $item['paid'];
        $totals['balance'] += (float) $item['balance'];
        $totals['due'] += max(0, (float) $item['balance']);
        $totals['credit'] += max(0, -(float) $item['balance']);
    }
    return $totals;
}

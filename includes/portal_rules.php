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

/** SQL condition for an outstanding handover (attendance alias a). */
const PORTAL_OPEN_CHECKOUT = "a.status IN ('present', 'late') AND a.check_in IS NOT NULL
    AND (a.check_out IS NULL OR a.picked_up_by IS NULL OR a.pickup_verified_by IS NULL)";

// A confirmed handover can no longer be overwritten by an ordinary attendance form.
const PORTAL_UPDATE_UNVERIFIED_ATTENDANCE = <<<'SQL'
ON DUPLICATE KEY UPDATE
    status    = IF(pickup_verified_by IS NULL, VALUES(status), status),
    check_in  = IF(pickup_verified_by IS NULL, VALUES(check_in), check_in),
    check_out = IF(pickup_verified_by IS NULL, VALUES(check_out), check_out),
    notes     = IF(pickup_verified_by IS NULL, VALUES(notes), notes)
SQL;

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

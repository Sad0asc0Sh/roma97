<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

/**
 * Shared helpers for parent portal child listings and detail views.
 */

function parentChildDisplayAge(string $dateOfBirth): string
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
            return persianNumber((string) $months) . ' ماه';
        }

        return persianNumber((string) $years) . ' سال';
    } catch (Throwable) {
        return 'نامشخص';
    }
}

function parentChildGenderLabel(?string $gender): string
{
    return match ($gender) {
        'male' => 'پسر',
        'female' => 'دختر',
        'other' => 'سایر',
        default => 'نامشخص',
    };
}

function parentChildEnrollmentLabel(string $status): string
{
    return match ($status) {
        'pending' => 'در انتظار تأیید',
        'active' => 'ثبت‌نام شده',
        'inactive' => 'غیرفعال',
        default => $status,
    };
}

/**
 * @return 'enrollment-awaiting'|'enrollment-enrolled'|'enrollment-inactive'
 */
function parentChildEnrollmentClass(string $status): string
{
    return match ($status) {
        'pending' => 'enrollment-awaiting',
        'active' => 'enrollment-enrolled',
        'inactive' => 'enrollment-inactive',
        default => 'enrollment-inactive',
    };
}

function parentChildDetailDateLabel(string $datetime): string
{
    return $datetime === '' ? 'نامشخص' : formatPersianDate($datetime);
}

function parentPortalParseDateYmd(string $raw): ?string
{
    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $raw) !== 1) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

    return $date !== false && $date->format('Y-m-d') === $raw ? $raw : null;
}

function parentPortalMondayOfDate(DateTimeImmutable $date): DateTimeImmutable
{
    $dow = (int) $date->format('N');

    return $date->modify('-' . ($dow - 1) . ' day');
}

function parentPortalAttendanceStatusLabel(string $status): string
{
    return match ($status) {
        'present' => 'حاضر',
        'late' => 'تأخیر',
        'excused' => 'غیبت موجه',
        'absent' => 'غایب',
        default => $status,
    };
}

/**
 * CSS classes: attendance-present, attendance-late, attendance-excused, attendance-absent, attendance-none.
 *
 * @return 'attendance-present'|'attendance-late'|'attendance-excused'|'attendance-absent'|'attendance-none'
 */
function parentPortalAttendanceBadgeClass(?string $status): string
{
    if ($status === null || $status === '') {
        return 'attendance-none';
    }

    return match ($status) {
        'present' => 'attendance-present',
        'late' => 'attendance-late',
        'excused' => 'attendance-excused',
        'absent' => 'attendance-absent',
        default => 'attendance-none',
    };
}

function parentPortalFormatTimeShort(?string $sqlTime): string
{
    if ($sqlTime === null || $sqlTime === '') {
        return '';
    }

    if (preg_match('/^(\d{2}:\d{2})/', $sqlTime, $matches)) {
        return $matches[1];
    }

    return '';
}

/** Category label shown to parents */
function parentPortalEventCategoryLabel(string $cat): string
{
    return match ($cat) {
        'trip' => 'اردو',
        'celebration' => 'جشن',
        'meeting' => 'جلسه',
        'holiday' => 'تعطیلات',
        default => 'عمومی',
    };
}

/**
 * Matches parent CSS: event-category-general, trip, celebration, meeting, holiday.
 */
function parentPortalEventCategoryClass(string $cat): string
{
    return match ($cat) {
        'trip' => 'event-category-trip',
        'celebration' => 'event-category-celebration',
        'meeting' => 'event-category-meeting',
        'holiday' => 'event-category-holiday',
        default => 'event-category-general',
    };
}

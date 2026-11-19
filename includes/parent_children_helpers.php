<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

/**
 * Shared helpers for parent portal child listings and detail views.
 */

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
        'graduated' => 'فارغ‌التحصیل',
        'withdrawn' => 'انصراف داده',
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
        'inactive', 'graduated', 'withdrawn' => 'enrollment-inactive',
        default => 'enrollment-inactive',
    };
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

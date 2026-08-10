<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

/**
 * Central permissions matrix mapping admin roles to allowed pages.
 */
function adminPermissionsMatrix(): array
{
    return [
        'owner' => [
            'admin/index.php',
            'admin/settings.php',
            'admin/slides.php',
            'admin/gallery.php',
            'admin/news.php',
            'admin/pages.php',
            'admin/teachers.php',
            'admin/children.php',
            'admin/child-detail.php',
            'admin/edit-child.php',
            'admin/child-action.php',
            'admin/attendance.php',
            'admin/classrooms.php',
            'admin/parents.php',
            'admin/tuition.php',
            'admin/salary.php',
            'admin/expenses.php',
            'admin/reports.php',
            'admin/events.php',
            'admin/messages.php',
            'admin/audit.php',
            'admin/backup.php',
            'admin/export-csv.php',
        ],
        'manager' => [
            'admin/index.php',
            'admin/slides.php',
            'admin/gallery.php',
            'admin/news.php',
            'admin/pages.php',
            'admin/teachers.php',
            'admin/children.php',
            'admin/child-detail.php',
            'admin/edit-child.php',
            'admin/child-action.php',
            'admin/attendance.php',
            'admin/classrooms.php',
            'admin/parents.php',
            'admin/tuition.php',
            'admin/salary.php',
            'admin/expenses.php',
            'admin/reports.php',
            'admin/events.php',
            'admin/messages.php',
            'admin/audit.php',
            'admin/backup.php',
            'admin/export-csv.php',
        ],
        'accountant' => [
            'admin/index.php',
            'admin/tuition.php',
            'admin/salary.php',
            'admin/expenses.php',
            'admin/reports.php',
            'admin/backup.php',
            'admin/export-csv.php',
        ],
        'receptionist' => [
            'admin/index.php',
            'admin/children.php',
            'admin/child-detail.php',
            'admin/edit-child.php',
            'admin/child-action.php',
            'admin/attendance.php',
            'admin/classrooms.php',
            'admin/parents.php',
            'admin/events.php',
            'admin/messages.php',
            'admin/export-csv.php',
        ],
    ];
}

/**
 * Human-readable Persian labels for admin roles.
 */
function adminRoleLabel(string $role): string
{
    return match ($role) {
        'owner'        => 'مدیر ارشد (Owner)',
        'manager'      => 'مدیر داخلی (Manager)',
        'accountant'   => 'حسابدار (Accountant)',
        'receptionist' => 'مسئول پذیرش (Receptionist)',
        default        => $role,
    };
}

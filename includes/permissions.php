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
            'admin/add-child.php',
            'admin/attendance.php',
            'admin/classrooms.php',
            'admin/parents.php',
            'admin/add-parent.php',
            'admin/edit-parent.php',
            'admin/delete-parent.php',
            'admin/tuition.php',
            'admin/salary.php',
            'admin/expenses.php',
            'admin/reports.php',
            'admin/events.php',
            'admin/messages.php',
            'admin/sms-center.php',
            'admin/contact-messages.php',
            'admin/audit.php',
            'admin/operations-pilot.php',
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
            'admin/add-child.php',
            'admin/attendance.php',
            'admin/classrooms.php',
            'admin/parents.php',
            'admin/add-parent.php',
            'admin/edit-parent.php',
            'admin/delete-parent.php',
            'admin/tuition.php',
            'admin/salary.php',
            'admin/expenses.php',
            'admin/reports.php',
            'admin/events.php',
            'admin/messages.php',
            'admin/sms-center.php',
            'admin/contact-messages.php',
            'admin/audit.php',
            'admin/operations-pilot.php',
            'admin/export-csv.php',
        ],
        'accountant' => [
            'admin/index.php',
            'admin/tuition.php',
            'admin/salary.php',
            'admin/expenses.php',
            'admin/reports.php',
            'admin/export-csv.php',
        ],
        'receptionist' => [
            'admin/index.php',
            'admin/children.php',
            'admin/child-detail.php',
            'admin/edit-child.php',
            'admin/child-action.php',
            'admin/add-child.php',
            'admin/attendance.php',
            'admin/classrooms.php',
            'admin/parents.php',
            'admin/add-parent.php',
            'admin/edit-parent.php',
            'admin/events.php',
            'admin/messages.php',
            'admin/contact-messages.php',
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

/**
 * Central permissions matrix for the Teacher / Coach portal (پنل مربیان).
 *
 * Defines allowed pages and capabilities based on teacher access tier:
 *  - 'assigned_coach'   : Active teacher with ≥1 assigned classroom
 *  - 'unassigned_coach' : Active teacher without an assigned classroom yet
 */
function teacherPermissionsMatrix(): array
{
    return [
        'assigned_coach' => [
            'teacher/index.php',
            'teacher/children.php',
            'teacher/child-detail.php',
            'teacher/attendance.php',
            'teacher/report.php',
            'teacher/schedule.php',
            'teacher/messages.php',
            'teacher/profile.php',
            'teacher/logout.php',
        ],
        'unassigned_coach' => [
            'teacher/index.php',
            'teacher/children.php',
            'teacher/attendance.php',
            'teacher/report.php',
            'teacher/schedule.php',
            'teacher/messages.php',
            'teacher/profile.php',
            'teacher/logout.php',
        ],
    ];
}

/**
 * Capabilities matrix for teacher actions (used to gate mutations & UI controls).
 *
 * @return array<string, list<string>>
 */
function teacherCapabilitiesMatrix(): array
{
    return [
        'assigned_coach' => [
            'dashboard.view',
            'classroom.view',
            'classroom.switch',
            'children.list',
            'children.detail',
            'attendance.view',
            'attendance.write',
            'report.view',
            'report.write',
            'schedule.view',
            'messages.view',
            'messages.send',
            'profile.view',
            'profile.edit',
            'salary.view',
        ],
        'unassigned_coach' => [
            'dashboard.view',
            'schedule.view',
            'profile.view',
            'profile.edit',
            'salary.view',
        ],
    ];
}

/**
 * Whether a teacher page is allowed for the given teacher access role.
 */
function isTeacherPageAllowed(string $pagePath, string $teacherAccessRole = 'assigned_coach'): bool
{
    $matrix = teacherPermissionsMatrix();
    $allowed = $matrix[$teacherAccessRole] ?? [];

    return in_array($pagePath, $allowed, true);
}

/**
 * Whether the teacher has a specific operational capability.
 */
function teacherHasCapability(string $capability, bool $hasAssignedClassroom = true): bool
{
    $role = $hasAssignedClassroom ? 'assigned_coach' : 'unassigned_coach';
    $matrix = teacherCapabilitiesMatrix();
    $caps = $matrix[$role] ?? [];

    return in_array($capability, $caps, true);
}

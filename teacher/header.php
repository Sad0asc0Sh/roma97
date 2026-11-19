<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/teacher_portal_helpers.php';

sendSecurityHeaders();
requireTeacherLogin();

$logo          = siteLogo();
$currentPage   = basename($_SERVER['PHP_SELF']);
$teacherId     = (int) ($_SESSION['teacher_id'] ?? 0);
$teacherName   = (string) ($_SESSION['teacher_name'] ?? 'مربی');
$teacherRole   = (string) ($_SESSION['teacher_role_title'] ?? 'مربی مهد');
$teacherAvatar = (string) ($_SESSION['teacher_avatar'] ?? '');
$teacherInitial = userInitial((string) ($_SESSION['teacher_first_name'] ?? $teacherName), 'م');
$siteNameValue = siteName();
$pageTitleValue = isset($pageTitle) ? e($pageTitle) : 'پنل مربیان | ' . e($siteNameValue);

// Resolve classroom context & live counters for header navigation badges
$headerClassrooms      = [];
$headerActiveClassroom = null;
$headerActiveClassId   = 0;
$headerChildCount      = 0;
$headerAttPending      = 0;
$headerRepPending      = 0;

try {
    $pdoHeader = getDb();
    $ctx = teacherResolveClassroomContext($pdoHeader, $teacherId);
    $headerClassrooms      = $ctx['classrooms'];
    $headerActiveClassroom = $ctx['active'];
    $headerActiveClassId   = $ctx['active_id'];

    if ($headerActiveClassroom !== null) {
        $todayYmd = date('Y-m-d');
        $headerChildCount = (int) ($headerActiveClassroom['active_children_count'] ?? 0);

        if ($headerChildCount > 0) {
            $attStmt = $pdoHeader->prepare(
                "SELECT COUNT(DISTINCT a.child_id)
                 FROM attendance a
                 INNER JOIN child_classroom cc ON cc.child_id = a.child_id
                 INNER JOIN children c ON c.id = cc.child_id
                 WHERE cc.classroom_id = :cid AND c.status = 'active' AND a.attendance_date = :today"
            );
            $attStmt->execute([':cid' => $headerActiveClassId, ':today' => $todayYmd]);
            $recordedAtt = (int) $attStmt->fetchColumn();
            $headerAttPending = max(0, $headerChildCount - $recordedAtt);

            $repStmt = $pdoHeader->prepare(
                "SELECT COUNT(*)
                 FROM attendance a
                 INNER JOIN child_classroom cc ON cc.child_id = a.child_id
                 INNER JOIN children c ON c.id = a.child_id
                 LEFT JOIN daily_reports dr ON dr.child_id = a.child_id AND dr.classroom_id = cc.classroom_id
                     AND dr.report_date = a.attendance_date
                 WHERE cc.classroom_id = :cid AND c.status = 'active' AND a.attendance_date = :today
                   AND a.status IN ('present', 'late') AND dr.id IS NULL"
            );
            $repStmt->execute([':cid' => $headerActiveClassId, ':today' => $todayYmd]);
            $headerRepPending = (int) $repStmt->fetchColumn();
        }
    }
} catch (Throwable $e) {
    error_log('teacher/header context failed: ' . $e->getMessage());
}

$navChildrenActive = in_array($currentPage, ['children.php', 'child-detail.php'], true);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#2B7A56" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0D1411" media="(prefers-color-scheme: dark)">
    <script>(function(){var saved;try{saved=localStorage.getItem('roma_theme');}catch(e){}var t=saved||(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);})();</script>
    <title><?php echo $pageTitleValue; ?></title>
    <link rel="preload" href="<?php echo e(url('assets/fonts/Vazirmatn-Regular.woff2')); ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?php echo e(url('assets/fonts/BalooBhaijaan2-arabic.woff2')); ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/fonts.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/app-shell.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/teacher-portal.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/portal-experience.css')); ?>">
    <script src="<?php echo e(asset('assets/js/app-shell.js')); ?>" defer></script>
    <script src="<?php echo e(asset('assets/js/portal-experience.js')); ?>" defer></script>
</head>
<body class="teacher-layout teacher-portal-body">
    <a class="skip-link" href="#teacherMain">پرش به محتوای اصلی</a>

    <header class="teacher-header teacher-header--unified">
        <div class="teacher-header-container">
            <div class="teacher-header-top">
                <div class="teacher-brand-group">
                    <a href="<?php echo e(url('teacher/index.php')); ?>" class="teacher-brand" aria-label="<?php echo e($siteNameValue); ?> — پنل مربیان">
                        <?php if ($logo !== ''): ?>
                            <img src="<?php echo e(url($logo)); ?>" alt="<?php echo e($siteNameValue); ?>" class="teacher-brand-logo">
                        <?php else: ?>
                            <span class="teacher-brand-mark" aria-hidden="true">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5"/></svg>
                            </span>
                        <?php endif; ?>
                        <span class="teacher-brand-text">
                            <span class="teacher-brand-name"><?php echo e($siteNameValue); ?></span>
                            <span class="teacher-brand-badge">پنل مربیان</span>
                        </span>
                    </a>

                    <?php if (count($headerClassrooms) > 1): ?>
                        <form method="get" action="<?php echo e(url('teacher/' . ($currentPage === 'child-detail.php' ? 'children.php' : $currentPage))); ?>" class="tp-class-switcher" title="تغییر کلاس فعال">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                            <label for="headerClassroomSelect" class="sr-only">انتخاب کلاس</label>
                            <select id="headerClassroomSelect" name="classroom_id" onchange="this.form.submit()">
                                <?php foreach ($headerClassrooms as $clItem): ?>
                                    <option value="<?php echo (int) $clItem['id']; ?>" <?php echo (int) $clItem['id'] === $headerActiveClassId ? 'selected' : ''; ?>>
                                        کلاس <?php echo e((string) $clItem['name']); ?> (<?php echo e(persianNumber((string) $clItem['active_children_count'])); ?> کودک)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php elseif ($headerActiveClassroom !== null): ?>
                        <span class="tp-class-switcher" title="کلاس اختصاص‌یافته به شما">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                            <span>کلاس <?php echo e((string) $headerActiveClassroom['name']); ?> · <?php echo e(persianNumber((string) $headerChildCount)); ?> کودک</span>
                        </span>
                    <?php else: ?>
                        <span class="tp-class-switcher tp-class-switcher--empty" title="هنوز کلاسی به شما اختصاص داده نشده است">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span>بدون کلاس فعال</span>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="teacher-header-actions">
                    <div class="tp-search-wrap">
                        <svg class="tp-search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="search" id="appSearchInput" placeholder="جست‌وجوی سریع کودک یا ردیف..." aria-label="جست‌وجوی سریع در صفحه">
                        <kbd class="tp-search-kbd">/</kbd>
                    </div>

                    <a href="<?php echo e(url('teacher/messages.php')); ?>" class="tp-action-btn" aria-label="مرکز پیام‌ها" title="ارسال پیام به والدین">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    </a>

                    <button type="button" class="tp-action-btn" data-theme-toggle-btn id="themeToggleBtn" aria-label="تغییر حالت شب/روز" title="تغییر حالت شب/روز">
                        <svg class="icon-moon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
                        <svg class="icon-sun" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                    </button>

                    <div class="tp-user-menu">
                        <button type="button" class="tp-user-trigger" id="tpUserTrigger" aria-haspopup="menu" aria-expanded="false" aria-label="منوی کاربری <?php echo e($teacherName); ?>">
                            <?php if ($teacherAvatar !== ''): ?>
                                <img src="<?php echo e(url($teacherAvatar)); ?>" alt="<?php echo e($teacherName); ?>" class="tp-user-avatar">
                            <?php else: ?>
                                <span class="tp-user-avatar"><?php echo e($teacherInitial); ?></span>
                            <?php endif; ?>
                            <span class="tp-user-info-inline">
                                <span class="tp-user-name"><?php echo e($teacherName); ?></span>
                                <span class="tp-user-role"><?php echo e($teacherRole !== '' ? $teacherRole : 'مربی مهد'); ?></span>
                            </span>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="tp-dropdown" id="tpUserMenu" role="menu" aria-label="منوی کاربری مربی">
                            <div class="tp-dropdown-head">
                                <strong><?php echo e($teacherName); ?></strong>
                                <span><?php echo e($teacherRole !== '' ? $teacherRole : 'مربی مهد'); ?></span>
                            </div>
                            <div class="tp-dropdown-divider"></div>
                            <a href="<?php echo e(url('teacher/profile.php')); ?>" class="tp-dropdown-item" role="menuitem">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <span>پروفایل و فیش حقوقی</span>
                            </a>
                            <a href="<?php echo e(url('teacher/schedule.php')); ?>" class="tp-dropdown-item" role="menuitem">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <span>برنامه کلاسی و تقویم</span>
                            </a>
                            <a href="<?php echo e(url('index.php')); ?>" class="tp-dropdown-item" role="menuitem" target="_blank" rel="noopener">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
                                <span>مشاهده وبسایت مهد</span>
                            </a>
                            <div class="tp-dropdown-divider"></div>
                            <form method="post" action="<?php echo e(url('teacher/logout.php')); ?>" style="margin:0">
                                <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                                <button type="submit" class="tp-dropdown-item danger" role="menuitem">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                                    <span>خروج از حساب</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <nav class="teacher-nav" id="teacherNav" aria-label="منوی اصلی پنل مربیان">
                <a href="<?php echo e(url('teacher/index.php')); ?>" class="teacher-nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'index.php' ? 'aria-current="page"' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <span class="nav-text">داشبورد</span>
                </a>
                <a href="<?php echo e(url('teacher/children.php')); ?>" class="teacher-nav-item <?php echo $navChildrenActive ? 'active' : ''; ?>" <?php echo $navChildrenActive ? 'aria-current="page"' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 10-16 0"/></svg>
                    <span class="nav-text">کودکان کلاس</span>
                    <?php if ($headerChildCount > 0): ?>
                        <span class="tp-nav-badge"><?php echo e(persianNumber((string) $headerChildCount)); ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo e(url('teacher/attendance.php')); ?>" class="teacher-nav-item <?php echo $currentPage === 'attendance.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'attendance.php' ? 'aria-current="page"' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                    <span class="nav-text">حضور و غیاب</span>
                    <?php if ($headerAttPending > 0): ?>
                        <span class="tp-nav-badge tp-nav-badge--warn" title="<?php echo e(persianNumber((string) $headerAttPending)); ?> کودک ثبت‌نشده"><?php echo e(persianNumber((string) $headerAttPending)); ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo e(url('teacher/report.php')); ?>" class="teacher-nav-item <?php echo $currentPage === 'report.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'report.php' ? 'aria-current="page"' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
                    <span class="nav-text">گزارش روزانه</span>
                    <?php if ($headerRepPending > 0): ?>
                        <span class="tp-nav-badge tp-nav-badge--warn" title="<?php echo e(persianNumber((string) $headerRepPending)); ?> گزارش باقی‌مانده"><?php echo e(persianNumber((string) $headerRepPending)); ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo e(url('teacher/schedule.php')); ?>" class="teacher-nav-item <?php echo $currentPage === 'schedule.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'schedule.php' ? 'aria-current="page"' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="nav-text">برنامه و رویدادها</span>
                </a>
                <a href="<?php echo e(url('teacher/messages.php')); ?>" class="teacher-nav-item <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'messages.php' ? 'aria-current="page"' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    <span class="nav-text">پیام‌ها</span>
                </a>
                <a href="<?php echo e(url('teacher/profile.php')); ?>" class="teacher-nav-item <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'profile.php' ? 'aria-current="page"' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span class="nav-text">پروفایل من</span>
                </a>
            </nav>
        </div>
    </header>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var trigger = document.getElementById('tpUserTrigger');
        var menu = document.getElementById('tpUserMenu');
        if (trigger && menu) {
            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                var open = menu.classList.toggle('show');
                trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function() {
                menu.classList.remove('show');
                trigger.setAttribute('aria-expanded', 'false');
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    menu.classList.remove('show');
                    trigger.setAttribute('aria-expanded', 'false');
                }
            });
        }
    });
    </script>

    <main class="teacher-main" id="teacherMain">
        <div class="teacher-container">

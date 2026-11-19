<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

sendSecurityHeaders();
requireLogin();

$logo = siteLogo();
$currentPage = basename($_SERVER['PHP_SELF']);

/**
 * Whether the signed-in admin may actually open a page.
 *
 * Gating every nav item with the same matrix that enforces access removes
 * unauthorized links and dead-end redirects completely.
 */
$adminRole = currentAdminRole();
$navCan = static fn(string $pagePath): bool => isPageAllowedForRole($pagePath, $adminRole);

/** True when at least one page of a submenu group is reachable. */
$navCanAny = static function (array $pagePaths) use ($navCan): bool {
    foreach ($pagePaths as $path) {
        if ($navCan($path)) {
            return true;
        }
    }

    return false;
};

$adminUsername = (string) ($_SESSION['admin_username'] ?? 'مدیر');
$adminAvatarChar = userInitial($adminUsername, 'م');
$adminRoleText = adminRoleLabel($adminRole);
$siteNameValue = siteName();
$pageTitleValue = isset($pageTitle) ? e($pageTitle) : 'پنل مدیریت | ' . e($siteNameValue);

// Active section detection for sidebar groups
$isEducationActive = in_array($currentPage, [
    'children.php', 'child-detail.php', 'edit-child.php', 'add-child.php',
    'parents.php', 'add-parent.php', 'edit-parent.php', 'delete-parent.php',
    'classrooms.php', 'teachers.php', 'operations-pilot.php',
], true);
$isFinanceActive = in_array($currentPage, ['tuition.php', 'salary.php', 'expenses.php', 'reports.php'], true);
$isCommsActive = in_array($currentPage, ['messages.php', 'sms-center.php', 'contact-messages.php'], true);
$isContentActive = in_array($currentPage, ['slides.php', 'gallery.php', 'news.php', 'pages.php'], true);
$isSystemActive = in_array($currentPage, ['audit.php', 'backup.php', 'settings.php'], true);

$hasDailySection = $navCanAny(['admin/index.php', 'admin/attendance.php', 'admin/events.php']);
$hasEducationGroup = $navCanAny(['admin/children.php', 'admin/parents.php', 'admin/classrooms.php', 'admin/teachers.php', 'admin/operations-pilot.php']);
$hasFinanceGroup = $navCanAny(['admin/tuition.php', 'admin/salary.php', 'admin/expenses.php', 'admin/reports.php']);
$hasCommsGroup = $navCanAny(['admin/messages.php', 'admin/sms-center.php', 'admin/contact-messages.php']);
$hasContentGroup = $navCanAny(['admin/slides.php', 'admin/gallery.php', 'admin/news.php', 'admin/pages.php']);
$hasSystemGroup = $navCanAny(['admin/audit.php', 'admin/backup.php', 'admin/settings.php']);
?>
<!doctype html>
<html lang="fa-IR" dir="rtl" class="admin-shell-root" data-iran-calendar="<?= e(iranCalendarData()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#1F7A68" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0E1715" media="(prefers-color-scheme: dark)">
    <script>(function(){var r=document.documentElement;r.classList.add('admin-shell-root');var saved,pinned;try{saved=localStorage.getItem('roma_theme');pinned=localStorage.getItem('roma_admin_sidebar_pinned');}catch(e){}var t=saved||(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');r.setAttribute('data-theme',t);r.setAttribute('data-sidebar-pinned',pinned==='1'?'true':'false');})();</script>
    <title><?php echo $pageTitleValue; ?></title>
    <link rel="preload" href="<?php echo e(url('assets/fonts/Vazirmatn-Regular.woff2')); ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/fonts.css')); ?>">
    <style>
        @layer adm-legacy, adm-tokens, adm-reset, adm-shell, adm-navigation, adm-components, adm-pages, adm-responsive;
        @import url('<?php echo e(asset('assets/css/app-shell.css')); ?>') layer(adm-legacy);
        @import url('<?php echo e(asset('assets/css/admin.css')); ?>') layer(adm-legacy);
        @import url('<?php echo e(asset('assets/css/admin-responsive.css')); ?>') layer(adm-legacy);
        @import url('<?php echo e(asset('assets/css/portal-experience.css')); ?>') layer(adm-legacy);
    </style>
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/admin-modern.css')); ?>">
    <script src="<?php echo e(asset('assets/js/app-shell.js')); ?>" defer></script>
    <script src="<?php echo e(asset('assets/js/portal-experience.js')); ?>" defer></script>
    <script src="<?php echo e(asset('assets/js/admin-modern.js')); ?>" defer></script>
</head>
<body class="admin-layout">
    <a class="portal-skip" href="#adminMain">پرش به محتوای اصلی</a>
    <div class="admin-overlay" id="adminOverlay" aria-hidden="true"></div>

    <aside class="admin-sidebar" id="adminSidebar" data-modern-sidebar aria-label="ناوبری پنل مدیریت">
        <div class="admin-sidebar-header">
            <a href="<?php echo e(url('admin/index.php')); ?>" class="admin-logo" title="<?php echo e($siteNameValue); ?>">
                <?php if ($logo !== ''): ?>
                    <img src="<?php echo e(url($logo)); ?>" alt="<?php echo e($siteNameValue); ?>" class="admin-logo-img">
                <?php else: ?>
                    <span class="admin-logo-mark" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 40 40" fill="none"><path d="M20 11c-3 0-5.5 1.5-5.5 4.2 0 1.8 1 3 2.5 3.9-.6.4-1.4 1.2-1.8 2.2-.4 1-.2 1.8.4 2.4.6.6 1.4.6 2.2.3.7-.2 1.2-1 1.5-1.5h.8c.3.5.8 1.3 1.5 1.5.8.3 1.6.3 2.2-.3.6-.6.8-1.4.4-2.4-.4-1-1.2-1.8-1.8-2.2 1.5-.9 2.5-2.1 2.5-3.9 0-2.7-2.5-4.2-5.5-4.2z" fill="currentColor"/><circle cx="17.5" cy="15.5" r="1" fill="rgba(19,35,32,0.75)"/><circle cx="22.5" cy="15.5" r="1" fill="rgba(19,35,32,0.75)"/></svg>
                    </span>
                <?php endif; ?>
                <span class="admin-logo-brand">
                    <span class="admin-logo-name"><?php echo e($siteNameValue); ?></span>
                    <span class="admin-logo-badge">مدیریت</span>
                </span>
            </a>
            <div class="admin-sidebar-header-actions">
                <button type="button" class="adm-sidebar-pin" id="sidebarPinBtn" aria-pressed="false" aria-label="ثابت‌سازی منو" title="ثابت‌سازی منو">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 00-1.11-1.79l-1.78-.9A2 2 0 0115 10.76V6h1a2 2 0 000-4H8a2 2 0 000 4h1v4.76a2 2 0 01-1.11 1.79l-1.78.9A2 2 0 005 15.24Z"/></svg>
                </button>
                <button type="button" class="admin-sidebar-close" id="adminSidebarClose" aria-label="بستن منو">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </div>

        <nav class="admin-nav" aria-label="منوی مدیریت">
            <?php if ($hasDailySection): ?>
                <div class="admin-nav-category">عملیات روزانه</div>

                <?php if ($navCan('admin/index.php')): ?>
                    <a href="<?php echo e(url('admin/index.php')); ?>" class="admin-nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>" title="داشبورد">
                        <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/></svg></span>
                        <span class="nav-text">داشبورد</span>
                    </a>
                <?php endif; ?>

                <?php if ($navCan('admin/attendance.php')): ?>
                    <a href="<?php echo e(url('admin/attendance.php')); ?>" class="admin-nav-item <?php echo $currentPage === 'attendance.php' ? 'active' : ''; ?>" title="حضور و غیاب">
                        <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg></span>
                        <span class="nav-text">حضور و غیاب</span>
                    </a>
                <?php endif; ?>

                <?php if ($navCan('admin/events.php')): ?>
                    <a href="<?php echo e(url('admin/events.php')); ?>" class="admin-nav-item <?php echo $currentPage === 'events.php' ? 'active' : ''; ?>" title="رویدادها و تقویم">
                        <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
                        <span class="nav-text">رویدادها و تقویم</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($hasEducationGroup || $hasFinanceGroup): ?>
                <div class="admin-nav-category">مدیریت و آموزش</div>
            <?php endif; ?>

            <?php if ($hasEducationGroup): ?>
                <div class="admin-nav-group">
                    <button type="button" class="admin-nav-item admin-nav-parent <?php echo $isEducationActive ? 'active' : ''; ?>" data-submenu="education" aria-expanded="<?php echo $isEducationActive ? 'true' : 'false'; ?>" aria-controls="submenu-education" title="کودکان و آموزش">
                        <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 10-16 0"/></svg></span>
                        <span class="nav-text">کودکان و آموزش</span>
                        <svg class="nav-arrow-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <div class="admin-nav-submenu <?php echo $isEducationActive ? 'open' : ''; ?>" id="submenu-education">
                        <div class="admin-nav-submenu-inner">
                            <?php if ($navCan('admin/children.php')): ?>
                                <a href="<?php echo e(url('admin/children.php')); ?>" class="admin-nav-subitem <?php echo in_array($currentPage, ['children.php', 'child-detail.php', 'add-child.php', 'edit-child.php'], true) ? 'active' : ''; ?>">
                                    <span>فهرست کودکان</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/parents.php')): ?>
                                <a href="<?php echo e(url('admin/parents.php')); ?>" class="admin-nav-subitem <?php echo in_array($currentPage, ['parents.php', 'add-parent.php', 'edit-parent.php', 'delete-parent.php'], true) ? 'active' : ''; ?>">
                                    <span>مدیریت والدین</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/classrooms.php')): ?>
                                <a href="<?php echo e(url('admin/classrooms.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'classrooms.php' ? 'active' : ''; ?>">
                                    <span>کلاس‌ها</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/teachers.php')): ?>
                                <a href="<?php echo e(url('admin/teachers.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'teachers.php' ? 'active' : ''; ?>">
                                    <span>مربیان</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/operations-pilot.php')): ?>
                                <a href="<?php echo e(url('admin/operations-pilot.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'operations-pilot.php' ? 'active' : ''; ?>">
                                    <span>آزمون میدانی کلاس</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($hasFinanceGroup): ?>
                <div class="admin-nav-group">
                    <button type="button" class="admin-nav-item admin-nav-parent <?php echo $isFinanceActive ? 'active' : ''; ?>" data-submenu="finance" aria-expanded="<?php echo $isFinanceActive ? 'true' : 'false'; ?>" aria-controls="submenu-finance" title="امور مالی">
                        <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></span>
                        <span class="nav-text">امور مالی</span>
                        <svg class="nav-arrow-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <div class="admin-nav-submenu <?php echo $isFinanceActive ? 'open' : ''; ?>" id="submenu-finance">
                        <div class="admin-nav-submenu-inner">
                            <?php if ($navCan('admin/tuition.php')): ?>
                                <a href="<?php echo e(url('admin/tuition.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'tuition.php' ? 'active' : ''; ?>">
                                    <span>شهریه</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/salary.php')): ?>
                                <a href="<?php echo e(url('admin/salary.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'salary.php' ? 'active' : ''; ?>">
                                    <span>حقوق</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/expenses.php')): ?>
                                <a href="<?php echo e(url('admin/expenses.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'expenses.php' ? 'active' : ''; ?>">
                                    <span>هزینه‌ها</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/reports.php')): ?>
                                <a href="<?php echo e(url('admin/reports.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'reports.php' ? 'active' : ''; ?>">
                                    <span>گزارش مالی</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($hasCommsGroup || $hasContentGroup): ?>
                <div class="admin-nav-category">ارتباطات و وب‌سایت</div>
            <?php endif; ?>

            <?php if ($hasCommsGroup): ?>
                <div class="admin-nav-group">
                    <button type="button" class="admin-nav-item admin-nav-parent <?php echo $isCommsActive ? 'active' : ''; ?>" data-submenu="comms" aria-expanded="<?php echo $isCommsActive ? 'true' : 'false'; ?>" aria-controls="submenu-comms" title="ارتباطات">
                        <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></span>
                        <span class="nav-text">ارتباطات</span>
                        <svg class="nav-arrow-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <div class="admin-nav-submenu <?php echo $isCommsActive ? 'open' : ''; ?>" id="submenu-comms">
                        <div class="admin-nav-submenu-inner">
                            <?php if ($navCan('admin/messages.php')): ?>
                                <a href="<?php echo e(url('admin/messages.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?>">
                                    <span>پیام‌ها</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/sms-center.php')): ?>
                                <a href="<?php echo e(url('admin/sms-center.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'sms-center.php' ? 'active' : ''; ?>">
                                    <span>مرکز پیامک</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/contact-messages.php')): ?>
                                <a href="<?php echo e(url('admin/contact-messages.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'contact-messages.php' ? 'active' : ''; ?>">
                                    <span>پیام‌های تماس</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($hasContentGroup): ?>
                <div class="admin-nav-group">
                    <button type="button" class="admin-nav-item admin-nav-parent <?php echo $isContentActive ? 'active' : ''; ?>" data-submenu="content" aria-expanded="<?php echo $isContentActive ? 'true' : 'false'; ?>" aria-controls="submenu-content" title="محتوای سایت">
                        <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                        <span class="nav-text">محتوای سایت</span>
                        <svg class="nav-arrow-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <div class="admin-nav-submenu <?php echo $isContentActive ? 'open' : ''; ?>" id="submenu-content">
                        <div class="admin-nav-submenu-inner">
                            <?php if ($navCan('admin/slides.php')): ?>
                                <a href="<?php echo e(url('admin/slides.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'slides.php' ? 'active' : ''; ?>">
                                    <span>اسلایدها</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/gallery.php')): ?>
                                <a href="<?php echo e(url('admin/gallery.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'gallery.php' ? 'active' : ''; ?>">
                                    <span>گالری تصاویر</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/news.php')): ?>
                                <a href="<?php echo e(url('admin/news.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'news.php' ? 'active' : ''; ?>">
                                    <span>اخبار</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/pages.php')): ?>
                                <a href="<?php echo e(url('admin/pages.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'pages.php' ? 'active' : ''; ?>">
                                    <span>صفحات</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($hasSystemGroup): ?>
                <div class="admin-nav-category">سیستم و امنیت</div>
                <div class="admin-nav-group">
                    <button type="button" class="admin-nav-item admin-nav-parent <?php echo $isSystemActive ? 'active' : ''; ?>" data-submenu="system" aria-expanded="<?php echo $isSystemActive ? 'true' : 'false'; ?>" aria-controls="submenu-system" title="مدیریت سیستم">
                        <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg></span>
                        <span class="nav-text">مدیریت سیستم</span>
                        <svg class="nav-arrow-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <div class="admin-nav-submenu <?php echo $isSystemActive ? 'open' : ''; ?>" id="submenu-system">
                        <div class="admin-nav-submenu-inner">
                            <?php if ($navCan('admin/audit.php')): ?>
                                <a href="<?php echo e(url('admin/audit.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'audit.php' ? 'active' : ''; ?>">
                                    <span>گزارش فعالیت‌ها</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/backup.php')): ?>
                                <a href="<?php echo e(url('admin/backup.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'backup.php' ? 'active' : ''; ?>">
                                    <span>پشتیبان‌گیری</span>
                                </a>
                            <?php endif; ?>
                            <?php if ($navCan('admin/settings.php')): ?>
                                <a href="<?php echo e(url('admin/settings.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">
                                    <span>تنظیمات</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </nav>

        <div class="admin-sidebar-footer">
            <div class="admin-sidebar-footer-row">
                <div class="admin-user-info">
                    <div class="admin-user-avatar" aria-hidden="true"><?php echo e($adminAvatarChar); ?></div>
                    <div class="admin-user-details">
                        <div class="admin-user-name"><?php echo e($adminUsername); ?></div>
                        <div class="admin-user-role"><?php echo e($adminRoleText); ?></div>
                    </div>
                </div>
                <form method="post" action="<?php echo e(url('admin/logout.php')); ?>" class="admin-sidebar-logout-form">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                    <button type="submit" class="admin-sidebar-logout-btn" aria-label="خروج از حساب" title="خروج از حساب">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <div class="admin-main">
        <?php $showChildDirectorySearch = $navCan('admin/children.php'); ?>
        <?php require_once __DIR__ . '/../templates/app/topbar.php'; ?>

        <main class="admin-content" id="adminMain">
            <!-- Default-password warning removed (hardened: no hardcoded default password exists) -->

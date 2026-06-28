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
$adminUsername = $_SESSION['admin_username'] ?? 'مدیر';
$siteNameValue = siteName();
$pageTitleValue = isset($pageTitle) ? e($pageTitle) : 'پنل مدیریت | ' . e($siteNameValue);
$topbarTitle = isset($pageTitle) ? e(str_replace(' | ' . $siteNameValue, '', $pageTitle)) : 'پنل مدیریت';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $pageTitleValue; ?></title>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo e(url('assets/css/admin.css')); ?>">
</head>
<body class="admin-layout">
    <div class="admin-overlay" id="adminOverlay"></div>

    <aside class="admin-sidebar" id="adminSidebar">
        <div class="admin-sidebar-header">
            <a href="<?php echo e(url('admin/index.php')); ?>" class="admin-logo">
                <?php if ($logo !== ''): ?>
                    <img src="<?php echo e(url($logo)); ?>" alt="<?php echo e($siteNameValue); ?>" class="admin-logo-img">
                <?php else: ?>
                    <span class="admin-logo-text"><svg width="18" height="18" viewBox="0 0 40 40" fill="none"><path d="M20 11c-3 0-5.5 1.5-5.5 4.2 0 1.8 1 3 2.5 3.9-.6.4-1.4 1.2-1.8 2.2-.4 1-.2 1.8.4 2.4.6.6 1.4.6 2.2.3.7-.2 1.2-1 1.5-1.5h.8c.3.5.8 1.3 1.5 1.5.8.3 1.6.3 2.2-.3.6-.6.8-1.4.4-2.4-.4-1-1.2-1.8-1.8-2.2 1.5-.9 2.5-2.1 2.5-3.9 0-2.7-2.5-4.2-5.5-4.2z" fill="white"/><circle cx="17.5" cy="15.5" r="1" fill="rgba(255,255,255,0.7)"/><circle cx="22.5" cy="15.5" r="1" fill="rgba(255,255,255,0.7)"/></svg> <?php echo e($siteNameValue); ?></span>
                <?php endif; ?>
                <span class="admin-logo-badge">مدیر</span>
            </a>
            <button class="admin-sidebar-toggle" id="sidebarToggle" aria-label="Toggle menu">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>

        <nav class="admin-nav" aria-label="منوی مدیریت">
            <a href="<?php echo e(url('admin/index.php')); ?>" class="admin-nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
                <span class="nav-text">داشبورد</span>
            </a>

            <div class="admin-nav-group">
                <button class="admin-nav-item admin-nav-parent" data-submenu="content">
                    <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                    <span class="nav-text">محتوا</span>
                    <span class="nav-arrow">&#8250;</span>
                </button>
                <div class="admin-nav-submenu" id="submenu-content">
                    <a href="<?php echo e(url('admin/slides.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'slides.php' ? 'active' : ''; ?>">
                        <span class="nav-text">اسلایدها</span>
                    </a>
                    <a href="<?php echo e(url('admin/gallery.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'gallery.php' ? 'active' : ''; ?>">
                        <span class="nav-text">&#128247; گالری تصاویر</span>
                    </a>
                    <a href="<?php echo e(url('admin/news.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'news.php' ? 'active' : ''; ?>">
                        <span class="nav-text">اخبار</span>
                    </a>
                    <a href="<?php echo e(url('admin/pages.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'pages.php' ? 'active' : ''; ?>">
                        <span class="nav-text">صفحات</span>
                    </a>
                </div>
            </div>

            <div class="admin-nav-group">
                <button class="admin-nav-item admin-nav-parent" data-submenu="users">
                    <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></span>
                    <span class="nav-text">کاربران</span>
                    <span class="nav-arrow">&#8250;</span>
                </button>
                <div class="admin-nav-submenu" id="submenu-users">
                    <a href="<?php echo e(url('admin/teachers.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'teachers.php' ? 'active' : ''; ?>">
                        <span class="nav-text">معلمان</span>
                    </a>
                </div>
            </div>

            <div class="admin-nav-group">
                <button class="admin-nav-item admin-nav-parent" data-submenu="children">
                    <span class="nav-icon">&#128102;</span>
                    <span class="nav-text">کودکان</span>
                    <span class="nav-arrow">&#8250;</span>
                </button>
                <div class="admin-nav-submenu" id="submenu-children">
                    <a href="<?php echo e(url('admin/children.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'children.php' || $currentPage === 'child-detail.php' ? 'active' : ''; ?>">
                        <span class="nav-text">تمام کودکان</span>
                    </a>
                    <a href="<?php echo e(url('admin/attendance.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'attendance.php' ? 'active' : ''; ?>">
                        <span class="nav-text">حضور و غیاب</span>
                    </a>
                    <a href="<?php echo e(url('admin/classrooms.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'classrooms.php' ? 'active' : ''; ?>">
                        <span class="nav-text">کلاس‌ها</span>
                    </a>
                </div>
            </div>

            <div class="admin-nav-group">
                <button class="admin-nav-item admin-nav-parent" data-submenu="finance">
                    <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></span>
                    <span class="nav-text">مالی</span>
                    <span class="nav-arrow">&#8250;</span>
                </button>
                <div class="admin-nav-submenu" id="submenu-finance">

            <a href="<?php echo e(url('admin/tuition.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'tuition.php' ? 'active' : ''; ?>">
                        <span class="nav-text">شهریه</span>
                    </a>
                    <a href="<?php echo e(url('admin/salary.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'salary.php' ? 'active' : ''; ?>">
                        <span class="nav-text">حقوق</span>
                    </a>
                </div>
            </div>

            <div class="admin-nav-section">ارتباطات</div>

            <a href="<?php echo e(url('admin/events.php')); ?>" class="admin-nav-item <?php echo $currentPage === 'events.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
                <span class="nav-text">رویدادها</span>
            </a>


            <a href="<?php echo e(url('admin/messages.php')); ?>" class="admin-nav-item <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></span>
                <span class="nav-text">پیام‌ها</span>
            </a>

            <a href="<?php echo e(url('admin/audit.php')); ?>" class="admin-nav-item <?php echo $currentPage === 'audit.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
                <span class="nav-text">گزارش فعالیت‌ها</span>
            </a>

            <div class="admin-nav-divider"></div>

            <a href="<?php echo e(url('admin/settings.php')); ?>" class="admin-nav-item <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg></span>
                <span class="nav-text">تنظیمات</span>
            </a>

            <form method="post" action="<?php echo e(url('admin/logout.php')); ?>" class="admin-nav-logout-form">
                <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                <button type="submit" class="admin-nav-item admin-nav-logout">
                    <span class="nav-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span>
                    <span class="nav-text">خروج</span>
                </button>
            </form>
        </nav>

        <div class="admin-sidebar-footer">
            <div class="admin-user-info">
                <div class="admin-user-avatar"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
                <div class="admin-user-details">
                    <div class="admin-user-name"><?php echo e($adminUsername); ?></div>
                    <div class="admin-user-role">مدیر سیستم</div>
                </div>
            </div>
        </div>
    </aside>

    <div class="admin-main">
        <header class="admin-topbar">
            <button class="admin-mobile-toggle" id="mobileSidebarToggle" aria-label="Toggle menu">
                <span></span>
                <span></span>
                <span></span>
            </button>
            <div class="admin-topbar-title">
                <h1><?php echo $topbarTitle; ?></h1>
            </div>
            <div class="admin-topbar-actions">
                <a href="<?php echo e(url('index.php')); ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener">
                    <span>&#128065;</span> مشاهده سایت
                </a>
            </div>
        </header>

        <main class="admin-content">
            <!-- Default-password warning removed (hardened: no hardcoded default password exists) -->

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
    <link rel="stylesheet" href="<?php echo e(url('assets/css/style.css')); ?>">
</head>
<body class="admin-layout">
    <div class="admin-overlay" id="adminOverlay"></div>

    <aside class="admin-sidebar" id="adminSidebar">
        <div class="admin-sidebar-header">
            <a href="<?php echo e(url('admin/index.php')); ?>" class="admin-logo">
                <?php if ($logo !== ''): ?>
                    <img src="<?php echo e(url($logo)); ?>" alt="<?php echo e($siteNameValue); ?>" class="admin-logo-img">
                <?php else: ?>
                    <span class="admin-logo-text">&#11088; <?php echo e($siteNameValue); ?></span>
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
                <span class="nav-icon">&#128202;</span>
                <span class="nav-text">داشبورد</span>
            </a>

            <div class="admin-nav-group">
                <button class="admin-nav-item admin-nav-parent" data-submenu="content">
                    <span class="nav-icon">&#128196;</span>
                    <span class="nav-text">محتوا</span>
                    <span class="nav-arrow">&#8250;</span>
                </button>
                <div class="admin-nav-submenu" id="submenu-content">
                    <a href="<?php echo e(url('admin/slides.php')); ?>" class="admin-nav-subitem <?php echo $currentPage === 'slides.php' ? 'active' : ''; ?>">
                        <span class="nav-text">اسلایدها</span>
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
                    <span class="nav-icon">&#128101;</span>
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
                    <span class="nav-icon">&#128176;</span>
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

            <a href="<?php echo e(url('admin/events.php')); ?>" class="admin-nav-item <?php echo $currentPage === 'events.php' ? 'active' : ''; ?>">
                <span class="nav-icon">&#128197;</span>
                <span class="nav-text">رویدادها</span>
            </a>

            <a href="<?php echo e(url('admin/messages.php')); ?>" class="admin-nav-item <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?>">
                <span class="nav-icon">&#128172;</span>
                <span class="nav-text">پیام‌ها</span>
            </a>

            <a href="<?php echo e(url('admin/audit.php')); ?>" class="admin-nav-item <?php echo $currentPage === 'audit.php' ? 'active' : ''; ?>">
                <span class="nav-icon">&#128220;</span>
                <span class="nav-text">گزارش فعالیت‌ها</span>
            </a>

            <a href="<?php echo e(url('admin/settings.php')); ?>" class="admin-nav-item <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">
                <span class="nav-icon">&#9881;</span>
                <span class="nav-text">تنظیمات</span>
            </a>

            <form method="post" action="<?php echo e(url('admin/logout.php')); ?>" class="admin-nav-logout-form">
                <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                <button type="submit" class="admin-nav-item admin-nav-logout">
                    <span class="nav-icon">&#128682;</span>
                    <span class="nav-text">خروج</span>
                </button>
            </form>
        </nav>

        <div class="admin-sidebar-footer">
            <div class="admin-user-info">
                <div class="admin-user-avatar">&#128100;</div>
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

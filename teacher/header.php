<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

sendSecurityHeaders();
requireTeacherLogin();

$logo = siteLogo();
$currentPage = basename($_SERVER['PHP_SELF']);
$teacherName = $_SESSION['teacher_name'] ?? 'معلم';
$siteNameValue = siteName();
$pageTitleValue = isset($pageTitle) ? e($pageTitle) : 'پنل معلم | ' . e($siteNameValue);
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
<body class="teacher-layout">
    <header class="teacher-header">
        <div class="teacher-header-inner">
            <a href="<?php echo e(url('teacher/index.php')); ?>" class="teacher-logo">
                <?php if ($logo !== ''): ?>
                    <img src="<?php echo e(url($logo)); ?>" alt="<?php echo e($siteNameValue); ?>" class="teacher-logo-img">
                <?php else: ?>
                    <span class="teacher-logo-text">&#11088; <?php echo e($siteNameValue); ?></span>
                <?php endif; ?>
                <span class="teacher-logo-badge">معلم</span>
            </a>

            <nav class="teacher-nav" id="teacherNav">
                <a href="<?php echo e(url('teacher/index.php')); ?>" class="teacher-nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    <span class="nav-text">داشبورد</span>
                </a>
                <a href="<?php echo e(url('teacher/report.php')); ?>" class="teacher-nav-item <?php echo $currentPage === 'report.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
                    <span class="nav-text">گزارش روزانه</span>
                </a>
                <a href="<?php echo e(url('teacher/messages.php')); ?>" class="teacher-nav-item <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    <span class="nav-text">پیام‌ها</span>
                </a>
                <div class="teacher-nav-user">
                    <span class="teacher-user-avatar">&#128100;</span>
                    <span class="teacher-user-name"><?php echo e($teacherName); ?></span>
                </div>
                <form method="post" action="<?php echo e(url('teacher/logout.php')); ?>" class="teacher-nav-logout-form">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                    <button type="submit" class="teacher-nav-item teacher-nav-logout">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        <span class="nav-text">خروج</span>
                    </button>
                </form>
            </nav>
        </div>
    </header>

    <main class="teacher-main">
        <div class="teacher-container">

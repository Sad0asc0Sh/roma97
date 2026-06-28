<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

// Send security headers
sendSecurityHeaders();

$logo = siteLogo();
$currentPage = basename($_SERVER['PHP_SELF']);
$currentSlug = $_GET['slug'] ?? '';
$siteNameValue = siteName();
$pageTitleValue = isset($pageTitle) ? e($pageTitle) : e($siteNameValue);
$pageDescriptionValue = isset($pageDescription) ? e($pageDescription) : e(siteDescription());
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?php echo $pageDescriptionValue; ?>">
    <meta name="theme-color" content="#3D8B63">
    <title><?php echo $pageTitleValue; ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Baloo+Bhaijaan+2:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php
    // Compute a reliable relative path to the CSS asset that works regardless
    // of how SITE_URL is configured (avoids broken styles on misconfigured servers).
    $scriptDir  = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $appRootDir = str_replace('\\', '/', __DIR__ . '/..');
    $appRootDir = realpath($appRootDir) ?: $appRootDir;
    $scriptDirReal = realpath($scriptDir) ?: $scriptDir;
    // Count how many directory levels the current script is below the app root
    $relativePrefix = '';
    $tmp = $scriptDirReal;
    $appNorm = str_replace('\\', '/', $appRootDir);
    while (strlen($tmp) > strlen($appNorm) && str_starts_with(str_replace('\\', '/', $tmp), $appNorm)) {
        $relativePrefix .= '../';
        $tmp = dirname($tmp);
    }
    if ($relativePrefix === '') { $relativePrefix = './'; }
    $cssHref = $relativePrefix . 'assets/css/style.css';
    ?>
    <link rel="stylesheet" href="<?php echo e($cssHref); ?>">
</head>
<body>
<header class="site-header" id="siteHeader">
    <div class="container header-inner">
        <a class="logo" href="<?php echo e(url('index.php')); ?>">
            <?php if ($logo !== ''): ?>
                <img src="<?php echo e(url($logo)); ?>" alt="<?php echo e($siteNameValue); ?>" class="site-logo">
            <?php else: ?>
                <svg class="logo-icon-svg" width="32" height="32" viewBox="0 0 32 32" fill="none">
                    <circle cx="16" cy="16" r="15" stroke="url(#logoGrad)" stroke-width="2"/>
                    <path d="M16 8c-2.5 0-4.5 1.2-4.5 3.5 0 1.5.8 2.5 2 3.2-.5.3-1.2 1-1.5 1.8-.3.8-.2 1.5.3 2 .5.5 1.2.5 1.8.3.6-.2 1-.8 1.2-1.2h.7c.2.4.6 1 1.2 1.2.6.2 1.3.2 1.8-.3.5-.5.6-1.2.3-2-.3-.8-1-1.5-1.5-1.8 1.2-.7 2-1.7 2-3.2 0-2.3-2-3.5-4.5-3.5z" fill="url(#logoGrad)"/>
                    <circle cx="13.5" cy="12" r="1" fill="white"/>
                    <circle cx="18.5" cy="12" r="1" fill="white"/>
                    <path d="M13.5 15.5c0 0 1 1.5 2.5 1.5s2.5-1.5 2.5-1.5" stroke="white" stroke-width="0.8" stroke-linecap="round" fill="none"/>
                    <defs>
                        <linearGradient id="logoGrad" x1="0" y1="0" x2="32" y2="32">
                            <stop stop-color="#3D8B63"/>
                            <stop offset="1" stop-color="#C4724A"/>
                        </linearGradient>
                    </defs>
                </svg>
                <span class="site-name"><?php echo e($siteNameValue); ?></span>
            <?php endif; ?>
        </a>

        <nav class="site-nav" aria-label="منوی اصلی">
            <a href="<?php echo e(url('index.php')); ?>" class="nav-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                <svg class="nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span class="nav-label">خانه</span>
            </a>
            <a href="<?php echo e(url('page.php?slug=about')); ?>" class="nav-link <?php echo $currentPage === 'page.php' && $currentSlug === 'about' ? 'active' : ''; ?>">
                <svg class="nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <span class="nav-label">درباره ما</span>
            </a>
            <a href="<?php echo e(url('news.php')); ?>" class="nav-link <?php echo $currentPage === 'news.php' ? 'active' : ''; ?>">
                <svg class="nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                <span class="nav-label">اخبار</span>
            </a>
            <a href="<?php echo e(url('page.php?slug=classes')); ?>" class="nav-link <?php echo $currentPage === 'page.php' && $currentSlug === 'classes' ? 'active' : ''; ?>">
                <svg class="nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5"/></svg>
                <span class="nav-label">کلاسها</span>
            </a>
            <a href="<?php echo e(url('page.php?slug=contact')); ?>" class="nav-link <?php echo $currentPage === 'page.php' && $currentSlug === 'contact' ? 'active' : ''; ?>">
                <svg class="nav-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
                <span class="nav-label">تماس با ما</span>
            </a>

            <div class="nav-auth">
                <?php if (isParentLoggedIn()): ?>
                    <a href="<?php echo e(url('parent/index.php')); ?>" class="btn btn-primary btn-sm">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        پنل والدین
                    </a>
                <?php else: ?>
                    <a href="<?php echo e(url('login.php')); ?>" class="btn btn-primary btn-sm">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                        ورود
                    </a>
                <?php endif; ?>

                <?php if (isLoggedIn()): ?>
                    <form class="logout-form" method="post" action="<?php echo e(url('admin/logout.php')); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                        <button type="submit" class="btn btn-sm btn-outline">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                            خروج
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </nav>
    </div>
</header>
<main class="site-main">
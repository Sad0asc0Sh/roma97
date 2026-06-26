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
    <title><?php echo $pageTitleValue; ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
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
                <span class="logo-icon">&#127800;</span>
                <span class="site-name"><?php echo e($siteNameValue); ?></span>
            <?php endif; ?>
        </a>

        <button class="mobile-menu-toggle" aria-label="باز و بسته کردن منو" aria-expanded="false">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <nav class="site-nav" aria-label="منوی اصلی">
            <a href="<?php echo e(url('index.php')); ?>" class="nav-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                <span class="nav-icon">&#127968;</span>
                <span class="nav-label">خانه</span>
            </a>
            <a href="<?php echo e(url('page.php?slug=about')); ?>" class="nav-link <?php echo $currentPage === 'page.php' && $currentSlug === 'about' ? 'active' : ''; ?>">
                <span class="nav-icon">&#128212;</span>
                <span class="nav-label">درباره ما</span>
            </a>
            <a href="<?php echo e(url('news.php')); ?>" class="nav-link <?php echo $currentPage === 'news.php' ? 'active' : ''; ?>">
                <span class="nav-icon">&#128240;</span>
                <span class="nav-label">اخبار</span>
            </a>
            <a href="<?php echo e(url('page.php?slug=classes')); ?>" class="nav-link <?php echo $currentPage === 'page.php' && $currentSlug === 'classes' ? 'active' : ''; ?>">
                <span class="nav-icon">&#127752;</span>
                <span class="nav-label">کلاس‌ها</span>
            </a>
            <a href="<?php echo e(url('page.php?slug=contact')); ?>" class="nav-link <?php echo $currentPage === 'page.php' && $currentSlug === 'contact' ? 'active' : ''; ?>">
                <span class="nav-icon">&#128222;</span>
                <span class="nav-label">تماس با ما</span>
            </a>

            <div class="nav-auth">
                <?php if (isParentLoggedIn()): ?>
                    <a href="<?php echo e(url('parent/index.php')); ?>" class="btn btn-primary btn-sm">&#128101; پنل والدین</a>
                <?php else: ?>
                    <a href="<?php echo e(url('login.php')); ?>" class="btn btn-primary btn-sm">&#128274; ورود</a>
                <?php endif; ?>

                <?php if (isLoggedIn()): ?>
                    <form class="logout-form" method="post" action="<?php echo e(url('admin/logout.php')); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                        <button type="submit" class="btn btn-sm btn-outline">&#128682; خروج</button>
                    </form>
                <?php endif; ?>
            </div>
        </nav>
    </div>
</header>
<main class="site-main">

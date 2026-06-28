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
$contactPhoneValue = siteContactPhone();
$contactEmailValue = siteContactEmail();
$siteAddressValue = siteAddress();
$workingHoursValue = siteWorkingHours();
$instagramValue = siteInstagram();
$telegramValue = siteTelegram();
$whatsappValue = siteWhatsApp();
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
<div class="top-bar" id="topBar">
    <div class="container top-bar-inner">
        <div class="top-bar-info">
            <?php if ($contactPhoneValue !== ''): ?>
                <a href="tel:<?php echo e(preg_replace('/[^0-9+]/', '', $contactPhoneValue)); ?>" class="top-bar-item">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                    <span><?php echo e($contactPhoneValue); ?></span>
                </a>
            <?php endif; ?>
            <?php if ($contactEmailValue !== ''): ?>
                <a href="mailto:<?php echo e($contactEmailValue); ?>" class="top-bar-item">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <span><?php echo e($contactEmailValue); ?></span>
                </a>
            <?php endif; ?>
            <?php if ($workingHoursValue !== ''): ?>
                <span class="top-bar-item">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span><?php echo e($workingHoursValue); ?></span>
                </span>
            <?php endif; ?>
        </div>
        <div class="top-bar-social">
            <?php if ($instagramValue !== ''): ?>
                <a href="<?php echo e($instagramValue); ?>" class="top-bar-social-link" aria-label="اینستاگرام" target="_blank" rel="noopener noreferrer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                </a>
            <?php endif; ?>
            <?php if ($telegramValue !== ''): ?>
                <a href="<?php echo e($telegramValue); ?>" class="top-bar-social-link" aria-label="تلگرام" target="_blank" rel="noopener noreferrer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M11.944 0A12 12 0 000 12a12 12 0 0012 12 12 12 0 0012-12A12 12 0 0012 0a12 12 0 00-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 01.171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
                </a>
            <?php endif; ?>
            <?php if ($whatsappValue !== ''): ?>
                <a href="https://wa.me/<?php echo e(preg_replace('/[^0-9]/', '', $whatsappValue)); ?>" class="top-bar-social-link" aria-label="واتساپ" target="_blank" rel="noopener noreferrer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<header class="site-header" id="siteHeader">
    <div class="container header-inner">
        <button class="hamburger" id="hamburger" aria-label="منو" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
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
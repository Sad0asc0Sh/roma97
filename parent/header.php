<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

sendSecurityHeaders();
requireParentLogin();

$pageTitle = $pageTitle ?? 'داشبورد والدین';
$currentPage = basename($_SERVER['PHP_SELF']);
$siteNameValue = siteName();
$siteLogoValue = siteLogo();

$unreadCount = 0;
if (isset($_SESSION['parent_id'])) {
    try {
        $pdo = getDb();
        $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (parent_id IS NULL OR parent_id = ?) AND is_read = 0 AND sender_type <> 'parent'");
        $unreadStmt->execute([$_SESSION['parent_id']]);
        $unreadCount = (int) $unreadStmt->fetchColumn();
    } catch (Throwable $e) {
        // Silently fail
    }
}
$unreadCountFa = persianNumber((string) $unreadCount);
$parentDisplayName = (string) ($_SESSION['parent_first_name'] ?? $_SESSION['parent_name'] ?? 'والد');
$parentInitial = mb_substr($parentDisplayName, 0, 1, 'UTF-8');

$navChildrenActive = in_array($currentPage, ['children.php', 'child-detail.php', 'add-child.php', 'edit-child.php'], true);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php echo e($siteNameValue); ?>">
    <meta name="theme-color" content="#2E7D5B" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0C1310" media="(prefers-color-scheme: dark)">
    <link rel="manifest" href="<?php echo e(url('manifest.webmanifest')); ?>">
    <link rel="apple-touch-icon" href="<?php echo e(url('assets/icons/icon-180.png')); ?>">
    <link rel="icon" href="<?php echo e(url('assets/icons/icon-192.png')); ?>" sizes="192x192" type="image/png">
    <script>(function(){var t=localStorage.getItem('roma_theme')||(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);})();</script>
    <script>window.ROMA_SW_URL = <?php echo json_encode(url('sw.js'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;</script>
    <title><?php echo e($pageTitle); ?> | <?php echo e($siteNameValue); ?></title>
    <link rel="preload" href="<?php echo e(url('assets/fonts/Vazirmatn-Regular.woff2')); ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?php echo e(url('assets/fonts/BalooBhaijaan2-arabic.woff2')); ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/fonts.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/app-shell.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/parent-portal.css')); ?>">
    <script src="<?php echo e(asset('assets/js/app-shell.js')); ?>" defer></script>
    <script src="<?php echo e(asset('assets/js/pwa-install.js')); ?>" defer></script>
</head>
<body class="parent-portal-body">
    <a class="skip-link" href="#parentMain">پرش به محتوای اصلی</a>

    <?php /* ── Unified parent header: ONE sticky bar.
           Brand + actions on top, section nav below (desktop).
           No hamburger: on mobile the bottom-nav owns navigation,
           so there is never a second competing menu. ── */ ?>
    <header class="parent-header parent-header--unified">
        <div class="parent-header-container">
            <div class="parent-header-top">
                <a href="<?php echo e(url('parent/index.php')); ?>" class="parent-brand" aria-label="<?php echo e($siteNameValue); ?> — داشبورد والدین">
                    <?php if ($siteLogoValue !== ''): ?>
                        <img src="<?php echo e(url($siteLogoValue)); ?>" alt="<?php echo e($siteNameValue); ?>" class="parent-brand-logo">
                    <?php else: ?>
                        <span class="parent-brand-mark" aria-hidden="true">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        </span>
                    <?php endif; ?>
                    <span class="parent-brand-text">
                        <span class="parent-brand-name"><?php echo e($siteNameValue); ?></span>
                        <span class="parent-brand-badge">پنل والدین</span>
                    </span>
                </a>

                <div class="parent-header-actions">
                    <button type="button" class="parent-action-btn parent-install-btn" data-pwa-install aria-label="نصب اپلیکیشن والدین">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        <span data-pwa-install-label>نصب اپ</span>
                    </button>

                    <a href="<?php echo e(url('parent/messages.php')); ?>" class="parent-action-btn parent-bell-btn" aria-label="پیام‌ها<?php echo $unreadCount > 0 ? ' — ' . e($unreadCountFa) . ' خوانده‌نشده' : ''; ?>" title="پیام‌ها">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                        <?php if ($unreadCount > 0): ?>
                            <span class="parent-bell-dot" aria-hidden="true"><?php echo e($unreadCountFa); ?></span>
                        <?php endif; ?>
                    </a>

                    <button type="button" class="parent-action-btn" data-theme-toggle-btn aria-pressed="false" aria-label="تغییر حالت شب/روز" title="تغییر حالت شب/روز">
                        <svg class="icon-moon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
                        <svg class="icon-sun" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                    </button>

                    <div class="parent-user-menu">
                        <button type="button" class="parent-user-trigger" id="parentUserTrigger" aria-haspopup="menu" aria-expanded="false" aria-label="منوی کاربری <?php echo e($parentDisplayName); ?>">
                            <span class="app-avatar parent-avatar"><?php echo e($parentInitial); ?></span>
                            <span class="parent-user-name"><?php echo e($parentDisplayName); ?></span>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="parent-dropdown" id="parentUserMenu" role="menu" aria-label="منوی کاربری">
                            <div class="parent-dropdown-head">
                                <strong><?php echo e($parentDisplayName); ?></strong>
                                <span>والدین</span>
                            </div>
                            <div class="parent-dropdown-divider"></div>
                            <a href="<?php echo e(url('parent/profile.php')); ?>" class="parent-dropdown-item" role="menuitem">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <span>پروفایل من</span>
                            </a>
                            <a href="<?php echo e(url('parent/payments.php')); ?>" class="parent-dropdown-item" role="menuitem">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                                <span>پرداخت‌ها</span>
                            </a>
                            <button type="button" class="parent-dropdown-item" data-pwa-guide role="menuitem">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                <span>راهنمای نصب اپ</span>
                            </button>
                            <div class="parent-dropdown-divider"></div>
                            <form method="post" action="<?php echo e(url('logout.php')); ?>" style="margin:0">
                                <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                                <button type="submit" class="parent-dropdown-item danger" role="menuitem">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                                    <span>خروج از حساب</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <nav class="parent-nav" aria-label="منوی والدین">
                <a href="<?php echo e(url('parent/index.php')); ?>" class="parent-nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'index.php' ? 'aria-current="page"' : ''; ?>>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <span>داشبورد</span>
                </a>
                <a href="<?php echo e(url('parent/children.php')); ?>" class="parent-nav-item <?php echo $navChildrenActive ? 'active' : ''; ?>" <?php echo $navChildrenActive ? 'aria-current="page"' : ''; ?>>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 10-16 0"/></svg>
                    <span>فرزندان</span>
                </a>
                <a href="<?php echo e(url('parent/messages.php')); ?>" class="parent-nav-item <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'messages.php' ? 'aria-current="page"' : ''; ?>>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                    <span>پیام‌ها</span>
                    <?php if ($unreadCount > 0): ?>
                        <span class="nav-badge"><?php echo e($unreadCountFa); ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo e(url('parent/attendance.php')); ?>" class="parent-nav-item <?php echo $currentPage === 'attendance.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'attendance.php' ? 'aria-current="page"' : ''; ?>>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span>حضور و غیاب</span>
                </a>
                <a href="<?php echo e(url('parent/payments.php')); ?>" class="parent-nav-item <?php echo $currentPage === 'payments.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'payments.php' ? 'aria-current="page"' : ''; ?>>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    <span>پرداخت‌ها</span>
                </a>
                <a href="<?php echo e(url('parent/profile.php')); ?>" class="parent-nav-item <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'profile.php' ? 'aria-current="page"' : ''; ?>>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>پروفایل</span>
                </a>
            </nav>
        </div>
    </header>

    <main class="parent-main" id="parentMain">
        <div class="parent-container">

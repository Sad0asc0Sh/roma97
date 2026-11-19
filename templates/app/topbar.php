<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

// Breadcrumb & Page Info Calculation
$siteNameVal = siteName();
$currentScript = basename($_SERVER['PHP_SELF']);
$showChildDirectorySearch = $showChildDirectorySearch ?? false;

$homeUrl = url('admin/index.php');
$messagesUrl = url('admin/messages.php');

if (isset($_SESSION['teacher_id'])) {
    $homeUrl = url('teacher/index.php');
    $messagesUrl = url('teacher/messages.php');
} elseif (isset($_SESSION['parent_id'])) {
    $homeUrl = url('parent/index.php');
    $messagesUrl = url('parent/messages.php');
}

$breadcrumbs = [
    ['title' => 'داشبورد', 'url' => $homeUrl],
];

if (isset($pageTitle)) {
    $cleanTitle = str_replace([' | مدیریت | ' . $siteNameVal, ' | ' . $siteNameVal], '', $pageTitle);
    if ($currentScript !== 'index.php') {
        $breadcrumbs[] = ['title' => $cleanTitle, 'url' => ''];
    }
}

// User context
$userRoleName = isset($_SESSION['admin_id']) ? adminRoleLabel(currentAdminRole()) : 'مدیر سیستم';
$userName = $_SESSION['admin_username'] ?? $_SESSION['teacher_name'] ?? $_SESSION['parent_first_name'] ?? $_SESSION['parent_name'] ?? 'کاربر';
if (isset($_SESSION['teacher_id'])) {
    $userRoleName = 'معلم مهد';
} elseif (isset($_SESSION['parent_id'])) {
    $userRoleName = 'والدین';
}

$userFirstChar = mb_substr($userName, 0, 1, 'UTF-8');
?>
<script>
// Shared mobile menu toggle for portals without an admin sidebar (parent/teacher).
// Admin registers its auto-responsive handler in admin-modern.js; this fallback
// only runs if that handler hasn't been registered yet.
window.toggleMobileSidebar = window.toggleMobileSidebar || function(evtOrState) {
    var nav = document.querySelector('.parent-nav, #teacherNav');
    if (!nav) return;
    var forceState = typeof evtOrState === 'boolean' ? evtOrState : null;
    var shouldOpen = forceState !== null ? forceState : !nav.classList.contains('is-open');
    nav.classList.toggle('is-open', shouldOpen);
};
</script>
<header class="app-topbar">
    <div class="app-topbar-right">
        <?php if (isset($_SESSION['admin_id']) || isset($_SESSION['teacher_id']) || isset($_SESSION['parent_id'])): ?>
            <button class="admin-mobile-toggle adm-hamburger-btn" id="mobileSidebarToggle" type="button" onclick="window.toggleMobileSidebar(event)" aria-label="باز کردن یا ثابت‌سازی منو" aria-controls="adminSidebar" aria-expanded="false" title="باز کردن یا ثابت‌سازی منو">
                <span class="adm-hb-line" aria-hidden="true"></span>
                <span class="adm-hb-line" aria-hidden="true"></span>
                <span class="adm-hb-line" aria-hidden="true"></span>
            </button>
        <?php endif; ?>

        <nav class="app-breadcrumb" aria-label="مسیر صفحه">
            <?php foreach ($breadcrumbs as $index => $crumb): ?>
                <?php if ($index > 0): ?>
                    <span class="app-breadcrumb-separator" aria-hidden="true">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    </span>
                <?php endif; ?>
                <?php if (!empty($crumb['url']) && $index < count($breadcrumbs) - 1): ?>
                    <a href="<?= e($crumb['url']) ?>" class="app-breadcrumb-item"><?= e($crumb['title']) ?></a>
                <?php else: ?>
                    <span class="app-breadcrumb-item active"><?= e($crumb['title']) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="app-topbar-center">
        <div class="app-search-shell<?= $showChildDirectorySearch ? ' app-search-shell--directory' : '' ?>" data-search-shell>
            <button type="button" class="app-topbar-btn app-search-toggle" data-search-toggle aria-expanded="false" aria-controls="appSearchBox" aria-label="<?= $showChildDirectorySearch ? 'جست‌وجوی پرونده‌ها' : 'جست‌وجو در صفحه' ?>" title="<?= $showChildDirectorySearch ? 'جست‌وجوی کودک، والد یا موبایل (/)' : 'جست‌وجو در صفحه (/)' ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </button>
            <?php if ($showChildDirectorySearch): ?>
                <form class="app-search-box" id="appSearchBox" role="search" aria-label="جست‌وجوی سراسری پرونده‌ها" data-directory-search method="get" action="<?= e(url('admin/children.php')) ?>">
                    <label for="appSearchInput" class="sr-only">نام کودک، نام والد یا شماره موبایل</label>
                    <input type="search" id="appSearchInput" name="q" class="app-search-input" maxlength="100" value="<?= e($directorySearch ?? '') ?>" placeholder="نام کودک، نام والد یا موبایل">
                    <button type="submit" class="app-directory-search-button">جست‌وجو</button>
                    <button type="button" class="app-search-close" data-search-close aria-label="بستن جست‌وجو" title="بستن (Esc)">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </form>
            <?php else: ?>
                <div class="app-search-box" id="appSearchBox">
                    <input type="search" id="appSearchInput" class="app-search-input" placeholder="جست‌وجوی زنده در صفحه..." aria-label="جست‌وجوی زنده">
                    <kbd class="app-search-kbd">/</kbd>
                    <button type="button" class="app-search-close" data-search-close aria-label="بستن جست‌وجو" title="بستن (Esc)">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="app-topbar-left">
        <button type="button" class="app-topbar-btn theme-toggle-btn" id="themeToggleBtn" aria-label="تغییر حالت شب/روز" title="تغییر حالت شب/روز">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
        </button>

        <a href="<?= e($messagesUrl) ?>" class="app-topbar-btn" aria-label="اعلان‌ها" title="اعلان‌ها و پیام‌ها">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            <?php if (isset($unreadCount) && $unreadCount > 0): ?>
                <span class="badge-dot"></span>
            <?php endif; ?>
        </a>

        <div class="app-user-menu">
            <button type="button" class="app-user-trigger" id="appUserTrigger" aria-haspopup="menu" aria-expanded="false" aria-label="منوی کاربری <?= e($userName) ?>">
                <span class="app-avatar"><?= e($userFirstChar) ?></span>
                <span class="app-user-name"><?= e($userName) ?></span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="app-dropdown-menu" id="appUserMenu" role="menu" aria-label="منوی کاربری">
                <div class="app-dropdown-head">
                    <span class="app-avatar"><?= e($userFirstChar) ?></span>
                    <div class="app-dropdown-head-meta">
                        <strong><?= e($userName) ?></strong>
                        <span><?= e($userRoleName) ?></span>
                    </div>
                </div>
                <div class="app-dropdown-divider"></div>
                <?php if (isset($_SESSION['admin_id'])): ?>
                    <?php if (isPageAllowedForRole('admin/settings.php', currentAdminRole())): ?>
                        <a href="<?= e(url('admin/settings.php')) ?>" class="app-dropdown-item" role="menuitem">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                            <span>تنظیمات و پروفایل</span>
                        </a>
                    <?php endif; ?>
                    <a href="<?= e(url('index.php')) ?>" class="app-dropdown-item" role="menuitem" target="_blank" rel="noopener">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
                        <span>مشاهده سایت</span>
                    </a>
                <?php elseif (isset($_SESSION['teacher_id'])): ?>
                    <a href="<?= e(url('teacher/index.php')) ?>" class="app-dropdown-item" role="menuitem">داشبورد معلم</a>
                <?php elseif (isset($_SESSION['parent_id'])): ?>
                    <a href="<?= e(url('parent/profile.php')) ?>" class="app-dropdown-item" role="menuitem">پروفایل من</a>
                <?php endif; ?>
                <div class="app-dropdown-divider"></div>
                <?php
                $logoutUrl = url('admin/logout.php');
                if (isset($_SESSION['teacher_id'])) $logoutUrl = url('teacher/logout.php');
                if (isset($_SESSION['parent_id'])) $logoutUrl = url('logout.php');
                ?>
                <form method="post" action="<?= e($logoutUrl) ?>" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <button type="submit" class="app-dropdown-item danger" role="menuitem">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        <span>خروج از حساب</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>

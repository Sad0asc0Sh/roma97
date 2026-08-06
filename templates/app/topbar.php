<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

// Breadcrumb & Page Info Calculation
$siteNameVal = siteName();
$currentScript = basename($_SERVER['PHP_SELF']);

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
    $cleanTitle = str_replace(' | ' . $siteNameVal, '', $pageTitle);
    if ($currentScript !== 'index.php') {
        $breadcrumbs[] = ['title' => $cleanTitle, 'url' => ''];
    }
}

// User context
$userRoleName = 'مدیر سیستم';
$userName = $_SESSION['admin_username'] ?? $_SESSION['teacher_name'] ?? $_SESSION['parent_first_name'] ?? $_SESSION['parent_name'] ?? 'کاربر';
if (isset($_SESSION['teacher_id'])) {
    $userRoleName = 'معلم مهد';
} elseif (isset($_SESSION['parent_id'])) {
    $userRoleName = 'والدین';
}

$userFirstChar = mb_substr($userName, 0, 1, 'UTF-8');
?>
<header class="app-topbar">
    <div class="app-topbar-right">
        <?php if (isset($_SESSION['admin_id'])): ?>
            <button class="admin-mobile-toggle" id="mobileSidebarToggle" aria-label="منو">
                <span></span>
                <span></span>
                <span></span>
            </button>
        <?php endif; ?>

        <nav class="app-breadcrumb" aria-label="مسیر صفحه">
            <?php foreach ($breadcrumbs as $index => $crumb): ?>
                <?php if ($index > 0): ?>
                    <span class="app-breadcrumb-separator">‹</span>
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
        <div class="app-search-box">
            <input type="search" id="appSearchInput" class="app-search-input" placeholder="جست‌وجوی زنده در صفحه..." aria-label="جست‌وجوی زنده">
            <kbd class="app-search-kbd">/</kbd>
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
            <button type="button" class="app-user-trigger" id="appUserTrigger">
                <span class="app-avatar"><?= e($userFirstChar) ?></span>
                <span class="app-user-name"><?= e($userName) ?></span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="app-dropdown-menu" id="appUserMenu">
                <div style="padding:6px 12px;font-size:12px;color:var(--app-text-muted)"><?= e($userRoleName) ?></div>
                <div class="app-dropdown-divider"></div>
                <?php if (isset($_SESSION['admin_id'])): ?>
                    <a href="<?= e(url('admin/settings.php')) ?>" class="app-dropdown-item">تنظیمات و پروفایل</a>
                    <a href="<?= e(url('index.php')) ?>" class="app-dropdown-item" target="_blank" rel="noopener">مشاهده سایت</a>
                <?php elseif (isset($_SESSION['teacher_id'])): ?>
                    <a href="<?= e(url('teacher/index.php')) ?>" class="app-dropdown-item">داشبورد معلم</a>
                <?php elseif (isset($_SESSION['parent_id'])): ?>
                    <a href="<?= e(url('parent/profile.php')) ?>" class="app-dropdown-item">پروفایل من</a>
                <?php endif; ?>
                <div class="app-dropdown-divider"></div>
                <?php
                $logoutUrl = url('admin/logout.php');
                if (isset($_SESSION['teacher_id'])) $logoutUrl = url('teacher/logout.php');
                if (isset($_SESSION['parent_id'])) $logoutUrl = url('logout.php');
                ?>
                <form method="post" action="<?= e($logoutUrl) ?>" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                    <button type="submit" class="app-dropdown-item danger">خروج از حساب</button>
                </form>
            </div>
        </div>
    </div>
</header>

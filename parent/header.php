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

$unreadCount = 0;
if (isset($_SESSION['parent_id'])) {
    try {
        $pdo = getDb();
        $unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE (parent_id IS NULL OR parent_id = ?) AND is_read = 0');
        $unreadStmt->execute([$_SESSION['parent_id']]);
        $unreadCount = (int) $unreadStmt->fetchColumn();
    } catch (Throwable $e) {
        // Silently fail
    }
}
$unreadCountFa = persianNumber((string) $unreadCount);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> | <?php echo e($siteNameValue); ?></title>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo e(url('assets/css/style.css')); ?>">
</head>
<body class="parent-portal-body">
    <header class="parent-header">
        <div class="parent-header-container">
            <nav class="parent-nav" aria-label="منوی والدین">
                <a href="<?php echo e(url('parent/index.php')); ?>" class="parent-nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'index.php' ? 'aria-current="page"' : ''; ?>>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <span>داشبورد</span>
                </a>
                <?php if ($unreadCount > 0): ?>
                <a href="<?php echo e(url('parent/messages.php')); ?>" class="parent-nav-item nav-messages <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'messages.php' ? 'aria-current="page"' : ''; ?>>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                    <span>پیام‌ها</span>
                    <span class="nav-badge"><?php echo e($unreadCountFa); ?></span>
                </a>
                <?php else: ?>
                <a href="<?php echo e(url('parent/messages.php')); ?>" class="parent-nav-item <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?>" <?php echo $currentPage === 'messages.php' ? 'aria-current="page"' : ''; ?>>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                    <span>پیام‌ها</span>
                </a>
                <?php endif; ?>
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
                <form method="post" action="<?php echo e(url('logout.php')); ?>" class="parent-logout-form">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                    <button type="submit" class="parent-nav-item parent-nav-logout">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        <span>خروج</span>
                    </button>
                </form>
            </nav>

            <div class="parent-user">
                <span class="parent-user-avatar">&#128100;</span>
                <span class="parent-user-name"><?php echo e($_SESSION['parent_first_name'] ?? $_SESSION['parent_name'] ?? 'والد'); ?></span>
            </div>
        </div>
    </header>

    <main class="parent-main">
        <div class="parent-container">

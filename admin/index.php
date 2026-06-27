<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/parent_children_helpers.php';

requireLogin();

// Fetch dashboard metrics
$metrics = [
    'active_children' => 0,
    'total_teachers' => 0,
    'pending_children' => 0,
    'upcoming_events' => 0,
    'monthly_tuition' => 0,
];

$recentRegistrations = [];
$upcomingEvents = [];

try {
    $pdo = getDb();
    
    // Active children count
    $stmt = $pdo->query("SELECT COUNT(*) FROM children WHERE status = 'active'");
    $metrics['active_children'] = (int) $stmt->fetchColumn();
    
    // Pending children count
    $stmt = $pdo->query("SELECT COUNT(*) FROM children WHERE status = 'pending'");
    $metrics['pending_children'] = (int) $stmt->fetchColumn();
    
    // Total teachers
    $stmt = $pdo->query("SELECT COUNT(*) FROM teachers WHERE status = 'active'");
    $metrics['total_teachers'] = (int) $stmt->fetchColumn();
    
    // Upcoming events (next 30 days)
    $stmt = $pdo->query("SELECT COUNT(*) FROM events WHERE event_date >= CURDATE() AND event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $metrics['upcoming_events'] = (int) $stmt->fetchColumn();
    
    // This month's tuition total
    $currentMonth = date('Y-m');
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM tuition_payments WHERE payment_date LIKE :month");
    $stmt->execute([':month' => $currentMonth . '%']);
    $metrics['monthly_tuition'] = (float) $stmt->fetchColumn();
    
    // Recent registrations (last 5 children)
    $stmt = $pdo->query("SELECT id, first_name, last_name, date_of_birth, status, created_at FROM children ORDER BY created_at DESC LIMIT 5");
    $recentRegistrations = $stmt->fetchAll();
    
    // Upcoming events (next 5)
    $stmt = $pdo->query("SELECT id, title, event_date FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 5");
    $upcomingEvents = $stmt->fetchAll();
    
} catch (Throwable $e) {
    error_log($e->getMessage());
}

$pageTitle = 'داشبورد مدیر | ' . e(siteName());
require_once __DIR__ . '/header.php';
?>

<div class="admin-dashboard">
    <!-- Page Header -->
    <div class="admin-page-header">
        <h1>داشبورد مدیریت</h1>
        <p>خلاصه وضعیت مهد کودک <?= e(siteName()) ?></p>
    </div>

    <!-- Metrics Grid -->
    <div class="metrics-grid">
        <div class="metric-card metric-primary">
            <div class="metric-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 10-16 0"/></svg></div>
            <div class="metric-content">
                <div class="metric-value"><?= e((string) $metrics['active_children']) ?></div>
                <div class="metric-label">کودکان فعال</div>
            </div>
            <a href="<?= e(url('admin/children.php?status=active')) ?>" class="metric-link">مشاهده همه ←</a>
        </div>

        <div class="metric-card metric-secondary">
            <div class="metric-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
            <div class="metric-content">
                <div class="metric-value"><?= e((string) $metrics['total_teachers']) ?></div>
                <div class="metric-label">معلمان</div>
            </div>
            <a href="<?= e(url('admin/teachers.php')) ?>" class="metric-link">مدیریت ←</a>
        </div>

        <div class="metric-card metric-accent">
            <div class="metric-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
            <div class="metric-content">
                <div class="metric-value">$<?= e(number_format($metrics['monthly_tuition'], 2)) ?></div>
                <div class="metric-label">شهریه این ماه</div>
            </div>
            <a href="<?= e(url('admin/tuition.php')) ?>" class="metric-link">مشاهده جزئیات ←</a>
        </div>

        <div class="metric-card metric-info">
            <div class="metric-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg></div>
            <div class="metric-content">
                <div class="metric-value"><?= e((string) $metrics['upcoming_events']) ?></div>
                <div class="metric-label">رویدادهای پیش‌رو</div>
            </div>
            <a href="<?= e(url('admin/events.php')) ?>" class="metric-link">مشاهده تقویم ←</a>
        </div>
    </div>

    <?php if ($metrics['pending_children'] > 0): ?>
    <div class="alert alert-warning" role="alert">
        <span class="alert-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></span>
        <div>
            <strong>اقدام لازم:</strong> <?= e((string) $metrics['pending_children']) ?> ثبت‌نام در انتظار تأیید است.
            <a href="<?= e(url('admin/children.php?status=pending')) ?>" class="alert-link">بررسی ←</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="admin-section">
        <div class="admin-section-header">
            <h2 class="admin-section-title">اقدامات سریع</h2>
        </div>
        <div class="quick-actions-grid">
            <a href="<?= e(url('admin/children.php')) ?>" class="quick-action">
                <span class="quick-action-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 10-16 0"/></svg></span>
                <span class="quick-action-text">مدیریت کودکان</span>
            </a>
            <a href="<?= e(url('admin/attendance.php')) ?>" class="quick-action">
                <span class="quick-action-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg></span>
                <span class="quick-action-text">ثبت حضور و غیاب</span>
            </a>
            <a href="<?= e(url('admin/events.php')) ?>" class="quick-action">
                <span class="quick-action-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg></span>
                <span class="quick-action-text">افزودن رویداد</span>
            </a>
            <a href="<?= e(url('admin/news.php')) ?>" class="quick-action">
                <span class="quick-action-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2"/></svg></span>
                <span class="quick-action-text">ارسال خبر</span>
            </a>
            <a href="<?= e(url('admin/teachers.php')) ?>" class="quick-action">
                <span class="quick-action-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                <span class="quick-action-text">مدیریت معلمان</span>
            </a>
            <a href="<?= e(url('admin/classrooms.php')) ?>" class="quick-action">
                <span class="quick-action-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
                <span class="quick-action-text">کلاس‌ها</span>
            </a>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div class="admin-two-column">
        <!-- Recent Registrations -->
        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title">ثبت‌نام‌های اخیر</h2>
                <a href="<?= e(url('admin/children.php')) ?>" class="btn btn-outline btn-sm">مشاهده همه</a>
            </div>
            <?php if (empty($recentRegistrations)): ?>
                <div class="empty-state empty-state-sm">
                    <div class="empty-state-icon">📭</div>
                    <p>ثبت‌نام اخیری وجود ندارد.</p>
                </div>
            <?php else: ?>
                <div class="quick-list">
                    <?php foreach ($recentRegistrations as $child): ?>
                        <div class="quick-list-item">
                            <div class="quick-list-avatar"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 10-16 0"/></svg></div>
                            <div class="quick-list-content">
                                <div class="quick-list-title">
                                    <a href="<?= e(url('admin/child-detail.php?id=' . $child['id'])) ?>">
                                        <?= e($child['first_name'] . ' ' . $child['last_name']) ?>
                                    </a>
                                </div>
                                <div class="quick-list-meta">
                                    <?php
                                    $statusClass = match($child['status']) {
                                        'active' => 'badge-success',
                                        'pending' => 'badge-warning',
                                        'inactive' => 'badge-danger',
                                        default => 'badge-info'
                                    };
                                    ?>
                                    <span class="badge <?= $statusClass ?>"><?= e(parentChildEnrollmentLabel((string) $child['status'])) ?></span>
                                    <span class="text-muted">• <?= e(date('M j, Y', strtotime($child['created_at']))) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Upcoming Events -->
        <div class="admin-section">
            <div class="admin-section-header">
                <h2 class="admin-section-title">رویدادهای پیش‌رو</h2>
                <a href="<?= e(url('admin/events.php')) ?>" class="btn btn-outline btn-sm">مشاهده همه</a>
            </div>
            <?php if (empty($upcomingEvents)): ?>
                <div class="empty-state empty-state-sm">
                    <div class="empty-state-icon">📭</div>
                    <p>هیچ رویداد آینده‌ای برنامه‌ریزی نشده است.</p>
                </div>
            <?php else: ?>
                <div class="quick-list">
                    <?php foreach ($upcomingEvents as $event): ?>
                        <div class="quick-list-item">
                            <div class="quick-list-avatar"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg></div>
                            <div class="quick-list-content">
                                <div class="quick-list-title">
                                    <a href="<?= e(url('admin/events.php')) ?>">
                                        <?= e($event['title']) ?>
                                    </a>
                                </div>
                                <div class="quick-list-meta">
                                    <span class="text-muted">
                                        <?= e(date('l, M j, Y', strtotime($event['event_date']))) ?>
                                        <?php if (!empty($event['location'])): ?>
                                            • <?= e($event['location']) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

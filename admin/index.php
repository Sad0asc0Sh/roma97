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
            <div class="metric-icon">👶</div>
            <div class="metric-content">
                <div class="metric-value"><?= e((string) $metrics['active_children']) ?></div>
                <div class="metric-label">کودکان فعال</div>
            </div>
            <a href="<?= e(url('admin/children.php?status=active')) ?>" class="metric-link">مشاهده همه ←</a>
        </div>

        <div class="metric-card metric-secondary">
            <div class="metric-icon">👨‍🏫</div>
            <div class="metric-content">
                <div class="metric-value"><?= e((string) $metrics['total_teachers']) ?></div>
                <div class="metric-label">معلمان</div>
            </div>
            <a href="<?= e(url('admin/teachers.php')) ?>" class="metric-link">مدیریت ←</a>
        </div>

        <div class="metric-card metric-accent">
            <div class="metric-icon">💰</div>
            <div class="metric-content">
                <div class="metric-value">$<?= e(number_format($metrics['monthly_tuition'], 2)) ?></div>
                <div class="metric-label">شهریه این ماه</div>
            </div>
            <a href="<?= e(url('admin/tuition.php')) ?>" class="metric-link">مشاهده جزئیات ←</a>
        </div>

        <div class="metric-card metric-info">
            <div class="metric-icon">📅</div>
            <div class="metric-content">
                <div class="metric-value"><?= e((string) $metrics['upcoming_events']) ?></div>
                <div class="metric-label">رویدادهای پیش‌رو</div>
            </div>
            <a href="<?= e(url('admin/events.php')) ?>" class="metric-link">مشاهده تقویم ←</a>
        </div>
    </div>

    <?php if ($metrics['pending_children'] > 0): ?>
    <div class="alert alert-warning" role="alert">
        <span class="alert-icon">📋</span>
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
            <a href="<?= e(url('admin/children.php')) ?>" class="quick-action-card">
                <span class="quick-action-icon">👶</span>
                <span class="quick-action-text">مدیریت کودکان</span>
            </a>
            <a href="<?= e(url('admin/attendance.php')) ?>" class="quick-action-card">
                <span class="quick-action-icon">✅</span>
                <span class="quick-action-text">ثبت حضور و غیاب</span>
            </a>
            <a href="<?= e(url('admin/events.php')) ?>" class="quick-action-card">
                <span class="quick-action-icon">📅</span>
                <span class="quick-action-text">افزودن رویداد</span>
            </a>
            <a href="<?= e(url('admin/news.php')) ?>" class="quick-action-card">
                <span class="quick-action-icon">📰</span>
                <span class="quick-action-text">ارسال خبر</span>
            </a>
            <a href="<?= e(url('admin/teachers.php')) ?>" class="quick-action-card">
                <span class="quick-action-icon">👨‍🏫</span>
                <span class="quick-action-text">مدیریت معلمان</span>
            </a>
            <a href="<?= e(url('admin/classrooms.php')) ?>" class="quick-action-card">
                <span class="quick-action-icon">🏫</span>
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
                            <div class="quick-list-avatar">👶</div>
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
                            <div class="quick-list-avatar">📅</div>
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

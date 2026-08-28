<?php
declare(strict_types=1);

if (!defined('ROOMA_APP')) {
    die('Access denied.');
}

require_once __DIR__ . '/../includes/ui.php';

$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF']);
$currentSlug = $currentSlug ?? (string) ($_GET['slug'] ?? '');

// The bottom bar shows four content destinations + one account slot, so the
// labels stay legible even on a 320px screen.
$bottomItems = roomaPublicNav($currentPage, $currentSlug);
$bottomItems = array_values(array_filter(
    $bottomItems,
    static fn (array $item): bool => $item['key'] !== 'about'
));

if (isParentLoggedIn()) {
    $accountItem = ['label' => 'پنل', 'href' => 'parent/index.php', 'icon' => 'user', 'active' => false];
} elseif (isLoggedIn()) {
    $accountItem = ['label' => 'پنل', 'href' => 'admin/index.php', 'icon' => 'grid', 'active' => false];
} else {
    $accountItem = ['label' => 'ورود', 'href' => 'login.php', 'icon' => 'login', 'active' => $currentPage === 'login.php'];
}
?>
<nav class="bottom-nav" data-bottom-nav aria-label="منوی ناوبری موبایل">
    <?php foreach ($bottomItems as $item): ?>
        <a class="bottom-nav__item<?php echo $item['active'] ? ' is-active' : ''; ?>"
           href="<?php echo e(url($item['href'])); ?>"
           <?php echo $item['active'] ? 'aria-current="page"' : ''; ?>>
            <span class="bottom-nav__icon-wrap"><?php echo icon($item['icon'], 21); ?></span>
            <span class="bottom-nav__label"><?php echo e($item['short']); ?></span>
        </a>
    <?php endforeach; ?>

    <a class="bottom-nav__item<?php echo $accountItem['active'] ? ' is-active' : ''; ?>"
       href="<?php echo e(url($accountItem['href'])); ?>">
        <span class="bottom-nav__icon-wrap"><?php echo icon($accountItem['icon'], 21); ?></span>
        <span class="bottom-nav__label"><?php echo e($accountItem['label']); ?></span>
    </a>
</nav>

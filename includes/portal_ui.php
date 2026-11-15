<?php
declare(strict_types=1);
if (!defined('ROOMA_APP')) { die('Access denied.'); }

/** Shared daily overview: the illustration is decorative; every action is a real route. */
function portalWelcome(string $eyebrow, string $title, string $description, array $actions = []): void
{
    ?>
    <section class="portal-welcome" aria-label="خلاصه روز">
        <div class="portal-welcome-copy">
            <span class="portal-eyebrow"><?= e($eyebrow) ?></span>
            <h1><?= e($title) ?></h1>
            <p><?= e($description) ?></p>
            <?php if ($actions !== []): ?>
                <div class="portal-actions">
                    <?php foreach ($actions as $i => $action): ?>
                        <a class="portal-button <?= $i === 0 ? 'portal-button-primary' : '' ?>" href="<?= e(url($action[1])) ?>"><?= e($action[0]) ?><span aria-hidden="true">←</span></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="portal-day">
            <svg class="portal-day-art" viewBox="0 0 180 100" fill="none" aria-hidden="true">
                <circle cx="90" cy="42" r="27" fill="#F5C65C"/>
                <path d="M90 4V0M53 10l-6-7M127 10l6-7M49 40h-9M131 40h9" stroke="#D7A23A" stroke-width="3" stroke-linecap="round"/>
                <path d="M18 99V70a22 22 0 0144 0v29" fill="#B6D4EC"/>
                <path d="M68 99V71a25 25 0 0150 0v28" fill="#72B5A9"/>
                <path d="M124 99V79a17 17 0 0134 0v20" fill="#E9A99A"/>
                <path d="M84 42q6 7 12 0" stroke="#6F5620" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
            <span>امروز در مهد</span>
            <strong><?= e(shamsiDate(date('Y-m-d'), 'with_day')) ?></strong>
        </div>
    </section>
    <?php
}

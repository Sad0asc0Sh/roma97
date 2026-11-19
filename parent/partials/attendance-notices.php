<?php if (!defined('ROOMA_APP')) { die('Access denied.'); } ?>

<section class="parent-card parent-operational-card" id="attendance-notice">
    <h2>اعلام غیبت یا تأخیر</h2>
    <?php if ($noticeError !== null): ?><p class="alert" role="alert"><?= e($noticeError) ?></p><?php endif; ?>
    <?php if ($noticeSuccess !== null): ?><p class="notice" role="status"><?= e($noticeSuccess) ?></p><?php endif; ?>

    <?php if ($noticeChildren === []): ?>
        <p class="muted">امکان اعلام برای کودکی فعال است که کلاس و روزهای کاری‌اش تعیین شده باشد.</p>
    <?php else: ?>
        <form method="post" action="<?= e($noticeUrl . '#attendance-notice') ?>" class="parent-notice-form">
            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_notice">
            <?php if ($selectedNotice !== null): ?>
                <input type="hidden" name="child_id" value="<?= e($selectedNotice['child_id']) ?>">
            <?php endif; ?>
            <label>کودک
                <select <?= $selectedNotice === null ? 'name="child_id" required' : 'disabled' ?> class="form-control">
                    <?php foreach ($noticeChildren as $noticeChild): ?>
                        <option value="<?= e($noticeChild['id']) ?>" <?= (int) $noticeChild['id'] === $selectedNoticeChild ? 'selected' : '' ?>>
                            <?= e(trim($noticeChild['first_name'] . ' ' . $noticeChild['last_name'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>تاریخ (شمسی)
                <input type="text" name="notice_date" class="form-control <?= $selectedNotice === null ? 'shamsi-datepicker' : '' ?>" data-year-past="0"
                       <?= $selectedNotice === null ? '' : 'readonly' ?>
                       value="<?= e(shamsiDate($selectedNotice['notice_date'] ?? $todayYmd, 'numeric')) ?>" required>
            </label>
            <label>نوع اعلام
                <select name="notice_type" class="form-control" required>
                    <?php foreach (ATTENDANCE_NOTICE_TYPES as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= ($selectedNotice['notice_type'] ?? 'absent') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>ساعت تقریبی ورود در صورت تأخیر
                <input type="time" name="expected_arrival" class="form-control" value="<?= e(substr($selectedNotice['expected_arrival'] ?? '', 0, 5)) ?>">
            </label>
            <label class="parent-notice-note">یادداشت برای پذیرش و مربی (اختیاری)
                <input type="text" name="note" class="form-control" maxlength="500" value="<?= e($selectedNotice['note'] ?? '') ?>">
            </label>
            <div class="parent-notice-actions">
                <button type="submit" class="btn btn-primary"><?= $selectedNotice === null ? 'ثبت اعلام' : 'ذخیره تغییر' ?></button>
                <?php if ($selectedNotice !== null): ?>
                    <a class="btn btn-outline" href="<?= e($noticeUrl . '#attendance-notice') ?>">اعلام جدید</a>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($upcomingNotices !== []): ?>
        <ul class="parent-notices-list">
            <?php foreach ($upcomingNotices as $notice): ?>
                <li>
                    <div>
                        <strong><?= e(trim($notice['first_name'] . ' ' . $notice['last_name'])) ?> · <?= e(shamsiDate($notice['notice_date'])) ?></strong>
                        <p><?= e(attendanceNoticeLabel($notice)) ?></p>
                        <?php if ($notice['note'] !== null): ?><small><?= e($notice['note']) ?></small><?php endif; ?>
                    </div>
                    <?php if ($notice['attendance_id'] === null): ?>
                        <div class="parent-notice-actions">
                            <?php if (isset($eligibleNoticeChildIds[(int) $notice['child_id']])): ?>
                                <a class="btn btn-outline btn-sm" href="<?= e($noticeUrl . '&' . http_build_query([
                                    'notice_child' => $notice['child_id'], 'notice_date' => $notice['notice_date'],
                                ]) . '#attendance-notice') ?>">ویرایش</a>
                            <?php endif; ?>
                            <form method="post" action="<?= e($noticeUrl . '#attendance-notice') ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                <input type="hidden" name="action" value="cancel_notice">
                                <input type="hidden" name="child_id" value="<?= e($notice['child_id']) ?>">
                                <input type="hidden" name="notice_date" value="<?= e($notice['notice_date']) ?>">
                                <button type="submit" class="btn btn-outline btn-sm">لغو اعلام</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <span class="muted">وضعیت حضور ثبت شده</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if (!defined('ROOMA_APP')) { die('Access denied.'); } ?>

<section class="admin-card" id="family-inbox">
    <div class="admin-card-header">
        <h2>پیگیری پیام خانواده‌ها</h2>
    </div>
    <div class="admin-card-body">
        <?php if ($replySuccess !== null): ?>
            <div class="notice" role="status"><?= e($replySuccess) ?></div>
        <?php endif; ?>
        <?php if ($replyError !== null): ?>
            <div class="alert" role="alert"><?= e($replyError) ?></div>
        <?php endif; ?>

        <nav class="filter-nav" aria-label="فیلتر پیگیری پیام‌ها">
            <?php foreach ($replyFilters as $value => $label): ?>
                <a href="<?= e($inboxUrl($value)) ?>" <?= $value === $replyFilter ? 'class="is-active" aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($unreadReplyCount > 0): ?>
            <p class="muted"><?= e(persianNumber($unreadReplyCount)) ?> پیام خوانده‌نشده</p>
        <?php endif; ?>

        <?php if ($parentReplies === []): ?>
            <p class="muted">پیامی در این فهرست نیست.</p>
        <?php endif; ?>
        <?php foreach ($parentReplies as $reply): ?>
            <?php
            $replyId = (int) $reply['id'];
            $status = $reply['follow_up_status'];
            $overdue = isMessageFollowUpOverdue($reply, $now);
            $deadline = $reply['response_due_at'];
            $responsible = $reply['follow_up_admin_id'];
            $assigneeName = $responsible !== null ? ($followUpStaff[$responsible] ?? null) : null;
            $suggestedStaffId = $responsible ?? ($deadline === null ? $reply['recipient_admin_id'] : null);
            $parentName = trim($reply['first_name'] . ' ' . $reply['last_name']);
            ?>
            <details class="family-message">
                <summary>
                    <span>
                        <strong><?= e($reply['subject']) ?></strong>
                        <small class="family-message-meta">
                            <?= e($parentName) ?> · <?= e($assigneeName ?? 'بدون مسئول') ?>
                            · <?= $deadline === null ? 'مهلت تعیین نشده' : 'مهلت ' . e(shamsiDate($deadline, 'with_time')) ?>
                        </small>
                    </span>
                    <span class="family-message-badges">
                        <span class="badge <?= $status === 'resolved' ? 'badge-success' : 'badge-info' ?>"><?= e(MESSAGE_FOLLOW_UP_LABELS[$status]) ?></span>
                        <?php if ($overdue): ?><span class="badge badge-warning">گذشته از مهلت</span><?php endif; ?>
                        <?php if ((int) $reply['is_read'] === 0): ?><span class="badge badge-warning">خوانده‌نشده</span><?php endif; ?>
                    </span>
                </summary>
                <div class="family-message-body">
                    <small class="muted"><?= e(shamsiDbTimestamp($reply['created_at'], 'with_time')) ?></small>
                    <p><?= nl2br(e($reply['body'])) ?></p>
                    <div class="family-message-actions">
                        <?php if ($reply['parent_status'] === 'active'): ?>
                            <a class="btn btn-sm btn-outline" href="<?= e(url('admin/messages.php?parent_id=' . (int) $reply['parent_id'] . '#compose-message')) ?>">پاسخ به خانواده</a>
                        <?php endif; ?>
                        <?php if (!empty($reply['phone'])): ?>
                            <a class="btn btn-sm btn-outline" href="tel:<?= e($reply['phone']) ?>">تماس با خانواده</a>
                        <?php endif; ?>
                        <?php if ((int) $reply['is_read'] === 0): ?>
                            <form method="post" action="<?= e($inboxUrl($replyFilter)) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                                <input type="hidden" name="reply_id" value="<?= e($replyId) ?>">
                                <button type="submit" class="btn btn-sm btn-outline" name="action" value="mark_reply_read">علامت‌گذاری خوانده‌شده</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if (mayEditMessageFollowUp($reply, $adminId, $canEscalate, $now)): ?>
                        <form method="post" action="<?= e($inboxUrl($replyFilter)) ?>" class="family-message-follow-up">
                            <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                            <input type="hidden" name="reply_id" value="<?= e($replyId) ?>">
                            <label>
                                وضعیت پیگیری
                                <select name="follow_up_status" class="form-control">
                                    <?php foreach (MESSAGE_FOLLOW_UP_LABELS as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                مسئول پیگیری
                                <select name="follow_up_admin_id" class="form-control">
                                    <option value="">بدون مسئول</option>
                                    <?php foreach ($followUpStaff as $staffId => $username): ?>
                                        <option value="<?= e($staffId) ?>" <?= (int) $suggestedStaffId === (int) $staffId ? 'selected' : '' ?>><?= e($username) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <?php $deadlineInput = $deadline ?? $defaultDeadlineInput; ?>
                            <label>
                                تاریخ شمسی مهلت پاسخ
                                <input type="text" name="response_due_date" class="form-control shamsi-datepicker"
                                       dir="ltr" inputmode="numeric" autocomplete="off" required
                                       value="<?= e(shamsiDate($deadlineInput, 'numeric')) ?>">
                            </label>
                            <label>
                                ساعت مهلت به وقت تهران
                                <input type="text" name="response_due_time" class="form-control" data-iran-time
                                       dir="ltr" inputmode="numeric" placeholder="۱۴:۳۰" required
                                       value="<?= e(iranTimeForInput(substr($deadlineInput, 11))) ?>">
                            </label>
                            <button type="submit" class="btn btn-primary" name="action" value="save_follow_up">ذخیره پیگیری</button>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
        <?= renderPagination($replyPagination, url('admin/messages.php'), ['filter' => $replyFilter], 'reply_page') ?>
    </div>
</section>

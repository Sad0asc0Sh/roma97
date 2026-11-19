<?php if (!defined('ROOMA_APP')) { exit; } ?>

<section class="admin-section" id="invoiceQueue" aria-labelledby="invoiceQueueTitle">
    <div class="admin-section-header invoice-toolbar">
        <h2 class="admin-section-title" id="invoiceQueueTitle">صف پیگیری شهریه</h2>
        <form method="get" action="<?= e(url('admin/tuition.php#invoiceQueue')) ?>" class="invoice-toolbar">
            <label for="invoice_filter">وضعیت
                <select name="filter" id="invoice_filter" class="form-control">
                    <?php foreach ($invoiceFilters as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $invoiceFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="view_month_year">ماه شهریه
                <select name="month_year" id="view_month_year" class="form-control">
                    <option value="">همهٔ ماه‌ها در صف پیگیری</option>
                    <?php foreach ($monthChoices as $choice): ?>
                        <option value="<?= e($choice['value']) ?>" <?= ($invoiceFilter === 'all' ? $viewMonthYear : $filterMonth) === $choice['value'] ? 'selected' : '' ?>><?= e($choice['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn btn-secondary">اعمال فیلتر</button>
        </form>
    </div>

    <?php if ($monthTotals !== []): ?>
        <dl class="invoice-month-totals">
            <?php foreach (['expected' => 'مبلغ مورد انتظار ماه', 'paid' => 'دریافتی', 'due' => 'ماندهٔ پرداخت‌نشده'] as $metric => $label): ?>
                <div><dt><?= e($label) ?></dt><dd><?= e(persianNumber(number_format($monthTotals[$metric]))) ?> تومان</dd></div>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>

    <?php if ($dashboardStatus === []): ?>
        <p class="muted"><?= e(match ($invoiceFilter) {
            'review' => 'مورد سررسیدگذشته یا دارای مغایرت پیدا نشد.',
            'needs_due_date' => 'صورتحساب پرداخت‌نشدهٔ بدون سررسید پیدا نشد.',
            default => 'صورتحسابی برای این ماه پیدا نشد.',
        }) ?></p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr>
                    <th scope="col">کودک و والد</th>
                    <th scope="col">ماه شهریه</th>
                    <th scope="col">سررسید</th>
                    <th scope="col">مبلغ / دریافتی</th>
                    <th scope="col">وضعیت</th>
                    <th scope="col">عملیات</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($dashboardStatus as $bill): ?>
                        <?php
                        $billUrl = url('admin/tuition.php?' . http_build_query([
                            'child_id' => $bill['child_id'], 'month_year' => $bill['month_year'], 'filter' => $invoiceFilter,
                        ]));
                        $reason = tuitionInvoiceNeedsReview($bill);
                        $badge = match ($bill['state']) {
                            'overdue' => 'badge-danger', 'discrepancy', 'needs_due_date' => 'badge-warning',
                            'settled' => 'badge-success', default => 'badge-info',
                        };
                        ?>
                        <tr data-invoice-state="<?= e($bill['state']) ?>">
                            <th scope="row"><?= e($bill['child_name']) ?><br><small class="muted"><?= e($bill['parent_name']) ?></small></th>
                            <td><?= e(formatShamsiMonthYear($bill['month_year'])) ?></td>
                            <td><?= $bill['due_date'] === null ? '—' : e(shamsiDate($bill['due_date'])) ?></td>
                            <td>
                                <?= e(persianNumber(number_format($bill['expected']))) ?> / <?= e(persianNumber(number_format($bill['paid']))) ?> تومان
                                <?php if (!$bill['has_plan']): ?><br><small class="muted">مبلغ برآوردی</small><?php endif; ?>
                                <?php if (abs($bill['balance']) >= tuitionSettleTolerance()): ?>
                                    <br><small><?= $bill['balance'] < 0 ? 'اعتبار' : 'مانده' ?> <?= e(persianNumber(number_format(abs($bill['balance'])))) ?> تومان</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $badge ?>"><?= e(TUITION_INVOICE_LABELS[$bill['state']]) ?></span>
                                <?php if ($reason !== null): ?><small class="invoice-discrepancy"><?= e($reason) ?></small><?php endif; ?>
                            </td>
                            <td>
                                <div class="invoice-row-actions">
                                    <a class="btn btn-outline btn-sm" href="<?= e($billUrl . '#invoicePlan') ?>"><?= $bill['has_plan'] ? 'ویرایش صورتحساب' : 'صدور صورتحساب' ?></a>
                                    <?php if ($bill['balance'] >= tuitionSettleTolerance()): ?>
                                        <a class="btn btn-secondary btn-sm" href="<?= e($billUrl . '#recordForm') ?>">ثبت پرداخت</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($pagination, url('admin/tuition.php'), ['filter' => $invoiceFilter, 'month_year' => $filterMonth ?? '']) ?>
    <?php endif; ?>
</section>

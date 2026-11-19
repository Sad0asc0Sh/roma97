<?php
declare(strict_types=1);
// Partial: one dynamic child block for admin/add-parent.php.
// Expects: $ci (int|string index), $child (array), $allClassrooms, $childStatusOptions.
if (!defined('ROOMA_APP')) { die('Access denied.'); }
$cbPrefix = 'children[' . $ci . ']';
$cbId = 'child_' . $ci . '_';
$cbStatus = (string) ($child['status'] ?? 'active');
$cbGender = (string) ($child['gender'] ?? '');
$cbClassroom = (int) ($child['classroom_id'] ?? 0);
?>
<div class="form-card adm-panel-muted" data-child-block>
    <div class="admin-card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:0 0 12px 0;margin-bottom:14px;">
        <h3 data-child-title style="margin:0;font-size:0.98rem;font-weight:700;">کودک</h3>
        <button type="button" class="btn btn-sm btn-reject" data-remove-child>حذف این کودک</button>
    </div>
    <div class="form-grid">
        <div class="form-group">
            <label>نام <span class="req">*</span></label>
            <input type="text" name="<?= e($cbPrefix . '[first_name]') ?>" maxlength="100" value="<?= e((string) ($child['first_name'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label>نام خانوادگی <span class="req">*</span></label>
            <input type="text" name="<?= e($cbPrefix . '[last_name]') ?>" maxlength="100" value="<?= e((string) ($child['last_name'] ?? '')) ?>">
        </div>
    </div>
    <div class="form-grid">
        <div class="form-group">
            <label>نام مستعار (اختیاری)</label>
            <input type="text" name="<?= e($cbPrefix . '[preferred_name]') ?>" maxlength="100" value="<?= e((string) ($child['preferred_name'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label>تاریخ شمسی تولد <span class="req">*</span></label>
            <input type="text" name="<?= e($cbPrefix . '[date_of_birth]') ?>" class="shamsi-datepicker" data-datepicker="birth" dir="ltr" inputmode="numeric" autocomplete="off"
                               data-min-date="<?= e(shamsiDate(earliestChildBirthDate(), 'Y-m-d')) ?>" data-max-date="<?= e(shamsiDate(strtotime('yesterday'), 'Y-m-d')) ?>" placeholder="۱۳۹۹/۰۵/۱۸" value="<?= e((string) ($child['date_of_birth'] ?? '')) ?>">
        </div>
    </div>
    <div class="form-grid">
        <div class="form-group">
            <label>جنسیت</label>
            <select name="<?= e($cbPrefix . '[gender]') ?>">
                <option value="" <?= $cbGender === '' ? 'selected' : '' ?>>نامشخص</option>
                <option value="male" <?= $cbGender === 'male' ? 'selected' : '' ?>>پسر</option>
                <option value="female" <?= $cbGender === 'female' ? 'selected' : '' ?>>دختر</option>
                <option value="other" <?= $cbGender === 'other' ? 'selected' : '' ?>>سایر</option>
            </select>
        </div>
        <div class="form-group">
            <label>وضعیت</label>
            <select name="<?= e($cbPrefix . '[status]') ?>">
                <?php foreach (($childStatusOptions ?? ['active'=>'فعال','pending'=>'در انتظار']) as $sVal => $sLabel): ?>
                    <option value="<?= e((string) $sVal) ?>" <?= $cbStatus === $sVal ? 'selected' : '' ?>><?= e((string) $sLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-group">
        <label>کلاس (اختیاری — فقط برای وضعیت فعال)</label>
        <select name="<?= e($cbPrefix . '[classroom_id]') ?>">
            <option value="0">— بدون کلاس —</option>
            <?php foreach (($allClassrooms ?? []) as $cl): ?>
                <option value="<?= (int) $cl['id'] ?>" <?= $cbClassroom === (int) $cl['id'] ? 'selected' : '' ?>>
                    <?= e((string) $cl['name']) ?><?= !empty($cl['teacher_name']) ? ' (' . e((string) $cl['teacher_name']) . ')' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>حساسیت‌ها</label>
        <textarea name="<?= e($cbPrefix . '[allergies]') ?>" maxlength="5000" rows="3"><?= e((string) ($child['allergies'] ?? '')) ?></textarea>
    </div>
    <div class="form-group">
        <label>توضیحات پزشکی</label>
        <textarea name="<?= e($cbPrefix . '[medical_notes]') ?>" maxlength="5000" rows="3"><?= e((string) ($child['medical_notes'] ?? '')) ?></textarea>
    </div>
    <div class="form-grid">
        <div class="form-group">
            <label>ولی دوم — نام</label>
            <input type="text" name="<?= e($cbPrefix . '[second_guardian_name]') ?>" maxlength="200" value="<?= e((string) ($child['second_guardian_name'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label>ولی دوم — شماره تماس</label>
            <input type="tel" name="<?= e($cbPrefix . '[second_guardian_phone]') ?>" maxlength="20" value="<?= e((string) ($child['second_guardian_phone'] ?? '')) ?>">
        </div>
    </div>
</div>

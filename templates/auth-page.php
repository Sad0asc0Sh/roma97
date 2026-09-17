<?php
if (!defined('ROOMA_APP')) { exit; }
$mode = $authMode ?? 'login';
$isRegister = $mode === 'register';
$isForgot = $mode === 'forgot';
$isReset = $mode === 'reset';
$isVerify = ($isRegister || $isForgot) && ($step ?? '') === 'verify';
$fieldErrors = $fieldErrors ?? [];
$messages = $errors ?? [];
if (!empty($error)) { $messages[] = $error; }
$success = $successMessage ?? $successFlash ?? null;
$displayPhone = $isRegister ? ($verifyPhone ?? '') : ($phone ?? '');
$heading = $isRegister ? ($isVerify ? 'شماره‌تان را تأیید کنید' : 'به خانوادهٔ ما بپیوندید')
    : ($isForgot ? ($isVerify ? 'یک رمز تازه انتخاب کنید' : 'رمزتان را فراموش کرده‌اید؟')
    : ($isReset ? 'یک رمز تازه انتخاب کنید' : 'خوش برگشتید!'));
$description = $isRegister ? 'حساب والدین را بسازید و همراه روزهای کودکتان باشید.'
    : ($isForgot ? 'شماره موبایل حساب خود را وارد کنید تا کد بازیابی را بفرستیم.'
    : ($isReset ? 'رمز جدید را وارد کنید؛ بعد به صفحهٔ ورود برمی‌گردید.' : 'برای دیدن تازه‌های کودکتان، وارد حساب والدین شوید.'));
if ($isVerify) { $description = 'کد ۵ رقمی پیامک‌شده را وارد کنید. اعتبار هر کد ۳ دقیقه است.'; }
$endpoint = $isRegister ? 'register.php' : ($isForgot ? 'forgot-password.php' : ($isReset ? 'reset-password.php' : 'login.php'));
$formAction = url($endpoint . ($isVerify ? '?step=verify&phone=' . urlencode($displayPhone) : ''));
$name = siteName();
$logo = siteLogo();
$renderField = static function (string $id, string $label, string $type, string $value = '', string $hint = '', string $autocomplete = '') use ($fieldErrors): void {
    $isPassword = $type === 'password';
    $otp = $id === 'otp_code';
    $phoneField = $id === 'phone';
    $invalid = isset($fieldErrors[$id]);
    ?>
    <div class="entry-field">
        <label for="<?= e($id) ?>"><?= e($label) ?></label>
        <div class="entry-input-wrap">
            <input id="<?= e($id) ?>" name="<?= e($id) ?>" type="<?= e($type) ?>" value="<?= e($value) ?>"
                autocomplete="<?= e($autocomplete) ?>" required aria-describedby="<?= e($id) ?>-hint <?= e($id) ?>-error"
                <?= $invalid ? 'aria-invalid="true"' : '' ?>
                <?= ($phoneField || $isPassword || $otp) ? 'dir="ltr"' : '' ?>
                <?= $phoneField ? 'inputmode="tel" maxlength="20" placeholder="0912 345 6789" data-phone' : '' ?>
                <?= $otp ? 'inputmode="numeric" maxlength="5" pattern="[0-9۰-۹٠-٩]{5}" placeholder="— — — — —" data-otp' : '' ?>
                <?= !$phoneField && !$isPassword && !$otp ? 'maxlength="100"' : '' ?>
                <?= $isPassword ? 'maxlength="4096"' : '' ?>
                <?= $isPassword && $autocomplete === 'new-password' && $id !== 'confirm_password' ? 'data-new-password' : '' ?>
                <?= $id === 'confirm_password' ? 'data-confirm-password' : '' ?>>
            <?php if ($isPassword): ?>
                <button class="entry-reveal" type="button" data-reveal="<?= e($id) ?>" aria-label="نمایش <?= e($label) ?>" aria-pressed="false" hidden>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="eye-slash" d="m3 3 18 18"/></svg>
                </button>
            <?php endif; ?>
        </div>
        <small id="<?= e($id) ?>-hint" class="entry-hint"><?= e($hint) ?></small>
        <small id="<?= e($id) ?>-error" class="entry-field-error" aria-live="polite"><?= e($fieldErrors[$id] ?? '') ?></small>
    </div>
    <?php
};
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#F6F9F2">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle ?? $heading . ' | ' . $name) ?></title>
    <link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preload" href="<?= e(url('assets/fonts/Vazirmatn-Regular.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/parent-auth.css')) ?>">
    <script src="<?= e(asset('assets/js/parent-auth.js')) ?>" defer></script>
</head>
<body class="parent-auth">
<a href="#entry-main" class="entry-skip">رفتن به فرم</a>
<header class="entry-topbar">
    <a class="entry-logo" href="<?= e(url('index.php')) ?>">
        <?php if ($logo !== ''): ?><img src="<?= e(url($logo)) ?>" alt="<?= e($name) ?>" width="140" height="44">
        <?php else: ?><svg width="42" height="42" viewBox="0 0 48 48" aria-hidden="true"><path d="M24 5C10 5 5 17 7 29s10 16 17 16 17-4 17-16S38 5 24 5" fill="#3D8B63"/><path d="M14 23q4-5 8 0m5 0q4-5 8 0M19 31q6 7 12 0" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round"/></svg><span><?= e($name) ?></span><?php endif; ?>
    </a>
    <a class="entry-home" href="<?= e(url('index.php')) ?>">صفحهٔ اصلی <span aria-hidden="true">↗</span></a>
</header>
<div class="entry-layout">
    <main class="entry-main" id="entry-main">
        <div class="entry-card">
            <?php if (!$isForgot && !$isReset): ?>
            <nav class="entry-tabs" aria-label="حساب والدین">
                <a href="<?= e(url('login.php')) ?>" <?= !$isRegister ? 'aria-current="page"' : '' ?>>ورود به حساب</a>
                <a href="<?= e(url('register.php')) ?>" <?= $isRegister ? 'aria-current="page"' : '' ?>>ثبت‌نام والدین</a>
            </nav>
            <?php else: ?>
            <a class="entry-back" href="<?= e(url(!empty($teacherRecovery) ? 'teacher/login.php' : 'login.php')) ?>">بازگشت به ورود</a>
            <?php endif; ?>
            <?php if ($isRegister): ?>
            <ol class="entry-steps" aria-label="مراحل ثبت‌نام">
                <li <?= !$isVerify ? 'aria-current="step"' : 'class="is-done"' ?>><span>۱</span> اطلاعات والد</li>
                <li <?= $isVerify ? 'aria-current="step"' : '' ?>><span>۲</span> تأیید موبایل</li>
                <li><span>۳</span> بررسی مدیریت</li>
            </ol>
            <?php endif; ?>
            <div class="entry-heading"><h1><?= e($heading) ?></h1><p><?= e($description) ?></p></div>
            <?php if ($success): ?><div class="entry-notice entry-notice-success" role="status"><?= e($success) ?></div><?php endif; ?>
            <?php if ($messages): ?><div class="entry-notice entry-notice-error" role="alert" tabindex="-1" data-server-error><ul><?php foreach (array_unique($messages) as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <?php if ($isVerify): ?>
                <div class="entry-recipient"><bdi><?= e(persianNumber($displayPhone)) ?></bdi><a href="<?= e(url($endpoint)) ?>"><?= $isRegister ? 'ویرایش اطلاعات' : 'تغییر شماره' ?></a></div>
            <?php endif; ?>
            <?php if ((!$isReset || ($tokenValid ?? false)) && empty($teacherRecovery)): ?>
            <form method="post" action="<?= e($formAction) ?>" class="entry-form" data-auth-form>
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <?php if ($isRegister || $isForgot): ?><input type="hidden" name="action" value="<?= $isRegister ? ($isVerify ? 'verify_otp' : 'submit_form') : ($isVerify ? 'set_new_password' : 'request_otp') ?>"><?php endif; ?>
                <?php if ($isReset): ?><input type="hidden" name="token" value="<?= e($rawToken) ?>"><?php endif; ?>
                <?php if ($isVerify): ?>
                    <input type="hidden" name="phone" value="<?= e($displayPhone) ?>">
                    <?php $renderField('otp_code', 'کد تأیید پیامکی', 'text', '', 'کد را می‌توانید یک‌جا جای‌گذاری کنید.', 'one-time-code'); ?>
                <?php elseif ($isRegister): ?>
                    <div class="entry-name-row">
                        <?php $renderField('first_name', 'نام والد', 'text', $old['first_name'] ?? '', '', 'given-name'); ?>
                        <?php $renderField('last_name', 'نام خانوادگی والد', 'text', $old['last_name'] ?? '', '', 'family-name'); ?>
                    </div>
                <?php endif; ?>
                <?php if (!$isVerify && !$isReset): ?>
                    <?php $renderField('phone', 'شماره موبایل', 'tel', $isRegister ? ($old['phone'] ?? '') : ($phone ?? ''), $isRegister ? 'کد تأیید را به همین شماره می‌فرستیم.' : '', $mode === 'login' ? 'username' : 'tel'); ?>
                <?php endif; ?>
                <?php if ($mode === 'login' || ($isRegister && !$isVerify) || ($isForgot && $isVerify) || $isReset): ?>
                    <?php $newPassword = $mode !== 'login';
                    $renderField($isForgot ? 'new_password' : 'password', $newPassword ? 'رمز عبور جدید' : 'رمز عبور', 'password', '', $newPassword ? 'حداقل ۸ کاراکتر، شامل حرف انگلیسی و عدد؛ حداکثر ۷۲ بایت.' : '', $newPassword ? 'new-password' : 'current-password'); ?>
                    <?php if ($newPassword): ?>
                        <?php $renderField('confirm_password', 'تکرار رمز عبور', 'password', '', '', 'new-password'); ?>
                    <?php else: ?><a class="entry-forgot" href="<?= e(url('forgot-password.php')) ?>">رمز عبور را فراموش کرده‌اید؟</a><?php endif; ?>
                <?php endif; ?>
                <?php if ($isRegister && !$isVerify): ?><p class="entry-note">پس از تأیید موبایل، حساب شما با بررسی مدیریت فعال می‌شود. این فرم برای ساخت حساب والدین است.</p><?php endif; ?>
                <button type="submit" class="entry-submit"><span data-submit-label><?= $isRegister ? ($isVerify ? 'تأیید و تکمیل ثبت‌نام' : 'دریافت کد تأیید') : ($isForgot ? ($isVerify ? 'ذخیرهٔ رمز جدید' : 'دریافت کد بازیابی') : ($isReset ? 'ذخیرهٔ رمز جدید' : 'ورود به حساب والدین')) ?></span><span class="entry-spinner" aria-hidden="true"></span></button>
            </form>
            <?php endif; ?>
            <?php if ($isVerify): ?>
            <form method="post" action="<?= e($formAction) ?>" class="entry-resend" data-auth-form>
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="resend_otp">
                <input type="hidden" name="phone" value="<?= e($displayPhone) ?>">
                <span>کد را دریافت نکرده‌اید؟</span>
                <button type="submit" data-resend data-wait="<?= max(0, ($resendAvailableAt ?? 0) - time()) ?>">ارسال دوبارهٔ کد</button>
                <span data-countdown role="timer"></span>
            </form>
            <?php endif; ?>
            <?php if ($isReset && !($tokenValid ?? false)): ?><a class="entry-link-button" href="<?= e(url('forgot-password.php')) ?>">شروع دوبارهٔ بازیابی</a><?php endif; ?>
            <?php if ($mode === 'login'): ?><p class="entry-switch">هنوز حساب ندارید؟ <a href="<?= e(url('register.php')) ?>">ثبت‌نام والدین</a></p><?php endif; ?>
            <div class="entry-staff">مربی یا مدیر مهد هستید؟ <a href="<?= e(url('teacher/login.php')) ?>">ورود مربیان</a><span aria-hidden="true">/</span><a href="<?= e(url('admin/login.php')) ?>">ورود مدیریت</a></div>
        </div>
        <p class="entry-bottom">کنار هم، برای روزهای قشنگ کودکی.</p>
    </main>
    <aside class="entry-story" aria-label="همراه خانه و مهدکودک">
        <div class="entry-story-copy"><h2>کودکی، فصلِ<br>شکفتن است.</h2><p>از اولین دوستی تا کشف‌های کوچک هر روز؛<br>خانه و مهد، کنار هم می‌مانند.</p></div>
        <img class="entry-illustration" src="<?= e(url('assets/img/auth-garden.svg')) ?>" width="560" height="460" alt="تصویرسازی یک مهدکودک در باغچه، کنار گل و برگ خندان">
        <div class="entry-story-footer"><span class="entry-story-mark" aria-hidden="true">✿</span><p><strong>یک پنجره به روزهای کودکتان</strong><span>گزارش‌های روزانه، پیام‌های مربی و پیگیری شهریه</span></p></div>
    </aside>
</div>
</body>
</html>

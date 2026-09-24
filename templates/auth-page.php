<?php
if (!defined('ROOMA_APP')) { exit; }
/*
 * قالب مشترک ورود / ثبت‌نام / بازیابی والدین (نسخهٔ ۲)
 * فقط لایهٔ نمایش عوض شده؛ نام فیلدها، اکشن‌ها، CSRF و منطق سرور دست‌نخورده است.
 */
$mode = $authMode ?? 'login';
$isRegister = $mode === 'register';
$isForgot = $mode === 'forgot';
$isReset = $mode === 'reset';
$isLogin = $mode === 'login';
$isVerify = ($isRegister || $isForgot) && ($step ?? '') === 'verify';
$fieldErrors = $fieldErrors ?? [];
$messages = $errors ?? [];
if (!empty($error)) { $messages[] = $error; }
$messages = array_values(array_unique(array_filter(array_map('strval', $messages), static fn (string $m): bool => $m !== '')));
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
// ارسال خودکار فقط وقتی فرم «فقط کد» دارد؛ در بازیابی، فیلد رمز هم هست و ارسال زودهنگام خطای بی‌جا نشان می‌داد.
$autoSubmit = $isVerify && $isRegister;
if ($isLogin) { $loadingText = 'در حال ورود…'; }
elseif ($isVerify) { $loadingText = $isRegister ? 'در حال تأیید…' : 'در حال ذخیرهٔ رمز جدید…'; }
elseif ($isReset) { $loadingText = 'در حال ذخیرهٔ رمز جدید…'; }
else { $loadingText = 'در حال ارسال کد…'; }
$submitLabel = $isRegister ? ($isVerify ? 'تأیید و تکمیل ثبت‌نام' : 'دریافت کد تأیید')
    : ($isForgot ? ($isVerify ? 'ذخیرهٔ رمز جدید' : 'دریافت کد بازیابی')
    : ($isReset ? 'ذخیرهٔ رمز جدید' : 'ورود به حساب والدین'));
$registerStep = $isRegister ? ($isVerify ? 2 : 1) : 0;
$stepLabels = ['اطلاعات والد', 'تأیید موبایل', 'بررسی مدیریت', 'ورود به پنل'];
$showForm = (!$isReset || ($tokenValid ?? false)) && empty($teacherRecovery);
$name = siteName();
$logo = siteLogo();

/** ارقام فارسی برای نمایش (فقط نمایش؛ مقدار ارسالی لاتین می‌ماند) */
$fa = static fn (string $value): string => strtr($value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
$prettyPhone = preg_match('/\A0\d{10}\z/', (string) $displayPhone) === 1
    ? substr($displayPhone, 0, 4) . ' ' . substr($displayPhone, 4, 3) . ' ' . substr($displayPhone, 7)
    : (string) $displayPhone;

/** آیکون‌های SVG سبک، بدون وابستگی خارجی */
$authIcon = static function (string $kind, int $size = 20): string {
    $paths = [
        'user' => '<circle cx="12" cy="8" r="3.4"/><path d="M5.5 19c1.6-3.2 4-4.8 6.5-4.8s4.9 1.6 6.5 4.8"/>',
        'phone' => '<rect x="7" y="2.8" width="10" height="18.4" rx="2.6"/><path d="M10.5 17.6h3"/>',
        'lock' => '<rect x="5.5" y="10.5" width="13" height="9.5" rx="2.4"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/>',
        'sms' => '<path d="M4.5 6.5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H10l-4 3.2v-3.2a2 2 0 0 1-1.5-2z"/><path d="M8.5 10.5h.01M12 10.5h.01M15.5 10.5h.01"/>',
        'arrow' => '<path d="M19 12H5"/><path d="m11 6-6 6 6 6"/>',
        'back' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'close' => '<path d="M7 7l10 10M17 7 7 17"/>',
        'alert' => '<path d="M12 7v6"/><path d="M12 16.8h.01"/>',
        'info' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5"/><path d="M12 7.8h.01"/>',
        'home' => '<path d="M4.5 11 12 4.5l7.5 6.5"/><path d="M6.5 9.5v9.5h11V9.5"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/><path d="m4 4 16 16"/>',
    ];
    $d = $paths[$kind] ?? $paths['user'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
};

/**
 * رندر یک فیلد فرم.
 * $o: value, hint, autocomplete, icon, rules (bool: چک‌لیست رمز), aside (HTML ثابت و امنِ خود قالب)
 */
$renderField = static function (string $id, string $label, string $type, array $o = []) use ($fieldErrors, $authIcon): void {
    $value = (string) ($o['value'] ?? '');
    $hint = (string) ($o['hint'] ?? '');
    $autocomplete = (string) ($o['autocomplete'] ?? '');
    $icon = (string) ($o['icon'] ?? 'user');
    $rules = !empty($o['rules']);
    $aside = (string) ($o['aside'] ?? '');
    $isPassword = $type === 'password';
    $otp = $id === 'otp_code';
    $phoneField = $id === 'phone';
    $nameField = in_array($id, ['first_name', 'last_name'], true);
    $invalid = isset($fieldErrors[$id]);
    $describedBy = ($rules ? $id . '-rules ' : '') . $id . '-hint ' . $id . '-error';
    ?>
    <div class="auth-field<?= $otp ? ' auth-field-otp' : '' ?>">
        <div class="auth-label-row">
            <label for="<?= e($id) ?>"><?= e($label) ?></label>
            <?= $aside ?>
        </div>
        <?php if ($otp): ?>
        <div class="auth-otp<?= $invalid ? ' is-invalid' : '' ?>" data-otp-group>
            <input id="<?= e($id) ?>" name="<?= e($id) ?>" type="text" class="auth-otp-input" value=""
                autocomplete="one-time-code" inputmode="numeric" maxlength="5" pattern="[0-9۰-۹٠-٩]{5}" required dir="ltr"
                aria-describedby="<?= e($describedBy) ?>"<?= $invalid ? ' aria-invalid="true"' : '' ?> data-otp>
            <div class="auth-otp-cells" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
        </div>
        <?php else: ?>
        <div class="auth-control<?= $isPassword ? ' has-reveal' : '' ?><?= $invalid ? ' is-invalid' : '' ?>">
            <span class="auth-control-icon" aria-hidden="true"><?= $authIcon($phoneField ? 'phone' : ($isPassword ? 'lock' : $icon)) ?></span>
            <input id="<?= e($id) ?>" name="<?= e($id) ?>" type="<?= e($type) ?>" value="<?= e($value) ?>"
                autocomplete="<?= e($autocomplete) ?>" required aria-describedby="<?= e($describedBy) ?>"
                <?= $invalid ? 'aria-invalid="true"' : '' ?>
                <?= ($phoneField || $isPassword) ? 'dir="ltr"' : '' ?>
                <?= $phoneField ? 'inputmode="tel" maxlength="20" placeholder="0912 345 6789" data-phone' : '' ?>
                <?= $nameField ? 'data-name maxlength="100"' : '' ?>
                <?= !$phoneField && !$isPassword && !$nameField ? 'maxlength="100"' : '' ?>
                <?= $isPassword ? 'maxlength="4096" data-password' : '' ?>
                <?= $isPassword && $autocomplete === 'new-password' && $id !== 'confirm_password' ? 'data-new-password' : '' ?>
                <?= $id === 'confirm_password' ? 'data-confirm-password' : '' ?>>
            <span class="auth-state auth-state-ok" aria-hidden="true"><?= $authIcon('check', 14) ?></span>
            <span class="auth-state auth-state-err" aria-hidden="true"><?= $authIcon('close', 14) ?></span>
            <?php if ($isPassword): ?>
                <button class="auth-reveal" type="button" data-reveal="<?= e($id) ?>" aria-label="نمایش <?= e($label) ?>" aria-pressed="false" hidden>
                    <span class="eye-open"><?= $authIcon('eye') ?></span>
                    <span class="eye-slash"><?= $authIcon('eye-off') ?></span>
                </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($isPassword): ?><span class="auth-caps" data-caps>⇪ Caps Lock روشن است</span><?php endif; ?>
        <?php if ($rules): ?>
            <ul class="auth-rules" id="<?= e($id) ?>-rules" data-rules-for="<?= e($id) ?>" aria-label="شرایط رمز عبور">
                <li data-rule="length">حداقل ۸ کاراکتر</li>
                <li data-rule="letter">یک حرف انگلیسی</li>
                <li data-rule="digit">یک عدد</li>
            </ul>
            <div class="auth-meter" data-meter-for="<?= e($id) ?>" data-level="0" aria-live="polite">
                <span class="auth-meter-bar" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                <span class="auth-meter-text" data-meter-text></span>
            </div>
        <?php endif; ?>
        <small id="<?= e($id) ?>-hint" class="auth-hint"><?= e($hint) ?></small>
        <small id="<?= e($id) ?>-error" class="auth-error" aria-live="polite"><?= e($fieldErrors[$id] ?? '') ?></small>
    </div>
    <?php
};
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#184b33" media="(max-width: 959px)">
    <meta name="theme-color" content="#f6f8f1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <title><?= e($pageTitle ?? $heading . ' | ' . $name) ?></title>
    <link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= e(url('assets/icons/icon-180.png')) ?>">
    <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="preload" href="<?= e(url('assets/fonts/Vazirmatn-Regular.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/parent-auth.css')) ?>">
    <script>window.ROMA_SW_URL = <?= json_encode(url('sw.js'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>
    <script src="<?= e(asset('assets/js/parent-auth.js')) ?>" defer></script>
    <script src="<?= e(asset('assets/js/pwa-install.js')) ?>" defer></script>
    <?php // Prefetch سبکِ صفحات احراز هویت هنگام هاور/فوکوس؛ ناوبری بعدی را سریع‌تر نشان می‌دهد. ?>
    <script type="speculationrules">
    {
      "prefetch": [
        {"where": {"and": [{"href_matches": "/*login*"}, {"not": {"href_matches": "/*logout*"}}]}, "eagerness": "moderate"},
        {"where": {"href_matches": "/*register*"}, "eagerness": "moderate"},
        {"where": {"href_matches": "/*forgot-password*"}, "eagerness": "moderate"}
      ]
    }
    </script>
    <script>
    /* نوار پیشرفت بالای صفحه — نسخهٔ مقاوم در برابر لودینگ بی‌پایان */
    (function(){
        var d=document.documentElement;
        var navTimer=null;
        function clearNav(){
            d.classList.remove('is-navigating');
            var p=document.querySelectorAll('a.is-pending');
            for(var i=0;i<p.length;i++)p[i].classList.remove('is-pending');
            if(navTimer){clearTimeout(navTimer);navTimer=null;}
        }
        function scheduleAutoClear(ms){
            if(navTimer)clearTimeout(navTimer);
            navTimer=setTimeout(clearNav,ms||8000);
        }
        function isSamePageUrl(href){
            try{
                var u=new URL(href,window.location.href);
                var c=new URL(window.location.href);
                return u.origin===c.origin&&u.pathname===c.pathname&&u.search===c.search;
            }catch(e){return false;}
        }
        function nav(a){
            if(!a)return false;
            if(a.target==='_blank'||a.hasAttribute('download'))return false;
            var h=a.getAttribute('href');
            if(!h||h.charAt(0)==='#'||h.indexOf('mailto:')===0||h.indexOf('tel:')===0||h.indexOf('javascript:')===0)return false;
            if(a.getAttribute('aria-current')==='page')return false;
            try{
                if(a.href&&isSamePageUrl(a.href))return false;
                else if(h&&isSamePageUrl(h))return false;
            }catch(e){}
            d.classList.add('is-navigating');
            a.classList.add('is-pending');
            scheduleAutoClear(8000);
            return true;
        }
        document.addEventListener('click',function(e){
            if(e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
            if(e.button!==undefined&&e.button!==0)return;
            var t=e.target;
            var a=t&&t.closest?t.closest('a[href]'):null;
            if(!a)return;
            setTimeout(function(){if(e.defaultPrevented)return;nav(a);},0);
        },false);
        document.addEventListener('submit',function(e){
            var f=e.target;
            if(!f||!f.hasAttribute||!f.hasAttribute('data-auth-form'))return;
            setTimeout(function(){
                if(e.defaultPrevented){
                    if(!f.hasAttribute('aria-busy'))clearNav();
                    return;
                }
                d.classList.add('is-navigating');
                scheduleAutoClear(15000);
            },0);
        },false);
        window.addEventListener('pageshow',clearNav);
        window.addEventListener('popstate',clearNav);
        window.__romaClearNav=clearNav;
    })();
    </script>
</head>
<body class="parent-auth auth-mode-<?= e($mode) ?>">
<div class="auth-navbar" aria-hidden="true"><span></span></div>
<a href="#auth-main" class="auth-skip">رفتن به فرم</a>
<div class="auth">
    <main class="auth-main" id="auth-main">
        <header class="auth-top">
            <a class="auth-logo" href="<?= e(url('index.php')) ?>">
                <?php if ($logo !== ''): ?><img src="<?= e(url($logo)) ?>" alt="<?= e($name) ?>" width="120" height="34">
                <?php else: ?><svg width="30" height="30" viewBox="0 0 48 48" aria-hidden="true"><path d="M24 5C10 5 5 17 7 29s10 16 17 16 17-4 17-16S38 5 24 5" fill="#2A7449"/><path d="M14 23q4-5 8 0m5 0q4-5 8 0M19 31q6 7 12 0" fill="none" stroke="#F2F6E3" stroke-width="2.5" stroke-linecap="round"/></svg><span><?= e($name) ?></span><?php endif; ?>
            </a>
            <a class="auth-home" href="<?= e(url('index.php')) ?>"><?= $authIcon('home', 18) ?><span>صفحهٔ اصلی</span></a>
            <p class="auth-top-line">پنجرهٔ شما به روزهای کودکتان</p>
            <img class="auth-top-art" src="<?= e(url('assets/img/auth-garden.svg')) ?>" width="560" height="460" alt="" aria-hidden="true" decoding="async">
        </header>

        <div class="auth-sheet">
            <?php if ($isForgot || $isReset): ?>
                <a class="auth-back" href="<?= e(url('login.php')) ?>"><?= $authIcon('back', 18) ?><span>بازگشت به ورود</span></a>
            <?php else: ?>
                <nav class="auth-tabs" aria-label="حساب والدین" data-active="<?= $isRegister ? 'register' : 'login' ?>">
                    <span class="auth-tabs-pill" aria-hidden="true"></span>
                    <a href="<?= e(url('login.php')) ?>"<?= !$isRegister ? ' aria-current="page"' : '' ?>>ورود به حساب</a>
                    <a href="<?= e(url('register.php')) ?>"<?= $isRegister ? ' aria-current="page"' : '' ?>>ثبت‌نام والدین</a>
                </nav>
            <?php endif; ?>

            <?php if ($isRegister): ?>
                <ol class="auth-steps" aria-label="مراحل ثبت‌نام">
                    <?php foreach ($stepLabels as $stepIndex => $stepLabel): $stepNo = $stepIndex + 1; ?>
                        <li class="<?= $stepNo < $registerStep ? 'is-done' : ($stepNo === $registerStep ? 'is-current' : 'is-next') ?>"<?= $stepNo === $registerStep ? ' aria-current="step"' : '' ?>><span><?= e($stepLabel) ?></span></li>
                    <?php endforeach; ?>
                </ol>
                <p class="auth-steps-caption" aria-hidden="true">مرحلهٔ <?= e($fa((string) $registerStep)) ?> از ۴ · <strong><?= e($stepLabels[$registerStep - 1]) ?></strong></p>
            <?php endif; ?>

            <div class="auth-heading"><h1><?= e($heading) ?></h1><p><?= e($description) ?></p></div>

            <div class="auth-notice auth-notice-warn" role="status" data-offline hidden>
                <span class="auth-notice-icon" aria-hidden="true"><?= $authIcon('alert', 16) ?></span>
                <div>اتصال اینترنت برقرار نیست. پس از وصل شدن دوباره تلاش کنید.</div>
            </div>
            <?php if ($success): ?>
                <div class="auth-notice auth-notice-success" role="status">
                    <span class="auth-notice-icon" aria-hidden="true"><?= $authIcon('check', 16) ?></span>
                    <div><?= e($success) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($messages): ?>
                <div class="auth-notice auth-notice-error" role="alert" tabindex="-1" data-server-error>
                    <span class="auth-notice-icon" aria-hidden="true"><?= $authIcon('alert', 16) ?></span>
                    <ul><?php foreach ($messages as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <?php if ($isVerify): ?>
                <div class="auth-recipient">
                    <span class="auth-recipient-icon" aria-hidden="true"><?= $authIcon('sms', 20) ?></span>
                    <div class="auth-recipient-text"><small>کد به این شماره پیامک شد</small><bdi dir="ltr"><?= e($fa($prettyPhone)) ?></bdi></div>
                    <a href="<?= e(url($endpoint)) ?>"><?= $isRegister ? 'ویرایش اطلاعات' : 'تغییر شماره' ?></a>
                </div>
            <?php endif; ?>

            <?php if ($showForm): ?>
            <form method="post" action="<?= e($formAction) ?>" class="auth-form" data-auth-form<?= $autoSubmit ? ' data-auto-submit' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <?php if ($isRegister || $isForgot): ?><input type="hidden" name="action" value="<?= $isRegister ? ($isVerify ? 'verify_otp' : 'submit_form') : ($isVerify ? 'set_new_password' : 'request_otp') ?>"><?php endif; ?>
                <?php if ($isReset): ?><input type="hidden" name="token" value="<?= e($rawToken ?? '') ?>"><?php endif; ?>
                <?php if ($isVerify): ?>
                    <input type="hidden" name="phone" value="<?= e($displayPhone) ?>">
                    <?php $renderField('otp_code', 'کد تأیید پیامکی', 'text', [
                        'hint' => $autoSubmit ? 'با وارد کردن رقم آخر، کد خودکار بررسی می‌شود. می‌توانید کد را یک‌جا جای‌گذاری کنید.' : 'کد را می‌توانید یک‌جا جای‌گذاری کنید.',
                        'autocomplete' => 'one-time-code',
                        'icon' => 'sms',
                    ]); ?>
                <?php elseif ($isRegister): ?>
                    <div class="auth-name-row">
                        <?php $renderField('first_name', 'نام والد', 'text', ['value' => $old['first_name'] ?? '', 'autocomplete' => 'given-name']); ?>
                        <?php $renderField('last_name', 'نام خانوادگی', 'text', ['value' => $old['last_name'] ?? '', 'autocomplete' => 'family-name']); ?>
                    </div>
                <?php endif; ?>
                <?php if (!$isVerify && !$isReset): ?>
                    <?php $renderField('phone', 'شماره موبایل', 'tel', [
                        'value' => $isRegister ? ($old['phone'] ?? '') : ($phone ?? ''),
                        'hint' => $isRegister ? 'کد تأیید را به همین شماره پیامک می‌کنیم.' : '',
                        'autocomplete' => $isLogin ? 'username' : 'tel',
                        'icon' => 'phone',
                    ]); ?>
                <?php endif; ?>
                <?php if ($isLogin || ($isRegister && !$isVerify) || ($isForgot && $isVerify) || $isReset): ?>
                    <?php $newPassword = !$isLogin;
                    $renderField($isForgot ? 'new_password' : 'password', $newPassword ? 'رمز عبور جدید' : 'رمز عبور', 'password', [
                        'autocomplete' => $newPassword ? 'new-password' : 'current-password',
                        'icon' => 'lock',
                        'rules' => $newPassword,
                        'aside' => $newPassword ? '' : '<a class="auth-label-link" href="' . e(url('forgot-password.php')) . '">رمز را فراموش کرده‌اید؟</a>',
                    ]); ?>
                    <?php if ($newPassword): ?>
                        <?php $renderField('confirm_password', 'تکرار رمز عبور', 'password', ['autocomplete' => 'new-password', 'icon' => 'lock']); ?>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($isRegister && !$isVerify): ?>
                    <p class="auth-note"><span class="auth-note-icon" aria-hidden="true"><?= $authIcon('info', 18) ?></span><span>پس از تأیید موبایل، مدیریت مهد حساب شما را بررسی و فعال می‌کند.</span></p>
                <?php endif; ?>
                <button type="submit" class="auth-submit" data-loading-text="<?= e($loadingText) ?>">
                    <span data-submit-label><?= e($submitLabel) ?></span>
                    <span class="auth-submit-icon" aria-hidden="true"><?= $authIcon('arrow', 20) ?></span>
                    <span class="auth-spinner" aria-hidden="true"></span>
                </button>
            </form>
            <?php endif; ?>

            <?php if ($isVerify): ?>
            <form method="post" action="<?= e($formAction) ?>" class="auth-resend" data-auth-form>
                <input type="hidden" name="csrf_token" value="<?= e(generateCsrfToken()) ?>">
                <input type="hidden" name="action" value="resend_otp">
                <input type="hidden" name="phone" value="<?= e($displayPhone) ?>">
                <span>کد را دریافت نکرده‌اید؟</span>
                <button type="submit" data-resend data-wait="<?= max(0, ($resendAvailableAt ?? 0) - time()) ?>">ارسال دوبارهٔ کد</button>
                <span data-countdown role="timer" dir="ltr"></span>
                <span class="auth-resend-track" aria-hidden="true" hidden><i data-resend-bar></i></span>
            </form>
            <?php endif; ?>

            <?php if ($isReset && !($tokenValid ?? false)): ?><a class="auth-submit auth-link-button" href="<?= e(url('forgot-password.php')) ?>"><span>شروع دوبارهٔ بازیابی</span><span class="auth-submit-icon" aria-hidden="true"><?= $authIcon('arrow', 20) ?></span></a><?php endif; ?>
            <?php if ($isLogin): ?><p class="auth-switch">هنوز حساب ندارید؟ <a href="<?= e(url('register.php')) ?>">ثبت‌نام والدین</a></p><?php endif; ?>
            <?php if ($isRegister && !$isVerify): ?><p class="auth-switch">قبلاً ثبت‌نام کرده‌اید؟ <a href="<?= e(url('login.php')) ?>">ورود به حساب</a></p><?php endif; ?>
            <div class="auth-staff">مربی یا مدیر مهد هستید؟ <a href="<?= e(url('teacher/login.php')) ?>">ورود مربیان</a><span aria-hidden="true">·</span><a href="<?= e(url('admin/login.php')) ?>">ورود مدیریت</a></div>
        </div>
        <p class="auth-foot">کنار هم، برای روزهای قشنگ کودکی.</p>
    </main>

    <aside class="auth-story" aria-label="همراه خانه و مهدکودک">
        <div class="auth-story-copy">
            <h2>کودکی، فصلِ <em>شکفتن</em> است.</h2>
            <p>از اولین دوستی تا کشف‌های کوچکِ هر روز؛ خانه و مهد، کنار هم می‌مانند.</p>
        </div>
        <img class="auth-story-art" src="<?= e(url('assets/img/auth-garden.svg')) ?>" width="560" height="460" alt="تصویرسازی یک مهدکودک در باغچه، کنار گل و برگ خندان" decoding="async">
        <div class="auth-report" aria-hidden="true">
            <div class="auth-report-head">
                <span class="auth-report-avatar">آ</span>
                <span><strong>گزارش امروزِ آوا</strong><small>نمونه · ساعت ۱۳:۴۰</small></span>
            </div>
            <p class="auth-report-msg">«امروز با دوستانش یک برج بلند ساخت و ناهارش را کامل خورد.»</p>
            <ul class="auth-report-tags">
                <li>حضور ۸:۰۵</li>
                <li>خواب ۱ ساعت</li>
                <li>نقاشی با آبرنگ</li>
            </ul>
        </div>
    </aside>
</div>
</body>
</html>

<?php
if (!defined('ROOMA_APP')) {
    die('Access denied.');
}
$siteNameValue = siteName();
[$footerJy] = gregorianToJalali((int) date('Y'), (int) date('n'), (int) date('j'));
$footerJyFa = persianNumber((string) $footerJy);
?>
      </div>
   </main>

    <footer class="parent-footer">
        <div class="parent-footer-container">
            <p>&copy; <?php echo e($footerJyFa); ?> <?php echo e($siteNameValue); ?> &mdash; تمامی حقوق محفوظ است</p>
        </div>
    </footer>

    <?php require_once __DIR__ . '/../templates/app/dialogs.php'; ?>

    <?php /* ── PWA install banner (shown once; closing it hides it forever) ── */ ?>
    <div class="pwa-banner" id="pwaInstallBanner" role="dialog" aria-live="polite" aria-label="نصب اپلیکیشن والدین">
        <span class="pwa-banner-icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
        </span>
        <div class="pwa-banner-text">
            <strong>اپ والدین <?php echo e($siteNameValue); ?> را نصب کنید</strong>
            <span>دسترسی سریع‌تر، بدون نیاز به مرورگر — گزارش روزانه همیشه یک لمس فاصله دارد.</span>
        </div>
        <div class="pwa-banner-actions">
            <button type="button" class="pwa-banner-install" data-pwa-install>نصب</button>
            <button type="button" class="pwa-banner-guide" data-pwa-guide>راهنما</button>
            <button type="button" class="pwa-banner-dismiss" data-pwa-dismiss aria-label="بستن">✕</button>
        </div>
    </div>

    <?php /* ── PWA install guide modal (Android / iOS / Desktop) ── */ ?>
    <div class="pwa-modal" id="pwaGuideModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="pwaGuideTitle">
        <div class="pwa-modal-backdrop" data-pwa-close></div>
        <div class="pwa-modal-card">
            <div class="pwa-modal-head">
                <div>
                    <h3 id="pwaGuideTitle">نصب اپ والدین <?php echo e($siteNameValue); ?></h3>
                    <p>روش نصب متناسب با دستگاه شما:</p>
                </div>
                <button type="button" class="pwa-modal-close" data-pwa-close aria-label="بستن راهنما">✕</button>
            </div>
            <div class="pwa-tabs" role="tablist" aria-label="انتخاب دستگاه">
                <button type="button" class="pwa-tab" data-pwa-tab="android" role="tab">اندروید</button>
                <button type="button" class="pwa-tab" data-pwa-tab="ios" role="tab">آیفون</button>
                <button type="button" class="pwa-tab" data-pwa-tab="desktop" role="tab">کامپیوتر</button>
            </div>
            <div class="pwa-panes">
                <section class="pwa-pane" data-pwa-pane="android" role="tabpanel">
                    <p class="pwa-pane-intro">گوشی اندرویدی دارید؟ یکی از دو روش زیر کافی است؛ روش اول سریع‌تر و ساده‌تر است.</p>
                    <div class="pwa-method">
                        <div class="pwa-method-head">
                            <span class="pwa-method-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></span>
                            <h4>روش پیشنهادی — نصب خودکار</h4>
                            <span class="pwa-method-badge">سریع‌تر</span>
                        </div>
                        <ol class="pwa-steps">
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۱</span>
                            <div class="pwa-step-body">
                                <strong>دکمه «نصب اپ» را بزنید</strong>
                                <p>همین دکمه در نوار ابزار بالای صفحه قرار دارد؛ یا از دکمه «نصب خودکار» پایین همین راهنما استفاده کنید.</p>
                            </div>
                        </li>
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۲</span>
                            <div class="pwa-step-body">
                                <strong>نصب را تأیید کنید</strong>
                                <p>در پنجره‌ای که مرورگر نشان می‌دهد، گزینه «نصب / Install» را انتخاب کنید.</p>
                            </div>
                        </li>
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۳</span>
                            <div class="pwa-step-body">
                                <strong>اپ را از صفحه اصلی باز کنید</strong>
                                <p>آیکون «روما» به صفحه اصلی گوشی اضافه می‌شود و مثل یک اپ مستقل، بدون مرورگر باز می‌شود.</p>
                            </div>
                        </li>
                        </ol>
                    </div>
                    <div class="pwa-method">
                        <div class="pwa-method-head">
                            <span class="pwa-method-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg></span>
                            <h4>روش جایگزین — از منوی مرورگر</h4>
                        </div>
                        <ol class="pwa-steps">
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۱</span>
                            <div class="pwa-step-body">
                                <strong>منوی مرورگر را باز کنید</strong>
                                <p>روی علامت <strong>⋮</strong> در گوشه مرورگر کروم بزنید.</p>
                            </div>
                        </li>
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۲</span>
                            <div class="pwa-step-body">
                                <strong>«نصب برنامه» را انتخاب کنید</strong>
                                <p>گزینه Install app / نصب برنامه را در فهرست منو پیدا کنید.</p>
                            </div>
                        </li>
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۳</span>
                            <div class="pwa-step-body">
                                <strong>نصب را کامل کنید</strong>
                                <p>«نصب» را بزنید تا آیکون روما به صفحه اصلی گوشی اضافه شود.</p>
                            </div>
                        </li>
                        </ol>
                    </div>
                    <p class="pwa-note"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="11" x2="12" y2="16"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg><span><strong>نکته:</strong> در بعضی گوشی‌ها اسم این گزینه «افزودن به صفحه اصلی» است. در مرورگر سامسونگ هم از منوی <strong>☰</strong>، «افزودن صفحه به ← صفحه اصلی» همین کار را می‌کند.</span></p>
                    <p class="pwa-done"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><span><strong>تمام شد!</strong> از این به بعد اپ والدین روما را مستقیم از صفحه اصلی گوشی باز کنید.</span></p>
                </section>

                <section class="pwa-pane" data-pwa-pane="ios" role="tabpanel" hidden>
                    <p class="pwa-pane-intro">در آیفون و آیپد، نصب فقط از مرورگر <strong>Safari</strong> انجام می‌شود و کمتر از یک دقیقه طول می‌کشد.</p>
                    <div class="pwa-method">
                        <div class="pwa-method-head">
                            <span class="pwa-method-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></span>
                            <h4>مراحل نصب در سافاری</h4>
                        </div>
                        <ol class="pwa-steps">
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۱</span>
                            <div class="pwa-step-body">
                                <strong>صفحه را در Safari باز کنید</strong>
                                <p>اگر الان در مرورگر دیگری هستید، نشانی همین سایت را در سافاری وارد کنید.</p>
                            </div>
                        </li>
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۲</span>
                            <div class="pwa-step-body">
                                <strong>دکمه اشتراک‌گذاری را بزنید</strong>
                                <p>آیکون مربع با فلش رو به بالا، در نوار پایین سافاری قرار دارد.</p>
                            </div>
                        </li>
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۳</span>
                            <div class="pwa-step-body">
                                <strong>«افزودن به صفحه اصلی» را انتخاب کنید</strong>
                                <p>در فهرست بازشده کمی پایین بروید و گزینه Add to Home Screen را بزنید.</p>
                            </div>
                        </li>
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۴</span>
                            <div class="pwa-step-body">
                                <strong>«افزودن / Add» را بزنید</strong>
                                <p>آیکون روما روی صفحه اصلی آیفون قرار می‌گیرد.</p>
                            </div>
                        </li>
                        </ol>
                    </div>
                    <p class="pwa-note"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="11" x2="12" y2="16"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg><span><strong>نکته:</strong> اگر دکمه اشتراک‌گذاری را نمی‌بینید، یک بار روی نوار آدرس بزنید تا نوار ابزار سافاری کامل نمایش داده شود.</span></p>
                    <p class="pwa-done"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><span><strong>تمام شد!</strong> از این به بعد اپ والدین روما را مستقیم از صفحه اصلی آیفون باز کنید.</span></p>
                </section>

                <section class="pwa-pane" data-pwa-pane="desktop" role="tabpanel" hidden>
                    <p class="pwa-pane-intro">در کامپیوتر، نصب از مرورگر <strong>کروم</strong> یا <strong>Edge</strong> انجام می‌شود.</p>
                    <div class="pwa-method">
                        <div class="pwa-method-head">
                            <span class="pwa-method-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></span>
                            <h4>روش پیشنهادی — آیکون نصب مرورگر</h4>
                            <span class="pwa-method-badge">سریع‌تر</span>
                        </div>
                        <ol class="pwa-steps">
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۱</span>
                            <div class="pwa-step-body">
                                <strong>روی آیکون نصب کلیک کنید</strong>
                                <p>در سمت راست نوار آدرس مرورگر، آیکونی شبیه مانیتور با فلش دانلود می‌بینید.</p>
                            </div>
                        </li>
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۲</span>
                            <div class="pwa-step-body">
                                <strong>«نصب / Install» را بزنید</strong>
                                <p>در پنجره تأیید مرورگر، نصب را تأیید کنید.</p>
                            </div>
                        </li>
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۳</span>
                            <div class="pwa-step-body">
                                <strong>اپ آماده است</strong>
                                <p>روما در پنجره‌ای مستقل باز می‌شود و به منوی استارت (ویندوز) یا داک (مک) اضافه شده است.</p>
                            </div>
                        </li>
                        </ol>
                    </div>
                    <div class="pwa-method">
                        <div class="pwa-method-head">
                            <span class="pwa-method-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg></span>
                            <h4>روش جایگزین — از منوی مرورگر</h4>
                        </div>
                        <ol class="pwa-steps">
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۱</span>
                            <div class="pwa-step-body">
                                <strong>منوی مرورگر را باز کنید</strong>
                                <p>روی علامت <strong>⋮</strong> در گوشه مرورگر بزنید.</p>
                            </div>
                        </li>
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۲</span>
                            <div class="pwa-step-body">
                                <strong>گزینه نصب را انتخاب کنید</strong>
                                <p>در کروم: «نصب روما / Install app» — در Edge: «برنامه‌ها ← نصب این سایت به‌عنوان برنامه».</p>
                            </div>
                        </li>
                        <li class="pwa-step">
                            <span class="pwa-step-num" aria-hidden="true">۳</span>
                            <div class="pwa-step-body">
                                <strong>نصب را تأیید کنید</strong>
                                <p>تا اپ به فهرست برنامه‌های کامپیوتر اضافه شود.</p>
                            </div>
                        </li>
                        </ol>
                    </div>
                    <p class="pwa-note"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="11" x2="12" y2="16"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg><span><strong>نکته:</strong> فایرفاکس روی کامپیوتر نصب مستقیم اپ را پشتیبانی نمی‌کند؛ برای نصب، از کروم یا Edge استفاده کنید.</span></p>
                    <p class="pwa-done"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><span><strong>تمام شد!</strong> اپ روما مثل یک برنامه معمولی از منوی استارت یا داک اجرا می‌شود.</span></p>
                </section>
            </div>
            <div class="pwa-modal-foot">
                <button type="button" class="btn btn-primary btn-sm" data-pwa-install>نصب خودکار</button>
                <button type="button" class="btn btn-outline btn-sm" data-pwa-close>باشه، فهمیدم</button>
            </div>
        </div>
    </div>

<!-- Mobile Bottom Navigation - Parent Portal -->
<nav class="mobile-bottom-nav parent-bottom-nav" aria-label="منوی ناوبری موبایل والدین">
    <a href="<?php echo e(url('parent/index.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        <span class="bottom-nav-label">داشبورد</span>
    </a>
    <a href="<?php echo e(url('parent/children.php')); ?>" class="bottom-nav-item <?php echo in_array($currentPage, ['children.php', 'child-detail.php', 'add-child.php'], true) ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 10-16 0"/></svg>
        <span class="bottom-nav-label">فرزندان</span>
    </a>
    <a href="<?php echo e(url('parent/messages.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'messages.php' ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        <span class="bottom-nav-label">پیام‌ها</span>
        <?php if (isset($unreadCount) && $unreadCount > 0): ?>
        <span class="bottom-nav-badge"><?php echo e(persianNumber((string)$unreadCount)); ?></span>
        <?php endif; ?>
    </a>
    <a href="<?php echo e(url('parent/attendance.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'attendance.php' ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span class="bottom-nav-label">حضور</span>
    </a>
    <a href="<?php echo e(url('parent/payments.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'payments.php' ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        <span class="bottom-nav-label">پرداخت</span>
    </a>
    <a href="<?php echo e(url('parent/profile.php')); ?>" class="bottom-nav-item <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>">
        <span class="bottom-nav-indicator"></span>
        <svg class="bottom-nav-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span class="bottom-nav-label">پروفایل</span>
    </a>
</nav>

    <script src="<?php echo e(asset('assets/js/script.js')); ?>" defer></script>
</body>
</html>

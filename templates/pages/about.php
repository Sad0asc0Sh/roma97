<?php
/**
 * Dedicated About Us Template
 * Roma Kindergarten
 */
?>
<!-- Inner Hero Banner -->
<section class="inner-hero" style="position: relative; background: var(--gradient-hero), url('<?php echo asset('assets/uploads/slide_6a40973767a173.36208127.png'); ?>') center/cover no-repeat; padding: var(--space-2xl) 0 var(--space-xl) 0; color: var(--white); text-align: center;">
    <div class="container">
        <div class="breadcrumb" style="display: flex; justify-content: center; gap: 8px; font-size: var(--text-sm); margin-bottom: var(--space-sm); opacity: 0.9;">
            <a href="<?php echo e(url('index.php')); ?>" style="color: var(--white); text-decoration: none;">خانه</a>
            <span>‹</span>
            <span style="color: var(--accent);">درباره روما</span>
        </div>
        <h1 style="font-family: var(--font-display); font-size: var(--text-4xl); margin-bottom: var(--space-xs);">داستان و فلسفه مهدکودک روما</h1>
        <p style="font-size: var(--text-lg); max-width: 640px; margin: 0 auto; opacity: 0.95;">
            محیطی امن، گرم و خلاق برای رشد همه‌جانبه، شادابی و کشف استعدادهای فرزند شما
        </p>
    </div>
</section>

<!-- Why Roma Section (Ink Night Signature Dark Card Container) -->
<section class="section why-roma-section" style="padding: var(--space-2xl) 0; background: var(--bg-cream);">
    <div class="container">
        <div style="background: var(--ink-night); color: var(--white); border-radius: var(--radius-xl); padding: var(--space-2xl) var(--space-xl); box-shadow: var(--shadow-xl);">
            <div class="section-header text-center" style="margin-bottom: var(--space-xl);">
                <h2 style="font-family: var(--font-display); font-size: var(--text-3xl); color: var(--white); margin-bottom: var(--space-xs);">
                    اصول سه‌گانه روما
                </h2>
                <p style="color: rgba(255,255,255,0.8); font-size: var(--text-base);">ارزش‌هایی که هر روز در روما زندگی می‌کنیم</p>
            </div>

            <div class="why-roma-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: var(--space-xl);">
                <div class="why-card" style="background: var(--ink-night-soft); padding: var(--space-lg); border-radius: var(--radius-lg); border: 1px solid rgba(255,255,255,0.1);">
                    <div style="font-family: var(--font-display); font-size: var(--text-5xl); font-weight: 800; color: var(--accent); margin-bottom: var(--space-xs); line-height: 1;">۰۱</div>
                    <h3 style="font-size: var(--text-xl); color: var(--white); margin-bottom: var(--space-sm);">۱. یادگیری از دل بازی</h3>
                    <p style="color: rgba(255,255,255,0.85); font-size: var(--text-base); line-height: 1.8;">
                        کودکان با حفظ‌کردن رشد نمی‌کنند؛ آن‌ها با لمس‌کردن، ساختن و تجربه مستقیم، مفاهیم ریاضی، زبان و مهارت‌های اجتماعی را فرا می‌گیرند.
                    </p>
                </div>

                <div class="why-card" style="background: var(--ink-night-soft); padding: var(--space-lg); border-radius: var(--radius-lg); border: 1px solid rgba(255,255,255,0.1);">
                    <div style="font-family: var(--font-display); font-size: var(--text-5xl); font-weight: 800; color: var(--accent); margin-bottom: var(--space-xs); line-height: 1;">۰۲</div>
                    <h3 style="font-size: var(--text-xl); color: var(--white); margin-bottom: var(--space-sm);">۲. شفافیت کامل با والدین</h3>
                    <p style="color: rgba(255,255,255,0.85); font-size: var(--text-base); line-height: 1.8;">
                        شما در تمام لحظات کنار ما هستید. از پنل اختصاصی والدین با گزارش روزانه تغذیه، خواب و فعالیت‌ها تا امکان ارتباط مستقیم با مربی.
                    </p>
                </div>

                <div class="why-card" style="background: var(--ink-night-soft); padding: var(--space-lg); border-radius: var(--radius-lg); border: 1px solid rgba(255,255,255,0.1);">
                    <div style="font-family: var(--font-display); font-size: var(--text-5xl); font-weight: 800; color: var(--accent); margin-bottom: var(--space-xs); line-height: 1;">۰۳</div>
                    <h3 style="font-size: var(--text-xl); color: var(--white); margin-bottom: var(--space-sm);">۳. رشد فردی، نه یکسان‌سازی</h3>
                    <p style="color: rgba(255,255,255,0.85); font-size: var(--text-base); line-height: 1.8;">
                        هر کودک یک الگوی رشد بی‌نظیر دارد. مربیان ما برنامه‌های آموزشی را با ریتم و استعداد ویژه هر فرزند تنظیم می‌کنند.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Timeline Section -->
<section class="section timeline-section" style="padding: var(--space-2xl) 0;">
    <div class="container">
        <div class="section-header text-center" style="margin-bottom: var(--space-xl);">
            <h2 class="title-underline" style="font-family: var(--font-display); font-size: var(--text-3xl); color: var(--neutral-dark);">
                مسیر رشد روما در یک نگاه
            </h2>
            <p style="color: var(--muted); font-size: var(--text-base); margin-top: var(--space-xs);">از تاسیس تا امروز همراه با خانواده‌ها</p>
        </div>

        <div class="timeline-vertical" style="max-width: 680px; margin: 0 auto;">
            <div class="timeline-item">
                <div class="timeline-time">۱۳۹۵</div>
                <div class="timeline-title">تاسیس مهدکودک روما با ۲ کلاس نوباوه</div>
                <div class="timeline-desc">شروع فعالیت با مجوز رسمی بهزیستی و ظرفیت پذیرش ۲۰ کودک در فضایی صمیمی.</div>
            </div>

            <div class="timeline-item">
                <div class="timeline-time">۱۳۹۹</div>
                <div class="timeline-title">توسعه فضای بازی سرپوشیده و کارگاه‌های تخصصی</div>
                <div class="timeline-desc">افزایش بخش پیش‌دبستانی، راه‌اندازی کارگاه سفالگری و اتاق بازی‌های تعاملی.</div>
            </div>

            <div class="timeline-item">
                <div class="timeline-time">۱۴۰۳</div>
                <div class="timeline-title">ارتقای سیستم هوشمند والدین و پایش رشد</div>
                <div class="timeline-desc">استقرار پنل دیجیتال شفافیت والدین، پایش تغذیه ارگانیک و دوربین‌های مداربسته پیشرفته.</div>
            </div>
        </div>
    </div>
</section>

<!-- Teachers Team Section -->
<section class="section team-section" style="padding: var(--space-2xl) 0; background: var(--paper);">
    <div class="container">
        <div class="section-header text-center" style="margin-bottom: var(--space-xl);">
            <h2 style="font-family: var(--font-display); font-size: var(--text-3xl); color: var(--neutral-dark);">
                تیم مربیان تخصصی روما
            </h2>
            <p style="color: var(--muted); font-size: var(--text-base);">دارای گواهی‌نامه‌های رسمی مربی‌گری و روانشناسی کودک</p>
        </div>

        <div class="team-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: var(--space-lg);">
            <div class="team-card" style="background: var(--white); border-radius: var(--radius-lg); padding: var(--space-lg); text-align: center; box-shadow: var(--shadow-sm); border: 1px solid var(--border);">
                <div style="width: 100px; height: 100px; border-radius: 50%; background: var(--primary-faint); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; font-size: var(--text-2xl); font-weight: 700; margin: 0 auto var(--space-md) auto; border: 3px solid var(--primary-light);">
                    م‌ا
                </div>
                <h3 style="font-size: var(--text-lg); margin-bottom: 4px;">مریم احمدی</h3>
                <span class="badge badge-success" style="margin-bottom: var(--space-xs);">سرپرست مربیان</span>
                <p style="font-size: var(--text-sm); color: var(--muted); margin: 0;">کارشناس ارشد روانشناسی کودک با ۸ سال سابقه آموزش</p>
            </div>

            <div class="team-card" style="background: var(--white); border-radius: var(--radius-lg); padding: var(--space-lg); text-align: center; box-shadow: var(--shadow-sm); border: 1px solid var(--border);">
                <div style="width: 100px; height: 100px; border-radius: 50%; background: var(--secondary-faint); color: var(--secondary-dark); display: flex; align-items: center; justify-content: center; font-size: var(--text-2xl); font-weight: 700; margin: 0 auto var(--space-md) auto; border: 3px solid var(--secondary-light);">
                    س‌ر
                </div>
                <h3 style="font-size: var(--text-lg); margin-bottom: 4px;">سارا رضایی</h3>
                <span class="badge badge-warning" style="margin-bottom: var(--space-xs);">مربی خردسال</span>
                <p style="font-size: var(--text-sm); color: var(--muted); margin: 0;">مربی رسمی بهزیستی و متخصص بازی‌درمانی</p>
            </div>

            <div class="team-card" style="background: var(--white); border-radius: var(--radius-lg); padding: var(--space-lg); text-align: center; box-shadow: var(--shadow-sm); border: 1px solid var(--border);">
                <div style="width: 100px; height: 100px; border-radius: 50%; background: var(--primary-faint); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; font-size: var(--text-2xl); font-weight: 700; margin: 0 auto var(--space-md) auto; border: 3px solid var(--primary-light);">
                    ن‌ک
                </div>
                <h3 style="font-size: var(--text-lg); margin-bottom: 4px;">نرگس کریمی</h3>
                <span class="badge badge-success" style="margin-bottom: var(--space-xs);">مربی پیش‌دبستانی</span>
                <p style="font-size: var(--text-sm); color: var(--muted); margin: 0;">کارشناس علوم تربیتی با تخصص مهارتهای قبل از مدرسه</p>
            </div>

            <div class="team-card" style="background: var(--white); border-radius: var(--radius-lg); padding: var(--space-lg); text-align: center; box-shadow: var(--shadow-sm); border: 1px solid var(--border);">
                <div style="width: 100px; height: 100px; border-radius: 50%; background: var(--secondary-faint); color: var(--secondary-dark); display: flex; align-items: center; justify-content: center; font-size: var(--text-2xl); font-weight: 700; margin: 0 auto var(--space-md) auto; border: 3px solid var(--secondary-light);">
                    ز‌م
                </div>
                <h3 style="font-size: var(--text-lg); margin-bottom: 4px;">زهرا محمدی</h3>
                <span class="badge badge-info" style="margin-bottom: var(--space-xs);">مربی هنر و خلاقیت</span>
                <p style="font-size: var(--text-sm); color: var(--muted); margin: 0;">مجری کارگاه‌های نقاشی، موسیقی و کاردستی ارگانیک</p>
            </div>
        </div>
    </div>
</section>

<!-- Certificates Section -->
<section class="section certificates-section" style="padding: var(--space-2xl) 0;">
    <div class="container">
        <div class="section-header text-center" style="margin-bottom: var(--space-xl);">
            <h2 style="font-family: var(--font-display); font-size: var(--text-3xl); color: var(--neutral-dark);">
                مجوزها و تاییدیه‌های رسمی
            </h2>
            <p style="color: var(--muted); font-size: var(--text-base);">تضمین استانداردهای بهداشتی، آموزشی و ایمنی</p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--space-md); text-align: center;">
            <div style="background: var(--white); padding: var(--space-md); border-radius: var(--radius-md); border: 1px solid var(--border);">
                <div style="color: var(--primary); margin-bottom: var(--space-xs);">
                    <svg style="width: 40px; height: 40px;"><use href="<?php echo asset('assets/img/icons.svg#icon-shield'); ?>"/></svg>
                </div>
                <h4 style="font-size: var(--text-base); margin-bottom: 4px;">پروانه رسمی بهزیستی</h4>
                <p style="font-size: var(--text-xs); color: var(--muted); margin: 0;">شماره ثبت و استعلام معتبر سازمان</p>
            </div>

            <div style="background: var(--white); padding: var(--space-md); border-radius: var(--radius-md); border: 1px solid var(--border);">
                <div style="color: var(--secondary); margin-bottom: var(--space-xs);">
                    <svg style="width: 40px; height: 40px;"><use href="<?php echo asset('assets/img/icons.svg#icon-award'); ?>"/></svg>
                </div>
                <h4 style="font-size: var(--text-base); margin-bottom: 4px;">تاییدیه بهداشت و تغذیه</h4>
                <p style="font-size: var(--text-xs); color: var(--muted); margin: 0;">کارت بهداشت مربیان و نظارت دوره‌ای</p>
            </div>

            <div style="background: var(--white); padding: var(--space-md); border-radius: var(--radius-md); border: 1px solid var(--border);">
                <div style="color: var(--accent); margin-bottom: var(--space-xs);">
                    <svg style="width: 40px; height: 40px;"><use href="<?php echo asset('assets/img/icons.svg#icon-camera'); ?>"/></svg>
                </div>
                <h4 style="font-size: var(--text-base); margin-bottom: 4px;">ایمنی و پایش دوربین</h4>
                <p style="font-size: var(--text-xs); color: var(--muted); margin: 0;">استاندارد ایمنی فیزیکی کلاس‌ها و حیاط</p>
            </div>
        </div>
    </div>
</section>

<!-- Shared Footer CTA -->
<?php require __DIR__ . '/../cta-section.php'; ?>
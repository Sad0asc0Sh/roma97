<?php
/**
 * Dedicated Classes & Programs Template
 * Roma Kindergarten
 */
?>
<!-- Inner Hero Banner -->
<section class="inner-hero" style="position: relative; background: var(--gradient-hero), url('<?php echo asset('assets/uploads/slide_6a40973767a173.36208127.png'); ?>') center/cover no-repeat; padding: var(--space-2xl) 0 var(--space-xl) 0; color: var(--white); text-align: center;">
    <div class="container">
        <div class="breadcrumb" style="display: flex; justify-content: center; gap: 8px; font-size: var(--text-sm); margin-bottom: var(--space-sm); opacity: 0.9;">
            <a href="<?php echo e(url('index.php')); ?>" style="color: var(--white); text-decoration: none;">خانه</a>
            <span>‹</span>
            <span style="color: var(--accent);">کلاس‌ها و دوره‌ها</span>
        </div>
        <h1 style="font-family: var(--font-display); font-size: var(--text-4xl); margin-bottom: var(--space-xs);">کلاس‌ها و برنامه‌های سنی روما</h1>
        <p style="font-size: var(--text-lg); max-width: 640px; margin: 0 auto; opacity: 0.95;">
            برنامه‌ریزی دقیق آموزشی، مراقبتی و پرورشی متناسب با نیازهای هر رده سنی
        </p>
    </div>
</section>

<!-- Classes Tabs & Details Section -->
<section class="section classes-detail-section" style="padding: var(--space-2xl) 0;">
    <div class="container">
        <!-- Interactive Age Group Tabs Nav -->
        <div class="tabs-wrapper" style="margin-bottom: var(--space-xl);">
            <div class="tabs" id="classTabs" role="tablist" style="justify-content: center; border-bottom-color: var(--paper-line);">
                <button class="tab-btn is-active" data-target="#tab-nobaaveh" role="tab" aria-selected="true">
                    کلاس نوباوه (۶ ماه تا ۲ سال)
                </button>
                <button class="tab-btn" data-target="#tab-khordsal" role="tab" aria-selected="false">
                    کلاس خردسال (۲ تا ۴ سال)
                </button>
                <button class="tab-btn" data-target="#tab-pish" role="tab" aria-selected="false">
                    پیش‌دبستانی (۴ تا ۶ سال)
                </button>
            </div>
        </div>

        <!-- Tab 1: Nobaaveh (0-2 Yrs) -->
        <div class="tab-pane is-active" id="tab-nobaaveh" role="tabpanel">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: var(--space-xl); align-items: start;">
                <div style="background: var(--white); padding: var(--space-xl); border-radius: var(--radius-xl); border: 1px solid var(--border); box-shadow: var(--shadow-sm);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-md);">
                        <span class="badge badge-warning" style="font-size: var(--text-sm); padding: 6px 14px;">رده سنی: ۶ ماه تا ۲ سال</span>
                        <span style="font-size: var(--text-sm); color: var(--muted); font-weight: 600;">نسبت مربی: ۱ به ۴</span>
                    </div>
                    <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); color: var(--neutral-dark); margin-bottom: var(--space-sm);">
                        کلاس نوباوه — مهار و مراقبت گرم
                    </h3>
                    <p style="color: var(--neutral-medium); font-size: var(--text-base); line-height: 1.8; margin-bottom: var(--space-lg);">
                        تمرکز بر حس امنیت، تقویت مهارت‌های حرکتی درشت، خواب منظم و مراقبت ویژه فردی. همراه با گزارش دقیق ساعتی تغذیه و خواب.
                    </p>

                    <!-- Capacity Progress Bar -->
                    <div style="background: var(--paper); padding: var(--space-md); border-radius: var(--radius-md); margin-bottom: var(--space-lg);">
                        <div style="display: flex; justify-content: space-between; font-size: var(--text-sm); font-weight: 600; margin-bottom: 6px;">
                            <span>ظرفیت باقیمانده دوره جدید</span>
                            <span style="color: var(--secondary);">۲ جای خالی از ۱۰</span>
                        </div>
                        <div style="height: 8px; background: var(--border); border-radius: 4px; overflow: hidden;">
                            <div style="width: 80%; height: 100%; background: var(--gradient-earth); border-radius: 4px;"></div>
                        </div>
                    </div>

                    <h4 style="font-size: var(--text-base); font-weight: 700; margin-bottom: var(--space-sm);">مهارت‌های کلیدی دوره:</h4>
                    <ul style="list-style: none; padding: 0; margin: 0; display: grid; gap: 8px;">
                        <li style="display: flex; align-items: center; gap: 8px; font-size: var(--text-sm); color: var(--neutral-dark);">
                            <svg style="width:18px; height:18px; color: var(--primary);"><use href="<?php echo asset('assets/img/icons.svg#icon-check'); ?>"/></svg>
                            تقویت تعادل و گام‌برداری اولیه
                        </li>
                        <li style="display: flex; align-items: center; gap: 8px; font-size: var(--text-sm); color: var(--neutral-dark);">
                            <svg style="width:18px; height:18px; color: var(--primary);"><use href="<?php echo asset('assets/img/icons.svg#icon-check'); ?>"/></svg>
                            تحریک حسی با اسباب‌بازی‌های لمسی ایمن
                        </li>
                        <li style="display: flex; align-items: center; gap: 8px; font-size: var(--text-sm); color: var(--neutral-dark);">
                            <svg style="width:18px; height:18px; color: var(--primary);"><use href="<?php echo asset('assets/img/icons.svg#icon-check'); ?>"/></svg>
                            تنظیم نظم خواب و تغذیه ارگانیک
                        </li>
                    </ul>
                </div>

                <!-- Daily Schedule Timeline -->
                <div style="background: var(--paper); padding: var(--space-xl); border-radius: var(--radius-xl); border: 1px solid var(--paper-line);">
                    <h3 style="font-family: var(--font-display); font-size: var(--text-xl); color: var(--neutral-dark); margin-bottom: var(--space-lg);">
                        برنامه روزانه کلاس نوباوه
                    </h3>
                    <div class="timeline-vertical">
                        <div class="timeline-item">
                            <div class="timeline-time">۰۷:۳۰ - ۰۹:۰۰</div>
                            <div class="timeline-title">پذیرش، معاینه اولیه و صبحانه گرم</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۰۹:۰۰ - ۱۰:۳۰</div>
                            <div class="timeline-title">بازی حرکتی ارگانیک و ماساژ کودک</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۱۰:۳۰ - ۱۲:۰۰</div>
                            <div class="timeline-title">میان‌العده میوه‌ای و قصه حسی</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۱۲:۰۰ - ۱۳:۳۰</div>
                            <div class="timeline-title">ناهار اصلی و آماده‌سازی خواب</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۱۳:۳۰ - ۱۵:۳۰</div>
                            <div class="timeline-title">زمان استراحت و خواب آرام</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۱۵:۳۰ - ۱۶:۳۰</div>
                            <div class="timeline-title">میان‌وعده و تحویل به والدین</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 2: Khordsal (2-4 Yrs) -->
        <div class="tab-pane" id="tab-khordsal" role="tabpanel">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: var(--space-xl); align-items: start;">
                <div style="background: var(--white); padding: var(--space-xl); border-radius: var(--radius-xl); border: 1px solid var(--border); box-shadow: var(--shadow-sm);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-md);">
                        <span class="badge badge-success" style="font-size: var(--text-sm); padding: 6px 14px;">رده سنی: ۲ تا ۴ سال</span>
                        <span style="font-size: var(--text-sm); color: var(--muted); font-weight: 600;">نسبت مربی: ۱ به ۶</span>
                    </div>
                    <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); color: var(--neutral-dark); margin-bottom: var(--space-sm);">
                        کلاس خردسال — کشف و جامعه‌پذیری
                    </h3>
                    <p style="color: var(--neutral-medium); font-size: var(--text-base); line-height: 1.8; margin-bottom: var(--space-lg);">
                        پایه‌ریزی کلامی، کار گروهی اولیه، سفالگری، شعر خوانی و مستقل شدن در آداب شخصی و اجتماعی.
                    </p>

                    <div style="background: var(--paper); padding: var(--space-md); border-radius: var(--radius-md); margin-bottom: var(--space-lg);">
                        <div style="display: flex; justify-content: space-between; font-size: var(--text-sm); font-weight: 600; margin-bottom: 6px;">
                            <span>ظرفیت باقیمانده دوره جدید</span>
                            <span style="color: var(--primary);">۳ جای خالی از ۱۵</span>
                        </div>
                        <div style="height: 8px; background: var(--border); border-radius: 4px; overflow: hidden;">
                            <div style="width: 75%; height: 100%; background: var(--gradient-forest); border-radius: 4px;"></div>
                        </div>
                    </div>

                    <h4 style="font-size: var(--text-base); font-weight: 700; margin-bottom: var(--space-sm);">مهارت‌های کلیدی دوره:</h4>
                    <ul style="list-style: none; padding: 0; margin: 0; display: grid; gap: 8px;">
                        <li style="display: flex; align-items: center; gap: 8px; font-size: var(--text-sm); color: var(--neutral-dark);">
                            <svg style="width:18px; height:18px; color: var(--primary);"><use href="<?php echo asset('assets/img/icons.svg#icon-check'); ?>"/></svg>
                            گسترش واژگان و بیان هوشمندانه احساسات
                        </li>
                        <li style="display: flex; align-items: center; gap: 8px; font-size: var(--text-sm); color: var(--neutral-dark);">
                            <svg style="width:18px; height:18px; color: var(--primary);"><use href="<?php echo asset('assets/img/icons.svg#icon-check'); ?>"/></svg>
                            کارگاه سفالگری و سفره‌نگاری دستی
                        </li>
                        <li style="display: flex; align-items: center; gap: 8px; font-size: var(--text-sm); color: var(--neutral-dark);">
                            <svg style="width:18px; height:18px; color: var(--primary);"><use href="<?php echo asset('assets/img/icons.svg#icon-check'); ?>"/></svg>
                            بازی‌های تعاملی رعایت نوبت و اشتراک
                        </li>
                    </ul>
                </div>

                <!-- Daily Schedule Timeline -->
                <div style="background: var(--paper); padding: var(--space-xl); border-radius: var(--radius-xl); border: 1px solid var(--paper-line);">
                    <h3 style="font-family: var(--font-display); font-size: var(--text-xl); color: var(--neutral-dark); margin-bottom: var(--space-lg);">
                        برنامه روزانه کلاس خردسال
                    </h3>
                    <div class="timeline-vertical">
                        <div class="timeline-item">
                            <div class="timeline-time">۰۷:۳۰ - ۰۹:۰۰</div>
                            <div class="timeline-title">پذیرش و صبحانه گروهی</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۰۹:۰۰ - ۱۰:۳۰</div>
                            <div class="timeline-title">کارگاه زبان و شعرخوانی موزیکال</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۱۰:۳۰ - ۱۲:۰۰</div>
                            <div class="timeline-title">بازی آزاد در حیاط اختصاصی</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۱۲:۰۰ - ۱۳:۰۰</div>
                            <div class="timeline-title">سفره ناهار و خود مراقبتی</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۱۳:۰۰ - ۱۵:۰۰</div>
                            <div class="timeline-title">خواب/قصه آرامش‌بخش</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۱۵:۰۰ - ۱۶:۳۰</div>
                            <div class="timeline-title">کارگاه هنری و آماده‌سازی تحویل</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 3: Pish-dabestani (4-6 Yrs) -->
        <div class="tab-pane" id="tab-pish" role="tabpanel">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: var(--space-xl); align-items: start;">
                <div style="background: var(--white); padding: var(--space-xl); border-radius: var(--radius-xl); border: 1px solid var(--border); box-shadow: var(--shadow-sm);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-md);">
                        <span class="badge badge-info" style="font-size: var(--text-sm); padding: 6px 14px;">رده سنی: ۴ تا ۶ سال</span>
                        <span style="font-size: var(--text-sm); color: var(--muted); font-weight: 600;">نسبت مربی: ۱ به ۸</span>
                    </div>
                    <h3 style="font-family: var(--font-display); font-size: var(--text-2xl); color: var(--neutral-dark); margin-bottom: var(--space-sm);">
                        پیش‌دبستانی — آمادگی ورود به مدرسه
                    </h3>
                    <p style="color: var(--neutral-medium); font-size: var(--text-base); line-height: 1.8; margin-bottom: var(--space-lg);">
                        پایه‌ریزی تفکر منطقی، ریاضیات لمسی، مهارت حل مسئله، زبان دوم کاربردی و بالا بردن اعتماد به نفس حضور در کلاس رسمی.
                    </p>

                    <div style="background: var(--paper); padding: var(--space-md); border-radius: var(--radius-md); margin-bottom: var(--space-lg);">
                        <div style="display: flex; justify-content: space-between; font-size: var(--text-sm); font-weight: 600; margin-bottom: 6px;">
                            <span>ظرفیت باقیمانده دوره جدید</span>
                            <span style="color: var(--info);">۴ جای خالی از ۱۸</span>
                        </div>
                        <div style="height: 8px; background: var(--border); border-radius: 4px; overflow: hidden;">
                            <div style="width: 78%; height: 100%; background: var(--gradient-magic); border-radius: 4px;"></div>
                        </div>
                    </div>

                    <h4 style="font-size: var(--text-base); font-weight: 700; margin-bottom: var(--space-sm);">مهارت‌های کلیدی دوره:</h4>
                    <ul style="list-style: none; padding: 0; margin: 0; display: grid; gap: 8px;">
                        <li style="display: flex; align-items: center; gap: 8px; font-size: var(--text-sm); color: var(--neutral-dark);">
                            <svg style="width:18px; height:18px; color: var(--primary);"><use href="<?php echo asset('assets/img/icons.svg#icon-check'); ?>"/></svg>
                            مهارت‌های دست‌ورزی و پیش‌نوشتاری
                        </li>
                        <li style="display: flex; align-items: center; gap: 8px; font-size: var(--text-sm); color: var(--neutral-dark);">
                            <svg style="width:18px; height:18px; color: var(--primary);"><use href="<?php echo asset('assets/img/icons.svg#icon-check'); ?>"/></svg>
                            مفاهیم پایه ریاضیات لمسی (مونته‌سوری)
                        </li>
                        <li style="display: flex; align-items: center; gap: 8px; font-size: var(--text-sm); color: var(--neutral-dark);">
                            <svg style="width:18px; height:18px; color: var(--primary);"><use href="<?php echo asset('assets/img/icons.svg#icon-check'); ?>"/></svg>
                            پروژه‌های پژوهشی و علمی فکری گروهی
                        </li>
                    </ul>
                </div>

                <!-- Daily Schedule Timeline -->
                <div style="background: var(--paper); padding: var(--space-xl); border-radius: var(--radius-xl); border: 1px solid var(--paper-line);">
                    <h3 style="font-family: var(--font-display); font-size: var(--text-xl); color: var(--neutral-dark); margin-bottom: var(--space-lg);">
                        برنامه روزانه پیش‌دبستانی
                    </h3>
                    <div class="timeline-vertical">
                        <div class="timeline-item">
                            <div class="timeline-time">۰۷:۳۰ - ۰۸:۳۰</div>
                            <div class="timeline-title">ورود و صبحانه سالم</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۰۸:۳۰ - ۱۰:۰۰</div>
                            <div class="timeline-title">حلقه گفتگو و آموزش پیش‌نوشتاری</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۱۰:۰۰ - ۱۱:۳۰</div>
                            <div class="timeline-title">کارگاه هوش ریاضی و بازی گروهی</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۱۱:۳۰ - ۱۳:۰۰</div>
                            <div class="timeline-title">بازی ورزشی، حیاط و ناهار</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۱۳:۰۰ - ۱۴:۳۰</div>
                            <div class="timeline-title">پروژه علمی کودک و آزمایشگاه خلاق</div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-time">۱۴:۳۰ - ۱۶:۳۰</div>
                            <div class="timeline-title">جمع‌بندی روزانه و تحویل به والدین</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Comparison Table Section -->
<section class="section comparison-section" style="padding: var(--space-2xl) 0; background: var(--bg-soft);">
    <div class="container">
        <div class="section-header text-center" style="margin-bottom: var(--space-xl);">
            <h2 style="font-family: var(--font-display); font-size: var(--text-3xl); color: var(--neutral-dark);">
                مقایسه سریع گروه‌های سنی
            </h2>
            <p style="color: var(--muted); font-size: var(--text-base);">بررسی یک‌نگاهی مشخصات دوره‌ها</p>
        </div>

        <div style="background: var(--white); border-radius: var(--radius-xl); padding: var(--space-lg); box-shadow: var(--shadow-sm); border: 1px solid var(--border); overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: start;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--paper-line);">
                        <th style="padding: 14px; color: var(--neutral-dark); font-size: var(--text-base);">معیار مقایسه</th>
                        <th style="padding: 14px; color: var(--primary); font-size: var(--text-base);">کلاس نوباوه</th>
                        <th style="padding: 14px; color: var(--secondary); font-size: var(--text-base);">کلاس خردسال</th>
                        <th style="padding: 14px; color: var(--info); font-size: var(--text-base);">پیش‌دبستانی</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: 14px; font-weight: 700; color: var(--neutral-dark);">رده سنی</td>
                        <td style="padding: 14px;">۶ ماه تا ۲ سال</td>
                        <td style="padding: 14px;">۲ تا ۴ سال</td>
                        <td style="padding: 14px;">۴ تا ۶ سال</td>
                    </tr>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: 14px; font-weight: 700; color: var(--neutral-dark);">نسبت مربی به کودک</td>
                        <td style="padding: 14px;">۱ به ۴ (تمرکز ویژه)</td>
                        <td style="padding: 14px;">۱ به ۶</td>
                        <td style="padding: 14px;">۱ به ۸</td>
                    </tr>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: 14px; font-weight: 700; color: var(--neutral-dark);">ساعات فعالیت</td>
                        <td style="padding: 14px;">۷:۳۰ الی ۱۶:۳۰</td>
                        <td style="padding: 14px;">۷:۳۰ الی ۱۶:۳۰</td>
                        <td style="padding: 14px;">۷:۳۰ الی ۱۶:۳۰</td>
                    </tr>
                    <tr>
                        <td style="padding: 14px; font-weight: 700; color: var(--neutral-dark);">گزارش‌دهی روزانه</td>
                        <td style="padding: 14px;">آنلاین + آنلاین هر ۲ ساعت</td>
                        <td style="padding: 14px;">روزانه در پنل والدین</td>
                        <td style="padding: 14px;">روزانه در پنل والدین</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- Shared Footer CTA -->
<?php require __DIR__ . '/../cta-section.php'; ?>
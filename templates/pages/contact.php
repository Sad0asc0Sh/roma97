<?php
/**
 * Dedicated Contact Us Template
 * Roma Kindergarten
 */
?>
<!-- Inner Hero Banner -->
<section class="inner-hero" style="position: relative; background: var(--gradient-hero), url('<?php echo asset('assets/uploads/slide_6a40973767a173.36208127.png'); ?>') center/cover no-repeat; padding: var(--space-2xl) 0 var(--space-xl) 0; color: var(--white); text-align: center;">
    <div class="container">
        <div class="breadcrumb" style="display: flex; justify-content: center; gap: 8px; font-size: var(--text-sm); margin-bottom: var(--space-sm); opacity: 0.9;">
            <a href="<?php echo e(url('index.php')); ?>" style="color: var(--white); text-decoration: none;">خانه</a>
            <span>‹</span>
            <span style="color: var(--accent);">تماس با ما</span>
        </div>
        <h1 style="font-family: var(--font-display); font-size: var(--text-4xl); margin-bottom: var(--space-xs);">ارتباط با مهدکودک روما و بازدید حضوری</h1>
        <p style="font-size: var(--text-lg); max-width: 640px; margin: 0 auto; opacity: 0.95;">
            خوشحال می‌شویم پاسخگوی سوالات شما باشیم و زمان بازدید از مجموعه را هماهنگ کنیم
        </p>
    </div>
</section>

<!-- Contact Form & Info Grid -->
<section class="section contact-section" style="padding: var(--space-2xl) 0;">
    <div class="container">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: var(--space-2xl); align-items: start;">

            <!-- Column 1: Contact Form -->
            <div style="background: var(--white); padding: var(--space-2xl); border-radius: var(--radius-xl); border: 1px solid var(--border); box-shadow: var(--shadow-md);">
                <h2 style="font-family: var(--font-display); font-size: var(--text-2xl); color: var(--neutral-dark); margin-bottom: var(--space-xs);">
                    ارسال پیام یا درخواست بازدید
                </h2>
                <p style="color: var(--muted); font-size: var(--text-sm); margin-bottom: var(--space-lg);">
                    فرم زیر را پر کنید؛ کارشناسان ما در سریع‌ترین زمان با شما تماس می‌گیرند.
                </p>

                <form id="contactForm" onsubmit="handleContactSubmit(event)" style="display: grid; gap: var(--space-md);">
                    <div>
                        <label for="contact_name" style="display: block; font-weight: 600; font-size: var(--text-sm); margin-bottom: 6px;">نام و نام خانوادگی <span style="color: var(--danger);">*</span></label>
                        <input type="text" id="contact_name" name="name" required placeholder="مثال: مریم محمدی" class="form-control" style="width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-family: inherit;">
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: var(--space-sm);">
                        <div>
                            <label for="contact_phone" style="display: block; font-weight: 600; font-size: var(--text-sm); margin-bottom: 6px;">شماره تماس <span style="color: var(--danger);">*</span></label>
                            <input type="tel" id="contact_phone" name="phone" required placeholder="۰۹۱۲XXXXXXX" class="form-control" style="width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-family: inherit;">
                        </div>
                        <div>
                            <label for="contact_email" style="display: block; font-weight: 600; font-size: var(--text-sm); margin-bottom: 6px;">ایمیل (اختیاری)</label>
                            <input type="email" id="contact_email" name="email" placeholder="example@mail.com" class="form-control" style="width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-family: inherit;">
                        </div>
                    </div>

                    <div>
                        <label for="contact_subject" style="display: block; font-weight: 600; font-size: var(--text-sm); margin-bottom: 6px;">موضوع پیام</label>
                        <select id="contact_subject" name="subject" class="form-control" style="width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-family: inherit; background: var(--white);">
                            <option value="visit">درخواست بازدید حضوری از مهدکودک</option>
                            <option value="register">اطلاعات شهریه و مدارک ثبت‌نام</option>
                            <option value="consult">مشاوره با روانشناس مجموعه</option>
                            <option value="other">سایر موارد</option>
                        </select>
                    </div>

                    <div>
                        <label for="contact_message" style="display: block; font-weight: 600; font-size: var(--text-sm); margin-bottom: 6px;">متن پیام یا توضیحات <span style="color: var(--danger);">*</span></label>
                        <textarea id="contact_message" name="message" rows="4" required placeholder="پیام خود را اینجا بنویسید..." class="form-control" style="width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-family: inherit; resize: vertical;"></textarea>
                    </div>

                    <div id="contactFormMessage" style="display: none; padding: 12px; border-radius: var(--radius-sm); font-size: var(--text-sm);"></div>

                    <button type="submit" id="contactSubmitBtn" class="btn btn-primary btn-lg" style="width: 100%; padding: 14px;">
                        ارسال پیام و هماهنگی
                    </button>
                </form>

                <!-- Quick Action Direct Buttons -->
                <div style="margin-top: var(--space-xl); padding-top: var(--space-lg); border-top: 1px solid var(--border); display: flex; gap: var(--space-sm); flex-wrap: wrap;">
                    <a href="tel:<?php echo e(siteContactPhone()); ?>" class="btn btn-outline" style="flex: 1; min-width: 140px; justify-content: center; gap: 8px;">
                        <svg style="width: 18px; height: 18px; color: var(--primary);"><use href="<?php echo asset('assets/img/icons.svg#icon-phone'); ?>"/></svg>
                        تماس مستقیم
                    </a>
                    <a href="https://wa.me/<?php echo e(preg_replace('/[^0-9]/', '', siteContactPhone())); ?>" target="_blank" rel="noopener" class="btn btn-outline" style="flex: 1; min-width: 140px; justify-content: center; gap: 8px; color: #25D366; border-color: #25D366;">
                        <svg style="width: 18px; height: 18px;"><use href="<?php echo asset('assets/img/icons.svg#icon-whatsapp'); ?>"/></svg>
                        واتس‌اپ روما
                    </a>
                </div>
            </div>

            <!-- Column 2: Information, Map & Working Hours -->
            <div style="display: grid; gap: var(--space-lg);">

                <!-- Address & Map Embed Card -->
                <div style="background: var(--paper); padding: var(--space-xl); border-radius: var(--radius-xl); border: 1px solid var(--paper-line);">
                    <div style="display: flex; gap: var(--space-sm); margin-bottom: var(--space-md);">
                        <div style="color: var(--primary); flex-shrink: 0; margin-top: 2px;">
                            <svg style="width: 24px; height: 24px;"><use href="<?php echo asset('assets/img/icons.svg#icon-map-pin'); ?>"/></svg>
                        </div>
                        <div>
                            <h3 style="font-size: var(--text-lg); font-weight: 700; margin-bottom: 4px; color: var(--neutral-dark);">نشانی مهدکودک روما</h3>
                            <p style="color: var(--neutral-medium); font-size: var(--text-base); margin: 0; line-height: 1.7;">
                                <?php echo e(siteAddress()); ?>

                            </p>
                        </div>
                    </div>

                    <!-- Map Embed Container -->
                    <div style="width: 100%; height: 220px; border-radius: var(--radius-lg); overflow: hidden; border: 1px solid var(--paper-line); margin-top: var(--space-md);">
                        <iframe
                            title="نقشه موقعیت روما"
                            src="https://maps.google.com/maps?q=Tehran,Valiasr&t=&z=14&ie=UTF8&iwloc=&output=embed"
                            width="100%"
                            height="100%"
                            style="border:0;"
                            allowfullscreen=""
                            loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade">
                        </iframe>
                    </div>
                </div>

                <!-- Working Hours Table Card -->
                <div style="background: var(--white); padding: var(--space-xl); border-radius: var(--radius-xl); border: 1px solid var(--border); box-shadow: var(--shadow-sm);">
                    <div style="display: flex; gap: var(--space-sm); align-items: center; margin-bottom: var(--space-md);">
                        <div style="color: var(--secondary);">
                            <svg style="width: 24px; height: 24px;"><use href="<?php echo asset('assets/img/icons.svg#icon-clock'); ?>"/></svg>
                        </div>
                        <h3 style="font-size: var(--text-lg); font-weight: 700; margin: 0; color: var(--neutral-dark);">ساعات کاری و پذیرش</h3>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; font-size: var(--text-sm);">
                        <tbody>
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td style="padding: 10px 0; font-weight: 600; color: var(--neutral-dark);">شنبه تا چهارشنبه</td>
                                <td style="padding: 10px 0; text-align: left; color: var(--primary-dark); font-weight: 700;">۰۷:۰۰ الی ۱۶:۳۰</td>
                            </tr>
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td style="padding: 10px 0; font-weight: 600; color: var(--neutral-dark);">پنج‌شنبه‌ها</td>
                                <td style="padding: 10px 0; text-align: left; color: var(--primary-dark); font-weight: 700;">۰۷:۰۰ الی ۱۳:۰۰</td>
                            </tr>
                            <tr>
                                <td style="padding: 10px 0; font-weight: 600; color: var(--muted);">جمعه‌ها و تعطیلات رسمی</td>
                                <td style="padding: 10px 0; text-align: left; color: var(--danger); font-weight: 700;">تعطیل</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
function handleContactSubmit(event) {
    event.preventDefault();
    const btn = document.getElementById('contactSubmitBtn');
    const msg = document.getElementById('contactFormMessage');

    btn.disabled = true;
    btn.innerHTML = 'در حال ارسال...';

    setTimeout(() => {
        btn.disabled = false;
        btn.innerHTML = 'ارسال پیام و هماهنگی';
        msg.style.display = 'block';
        msg.style.background = 'var(--primary-faint)';
        msg.style.color = 'var(--primary-dark)';
        msg.style.border = '1px solid var(--primary)';
        msg.innerHTML = '✓ پیام شما با موفقیت ثبت شد. کارشناسان روما به زودی با شما تماس خواهند گرفت.';
        document.getElementById('contactForm').reset();
    }, 800);
}
</script>

<!-- Shared Footer CTA -->
<?php require __DIR__ . '/../cta-section.php'; ?>
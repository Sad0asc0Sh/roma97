(() => {
    'use strict';
    const digits = value => value.replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
        .replace(/[٠-٩]/g, d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
    const normalizePhone = value => {
        let phone = digits(value).replace(/[^0-9]/g, '');
        if (phone.startsWith('0098')) phone = '0' + phone.slice(4);
        else if (phone.startsWith('98') && phone.length === 12) phone = '0' + phone.slice(2);
        else if (phone.startsWith('9') && phone.length === 10) phone = '0' + phone;
        return phone;
    };
    const setError = (input, message) => {
        input.setCustomValidity(message);
        if (message) input.setAttribute('aria-invalid', 'true');
        else input.removeAttribute('aria-invalid');
        const target = document.getElementById(input.id + '-error');
        if (target) target.textContent = message;
    };
    const validate = input => {
        let message = '';
        if (input.required && !input.value) message = 'لطفاً این بخش را تکمیل کنید.';
        else if (input.matches('[data-phone]') && !/^09[0-9]{9}$/.test(normalizePhone(input.value))) {
            message = 'شماره موبایل معتبر وارد کنید؛ مانند 09123456789.';
        } else if (input.matches('[data-otp]') && !/^[0-9]{5}$/.test(digits(input.value.trim()))) {
            message = 'کد تأیید باید دقیقاً ۵ رقم باشد.';
        } else if (input.matches('[data-new-password]') && (new TextEncoder().encode(input.value).length < 8
            || new TextEncoder().encode(input.value).length > 72 || !/[A-Za-z]/.test(input.value)
            || !/[0-9]/.test(input.value) || input.value.includes('\0'))) {
            message = 'رمز باید حداقل ۸ کاراکتر، شامل حرف انگلیسی و عدد و حداکثر ۷۲ بایت باشد.';
        } else if (input.matches('[data-confirm-password]')
            && input.value !== input.form.querySelector('[data-new-password]')?.value) {
            message = 'تکرار رمز عبور با رمز جدید یکسان نیست.';
        } else if (['first_name', 'last_name'].includes(input.id)
            && !/^[\p{L}\p{Z}\s'\-‌]+$/u.test(input.value.trim())) {
            message = 'نام را با حروف وارد کنید.';
        }
        setError(input, message);
        return !message;
    };
    document.querySelectorAll('[data-reveal]').forEach(button => {
        button.hidden = false;
        const originalLabel = button.getAttribute('aria-label');
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.reveal);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(show));
            button.setAttribute('aria-label', show ? originalLabel.replace('نمایش', 'پنهان کردن') : originalLabel);
        });
    });
    document.querySelectorAll('[data-otp]').forEach(input => {
        input.addEventListener('input', () => { input.value = digits(input.value); });
        input.addEventListener('paste', event => {
            const code = digits(event.clipboardData.getData('text')).replace(/\s/g, '');
            if (/^[0-9]{5}$/.test(code)) {
                event.preventDefault();
                input.value = code;
                setError(input, '');
            }
        });
    });
    document.querySelectorAll('[data-auth-form]').forEach(form => {
        form.noValidate = true;
        const inputs = [...form.querySelectorAll('input:not([type="hidden"])')];
        inputs.forEach(input => {
            input.addEventListener('blur', () => { if (input.value) validate(input); });
            input.addEventListener('input', () => {
                setError(input, '');
                if (input.matches('[data-new-password]')) {
                    const confirm = form.querySelector('[data-confirm-password]');
                    if (confirm?.value) validate(confirm);
                }
            });
        });
        form.addEventListener('submit', event => {
            if (form.getAttribute('aria-busy') === 'true') { event.preventDefault(); return; }
            const invalid = inputs.filter(input => !validate(input));
            if (invalid.length) { event.preventDefault(); invalid[0].focus(); return; }
            inputs.filter(input => input.matches('[data-phone]')).forEach(input => { input.value = normalizePhone(input.value); });
            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"]').forEach(button => {
                button.disabled = true;
                const label = button.querySelector('[data-submit-label]');
                const loadingText = button.getAttribute('data-loading-text');
                if (label && loadingText && !button.dataset.origLabel) {
                    button.dataset.origLabel = label.textContent;
                    label.textContent = loadingText;
                }
            });
            document.documentElement.classList.add('is-navigating');
        });
    });
    document.querySelectorAll('[data-resend]').forEach(button => {
        const timer = button.form.querySelector('[data-countdown]');
        const until = Date.now() + Number(button.dataset.wait) * 1000;
        const tick = () => {
            const remaining = Math.max(0, Math.ceil((until - Date.now()) / 1000));
            button.disabled = remaining > 0 || button.form.getAttribute('aria-busy') === 'true';
            timer.textContent = remaining ? `(${remaining.toLocaleString('fa-IR')} ثانیه)` : '';
            return remaining;
        };
        if (tick()) {
            const interval = window.setInterval(() => { if (!tick()) window.clearInterval(interval); }, 1000);
        }
    });
    window.addEventListener('pageshow', () => {
        document.documentElement.classList.remove('is-navigating');
        document.querySelectorAll('a.is-pending').forEach(a => a.classList.remove('is-pending'));
        document.querySelectorAll('[data-auth-form]').forEach(form => {
            form.removeAttribute('aria-busy');
            form.querySelectorAll('button[type="submit"]:not([data-resend])').forEach(button => {
                button.disabled = false;
                const label = button.querySelector('[data-submit-label]');
                if (label && button.dataset.origLabel) {
                    label.textContent = button.dataset.origLabel;
                    delete button.dataset.origLabel;
                }
            });
        });
    });
    // Prefetch مقصد هنگام اعلام قصد کاربر (هاور/فوکوس/تاچ) — فقط هم‌مبدأ و GET، بدون تغییر رفتار کلیک.
    const prefetched = new Set();
    const prefetchUrl = url => {
        if (!url || prefetched.has(url)) return;
        let parsed = null;
        try { parsed = new URL(url, window.location.href); } catch (e) { return; }
        if (parsed.origin !== window.location.origin) return;
        if (parsed.pathname.indexOf('logout') !== -1) return;
        prefetched.add(url);
        const link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = parsed.href;
        link.setAttribute('as', 'document');
        document.head.appendChild(link);
    };
    document.querySelectorAll('.entry-tabs a[href], .entry-switch a[href], .entry-staff a[href], .entry-home[href], .entry-forgot[href], .entry-recipient a[href]').forEach(a => {
        const go = () => prefetchUrl(a.href);
        a.addEventListener('mouseenter', go, { passive: true });
        a.addEventListener('focus', go);
        a.addEventListener('touchstart', go, { passive: true });
        a.addEventListener('click', () => {
            document.documentElement.classList.add('is-navigating');
            a.classList.add('is-pending');
        }, { passive: true });
    });
    // فوکوس آغازین فقط دسکتاپ و فقط وقتی خطایی برای اصلاح وجود ندارد.
    try {
        const hasError = document.querySelector('[aria-invalid="true"], [data-server-error]');
        const finePointer = window.matchMedia && window.matchMedia('(pointer: fine)').matches;
        if (!hasError && finePointer && document.activeElement === document.body) {
            const first = document.querySelector('[data-auth-form] input:not([type="hidden"])');
            if (first) {
                window.requestAnimationFrame(() => {
                    try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); }
                });
            }
        }
    } catch (e) { /* فوکوس تزئینی است؛ خطا را نادیده بگیر */ }
    const error = document.querySelector('[aria-invalid="true"], [data-server-error]');
    if (error) error.focus();
})();

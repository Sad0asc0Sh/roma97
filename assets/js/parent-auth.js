/* ROMA · Parent auth v2: progressive enhancement only.
   Without JS the forms still post normally; every server rule stays authoritative. */
(() => {
    'use strict';

    const root = document.documentElement;
    const FA = '۰۱۲۳۴۵۶۷۸۹';
    const AR = '٠١٢٣٤٥٦٧٨٩';

    /* ── Helpers ───────────────────────────────────────── */
    const digits = value => String(value || '')
        .replace(/[۰-۹]/g, d => String(FA.indexOf(d)))
        .replace(/[٠-٩]/g, d => String(AR.indexOf(d)));
    const toFa = value => String(value).replace(/[0-9]/g, d => FA[Number(d)]);
    const byteLength = value => new TextEncoder().encode(value).length;
    const NAME_RE = /^[\p{L}\p{Z}\s'\-\u200C]+$/u;

    const normalizePhone = value => {
        let phone = digits(value).replace(/[^0-9]/g, '');
        if (phone.startsWith('0098')) phone = '0' + phone.slice(4);
        else if (phone.startsWith('98') && phone.length === 12) phone = '0' + phone.slice(2);
        else if (phone.startsWith('9') && phone.length === 10) phone = '0' + phone;
        return phone;
    };

    /** 09123456789 → 0912 345 6789 */
    const prettyPhone = raw => {
        const p = digits(raw).replace(/[^0-9]/g, '').slice(0, 11);
        if (p.length <= 4) return p;
        if (p.length <= 7) return p.slice(0, 4) + ' ' + p.slice(4);
        return p.slice(0, 4) + ' ' + p.slice(4, 7) + ' ' + p.slice(7, 11);
    };
    const digitsBeforeCaret = (value, caret) => (digits(value.slice(0, Math.max(0, caret))).match(/[0-9]/g) || []).length;
    const caretAfterDigits = (formatted, count) => {
        if (count <= 0) return 0;
        let seen = 0;
        for (let i = 0; i < formatted.length; i++) {
            if (formatted[i] >= '0' && formatted[i] <= '9' && ++seen >= count) return i + 1;
        }
        return formatted.length;
    };
    /** Reformat without moving typed digits: caret is restored by digit count, not string index. */
    const applyPhoneFormat = input => {
        const prev = input.value;
        const caret = input.selectionStart == null ? prev.length : input.selectionStart;
        const count = digitsBeforeCaret(prev, caret);
        const next = prettyPhone(prev);
        if (next === prev) return;
        input.value = next;
        try { const pos = caretAfterDigits(next, count); input.setSelectionRange(pos, pos); } catch (e) { /* ignore */ }
    };

    const passwordRules = value => ({
        length: value.length >= 8 && byteLength(value) >= 8,
        letter: /[A-Za-z]/.test(value),
        digit: /[0-9]/.test(value),
    });
    const strongPassword = value => {
        const bytes = byteLength(value);
        return bytes >= 8 && bytes <= 72 && /[A-Za-z]/.test(value) && /[0-9]/.test(value) && !value.includes('\0');
    };

    const controlOf = input => input.closest('.auth-control, .auth-otp');
    const setState = (input, state) => {
        const control = controlOf(input);
        if (!control) return;
        control.classList.toggle('is-valid', state === 'valid');
        control.classList.toggle('is-invalid', state === 'invalid');
    };

    /** Returns the error message for a field, or '' when it passes. Mirrors server rules. */
    const messageFor = input => {
        const value = input.value;
        if (input.required && !value.trim()) {
            return input.matches('[data-otp]') ? 'کد ۵ رقمی پیامک‌شده را وارد کنید.' : 'لطفاً این بخش را تکمیل کنید.';
        }
        if (input.matches('[data-phone]') && !/^09[0-9]{9}$/.test(normalizePhone(value))) {
            return 'شماره موبایل معتبر وارد کنید؛ مانند 09123456789.';
        }
        if (input.matches('[data-otp]') && !/^[0-9]{5}$/.test(digits(value.trim()))) return 'کد تأیید باید دقیقاً ۵ رقم باشد.';
        if (input.matches('[data-new-password]')) {
            if (byteLength(value) > 72) return 'رمز عبور حداکثر ۷۲ بایت می‌تواند باشد؛ کوتاه‌ترش کنید.';
            if (!strongPassword(value)) return 'رمز باید حداقل ۸ کاراکتر و شامل حرف انگلیسی و عدد باشد.';
        }
        if (input.matches('[data-confirm-password]')) {
            const pass = input.form && input.form.querySelector('[data-new-password]');
            if (!pass || value !== pass.value) return 'تکرار رمز با رمز جدید یکسان نیست.';
        }
        if (input.matches('[data-name]') && !NAME_RE.test(value.trim())) return 'نام را فقط با حروف وارد کنید.';
        return '';
    };

    const showError = (input, message) => {
        input.setCustomValidity(message);
        if (message) input.setAttribute('aria-invalid', 'true');
        else input.removeAttribute('aria-invalid');
        const target = document.getElementById(input.id + '-error');
        if (target) target.textContent = message;
        if (message) setState(input, 'invalid');
        else setState(input, input.value ? 'valid' : null);
    };

    const validate = input => {
        const message = messageFor(input);
        showError(input, message);
        return !message;
    };

    const buzz = () => { try { if (navigator.vibrate) navigator.vibrate(24); } catch (e) { /* ignore */ } };

    /* ── Password: live rules + strength ───────────────── */
    const strengthOf = value => {
        if (!value) return 0;
        const bytes = byteLength(value);
        let score = 0;
        if (bytes >= 8) score++;
        if (bytes >= 12) score++;
        if (/[A-Za-z]/.test(value) && /[0-9]/.test(value)) score++;
        if (/[^A-Za-z0-9]/.test(value) || (bytes >= 16 && /[A-Za-z]/.test(value) && /[0-9]/.test(value))) score++;
        if (value.length < 8) score = Math.min(score, 1);
        return Math.max(0, Math.min(4, score));
    };
    const strengthLabel = ['خیلی ضعیف', 'ضعیف', 'قابل قبول', 'خوب', 'عالی'];

    document.querySelectorAll('[data-rules-for]').forEach(list => {
        const input = document.getElementById(list.dataset.rulesFor);
        if (!input) return;
        const meter = document.querySelector('[data-meter-for="' + list.dataset.rulesFor + '"]');
        const meterText = meter && meter.querySelector('[data-meter-text]');
        const sync = () => {
            const met = passwordRules(input.value);
            list.querySelectorAll('[data-rule]').forEach(li => li.classList.toggle('is-met', !!met[li.dataset.rule]));
            if (!meter) return;
            if (!input.value) { meter.classList.remove('is-visible'); meter.dataset.level = '0'; return; }
            const level = strengthOf(input.value);
            meter.classList.add('is-visible');
            meter.dataset.level = String(level);
            if (meterText) meterText.textContent = 'قدرت: ' + strengthLabel[level];
        };
        input.addEventListener('input', sync);
        sync();
    });

    /* ── Caps lock ─────────────────────────────────────── */
    document.querySelectorAll('[data-password]').forEach(input => {
        const caps = input.closest('.auth-field')?.querySelector('[data-caps]');
        if (!caps) return;
        const update = e => caps.classList.toggle('is-on', !!(e && typeof e.getModifierState === 'function' && e.getModifierState('CapsLock')));
        input.addEventListener('keyup', update);
        input.addEventListener('keydown', update);
        input.addEventListener('blur', () => caps.classList.remove('is-on'));
    });

    /* ── Reveal password ───────────────────────────────── */
    document.querySelectorAll('[data-reveal]').forEach(button => {
        button.hidden = false;
        const label = button.getAttribute('aria-label') || 'نمایش رمز';
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.reveal);
            if (!input) return;
            const show = input.type === 'password';
            const pos = input.selectionStart;
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(show));
            button.setAttribute('aria-label', show ? label.replace('نمایش', 'پنهان کردن') : label);
            try { input.focus({ preventScroll: true }); if (pos != null) input.setSelectionRange(pos, pos); } catch (e) { /* ignore */ }
        });
    });

    /* ── Phone: live format, cursor-safe ───────────────── */
    document.querySelectorAll('[data-phone]').forEach(input => {
        let composing = false;
        input.setAttribute('maxlength', '13');
        input.setAttribute('inputmode', 'numeric');
        if (input.value) input.value = prettyPhone(normalizePhone(input.value) || input.value);
        input.addEventListener('compositionstart', () => { composing = true; });
        input.addEventListener('compositionend', () => { composing = false; applyPhoneFormat(input); });
        input.addEventListener('input', event => {
            if (composing || (event && event.isComposing)) return;
            applyPhoneFormat(input);
        });
        input.addEventListener('paste', event => {
            const text = (event.clipboardData || window.clipboardData).getData('text') || '';
            event.preventDefault();
            const clean = normalizePhone(text).slice(0, 11);
            if (!clean) return;
            input.value = prettyPhone(clean);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            try { const pos = input.value.length; input.setSelectionRange(pos, pos); } catch (e) { /* ignore */ }
        });
        input.addEventListener('keydown', event => {
            if (event.ctrlKey || event.metaKey || event.altKey) return;
            const k = event.key;
            if (k.length !== 1 || /^[0-9۰-۹٠-٩]$/.test(k)) return;
            event.preventDefault();
        });
        input.addEventListener('blur', () => {
            const p = normalizePhone(input.value);
            if (p) input.value = prettyPhone(p);
        });
    });

    /* ── OTP: painted cells + auto-submit + WebOTP ─────── */
    document.querySelectorAll('[data-otp]').forEach(input => {
        const group = input.closest('[data-otp-group]');
        const cells = group ? [...group.querySelectorAll('.auth-otp-cells span')] : [];
        const form = input.form;
        let submitted = false;

        const paint = () => {
            const value = input.value;
            const focused = document.activeElement === input;
            const active = Math.min(value.length, cells.length - 1);
            cells.forEach((cell, i) => {
                cell.textContent = value[i] ? toFa(value[i]) : '';
                cell.classList.toggle('is-filled', !!value[i]);
                cell.classList.toggle('is-active', focused && i === active);
            });
            if (group) group.classList.toggle('is-focused', focused);
        };
        const complete = () => {
            if (input.value.length !== 5 || !form) return;
            if (form.hasAttribute('data-auto-submit')) {
                if (submitted || form.getAttribute('aria-busy') === 'true') return;
                window.setTimeout(() => {
                    if (input.value.length === 5 && form.getAttribute('aria-busy') !== 'true' && !submitted) {
                        submitted = true;
                        if (form.requestSubmit) form.requestSubmit(); else form.submit();
                    }
                }, 260);
            } else {
                const next = form.querySelector('input[data-new-password]');
                if (next) window.setTimeout(() => next.focus(), 160);
            }
        };
        const setCode = raw => {
            const clean = digits(raw).replace(/\D/g, '').slice(0, 5);
            input.value = clean;
            if (clean.length === 5) { showError(input, ''); setState(input, 'valid'); }
            else setState(input, null);
            paint();
            complete();
        };
        const toEnd = () => { try { const n = input.value.length; input.setSelectionRange(n, n); } catch (e) { /* ignore */ } };

        input.addEventListener('input', () => { submitted = false; setCode(input.value); });
        input.addEventListener('focus', () => { toEnd(); paint(); });
        input.addEventListener('click', toEnd);
        input.addEventListener('blur', paint);
        input.addEventListener('keyup', paint);
        input.addEventListener('paste', event => {
            const text = digits((event.clipboardData || window.clipboardData).getData('text') || '');
            const match = text.match(/\d{5}/);
            event.preventDefault();
            setCode(match ? match[0] : text);
        });
        paint();

        // Android Chrome can read the code straight from the SMS when the message carries the domain line.
        if ('OTPCredential' in window && form && navigator.credentials) {
            const ac = new AbortController();
            form.addEventListener('submit', () => ac.abort(), { once: true });
            navigator.credentials.get({ otp: { transport: ['sms'] }, signal: ac.signal })
                .then(otp => { if (otp && otp.code && !input.value) setCode(otp.code); })
                .catch(() => { /* not supported or dismissed */ });
        }
    });

    /* ── Offline awareness ─────────────────────────────── */
    const offlineNotice = document.querySelector('[data-offline]');
    const syncOnline = () => { if (offlineNotice) offlineNotice.hidden = navigator.onLine !== false; };
    window.addEventListener('online', syncOnline);
    window.addEventListener('offline', syncOnline);
    syncOnline();

    /* ── Forms ─────────────────────────────────────────── */
    const shake = form => {
        form.classList.remove('auth-shake');
        void form.offsetWidth;
        form.classList.add('auth-shake');
        window.setTimeout(() => form.classList.remove('auth-shake'), 450);
        buzz();
    };
    const clearNav = () => {
        if (window.__romaClearNav) { try { window.__romaClearNav(); } catch (e) { /* ignore */ } }
        else root.classList.remove('is-navigating');
    };

    document.querySelectorAll('[data-auth-form]').forEach(form => {
        form.noValidate = true;
        const inputs = [...form.querySelectorAll('input:not([type="hidden"])')];

        inputs.forEach(input => {
            input.addEventListener('input', () => {
                input.dataset.dirty = '1';
                if (input.getAttribute('aria-invalid') === 'true') showError(input, '');
                if (input.value && !input.matches('[data-otp]')) setState(input, messageFor(input) ? null : 'valid');
                else if (!input.value) setState(input, null);
                if (input.matches('[data-new-password]')) {
                    const confirm = form.querySelector('[data-confirm-password]');
                    if (confirm && confirm.value) {
                        if (confirm.value === input.value) showError(confirm, '');
                        else if (confirm.dataset.touched) validate(confirm);
                    }
                }
            });
            input.addEventListener('blur', () => {
                if (input.matches('[data-otp]')) return;
                if (input.value || input.dataset.dirty) { input.dataset.touched = '1'; validate(input); }
            });
        });

        form.addEventListener('submit', event => {
            if (form.getAttribute('aria-busy') === 'true') { event.preventDefault(); return; }
            if (navigator.onLine === false) {
                event.preventDefault();
                syncOnline();
                clearNav();
                shake(form);
                return;
            }
            const invalid = inputs.filter(input => !validate(input));
            const rules = form.querySelector('[data-rules-for]');
            if (rules) rules.classList.toggle('is-checked', invalid.some(i => i.matches('[data-new-password]')));
            if (invalid.length) {
                event.preventDefault();
                clearNav();
                shake(form);
                try { invalid[0].focus({ preventScroll: true }); } catch (e) { invalid[0].focus(); }
                try { invalid[0].scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) { /* ignore */ }
                return;
            }
            inputs.filter(input => input.matches('[data-phone]')).forEach(input => { input.value = normalizePhone(input.value); });
            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"]').forEach(button => {
                button.disabled = true;
                const label = button.querySelector('[data-submit-label]');
                const loading = button.getAttribute('data-loading-text');
                if (label && loading && !button.dataset.origLabel) {
                    button.dataset.origLabel = label.textContent;
                    label.textContent = loading;
                }
            });
            root.classList.add('is-navigating');
        });
    });

    /* ── Tabs: slide the pill before the next page paints ─ */
    document.querySelectorAll('.auth-tabs a[href]').forEach(link => {
        link.addEventListener('click', event => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.hasAttribute('aria-current')) return;
            const tabs = link.closest('.auth-tabs');
            if (tabs) tabs.dataset.active = /register/.test(link.getAttribute('href')) ? 'register' : 'login';
        });
    });

    /* ── Resend countdown ──────────────────────────────── */
    const formatClock = s => toFa(Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'));
    const refreshResend = (button, keep) => {
        if (!button || !button.form) return;
        const form = button.form;
        const timer = form.querySelector('[data-countdown]');
        const track = form.querySelector('.auth-resend-track');
        const bar = form.querySelector('[data-resend-bar]');
        if (!button._until || !keep) {
            const wait = Math.max(0, Number(button.dataset.wait || 0));
            button._until = Date.now() + wait * 1000;
            button._total = wait;
        }
        const tick = () => {
            const remaining = Math.max(0, Math.ceil((button._until - Date.now()) / 1000));
            button.disabled = remaining > 0 || form.getAttribute('aria-busy') === 'true';
            if (timer) timer.textContent = remaining ? formatClock(remaining) : '';
            if (track) track.hidden = !remaining;
            if (bar && button._total) bar.style.transform = 'scaleX(' + (remaining / button._total) + ')';
            return remaining;
        };
        if (button._timer) window.clearInterval(button._timer);
        if (tick()) button._timer = window.setInterval(() => { if (!tick()) window.clearInterval(button._timer); }, 1000);
    };
    document.querySelectorAll('[data-resend]').forEach(b => refreshResend(b, false));

    /* ── Back/forward cache: restore idle state ────────── */
    window.addEventListener('pageshow', () => {
        clearNav();
        document.querySelectorAll('a.is-pending').forEach(a => a.classList.remove('is-pending'));
        document.querySelectorAll('.auth-tabs').forEach(tabs => {
            const current = tabs.querySelector('a[aria-current]');
            if (current) tabs.dataset.active = /register/.test(current.getAttribute('href')) ? 'register' : 'login';
        });
        document.querySelectorAll('[data-auth-form]').forEach(form => {
            form.removeAttribute('aria-busy');
            form.querySelectorAll('button[type="submit"]').forEach(button => {
                if (!button.hasAttribute('data-resend')) button.disabled = false;
                const label = button.querySelector('[data-submit-label]');
                if (label && button.dataset.origLabel) { label.textContent = button.dataset.origLabel; delete button.dataset.origLabel; }
            });
        });
        document.querySelectorAll('[data-resend]').forEach(b => refreshResend(b, true));
    });

    /* ── Prefetch on intent ────────────────────────────── */
    const prefetched = new Set();
    const prefetch = href => {
        if (!href || prefetched.has(href)) return;
        let parsed;
        try { parsed = new URL(href, window.location.href); } catch (e) { return; }
        if (parsed.origin !== window.location.origin || parsed.pathname.includes('logout')) return;
        prefetched.add(href);
        const link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = parsed.href;
        link.setAttribute('as', 'document');
        document.head.appendChild(link);
    };
    document.querySelectorAll('.auth-tabs a[href], .auth-switch a[href], .auth-staff a[href], .auth-back[href], .auth-label-link[href], .auth-recipient a[href]').forEach(a => {
        const go = () => prefetch(a.href);
        a.addEventListener('mouseenter', go, { passive: true });
        a.addEventListener('focus', go);
        a.addEventListener('touchstart', go, { passive: true });
    });

    /* ── Focus management ──────────────────────────────── */
    const firstError = document.querySelector('[aria-invalid="true"]') || document.querySelector('[data-server-error]');
    if (firstError) {
        try { firstError.focus({ preventScroll: false }); } catch (e) { firstError.focus(); }
    } else {
        const otp = document.querySelector('[data-otp]');
        const finePointer = window.matchMedia && window.matchMedia('(pointer: fine)').matches;
        // On the code screen, open the keyboard right away (that is the only thing to do there).
        const target = otp || (finePointer ? document.querySelector('[data-auth-form] input:not([type="hidden"])') : null);
        if (target && document.activeElement === document.body) {
            window.requestAnimationFrame(() => { try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); } });
        }
    }
})();

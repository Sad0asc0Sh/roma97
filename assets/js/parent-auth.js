(() => {
    'use strict';

    /* ── Helpers ───────────────────────────── */
    const digits = value => value.replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
        .replace(/[٠-٩]/g, d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));

    const normalizePhone = value => {
        let phone = digits(value).replace(/[^0-9]/g, '');
        if (phone.startsWith('0098')) phone = '0' + phone.slice(4);
        else if (phone.startsWith('98') && phone.length === 12) phone = '0' + phone.slice(2);
        else if (phone.startsWith('9') && phone.length === 10) phone = '0' + phone;
        return phone;
    };

    /** 09123456789 → 0912 345 6789 (display while typing; digits only, max 11) */
    const prettyPhone = raw => {
        const p = digits(String(raw || '')).replace(/[^0-9]/g, '').slice(0, 11);
        if (p.length <= 4) return p;
        if (p.length <= 7) return p.slice(0, 4) + ' ' + p.slice(4);
        return p.slice(0, 4) + ' ' + p.slice(4, 7) + ' ' + p.slice(7, 11);
    };

    /** How many digits sit at/before the caret? (Persian/Arabic digits count too) */
    const digitsBeforeCaret = (value, caret) => {
        const head = value.slice(0, Math.max(0, caret));
        // Count after converting ۰-۹ / ٠-٩ → 0-9 so fa-keyboard input keeps the caret
        return (digits(head).match(/[0-9]/g) || []).length;
    };

    /** Map “N digits seen” back to a caret index in the formatted string */
    const caretAfterDigits = (formatted, digitCount) => {
        if (digitCount <= 0) return 0;
        let seen = 0;
        for (let i = 0; i < formatted.length; i++) {
            if (formatted[i] >= '0' && formatted[i] <= '9') {
                seen++;
                if (seen >= digitCount) return i + 1;
            }
        }
        return formatted.length;
    };

    /**
     * Reformat phone input WITHOUT moving typed digits around.
     * Cursor is restored by digit count, not raw string index —
     * inserting spaces used to park the caret mid-number and stack later digits.
     */
    const applyPhoneFormat = (input) => {
        const prev = input.value;
        const caret = input.selectionStart == null ? prev.length : input.selectionStart;
        const digitCount = digitsBeforeCaret(prev, caret);
        const next = prettyPhone(prev);
        if (next === prev) return;
        input.value = next;
        try {
            const pos = caretAfterDigits(next, digitCount);
            input.setSelectionRange(pos, pos);
        } catch (e) { /* ignore */ }
    };

    const wrapOf = input => input.closest('.entry-input-wrap');
    const setWrapState = (input, state) => {
        const wrap = wrapOf(input);
        if (!wrap) return;
        wrap.classList.toggle('is-valid', state === 'valid');
        wrap.classList.toggle('is-invalid', state === 'invalid');
    };

    const setError = (input, message) => {
        input.setCustomValidity(message);
        if (message) input.setAttribute('aria-invalid', 'true');
        else input.removeAttribute('aria-invalid');
        const target = document.getElementById(input.id + '-error');
        if (target) target.textContent = message;
        if (message) setWrapState(input, 'invalid');
        else if (input.value) setWrapState(input, isValidValue(input) ? 'valid' : null);
        else setWrapState(input, null);
    };

    /* Soft check: does this value pass? (no DOM error write) */
    const isValidValue = input => {
        if (input.required && !input.value) return false;
        if (input.matches('[data-phone]') && !/^09[0-9]{9}$/.test(normalizePhone(input.value))) return false;
        if (input.matches('[data-otp]') && !/^[0-9]{5}$/.test(digits(input.value.trim()))) return false;
        if (input.matches('[data-new-password]')) {
            const bytes = new TextEncoder().encode(input.value).length;
            if (bytes < 8 || bytes > 72 || !/[A-Za-z]/.test(input.value) || !/[0-9]/.test(input.value) || input.value.includes('\0')) return false;
        }
        if (input.matches('[data-confirm-password]')) {
            const pass = input.form && input.form.querySelector('[data-new-password]');
            if (!pass || input.value !== pass.value) return false;
        }
        if (input.matches('[data-name]') && !/^[\p{L}\p{Z}\s'\-‌]+$/u.test(input.value.trim())) return false;
        return true;
    };

    const validate = (input, silent) => {
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
        } else if (input.matches('[data-name]')
            && !/^[\p{L}\p{Z}\s'\-‌]+$/u.test(input.value.trim())) {
            message = 'نام را با حروف وارد کنید.';
        }
        if (!silent) setError(input, message);
        else setWrapState(input, message ? 'invalid' : (input.value ? 'valid' : null));
        return !message;
    };

    /* ── Password strength ─────────────────── */
    const strengthOf = (password) => {
        if (!password) return 0;
        let score = 0;
        const bytes = new TextEncoder().encode(password).length;
        if (bytes >= 8) score++;
        if (bytes >= 12) score++;
        if (/[A-Za-z]/.test(password) && /[0-9]/.test(password)) score++;
        if (/[^A-Za-z0-9]/.test(password) || (bytes >= 16 && /[A-Za-z]/.test(password) && /[0-9]/.test(password))) score++;
        if (password.length < 8) score = Math.min(score, 1);
        return Math.max(0, Math.min(4, score));
    };

    const strengthLabel = ['', 'ضعیف', 'قابل قبول', 'خوب', 'عالی'];

    document.querySelectorAll('[data-strength-for]').forEach(box => {
        const input = document.getElementById(box.dataset.strengthFor);
        if (!input) return;
        const text = box.querySelector('[data-strength-text]');
        const sync = () => {
            if (!input.value) {
                box.classList.remove('is-visible');
                box.removeAttribute('data-level');
                return;
            }
            const level = strengthOf(input.value);
            box.classList.add('is-visible');
            box.setAttribute('data-level', String(level));
            if (text) text.textContent = level ? ('قدرت رمز: ' + strengthLabel[level]) : 'قدرت رمز: خیلی ضعیف';
        };
        input.addEventListener('input', sync);
        input.addEventListener('focus', sync);
    });

    /* ── Caps lock ─────────────────────────── */
    document.querySelectorAll('[data-password]').forEach(input => {
        const caps = input.closest('.entry-field')?.querySelector('[data-caps]');
        if (!caps) return;
        const update = (e) => {
            const on = !!(e && typeof e.getModifierState === 'function' && e.getModifierState('CapsLock'));
            caps.classList.toggle('is-on', on);
        };
        input.addEventListener('keyup', update);
        input.addEventListener('keydown', update);
        input.addEventListener('blur', () => caps.classList.remove('is-on'));
    });

    /* ── Reveal password ───────────────────── */
    document.querySelectorAll('[data-reveal]').forEach(button => {
        button.hidden = false;
        const originalLabel = button.getAttribute('aria-label') || 'نمایش رمز';
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.reveal);
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(show));
            button.setAttribute('aria-label', show ? originalLabel.replace('نمایش', 'پنهان کردن') : originalLabel);
            // re-sync strength visibility after type flip
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    /* ── Phone live format (cursor-safe) ───── */
    document.querySelectorAll('[data-phone]').forEach(input => {
        let composing = false;
        input.setAttribute('maxlength', '13'); // "0912 345 6789"
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('autocomplete', input.getAttribute('autocomplete') || 'tel');

        input.addEventListener('compositionstart', () => { composing = true; });
        input.addEventListener('compositionend', () => {
            composing = false;
            applyPhoneFormat(input);
        });

        input.addEventListener('input', (event) => {
            if (composing || (event && event.isComposing)) return;
            applyPhoneFormat(input);
        });

        // Paste: keep only digits, then format
        input.addEventListener('paste', (event) => {
            const text = (event.clipboardData || window.clipboardData).getData('text') || '';
            const clean = digits(text).replace(/[^0-9]/g, '').slice(0, 11);
            if (!clean) {
                event.preventDefault();
                return;
            }
            event.preventDefault();
            input.value = clean; // digits first, then format with caret at end
            applyPhoneFormat(input);
            try {
                const pos = input.value.length;
                input.setSelectionRange(pos, pos);
            } catch (e) { /* ignore */ }
        });

        // Block non-digit keystrokes early (still allow navigation keys)
        input.addEventListener('keydown', (event) => {
            if (event.ctrlKey || event.metaKey || event.altKey) return;
            const k = event.key;
            if (k === 'Backspace' || k === 'Delete' || k === 'Tab' || k === 'Enter'
                || k === 'ArrowLeft' || k === 'ArrowRight' || k === 'ArrowUp' || k === 'ArrowDown'
                || k === 'Home' || k === 'End' || k === 'Escape' || k === 'Process') return;
            // allow digit / Persian digit / Arabic digit
            if (/^[0-9۰-۹٠-٩]$/.test(k)) return;
            if (k.length === 1) event.preventDefault();
        });

        input.addEventListener('blur', () => {
            const p = normalizePhone(input.value);
            input.value = p ? prettyPhone(p) : prettyPhone(input.value);
        });
    });

    /* ── OTP: digits only + optional auto-submit ─ */
    const maybeAutoSubmitOtp = (input) => {
        if (input.value.length !== 5) return;
        const form = input.form;
        if (!form || !form.hasAttribute('data-auto-submit')) return;
        if (form.getAttribute('aria-busy') === 'true') return;
        window.setTimeout(() => {
            if (input.value.length === 5 && form.getAttribute('aria-busy') !== 'true') {
                if (form.requestSubmit) form.requestSubmit();
                else form.submit();
            }
        }, 220);
    };

    document.querySelectorAll('[data-otp]').forEach(input => {
        input.addEventListener('input', () => {
            const cleaned = digits(input.value).replace(/\D/g, '').slice(0, 5);
            input.value = cleaned;
            setWrapState(input, cleaned.length === 5 ? 'valid' : null);
            maybeAutoSubmitOtp(input);
        });
        input.addEventListener('paste', event => {
            const code = digits(event.clipboardData.getData('text')).replace(/\s/g, '');
            if (/^[0-9]{5}$/.test(code)) {
                event.preventDefault();
                input.value = code;
                setError(input, '');
                setWrapState(input, 'valid');
                maybeAutoSubmitOtp(input);
            } else if (/^[0-9]+$/.test(code)) {
                event.preventDefault();
                input.value = code.slice(0, 5);
                setWrapState(input, input.value.length === 5 ? 'valid' : null);
            }
        });
    });

    /* ── Forms ─────────────────────────────── */
    document.querySelectorAll('[data-auth-form]').forEach(form => {
        form.noValidate = true;
        const inputs = [...form.querySelectorAll('input:not([type="hidden"])')];

        inputs.forEach(input => {
            input.addEventListener('blur', () => {
                if (input.value || input.required) validate(input);
                if (input.matches('[data-phone]') && input.value) {
                    const p = normalizePhone(input.value);
                    if (p) input.value = prettyPhone(p);
                }
            });
            input.addEventListener('input', () => {
                // clear error while typing; soft-valid check
                setError(input, '');
                form.classList.remove('entry-shake');
                if (input.value) {
                    setWrapState(input, isValidValue(input) ? 'valid' : null);
                }
                if (input.matches('[data-new-password]')) {
                    const confirm = form.querySelector('[data-confirm-password]');
                    if (confirm?.value) validate(confirm, false);
                }
                if (input.matches('[data-confirm-password]') && input.value) {
                    // live match feedback
                    const pass = form.querySelector('[data-new-password]');
                    if (pass && pass.value && input.value === pass.value) {
                        setError(input, '');
                        setWrapState(input, 'valid');
                    }
                }
            });
        });

        form.addEventListener('submit', event => {
            if (form.getAttribute('aria-busy') === 'true') { event.preventDefault(); return; }
            const invalid = inputs.filter(input => !validate(input));
            if (invalid.length) {
                event.preventDefault();
                if (window.__romaClearNav) { try { window.__romaClearNav(); } catch (e) {} }
                else document.documentElement.classList.remove('is-navigating');
                form.classList.remove('entry-shake');
                void form.offsetWidth;
                form.classList.add('entry-shake');
                setTimeout(() => form.classList.remove('entry-shake'), 500);
                try { invalid[0].focus({ preventScroll: false }); } catch (e) { invalid[0].focus(); }
                if (invalid[0].scrollIntoView) {
                    try { invalid[0].scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
                }
                return;
            }
            inputs.filter(input => input.matches('[data-phone]')).forEach(input => {
                input.value = normalizePhone(input.value);
            });
            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"]').forEach(button => {
                button.disabled = true;
                button.setAttribute('aria-disabled', 'true');
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

    /* ── Resend countdown ──────────────────── */
    const refreshResend = (button, keepUntil) => {
        if (!button || !button.form) return;
        const timer = button.form.querySelector('[data-countdown]');
        if (!button._romaUntil || !keepUntil) {
            button._romaUntil = Date.now() + Number(button.dataset.wait || 0) * 1000;
        }
        const until = button._romaUntil;
        const tick = () => {
            const remaining = Math.max(0, Math.ceil((until - Date.now()) / 1000));
            button.disabled = remaining > 0 || button.form.getAttribute('aria-busy') === 'true';
            if (timer) timer.textContent = remaining ? `(${remaining.toLocaleString('fa-IR')} ثانیه)` : '';
            return remaining;
        };
        button._romaTick = tick;
        if (tick()) {
            if (button._romaTimer) window.clearInterval(button._romaTimer);
            button._romaTimer = window.setInterval(() => { if (!tick()) window.clearInterval(button._romaTimer); }, 1000);
        }
    };
    document.querySelectorAll('[data-resend]').forEach(refreshResend);

    window.addEventListener('pageshow', () => {
        if (window.__romaClearNav) { try { window.__romaClearNav(); } catch (e) {} }
        else {
            document.documentElement.classList.remove('is-navigating');
            document.querySelectorAll('a.is-pending').forEach(a => a.classList.remove('is-pending'));
        }
        document.querySelectorAll('[data-auth-form]').forEach(form => {
            form.removeAttribute('aria-busy');
            form.querySelectorAll('button[type="submit"]').forEach(button => {
                if (!button.hasAttribute('data-resend')) button.disabled = false;
                button.removeAttribute('aria-disabled');
                const label = button.querySelector('[data-submit-label]');
                if (label && button.dataset.origLabel) {
                    label.textContent = button.dataset.origLabel;
                    delete button.dataset.origLabel;
                }
            });
        });
        document.querySelectorAll('[data-resend]').forEach(b => refreshResend(b, true));
    });

    /* ── Prefetch on intent ────────────────── */
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
    });

    /* ── Focus management ──────────────────── */
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
    } catch (e) { /* decorative focus */ }

    const error = document.querySelector('[aria-invalid="true"], [data-server-error]');
    if (error) {
        try { error.focus({ preventScroll: false }); } catch (e) { error.focus(); }
    }
})();

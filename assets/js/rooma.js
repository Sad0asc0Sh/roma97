/* ============================================================================
 * ROOMA — public site interaction engine
 * ----------------------------------------------------------------------------
 * Vanilla JS, no dependencies, IIFE-scoped, defer-loaded.
 *
 * Modules
 *  01  utilities & shared state
 *  02  theme toggle
 *  03  scroll progress + back-to-top
 *  04  adaptive header (shrink / auto-hide)
 *  05  desktop nav sliding pill
 *  06  mobile drawer (focus trap, ESC, swipe-to-close)
 *  07  scroll reveal (IntersectionObserver + stagger)
 *  08  animated counters
 *  09  capacity meters
 *  10  hero slider (autoplay, swipe, keyboard, a11y, visibility-aware)
 *  11  gallery filter + lightbox
 *  12  tabs
 *  13  card cursor glow
 *  14  newsletter form
 *  15  parallax-lite for decorative layers
 *  16  soft page transitions
 *  17  background pause when tab hidden
 *  18  share buttons
 * ========================================================================= */
(function () {
    'use strict';

    /* ─── 01. Utilities & shared state ─────────────────────────────────── */
    var doc = document;
    var root = doc.documentElement;
    var win = window;

    var mqReduce = win.matchMedia ? win.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var reduceMotion = mqReduce ? mqReduce.matches : false;
    if (mqReduce && mqReduce.addEventListener) {
        mqReduce.addEventListener('change', function (e) { reduceMotion = e.matches; });
    }

    var hasIO = 'IntersectionObserver' in win;
    var isTouch = win.matchMedia ? win.matchMedia('(hover: none)').matches : false;

    function $(sel, ctx) { return (ctx || doc).querySelector(sel); }
    function $$(sel, ctx) { return Array.prototype.slice.call((ctx || doc).querySelectorAll(sel)); }

    /** Batches scroll work into a single rAF pass shared by all modules. */
    var scrollSubs = [];
    var scrollQueued = false;
    function onScrollFrame() {
        scrollQueued = false;
        var y = win.scrollY || win.pageYOffset || 0;
        for (var i = 0; i < scrollSubs.length; i++) {
            try { scrollSubs[i](y); } catch (err) { /* keep other subscribers alive */ }
        }
    }
    function subscribeScroll(fn) {
        scrollSubs.push(fn);
        if (scrollSubs.length === 1) {
            win.addEventListener('scroll', function () {
                if (!scrollQueued) { scrollQueued = true; win.requestAnimationFrame(onScrollFrame); }
            }, { passive: true });
        }
        fn(win.scrollY || 0);
    }

    function clamp(v, lo, hi) { return v < lo ? lo : (v > hi ? hi : v); }

    /** Latin digits → Persian digits, for JS-generated numbers. */
    var FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    function toFa(input) {
        return String(input).replace(/[0-9]/g, function (d) { return FA_DIGITS[+d]; });
    }

    function prefersFinePointer() {
        return win.matchMedia && win.matchMedia('(hover: hover) and (pointer: fine)').matches;
    }

    root.classList.add('js-enabled');

    /* ─── 02. Theme toggle ─────────────────────────────────────────────── */
    (function themeToggle() {
        var STORAGE_KEY = 'roma_theme';
        var btns = $$('[data-theme-toggle]');
        if (!btns.length) return;

        function current() { return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'; }

        function apply(theme, persist) {
            root.setAttribute('data-theme', theme);
            if (persist) {
                try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) { /* private mode */ }
            }
            btns.forEach(function (b) {
                b.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
                b.setAttribute('aria-label', theme === 'dark' ? 'تغییر به حالت روز' : 'تغییر به حالت شب');
            });
            // Keep the browser UI colour in sync
            var meta = $('meta[name="theme-color"]:not([media])');
            if (meta) meta.setAttribute('content', theme === 'dark' ? '#0B1712' : '#3D8B63');
        }

        apply(current(), false);

        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var next = current() === 'dark' ? 'light' : 'dark';

                // Use the View Transitions API where available for a smooth cross-fade
                if (!reduceMotion && doc.startViewTransition) {
                    doc.startViewTransition(function () { apply(next, true); });
                } else {
                    apply(next, true);
                }
            });
        });

        // Follow the OS only while the user has not made an explicit choice
        if (win.matchMedia) {
            var mqDark = win.matchMedia('(prefers-color-scheme: dark)');
            var listener = function (e) {
                var stored = null;
                try { stored = localStorage.getItem(STORAGE_KEY); } catch (err) { /* noop */ }
                if (!stored) apply(e.matches ? 'dark' : 'light', false);
            };
            if (mqDark.addEventListener) mqDark.addEventListener('change', listener);
        }
    })();

    /* ─── 03. Scroll progress + back-to-top ────────────────────────────── */
    (function scrollProgress() {
        var bar = $('[data-progress-bar]');
        var toTop = $('[data-to-top]');
        var ring = toTop ? $('[data-to-top-ring]', toTop) : null;
        if (!bar && !toTop) return;

        var circumference = 0;
        if (ring) {
            var r = parseFloat(ring.getAttribute('r')) || 20;
            circumference = 2 * Math.PI * r;
            ring.style.strokeDasharray = circumference + ' ' + circumference;
            ring.style.strokeDashoffset = String(circumference);
        }

        subscribeScroll(function (y) {
            var max = doc.documentElement.scrollHeight - win.innerHeight;
            var pct = max > 8 ? clamp(y / max, 0, 1) : 0;

            if (bar) bar.style.transform = 'scaleX(' + pct.toFixed(4) + ')';

            if (toTop) {
                toTop.classList.toggle('is-visible', y > 520);
                if (ring) ring.style.strokeDashoffset = String(circumference * (1 - pct));
            }
        });

        if (toTop) {
            toTop.addEventListener('click', function () {
                win.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
            });
        }
    })();

    /* ─── 04. Adaptive header autohide ─────────────────────────────────── */
    (function adaptiveHeader() {
        var header = $('[data-header]');
        if (!header) return;

        var lastY = win.scrollY || 0;
        var accumulated = 0;
        var HIDE_AFTER = 90;   // px of continuous downward scroll before hiding
        var SHOW_AFTER = 60;   // px of upward scroll before revealing
        var STUCK_AT = 20;

        subscribeScroll(function (y) {
            var delta = y - lastY;
            lastY = y;

            if (header) header.classList.toggle('is-stuck', y > STUCK_AT);

            // Never hide near the very top of the document
            if (y < 180) {
                accumulated = 0;
                if (header) header.classList.remove('is-hidden');
                return;
            }

            // Don't fight the drawer or a modal
            if (root.classList.contains('no-scroll')) return;

            // Reset the accumulator whenever the direction flips
            if ((delta > 0 && accumulated < 0) || (delta < 0 && accumulated > 0)) accumulated = 0;
            accumulated += delta;

            if (accumulated > HIDE_AFTER) {
                if (header) header.classList.add('is-hidden');
                accumulated = 0;
            } else if (accumulated < -SHOW_AFTER) {
                if (header) header.classList.remove('is-hidden');
                accumulated = 0;
            }
        });

        // Any keyboard focus inside the header must bring it back into view
        if (header) {
            header.addEventListener('focusin', function () { header.classList.remove('is-hidden'); });
        }
    })();

    /* ─── 05. Desktop nav sliding pill ─────────────────────────────────── */
    (function navPill() {
        var nav = $('[data-nav]');
        if (!nav) return;
        var pill = $('[data-nav-pill]', nav);
        if (!pill) return;

        var links = $$('.nav__link', nav);
        var active = nav.querySelector('.nav__link.is-active');

        function moveTo(el, animate) {
            if (!el) { pill.classList.remove('is-ready'); return; }
            if (!animate) pill.style.transition = 'none';
            pill.style.width = el.offsetWidth + 'px';
            // Position within the nav's own coordinate space (RTL-safe)
            pill.style.insetInlineStart = (el.offsetLeft - nav.offsetLeft) + 'px';
            pill.classList.add('is-ready');
            if (!animate) {
                // Force a reflow, then restore transitions
                void pill.offsetWidth;
                pill.style.transition = '';
            }
        }

        // The nav uses logical properties; offsetLeft is physical. Convert.
        function moveToPhysical(el, animate) {
            if (!el) { pill.classList.remove('is-ready'); return; }
            if (!animate) pill.style.transition = 'none';
            var navRect = nav.getBoundingClientRect();
            var rect = el.getBoundingClientRect();
            var isRtl = getComputedStyle(nav).direction === 'rtl';
            var offset = isRtl
                ? (navRect.right - rect.right)
                : (rect.left - navRect.left);
            pill.style.width = rect.width + 'px';
            pill.style.insetInlineStart = Math.max(0, offset) + 'px';
            pill.classList.add('is-ready');
            if (!animate) { void pill.offsetWidth; pill.style.transition = ''; }
        }

        function reset() { moveToPhysical(active, true); }

        if (active) {
            // Wait for webfonts so the measurement is correct
            if (doc.fonts && doc.fonts.ready) {
                doc.fonts.ready.then(function () { moveToPhysical(active, false); });
            } else {
                moveToPhysical(active, false);
            }
        }

        if (prefersFinePointer()) {
            links.forEach(function (link) {
                link.addEventListener('mouseenter', function () { moveToPhysical(link, true); });
                link.addEventListener('focus', function () { moveToPhysical(link, true); });
            });
            nav.addEventListener('mouseleave', reset);
            nav.addEventListener('focusout', function (e) {
                if (!nav.contains(e.relatedTarget)) reset();
            });
        }

        var rt;
        win.addEventListener('resize', function () {
            clearTimeout(rt);
            rt = setTimeout(function () { moveToPhysical(active, false); }, 140);
        }, { passive: true });
    })();

    /* ─── 06. Mobile drawer ────────────────────────────────────────────── */
    (function drawer() {
        var panel = $('[data-drawer]');
        var overlay = $('[data-drawer-overlay]');
        var openBtns = $$('[data-drawer-open]');
        var closeBtns = $$('[data-drawer-close]');
        if (!panel || !openBtns.length) return;

        var lastFocused = null;
        var isOpen = false;

        var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

        function open() {
            if (isOpen) return;
            isOpen = true;
            lastFocused = doc.activeElement;

            panel.classList.add('is-open');
            if (overlay) overlay.classList.add('is-open');
            panel.setAttribute('aria-hidden', 'false');
            root.classList.add('no-scroll');
            doc.body.classList.add('no-scroll');
            openBtns.forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });

            // Move focus into the panel
            var first = panel.querySelector(FOCUSABLE);
            if (first) setTimeout(function () { first.focus(); }, 120);
        }

        function close() {
            if (!isOpen) return;
            isOpen = false;

            panel.classList.remove('is-open');
            if (overlay) overlay.classList.remove('is-open');
            panel.setAttribute('aria-hidden', 'true');
            root.classList.remove('no-scroll');
            doc.body.classList.remove('no-scroll');
            openBtns.forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });

            if (lastFocused && lastFocused.focus) lastFocused.focus();
        }

        openBtns.forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.preventDefault();
                isOpen ? close() : open();
            });
        });
        closeBtns.forEach(function (b) { b.addEventListener('click', close); });
        if (overlay) overlay.addEventListener('click', close);

        // Close after tapping a nav link (same-page anchors especially)
        $$('a', panel).forEach(function (a) {
            a.addEventListener('click', function () { setTimeout(close, 80); });
        });

        doc.addEventListener('keydown', function (e) {
            if (!isOpen) return;

            if (e.key === 'Escape') { e.preventDefault(); close(); return; }

            if (e.key === 'Tab') {
                var items = $$(FOCUSABLE, panel).filter(function (el) {
                    return el.offsetParent !== null;
                });
                if (!items.length) return;
                var first = items[0];
                var last = items[items.length - 1];
                if (e.shiftKey && doc.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && doc.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        });

        // Swipe toward the inline-end edge to dismiss
        var sx = 0, sy = 0, tracking = false;
        panel.addEventListener('touchstart', function (e) {
            if (!isOpen || e.touches.length !== 1) return;
            sx = e.touches[0].clientX; sy = e.touches[0].clientY; tracking = true;
        }, { passive: true });
        panel.addEventListener('touchmove', function (e) {
            if (!tracking) return;
            var dx = e.touches[0].clientX - sx;
            var dy = e.touches[0].clientY - sy;
            if (Math.abs(dy) > Math.abs(dx)) { tracking = false; return; }   // vertical scroll
            var isRtl = getComputedStyle(panel).direction === 'rtl' ||
                        root.getAttribute('dir') === 'rtl';
            // RTL panel sits at the right → swiping right (positive dx) closes it
            var closing = isRtl ? dx > 62 : dx < -62;
            if (closing) { tracking = false; close(); }
        }, { passive: true });
        panel.addEventListener('touchend', function () { tracking = false; }, { passive: true });

        // Leaving the mobile breakpoint must not leave the drawer stuck open
        if (win.matchMedia) {
            var mqDesktop = win.matchMedia('(min-width: 992px)');
            var onChange = function (e) { if (e.matches) close(); };
            if (mqDesktop.addEventListener) mqDesktop.addEventListener('change', onChange);
        }
    })();

    /* ─── 07. Scroll reveal ────────────────────────────────────────────── */
    (function reveal() {
        var targets = $$('[data-reveal]');
        if (!targets.length) return;

        // No IO or reduced motion → show everything immediately, skip the hidden state
        if (!hasIO || reduceMotion) return;

        root.classList.add('js-reveal');

        // Assign stagger delays inside groups
        $$('[data-reveal-group]').forEach(function (group) {
            var step = parseInt(group.getAttribute('data-reveal-step'), 10);
            if (isNaN(step)) step = 90;
            var kids = $$('[data-reveal]', group);
            kids.forEach(function (kid, i) {
                // Only stagger direct-ish descendants that have no explicit delay
                if (!kid.hasAttribute('data-reveal-delay')) {
                    kid.style.setProperty('--reveal-delay', (i * step) + 'ms');
                }
            });
        });

        // Explicit per-element delays
        targets.forEach(function (el) {
            var d = el.getAttribute('data-reveal-delay');
            if (d) el.style.setProperty('--reveal-delay', parseInt(d, 10) + 'ms');
        });

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                // Fire when intersecting, or when already scrolled past (fast jumps)
                if (entry.isIntersecting || entry.boundingClientRect.top < 0) {
                    entry.target.classList.add('is-revealed');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });

        targets.forEach(function (el) { io.observe(el); });

        // Safety net: anything still hidden after load gets shown
        win.addEventListener('load', function () {
            setTimeout(function () {
                $$('[data-reveal]:not(.is-revealed)').forEach(function (el) {
                    var r = el.getBoundingClientRect();
                    if (r.top < win.innerHeight) el.classList.add('is-revealed');
                });
            }, 900);
        });
    })();

    /* ─── 08. Animated counters ────────────────────────────────────────── */
    (function counters() {
        var els = $$('[data-count]');
        if (!els.length) return;

        function run(el) {
            var target = parseFloat(el.getAttribute('data-count'));
            if (isNaN(target)) return;
            var suffix = el.getAttribute('data-suffix') || '';
            var dur = parseInt(el.getAttribute('data-count-duration'), 10) || 1600;

            if (reduceMotion) {
                el.textContent = toFa(target) + suffix;
                return;
            }

            var start = null;
            function frame(ts) {
                if (start === null) start = ts;
                var p = clamp((ts - start) / dur, 0, 1);
                // easeOutExpo
                var eased = p === 1 ? 1 : 1 - Math.pow(2, -10 * p);
                el.textContent = toFa(Math.round(target * eased)) + suffix;
                if (p < 1) win.requestAnimationFrame(frame);
            }
            win.requestAnimationFrame(frame);
        }

        if (!hasIO) { els.forEach(run); return; }

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting) { run(e.target); io.unobserve(e.target); }
            });
        }, { threshold: 0.5 });
        els.forEach(function (el) { io.observe(el); });
    })();

    /* ─── 09. Capacity meters ──────────────────────────────────────────── */
    (function meters() {
        var els = $$('[data-meter]');
        if (!els.length) return;

        function fill(el) {
            var w = el.getAttribute('data-meter') || el.style.getPropertyValue('--w') || '0%';
            el.style.width = w;
        }

        if (!hasIO || reduceMotion) { els.forEach(fill); return; }

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting) {
                    setTimeout(function () { fill(e.target); }, 180);
                    io.unobserve(e.target);
                }
            });
        }, { threshold: 0.4 });
        els.forEach(function (el) { io.observe(el); });
    })();

    /* ─── 10. Hero slider ──────────────────────────────────────────────── */
    (function heroSlider() {
        var slider = $('[data-slider]');
        if (!slider) return;

        var slides = $$('[data-slide]', slider);
        if (slides.length < 1) return;

        var dots = $$('[data-slide-dot]', slider);
        var prevBtn = $('[data-slide-prev]', slider);
        var nextBtn = $('[data-slide-next]', slider);
        var toggle = $('[data-slide-toggle]', slider);
        var live = $('[data-slider-live]', slider);

        var index = 0;
        var timer = null;
        var INTERVAL = parseInt(slider.getAttribute('data-slider-interval'), 10) || 6500;
        var playing = false;
        var single = slides.length < 2;

        function show(next, announce) {
            index = (next + slides.length) % slides.length;

            slides.forEach(function (s, i) {
                var on = i === index;
                s.classList.toggle('is-active', on);
                s.setAttribute('aria-hidden', on ? 'false' : 'true');
                // Keep off-screen slides out of the tab order
                $$('a, button', s).forEach(function (f) {
                    if (on) f.removeAttribute('tabindex');
                    else f.setAttribute('tabindex', '-1');
                });
            });

            dots.forEach(function (d, i) {
                var on = i === index;
                d.classList.toggle('is-active', on);
                d.setAttribute('aria-selected', on ? 'true' : 'false');
                d.setAttribute('tabindex', on ? '0' : '-1');
            });

            if (announce && live) {
                live.textContent = 'تصویر ' + toFa(index + 1) + ' از ' + toFa(slides.length);
            }
        }

        function next() { show(index + 1, true); }
        function prev() { show(index - 1, true); }

        function play() {
            if (single || reduceMotion) return;
            stop();
            playing = true;
            timer = setInterval(next, INTERVAL);
            if (toggle) {
                toggle.setAttribute('aria-pressed', 'true');
                toggle.setAttribute('aria-label', 'توقف پخش خودکار');
            }
        }
        function stop() {
            if (timer) { clearInterval(timer); timer = null; }
            playing = false;
            if (toggle) {
                toggle.setAttribute('aria-pressed', 'false');
                toggle.setAttribute('aria-label', 'شروع پخش خودکار');
            }
        }

        show(0, false);

        if (single) {
            [prevBtn, nextBtn, toggle].forEach(function (b) { if (b) b.hidden = true; });
            var dotsWrap = $('[data-slider-dots]', slider);
            if (dotsWrap) dotsWrap.hidden = true;
            return;
        }

        if (nextBtn) nextBtn.addEventListener('click', function () { next(); play(); });
        if (prevBtn) prevBtn.addEventListener('click', function () { prev(); play(); });
        dots.forEach(function (d, i) {
            d.addEventListener('click', function () { show(i, true); play(); });
        });
        if (toggle) {
            toggle.addEventListener('click', function () { playing ? stop() : play(); });
        }

        // Pause while hovered or focused (desktop courtesy)
        slider.addEventListener('mouseenter', function () { if (playing) { clearInterval(timer); timer = null; } });
        slider.addEventListener('mouseleave', function () { if (playing && !timer) timer = setInterval(next, INTERVAL); });
        slider.addEventListener('focusin', stop);

        // Keyboard: arrows follow RTL reading direction
        slider.setAttribute('tabindex', '-1');
        slider.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') { e.preventDefault(); next(); stop(); }
            else if (e.key === 'ArrowRight') { e.preventDefault(); prev(); stop(); }
        });

        // Touch swipe
        var sx = 0, sy = 0, swiping = false;
        slider.addEventListener('touchstart', function (e) {
            if (e.touches.length !== 1) return;
            sx = e.touches[0].clientX; sy = e.touches[0].clientY; swiping = true;
        }, { passive: true });
        slider.addEventListener('touchend', function (e) {
            if (!swiping) return;
            swiping = false;
            var t = e.changedTouches[0];
            var dx = t.clientX - sx;
            var dy = t.clientY - sy;
            if (Math.abs(dx) < 45 || Math.abs(dy) > Math.abs(dx)) return;
            // RTL: swiping left advances
            if (dx < 0) next(); else prev();
            stop();
        }, { passive: true });

        // Only autoplay while the hero is actually on screen
        if (hasIO) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (en.isIntersecting) { if (!playing) play(); }
                    else if (playing) { clearInterval(timer); timer = null; }
                });
            }, { threshold: 0.25 });
            io.observe(slider);
        } else {
            play();
        }

        doc.addEventListener('visibilitychange', function () {
            if (doc.hidden) { if (timer) { clearInterval(timer); timer = null; } }
            else if (playing && !timer) timer = setInterval(next, INTERVAL);
        });
    })();

    /* ─── 11. Gallery filter + lightbox ────────────────────────────────── */
    (function gallery() {
        var grid = $('[data-gallery]');
        var chips = $$('[data-gallery-filter]');

        /* filtering */
        if (grid && chips.length) {
            var items = $$('[data-gallery-item]', grid);

            chips.forEach(function (chip) {
                chip.addEventListener('click', function () {
                    var want = chip.getAttribute('data-gallery-filter');

                    chips.forEach(function (c) {
                        var on = c === chip;
                        c.classList.toggle('is-active', on);
                        c.setAttribute('aria-pressed', on ? 'true' : 'false');
                    });

                    items.forEach(function (item) {
                        var cat = item.getAttribute('data-category') || '';
                        var match = want === 'all' || cat === want;
                        item.classList.toggle('is-filtered', !match);
                        // Remove from layout after the fade so the grid re-flows
                        if (match) {
                            item.hidden = false;
                        } else {
                            setTimeout(function () {
                                if (item.classList.contains('is-filtered')) item.hidden = true;
                            }, 260);
                        }
                    });
                });
            });
        }

        /* lightbox */
        var box = $('[data-lightbox]');
        if (!box) return;

        var imgEl = $('[data-lightbox-img]', box);
        var capEl = $('[data-lightbox-cap]', box);
        var closeBtn = $('[data-lightbox-close]', box);
        var prevBtn = $('[data-lightbox-prev]', box);
        var nextBtn = $('[data-lightbox-next]', box);

        var triggers = $$('[data-lightbox-src]');
        if (!triggers.length) return;

        var visible = [];
        var cur = 0;
        var lastFocused = null;

        function refreshVisible() {
            visible = triggers.filter(function (t) {
                return !t.hidden && !t.classList.contains('is-filtered');
            });
        }

        function render() {
            var t = visible[cur];
            if (!t || !imgEl) return;
            imgEl.setAttribute('src', t.getAttribute('data-lightbox-src'));
            imgEl.setAttribute('alt', t.getAttribute('data-lightbox-alt') || '');
            if (capEl) {
                var cap = t.getAttribute('data-lightbox-cap') || '';
                capEl.textContent = cap;
                capEl.hidden = !cap;
            }
            var many = visible.length > 1;
            if (prevBtn) prevBtn.hidden = !many;
            if (nextBtn) nextBtn.hidden = !many;
        }

        function open(trigger) {
            refreshVisible();
            cur = Math.max(0, visible.indexOf(trigger));
            lastFocused = doc.activeElement;
            render();
            box.classList.add('is-open');
            box.setAttribute('aria-hidden', 'false');
            root.classList.add('no-scroll');
            doc.body.classList.add('no-scroll');
            if (closeBtn) setTimeout(function () { closeBtn.focus(); }, 90);
        }

        function close() {
            box.classList.remove('is-open');
            box.setAttribute('aria-hidden', 'true');
            root.classList.remove('no-scroll');
            doc.body.classList.remove('no-scroll');
            if (lastFocused && lastFocused.focus) lastFocused.focus();
        }

        function step(dir) {
            if (!visible.length) return;
            cur = (cur + dir + visible.length) % visible.length;
            render();
        }

        triggers.forEach(function (t) {
            t.addEventListener('click', function (e) {
                e.preventDefault();
                open(t);
            });
        });

        if (closeBtn) closeBtn.addEventListener('click', close);
        if (nextBtn) nextBtn.addEventListener('click', function () { step(1); });
        if (prevBtn) prevBtn.addEventListener('click', function () { step(-1); });

        box.addEventListener('click', function (e) {
            if (e.target === box) close();
        });

        doc.addEventListener('keydown', function (e) {
            if (!box.classList.contains('is-open')) return;
            if (e.key === 'Escape') { e.preventDefault(); close(); }
            else if (e.key === 'ArrowLeft') { e.preventDefault(); step(1); }
            else if (e.key === 'ArrowRight') { e.preventDefault(); step(-1); }
        });

        // Swipe between images
        var lx = 0, ly = 0, ltrack = false;
        box.addEventListener('touchstart', function (e) {
            if (e.touches.length !== 1) return;
            lx = e.touches[0].clientX; ly = e.touches[0].clientY; ltrack = true;
        }, { passive: true });
        box.addEventListener('touchend', function (e) {
            if (!ltrack) return;
            ltrack = false;
            var dx = e.changedTouches[0].clientX - lx;
            var dy = e.changedTouches[0].clientY - ly;
            if (Math.abs(dx) < 45 || Math.abs(dy) > Math.abs(dx)) return;
            step(dx < 0 ? 1 : -1);
        }, { passive: true });
    })();

    /* ─── 12. Tabs ─────────────────────────────────────────────────────── */
    (function tabs() {
        var groups = $$('[data-tabs]');
        if (!groups.length) return;

        groups.forEach(function (group) {
            var btns = $$('[data-tab]', group);
            if (!btns.length) return;

            function activate(btn, focus) {
                var targetSel = btn.getAttribute('data-tab');
                var panel = doc.querySelector(targetSel);

                btns.forEach(function (b) {
                    var on = b === btn;
                    b.classList.toggle('is-active', on);
                    b.setAttribute('aria-selected', on ? 'true' : 'false');
                    b.setAttribute('tabindex', on ? '0' : '-1');

                    var p = doc.querySelector(b.getAttribute('data-tab'));
                    if (p) {
                        p.classList.toggle('is-active', on);
                        p.hidden = !on;
                    }
                });

                if (focus) btn.focus();

                // Keep the chosen tab in view on narrow screens
                if (btn.scrollIntoView) {
                    try { btn.scrollIntoView({ inline: 'center', block: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' }); }
                    catch (e) { /* older browsers */ }
                }
                // Re-run reveals that live inside the freshly shown panel
                if (panel) {
                    $$('[data-reveal]', panel).forEach(function (el) { el.classList.add('is-revealed'); });
                }
            }

            btns.forEach(function (btn) {
                btn.addEventListener('click', function () { activate(btn, false); });
                btn.addEventListener('keydown', function (e) {
                    var i = btns.indexOf(btn);
                    // RTL: ArrowLeft moves forward
                    if (e.key === 'ArrowLeft') { e.preventDefault(); activate(btns[(i + 1) % btns.length], true); }
                    else if (e.key === 'ArrowRight') { e.preventDefault(); activate(btns[(i - 1 + btns.length) % btns.length], true); }
                    else if (e.key === 'Home') { e.preventDefault(); activate(btns[0], true); }
                    else if (e.key === 'End') { e.preventDefault(); activate(btns[btns.length - 1], true); }
                });
            });

            var initial = group.querySelector('[data-tab].is-active') || btns[0];
            activate(initial, false);
        });
    })();

    /* ─── 13. Card cursor glow ─────────────────────────────────────────── */
    (function cardGlow() {
        if (!prefersFinePointer() || reduceMotion) return;
        var cards = $$('.card-glow');
        if (!cards.length) return;

        cards.forEach(function (card) {
            card.addEventListener('pointermove', function (e) {
                var r = card.getBoundingClientRect();
                card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
                card.style.setProperty('--my', (e.clientY - r.top) + 'px');
            });
        });
    })();

    /* ─── 14. Newsletter form ──────────────────────────────────────────── */
    (function newsletter() {
        var form = $('[data-newsletter]');
        if (!form) return;
        var toast = $('[data-newsletter-toast]');

        function say(msg, ok) {
            if (!toast) return;
            toast.textContent = msg;
            toast.className = 'newsletter__toast ' + (ok ? 'is-success' : 'is-error');
            clearTimeout(toast._t);
            toast._t = setTimeout(function () { toast.className = 'newsletter__toast'; }, 7000);
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var name = (form.elements.name && form.elements.name.value || '').trim();
            var email = (form.elements.email && form.elements.email.value || '').trim();

            if (!name) { say('لطفاً نام خود را وارد کنید.', false); return; }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
                say('آدرس ایمیل معتبر وارد کنید.', false);
                return;
            }

            var btn = form.querySelector('button[type="submit"]');
            var label = btn ? btn.textContent : '';
            if (btn) { btn.disabled = true; btn.textContent = 'در حال ثبت…'; }

            setTimeout(function () {
                say('با تشکر ' + name + ' عزیز! عضویت شما در خبرنامه روما ثبت شد.', true);
                form.reset();
                if (btn) { btn.disabled = false; btn.textContent = label; }
            }, 700);
        });
    })();

    /* ─── 15. Parallax-lite ────────────────────────────────────────────── */
    (function parallax() {
        if (reduceMotion || isTouch) return;
        var els = $$('[data-parallax]');
        if (!els.length) return;

        var items = els.map(function (el) {
            return { el: el, speed: parseFloat(el.getAttribute('data-parallax')) || 0.12 };
        });

        subscribeScroll(function () {
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                var r = it.el.getBoundingClientRect();
                if (r.bottom < -200 || r.top > win.innerHeight + 200) continue;
                // Distance of the element's centre from the viewport centre
                var mid = r.top + r.height / 2 - win.innerHeight / 2;
                it.el.style.transform = 'translate3d(0,' + (-mid * it.speed).toFixed(2) + 'px,0)';
            }
        });
    })();

    /* ─── 16. Soft page transitions ────────────────────────────────────── */
    (function pageTransition() {
        if (reduceMotion) return;

        function markEnter() {
            root.classList.add('page-entering');
            setTimeout(function () { root.classList.remove('page-entering'); }, 620);
        }
        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', markEnter);
        else markEnter();

        // Chromium prerenders via Speculation Rules — an exit fade only adds latency
        var supportsPrerender = typeof HTMLScriptElement !== 'undefined' &&
            HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules');
        if (supportsPrerender) return;

        var leaving = false;

        doc.addEventListener('click', function (e) {
            if (leaving) return;
            if (e.defaultPrevented) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

            var link = e.target.closest ? e.target.closest('a[href]') : null;
            if (!link) return;
            if (link.target && link.target !== '_self') return;
            if (link.hasAttribute('download') || link.hasAttribute('data-no-transition')) return;
            if (link.protocol !== win.location.protocol || link.host !== win.location.host) return;

            var href = link.getAttribute('href');
            if (!href || href.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(href)) return;

            e.preventDefault();
            leaving = true;
            root.classList.add('page-leaving');
            setTimeout(function () { win.location.href = link.href; }, 90);
        }, true);
    })();

    /* ─── 17. Pause background work when hidden ────────────────────────── */
    (function bgPause() {
        var canvas = $('.bg-canvas');
        if (!canvas) return;

        doc.addEventListener('visibilitychange', function () {
            doc.body.classList.toggle('bg-paused', doc.hidden);
        });

        // Also stop the (relatively costly) blur animation while off-screen
        if (hasIO) {
            var sentinel = doc.createElement('div');
            sentinel.setAttribute('aria-hidden', 'true');
            sentinel.style.cssText = 'position:absolute;top:0;height:1px;width:1px;';
            doc.body.appendChild(sentinel);
        }
    })();

    /* ─── 18. Share buttons ────────────────────────────────────────────── */
    (function share() {
        var btns = $$('[data-share]');
        if (!btns.length) return;

        btns.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                var kind = btn.getAttribute('data-share');
                var url = btn.getAttribute('data-share-url') || win.location.href;
                var title = btn.getAttribute('data-share-title') || doc.title;

                if (kind === 'native') {
                    e.preventDefault();
                    if (navigator.share) {
                        navigator.share({ title: title, url: url }).catch(function () { /* dismissed */ });
                    } else if (navigator.clipboard) {
                        navigator.clipboard.writeText(url).then(function () {
                            var old = btn.getAttribute('aria-label');
                            btn.setAttribute('aria-label', 'لینک کپی شد');
                            btn.classList.add('is-copied');
                            setTimeout(function () {
                                btn.setAttribute('aria-label', old || 'اشتراک‌گذاری');
                                btn.classList.remove('is-copied');
                            }, 2000);
                        });
                    }
                }
            });
        });
    })();

})();

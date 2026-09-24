/* ============================================================================
 * ROMA — public site interaction engine
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

    /** Cached document scroll range. scrollHeight forces layout when read
     *  every frame — refresh only on resize / load / explicit invalidate. */
    var maxScroll = -1;
    function getMaxScroll() {
        if (maxScroll < 0) {
            maxScroll = Math.max(0, (doc.documentElement.scrollHeight || 0) - (win.innerHeight || 0));
        }
        return maxScroll;
    }
    function invalidateMaxScroll() { maxScroll = -1; }
    win.addEventListener('resize', invalidateMaxScroll, { passive: true });
    win.addEventListener('load', invalidateMaxScroll, { passive: true });
    if (hasIO) {
        // Long pages grow as images decode / reveals settle.
        try {
            new ResizeObserver(invalidateMaxScroll).observe(doc.documentElement);
        } catch (e) { /* older browsers */ }
    }

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

    /* PERF: runtime frame-time governor. If the device cannot hold ~45fps
       while scrolling, drop the expensive glass / blur work (class `perf-low`)
       rather than letting every frame miss vsync. Capable devices never see
       this class, so the designed look is untouched there. */
    (function perfGovernor() {
        var samples = 0, slow = 0, last = 0, watching = false, done = false;

        function onFrame(ts) {
            if (done) return;
            if (last) {
                var dt = ts - last;
                // Ignore tab-switch / breakpoint gaps
                if (dt > 0 && dt < 120) {
                    samples++;
                    if (dt > 22) slow++;
                }
            }
            last = ts;
            if (samples >= 40) {
                done = true;
                if (slow >= 12) root.classList.add('perf-low');
                return;
            }
            if (watching) win.requestAnimationFrame(onFrame);
        }

        function start() {
            if (watching || done) return;
            watching = true;
            last = 0;
            win.requestAnimationFrame(onFrame);
        }
        // Only measure during real scroll work, not idle first paint.
        win.addEventListener('scroll', start, { passive: true, once: false });
    })();

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
            var max = getMaxScroll();
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

    /* ─── 04. Morphing capsule header ──────────────────────────────────────
     * The header is FIXED and painted from the very first frame. It never
     * enters from outside the viewport — there is no reveal, no slide-in and
     * no hidden state to recover from. This module's whole job is therefore
     * a single number:
     *
     *   `--fh-p`  0 → 1, written at most once per rAF frame.
     *
     *             0  the header at rest: transparent, full-bleed, square,
     *                unblurred — just the logo / nav / burger over content.
     *             1  a fully condensed floating green glass capsule.
     *
     * CSS calc()s width, border-radius, background opacity, blur, scale,
     * height and padding from it, so the morph tracks the wheel or finger
     * exactly instead of trailing a CSS transition behind the scroll.
     *
     * MORPH_AT is short on purpose: the transformation should feel like a
     * direct response to the first flick of the scroll, not a delayed event.
     * ------------------------------------------------------------------- */
    (function morphingHeader() {
        var header = $('[data-header]');
        if (!header) return;

        var MORPH_AT = 140;     // px of scroll over which 0 → 1 plays out
        var GLASS_AT = 0.55;    // p above which the glass counts as "on"

        var lastP = -1;
        var lastPc = -1;
        var glass = false;
        var filtering = false;

        function update(y) {
            // While the drawer or lightbox holds the scroll lock the page
            // cannot move; leave the header exactly as the user last saw it.
            if (root.classList.contains('no-scroll')) return;

            // Eased so the first pixels of scroll read as a gentle swell
            // rather than an abrupt snap into the capsule.
            var raw = clamp(y / MORPH_AT, 0, 1);
            var p = raw * raw * (3 - 2 * raw);          // smoothstep

            // PERF: two quantization tiers.
            //  --fh-p  fine (0.005)  → opacity / transform only (compositor)
            //  --fh-pc coarse (0.04) → layout sizes, color-mix, backdrop-filter
            // Layout + color-mix + filter recalculation is what janks Firefox /
            // Safari on 120Hz trackpads; 25 coarse steps over a 140px morph is
            // visually identical to a continuous ramp.
            var pf = Math.round(p * 200) / 200;
            var pc = Math.round(p * 25) / 25;

            if (pf !== lastP) {
                lastP = pf;
                header.style.setProperty('--fh-p', String(pf));
            }
            if (pc !== lastPc) {
                lastPc = pc;
                header.style.setProperty('--fh-pc', String(pc));
            }

            // backdrop-filter: none at rest — blur(0) still opens an
            // expensive backdrop root and samples the page every frame.
            // Enable only once the glass is actually coming in.
            var nextFilter = pc > 0;
            if (nextFilter !== filtering) {
                filtering = nextFilter;
                header.classList.toggle('is-filtering', filtering);
            }

            // Only used by the no-color-mix fallback, which cannot
            // interpolate the ink and needs a single clean switch.
            var nextGlass = p >= GLASS_AT;
            if (nextGlass !== glass) {
                glass = nextGlass;
                header.classList.toggle('is-glass', glass);
            }
        }

        subscribeScroll(update);

        // Paint the correct state for a restored scroll position (reload,
        // back-forward cache, deep link) before the first scroll event.
        update(win.scrollY || 0);
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
        var unlockTimer = null;
        // PERF: cached once. The old code called getComputedStyle(panel) on
        // every touchmove, forcing a style recalc per move event and janking
        // the swipe-to-close gesture. Direction never changes at runtime.
        var isRtlPanel = (function () {
            try {
                if (root.getAttribute('dir') === 'rtl') return true;
                if (root.getAttribute('dir') === 'ltr') return false;
                if (win.getComputedStyle) return win.getComputedStyle(panel).direction === 'rtl';
            } catch (e) { /* fall through */ }
            return true; // site default is RTL
        })();

        var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

        function lockScroll() {
            // PERF: overflow:hidden on <html>/<body> reflows the whole page.
            // Doing it in the same frame as the slide-in start stole the
            // first animation frames on Firefox / mid Android. One rAF later
            // the drawer is already mid-flight and the reflow is invisible.
            if (unlockTimer) { clearTimeout(unlockTimer); unlockTimer = null; }
            win.requestAnimationFrame(function () {
                if (!isOpen) return;
                root.classList.add('no-scroll');
                doc.body.classList.add('no-scroll');
            });
        }

        function unlockScrollDelayed() {
            // PERF: keep the page locked while the overlay fades out so the
            // unlock reflow lands after the animation, not inside it.
            if (unlockTimer) clearTimeout(unlockTimer);
            unlockTimer = setTimeout(function () {
                unlockTimer = null;
                if (isOpen) return; // reopened in the meantime — stay locked
                root.classList.remove('no-scroll');
                doc.body.classList.remove('no-scroll');
            }, 300);
        }

        function clearPriming() {
            panel.style.visibility = '';
            panel.style.opacity = '';
            if (panel.removeAttribute) panel.removeAttribute('inert');
        }

        function open() {
            if (isOpen) return;
            isOpen = true;
            lastFocused = doc.activeElement;

            // Drop any priming inline overrides so .is-open CSS drives the slide.
            clearPriming();
            panel.classList.add('is-open');
            if (overlay) overlay.classList.add('is-open');
            panel.setAttribute('aria-hidden', 'false');
            lockScroll();
            openBtns.forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });

            // Move focus into the panel AFTER the slide settles. Focusing at
            // 120ms forced a mid-animation style/layout pass and stuttered the
            // first frames of the open on Firefox / mid Android.
            // preventScroll: focus must never nudge any scroll container.
            var first = panel.querySelector(FOCUSABLE);
            if (first) {
                setTimeout(function () {
                    if (isOpen) first.focus({ preventScroll: true });
                }, 450);
            }
        }

        function close() {
            if (!isOpen) return;
            isOpen = false;

            panel.classList.remove('is-open');
            if (overlay) overlay.classList.remove('is-open');
            panel.setAttribute('aria-hidden', 'true');
            unlockScrollDelayed();
            openBtns.forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });

            // preventScroll: the burger lives inside the position:sticky header,
            // and focusing it without this flag makes Chromium reveal-scroll the
            // page upward every time the drawer closes.
            if (lastFocused && lastFocused.focus) lastFocused.focus({ preventScroll: true });
        }

        openBtns.forEach(function (b) {
            // PERF: paint the off-screen panel once on first pointer-down so
            // the open transition starts against an already-rasterized layer.
            // Resting CSS uses visibility:hidden (skips paint); priming briefly
            // paints the subtree at opacity 0 and keeps it painted via inert
            // so the 420ms slide does not pay first-raster cost mid-animation.
            var primed = false;
            function prime() {
                if (primed || isOpen) return;
                primed = true;
                panel.style.visibility = 'visible';
                panel.style.opacity = '0';
                if (panel.setAttribute) panel.setAttribute('inert', '');
                // Force style + layout so the next paint includes the panel.
                void panel.offsetWidth;
            }
            b.addEventListener('pointerdown', prime, { passive: true });
            b.addEventListener('touchstart', prime, { passive: true });
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
            // RTL panel sits at the right → swiping right (positive dx) closes it
            // (direction is cached at init — see isRtlPanel — no per-move reflow)
            var closing = isRtlPanel ? dx > 62 : dx < -62;
            if (closing) { tracking = false; close(); }
        }, { passive: true });
        panel.addEventListener('touchend', function () { tracking = false; }, { passive: true });

        // The opener is available below the desktop-nav breakpoint. Close the drawer
        // when crossing into desktop so a panel opened in a narrower layout
        // cannot linger underneath the inline navigation after a resize.
        if (win.matchMedia) {
            var mqDesktop = win.matchMedia('(min-width: 992px)');
            var onChange = function () { close(); };
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
            if (lastFocused && lastFocused.focus) lastFocused.focus({ preventScroll: true });
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

    /* ─── 13. Card cursor glow ───────────────────────────────────────────
     * PERF: rAF-throttled per card + rect cached on enter. Previously every
     * pointermove did a getBoundingClientRect (forced layout) + two custom
     * property writes → repaint of a radial gradient. Now at most one write
     * per frame, rect re-used while the pointer stays on the card. */
    (function cardGlow() {
        if (!prefersFinePointer() || reduceMotion) return;
        var cards = $$('.card-glow');
        if (!cards.length) return;

        cards.forEach(function (card) {
            var rect = null;
            var queued = false;
            var px = 0, py = 0;

            card.addEventListener('pointerenter', function () {
                rect = card.getBoundingClientRect();
            }, { passive: true });

            card.addEventListener('pointermove', function (e) {
                px = e.clientX; py = e.clientY;
                if (queued) return;
                queued = true;
                win.requestAnimationFrame(function () {
                    queued = false;
                    // Re-measure only if the rect is stale (scroll/resize can
                    // move the card while hovered). Cheap guard: re-cache when
                    // the pointer falls outside the cached box.
                    if (!rect || px < rect.left || px > rect.right || py < rect.top || py > rect.bottom) {
                        rect = card.getBoundingClientRect();
                    }
                    card.style.setProperty('--mx', (px - rect.left) + 'px');
                    card.style.setProperty('--my', (py - rect.top) + 'px');
                });
            }, { passive: true });

            card.addEventListener('pointerleave', function () { rect = null; }, { passive: true });
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

    /* ─── 15. Parallax-lite ──────────────────────────────────────────────
     * PERF: only visible items are measured per frame. An IntersectionObserver
     * keeps a `visible` set so off-screen [data-parallax] nodes cost zero
     * getBoundingClientRect calls during scroll — previously every node was
     * measured on every scroll frame (forced sync layout). */
    (function parallax() {
        if (reduceMotion || isTouch) return;
        var els = $$('[data-parallax]');
        if (!els.length) return;

        var items = els.map(function (el) {
            return { el: el, speed: parseFloat(el.getAttribute('data-parallax')) || 0.12, visible: true, top: 0, height: 0 };
        });

        // PERF: document-space rect cache. Measuring with getBoundingClientRect
        // on every scroll frame is forced sync layout; store top/height once and
        // derive viewport position from scrollY.
        function measure(it) {
            var r = it.el.getBoundingClientRect();
            it.top = r.top + (win.scrollY || win.pageYOffset || 0);
            it.height = r.height;
        }
        function measureAll() {
            for (var i = 0; i < items.length; i++) measure(items[i]);
        }

        if (hasIO) {
            var pio = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    for (var k = 0; k < items.length; k++) {
                        if (items[k].el === en.target) {
                            // Widen the visible window slightly so elements
                            // entering the viewport never pop in late.
                            items[k].visible = en.isIntersecting;
                            if (en.isIntersecting) measure(items[k]);
                            break;
                        }
                    }
                });
            }, { rootMargin: '220px 0px 220px 0px', threshold: 0 });
            items.forEach(function (it) { pio.observe(it.el); });
        }

        var vh = win.innerHeight || 800;
        win.addEventListener('resize', function () {
            vh = win.innerHeight || 800;
            measureAll();
        }, { passive: true });
        win.addEventListener('load', measureAll, { passive: true });
        measureAll();

        subscribeScroll(function (y) {
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                if (!it.visible) continue;
                var topInView = it.top - y;
                if (topInView + it.height < -200 || topInView > vh + 200) continue;
                // Distance of the element's centre from the viewport centre
                var mid = topInView + it.height / 2 - vh / 2;
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

    /* ─── 16b. Pause continuous decor while off-screen ─────────────────
     * PERF: infinite CSS loops (cardBob, bgPan, orbFloat, iconBreathe,
     * blobDrift, hero kenBurns) keep the compositor awake even when the
     * user has scrolled far past them. Toggle .is-offscreen when the host
     * leaves the viewport — visuals while visible are identical. */
    (function pauseOffscreen() {
        if (!hasIO || reduceMotion) return;
        var sel = '.stats-section, .cta-banner, .cta-banner-wrapper, ' +
            '.testimonials-slider, .newsletter-card, .newsletter-section, ' +
            '.hero-fallback, .site-footer, .trust-strip, .hero__viewport, .slider';
        var hosts = $$(sel);
        if (!hosts.length) return;
        var oio = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                en.target.classList.toggle('is-offscreen', !en.isIntersecting);
            });
        }, { rootMargin: '240px 0px 240px 0px', threshold: 0 });
        hosts.forEach(function (h) {
            h.classList.add('is-offscreen');
            oio.observe(h);
        });
    })();

    /* ─── 17. Pause background work when hidden ──────────────────────────
     * PERF: .bg-canvas is position:fixed so it can never scroll off-screen —
     * the old sentinel did nothing. Instead: pause on tab-hide, enable a
     * lite mode (fewer blur costs) on Save-Data / 2G / low-core devices.
     * Visual output on capable devices is untouched. */
    (function bgPause() {
        var canvas = $('.bg-canvas');
        if (!canvas) return;

        function syncPaused() {
            doc.body.classList.toggle('bg-paused', !!doc.hidden);
        }
        doc.addEventListener('visibilitychange', syncPaused);
        syncPaused();

        try {
            var conn = navigator.connection || {};
            var saveData = !!conn.saveData;
            var slowNet = typeof conn.effectiveType === 'string' &&
                (conn.effectiveType.indexOf('2g') !== -1 || conn.effectiveType === 'slow-2g');
            var lowCores = typeof navigator.hardwareConcurrency === 'number' &&
                navigator.hardwareConcurrency <= 4;
            var lowMem = typeof navigator.deviceMemory === 'number' &&
                navigator.deviceMemory <= 2;
            if (saveData || slowNet || lowCores || lowMem) {
                doc.body.classList.add('bg-lite');
                doc.body.classList.add('perf-low');
            }
        } catch (e) { /* never break visuals on exotic browsers */ }
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

document.documentElement.classList.add('js-enabled');

// Shared reduced-motion preference — every JS-driven motion below defers to it.
var prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// PERF: cached scroll range. scrollHeight forces layout when read every
// scroll frame — refresh only on resize / load.
var _maxScrollCache = -1;
function getMaxScrollCached() {
    if (_maxScrollCache < 0) {
        var d = document.documentElement;
        _maxScrollCache = Math.max(0, (d.scrollHeight || 0) - (window.innerHeight || 0));
    }
    return _maxScrollCache;
}
function invalidateMaxScrollCached() { _maxScrollCache = -1; }
window.addEventListener('resize', invalidateMaxScrollCached, { passive: true });
window.addEventListener('load', invalidateMaxScrollCached, { passive: true });

// PERF: one shared rAF-throttled scroll bus for every script.js module.
var _scrollSubs = [];
var _scrollQueued = false;
function onSharedScrollFrame() {
    _scrollQueued = false;
    var y = window.scrollY || window.pageYOffset || 0;
    for (var i = 0; i < _scrollSubs.length; i++) {
        try { _scrollSubs[i](y); } catch (e) { /* keep other subscribers alive */ }
    }
}
function subscribeSharedScroll(fn) {
    _scrollSubs.push(fn);
    if (_scrollSubs.length === 1) {
        window.addEventListener('scroll', function () {
            if (!_scrollQueued) {
                _scrollQueued = true;
                window.requestAnimationFrame(onSharedScrollFrame);
            }
        }, { passive: true });
    }
    fn(window.scrollY || 0);
}

// PERF: runtime frame-time governor (same contract as rooma.js).
(function perfGovernor() {
    var root = document.documentElement;
    var samples = 0, slow = 0, last = 0, watching = false, done = false;
    function onFrame(ts) {
        if (done) return;
        if (last) {
            var dt = ts - last;
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
        if (watching) window.requestAnimationFrame(onFrame);
    }
    function start() {
        if (watching || done) return;
        watching = true;
        last = 0;
        window.requestAnimationFrame(onFrame);
    }
    window.addEventListener('scroll', start, { passive: true });

    // Cheap static tier: Save-Data / 2G / ≤4 cores (mirrors rooma.js bg-lite).
    try {
        var conn = navigator.connection || {};
        var saveData = !!conn.saveData;
        var slowNet = typeof conn.effectiveType === 'string' &&
            (conn.effectiveType.indexOf('2g') !== -1 || conn.effectiveType === 'slow-2g');
        var lowCores = typeof navigator.hardwareConcurrency === 'number' &&
            navigator.hardwareConcurrency <= 4;
        var lowMem = typeof navigator.deviceMemory === 'number' && navigator.deviceMemory <= 2;
        if (saveData || slowNet || lowCores || lowMem) {
            root.classList.add('bg-lite');
            root.classList.add('perf-low');
        }
    } catch (e) { /* never break on exotic browsers */ }
})();

// ============================================
// SCROLL PROGRESS RIBBON
// Thin gradient bar pinned to the top edge that
// fills as the user scrolls down the page.
// ============================================
(function() {
    var bar = document.getElementById('scrollProgress');
    if (!bar) return;
    // PERF: transform:scaleX is compositor-only; width triggers layout on
    // every scroll frame. Visually identical (RTL: grows right→left).
    bar.style.transformOrigin = 'right center';
    bar.style.willChange = 'transform';
    subscribeSharedScroll(function(y) {
        var max = getMaxScrollCached();
        var pct = max > 8 ? (y / max) : 0;
        if (pct < 0) pct = 0; else if (pct > 1) pct = 1;
        bar.style.transform = 'scaleX(' + pct.toFixed(4) + ')';
    });
})();

// ============================================
// SECTION HEADER ENTRANCE TRIGGER
// Adds .in-view to .section-header elements when they
// scroll into the viewport so the drawn-underline and
// icon-pop animations in enhance.css fire once.
// ============================================
(function() {
    var headers = document.querySelectorAll('.section-header');
    if (!headers.length || !('IntersectionObserver' in window)) return;
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            // Also fire when the header has been scrolled past (fast jumps
            // skip intersection entirely and would leave it un-animated).
            if (entry.isIntersecting || entry.boundingClientRect.top < 0) {
                entry.target.classList.add('in-view');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.2 });
    headers.forEach(function(h) { observer.observe(h); });
})();

// ============================================
// SOFT PAGE TRANSITIONS
// Fade+slide the main content in on every page load, and fade the
// whole page out when an internal link is clicked — so multi-page
// navigation feels smooth instead of an abrupt white flash.
// Skipped for users with reduced-motion preference and for
// modifier-click / target-blank / download links.
// ============================================
(function() {
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reducedMotion) return;

    // Entrance: mark the page so the CSS animation runs. The script loads
    // with `defer`, so DOMContentLoaded may have already fired — check first.
    function markEntering() {
        document.documentElement.classList.add('page-entering');
        // Outlasts the longest entrance animation (headerDrop 0.55s) so it
        // never gets cut mid-flight.
        setTimeout(function() {
            document.documentElement.classList.remove('page-entering');
        }, 600);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', markEntering);
    } else {
        markEntering();
    }

    // Exit: intercept internal link clicks and form submits.
    var isLeaving = false;
    // Chromium prerenders internal pages via Speculation Rules — activation
    // is instant, so the 60ms exit fade only delays those visitors.
    var supportsPrerender = typeof HTMLScriptElement !== 'undefined' &&
        HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules');

    function shouldIgnore(el) {
        if (!el || isLeaving) return true;
        if (el.target && el.target !== '_self') return true;
        if (el.hasAttribute('download')) return true;
        if (el.protocol !== window.location.protocol || el.host !== window.location.host) return true;
        var href = el.getAttribute('href');
        if (!href || href.charAt(0) === '#') return true;
        if (el.hasAttribute('data-no-transition')) return true;
        // Modifier clicks open new tabs — let the browser handle them.
        if (event && (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0)) return true;
        return false;
    }

    document.addEventListener('click', function(e) {
        var link = e.target.closest ? e.target.closest('a[href]') : null;
        if (!shouldIgnore(link)) {
            if (supportsPrerender) return; // prerendered activation — navigate natively
            e.preventDefault();
            isLeaving = true;
            document.documentElement.classList.add('page-leaving');
            // Match the CSS exit duration before navigating.
            setTimeout(function() { window.location.href = link.href; }, 60);
        }
    }, true);

    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (isLeaving) return;
        // Only same-document GET navigations get the fade; POSTs often
        // stay on the same URL or trigger downloads.
        if (form.method && form.method.toLowerCase() === 'post') return;
        var actionUrl = form.getAttribute('action');
        if (!actionUrl || actionUrl.charAt(0) === '#') return;
        try {
            var resolved = new URL(actionUrl, window.location.href);
            if (resolved.origin !== window.location.origin) return;
        } catch (err) { return; }
        e.preventDefault();
        isLeaving = true;
        document.documentElement.classList.add('page-leaving');
        setTimeout(function() { form.submit(); }, 60);
    }, true);
})();

// ============================================
// SPECULATIVE LINK PREFETCH
// Warms the server connection with internal pages as they scroll near the
// viewport, so clicking a nav link feels faster. Skipped entirely in
// Chromium browsers, which get full prerendering via the Speculation
// Rules <script> in header.php instead. Also skipped in admin/teacher/
// parent shells and reduced-data contexts via Save-Data.
// ============================================
(function() {
    var supportsPrerender = typeof HTMLScriptElement !== 'undefined' &&
        HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules');
    if (supportsPrerender) return;
    if (document.body && document.body.classList.contains('admin-layout')) return;
    if (document.querySelector('.parent-main, .teacher-container')) return;
    var conn = navigator.connection || {};
    if (conn.saveData) return;

    var prefetched = new Set();

    function prefetch(href) {
        if (prefetched.has(href)) return;
        prefetched.add(href);
        var r = new Request(href, { mode: 'same-origin', credentials: 'same-origin', priority: 'low' });
        fetch(r).catch(function() {});
    }

    function eligible(link) {
        var href = link.getAttribute('href');
        if (!href || href.charAt(0) === '#') return false;
        if (link.target && link.target !== '_self') return false;
        if (link.protocol !== window.location.protocol || link.host !== window.location.host) return false;
        // Same page — browser already handles it.
        if (link.href === window.location.href) return false;
        return true;
    }

    function scan(root) {
        root.querySelectorAll('a[href]').forEach(function(link) {
            if (!eligible(link)) return;
            prefetch(link.href);
        });
    }

    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    prefetch(entry.target.href);
                    io.unobserve(entry.target);
                }
            });
        }, { rootMargin: '200px' });

        function observeAll() {
            document.querySelectorAll('a[href]').forEach(function(link) {
                if (eligible(link)) io.observe(link);
            });
        }
        observeAll();
    } else {
        scan(document);
    }
})();

// Mobile Menu Toggle (Public Site)
document.addEventListener('DOMContentLoaded', function() {
    // ============================================
    // HERO SLIDER
    // ============================================
    var slider = document.querySelector('.slider');
    if (slider) {
        var slides = slider.querySelectorAll('.slide');
        var dots = slider.querySelectorAll('.slider-dot');
        var prevBtn = slider.querySelector('.slider-prev');
        var nextBtn = slider.querySelector('.slider-next');
        var currentSlide = 0;
        var totalSlides = slides.length;
        var autoPlayInterval = null;
        var autoPlayDelay = 5000;
        var isAutoPlaying = true;
        var resumeTimeout = null;

        if (totalSlides > 0) {
            // Initialize first slide if not already active
            var activeSlide = slider.querySelector('.slide.is-active');
            if (!activeSlide) {
                slides[0].classList.add('is-active');
                if (dots[0]) dots[0].classList.add('is-active');
            } else {
                currentSlide = Array.from(slides).indexOf(activeSlide);
            }

            function goToSlide(index) {
                if (index < 0) index = totalSlides - 1;
                if (index >= totalSlides) index = 0;

                slides[currentSlide].classList.remove('is-active');
                if (dots[currentSlide]) dots[currentSlide].classList.remove('is-active');

                currentSlide = index;

                slides[currentSlide].classList.add('is-active');
                if (dots[currentSlide]) dots[currentSlide].classList.add('is-active');

                // Inactive slides keep opacity:0 for the crossfade but must not
                // stay in the tab order — otherwise focus lands on invisible CTAs.
                slides.forEach(function(slide, i) {
                    slide.toggleAttribute('inert', i !== currentSlide);
                    slide.setAttribute('aria-hidden', i === currentSlide ? 'false' : 'true');
                });
            }

            function goNext() { goToSlide(currentSlide + 1); }
            function goPrev() { goToSlide(currentSlide - 1); }

            function startAutoPlay() {
                if (autoPlayInterval) clearInterval(autoPlayInterval);
                autoPlayInterval = setInterval(goNext, autoPlayDelay);
                isAutoPlaying = true;
            }

            function stopAutoPlay() {
                if (autoPlayInterval) clearInterval(autoPlayInterval);
                isAutoPlaying = false;
            }

            function handleManualNav() {
                stopAutoPlay();
                // Respect an explicit user pause — manual navigation must not
                // silently resume the carousel they chose to stop.
                if (slider.classList.contains('is-paused')) return;
                if (resumeTimeout) clearTimeout(resumeTimeout);
                resumeTimeout = setTimeout(function() {
                    if (!slider.matches(':hover')) startAutoPlay();
                }, autoPlayDelay);
            }

            // Navigation buttons
            if (prevBtn) {
                prevBtn.addEventListener('click', function() {
                    goPrev();
                    handleManualNav();
                });
            }
            if (nextBtn) {
                nextBtn.addEventListener('click', function() {
                    goNext();
                    handleManualNav();
                });
            }

            // Dot navigation
            dots.forEach(function(dot, index) {
                dot.addEventListener('click', function() {
                    goToSlide(index);
                    handleManualNav();
                });
            });

            // Pause on hover
            slider.addEventListener('mouseenter', stopAutoPlay);
            slider.addEventListener('mouseleave', startAutoPlay);

            // Touch gestures for mobile — RTL reading direction: swiping the
            // finger toward the left (diffX < 0) reveals the NEXT slide.
            var touchStartX = 0;
            var touchStartY = 0;
            slider.addEventListener('touchstart', function(e) {
                touchStartX = e.changedTouches[0].screenX;
                touchStartY = e.changedTouches[0].screenY;
            }, { passive: true });

            slider.addEventListener('touchend', function(e) {
                var diffX = touchStartX - e.changedTouches[0].screenX;
                var diffY = touchStartY - e.changedTouches[0].screenY;

                if (Math.abs(diffX) > 50 && Math.abs(diffX) > Math.abs(diffY)) {
                    if (diffX > 0) goPrev();
                    else goNext();
                    handleManualNav();
                }
            }, { passive: true });

            // Initialize inert/aria-hidden on non-active slides, then start
            // autoplay unless the visitor prefers reduced motion.
            goToSlide(currentSlide);
            if (prefersReducedMotion) {
                // No autoplay at all — reflect a paused state in the toggle.
                isAutoPlaying = false;
                var pauseBtnRM = slider.querySelector('[data-slider-toggle]');
                if (pauseBtnRM) {
                    pauseBtnRM.setAttribute('aria-pressed', 'false');
                    pauseBtnRM.setAttribute('aria-label', 'پخش خودکار');
                    slider.classList.add('is-paused');
                }
            } else {
                startAutoPlay();
            }

            // Pause / play control — required for auto-advancing content
            // (WCAG 2.2.2) and simply kind to anyone wanting to linger.
            var autoplayToggle = slider.querySelector('[data-slider-toggle]');
            if (autoplayToggle) {
                autoplayToggle.addEventListener('click', function() {
                    var paused = slider.classList.toggle('is-paused');
                    if (paused) {
                        stopAutoPlay();
                        autoplayToggle.setAttribute('aria-pressed', 'false');
                        autoplayToggle.setAttribute('aria-label', 'پخش خودکار');
                    } else {
                        startAutoPlay();
                        autoplayToggle.setAttribute('aria-pressed', 'true');
                        autoplayToggle.setAttribute('aria-label', 'توقف پخش خودکار');
                    }
                });
            }
        }
    }

    // Header scroll effect (legacy .scrolled variant for style.css pages).
    // PERF: shared scroll bus + state-guarded so no class churn on every pixel.
    var header = document.querySelector('.site-header');
    if (header) {
        var headerOn = null;
        subscribeSharedScroll(function(y) {
            var next = y > 50;
            if (next !== headerOn) {
                headerOn = next;
                header.classList.toggle('scrolled', next);
            }
        });
    }

    // Enhanced scroll entrance animation observer
    var motionElements = document.querySelectorAll('.fade-in, .fade-up, .fade-scale, .slide-in-right, .slide-in-left');
    if (motionElements.length > 0 && 'IntersectionObserver' in window) {
        var motionObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                // Reveal when entering the viewport — or when already scrolled
                // PAST it (fast jumps land elements above the fold without ever
                // intersecting, which would otherwise leave them invisible).
                if (entry.isIntersecting || entry.boundingClientRect.top < 0) {
                    entry.target.classList.add('visible');
                    motionObserver.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.08,
            rootMargin: '0px 0px -40px 0px'
        });

        motionElements.forEach(function(element) {
            motionObserver.observe(element);
        });
    }

    // ============================================
    // TAB SWITCHER COMPONENT
    // ============================================
    var tabButtons = document.querySelectorAll('.tab-btn[data-target]');
    tabButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetSelector = btn.getAttribute('data-target');

            var tabList = btn.closest('.tabs');
            if (tabList) {
                tabList.querySelectorAll('.tab-btn').forEach(function(b) {
                    b.classList.remove('is-active');
                    b.setAttribute('aria-selected', 'false');
                });
            }

            btn.classList.add('is-active');
            btn.setAttribute('aria-selected', 'true');

            var container = btn.closest('.container') || document;
            container.querySelectorAll('.tab-pane').forEach(function(pane) {
                pane.classList.remove('is-active');
            });

            var targetPane = document.querySelector(targetSelector);
            if (targetPane) {
                targetPane.classList.add('is-active');
            }
        });
    });

    // ============================================
    // ADMIN PANEL FUNCTIONALITY
    // ============================================
    
    var adminSidebar = document.getElementById('adminSidebar');
    var sidebarToggle = document.getElementById('sidebarToggle');
    var mobileSidebarToggle = document.getElementById('mobileSidebarToggle');
    var adminOverlay = document.getElementById('adminOverlay');
    
    // Desktop sidebar toggle (collapse/expand)
    if (sidebarToggle && adminSidebar) {
        sidebarToggle.addEventListener('click', function() {
            adminSidebar.classList.toggle('collapsed');
            
            // Save state to localStorage
            if (adminSidebar.classList.contains('collapsed')) {
                localStorage.setItem('adminSidebarCollapsed', 'true');
            } else {
                localStorage.setItem('adminSidebarCollapsed', 'false');
            }
        });
        
        // Restore sidebar state from localStorage
        var sidebarCollapsed = localStorage.getItem('adminSidebarCollapsed');
        if (sidebarCollapsed === 'true') {
            adminSidebar.classList.add('collapsed');
        }
    }
    
    // Mobile sidebar toggle
    if (mobileSidebarToggle && adminSidebar && adminOverlay) {
        mobileSidebarToggle.addEventListener('click', function() {
            adminSidebar.classList.toggle('active');
            adminOverlay.classList.toggle('active');
        });
        
        // Close sidebar when clicking overlay
        adminOverlay.addEventListener('click', function() {
            adminSidebar.classList.remove('active');
            adminOverlay.classList.remove('active');
        });
    }
    
    // Admin submenu accordion — CSS uses class "open" (admin.css) 
    var adminNavParents = document.querySelectorAll('.admin-nav-parent');
    adminNavParents.forEach(function(parent) {
        parent.addEventListener('click', function(e) {
            e.preventDefault();
            var submenuId = 'submenu-' + parent.getAttribute('data-submenu');
            var submenu = document.getElementById(submenuId);
            
            if (submenu) {
                var isOpen = submenu.classList.contains('open');
                
                // Close all other submenus
                document.querySelectorAll('.admin-nav-submenu').forEach(function(sub) {
                    sub.classList.remove('open', 'active');
                });
                document.querySelectorAll('.admin-nav-parent').forEach(function(p) {
                    p.removeAttribute('aria-expanded');
                    p.classList.remove('active');
                });
                
                // Toggle current submenu
                if (!isOpen) {
                    submenu.classList.add('open');
                    parent.setAttribute('aria-expanded', 'true');
                    parent.classList.add('active');
                }
            }
        });
    });
    
    // Auto-open active submenu on page load
    var activeSubitem = document.querySelector('.admin-nav-subitem.active, .admin-nav-submenu .admin-nav-item.active');
    if (activeSubitem) {
        var parentSubmenu = activeSubitem.closest('.admin-nav-submenu');
        if (parentSubmenu) {
            parentSubmenu.classList.add('open');
            var parentButton = document.querySelector('[data-submenu="' + parentSubmenu.id.replace('submenu-', '') + '"]');
            if (parentButton) {
                parentButton.setAttribute('aria-expanded', 'true');
                parentButton.classList.add('active');
            }
        }
    }
    
    // ============================================
    // FORM ENHANCEMENTS
    // ============================================

    // Auto-focus first empty input in standalone auth or modal forms (skip public page forms to avoid unwanted scroll)
    var authForm = document.querySelector('.auth-form-panel form, .modal-content form');
    if (authForm) {
        var firstInput = authForm.querySelector('input:not([type="hidden"]):not([type="submit"])');
        if (firstInput && !firstInput.value) {
            firstInput.focus({ preventScroll: true });
        }
    }

    // Confirm before delete actions handled by app-shell.js modal (A1 fix)

    // ============================================
    // TABLE ENHANCEMENTS
    // ============================================

    // Add responsive wrapper to tables
    var tables = document.querySelectorAll('table:not(.no-responsive)');
    tables.forEach(function(table) {
        if (!table.parentElement.classList.contains('table-responsive')) {
            var wrapper = document.createElement('div');
            wrapper.className = 'table-responsive';
            table.parentNode.insertBefore(wrapper, table);
            wrapper.appendChild(table);
        }
    });

    // ============================================
    // PASSWORD TOGGLE
    // ============================================

    var toggleButtons = document.querySelectorAll('.toggle-password');
    var eyeOpenSvg = '<svg class="eye-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var eyeClosedSvg = '<svg class="eye-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    toggleButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            var targetId = button.getAttribute('data-toggle');
            var input = document.getElementById(targetId);
            if (input) {
                if (input.type === 'password') {
                    input.type = 'text';
                    button.innerHTML = eyeClosedSvg;
                    button.setAttribute('aria-label', 'مخفی کردن رمز عبور');
                } else {
                    input.type = 'password';
                    button.innerHTML = eyeOpenSvg;
                    button.setAttribute('aria-label', 'نمایش رمز عبور');
                }
            }
        });
    });

    // ============================================
    // SMOOTH SCROLL FOR ANCHOR LINKS
    // ============================================

    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            var targetId = this.getAttribute('href');
            if (targetId === '#') return;
            var target = document.querySelector(targetId);
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: prefersReducedMotion ? 'auto' : 'smooth', block: 'start' });
            }
        });
    });

    // ============================================
    // MOBILE BOTTOM NAVIGATION
    // ============================================

    // Add haptic-style touch feedback to bottom nav items.
    // PERF: no inline style writes — the press scale lives in CSS (:active)
    // so touch scrolling never triggers style recalc from JS.
    // (Kept as a no-op hook for future haptics; intentionally style-free.)

    // Hide bottom nav on scroll down, show on scroll up (mobile only).
    // Skipped for reduced-motion users — the sliding bar is pure motion.
    // PERF: transform-only + class toggle. The transition lives in CSS
    // (.mobile-bottom-nav.is-anim + .is-hidden) so JS writes one cheap
    // property per direction change instead of transition+transform inline.
    var bottomNav = document.querySelector('.mobile-bottom-nav');
    if (bottomNav && !prefersReducedMotion) {
        var lastScrollY = window.scrollY || 0;
        var navHideThreshold = 10;
        var navHidden = null;

        subscribeSharedScroll(function(currentScrollY) {
            var diff = currentScrollY - lastScrollY;

            if (diff > navHideThreshold && currentScrollY > 200) {
                if (navHidden !== true) {
                    navHidden = true;
                    bottomNav.classList.add('is-hidden');
                }
            } else if (diff < -navHideThreshold) {
                if (navHidden !== false) {
                    navHidden = false;
                    bottomNav.classList.remove('is-hidden');
                }
            }

            lastScrollY = currentScrollY;
        });
    }

    // ============================================
    // STAT COUNTER ANIMATION
    // ============================================
    var statNumbers = document.querySelectorAll('.stat-number[data-count], .metric-value[data-count]');
    if (statNumbers.length > 0 && 'IntersectionObserver' in window) {
        var countObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    var el = entry.target;
                    var target = parseInt(el.getAttribute('data-count'), 10);
                    var suffix = el.getAttribute('data-suffix') || '';
                    // Reduced motion: show the final number immediately.
                    if (prefersReducedMotion) {
                        el.textContent = target.toLocaleString('fa-IR') + suffix;
                        countObserver.unobserve(el);
                        return;
                    }
                    var duration = 1200;
                    var startTime = null;
                    var startVal = 0;
                    function step(timestamp) {
                        if (!startTime) startTime = timestamp;
                        var progress = Math.min((timestamp - startTime) / duration, 1);
                        var eased = progress < 0.5 ? 2 * progress * progress : -1 + (4 - 2 * progress) * progress;
                        el.textContent = Math.floor(startVal + (target - startVal) * eased).toLocaleString('fa-IR') + suffix;
                        if (progress < 1) {
                            requestAnimationFrame(step);
                        } else {
                            el.textContent = target.toLocaleString('fa-IR') + suffix;
                        }
                    }
                    requestAnimationFrame(step);
                    countObserver.unobserve(el);
                }
            });
        }, { threshold: 0.3 });
        statNumbers.forEach(function(el) { countObserver.observe(el); });
    }

    // ============================================
    // TOAST NOTIFICATIONS
    // ============================================
    // Auto-dismiss flash messages after 5s
    var alerts = document.querySelectorAll('.alert[role="status"], .notice[role="status"]');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            alert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-8px)';
            setTimeout(function() {
                if (alert.parentNode) alert.parentNode.removeChild(alert);
            }, 500);
        }, 5000);
    });

    // ============================================
    // ACTIVE NAV LINK HIGHLIGHT (public site)
    // ============================================
    // PHP already sets class="active" per page+slug. JS fallback compares the
    // full query string too, so page.php?slug=about / classes / contact don't
    // all match each other.
    var currentLocation = window.location.pathname + window.location.search;
    document.querySelectorAll('.bottom-nav-item').forEach(function(item) {
        var href = item.getAttribute('href');
        if (!href || item.classList.contains('active')) return;
        var resolved = new URL(href, window.location.origin);
        var itemPath = resolved.pathname + resolved.search;
        if (currentLocation === itemPath) {
            item.classList.add('active');
        }
    });

    // ============================================
    // FORM SUBMIT LOADING STATES
    // ============================================
    document.querySelectorAll('form[data-loading]').forEach(function(form) {
        form.addEventListener('submit', function() {
            var btn = form.querySelector('[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.style.opacity = '0.7';
                var original = btn.textContent;
                btn.setAttribute('data-original', original);
            }
        });
    });

    // ============================================
    // THEME SWITCHER (Dark/Light mode)
    // ============================================
    var sunSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
    var moonSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>';

    function updateThemeIcon(theme) {
        var btn = document.getElementById('themeToggleBtn');
        if (btn) {
            btn.innerHTML = theme === 'dark' ? sunSvg : moonSvg;
            btn.setAttribute('aria-label', theme === 'dark' ? 'تغییر به حالت روشن' : 'تغییر به حالت تاریک');
            if (!prefersReducedMotion) {
                var icon = btn.querySelector('svg');
                if (icon) icon.style.animation = 'themeIconSpin 0.35s cubic-bezier(0.34, 1.56, 0.64, 1)';
            }
        }
    }

    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('roma_theme', theme);
        updateThemeIcon(theme);
    }

    var activeTheme = document.documentElement.getAttribute('data-theme') ||
        localStorage.getItem('roma_theme') ||
        (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    updateThemeIcon(activeTheme);

    var themeBtn = document.getElementById('themeToggleBtn');
    if (themeBtn) {
        themeBtn.addEventListener('click', function() {
            var current = document.documentElement.getAttribute('data-theme') || 'light';
            setTheme(current === 'dark' ? 'light' : 'dark');
        });
    }

    // ============================================
    // IMAGE LAZY LOAD FALLBACK
    // ============================================
    document.querySelectorAll('img[loading="lazy"]').forEach(function(img) {
        img.addEventListener('error', function() {
            this.style.visibility = 'hidden';
        });
    });
});

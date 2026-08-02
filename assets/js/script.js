document.documentElement.classList.add('js-enabled');

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

            // Touch gestures for mobile
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
                    if (diffX > 0) goNext();
                    else goPrev();
                    handleManualNav();
                }
            }, { passive: true });

            // Start autoplay
            startAutoPlay();
        }
    }

    // Header scroll effect
    var header = document.querySelector('.site-header');
    if (header) {
        var scrollHandler = function() {
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        };
        window.addEventListener('scroll', scrollHandler, { passive: true });
    }

    // Fade-in animation on scroll
    var fadeElements = document.querySelectorAll('.fade-in');
    if (fadeElements.length > 0 && 'IntersectionObserver' in window) {
        var fadeObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    fadeObserver.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        });

        fadeElements.forEach(function(element) {
            fadeObserver.observe(element);
        });
    }
    
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

    // Auto-focus first empty input in forms
    var firstInput = document.querySelector('form input:not([type="hidden"]):not([type="submit"])');
    if (firstInput && !firstInput.value) {
        firstInput.focus();
    }

    // Confirm before delete actions
    var deleteButtons = document.querySelectorAll('[data-confirm]');
    deleteButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            var message = button.getAttribute('data-confirm') || 'آیا از حذف این مورد اطمینان دارید؟';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });

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
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // ============================================
    // MOBILE BOTTOM NAVIGATION
    // ============================================

    // Add haptic-style touch feedback to bottom nav items
    var bottomNavItems = document.querySelectorAll('.mobile-bottom-nav .bottom-nav-item, .public-bottom-nav .bottom-nav-item');
    bottomNavItems.forEach(function(item) {
        item.addEventListener('touchstart', function() {
            this.style.transition = 'transform 0.1s ease';
        }, { passive: true });
        item.addEventListener('touchend', function() {
            var el = this;
            setTimeout(function() {
                el.style.transition = '';
            }, 150);
        }, { passive: true });
    });

    // Hide bottom nav on scroll down, show on scroll up (mobile only)
    var bottomNav = document.querySelector('.mobile-bottom-nav');
    if (bottomNav) {
        var lastScrollY = 0;
        var navHideThreshold = 10;
        var ticking = false;

        window.addEventListener('scroll', function() {
            if (!ticking) {
                window.requestAnimationFrame(function() {
                    var currentScrollY = window.scrollY;
                    var diff = currentScrollY - lastScrollY;

                    if (diff > navHideThreshold && currentScrollY > 200) {
                        bottomNav.style.transform = 'translateY(100%)';
                        bottomNav.style.transition = 'transform 0.3s cubic-bezier(0.4,0,0.2,1)';
                    } else if (diff < -navHideThreshold) {
                        bottomNav.style.transform = 'translateY(0)';
                        bottomNav.style.transition = 'transform 0.3s cubic-bezier(0.4,0,0.2,1)';
                    }

                    lastScrollY = currentScrollY;
                    ticking = false;
                });
                ticking = true;
            }
        }, { passive: true });
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
                    var duration = 1200;
                    var startTime = null;
                    var startVal = 0;
                    function step(timestamp) {
                        if (!startTime) startTime = timestamp;
                        var progress = Math.min((timestamp - startTime) / duration, 1);
                        var eased = progress < 0.5 ? 2 * progress * progress : -1 + (4 - 2 * progress) * progress;
                        el.textContent = Math.floor(startVal + (target - startVal) * eased).toLocaleString('fa-IR');
                        if (progress < 1) {
                            requestAnimationFrame(step);
                        } else {
                            el.textContent = target.toLocaleString('fa-IR');
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
    // Already set via PHP class="active", but ensure bottom nav matches
    var currentPath = window.location.pathname;
    document.querySelectorAll('.bottom-nav-item').forEach(function(item) {
        var href = item.getAttribute('href');
        if (href && currentPath.endsWith(href.split('?')[0])) {
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

    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('roma_theme', theme);
        var btn = document.getElementById('themeToggleBtn');
        if (btn) {
            btn.innerHTML = theme === 'dark' ? sunSvg : moonSvg;
            btn.setAttribute('aria-label', theme === 'dark' ? 'تغییر به حالت روشن' : 'تغییر به حالت تاریک');
        }
    }

    var savedTheme = localStorage.getItem('roma_theme') ||
        (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    setTheme(savedTheme);

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

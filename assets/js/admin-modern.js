/**
 * ROMA ADMIN MODERN SHELL CONTROLLER
 * Handles auto-responsive hover rail, pin state, mobile drawer & swipe gestures,
 * accordion submenus, floating table action popovers, and section scroll-spy.
 */
(function () {
  'use strict';

  const PIN_STORAGE_KEY = 'roma_admin_sidebar_pinned';
  const DESKTOP_BP = 1024;
  const desktopMQ = window.matchMedia(`(min-width: ${DESKTOP_BP}px)`);
  const desktopHoverMQ = window.matchMedia(`(min-width: ${DESKTOP_BP}px) and (any-hover: hover)`);

  document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('adminSidebar');
    if (sidebar && sidebar.hasAttribute('data-modern-sidebar')) {
      initModernSidebar(sidebar);
      initAccordionSubmenus(sidebar);
    }
    initUserMenuHover();
    initActionPopovers();
    initSectionNavSpy();
  });

  /* ── 1. AUTO-RESPONSIVE HOVER RAIL & MOBILE DRAWER ─────────────── */
  function initModernSidebar(sidebar) {
    const root = document.documentElement;
    const overlay = document.getElementById('adminOverlay');
    const mobileToggle = document.getElementById('mobileSidebarToggle');
    const dockMenuBtn = document.querySelector('[data-dock-menu-trigger]');
    const closeBtn = document.getElementById('adminSidebarClose');
    const pinBtn = document.getElementById('sidebarPinBtn');

    let isPinned = false;
    try {
      isPinned = localStorage.getItem(PIN_STORAGE_KEY) === '1';
    } catch (e) {}

    let isHoverOpen = false;
    let sidebarHovered = false;
    let toggleHovered = false;
    let hoverCloseTimer = null;
    let toggleHoverOpenTimer = null;
    let recentKeyboardEvent = false;

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Tab' || e.key.startsWith('Arrow')) {
        recentKeyboardEvent = true;
      }
    }, true);

    document.addEventListener('pointerdown', () => {
      recentKeyboardEvent = false;
    }, true);

    function applyPinState(pinned, persist = true) {
      isPinned = Boolean(pinned);
      root.setAttribute('data-sidebar-pinned', isPinned ? 'true' : 'false');
      if (pinBtn) {
        pinBtn.setAttribute('aria-pressed', isPinned ? 'true' : 'false');
        pinBtn.setAttribute('title', isPinned ? 'رهاسازی منو (حالت هوشمند)' : 'ثابت‌سازی منو');
        pinBtn.setAttribute('aria-label', isPinned ? 'رهاسازی منو' : 'ثابت‌سازی منو');
      }
      if (persist) {
        try {
          localStorage.setItem(PIN_STORAGE_KEY, isPinned ? '1' : '0');
        } catch (e) {}
      }
      syncAriaAndInert();
    }

    function setHoverOpen(open) {
      if (!desktopMQ.matches) {
        open = false;
      }
      isHoverOpen = Boolean(open);
      root.classList.toggle('adm-sidebar-hover', isHoverOpen);
      sidebar.classList.toggle('is-hover-open', isHoverOpen);
      syncAriaAndInert();
    }

    function clearHoverTimer() {
      if (hoverCloseTimer !== null) {
        clearTimeout(hoverCloseTimer);
        hoverCloseTimer = null;
      }
    }

    function clearToggleOpenTimer() {
      if (toggleHoverOpenTimer !== null) {
        clearTimeout(toggleHoverOpenTimer);
        toggleHoverOpenTimer = null;
      }
    }

    function scheduleHoverClose() {
      clearHoverTimer();
      hoverCloseTimer = window.setTimeout(() => {
        if (sidebarHovered || toggleHovered || isPinned) return;
        if (sidebar.matches(':hover') || (mobileToggle && mobileToggle.matches(':hover'))) return;
        const active = document.activeElement;
        if (active && sidebar.contains(active)) {
          let hasVisibleFocus = false;
          try {
            hasVisibleFocus = active.matches(':focus-visible');
          } catch (err) {}
          if (recentKeyboardEvent || hasVisibleFocus) return;
          if (typeof active.blur === 'function') active.blur();
        }
        setHoverOpen(false);
      }, 180);
    }

    function isMobileDrawerOpen() {
      return !desktopMQ.matches && sidebar.classList.contains('active');
    }

    function syncAriaAndInert() {
      const desktop = desktopMQ.matches;
      const mobileOpen = !desktop && sidebar.classList.contains('active');
      sidebar.inert = !desktop && !mobileOpen;

      const expandedDesktop = desktop && (isPinned || isHoverOpen);
      const expanded = desktop ? expandedDesktop : mobileOpen;

      if (mobileToggle) {
        mobileToggle.setAttribute('aria-controls', 'adminSidebar');
        mobileToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      }
      if (dockMenuBtn) {
        dockMenuBtn.setAttribute('aria-expanded', mobileOpen ? 'true' : 'false');
      }
    }

    function setMobileDrawer(open) {
      if (desktopMQ.matches) {
        sidebar.classList.remove('active');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
        syncAriaAndInert();
        return;
      }
      const wasOpen = sidebar.classList.contains('active');
      const next = Boolean(open);
      sidebar.classList.toggle('active', next);
      if (overlay) overlay.classList.toggle('active', next);
      document.body.style.overflow = next ? 'hidden' : '';
      syncAriaAndInert();

      if (next && !wasOpen) {
        const firstFocusable = sidebar.querySelector('a[href], button:not([disabled])');
        if (firstFocusable) firstFocusable.focus({ preventScroll: true });
      } else if (!next && wasOpen && mobileToggle) {
        mobileToggle.focus({ preventScroll: true });
      }
    }

    // Override unified toggleMobileSidebar with desktop-pin + mobile-drawer support
    window.toggleMobileSidebar = function (evtOrState) {
      if (evtOrState && typeof evtOrState === 'object' && typeof evtOrState.stopPropagation === 'function') {
        evtOrState.stopPropagation();
      }
      const forceState = typeof evtOrState === 'boolean' ? evtOrState : null;

      if (desktopMQ.matches) {
        clearToggleOpenTimer();
        clearHoverTimer();
        const nextPin = forceState !== null ? forceState : !isPinned;
        applyPinState(nextPin, true);
        if (!nextPin) {
          sidebarHovered = false;
          toggleHovered = false;
          setHoverOpen(false);
        }
        return;
      }

      const nextMobile = forceState !== null ? forceState : !sidebar.classList.contains('active');
      setMobileDrawer(nextMobile);
    };

    // Initial state
    applyPinState(isPinned, false);
    setHoverOpen(false);
    setMobileDrawer(false);

    // Desktop hover triggers (ignoring touch taps)
    sidebar.addEventListener('pointerenter', (e) => {
      if (e.pointerType === 'touch' || !desktopHoverMQ.matches) return;
      sidebarHovered = true;
      clearHoverTimer();
      clearToggleOpenTimer();
      if (!isPinned) setHoverOpen(true);
    });

    sidebar.addEventListener('pointerleave', (e) => {
      if (e.pointerType === 'touch' || !desktopHoverMQ.matches) return;
      sidebarHovered = false;
      scheduleHoverClose();
    });

    if (mobileToggle) {
      mobileToggle.addEventListener('pointerenter', (e) => {
        if (e.pointerType === 'touch' || !desktopHoverMQ.matches) return;
        toggleHovered = true;
        clearHoverTimer();
        clearToggleOpenTimer();
        if (!isPinned) {
          toggleHoverOpenTimer = window.setTimeout(() => {
            if (toggleHovered && !isPinned) setHoverOpen(true);
          }, 110);
        }
      });

      mobileToggle.addEventListener('pointerleave', (e) => {
        if (e.pointerType === 'touch' || !desktopHoverMQ.matches) return;
        toggleHovered = false;
        clearToggleOpenTimer();
        scheduleHoverClose();
      });

      if (!mobileToggle.hasAttribute('onclick')) {
        mobileToggle.addEventListener('click', (e) => {
          e.preventDefault();
          window.toggleMobileSidebar(e);
        });
      }
    }

    document.documentElement.addEventListener('pointerleave', (e) => {
      if (e.pointerType === 'touch' || !desktopHoverMQ.matches) return;
      if (isHoverOpen && !isPinned) {
        sidebarHovered = false;
        toggleHovered = false;
        clearToggleOpenTimer();
        scheduleHoverClose();
      }
    });

    // Keyboard accessibility inside sidebar on desktop
    sidebar.addEventListener('focusin', (e) => {
      if (!desktopMQ.matches || isPinned) return;
      let hasVisibleFocus = false;
      try {
        hasVisibleFocus = Boolean(e.target && e.target.matches(':focus-visible'));
      } catch (err) {}
      if (recentKeyboardEvent || hasVisibleFocus) {
        clearHoverTimer();
        setHoverOpen(true);
      }
    });

    sidebar.addEventListener('focusout', () => {
      if (!desktopMQ.matches || isPinned) return;
      window.setTimeout(() => {
        if (!sidebar.contains(document.activeElement) && !sidebarHovered && !toggleHovered) {
          setHoverOpen(false);
        }
      }, 40);
    });

    if (pinBtn) {
      pinBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const nextPin = !isPinned;
        applyPinState(nextPin, true);
        if (!nextPin) {
          sidebarHovered = sidebar.matches(':hover');
          setHoverOpen(sidebarHovered);
        } else {
          setHoverOpen(false);
        }
      });
    }

    if (closeBtn) {
      closeBtn.addEventListener('click', (e) => {
        e.preventDefault();
        setMobileDrawer(false);
      });
    }

    if (overlay) {
      overlay.addEventListener('click', (e) => {
        e.preventDefault();
        setMobileDrawer(false);
      });
    }

    // Close mobile drawer when clicking leaf nav links
    sidebar.addEventListener('click', (e) => {
      const link = e.target.closest('a.admin-nav-item, a.admin-nav-subitem');
      if (link && !desktopMQ.matches) {
        setMobileDrawer(false);
      }
    });

    // RTL swipe-right to close mobile drawer
    let touchStartX = 0;
    let touchStartY = 0;
    sidebar.addEventListener('touchstart', (e) => {
      if (desktopMQ.matches || !e.changedTouches || !e.changedTouches[0]) return;
      touchStartX = e.changedTouches[0].clientX;
      touchStartY = e.changedTouches[0].clientY;
    }, { passive: true });

    sidebar.addEventListener('touchend', (e) => {
      if (desktopMQ.matches || !sidebar.classList.contains('active') || !e.changedTouches || !e.changedTouches[0]) return;
      const dx = e.changedTouches[0].clientX - touchStartX;
      const dy = e.changedTouches[0].clientY - touchStartY;
      if (dx > 64 && Math.abs(dx) > Math.abs(dy) * 1.2) {
        setMobileDrawer(false);
      }
    }, { passive: true });

    // Escape & Tab focus trap
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        if (isMobileDrawerOpen()) {
          setMobileDrawer(false);
        } else if (desktopMQ.matches && isHoverOpen && !isPinned) {
          sidebarHovered = false;
          toggleHovered = false;
          setHoverOpen(false);
          if (sidebar.contains(document.activeElement) && mobileToggle) {
            mobileToggle.focus({ preventScroll: true });
          }
        }
      }
    });

    sidebar.addEventListener('keydown', (e) => {
      if (e.key !== 'Tab' || !isMobileDrawerOpen()) return;
      const focusables = [...sidebar.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled])')]
        .filter((el) => el.getClientRects().length > 0);
      if (!focusables.length) return;
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    });

    // Sync cleanly across viewport transitions
    const onBreakpointChange = () => {
      clearHoverTimer();
      sidebarHovered = false;
      toggleHovered = false;
      if (desktopMQ.matches) {
        sidebar.classList.remove('active');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
        setHoverOpen(false);
      } else {
        setHoverOpen(false);
      }
      syncAriaAndInert();
    };

    if (typeof desktopMQ.addEventListener === 'function') {
      desktopMQ.addEventListener('change', onBreakpointChange);
    } else {
      window.addEventListener('resize', onBreakpointChange);
    }

    window.addEventListener('pageshow', (e) => {
      if (e.persisted) {
        setMobileDrawer(false);
        setHoverOpen(false);
      }
    });
  }

  /* ── 2. SMOOTH ACCORDION SUBMENUS ─────────────────────────────── */
  function initAccordionSubmenus(sidebar) {
    const parents = [...sidebar.querySelectorAll('.admin-nav-parent[data-submenu]')];
    if (!parents.length) return;

    function setGroupOpen(parentBtn, open) {
      const id = parentBtn.getAttribute('data-submenu');
      const sub = id ? document.getElementById('submenu-' + id) : null;
      if (!sub) return;
      parentBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      sub.classList.toggle('open', open);
    }

    parents.forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const currentlyOpen = btn.getAttribute('aria-expanded') === 'true';
        const nextOpen = !currentlyOpen;

        if (nextOpen) {
          parents.forEach((other) => {
            if (other !== btn) setGroupOpen(other, false);
          });
        }
        setGroupOpen(btn, nextOpen);
      });
    });
  }

  /* ── 3. TOPBAR USER MENU DESKTOP HOVER POLISH ────────────────── */
  function initUserMenuHover() {
    const container = document.querySelector('body.admin-layout .app-user-menu');
    const trigger = document.getElementById('appUserTrigger');
    const menu = document.getElementById('appUserMenu');
    if (!container || !trigger || !menu) return;

    let openTimer = null;
    let closeTimer = null;
    let openedByHover = false;

    function clearTimers() {
      if (openTimer !== null) {
        clearTimeout(openTimer);
        openTimer = null;
      }
      if (closeTimer !== null) {
        clearTimeout(closeTimer);
        closeTimer = null;
      }
    }

    // Prevent hover-open from colliding with an explicit click on #appUserTrigger
    trigger.addEventListener('click', (e) => {
      if (openTimer !== null) {
        clearTimeout(openTimer);
        openTimer = null;
      }
      if (openedByHover && menu.classList.contains('show')) {
        e.stopImmediatePropagation();
        e.preventDefault();
        openedByHover = false;
        return;
      }
      openedByHover = false;
    }, true);

    container.addEventListener('pointerenter', (e) => {
      if (e.pointerType === 'touch' || !desktopHoverMQ.matches) return;
      clearTimers();
      if (menu.classList.contains('show')) return;
      openTimer = window.setTimeout(() => {
        if (!menu.classList.contains('show')) {
          menu.classList.add('show');
          trigger.setAttribute('aria-expanded', 'true');
          openedByHover = true;
        }
      }, 140);
    });

    container.addEventListener('pointerleave', (e) => {
      if (e.pointerType === 'touch' || !desktopHoverMQ.matches) return;
      if (openTimer !== null) {
        clearTimeout(openTimer);
        openTimer = null;
      }
      if (!openedByHover) return;
      closeTimer = window.setTimeout(() => {
        if (openedByHover) {
          menu.classList.remove('show');
          trigger.setAttribute('aria-expanded', 'false');
          openedByHover = false;
        }
      }, 200);
    });
  }

  /* ── 4. FLOATING ROW ACTION POPOVERS (.adm-action-menu) ────────── */
  function initActionPopovers() {
    let activeMenu = null;

    function closeAllPopovers() {
      if (!activeMenu) return;
      const { trigger, popover } = activeMenu;
      popover.classList.remove('is-open');
      popover.style.top = '';
      popover.style.left = '';
      trigger.setAttribute('aria-expanded', 'false');
      activeMenu = null;
    }

    function positionPopover(trigger, popover) {
      popover.style.visibility = 'hidden';
      popover.classList.add('is-open');

      const tRect = trigger.getBoundingClientRect();
      const pRect = popover.getBoundingClientRect();
      const margin = 10;
      const vw = window.innerWidth;
      const vh = window.innerHeight;

      const spaceBelow = vh - tRect.bottom - margin;
      const spaceAbove = tRect.top - margin;
      let top = tRect.bottom + 6;
      if (spaceBelow < pRect.height && spaceAbove > spaceBelow) {
        top = Math.max(margin, tRect.top - pRect.height - 6);
      }

      // Align inline-end edge of popover with trigger in RTL (left-aligned with trigger's left)
      let left = tRect.left;
      if (left + pRect.width > vw - margin) {
        left = vw - pRect.width - margin;
      }
      if (left < margin) {
        left = margin;
      }

      popover.style.top = `${Math.round(top)}px`;
      popover.style.left = `${Math.round(left)}px`;
      popover.style.visibility = '';
    }

    document.addEventListener('click', (e) => {
      const trigger = e.target.closest?.('.adm-action-trigger');
      if (trigger) {
        e.preventDefault();
        e.stopPropagation();
        const menu = trigger.closest('.adm-action-menu');
        const popover = menu?.querySelector('.adm-action-popover');
        if (!popover) return;

        const wasOpen = popover.classList.contains('is-open');
        closeAllPopovers();
        if (!wasOpen) {
          positionPopover(trigger, popover);
          trigger.setAttribute('aria-expanded', 'true');
          activeMenu = { trigger, popover };
        }
        return;
      }

      if (activeMenu && !e.target.closest?.('.adm-action-popover')) {
        closeAllPopovers();
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && activeMenu) {
        const prevTrigger = activeMenu.trigger;
        closeAllPopovers();
        prevTrigger.focus({ preventScroll: true });
      }
    });

    window.addEventListener('scroll', closeAllPopovers, { passive: true, capture: true });
    window.addEventListener('resize', closeAllPopovers, { passive: true });
  }

  /* ── 5. IN-PAGE SECTION SUB-NAV SCROLL SPY ────────────────────── */
  function initSectionNavSpy() {
    const nav = document.querySelector('.adm-section-nav');
    if (!nav) return;
    const links = [...nav.querySelectorAll('.adm-section-nav-link[href^="#"]')];
    if (!links.length) return;

    const pairs = links
      .map((link) => {
        const id = link.getAttribute('href').slice(1);
        const section = id ? document.getElementById(id) : null;
        return section ? { link, section } : null;
      })
      .filter(Boolean);

    if (!pairs.length) return;

    function setActiveLink(targetLink) {
      links.forEach((l) => l.classList.toggle('is-active', l === targetLink));
    }

    links.forEach((link) => {
      link.addEventListener('click', () => setActiveLink(link));
    });

    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver(
        (entries) => {
          const visible = entries
            .filter((entry) => entry.isIntersecting)
            .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
          if (visible.length) {
            const match = pairs.find((p) => p.section === visible[0].target);
            if (match) setActiveLink(match.link);
          }
        },
        { rootMargin: '-25% 0px -60% 0px', threshold: 0.01 }
      );
      pairs.forEach(({ section }) => io.observe(section));
    }
  }
})();

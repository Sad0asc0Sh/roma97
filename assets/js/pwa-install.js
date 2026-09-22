/**
 * Roma PWA install helper — parent portal + public site.
 * - Registers the service worker (URL injected via window.ROMA_SW_URL).
 * - Captures beforeinstallprompt and wires every [data-pwa-install] button.
 * - Shows a dismissible install banner + a platform-aware guide modal.
 * - iOS Safari has no prompt event: the guide explains Share > Add to Home Screen.
 * No dependencies. RTL / Persian UI.
 */
(function () {
  'use strict';

  var SW_URL = window.ROMA_SW_URL || 'sw.js';
  var DISMISS_KEY = 'roma_pwa_banner_dismissed';
  var INSTALLED_KEY = 'roma_pwa_installed';

  var deferredPrompt = null;
  var promptAvailable = false;

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function isStandalone() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
      window.navigator.standalone === true ||
      document.referrer.indexOf('android-app://') === 0;
  }

  function isIOS() {
    return /iphone|ipad|ipod/i.test(window.navigator.userAgent) ||
      (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);
  }

  function isAndroid() { return /android/i.test(window.navigator.userAgent); }

  function markInstalled() {
    try { localStorage.setItem(INSTALLED_KEY, '1'); } catch (e) {}
    document.documentElement.classList.add('pwa-installed');
    hideBanner();
    $all('[data-pwa-install]').forEach(function (btn) {
      btn.classList.add('is-installed');
      var label = btn.querySelector('[data-pwa-install-label]');
      if (label) label.textContent = 'اپ نصب شد';
      btn.setAttribute('disabled', 'disabled');
    });
  }

  /* ── Service worker ─────────────────────────────────────── */
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(SW_URL, { scope: './' }).catch(function () {
        // Registration may fail on plain HTTP subpaths in old browsers — PWA
        // features degrade gracefully; the guide modal still helps.
      });
    });
  }

  /* ── Native prompt capture ──────────────────────────────── */
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    promptAvailable = true;
    document.documentElement.classList.add('pwa-prompt-ready');
    $all('[data-pwa-install]').forEach(function (btn) { btn.classList.add('is-ready'); });
    maybeShowBanner();
  });

  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    promptAvailable = false;
    markInstalled();
  });

  /* ── Install action ─────────────────────────────────────── */
  function openGuide() {
    var modal = $('#pwaGuideModal');
    if (!modal) return;
    // Pick the right tab for this device.
    var platform = isIOS() ? 'ios' : (isAndroid() ? 'android' : 'desktop');
    $all('[data-pwa-tab]', modal).forEach(function (t) {
      t.classList.toggle('active', t.getAttribute('data-pwa-tab') === platform);
    });
    $all('[data-pwa-pane]', modal).forEach(function (p) {
      p.hidden = p.getAttribute('data-pwa-pane') !== platform;
    });
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeGuide() {
    var modal = $('#pwaGuideModal');
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  function triggerInstall() {
    if (isStandalone()) return;
    if (deferredPrompt) {
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then(function (choice) {
        if (choice && choice.outcome === 'accepted') markInstalled();
        deferredPrompt = null;
        promptAvailable = false;
      }).catch(function () {});
      return;
    }
    openGuide();
  }

  document.addEventListener('click', function (e) {
    var installBtn = e.target.closest('[data-pwa-install]');
    if (installBtn) { e.preventDefault(); triggerInstall(); return; }
    if (e.target.closest('[data-pwa-guide]')) { e.preventDefault(); openGuide(); return; }
    if (e.target.closest('[data-pwa-close]')) { e.preventDefault(); closeGuide(); return; }
    if (e.target.closest('[data-pwa-dismiss]')) {
      e.preventDefault();
      try { localStorage.setItem(DISMISS_KEY, Date.now().toString()); } catch (err) {}
      hideBanner();
      return;
    }
    // Guide tabs
    var tab = e.target.closest('[data-pwa-tab]');
    if (tab) {
      e.preventDefault();
      var modal = tab.closest('#pwaGuideModal');
      var name = tab.getAttribute('data-pwa-tab');
      $all('[data-pwa-tab]', modal).forEach(function (t) {
        t.classList.toggle('active', t === tab);
      });
      $all('[data-pwa-pane]', modal).forEach(function (p) {
        p.hidden = p.getAttribute('data-pwa-pane') !== name;
      });
      return;
    }
    // Click on modal backdrop closes
    if (e.target.classList && e.target.classList.contains('pwa-modal-backdrop')) closeGuide();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeGuide(); hideBanner(); }
  });

  /* ── Banner ─────────────────────────────────────────────── */
  /* Dismissal is permanent: once the parent closes the install prompt,
     it never appears again — neither the banner nor the dashboard card.
     Install stays reachable via the header «نصب اپ» button and the
     user-menu guide entry. */
  function isDismissed() {
    try { return localStorage.getItem(DISMISS_KEY) !== null; }
    catch (e) { return false; }
  }

  function hideBanner() {
    var banner = $('#pwaInstallBanner');
    if (banner) banner.classList.remove('show');
  }

  function maybeShowBanner() {
    if (isStandalone()) return;
    var banner = $('#pwaInstallBanner');
    if (!banner) return;
    try {
      if (localStorage.getItem(INSTALLED_KEY) === '1') return;
    } catch (e) {}
    if (isDismissed()) return;
    // Show when: native prompt is ready, OR iOS (manual steps), OR a guide
    // entry point exists on touch devices. Desktop Chrome without prompt yet
    // stays quiet to avoid nagging.
    var touch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
    if (promptAvailable || isIOS() || (touch && isAndroid())) {
      // Small delay so it never fights the page entrance animation.
      setTimeout(function () { banner.classList.add('show'); }, 1200);
    }
  }

  /* ── Theme toggle (unified parent header) ───────────────── */
  function initThemeToggle() {
    var btns = $all('[data-theme-toggle-btn]');
    if (!btns.length) return;
    function paint() {
      var dark = document.documentElement.getAttribute('data-theme') === 'dark';
      btns.forEach(function (b) { b.setAttribute('aria-pressed', dark ? 'true' : 'false'); });
    }
    btns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var cur = document.documentElement.getAttribute('data-theme') || 'light';
        var next = cur === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('roma_theme', next); } catch (e) {}
        paint();
      });
    });
    paint();
  }

  /* ── User dropdown (unified parent header) ──────────────── */
  function initUserMenu() {
    var trigger = $('#parentUserTrigger');
    var menu = $('#parentUserMenu');
    if (!trigger || !menu) return;
    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      menu.classList.toggle('show');
      trigger.setAttribute('aria-expanded', menu.classList.contains('show') ? 'true' : 'false');
    });
    document.addEventListener('click', function () {
      menu.classList.remove('show');
      trigger.setAttribute('aria-expanded', 'false');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') menu.classList.remove('show');
    });
  }

  /* ── Dismissible dashboard install card ─────────────────── */
  function initInstallCard() {
    var card = $('#pwaDashboardCard');
    if (!card) return;
    // Dismissed once = gone forever from the dashboard (frees the space).
    var gone = isStandalone() || isDismissed();
    try {
      gone = gone || localStorage.getItem(INSTALLED_KEY) === '1';
    } catch (e) {}
    if (gone) { card.hidden = true; return; }
    var hide = card.querySelector('[data-pwa-card-hide]');
    if (hide) {
      hide.addEventListener('click', function () {
        card.hidden = true;
        try { localStorage.setItem(DISMISS_KEY, Date.now().toString()); } catch (err) {}
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (isStandalone()) {
      document.documentElement.classList.add('pwa-installed');
      try { localStorage.setItem(INSTALLED_KEY, '1'); } catch (e) {}
    }
    initThemeToggle();
    initUserMenu();
    initInstallCard();
    maybeShowBanner();
  });
})();

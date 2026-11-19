/**
 * ROMA UNIFIED APP SHELL JS
 * Provides interactive behavior for enterprise app components across Admin, Teacher, and Parent portals.
 */

document.addEventListener('DOMContentLoaded', () => {
  initSidebarToggle();
  /* initUserDropdown is intentionally NOT in this list: it wires itself
     immediately at the bottom of its section (delegated listeners), so
     the topbar user menu can never be left dead by load timing. */
  initGlobalSearchKeybind();
  initTabs();
  initConfirmDialogs();
  initDrawers();
  initShamsiDatePicker();
});

/* ── BFCACHE SAFEGUARD ───────────────────────────────────────
   When a page is restored from bfcache (Back/Forward navigation),
   slide-in panels (mobile sidebar, drawer) may be stuck open from the
   previous visit. Force them closed unless there's a legitimate reason
   (e.g. ?edit= auto-open on list pages). */
window.addEventListener('pageshow', (e) => {
  if (!e.persisted) return;
  try {
    if (typeof window.toggleMobileSidebar === 'function') {
      window.toggleMobileSidebar(false);
    }
    const url = new URL(window.location.href);
    if (!url.searchParams.has('edit') && typeof window.closeDrawer === 'function') {
      window.closeDrawer();
    }
  } catch (err) {}
});

/* ── SIDEBAR TOGGLE & COLLAPSE ─────────────────────────────── */
function initSidebarToggle() {
  const sidebar = document.getElementById('adminSidebar');
  const sidebarToggle = document.getElementById('sidebarToggle');
  const mobileToggle = document.getElementById('mobileSidebarToggle');
  const overlay = document.getElementById('adminOverlay');

  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', () => {
      sidebar.classList.toggle('collapsed');
      try { localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed') ? '1' : '0'); } catch (e) {}
    });

    try { if (localStorage.getItem('sidebar_collapsed') === '1') sidebar.classList.add('collapsed'); } catch (e) {}
  }

  if (sidebar && overlay) {
    if (mobileToggle && !mobileToggle.hasAttribute('onclick')) {
      mobileToggle.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (typeof window.toggleMobileSidebar === 'function') {
          window.toggleMobileSidebar(e);
        }
      });
    }

    overlay.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (typeof window.toggleMobileSidebar === 'function') {
        window.toggleMobileSidebar(false);
      }
    });

    // Auto-close mobile sidebar when clicking menu links
    const navLinks = sidebar.querySelectorAll('.admin-nav-item:not(.admin-nav-parent), .admin-nav-subitem');
    navLinks.forEach(link => {
      link.addEventListener('click', () => {
        if (window.innerWidth <= 991 && typeof window.toggleMobileSidebar === 'function') {
          window.toggleMobileSidebar(false);
        }
      });
    });
  }

  // Submenus
  const activeSubitem = document.querySelector('.admin-nav-submenu .admin-nav-subitem.active');
  if (activeSubitem) {
    const parentSubmenu = activeSubitem.closest('.admin-nav-submenu');
    if (parentSubmenu) {
      parentSubmenu.classList.add('open', 'active');
      const parentButton = parentSubmenu.previousElementSibling;
      if (parentButton && parentButton.classList.contains('admin-nav-parent')) {
        parentButton.setAttribute('aria-expanded', 'true');
        parentButton.classList.add('active');
      }
    }
  }

  // Submenu parents without an inline onclick handler fall back to
  // data-submenu driven toggling (avoids double-toggle with header.php's
  // toggleAdminSubmenu which uses onclick).
  const parentItems = document.querySelectorAll('.admin-nav-parent:not([onclick])');
  parentItems.forEach(item => {
    if (!item.dataset.submenu) return;
    item.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const targetId = 'submenu-' + item.dataset.submenu;
      const targetSubmenu = document.getElementById(targetId);
      const isExpanded = item.getAttribute('aria-expanded') === 'true';

      if (targetSubmenu) {
        const nextState = !isExpanded;
        item.setAttribute('aria-expanded', nextState ? 'true' : 'false');
        if (nextState) {
          targetSubmenu.classList.add('open', 'active');
        } else {
          targetSubmenu.classList.remove('open', 'active');
        }
      }
    });
  });
}

/* ── USER DROPDOWN MENU ────────────────────────────────────── */
/* Handled by ONE delegated document listener registered immediately,
   not inside DOMContentLoaded: when this file executes after that
   event has already fired (async load, service-worker cache timing,
   template order), a DOMContentLoaded-only wiring never runs and the
   trigger looks completely dead — no error, no menu. Delegation also
   means the elements are looked up at click time, so it works no
   matter where the topbar markup sits, and the guard below makes a
   duplicate include of this file harmless (it can never double-toggle). */
function appUserMenuEls() {
  return {
    trigger: document.getElementById('appUserTrigger'),
    menu: document.getElementById('appUserMenu')
  };
}

function setAppUserMenu(open) {
  const els = appUserMenuEls();
  if (!els.trigger || !els.menu) return;
  els.menu.classList.toggle('show', open);
  els.trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function handleAppUserMenuClick(e) {
  if (!e.target || typeof e.target.closest !== 'function') return;
  const els = appUserMenuEls();
  if (!els.trigger || !els.menu) return;

  if (e.target.closest('#appUserTrigger')) {
    e.preventDefault();
    setAppUserMenu(!els.menu.classList.contains('show'));
    return;
  }
  if (e.target.closest('#appUserMenu')) {
    /* Choosing an item navigates / submits logout — close behind it.
       Clicks on the menu head or dividers keep it open. */
    if (e.target.closest('a, button')) setAppUserMenu(false);
    return;
  }
  setAppUserMenu(false);
}

function handleAppUserMenuKeydown(e) {
  if (e.key !== 'Escape') return;
  const els = appUserMenuEls();
  if (els.menu && els.menu.classList.contains('show')) {
    setAppUserMenu(false);
    if (els.trigger) els.trigger.focus();
  }
}

function initUserDropdown() {
  if (window.__romaAppUserMenuWired) return;
  window.__romaAppUserMenuWired = true;
  document.addEventListener('click', handleAppUserMenuClick);
  document.addEventListener('keydown', handleAppUserMenuKeydown);
  window.addEventListener('pageshow', (e) => {
    if (e.persisted) setAppUserMenu(false);
  });
}

initUserDropdown();

/* ── GLOBAL SEARCH KEYBOARD SHORTCUT (/) & LIVE CLIENT FILTER ──── */
function initGlobalSearchKeybind() {
  const input = document.getElementById('appSearchInput');
  const main = document.querySelector('main');
  if (!input || !main) return;

  const shell = input.closest('[data-search-shell]') || input.closest('.app-search-box, .tp-search-wrap');
  const toggleBtn = shell?.querySelector('[data-search-toggle]') || null;
  const closeBtn = shell?.querySelector('[data-search-close]') || null;
  const isDirectorySearch = Boolean(input.form?.hasAttribute('data-directory-search'));

  let items = [];
  if (!isDirectorySearch) {
    // Pages with a dedicated register filter should have only one search owner.
    const ownSearch = main.querySelector('input[type="search"], #attendanceSearch, #reportSearch, #childSearch');
    if (ownSearch) {
      shell?.setAttribute('hidden', '');
      return;
    }
    const selector = '.app-table tbody tr, .admin-table tbody tr, .payments-table tbody tr, .attendance-table tbody tr, .admin-child-card, .child-card-large, .child-list-card, .event-card, .admin-event-card, .quick-list-item, .tp-child-card, .tp-att-row, .tp-report-card, .tp-msg-item, .portal-row';
    items = [...main.querySelectorAll(selector)];
    if (!items.length) {
      shell?.setAttribute('hidden', '');
      return;
    }
    input.placeholder = 'جست‌وجو در همین صفحه…';
  }

  const setSearchOpen = (open, { focus = false, clear = false } = {}) => {
    if (!shell) return;
    if (clear && input.value !== '') {
      input.value = '';
      input.dispatchEvent(new Event('input'));
    }
    shell.classList.toggle('is-open', open);
    if (toggleBtn) {
      toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (open && focus) {
      input.focus();
    } else if (!open && focus && toggleBtn) {
      toggleBtn.focus();
    }
  };

  if (toggleBtn) {
    toggleBtn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      setSearchOpen(true, { focus: true });
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      setSearchOpen(false, { focus: true, clear: true });
    });
  }

  if (shell) {
    document.addEventListener('click', (event) => {
      if (!shell.classList.contains('is-open')) return;
      if (event.target.closest?.('[data-search-shell]') === shell) return;
      if (input.value.trim() === '') {
        setSearchOpen(false);
      }
    });

    window.addEventListener('pageshow', (event) => {
      if (event.persisted && input.value.trim() === '') {
        setSearchOpen(false);
      }
    });
  }

  document.addEventListener('keydown', (event) => {
    const shellVisible = shell ? !shell.hidden && shell.getClientRects().length > 0 : input.getClientRects().length > 0;
    if ((event.key === '/' || ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k'))
        && !event.target.closest?.('input,textarea,select,[contenteditable]') && shellVisible) {
      event.preventDefault();
      if (shell && toggleBtn) {
        setSearchOpen(true, { focus: true });
      } else {
        input.focus();
      }
      return;
    }
    if (event.key === 'Escape' && (document.activeElement === input || shell?.contains(document.activeElement))) {
      event.preventDefault();
      if (shell && toggleBtn) {
        setSearchOpen(false, { focus: true, clear: true });
      } else {
        input.value = '';
        input.dispatchEvent(new Event('input'));
      }
    }
  });

  if (isDirectorySearch) return;
  const normalize = value => value.toLowerCase().replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[\u200c\u200f]/g,' ').replace(/[۰-۹]/g,c=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(c)).replace(/\s+/g,' ').trim();
  const notice = document.createElement('p');
  notice.className = 'portal-search-empty';
  notice.setAttribute('role','status');
  notice.hidden = true;
  main.prepend(notice);
  input.addEventListener('input', () => {
    const q = normalize(input.value);
    let visible = 0;
    items.forEach(item => {
      const match = !q || normalize(item.textContent || '').includes(q);
      item.classList.toggle('portal-filter-hidden', !match);
      if(match) visible++;
    });
    notice.hidden = !q || visible > 0;
    notice.textContent = 'نتیجه‌ای در این صفحه پیدا نشد. عبارت جست‌وجو را تغییر دهید.';
  });
}

/* ── TABS ─────────────────────────────────────────────────── */
function initTabs() {
  const tabContainers = document.querySelectorAll('.app-tabs');
  tabContainers.forEach(container => {
    const tabs = container.querySelectorAll('.app-tab-btn');
    tabs.forEach(tab => {
      tab.addEventListener('click', () => {
        const targetId = tab.dataset.target;
        tabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');

        if (targetId) {
          const contents = container.parentElement.querySelectorAll('.app-tab-content');
          contents.forEach(content => {
            if (content.id === targetId) {
              content.style.display = 'block';
              content.style.animation = 'fadeUp 0.3s ease-out';
            } else {
              content.style.display = 'none';
            }
          });
        }
      });
    });
  });
}

/* ── CUSTOM CONFIRM DIALOG ─────────────────────────────────── */
let activeConfirmForm = null;

function initConfirmDialogs() {
  const dialogOverlay = document.getElementById('appConfirmOverlay');
  const cancelBtn = document.getElementById('appConfirmCancel');
  const proceedBtn = document.getElementById('appConfirmProceed');

  if (!dialogOverlay || !cancelBtn || !proceedBtn) return;

  cancelBtn.addEventListener('click', closeConfirmModal);
  dialogOverlay.addEventListener('click', (e) => {
    if (e.target === dialogOverlay) closeConfirmModal();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeConfirmModal();
  });

  proceedBtn.addEventListener('click', () => {
    if (activeConfirmForm) {
      activeConfirmForm.submit();
      activeConfirmForm = null;
    }
    closeConfirmModal();
  });

  // Intercept confirm clicks/forms with data-confirm or onsubmit confirm
  document.addEventListener('submit', (e) => {
    const form = e.target;
    const confirmMsg = form.dataset.confirm;
    if (confirmMsg) {
      e.preventDefault();
      openConfirmModal(form, confirmMsg);
    }
  });
}

window.confirmAction = function(formOrElement, message, itemName) {
  const finalMsg = itemName ? `آیا از انجام این عملیات روی «${itemName}» اطمینان دارید؟` : message;
  openConfirmModal(formOrElement, finalMsg);
};

function openConfirmModal(formOrElement, message) {
  const dialogOverlay = document.getElementById('appConfirmOverlay');
  const msgEl = document.getElementById('appConfirmMessage');
  if (!dialogOverlay || !msgEl) return;

  activeConfirmForm = formOrElement;
  msgEl.textContent = message || 'آیا از انجام این عملیات اطمینان دارید؟';
  dialogOverlay.classList.add('active');
}

function closeConfirmModal() {
  const dialogOverlay = document.getElementById('appConfirmOverlay');
  if (dialogOverlay) dialogOverlay.classList.remove('active');
  activeConfirmForm = null;
}

/* ── DRAWER COMPONENT ─────────────────────────────────────── */
function initDrawers() {
  const overlay = document.getElementById('appDrawerOverlay');
  const drawer = document.getElementById('appDrawer');
  const closeBtn = document.getElementById('appDrawerClose');

  if (!overlay || !drawer) return;

  if (closeBtn) {
    closeBtn.addEventListener('click', window.closeDrawer);
  }
  overlay.addEventListener('click', window.closeDrawer);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      window.closeDrawer();
    }
  });
}

window.openDrawer = function(title, contentHtml) {
  const overlay = document.getElementById('appDrawerOverlay');
  const drawer = document.getElementById('appDrawer');
  const titleEl = document.getElementById('appDrawerTitle');
  const bodyEl = document.getElementById('appDrawerBody');

  if (!overlay || !drawer) return;

  if (titleEl) titleEl.textContent = title || 'فرم مدیریت';
  if (bodyEl && contentHtml) bodyEl.innerHTML = contentHtml;

  overlay.classList.add('active');
  drawer.classList.add('open');
};

window.closeDrawer = function() {
  const overlay = document.getElementById('appDrawerOverlay');
  const drawer = document.getElementById('appDrawer');

  if (overlay) overlay.classList.remove('active');
  if (drawer) drawer.classList.remove('open');

  if (window.history && window.history.replaceState) {
    try {
      const url = new URL(window.location.href);
      if (url.searchParams.has('edit')) {
        url.searchParams.delete('edit');
        const search = url.searchParams.toString();
        const newUrl = url.pathname + (search ? '?' + search : '');
        window.history.replaceState(null, '', newUrl);
      }
    } catch (e) {}
  }

  document.querySelectorAll('.tr-editing').forEach(el => el.classList.remove('tr-editing'));
};

/* ── TOAST COMPONENT ──────────────────────────────────────── */
window.showToast = function(message, type = 'success') {
  let container = document.getElementById('appToastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'appToastContainer';
    container.className = 'app-toast-container';
    document.body.appendChild(container);
  }

  const toast = document.createElement('div');
  toast.className = `app-toast ${type}`;
  toast.innerHTML = `
    <div class="app-toast-content">${message}</div>
  `;

  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(-20px)';
    setTimeout(() => toast.remove(), 300);
  }, 4000);
};

/* ── SHAMSI DATEPICKER COMPONENT ───────────────────────────── */
function initShamsiDatePicker() {
  const monthNames = [
    'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
  ];
  const faDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

  function toFa(num) {
    return String(num).replace(/\d/g, d => faDigits[d]);
  }

  function toEn(str) {
    return String(str).replace(/[۰-۹]/g, d => faDigits.indexOf(d));
  }

  function gregorianToJalali(gy, gm, gd) {
    const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    let gy2 = (gm > 2) ? (gy + 1) : gy;
    let days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100)
      + Math.floor((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
    let jy = -1595 + (33 * Math.floor(days / 12053));
    days %= 12053;
    jy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) {
      jy += Math.floor((days - 1) / 365);
      days = (days - 1) % 365;
    }
    let jm = (days < 186) ? (1 + Math.floor(days / 31)) : (7 + Math.floor((days - 186) / 30));
    let jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
    return [jy, jm, jd];
  }

  function jalaliToGregorian(jy, jm, jd) {
    jy += 1595;
    let days = -355668 + (365 * jy) + (Math.floor(jy / 33) * 8) + Math.floor(((jy % 33) + 3) / 4) + jd
      + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
    let gy = 400 * Math.floor(days / 146097);
    days %= 146097;
    if (days > 36524) {
      gy += 100 * Math.floor(--days / 36524);
      days %= 36524;
      if (days >= 365) days++;
    }
    gy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) {
      gy += Math.floor((days - 1) / 365);
      days = (days - 1) % 365;
    }
    let gd = days + 1;
    let sal_a = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    let gm = 0;
    while (gm < 13 && gd > sal_a[gm]) {
      gd -= sal_a[gm];
      gm++;
    }
    return [gy, gm, gd];
  }

  let activePicker = null;

  function closeActivePicker() {
    if (activePicker) {
      activePicker.remove();
      activePicker = null;
    }
  }

  document.addEventListener('click', (e) => {
    if (activePicker && !activePicker.contains(e.target) && !e.target.classList.contains('shamsi-datepicker') && !e.target.hasAttribute('data-jdp')) {
      closeActivePicker();
    }
  });

  const inputs = document.querySelectorAll('.shamsi-datepicker, [data-jdp]');
  inputs.forEach(input => {
    input.setAttribute('autocomplete', 'off');

    input.addEventListener('focus', () => renderPicker(input));
    input.addEventListener('click', (e) => {
      e.stopPropagation();
      renderPicker(input);
    });
  });

  function isJalaliLeapYear(jy) {
    // Jalali leap years in 33-year cycle: 1, 5, 9, 13, 17, 22, 26, 30
    const breaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
    let bl = breaks.length;
    let gy = jy + 621;
    let leapJ = -14;
    let jp = breaks[0];
    let jump = 0;
    for (let i = 1; i < bl; i++) {
      const jm = breaks[i];
      jump = jm - jp;
      if (jy < jm) break;
      leapJ += Math.floor(jump / 33) * 8 + Math.floor((jump % 33) / 4);
      jp = jm;
    }
    let n = jy - jp;
    leapJ += Math.floor(n / 33) * 8 + Math.floor(((n % 33) + 3) / 4);
    if ((jump % 33) === 4 && (jump - n) === 4) leapJ++;
    const leapG = Math.floor(gy / 4) - Math.floor((Math.floor(gy / 100) + 1) * 3 / 4) - 150;
    return ((leapJ - leapG) % 4) === 0 || (((leapJ - leapG) % 33) === 0);
  }

  function getPickerConfig(input) {
    const today = new Date();
    const [tJy] = gregorianToJalali(today.getFullYear(), today.getMonth() + 1, today.getDate());
    const isBirth = (
      (input.id && input.id === 'date_of_birth') ||
      (input.name && input.name === 'date_of_birth') ||
      input.hasAttribute('data-birth') ||
      input.dataset.datepicker === 'birth'
    );
    // Explicit overrides: data-year-min / data-year-max / data-year-past / data-year-future
    let yearMin = parseInt(toEn(input.dataset.yearMin || ''), 10);
    let yearMax = parseInt(toEn(input.dataset.yearMax || ''), 10);
    const past = parseInt(toEn(input.dataset.yearPast || input.dataset.yearRangePast || ''), 10);
    const future = parseInt(toEn(input.dataset.yearFuture || ''), 10);
    if (!Number.isFinite(yearMin)) {
      yearMin = Number.isFinite(past) ? (tJy - past) : (isBirth ? (tJy - 15) : (tJy - 100));
    }
    if (!Number.isFinite(yearMax)) {
      yearMax = Number.isFinite(future) ? (tJy + future) : (isBirth ? tJy : (tJy + 20));
    }
    if (yearMin > yearMax) {
      const tmp = yearMin;
      yearMin = yearMax;
      yearMax = tmp;
    }
    return { tJy, isBirth, yearMin, yearMax };
  }

  function renderPicker(input) {
    closeActivePicker();

    const today = new Date();
    const [tJy, tJm, tJd] = gregorianToJalali(today.getFullYear(), today.getMonth() + 1, today.getDate());
    const cfg = getPickerConfig(input);

    let curJy = tJy;
    let curJm = tJm;
    let viewMode = 'days'; // 'days' | 'years'
    let yearPage = 0;

    let val = toEn(input.value.trim());
    let m = val.match(/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
    if (m) {
      const parsedY = parseInt(m[1], 10);
      const parsedM = parseInt(m[2], 10);
      if (Number.isFinite(parsedY)) curJy = Math.min(Math.max(parsedY, cfg.yearMin), cfg.yearMax);
      if (parsedM >= 1 && parsedM <= 12) curJm = parsedM;
    } else if (cfg.isBirth && !input.value.trim()) {
      curJy = Math.max(cfg.yearMin, tJy - 4);
      curJm = tJm;
    }

    const container = document.createElement('div');
    container.className = 'shamsi-datepicker-container';

    const rect = input.getBoundingClientRect();
    container.style.top = `${rect.bottom + window.scrollY}px`;
    container.style.left = `${rect.left + window.scrollX}px`;

    function clampYearMonth() {
      if (curJy < cfg.yearMin) { curJy = cfg.yearMin; }
      if (curJy > cfg.yearMax) { curJy = cfg.yearMax; }
      if (curJm < 1) curJm = 1;
      if (curJm > 12) curJm = 12;
    }

    function buildYearOptions(selected) {
      let html = '';
      for (let y = cfg.yearMax; y >= cfg.yearMin; y--) {
        html += `<option value="${y}"${y === selected ? ' selected' : ''}>${toFa(y)}</option>`;
      }
      return html;
    }

    function buildMonthOptions(selected) {
      let html = '';
      for (let i = 1; i <= 12; i++) {
        html += `<option value="${i}"${i === selected ? ' selected' : ''}>${monthNames[i - 1]}</option>`;
      }
      return html;
    }

    function updateCalendar() {
      clampYearMonth();
      if (viewMode === 'years') {
        renderYearView();
        return;
      }

      let daysInMonth = curJm <= 6 ? 31 : (curJm <= 11 ? 30 : 29);
      if (curJm === 12 && isJalaliLeapYear(curJy)) daysInMonth = 30;
      const [gY, gM, gD] = jalaliToGregorian(curJy, curJm, 1);
      const firstDayOfWeek = (new Date(gY, gM - 1, gD).getDay() + 1) % 7;

      container.innerHTML = `
        <div class="sdp-header">
          <button type="button" class="sdp-nav-btn sdp-prev" title="ماه قبل">&gt;</button>
          <div class="sdp-selects">
            <select class="sdp-select sdp-month-select" aria-label="انتخاب ماه">${buildMonthOptions(curJm)}</select>
            <button type="button" class="sdp-title sdp-year-btn" title="کلیک کنید تا لیست سال‌ها باز شود">${toFa(curJy)} ▾</button>
            <select class="sdp-select sdp-year-select" aria-label="انتخاب سال">${buildYearOptions(curJy)}</select>
          </div>
          <button type="button" class="sdp-nav-btn sdp-next" title="ماه بعد">&lt;</button>
        </div>
        ${cfg.isBirth ? `<div class="sdp-hint">از ${toFa(cfg.yearMin)} تا ${toFa(cfg.yearMax)} — برای انتخاب سریع سال، روی سال کلیک کنید</div>` : ''}
        <div class="sdp-weekdays">
          <div>ش</div><div>۱ش</div><div>۲ش</div><div>۳ش</div><div>۴ش</div><div>۵ش</div><div>ج</div>
        </div>
        <div class="sdp-days"></div>
        <div class="sdp-footer">
          <button type="button" class="sdp-today-btn">امروز (${toFa(tJy)}/${toFa(String(tJm).padStart(2, '0'))}/${toFa(String(tJd).padStart(2, '0'))})</button>
        </div>
      `;

      const daysGrid = container.querySelector('.sdp-days');
      for (let i = 0; i < firstDayOfWeek; i++) {
        const emptyBtn = document.createElement('button');
        emptyBtn.type = 'button';
        emptyBtn.className = 'sdp-day-btn empty';
        emptyBtn.tabIndex = -1;
        daysGrid.appendChild(emptyBtn);
      }

      for (let day = 1; day <= daysInMonth; day++) {
        const dayBtn = document.createElement('button');
        dayBtn.type = 'button';
        dayBtn.className = 'sdp-day-btn';
        dayBtn.textContent = toFa(day);

        const formattedMonth = String(curJm).padStart(2, '0');
        const formattedDay = String(day).padStart(2, '0');
        const dateStr = `${curJy}/${formattedMonth}/${formattedDay}`;

        if (input.value && toEn(input.value).includes(`${curJy}/${formattedMonth}/${formattedDay}`)) {
          dayBtn.classList.add('selected');
        }

        dayBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          input.value = toFa(dateStr);
          input.dispatchEvent(new Event('change', { bubbles: true }));
          closeActivePicker();
        });

        daysGrid.appendChild(dayBtn);
      }

      container.querySelector('.sdp-prev').addEventListener('click', (e) => {
        e.stopPropagation();
        curJm--;
        if (curJm < 1) {
          curJm = 12;
          curJy--;
        }
        clampYearMonth();
        updateCalendar();
      });

      container.querySelector('.sdp-next').addEventListener('click', (e) => {
        e.stopPropagation();
        curJm++;
        if (curJm > 12) {
          curJm = 1;
          curJy++;
        }
        clampYearMonth();
        updateCalendar();
      });

      const monthSelect = container.querySelector('.sdp-month-select');
      const yearSelect = container.querySelector('.sdp-year-select');
      monthSelect.addEventListener('click', (e) => e.stopPropagation());
      yearSelect.addEventListener('click', (e) => e.stopPropagation());
      monthSelect.addEventListener('change', (e) => {
        e.stopPropagation();
        curJm = parseInt(monthSelect.value, 10) || curJm;
        updateCalendar();
      });
      yearSelect.addEventListener('change', (e) => {
        e.stopPropagation();
        curJy = parseInt(yearSelect.value, 10) || curJy;
        clampYearMonth();
        updateCalendar();
      });

      container.querySelector('.sdp-year-btn').addEventListener('click', (e) => {
        e.stopPropagation();
        viewMode = 'years';
        yearPage = Math.floor((cfg.yearMax - curJy) / 12);
        updateCalendar();
      });

      const todayBtn = container.querySelector('.sdp-today-btn');
      if (todayBtn) {
        todayBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          if (cfg.isBirth) {
            curJy = cfg.yearMax;
            curJm = tJm;
          } else {
            curJy = tJy;
            curJm = tJm;
          }
          updateCalendar();
        });
      }
    }

    function renderYearView() {
      const totalYears = cfg.yearMax - cfg.yearMin + 1;
      const totalPages = Math.max(1, Math.ceil(totalYears / 12));
      if (yearPage < 0) yearPage = 0;
      if (yearPage >= totalPages) yearPage = totalPages - 1;
      const startY = cfg.yearMax - yearPage * 12;
      const endY = Math.max(cfg.yearMin, startY - 11);

      let yearsHtml = '';
      for (let y = startY; y >= endY; y--) {
        const active = y === curJy ? ' selected' : '';
        yearsHtml += `<button type="button" class="sdp-year-cell${active}" data-year="${y}">${toFa(y)}</button>`;
      }

      container.innerHTML = `
        <div class="sdp-header">
          <button type="button" class="sdp-nav-btn sdp-year-prev" title="سال‌های جدیدتر">›</button>
          <div class="sdp-title">انتخاب سال<div class="sdp-subtitle">${toFa(endY)} تا ${toFa(startY)}</div></div>
          <button type="button" class="sdp-nav-btn sdp-year-next" title="سال‌های قدیمی‌تر">‹</button>
        </div>
        <div class="sdp-years-grid">${yearsHtml}</div>
        <div class="sdp-footer">
          <button type="button" class="sdp-back-btn">بازگشت به تقویم</button>
        </div>
      `;

      container.querySelectorAll('.sdp-year-cell').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          curJy = parseInt(btn.dataset.year, 10) || curJy;
          viewMode = 'days';
          updateCalendar();
        });
      });

      container.querySelector('.sdp-year-prev').addEventListener('click', (e) => {
        e.stopPropagation();
        if (yearPage > 0) { yearPage--; renderYearView(); }
      });
      container.querySelector('.sdp-year-next').addEventListener('click', (e) => {
        e.stopPropagation();
        if (yearPage < totalPages - 1) { yearPage++; renderYearView(); }
      });
      container.querySelector('.sdp-back-btn').addEventListener('click', (e) => {
        e.stopPropagation();
        viewMode = 'days';
        updateCalendar();
      });
    }

    updateCalendar();
    document.body.appendChild(container);
    activePicker = container;
  }
}

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
  if (sidebar && sidebar.hasAttribute('data-modern-sidebar')) {
    return;
  }
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

  const isCollapsible = () => Boolean(
    (toggleBtn && toggleBtn.getClientRects().length > 0) ||
    (closeBtn && closeBtn.getClientRects().length > 0)
  );

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
    } else if (!open && focus && toggleBtn && toggleBtn.getClientRects().length > 0) {
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

    window.addEventListener('resize', () => {
      if (shell.classList.contains('is-open') && !isCollapsible()) {
        setSearchOpen(false);
      }
    });
  }

  document.addEventListener('keydown', (event) => {
    const shellVisible = shell ? !shell.hidden && shell.getClientRects().length > 0 : input.getClientRects().length > 0;
    if ((event.key === '/' || ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k'))
        && !event.target.closest?.('input,textarea,select,[contenteditable]') && shellVisible) {
      event.preventDefault();
      if (isCollapsible()) {
        setSearchOpen(true, { focus: true });
      } else {
        input.focus();
      }
      return;
    }
    if (event.key === 'Escape' && (document.activeElement === input || shell?.contains(document.activeElement))) {
      if (isCollapsible()) {
        event.preventDefault();
        setSearchOpen(false, { focus: true, clear: true });
      } else if (document.activeElement === input) {
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
let activeConfirmSubmitter = null;

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
      if (activeConfirmSubmitter && activeConfirmSubmitter.name) {
        const clone = document.createElement('input');
        clone.type = 'hidden';
        clone.name = activeConfirmSubmitter.name;
        clone.value = activeConfirmSubmitter.value || '';
        activeConfirmForm.appendChild(clone);
      }
      activeConfirmForm.submit();
      activeConfirmForm = null;
      activeConfirmSubmitter = null;
    }
    closeConfirmModal();
  });

  // Intercept confirm clicks/forms with data-confirm or onsubmit confirm
  document.addEventListener('submit', (e) => {
    const form = e.target;
    const confirmMsg = form.dataset.confirm;
    if (confirmMsg) {
      e.preventDefault();
      openConfirmModal(form, confirmMsg, e.submitter || null);
    }
  });
}

window.confirmAction = function(formOrElement, message, itemName) {
  const finalMsg = itemName ? `آیا از انجام این عملیات روی «${itemName}» اطمینان دارید؟` : message;
  openConfirmModal(formOrElement, finalMsg);
};

function openConfirmModal(formOrElement, message, submitter = null) {
  const dialogOverlay = document.getElementById('appConfirmOverlay');
  const msgEl = document.getElementById('appConfirmMessage');
  if (!dialogOverlay || !msgEl) return;

  activeConfirmForm = formOrElement;
  activeConfirmSubmitter = submitter;
  msgEl.textContent = message || 'آیا از انجام این عملیات اطمینان دارید؟';
  dialogOverlay.classList.add('active');
}

function closeConfirmModal() {
  const dialogOverlay = document.getElementById('appConfirmOverlay');
  if (dialogOverlay) dialogOverlay.classList.remove('active');
  activeConfirmForm = null;
  activeConfirmSubmitter = null;
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

/* ── IRANIAN CALENDAR AND CLOCK ───────────────────────────── */
const iranCalendar = JSON.parse(document.documentElement.dataset.iranCalendar || 'null');

function iranLocalNow() {
  // Use the app server's Iran offset and UTC accessors, independently of device settings.
  return new Date(Date.now() + iranCalendar.offsetSeconds * 1000);
}

function iranPersianDigits(value) {
  return String(value).replace(/[0-9]/g, digit => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
}

function iranLatinDigits(value) {
  return String(value).trim().replace(/[۰-۹٠-٩]/g, digit => String(digit.charCodeAt(0) & 15));
}

function initShamsiDatePicker() {
  if (!iranCalendar) return;

  const { firstYear, firstDay, leapYears, monthNames } = iranCalendar;
  const dayMs = 86400000;
  const yearStarts = [Date.parse(firstDay + 'T00:00:00Z') / dayMs];
  for (const leap of leapYears) {
    yearStarts.push(yearStarts[yearStarts.length - 1] + (leap === '1' ? 366 : 365));
  }
  const lastYear = firstYear + leapYears.length - 1;
  const weekdays = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];
  const selector = 'input.shamsi-datepicker, input[data-jdp]';
  const pad = number => String(number).padStart(2, '0');
  const dateKey = ({ year, month, day }) => year + '-' + pad(month) + '-' + pad(day);
  const dateLabel = date => iranPersianDigits(dateKey(date).replace(/-/g, '/'));
  let active = null;
  let returningFocus = false;

  function monthLength(year, month) {
    return month <= 6 ? 31 : (month < 12 || leapYears[year - firstYear] === '1' ? 30 : 29);
  }

  function readDate(value) {
    const text = iranLatinDigits(value || '');
    const separated = /^(\d{4})([/-])(\d{1,2})\2(\d{1,2})$/.exec(text);
    const digits = separated ? [separated[1], separated[3], separated[4]] : /^(\d{4})(\d{2})(\d{2})$/.exec(text)?.slice(1);
    if (!digits) return null;

    const [year, month, day] = digits.map(Number);
    if (year < firstYear || year > lastYear || month < 1 || month > 12 || day < 1 || day > monthLength(year, month)) return null;
    return { year, month, day };
  }

  function todayInIran() {
    const today = Math.floor(iranLocalNow().getTime() / dayMs);
    const yearIndex = yearStarts.findIndex(start => start > today) - 1;
    const offset = today - yearStarts[yearIndex];
    return offset < 186
      ? { year: firstYear + yearIndex, month: 1 + Math.floor(offset / 31), day: 1 + offset % 31 }
      : { year: firstYear + yearIndex, month: 7 + Math.floor((offset - 186) / 30), day: 1 + (offset - 186) % 30 };
  }

  function dateLimits(input, today) {
    const birth = input.dataset.datepicker === 'birth';
    const past = Number(input.dataset.yearPast ?? (birth ? 15 : 100));
    const future = Number(input.dataset.yearFuture ?? (birth ? 0 : 20));
    const latestYear = Math.min(lastYear, today.year + future);
    const min = readDate(input.dataset.minDate)
      || { year: Math.max(firstYear, today.year - past), month: 1, day: 1 };
    const max = readDate(input.dataset.maxDate)
      || (birth ? today : { year: latestYear, month: 12, day: monthLength(latestYear, 12) });
    return { min, max, birth };
  }

  function closePicker(restoreFocus = false) {
    if (!active) return;
    const { input, panel } = active;
    active = null;
    panel.remove();
    input.removeAttribute('aria-controls');
    if (restoreFocus) {
      returningFocus = true;
      input.focus({ preventScroll: true });
      returningFocus = false;
    }
  }

  function openPicker(input) {
    if (active?.input === input || input.readOnly || input.disabled) return;
    closePicker();

    const today = todayInIran();
    const { min, max, birth } = dateLimits(input, today);
    const minKey = dateKey(min);
    const maxKey = dateKey(max);
    const isAllowed = date => dateKey(date) >= minKey && dateKey(date) <= maxKey;
    const entered = readDate(input.value);
    const initial = entered || (birth ? { ...today, year: today.year - 4 } : today);
    const start = dateKey(initial) < minKey ? min : dateKey(initial) > maxKey ? max : initial;
    let { year, month } = start;
    let yearPage = 0;

    const panel = document.createElement('div');
    panel.id = 'iran-date-picker';
    panel.className = 'shamsi-datepicker-container';
    panel.setAttribute('role', 'group');
    panel.setAttribute('aria-label', 'تقویم هجری شمسی');
    input.setAttribute('aria-controls', panel.id);
    active = { input, panel, positionPicker };
    document.body.appendChild(panel);

    function positionPicker() {
      const rect = input.getBoundingClientRect();
      const viewport = window.visualViewport;
      const topEdge = viewport?.offsetTop ?? 0;
      const bottomEdge = topEdge + (viewport?.height ?? window.innerHeight);
      const left = Math.max(8, Math.min(rect.right - panel.offsetWidth, window.innerWidth - panel.offsetWidth - 8));
      const below = bottomEdge - rect.bottom - 8;
      const above = rect.top - topEdge - 8;
      const openAbove = panel.scrollHeight > below && above > below;
      panel.style.maxHeight = Math.max(96, openAbove ? above : below) + 'px';
      const top = openAbove ? rect.top - panel.offsetHeight - 8 : rect.bottom;
      panel.style.left = (left + window.scrollX) + 'px';
      panel.style.top = (top + window.scrollY) + 'px';
    }

    function choose(date) {
      input.value = dateLabel(date);
      closePicker(true);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function showDays(focusSelector) {
      const firstMonth = year === min.year ? min.month : 1;
      const lastMonth = year === max.year ? max.month : 12;
      month = Math.min(lastMonth, Math.max(firstMonth, month));
      const months = monthNames.map((name, index) => {
        const number = index + 1;
        return '<option value="' + number + '"' + (number === month ? ' selected' : '')
          + (number < firstMonth || number > lastMonth ? ' disabled' : '') + '>' + name + '</option>';
      }).join('');
      const firstOffset = month <= 6 ? (month - 1) * 31 : 186 + (month - 7) * 30;
      const monthStart = new Date((yearStarts[year - firstYear] + firstOffset) * dayMs);
      const firstWeekday = (monthStart.getUTCDay() + 1) % 7;
      const previous = year === min.year && month === min.month;
      const next = year === max.year && month === max.month;
      const todayKey = dateKey(today);
      const selectedKey = entered ? dateKey(entered) : null;
      let days = '<span aria-hidden="true"></span>'.repeat(firstWeekday);
      for (let day = 1; day <= monthLength(year, month); day++) {
        const date = { year, month, day };
        const selected = dateKey(date) === selectedKey;
        const friday = (firstWeekday + day - 1) % 7 === 6;
        days += '<button type="button" class="sdp-day-btn' + (friday ? ' sdp-friday' : '') + (selected ? ' selected' : '') + '" data-day="' + day
          + '" aria-label="' + dateLabel(date) + '"' + (dateKey(date) === todayKey ? ' aria-current="date"' : '')
          + (selected ? ' aria-pressed="true"' : '')
          + (isAllowed(date) ? '' : ' disabled') + '>' + iranPersianDigits(day) + '</button>';
      }

      panel.innerHTML =
        '<div class="sdp-header">'
        + '<button type="button" class="sdp-nav-btn sdp-prev" aria-label="ماه قبل"' + (previous ? ' disabled' : '') + '>›</button>'
        + '<div class="sdp-selects"><select class="sdp-select sdp-month-select" aria-label="ماه">' + months + '</select>'
        + '<button type="button" class="sdp-title sdp-year-btn" aria-label="انتخاب سال، ' + iranPersianDigits(year) + '">' + iranPersianDigits(year) + ' ▾</button></div>'
        + '<button type="button" class="sdp-nav-btn sdp-next" aria-label="ماه بعد"' + (next ? ' disabled' : '') + '>‹</button></div>'
        + '<div class="sdp-weekdays">' + weekdays.map(name => '<div title="' + name + '">' + name[0] + '</div>').join('') + '</div>'
        + '<div class="sdp-days">' + days + '</div>'
        + (isAllowed(today) ? '<div class="sdp-footer"><button type="button" class="sdp-today-btn">امروز ' + dateLabel(today) + '</button></div>' : '');

      panel.querySelector('.sdp-month-select').addEventListener('change', event => {
        month = Number(event.target.value);
        showDays('.sdp-month-select');
      });
      for (const [button, step] of [['.sdp-prev', -1], ['.sdp-next', 1]]) {
        panel.querySelector(button).addEventListener('click', () => {
          const index = year * 12 + month - 1 + step;
          year = Math.floor(index / 12);
          month = index % 12 + 1;
          showDays(button);
        });
      }
      panel.querySelector('.sdp-year-btn').addEventListener('click', () => {
        yearPage = Math.floor((max.year - year) / 12);
        showYears('.sdp-year-cell.selected');
      });
      panel.querySelector('.sdp-today-btn')?.addEventListener('click', () => choose(today));
      panel.querySelector('.sdp-days').addEventListener('click', event => {
        const day = Number(event.target.dataset.day);
        if (day && isAllowed({ year, month, day })) choose({ year, month, day });
      });
      positionPicker();
      if (focusSelector) {
        (panel.querySelector(focusSelector + ':not(:disabled)') || panel.querySelector('.sdp-month-select')).focus({ preventScroll: true });
      }
    }

    function showYears(focusSelector) {
      const newest = max.year - yearPage * 12;
      const oldest = Math.max(min.year, newest - 11);
      let years = '';
      for (let choice = newest; choice >= oldest; choice--) {
        years += '<button type="button" class="sdp-year-cell' + (choice === year ? ' selected' : '') + '" data-year="' + choice + '">'
          + iranPersianDigits(choice) + '</button>';
      }
      panel.innerHTML =
        '<div class="sdp-header">'
        + '<button type="button" class="sdp-nav-btn sdp-year-prev" aria-label="سال‌های جدیدتر"' + (yearPage === 0 ? ' disabled' : '') + '>›</button>'
        + '<div class="sdp-title">انتخاب سال</div>'
        + '<button type="button" class="sdp-nav-btn sdp-year-next" aria-label="سال‌های قدیمی‌تر"' + (oldest === min.year ? ' disabled' : '') + '>‹</button></div>'
        + '<div class="sdp-years-grid">' + years + '</div>'
        + '<div class="sdp-footer"><button type="button" class="sdp-back-btn">بازگشت به تقویم</button></div>';

      panel.querySelector('.sdp-years-grid').addEventListener('click', event => {
        if (!event.target.dataset.year) return;
        year = Number(event.target.dataset.year);
        showDays('.sdp-year-btn');
      });
      for (const [button, step] of [['.sdp-year-prev', -1], ['.sdp-year-next', 1]]) {
        panel.querySelector(button).addEventListener('click', () => {
          yearPage += step;
          showYears(button);
        });
      }
      panel.querySelector('.sdp-back-btn').addEventListener('click', () => showDays('.sdp-year-btn'));
      positionPicker();
      (panel.querySelector(focusSelector + ':not(:disabled)') || panel.querySelector('.sdp-year-cell')).focus({ preventScroll: true });
    }

    showDays();
  }

  // Deliberately delegated for date inputs added to the form after page load.
  document.addEventListener('focusin', event => {
    if (returningFocus) return;
    if (event.target.matches(selector)) openPicker(event.target);
    else if (!active?.panel.contains(event.target)) closePicker();
  });
  document.addEventListener('click', event => {
    if (event.target.matches(selector)) openPicker(event.target);
    else if (!active?.panel.contains(event.target)) closePicker();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && active) {
      event.preventDefault();
      event.stopPropagation();
      closePicker(true);
    } else if (event.key === 'ArrowDown' && event.target.matches(selector)) {
      openPicker(event.target);
      if (!active) return;
      event.preventDefault();
      (active.panel.querySelector('.sdp-day-btn.selected:not(:disabled), .sdp-day-btn[aria-current="date"]:not(:disabled)')
        || active.panel.querySelector('.sdp-day-btn:not(:disabled)'))?.focus({ preventScroll: true });
    }
  }, true);
  window.addEventListener('resize', () => active?.positionPicker());
  window.visualViewport?.addEventListener('resize', () => active?.positionPicker());

  function validateDate(input) {
    const date = readDate(input.value);
    const key = date && dateKey(date);
    const min = input.dataset.minDate;
    const max = input.dataset.maxDate;
    let error = '';
    if (input.value.trim() && !date) {
      error = 'تاریخ شمسی معتبر وارد کنید، مانند ۱۴۰۵/۰۵/۱۸.';
    } else if (key && ((min && key < min) || (max && key > max))) {
      error = 'تاریخ را از بازهٔ مجاز تقویم انتخاب کنید.';
    }
    input.setCustomValidity(error);
    return date;
  }
  document.addEventListener('input', event => {
    if (event.target.matches(selector)) validateDate(event.target);
  });

  document.addEventListener('change', event => {
    const input = event.target;
    if (input.matches(selector)) {
      const date = validateDate(input);
      if (date) input.value = dateLabel(date);
    } else if (input.matches('[data-iran-time]')) {
      const value = iranLatinDigits(input.value);
      const compact = /^(\d{1,2})(\d{2})$/.exec(value) || /^(\d{2})(\d{2})(\d{2})$/.exec(value);
      const time = /^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(compact ? compact.slice(1).join(':') : value);
      if (time && Number(time[1]) < 24 && Number(time[2]) < 60 && Number(time[3] || 0) < 60) {
        input.value = iranPersianDigits(pad(time[1]) + ':' + time[2] + (time[3] && time[3] !== '00' ? ':' + time[3] : ''));
      }
    }
  });
}

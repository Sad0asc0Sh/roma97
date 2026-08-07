/**
 * ROMA UNIFIED APP SHELL JS
 * Provides interactive behavior for enterprise app components across Admin, Teacher, and Parent portals.
 */

document.addEventListener('DOMContentLoaded', () => {
  initSidebarToggle();
  initUserDropdown();
  initGlobalSearchKeybind();
  initTabs();
  initConfirmDialogs();
  initDrawers();
  initShamsiDatePicker();
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
      localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
    });

    if (localStorage.getItem('sidebar_collapsed') === '1') {
      sidebar.classList.add('collapsed');
    }
  }

  if (sidebar && overlay) {
    if (mobileToggle) {
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

  const parentItems = document.querySelectorAll('.admin-nav-parent');
  parentItems.forEach(item => {
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
function initUserDropdown() {
  const userTrigger = document.getElementById('appUserTrigger');
  const userMenu = document.getElementById('appUserMenu');

  if (userTrigger && userMenu) {
    userTrigger.addEventListener('click', (e) => {
      e.stopPropagation();
      userMenu.classList.toggle('show');
    });

    document.addEventListener('click', () => {
      userMenu.classList.remove('show');
    });
  }
}

/* ── GLOBAL SEARCH KEYBOARD SHORTCUT (/) & LIVE CLIENT FILTER ──── */
function initGlobalSearchKeybind() {
  const searchInput = document.getElementById('appSearchInput');
  if (!searchInput) return;

  document.addEventListener('keydown', (e) => {
    if ((e.key === '/' || (e.ctrlKey && e.key === 'k')) && document.activeElement !== searchInput) {
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) return;
      e.preventDefault();
      searchInput.focus();
    }
  });

  searchInput.addEventListener('input', (e) => {
    const query = e.target.value.trim().toLowerCase();
    const tableRows = document.querySelectorAll('.app-table tbody tr, .admin-table tbody tr, .payments-table tbody tr, .attendance-table tbody tr');

    tableRows.forEach(row => {
      const text = row.textContent.toLowerCase();
      if (query === '' || text.includes(query)) {
        row.style.display = '';
        row.style.animation = 'fadeIn 0.25s ease-out';
      } else {
        row.style.display = 'none';
      }
    });

    const cards = document.querySelectorAll('.admin-child-card, .child-card-large, .child-list-card, .event-card, .admin-event-card, .quick-list-item');
    cards.forEach(card => {
      const text = card.textContent.toLowerCase();
      if (query === '' || text.includes(query)) {
        card.style.display = '';
        card.style.animation = 'fadeScale 0.25s ease-out';
      } else {
        card.style.display = 'none';
      }
    });
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

  function renderPicker(input) {
    closeActivePicker();

    const today = new Date();
    const [tJy, tJm, tJd] = gregorianToJalali(today.getFullYear(), today.getMonth() + 1, today.getDate());

    let curJy = tJy;
    let curJm = tJm;

    let val = toEn(input.value.trim());
    let m = val.match(/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
    if (m) {
      curJy = parseInt(m[1], 10);
      curJm = parseInt(m[2], 10);
    }

    const container = document.createElement('div');
    container.className = 'shamsi-datepicker-container';

    const rect = input.getBoundingClientRect();
    container.style.top = `${rect.bottom + window.scrollY}px`;
    container.style.left = `${rect.left + window.scrollX}px`;

    function updateCalendar() {
      const daysInMonth = curJm <= 6 ? 31 : (curJm <= 11 ? 30 : 29);
      const [gY, gM, gD] = jalaliToGregorian(curJy, curJm, 1);
      const firstDayOfWeek = (new Date(gY, gM - 1, gD).getDay() + 1) % 7;

      container.innerHTML = `
        <div class="sdp-header">
          <button type="button" class="sdp-nav-btn sdp-prev">&gt;</button>
          <div class="sdp-title">${monthNames[curJm - 1]} ${toFa(curJy)}</div>
          <button type="button" class="sdp-nav-btn sdp-next">&lt;</button>
        </div>
        <div class="sdp-weekdays">
          <div>ش</div><div>۱ش</div><div>۲ش</div><div>۳ش</div><div>۴ش</div><div>۵ش</div><div>ج</div>
        </div>
        <div class="sdp-days"></div>
      `;

      const daysGrid = container.querySelector('.sdp-days');
      for (let i = 0; i < firstDayOfWeek; i++) {
        const emptyBtn = document.createElement('button');
        emptyBtn.type = 'button';
        emptyBtn.className = 'sdp-day-btn empty';
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
        updateCalendar();
      });

      container.querySelector('.sdp-next').addEventListener('click', (e) => {
        e.stopPropagation();
        curJm++;
        if (curJm > 12) {
          curJm = 1;
          curJy++;
        }
        updateCalendar();
      });
    }

    updateCalendar();
    document.body.appendChild(container);
    activePicker = container;
  }
}

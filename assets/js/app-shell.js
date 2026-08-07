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

'use strict';

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('nav a.active').forEach(link => link.setAttribute('aria-current', 'page'));

  // Long registers stay inside the viewport and can be scrolled by keyboard.
  document.querySelectorAll('main table').forEach(table => {
    const parent = table.parentElement;
    if (parent && (parent.className.includes('table') || parent.children.length === 1)) {
      parent.classList.add('portal-table-scroll');
      parent.tabIndex = 0;
      parent.setAttribute('role', 'region');
      parent.setAttribute('aria-label', table.getAttribute('aria-label') || 'جدول؛ برای دیدن ستون‌های بیشتر به طرفین حرکت کنید');
    }
  });

  // A single owner controls the drawer; keep keyboard focus inside on mobile.
  const sidebar = document.getElementById('adminSidebar');
  const toggle = document.getElementById('mobileSidebarToggle');
  if (sidebar && !sidebar.hasAttribute('data-modern-sidebar') && toggle && window.toggleMobileSidebar) {
    const originalToggle = window.toggleMobileSidebar;
    toggle.setAttribute('aria-controls', 'adminSidebar');
    toggle.setAttribute('aria-expanded', 'false');
    const syncSidebar = () => {
      const mobile = window.matchMedia('(max-width: 991px)').matches;
      const open = sidebar.classList.contains('active');
      sidebar.inert = mobile && !open;
      toggle.setAttribute('aria-expanded', String(open && mobile));
    };
    window.toggleMobileSidebar = value => {
      const wasOpen = sidebar.classList.contains('active');
      originalToggle(value);
      syncSidebar();
      if (sidebar.classList.contains('active')) {
        sidebar.querySelector('a, button')?.focus();
      } else if (wasOpen) toggle.focus();
    };
    syncSidebar();
    window.addEventListener('resize', () => {
      if (window.innerWidth > 991) originalToggle(false);
      syncSidebar();
    });
    sidebar.addEventListener('keydown', event => {
      if (event.key !== 'Tab' || window.innerWidth > 991 || !sidebar.classList.contains('active')) return;
      const items = [...sidebar.querySelectorAll('a,button,input,select')].filter(el => !el.disabled && el.getClientRects().length);
      const first = items[0], last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    });
  }

  // No personal data is persisted. Only warn while an edited register is open.
  let dirty = false;
  document.querySelectorAll('#attendanceForm, #psychReportForm, form[data-unsaved-guard]').forEach(form => {
    const notice = document.createElement('p');
    notice.className = 'portal-dirty-notice';
    notice.setAttribute('role', 'status');
    notice.hidden = true;
    notice.textContent = 'تغییرات هنوز ذخیره نشده‌اند؛ در پایان دکمه ذخیره را بزنید.';
    form.prepend(notice);
    const mark = () => { dirty = true; notice.hidden = false; };
    form.addEventListener('input', mark);
    form.addEventListener('change', mark);
    form.addEventListener('click', event => {
      if (event.target.closest('.tp-quick-mood-btn, [data-bulk-status]')) mark();
    });
    form.addEventListener('submit', event => { if (!event.defaultPrevented) dirty = false; });
  });
  window.addEventListener('beforeunload', event => {
    if (dirty) { event.preventDefault(); event.returnValue = ''; }
  });
});

/**
 * Мобильный UX: нижняя навигация, меню действий, фильтры.
 */
(function (window) {
  function syncBottomNav(activeTabId) {
    document.querySelectorAll('.mobile-bottom-nav__item').forEach((btn) => {
      const isActive = btn.dataset.tab === activeTabId;
      btn.classList.toggle('active', isActive);
      btn.setAttribute('aria-current', isActive ? 'page' : 'false');
    });
  }

  function initBottomNav() {
    const nav = document.getElementById('mobileBottomNav');
    if (!nav) return;

    nav.querySelectorAll('[data-tab]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const tabId = btn.dataset.tab;
        if (tabId === 'menu') {
          document.getElementById('sidebarToggle')?.click();
          return;
        }
        document.getElementById(tabId)?.click();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    document.querySelectorAll('[data-bs-toggle="tab"]').forEach((tabBtn) => {
      tabBtn.addEventListener('shown.bs.tab', (e) => syncBottomNav(e.target.id));
    });

    syncBottomNav(document.querySelector('#sidebarNav .nav-link.active')?.id || 'tab-dashboard');
  }

  function initFilterToggles() {
    document.querySelectorAll('[data-filter-toggle]').forEach((btn) => {
      const targetId = btn.dataset.filterToggle;
      const panel = document.getElementById(targetId);
      if (!panel) return;
      btn.addEventListener('click', () => {
        const open = panel.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.querySelector('.filter-toggle-label')?.classList.toggle('d-none', open);
        btn.querySelector('.filter-toggle-label-close')?.classList.toggle('d-none', !open);
      });
    });
  }

  function initTopbarMore() {
    const menu = document.getElementById('topbarMoreMenu');
    if (!menu) return;
    menu.querySelectorAll('[data-trigger]').forEach((item) => {
      item.addEventListener('click', (e) => {
        e.preventDefault();
        const id = item.dataset.trigger;
        if (id === 'importJson') {
          document.getElementById('importJsonInput')?.click();
        } else {
          document.getElementById(id)?.click();
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    initBottomNav();
    initFilterToggles();
    initTopbarMore();
  });

  window.syncMobileBottomNav = syncBottomNav;
})(window);

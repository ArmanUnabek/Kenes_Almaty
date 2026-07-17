(function (window) {
  const NAV_ITEMS = [
    { id: 'tab-incoming', key: 'i', label: 'Входящий журнал' },
    { id: 'tab-outgoing', key: 'o', label: 'Исходящий журнал' },
    { id: 'tab-dashboard', key: 'd', label: 'Статистика' },
    { id: 'tab-members', key: 'm', label: 'Члены ОС' },
    { id: 'tab-archive', key: 'a', label: 'Архив' },
    { id: 'tab-events', key: 'e', label: 'Мероприятия' },
    { id: 'tab-calendar', key: 'c', label: 'Календарь' },
  ];

  let pendingG = false;
  let pendingGTimer = null;

  function isInputFocused() {
    const el = document.activeElement;
    if (!el) return false;
    const tag = el.tagName;
    return (
      tag === 'INPUT' ||
      tag === 'TEXTAREA' ||
      tag === 'SELECT' ||
      el.isContentEditable
    );
  }

  function isModalOpen() {
    return !!document.querySelector('.modal.show');
  }

  function clickTab(id) {
    const btn = document.getElementById(id);
    if (btn) btn.click();
  }

  function openNewLetterModal() {
    const incomingTab = document.getElementById('tab-incoming');
    const outgoingTab = document.getElementById('tab-outgoing');
    const activeTab = document.querySelector('.sidebar-nav .nav-link.active');

    if (activeTab?.id === 'tab-outgoing') {
      const collapse = document.getElementById('collapseOutgoingForm');
      if (collapse && !collapse.classList.contains('show')) {
        collapse.classList.add('show');
      }
      const firstInput = document.querySelector('#formOutgoing input:not([type="hidden"]):not([readonly])');
      if (firstInput) firstInput.focus();
    } else {
      if (incomingTab) incomingTab.click();
      const collapse = document.getElementById('collapseIncomingForm');
      if (collapse && !collapse.classList.contains('show')) {
        collapse.classList.add('show');
      }
      setTimeout(() => {
        const firstInput = document.querySelector('#formIncoming input:not([type="hidden"]):not([readonly])');
        if (firstInput) firstInput.focus();
      }, 100);
    }
  }

  function focusSearch() {
    const activeTab = document.querySelector('.sidebar-nav .nav-link.active');
    const tabId = activeTab?.id || '';
    let searchInput = null;

    if (tabId === 'tab-incoming') {
      searchInput = document.getElementById('searchIncoming');
    } else if (tabId === 'tab-outgoing') {
      searchInput = document.getElementById('searchOutgoing');
    } else if (tabId === 'tab-events') {
      searchInput = document.getElementById('searchEvents');
    }

    if (!searchInput) {
      searchInput = document.querySelector('.search-field input');
    }
    if (searchInput) {
      searchInput.focus();
      searchInput.select();
    }
  }

  function closeTopModal() {
    const modals = document.querySelectorAll('.modal.show');
    if (modals.length === 0) return false;
    const top = modals[modals.length - 1];
    const instance = bootstrap.Modal.getInstance(top);
    if (instance) instance.hide();
    return true;
  }

  function showShortcutsOverlay() {
    let overlay = document.getElementById('shortcutsOverlay');
    if (overlay) {
      overlay.classList.toggle('d-none');
      return;
    }

    overlay = document.createElement('div');
    overlay.id = 'shortcutsOverlay';
    overlay.className = 'shortcuts-overlay';
    overlay.innerHTML = `
      <div class="shortcuts-overlay__backdrop"></div>
      <div class="shortcuts-overlay__panel">
        <div class="shortcuts-overlay__header">
          <h5><i class="bi bi-keyboard me-2"></i>Горячие клавиши</h5>
          <button class="btn-close" id="shortcutsCloseBtn" aria-label="Закрыть"></button>
        </div>
        <div class="shortcuts-overlay__body">
          <div class="shortcuts-section">
            <h6>Навигация</h6>
            <div class="shortcuts-row"><kbd>G</kbd> then <kbd>I</kbd><span>Входящий журнал</span></div>
            <div class="shortcuts-row"><kbd>G</kbd> then <kbd>O</kbd><span>Исходящий журнал</span></div>
            <div class="shortcuts-row"><kbd>G</kbd> then <kbd>D</kbd><span>Статистика</span></div>
            <div class="shortcuts-row"><kbd>G</kbd> then <kbd>M</kbd><span>Члены ОС</span></div>
            <div class="shortcuts-row"><kbd>G</kbd> then <kbd>A</kbd><span>Архив</span></div>
          </div>
          <div class="shortcuts-section">
            <h6>Действия</h6>
            <div class="shortcuts-row"><kbd>N</kbd><span>Новое письмо</span></div>
            <div class="shortcuts-row"><kbd>/</kbd><span>Фокус на поиск</span></div>
            <div class="shortcuts-row"><kbd>Esc</kbd><span>Закрыть модал / оверлей</span></div>
            <div class="shortcuts-row"><kbd>?</kbd><span>Эта справка</span></div>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);

    overlay.querySelector('.shortcuts-overlay__backdrop').addEventListener('click', () => {
      overlay.classList.add('d-none');
    });
    overlay.querySelector('#shortcutsCloseBtn').addEventListener('click', () => {
      overlay.classList.add('d-none');
    });
  }

  function handleKeyDown(e) {
    if (e.defaultPrevented) return;

    if (isModalOpen() && e.key === 'Escape') {
      if (closeTopModal()) {
        e.preventDefault();
        return;
      }
    }

    const overlay = document.getElementById('shortcutsOverlay');
    if (overlay && !overlay.classList.contains('d-none') && e.key === 'Escape') {
      overlay.classList.add('d-none');
      e.preventDefault();
      return;
    }

    if (isInputFocused()) return;

    if (pendingG) {
      clearTimeout(pendingGTimer);
      pendingG = false;
      const key = e.key.toLowerCase();
      const nav = NAV_ITEMS.find((n) => n.key === key);
      if (nav) {
        e.preventDefault();
        clickTab(nav.id);
        return;
      }
      return;
    }

    switch (e.key) {
      case 'n':
      case 'N':
        if (!e.ctrlKey && !e.metaKey) {
          e.preventDefault();
          openNewLetterModal();
        }
        break;
      case '/':
        e.preventDefault();
        focusSearch();
        break;
      case '?':
        e.preventDefault();
        showShortcutsOverlay();
        break;
      case 'Escape':
        if (closeTopModal()) e.preventDefault();
        break;
      case 'g':
      case 'G':
        pendingG = true;
        pendingGTimer = setTimeout(() => { pendingG = false; }, 800);
        break;
      default:
        break;
    }
  }

  function init() {
    document.addEventListener('keydown', handleKeyDown);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.AppShortcuts = { init, showShortcutsOverlay };
})(window);

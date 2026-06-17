(function () {
  const API = '/api';
  let debounceTimer = null;
  let searchModal = null;

  function t(key, fallback) {
    return window.AppI18n?.t(key, fallback) ?? fallback;
  }

  function sourceLabel(source) {
    if (source === 'incoming') return t('search.source.incoming', 'Входящее');
    if (source === 'outgoing') return t('search.source.outgoing', 'Исходящее');
    if (source === 'member') return t('search.source.member', 'Член ОС');
    return source;
  }

  function sourceIcon(source) {
    if (source === 'incoming') return 'bi-inbox';
    if (source === 'outgoing') return 'bi-send';
    if (source === 'member') return 'bi-person';
    return 'bi-search';
  }

  function openLetterFromSearch(source, id) {
    if (source === 'incoming') {
      document.getElementById('tab-incoming')?.click();
      if (window.canWrite?.() && typeof window.editIncoming === 'function') {
        setTimeout(() => window.editIncoming(id), 150);
      } else if (typeof window.viewLetterDetail === 'function') {
        setTimeout(() => window.viewLetterDetail('incoming', id), 150);
      }
      return;
    }
    if (source === 'outgoing') {
      document.getElementById('tab-outgoing')?.click();
      if (window.canWrite?.() && typeof window.editOutgoing === 'function') {
        setTimeout(() => window.editOutgoing(id), 150);
      } else if (typeof window.viewLetterDetail === 'function') {
        setTimeout(() => window.viewLetterDetail('outgoing', id), 150);
      }
    }
  }

  function navigateToResult(item) {
    if (item.source === 'incoming' || item.source === 'outgoing') {
      openLetterFromSearch(item.source, item.id);
      return;
    }
    if (item.source === 'member') {
      document.getElementById('tab-members')?.click();
      const filter = document.getElementById('filterMembersCommission');
      if (filter) filter.value = '';
      if (typeof window.renderMembersGrid === 'function') {
        setTimeout(() => window.renderMembersGrid(), 100);
      }
    }
  }

  function renderResults(items, query) {
    const body = document.getElementById('globalSearchResults');
    if (!body) return;

    if (!query) {
      body.innerHTML = `<div class="text-muted small p-3">${t('search.enter_query', 'Введите запрос для поиска')}</div>`;
      return;
    }
    if (!items.length) {
      body.innerHTML = `<div class="text-muted small p-3">${t('search.nothing_found', 'Ничего не найдено')}</div>`;
      return;
    }

    body.innerHTML = items.map((item) => `
      <button type="button" class="global-search-item" data-source="${item.source}" data-id="${item.id}">
        <span class="global-search-item__icon"><i class="bi ${sourceIcon(item.source)}"></i></span>
        <span class="global-search-item__body">
          <span class="global-search-item__title">${escapeHtml(item.subject || item.organization || '—')}</span>
          <span class="global-search-item__meta">
            ${escapeHtml(sourceLabel(item.source))}
            ${item.number_label ? ' · ' + escapeHtml(item.number_label) : ''}
            ${item.date ? ' · ' + escapeHtml(item.date) : ''}
          </span>
        </span>
      </button>
    `).join('');

    body.querySelectorAll('.global-search-item').forEach((btn, idx) => {
      btn.addEventListener('click', () => {
        navigateToResult(items[idx]);
        searchModal?.hide();
      });
    });
  }

  async function runSearch(query) {
    const q = query.trim();
    const body = document.getElementById('globalSearchResults');
    if (!q) {
      renderResults([], '');
      return;
    }
    if (body) body.innerHTML = `<div class="text-muted small p-3">${t('search.searching', 'Поиск...')}</div>`;
    try {
      const resp = await fetch(`${API}/search.php?q=${encodeURIComponent(q)}&limit=20`);
      const data = await resp.json();
      renderResults(data.items || [], q);
    } catch (err) {
      if (body) body.innerHTML = `<div class="text-danger small p-3">${t('search.error', 'Ошибка поиска')}</div>`;
    }
  }

  function openSearchModal() {
    const modalEl = document.getElementById('globalSearchModal');
    if (!modalEl) return;
    if (!searchModal) searchModal = new bootstrap.Modal(modalEl);
    const input = document.getElementById('globalSearchInput');
    searchModal.show();
    setTimeout(() => input?.focus(), 200);
    if (input?.value.trim()) runSearch(input.value);
    else renderResults([], '');
  }

  document.addEventListener('DOMContentLoaded', () => {
    const trigger = document.getElementById('globalSearchBtn');
    const input = document.getElementById('globalSearchInput');
    const modalEl = document.getElementById('globalSearchModal');

    trigger?.addEventListener('click', openSearchModal);

    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        openSearchModal();
      }
    });

    input?.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => runSearch(input.value), 250);
    });

    modalEl?.addEventListener('shown.bs.modal', () => input?.focus());
  });

  window.addEventListener('app:langchange', () => {
    const input = document.getElementById('globalSearchInput');
    if (input?.value.trim()) runSearch(input.value);
    else renderResults([], '');
  });

  window.openGlobalSearch = openSearchModal;
})();

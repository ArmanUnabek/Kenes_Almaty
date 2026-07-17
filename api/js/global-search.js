(function () {
  const API = window.AppCore?.API_BASE || '/api';
  let debounceTimer = null;
  let dropdownEl = null;
  let activeIndex = -1;

  function t(key, fallback) {
    return window.AppI18n?.t(key, fallback) ?? fallback;
  }

  window.AppI18n?.register?.({
    ru: { 'search.type_to_search': 'Начните вводить для поиска...' },
    kz: { 'search.type_to_search': 'Іздеу үшін тере бастаңыз...' },
  });

  function escapeHtml(str) {
    return window.AppUtils?.escapeHtml?.(str) ?? String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function typeIcon(type) {
    if (type === 'incoming') return 'bi-inbox';
    if (type === 'outgoing') return 'bi-send';
    if (type === 'member') return 'bi-person';
    if (type === 'event') return 'bi-calendar-event';
    if (type === 'comment') return 'bi-chat-left-text';
    return 'bi-search';
  }

  function typeLabel(type) {
    if (type === 'incoming') return t('search.source.incoming', 'Входящее');
    if (type === 'outgoing') return t('search.source.outgoing', 'Исходящее');
    if (type === 'member') return t('search.source.member', 'Член ОС');
    if (type === 'event') return t('search.source.event', 'Мероприятие');
    if (type === 'comment') return t('search.source.comment', 'Комментарий');
    return type;
  }

  function typeBadgeClass(type) {
    if (type === 'incoming') return 'gs-badge-incoming';
    if (type === 'outgoing') return 'gs-badge-outgoing';
    if (type === 'member') return 'gs-badge-member';
    if (type === 'event') return 'gs-badge-event';
    if (type === 'comment') return 'gs-badge-comment';
    return '';
  }

  function ensureDropdown() {
    if (dropdownEl) return dropdownEl;

    dropdownEl = document.createElement('div');
    dropdownEl.className = 'gs-dropdown';
    dropdownEl.id = 'globalSearchDropdown';
    dropdownEl.setAttribute('role', 'listbox');
    document.body.appendChild(dropdownEl);

    document.addEventListener('click', (e) => {
      if (!dropdownEl.contains(e.target) && !e.target.closest('#globalSearchInput, #globalSearchBtn, .global-search-trigger')) {
        hideDropdown();
      }
    });

    return dropdownEl;
  }

  function positionDropdown(input) {
    const rect = input.getBoundingClientRect();
    const dd = ensureDropdown();
    dd.style.top = rect.bottom + window.scrollY + 4 + 'px';
    dd.style.left = rect.left + window.scrollX + 'px';
    dd.style.width = Math.max(rect.width, 420) + 'px';
  }

  function hideDropdown() {
    if (dropdownEl) {
      dropdownEl.innerHTML = '';
      dropdownEl.classList.remove('gs-dropdown--visible');
    }
    activeIndex = -1;
  }

  function showDropdown() {
    ensureDropdown();
    dropdownEl.classList.add('gs-dropdown--visible');
  }

  function renderLoading() {
    const dd = ensureDropdown();
    dd.innerHTML = '<div class="gs-empty"><i class="bi bi-arrow-repeat gs-spin me-2"></i>' + escapeHtml(t('search.searching', 'Поиск...')) + '</div>';
    showDropdown();
  }

  function renderEmpty(query) {
    const dd = ensureDropdown();
    if (!query) {
      dd.innerHTML = '<div class="gs-empty">' + escapeHtml(t('search.type_to_search', 'Начните вводить для поиска...')) + '</div>';
    } else {
      dd.innerHTML = '<div class="gs-empty">' + escapeHtml(t('search.nothing_found', 'Ничего не найдено')) + '</div>';
    }
    showDropdown();
  }

  function renderResults(results, query) {
    const dd = ensureDropdown();
    if (!results.length) {
      renderEmpty(query);
      return;
    }

    const grouped = {};
    results.forEach((r) => {
      if (!grouped[r.type]) grouped[r.type] = [];
      grouped[r.type].push(r);
    });

    let html = '';
    const order = ['member', 'incoming', 'outgoing', 'event', 'comment'];
    order.forEach((type) => {
      const items = grouped[type];
      if (!items || !items.length) return;

      html += '<div class="gs-group">';
      html += '<div class="gs-group__label">' + escapeHtml(typeLabel(type)) + '</div>';
      items.forEach((item, idx) => {
        const globalIdx = results.indexOf(item);
        const title = item.title || '—';
        const meta = item.meta || {};
        let metaLine = '';
        if (type === 'incoming' || type === 'outgoing') {
          metaLine = [meta.number, meta.organization, item.date].filter(Boolean).map(escapeHtml).join(' &middot; ');
        } else if (type === 'member') {
          metaLine = [meta.position, meta.commission].filter(Boolean).map(escapeHtml).join(' &middot; ');
        } else if (type === 'event') {
          metaLine = [meta.location, item.date].filter(Boolean).map(escapeHtml).join(' &middot; ');
        } else if (type === 'comment') {
          metaLine = [meta.user_name, item.date].filter(Boolean).map(escapeHtml).join(' &middot; ');
        }

        html += '<button type="button" class="gs-item" data-index="' + globalIdx + '" role="option">';
        html += '  <span class="gs-item__icon ' + typeBadgeClass(type) + '"><i class="bi ' + typeIcon(type) + '"></i></span>';
        html += '  <span class="gs-item__body">';
        html += '    <span class="gs-item__title">' + escapeHtml(title) + '</span>';
        if (metaLine) {
          html += '    <span class="gs-item__meta">' + metaLine + '</span>';
        }
        html += '  </span>';
        html += '</button>';

        html += '<div class="gs-preview" data-preview="' + globalIdx + '">';
        html += '  <div class="gs-preview__snippet">' + (item.snippet || escapeHtml(item.title || '')) + '</div>';
        html += '</div>';
      });
      html += '</div>';
    });

    dd.innerHTML = html;
    showDropdown();
    activeIndex = -1;

    dd.querySelectorAll('.gs-item').forEach((btn) => {
      btn.addEventListener('click', () => {
        const idx = parseInt(btn.dataset.index, 10);
        navigateTo(results[idx]);
      });

      btn.addEventListener('mouseenter', () => {
        dd.querySelectorAll('.gs-item--active').forEach((el) => el.classList.remove('gs-item--active'));
        btn.classList.add('gs-item--active');
        const idx = parseInt(btn.dataset.index, 10);
        showPreview(idx);
        activeIndex = idx;
      });
    });
  }

  function showPreview(idx) {
    const dd = ensureDropdown();
    dd.querySelectorAll('.gs-preview--visible').forEach((el) => el.classList.remove('gs-preview--visible'));
    const preview = dd.querySelector('[data-preview="' + idx + '"]');
    if (preview) preview.classList.add('gs-preview--visible');
  }

  function navigateTo(item) {
    if (!item) return;
    hideDropdown();

    if (item.type === 'incoming' || item.type === 'outgoing') {
      const tabId = item.type === 'incoming' ? 'tab-incoming' : 'tab-outgoing';
      document.getElementById(tabId)?.click();
      if (window.canWrite?.() && typeof window['edit' + (item.type === 'incoming' ? 'Incoming' : 'Outgoing')] === 'function') {
        setTimeout(() => window['edit' + (item.type === 'incoming' ? 'Incoming' : 'Outgoing')](item.id), 150);
      } else if (typeof window.viewLetterDetail === 'function') {
        setTimeout(() => window.viewLetterDetail(item.type, item.id), 150);
      }
      return;
    }
    if (item.type === 'member') {
      document.getElementById('tab-members')?.click();
      return;
    }
    if (item.type === 'event') {
      document.getElementById('tab-events')?.click();
      return;
    }
    if (item.type === 'comment' && item.meta?.letter_type) {
      const tabId = item.meta.letter_type === 'incoming' ? 'tab-incoming' : 'tab-outgoing';
      document.getElementById(tabId)?.click();
      if (typeof window.viewLetterDetail === 'function') {
        setTimeout(() => window.viewLetterDetail(item.meta.letter_type, item.meta.letter_id), 150);
      }
    }
  }

  async function runSearch(query) {
    const q = query.trim();
    if (!q) {
      hideDropdown();
      return;
    }

    renderLoading();

    try {
      const resp = await fetch(API + '/global_search.php?q=' + encodeURIComponent(q) + '&limit=20');
      const data = await resp.json();
      renderResults(data.results || [], q);
    } catch (err) {
      const dd = ensureDropdown();
      dd.innerHTML = '<div class="gs-empty gs-empty--error"><i class="bi bi-exclamation-triangle me-2"></i>' + escapeHtml(t('search.error', 'Ошибка поиска')) + '</div>';
      showDropdown();
    }
  }

  function handleKeyboard(e) {
    const dd = ensureDropdown();
    if (!dd.classList.contains('gs-dropdown--visible')) return;
    const items = dd.querySelectorAll('.gs-item');
    if (!items.length) return;

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      activeIndex = Math.min(activeIndex + 1, items.length - 1);
      updateActive(items);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      activeIndex = Math.max(activeIndex - 1, 0);
      updateActive(items);
    } else if (e.key === 'Enter' && activeIndex >= 0) {
      e.preventDefault();
      items[activeIndex].click();
    } else if (e.key === 'Escape') {
      hideDropdown();
    }
  }

  function updateActive(items) {
    items.forEach((el, i) => el.classList.toggle('gs-item--active', i === activeIndex));
    if (activeIndex >= 0) {
      items[activeIndex].scrollIntoView({ block: 'nearest' });
      showPreview(activeIndex);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('globalSearchInput');
    if (!input) return;

    input.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => runSearch(input.value), 300);
    });

    input.addEventListener('keydown', handleKeyboard);

    input.addEventListener('focus', () => {
      if (input.value.trim()) runSearch(input.value);
    });

    const trigger = document.getElementById('globalSearchBtn') || document.querySelector('.global-search-trigger');
    trigger?.addEventListener('click', () => {
      input.focus();
      if (input.value.trim()) runSearch(input.value);
    });

    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        input.focus();
        if (input.value.trim()) runSearch(input.value);
      }
    });
  });

  window.GlobalSearch = { runSearch, hideDropdown };
})();

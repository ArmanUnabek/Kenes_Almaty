(function (window) {
  const API_BASE = window.AppCore?.API_BASE || '/api';
  const ENDPOINT = `${API_BASE}/saved_searches.php`;

  let _items = [];
  let _dropdown = null;
  let _currentTab = 'incoming';

  function t(key, fallback) {
    return window.AppI18n?.t(key, fallback) ?? fallback;
  }

  function getCsrfHeaders() {
    const headers = { 'Content-Type': 'application/json' };
    const token = window.AppCore?.csrfToken || window.csrfToken || '';
    if (token) headers['X-CSRF-Token'] = token;
    return headers;
  }

  function getCurrentParams() {
    const params = {};
    const activeTab = document.querySelector('.sidebar-nav .nav-link.active');
    _currentTab = activeTab?.id?.replace('tab-', '') || 'incoming';

    if (_currentTab === 'incoming') {
      params.search = document.getElementById('searchIncoming')?.value || '';
      params.year = document.getElementById('filterYearIncoming')?.value || '';
      params.month = document.getElementById('filterMonthIncoming')?.value || 'all';
      params.status = document.getElementById('filterStatusIncoming')?.value || 'all';
      params.scans = document.getElementById('filterScansIncoming')?.value || 'all';
      params.recipient = document.getElementById('filterRecipientIncoming')?.value || 'all';
      params.tab = 'incoming';
    } else if (_currentTab === 'outgoing') {
      params.search = document.getElementById('searchOutgoing')?.value || '';
      params.year = document.getElementById('filterYearOutgoing')?.value || '';
      params.month = document.getElementById('filterMonthOutgoing')?.value || 'all';
      params.tab = 'outgoing';
    } else if (_currentTab === 'events') {
      params.search = document.getElementById('searchEvents')?.value || '';
      params.tab = 'events';
    } else if (_currentTab === 'members') {
      params.search = document.getElementById('searchMembers')?.value || '';
      params.commission = document.getElementById('filterMembersCommission')?.value || '';
      params.tab = 'members';
    }

    return params;
  }

  function applyParams(params) {
    if (!params) return;

    if (params.tab) {
      const tabBtn = document.getElementById(`tab-${params.tab}`);
      if (tabBtn) tabBtn.click();
    }

    setTimeout(() => {
      if (params.tab === 'incoming' || !params.tab) {
        setSearchValue('searchIncoming', params.search);
        setSelectValue('filterYearIncoming', params.year);
        setSelectValue('filterMonthIncoming', params.month || 'all');
        setSelectValue('filterStatusIncoming', params.status || 'all');
        setSelectValue('filterScansIncoming', params.scans || 'all');
        setSelectValue('filterRecipientIncoming', params.recipient || 'all');
      } else if (params.tab === 'outgoing') {
        setSearchValue('searchOutgoing', params.search);
        setSelectValue('filterYearOutgoing', params.year);
        setSelectValue('filterMonthOutgoing', params.month || 'all');
      } else if (params.tab === 'events') {
        setSearchValue('searchEvents', params.search);
      } else if (params.tab === 'members') {
        setSearchValue('searchMembers', params.search);
        setSelectValue('filterMembersCommission', params.commission || '');
      }

      triggerFilterChange(params.tab || 'incoming');
    }, 200);
  }

  function setSearchValue(id, value) {
    const el = document.getElementById(id);
    if (el && value !== undefined) {
      el.value = value;
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  function setSelectValue(id, value) {
    const el = document.getElementById(id);
    if (el && value !== undefined) {
      el.value = value;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function triggerFilterChange(tab) {
    if (tab === 'incoming') {
      document.getElementById('searchIncoming')?.dispatchEvent(new Event('input'));
    } else if (tab === 'outgoing') {
      document.getElementById('searchOutgoing')?.dispatchEvent(new Event('input'));
    } else if (tab === 'events') {
      document.getElementById('searchEvents')?.dispatchEvent(new Event('input'));
    } else if (tab === 'members') {
      document.getElementById('searchMembers')?.dispatchEvent(new Event('input'));
    }
  }

  async function fetchSavedSearches() {
    try {
      const resp = await fetch(ENDPOINT, { credentials: 'same-origin' });
      if (!resp.ok) return [];
      const data = await resp.json();
      _items = data.items || [];
      return _items;
    } catch (_) {
      return [];
    }
  }

  async function saveSearch(name) {
    const params = getCurrentParams();
    const resp = await fetch(ENDPOINT, {
      method: 'POST',
      headers: getCsrfHeaders(),
      credentials: 'same-origin',
      body: JSON.stringify({ name, params }),
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.error || 'Ошибка сохранения');
    }
    const data = await resp.json();
    _items.unshift({ id: data.id, name: data.name, params: data.params, created_at: new Date().toISOString() });
    renderDropdown();
    return data;
  }

  async function deleteSearch(id) {
    const resp = await fetch(`${ENDPOINT}?id=${id}`, {
      method: 'DELETE',
      headers: getCsrfHeaders(),
      credentials: 'same-origin',
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.error || 'Ошибка удаления');
    }
    _items = _items.filter((s) => s.id !== id);
    renderDropdown();
  }

  function loadSearch(id) {
    const item = _items.find((s) => s.id === id);
    if (item) applyParams(item.params);
  }

  function createDropdown() {
    const existing = document.getElementById('savedSearchesDropdown');
    if (existing) return existing;

    const searchFields = document.querySelectorAll('.search-field');
    searchFields.forEach((field) => {
      if (field.querySelector('.saved-searches-trigger')) return;

      const wrapper = document.createElement('div');
      wrapper.className = 'saved-searches-trigger';
      wrapper.innerHTML = `
        <button type="button" class="btn btn-sm btn-outline-secondary saved-searches-btn" title="Сохранённые поиски">
          <i class="bi bi-bookmark"></i>
        </button>
        <div class="saved-searches-dropdown d-none">
          <div class="saved-searches-dropdown__header">
            <strong>Сохранённые поиски</strong>
            <button type="button" class="btn btn-sm btn-primary saved-searches-save-btn">
              <i class="bi bi-plus-lg me-1"></i>Сохранить текущий
            </button>
          </div>
          <div class="saved-searches-dropdown__list"></div>
        </div>
      `;

      field.appendChild(wrapper);

      const btn = wrapper.querySelector('.saved-searches-btn');
      const dropdown = wrapper.querySelector('.saved-searches-dropdown');
      const saveBtn = wrapper.querySelector('.saved-searches-save-btn');

      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isVisible = !dropdown.classList.contains('d-none');
        closeAllDropdowns();
        if (!isVisible) {
          dropdown.classList.remove('d-none');
          renderDropdownList(wrapper);
        }
      });

      saveBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const name = prompt('Название сохранённого поиска:');
        if (!name || !name.trim()) return;
        try {
          await saveSearch(name.trim());
          window.showSuccess?.('Поиск сохранён');
        } catch (err) {
          window.showError?.(err.message);
        }
      });
    });

    document.addEventListener('click', () => closeAllDropdowns());
  }

  function closeAllDropdowns() {
    document.querySelectorAll('.saved-searches-dropdown').forEach((d) => d.classList.add('d-none'));
  }

  function renderDropdown() {
    document.querySelectorAll('.saved-searches-trigger').forEach(renderDropdownList);
  }

  function renderDropdownList(wrapper) {
    const list = wrapper.querySelector('.saved-searches-dropdown__list');
    if (!list) return;

    if (_items.length === 0) {
      list.innerHTML = '<div class="saved-searches-dropdown__empty">Нет сохранённых поисков</div>';
      return;
    }

    list.innerHTML = _items.map((s) => `
      <div class="saved-searches-dropdown__item" data-search-id="${s.id}">
        <div class="saved-searches-dropdown__item-info" data-search-id="${s.id}">
          <span class="saved-searches-dropdown__item-name">${escapeHtml(s.name)}</span>
          <span class="saved-searches-dropdown__item-meta">${s.params?.tab || 'incoming'} · ${escapeHtml(s.params?.search || '—')}</span>
        </div>
        <button class="btn btn-sm btn-outline-danger saved-searches-delete-btn" data-delete-id="${s.id}" title="Удалить">
          <i class="bi bi-trash"></i>
        </button>
      </div>
    `).join('');

    list.querySelectorAll('.saved-searches-dropdown__item-info').forEach((el) => {
      el.addEventListener('click', () => {
        loadSearch(Number(el.dataset.searchId));
        closeAllDropdowns();
      });
    });

    list.querySelectorAll('.saved-searches-delete-btn').forEach((el) => {
      el.addEventListener('click', async (e) => {
        e.stopPropagation();
        const id = Number(el.dataset.deleteId);
        if (!confirm('Удалить сохранённый поиск?')) return;
        try {
          await deleteSearch(id);
          window.showSuccess?.('Поиск удалён');
        } catch (err) {
          window.showError?.(err.message);
        }
      });
    });
  }

  function escapeHtml(str) {
    return window.AppUtils?.escapeHtml?.(str) ?? String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  async function init() {
    createDropdown();
    await fetchSavedSearches();
    renderDropdown();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => init());
  } else {
    init();
  }

  window.AppSavedSearches = { init, fetchSavedSearches, saveSearch, deleteSearch, loadSearch, getCurrentParams, applyParams };
})(window);

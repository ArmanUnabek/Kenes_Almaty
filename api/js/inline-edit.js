/**
 * Inline editing for letter fields (status, responsible) in table views.
 * Click a cell → dropdown appears → select → auto-saves via PATCH.
 */
(function (window) {
  const API_BASE = window.API_BASE || '/api';
  const DEBOUNCE_MS = 400;
  const t = (k, fb) => window.AppI18n?.t(k, fb) ?? fb;

  window.AppI18n?.register?.({
    ru: {
      'inline_edit.saved': 'Сохранено',
      'inline_edit.error': 'Не удалось сохранить',
      'inline_edit.status.pending': 'В обработке',
      'inline_edit.status.done': 'Выполнено',
      'inline_edit.status.overdue': 'Просрочено',
      'inline_edit.search_member': 'Поиск...',
    },
    kz: {
      'inline_edit.saved': 'Сақталды',
      'inline_edit.error': 'Сақтау сәтсіз',
      'inline_edit.status.pending': 'Өңделуде',
      'inline_edit.status.done': 'Орындалды',
      'inline_edit.status.overdue': 'Мерзімі өткен',
      'inline_edit.search_member': 'Іздеу...',
    },
  });
  const escapeHtml = window.AppUtils?.escapeHtml || ((s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'));
  const debounce = window.AppUtils?.debounce || ((fn, w) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), w); }; });

  let _activeDropdown = null;
  let _activeCell = null;

  function closeDropdown() {
    if (_activeDropdown) {
      _activeDropdown.remove();
      _activeDropdown = null;
    }
    if (_activeCell) {
      _activeCell.classList.remove('inline-edit-active');
      _activeCell = null;
    }
    document.removeEventListener('click', onOutsideClick, true);
  }

  function onOutsideClick(e) {
    if (_activeDropdown && !_activeDropdown.contains(e.target) && _activeCell && !_activeCell.contains(e.target)) {
      closeDropdown();
    }
  }

  function showSpinner(cell) {
    const existing = cell.querySelector('.inline-edit-indicator');
    if (existing) existing.remove();
    const el = document.createElement('span');
    el.className = 'inline-edit-indicator inline-edit-spinner';
    el.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.8s" repeatCount="indefinite"/></path></svg>';
    cell.appendChild(el);
    return el;
  }

  function showCheck(cell) {
    const existing = cell.querySelector('.inline-edit-indicator');
    if (existing) existing.remove();
    const el = document.createElement('span');
    el.className = 'inline-edit-indicator inline-edit-check';
    el.textContent = '✓';
    cell.appendChild(el);
    setTimeout(() => el.remove(), 1500);
  }

  function showError(cell) {
    const existing = cell.querySelector('.inline-edit-indicator');
    if (existing) existing.remove();
    const el = document.createElement('span');
    el.className = 'inline-edit-indicator inline-edit-error';
    el.textContent = '✗';
    cell.appendChild(el);
    setTimeout(() => el.remove(), 2000);
  }

  async function patchField(type, id, field, value) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
      || window.csrfToken || '';
    const resp = await fetch(`${API_BASE}/letters.php?type=${type}&id=${id}`, {
      method: 'PATCH',
      headers: {
        'Content-Type': 'application/json',
        ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
      },
      body: JSON.stringify({ field, value }),
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.error || 'Ошибка сохранения');
    }
    return resp.json();
  }

  const debouncedPatch = debounce(async (type, id, field, value, cell) => {
    try {
      const spinner = showSpinner(cell);
      await patchField(type, id, field, value);
      spinner.remove();
      showCheck(cell);
      if (window.showSuccess) {
        window.showSuccess(t('inline_edit.saved', 'Сохранено'));
      }
      if (typeof window.refreshLetters === 'function') {
        await window.refreshLetters();
        if (typeof window.renderAll === 'function') window.renderAll();
      }
    } catch (e) {
      console.error('Inline edit save failed:', e);
      const sp = cell.querySelector('.inline-edit-spinner');
      if (sp) sp.remove();
      showError(cell);
      if (window.showError) {
        window.showError(t('inline_edit.error', 'Не удалось сохранить'));
      }
    }
  }, DEBOUNCE_MS);

  function openStatusDropdown(cell, type, id, currentValue) {
    closeDropdown();
    _activeCell = cell;
    cell.classList.add('inline-edit-active');

    const statuses = [
      { value: 'pending',  label: t('inline_edit.status.pending',  'В обработке'), cls: 'badge-status-pending' },
      { value: 'done',     label: t('inline_edit.status.done',     'Выполнено'),   cls: 'badge-status-done' },
      { value: 'overdue',  label: t('inline_edit.status.overdue',  'Просрочено'),  cls: 'badge-due-danger' },
    ];

    const dd = document.createElement('div');
    dd.className = 'inline-edit-dropdown';
    statuses.forEach((s) => {
      const opt = document.createElement('button');
      opt.type = 'button';
      opt.className = 'inline-edit-option' + (s.value === currentValue ? ' inline-edit-option--active' : '');
      opt.innerHTML = `<span class="badge rounded-pill ${s.cls}">${escapeHtml(s.label)}</span>`;
      opt.addEventListener('click', (e) => {
        e.stopPropagation();
        cell.dataset.value = s.value;
        const badge = cell.querySelector('.badge');
        if (badge) {
          badge.className = `badge rounded-pill ${s.cls}`;
          badge.textContent = s.label;
        }
        closeDropdown();
        debouncedPatch(type, id, 'status', s.value, cell);
      });
      dd.appendChild(opt);
    });

    positionDropdown(cell, dd);
    document.addEventListener('click', onOutsideClick, true);
  }

  function openResponsibleDropdown(cell, type, id, currentMembers) {
    closeDropdown();
    _activeCell = cell;
    cell.classList.add('inline-edit-active');

    const catalog = Array.isArray(window.membersCatalog) ? window.membersCatalog : [];
    const currentIds = (currentMembers || []).map((m) => Number(m.member_id || m.id));

    const dd = document.createElement('div');
    dd.className = 'inline-edit-dropdown inline-edit-dropdown--wide';

    const search = document.createElement('input');
    search.type = 'text';
    search.className = 'form-control form-control-sm mb-1';
    search.placeholder = t('inline_edit.search_member', 'Поиск...');
    dd.appendChild(search);

    const list = document.createElement('div');
    list.className = 'inline-edit-member-list';
    dd.appendChild(list);

    function renderList(filter) {
      list.innerHTML = '';
      const q = (filter || '').toLowerCase();
      catalog
        .filter((m) => !q || (m.full_name || '').toLowerCase().includes(q))
        .forEach((m) => {
          const isSelected = currentIds.includes(Number(m.id));
          const opt = document.createElement('label');
          opt.className = 'inline-edit-member-option' + (isSelected ? ' inline-edit-option--active' : '');
          opt.innerHTML = `<input type="checkbox" value="${m.id}" ${isSelected ? 'checked' : ''} class="form-check-input me-1"> ${escapeHtml(m.full_name)}`;
          list.appendChild(opt);
        });
    }

    renderList('');
    search.addEventListener('input', () => renderList(search.value));

    const saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'btn btn-sm btn-primary mt-1 w-100';
    saveBtn.textContent = t('common.save', 'Сохранить');
    saveBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const checked = Array.from(list.querySelectorAll('input[type=checkbox]:checked'))
        .map((cb) => ({ member_id: Number(cb.value), is_lead: false }));
      if (checked.length > 0) checked[0].is_lead = true;

      const names = checked.map((c) => {
        const m = catalog.find((x) => Number(x.id) === c.member_id);
        return m ? m.full_name : '';
      }).filter(Boolean);

      const chips = cell.querySelector('.members-chips');
      if (chips) {
        chips.innerHTML = names.length
          ? names.map((n) => `<span class="badge rounded-pill bg-light text-dark border me-1">${escapeHtml(n)}</span>`).join('')
          : '<span class="text-muted">—</span>';
      }

      closeDropdown();
      debouncedPatch(type, id, 'members', checked, cell);
    });
    dd.appendChild(saveBtn);

    positionDropdown(cell, dd);
    document.addEventListener('click', onOutsideClick, true);
    setTimeout(() => search.focus(), 50);
  }

  function positionDropdown(cell, dd) {
    document.body.appendChild(dd);
    const rect = cell.getBoundingClientRect();
    dd.style.position = 'fixed';
    dd.style.top = (rect.bottom + 4) + 'px';
    dd.style.left = rect.left + 'px';
    dd.style.zIndex = '10000';

    requestAnimationFrame(() => {
      const ddRect = dd.getBoundingClientRect();
      if (ddRect.right > window.innerWidth) {
        dd.style.left = (window.innerWidth - ddRect.width - 8) + 'px';
      }
      if (ddRect.bottom > window.innerHeight) {
        dd.style.top = (rect.top - ddRect.height - 4) + 'px';
      }
    });

    _activeDropdown = dd;
  }

  function handleCellClick(e) {
    const cell = e.target.closest('[data-inline-field]');
    if (!cell) return;
    if (!window.canWrite?.()) return;

    const field = cell.dataset.inlineField;
    const type = cell.dataset.inlineType;
    const id = cell.dataset.inlineId;

    if (!field || !type || !id) return;

    e.stopPropagation();

    if (field === 'status') {
      openStatusDropdown(cell, type, id, cell.dataset.value || 'pending');
    } else if (field === 'members') {
      let members = [];
      try { members = JSON.parse(cell.dataset.members || '[]'); } catch (_) {}
      openResponsibleDropdown(cell, type, id, members);
    }
  }

  function init() {
    document.addEventListener('click', handleCellClick);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeDropdown();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.AppInlineEdit = {
    close: closeDropdown,
    refresh: init,
  };
})(window);

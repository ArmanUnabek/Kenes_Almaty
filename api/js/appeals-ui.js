/**
 * UI обращений граждан: таблица, фильтры, модальное окно деталей.
 */
(function (window) {
  const API_BASE = window.API_BASE;
  const store = window.store;
  const canWrite = () => window.canWrite?.() ?? false;
  const t = (key, fb) => window.AppI18n?.t(key, fb) ?? fb;
  const fmt = (key, vars, fb) => {
    if (window.AppI18n?.fmt) return window.AppI18n.fmt(key, vars);
    let out = fb;
    Object.entries(vars || {}).forEach(([k, v]) => { out = out.replace('{' + k + '}', String(v)); });
    return out;
  };

  window.AppI18n?.register?.({
    ru: {
      'appeals.cat_complaint': 'Жалоба',
      'appeals.cat_suggestion': 'Предложение',
      'appeals.cat_question': 'Вопрос',
      'appeals.cat_request': 'Заявление',
      'appeals.cat_other': 'Иное',
      'appeals.status_new': 'Новое',
      'appeals.status_in_review': 'На рассмотрении',
      'appeals.status_responded': 'Отвечено',
      'appeals.status_closed': 'Закрыто',
      'appeals.view': 'Подробнее',
      'appeals.th_number': 'Номер',
      'appeals.th_subject': 'Тема',
      'appeals.th_date': 'Дата',
      'appeals.empty': 'Обращений не найдено',
      'appeals.shown_fmt': 'Показано {start}–{end} из {total}',
      'appeals.load_error': 'Ошибка загрузки',
      'appeals.load_data_error': 'Ошибка загрузки данных',
      'appeals.full_name': 'ФИО',
      'appeals.category': 'Категория',
      'appeals.phone': 'Телефон',
      'appeals.region': 'Регион',
      'appeals.executor': 'Исполнитель',
      'appeals.not_assigned': '— Не назначен —',
      'appeals.text_label': 'Текст обращения:',
      'appeals.reply_label': 'Ответ:',
      'appeals.status_label': 'Статус',
      'appeals.reply_to_citizen': 'Ответ гражданину',
      'appeals.save': 'Сохранить',
      'appeals.updated': 'Обращение обновлено',
      'appeals.save_error': 'Не удалось сохранить изменения',
    },
    kz: {
      'appeals.cat_complaint': 'Шағым',
      'appeals.cat_suggestion': 'Ұсыныс',
      'appeals.cat_question': 'Сұрақ',
      'appeals.cat_request': 'Өтініш',
      'appeals.cat_other': 'Өзге',
      'appeals.status_new': 'Жаңа',
      'appeals.status_in_review': 'Қаралуда',
      'appeals.status_responded': 'Жауап берілді',
      'appeals.status_closed': 'Жабылды',
      'appeals.view': 'Толығырақ',
      'appeals.th_number': 'Нөмір',
      'appeals.th_subject': 'Тақырып',
      'appeals.th_date': 'Күні',
      'appeals.empty': 'Өтініштер табылмады',
      'appeals.shown_fmt': '{total} ішінен {start}–{end} көрсетілді',
      'appeals.load_error': 'Жүктеу қатесі',
      'appeals.load_data_error': 'Деректерді жүктеу қатесі',
      'appeals.full_name': 'Аты-жөні',
      'appeals.category': 'Санат',
      'appeals.phone': 'Телефон',
      'appeals.region': 'Аймақ',
      'appeals.executor': 'Орындаушы',
      'appeals.not_assigned': '— Тағайындалмаған —',
      'appeals.text_label': 'Өтініш мәтіні:',
      'appeals.reply_label': 'Жауап:',
      'appeals.status_label': 'Күйі',
      'appeals.reply_to_citizen': 'Азаматқа жауап',
      'appeals.save': 'Сақтау',
      'appeals.updated': 'Өтініш жаңартылды',
      'appeals.save_error': 'Өзгерістерді сақтау сәтсіз',
    },
  });

  const categoryLabel = (c) => t('appeals.cat_' + c, CATEGORY_LABELS[c] || c);
  const statusLabel = (st) => t('appeals.status_' + st, STATUS_LABELS[st] || st);

  store.appeals = store.appeals || [];

  const CATEGORY_LABELS = {
    complaint:   'Жалоба',
    suggestion:  'Предложение',
    question:    'Вопрос',
    request:     'Заявление',
    other:       'Иное',
  };

  const STATUS_LABELS = {
    new:         'Новое',
    in_review:   'На рассмотрении',
    responded:   'Отвечено',
    closed:      'Закрыто',
  };

  const STATUS_BADGE_CLASS = {
    new:         'badge-appeal-new',
    in_review:   'badge-appeal-review',
    responded:   'badge-appeal-responded',
    closed:      'badge-appeal-closed',
  };

  let currentPage  = 1;
  let totalAppeals = 0;
  let filterStatus   = '';
  let filterCategory = '';
  let searchQuery    = '';
  let refreshTimer   = null;

  async function refreshAppeals() {
    const params = new URLSearchParams({ page: currentPage, limit: 30 });
    if (filterStatus)   params.append('status', filterStatus);
    if (filterCategory) params.append('category', filterCategory);
    if (searchQuery)    params.append('search', searchQuery);
    try {
      const res = await fetch(`${API_BASE}/appeals.php?${params}`);
      if (!res.ok) return;
      const data = await res.json();
      store.appeals = data.items || [];
      totalAppeals  = data.pagination?.total || 0;
      renderAppeals();
      updateBadge();
    } catch { /* non-critical — user not on this tab */ }
  }

  function updateBadge() {
    const badge = document.getElementById('appealsNewBadge');
    if (!badge) return;
    const newCount = store.appeals.filter((a) => a.status === 'new').length;
    if (newCount > 0) {
      badge.textContent = newCount;
      badge.style.display = '';
    } else {
      badge.style.display = 'none';
    }
  }

  function renderAppeals() {
    const tbody = document.querySelector('#tableAppeals tbody');
    if (!tbody) return;

    const rows = store.appeals.map((a) => {
      const statusBadge = `<span class="badge ${STATUS_BADGE_CLASS[a.status] || 'bg-secondary'}">${statusLabel(a.status)}</span>`;
      const dateStr = a.created_at ? new Date(a.created_at).toLocaleDateString('ru-RU') : '—';
      return `<tr>
        <td data-label="${t('appeals.th_number', 'Номер')}"><code>${escapeHtml(a.appeal_number || String(a.id))}</code></td>
        <td data-label="${t('appeals.full_name', 'ФИО')}">${escapeHtml(a.full_name)}</td>
        <td data-label="${t('appeals.th_subject', 'Тема')}" class="text-truncate" style="max-width:200px">${escapeHtml(a.subject)}</td>
        <td data-label="${t('appeals.category', 'Категория')}">${categoryLabel(a.category)}</td>
        <td data-label="${t('appeals.status_label', 'Статус')}">${statusBadge}</td>
        <td data-label="${t('appeals.region', 'Регион')}" class="text-muted small">${escapeHtml(a.region_name || '—')}</td>
        <td data-label="${t('appeals.th_date', 'Дата')}" class="text-muted small">${dateStr}</td>
        <td class="table-actions" data-label="">
          <button class="btn btn-sm btn-outline-secondary" data-action="view-appeal" data-id="${a.id}" title="${t('appeals.view', 'Подробнее')}">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        </td>
      </tr>`;
    }).join('');

    tbody.innerHTML = rows || `<tr><td colspan="8" class="text-center text-muted">${t('appeals.empty', 'Обращений не найдено')}</td></tr>`;

    // Pagination controls
    const foot = document.getElementById('appealsPaginationInfo');
    if (foot) {
      const totalPages = Math.ceil(totalAppeals / 30);
      if (totalAppeals > 0) {
        const start = (currentPage - 1) * 30 + 1;
        const end   = Math.min(currentPage * 30, totalAppeals);
        foot.innerHTML =
          `<div class="d-flex align-items-center gap-2">
            <button id="appealsPrevPage" class="btn btn-sm btn-outline-secondary" ${currentPage <= 1 ? 'disabled' : ''}>‹</button>
            <span class="small text-muted">${fmt('appeals.shown_fmt', { start, end, total: totalAppeals }, `Показано ${start}–${end} из ${totalAppeals}`)}</span>
            <button id="appealsNextPage" class="btn btn-sm btn-outline-secondary" ${currentPage >= totalPages ? 'disabled' : ''}>›</button>
          </div>`;
      } else {
        foot.innerHTML = '';
      }
    }
  }

  async function viewAppeal(id) {
    const body = document.getElementById('appealDetailBody');
    if (!body) return;
    body.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('appealDetailModal')).show();

    try {
      const res = await fetch(`${API_BASE}/appeals.php?id=${id}`);
      if (!res.ok) { body.textContent = t('appeals.load_error', 'Ошибка загрузки'); return; }
      const a = await res.json();

      const statusBadge = `<span class="badge ${STATUS_BADGE_CLASS[a.status] || 'bg-secondary'}">${statusLabel(a.status)}</span>`;
      const dateStr = a.created_at ? new Date(a.created_at).toLocaleDateString('ru-RU') : '—';

      let html = `
        <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
          <div>
            <h6 class="fw-bold mb-0">${escapeHtml(a.subject)}</h6>
            <small class="text-muted">${escapeHtml(a.appeal_number || '')} · ${dateStr}</small>
          </div>
          ${statusBadge}
        </div>
        <div class="row g-2 mb-3 small">
          <div class="col-6"><span class="text-muted">${t('appeals.full_name', 'ФИО')}:</span> ${escapeHtml(a.full_name)}</div>
          <div class="col-6"><span class="text-muted">${t('appeals.category', 'Категория')}:</span> ${categoryLabel(a.category)}</div>
          ${a.email ? `<div class="col-6"><span class="text-muted">Email:</span> <a href="mailto:${escapeHtml(a.email)}">${escapeHtml(a.email)}</a></div>` : ''}
          ${a.phone ? `<div class="col-6"><span class="text-muted">${t('appeals.phone', 'Телефон')}:</span> ${escapeHtml(a.phone)}</div>` : ''}
          ${a.region_name ? `<div class="col-6"><span class="text-muted">${t('appeals.region', 'Регион')}:</span> ${escapeHtml(a.region_name)}</div>` : ''}
          ${a.assigned_name ? `<div class="col-6"><span class="text-muted">${t('appeals.executor', 'Исполнитель')}:</span> ${escapeHtml(a.assigned_name)}</div>` : ''}
        </div>
        <div class="mb-3">
          <div class="text-muted small mb-1">${t('appeals.text_label', 'Текст обращения:')}</div>
          <div class="p-2 bg-light rounded small" style="white-space:pre-wrap">${escapeHtml(a.message)}</div>
        </div>`;

      if (a.response_text) {
        html += `<div class="mb-3">
          <div class="text-muted small mb-1">${t('appeals.reply_label', 'Ответ:')}</div>
          <div class="p-2 bg-light rounded small" style="white-space:pre-wrap">${escapeHtml(a.response_text)}</div>
        </div>`;
      }

      if (canWrite()) {
        const members = window.membersCatalog || [];
        const memberOptions = members.map((m) =>
          `<option value="${m.id}" ${a.assigned_member_id === m.id ? 'selected' : ''}>${escapeHtml(m.full_name)}</option>`
        ).join('');

        html += `<hr class="my-3"/>
          <div class="row g-2" id="appealUpdateForm" data-id="${a.id}">
            <div class="col-12 col-sm-6">
              <label class="form-label small mb-1">${t('appeals.status_label', 'Статус')}</label>
              <select class="form-select form-select-sm" id="appealStatusSelect">
                <option value="new"       ${a.status === 'new'       ? 'selected' : ''}>${statusLabel('new')}</option>
                <option value="in_review" ${a.status === 'in_review' ? 'selected' : ''}>${statusLabel('in_review')}</option>
                <option value="responded" ${a.status === 'responded' ? 'selected' : ''}>${statusLabel('responded')}</option>
                <option value="closed"    ${a.status === 'closed'    ? 'selected' : ''}>${statusLabel('closed')}</option>
              </select>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label small mb-1">${t('appeals.executor', 'Исполнитель')}</label>
              <select class="form-select form-select-sm" id="appealMemberSelect">
                <option value="">${t('appeals.not_assigned', '— Не назначен —')}</option>
                ${memberOptions}
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small mb-1">${t('appeals.reply_to_citizen', 'Ответ гражданину')}</label>
              <textarea class="form-control form-control-sm" id="appealResponseText" rows="3">${escapeHtml(a.response_text || '')}</textarea>
            </div>
            <div class="col-12">
              <button class="btn btn-sm btn-primary" id="appealSaveBtn">${t('appeals.save', 'Сохранить')}</button>
            </div>
          </div>`;
      }

      body.innerHTML = html;

      if (canWrite()) {
        document.getElementById('appealSaveBtn')?.addEventListener('click', () => saveAppealUpdate(a.id));
      }
    } catch (e) {
      console.error('viewAppeal error:', e);
      body.textContent = t('appeals.load_data_error', 'Ошибка загрузки данных');
    }
  }

  async function saveAppealUpdate(id) {
    const status     = document.getElementById('appealStatusSelect')?.value;
    const memberId   = document.getElementById('appealMemberSelect')?.value;
    const response   = document.getElementById('appealResponseText')?.value.trim();
    const btn        = document.getElementById('appealSaveBtn');
    if (btn) btn.disabled = true;

    try {
      const res = await fetch(`${API_BASE}/appeals.php`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id,
          status,
          assigned_member_id: memberId ? Number(memberId) : null,
          response_text: response || null,
        }),
      });
      if (!res.ok) throw new Error('update failed');
      bootstrap.Modal.getOrCreateInstance(document.getElementById('appealDetailModal')).hide();
      await refreshAppeals();
      window.showSuccess?.(t('appeals.updated', 'Обращение обновлено'));
    } catch {
      window.showError?.(t('appeals.save_error', 'Не удалось сохранить изменения'));
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  function bindAppealsUI() {
    // Tab shown → load
    document.addEventListener('shown.bs.tab', (e) => {
      if (e.target?.id === 'tab-appeals') {
        refreshAppeals();
      }
    });

    // Single delegated handler on the pane (covers dynamic tbody too)
    document.getElementById('pane-appeals')?.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-action="view-appeal"]');
      if (btn) viewAppeal(btn.dataset.id);

      if (e.target.id === 'appealsPrevPage') {
        if (currentPage > 1) { currentPage--; refreshAppeals(); }
      }
      if (e.target.id === 'appealsNextPage') {
        if (currentPage * 30 < totalAppeals) { currentPage++; refreshAppeals(); }
      }
    });

    // Filters
    document.getElementById('appealFilterStatus')?.addEventListener('change', (e) => {
      filterStatus = e.target.value;
      currentPage = 1;
      refreshAppeals();
    });
    document.getElementById('appealFilterCategory')?.addEventListener('change', (e) => {
      filterCategory = e.target.value;
      currentPage = 1;
      refreshAppeals();
    });
    document.getElementById('appealSearch')?.addEventListener('input', (e) => {
      searchQuery = e.target.value.trim();
      currentPage = 1;
      refreshAppeals();
    });

    // Auto-refresh every 5 min when on the tab
    refreshTimer = setInterval(() => {
      const pane = document.getElementById('pane-appeals');
      if (pane?.classList.contains('show')) refreshAppeals();
    }, 300000);
  }

  // Initialise when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindAppealsUI);
  } else {
    bindAppealsUI();
  }

  window.refreshAppeals = refreshAppeals;
  window.renderAppeals  = renderAppeals;
})(window);

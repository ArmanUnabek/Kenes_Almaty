// «Последние ошибки» — блок для админа в модалке «Мой профиль».
// Не входит в бандл: подключается отдельным <script> тегом.
(function () {
  'use strict';

  const API_BASE = window.AppCore?.API_BASE || '/api';
  const ENDPOINT = `${API_BASE}/admin_errors.php`;

  const t = (k, fb) => window.AppI18n?.t(k, fb) ?? fb;

  window.AppI18n?.register?.({
    ru: {
      'errors.title': 'Последние ошибки',
      'errors.refresh': 'Обновить',
      'errors.loading': 'Загрузка…',
      'errors.empty': 'Ошибок не найдено',
      'errors.load_error': 'Не удалось загрузить журнал ошибок',
      'errors.th_time': 'Время',
      'errors.th_level': 'Уровень',
      'errors.th_message': 'Сообщение',
    },
    kz: {
      'errors.title': 'Соңғы қателер',
      'errors.refresh': 'Жаңарту',
      'errors.loading': 'Жүктелуде…',
      'errors.empty': 'Қателер табылмады',
      'errors.load_error': 'Қателер журналын жүктеу сәтсіз',
      'errors.th_time': 'Уақыты',
      'errors.th_level': 'Деңгейі',
      'errors.th_message': 'Хабарлама',
    },
  });

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = String(str ?? '');
    return div.innerHTML;
  }

  function isAdmin() {
    const user = window.AppCore?.getSessionUser?.() || window.sessionUser;
    return !!(user && (user.is_admin || user.role === 'admin'));
  }

  function levelBadge(level) {
    const lv = String(level || '').toUpperCase();
    let cls = 'bg-secondary';
    if (/FATAL|ERROR/.test(lv)) cls = 'bg-danger';
    else if (/WARN/.test(lv)) cls = 'bg-warning text-dark';
    else if (/NOTICE|DEPRECATED|INFO/.test(lv)) cls = 'bg-info text-dark';
    return `<span class="badge ${cls}">${escapeHtml(lv || '—')}</span>`;
  }

  async function loadErrors() {
    const tbody = document.getElementById('adminErrorsTableBody');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="3" class="text-muted small">${t('errors.loading', 'Загрузка…')}</td></tr>`;
    try {
      const res = await fetch(`${ENDPOINT}?limit=100`, { credentials: 'same-origin' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      const items = data.items || [];
      if (!items.length) {
        tbody.innerHTML = `<tr><td colspan="3" class="text-muted small">${t('errors.empty', 'Ошибок не найдено')}</td></tr>`;
        return;
      }
      tbody.innerHTML = items
        .map(
          (it) => `
        <tr>
          <td class="small text-nowrap">${escapeHtml(it.time || '—')}</td>
          <td>${levelBadge(it.level)}</td>
          <td class="small" style="word-break:break-word">${escapeHtml(it.message || '')}</td>
        </tr>`
        )
        .join('');
    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="3" class="text-danger small">${t('errors.load_error', 'Не удалось загрузить журнал ошибок')}</td></tr>`;
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('profileModal');
    const block = document.getElementById('adminErrorsBlock');
    const refreshBtn = document.getElementById('adminErrorsRefreshBtn');
    if (!modal || !block) return;

    modal.addEventListener('shown.bs.modal', () => {
      if (!isAdmin()) {
        block.classList.add('d-none');
        return;
      }
      block.classList.remove('d-none');
      loadErrors();
    });

    refreshBtn?.addEventListener('click', () => {
      if (isAdmin()) loadErrors();
    });
  });
})();

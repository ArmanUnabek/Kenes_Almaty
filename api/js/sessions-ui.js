// Активные сессии пользователя (блок в модалке «Мой профиль»)
(function () {
  'use strict';

  const API_BASE = window.AppCore?.API_BASE || '/api';
  const ENDPOINT = `${API_BASE}/sessions.php`;

  // X-CSRF-Token подставляется автоматически перехватчиком window.fetch
  // из csrf-handler.js (собран в /dist/app.js, грузится раньше этого файла).
  const JSON_HEADERS = { 'Content-Type': 'application/json' };

  const t = (k, fb) => window.AppI18n?.t(k, fb) ?? fb;

  window.AppI18n?.register?.({
    ru: {
      'sessions.unknown_device': 'Неизвестное устройство',
      'sessions.browser': 'Браузер',
      'sessions.loading': 'Загрузка…',
      'sessions.empty': 'Нет активных сессий',
      'sessions.current': 'текущая',
      'sessions.terminate': 'Завершить',
      'sessions.load_error': 'Не удалось загрузить сессии',
      'sessions.terminated': 'Сессия завершена',
      'sessions.terminate_error': 'Не удалось завершить сессию',
      'sessions.all_terminated': 'Другие сессии завершены',
      'sessions.terminate_all_error': 'Не удалось завершить сессии',
      'sessions.error': 'Ошибка',
    },
    kz: {
      'sessions.unknown_device': 'Белгісіз құрылғы',
      'sessions.browser': 'Браузер',
      'sessions.loading': 'Жүктелуде…',
      'sessions.empty': 'Белсенді сессиялар жоқ',
      'sessions.current': 'ағымдағы',
      'sessions.terminate': 'Аяқтау',
      'sessions.load_error': 'Сессиялар жүктелмеді',
      'sessions.terminated': 'Сессия аяқталды',
      'sessions.terminate_error': 'Сессияны аяқтау сәтсіз',
      'sessions.all_terminated': 'Басқа сессиялар аяқталды',
      'sessions.terminate_all_error': 'Сессияларды аяқтау сәтсіз',
      'sessions.error': 'Қате',
    },
  });

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = String(str ?? '');
    return div.innerHTML;
  }

  function parseUserAgent(ua) {
    if (!ua) return t('sessions.unknown_device', 'Неизвестное устройство');
    let browser = t('sessions.browser', 'Браузер');
    if (/Edg\//.test(ua)) browser = 'Edge';
    else if (/OPR\/|Opera/.test(ua)) browser = 'Opera';
    else if (/Firefox\//.test(ua)) browser = 'Firefox';
    else if (/Chrome\//.test(ua)) browser = 'Chrome';
    else if (/Safari\//.test(ua)) browser = 'Safari';
    let os = '';
    if (/Windows/.test(ua)) os = 'Windows';
    else if (/Android/.test(ua)) os = 'Android';
    else if (/iPhone|iPad|iOS/.test(ua)) os = 'iOS';
    else if (/Mac OS X|Macintosh/.test(ua)) os = 'macOS';
    else if (/Linux/.test(ua)) os = 'Linux';
    return os ? `${browser} · ${os}` : browser;
  }

  function showAlert(msg, type) {
    const el = document.getElementById('sessionsAlert');
    if (!el) return;
    el.className = `alert alert-${type || 'info'} py-2 small mb-2`;
    el.textContent = msg;
    el.classList.remove('d-none');
  }

  function hideAlert() {
    document.getElementById('sessionsAlert')?.classList.add('d-none');
  }

  async function loadSessions() {
    const tbody = document.getElementById('sessionsTableBody');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="4" class="text-muted small">${t('sessions.loading', 'Загрузка…')}</td></tr>`;
    try {
      const res = await fetch(ENDPOINT, { credentials: 'same-origin' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      const sessions = data.sessions || [];
      if (!sessions.length) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-muted small">${t('sessions.empty', 'Нет активных сессий')}</td></tr>`;
        return;
      }
      tbody.innerHTML = sessions.map((s) => `
        <tr>
          <td>
            <div class="small fw-semibold">${escapeHtml(parseUserAgent(s.user_agent))}
              ${s.is_current ? `<span class="badge bg-success ms-1">${t('sessions.current', 'текущая')}</span>` : ''}
            </div>
            <div class="text-muted" style="font-size:.75rem">${escapeHtml(s.id)}</div>
          </td>
          <td class="small">${escapeHtml(s.ip_address || '—')}</td>
          <td class="small">${escapeHtml(s.last_active || '—')}</td>
          <td class="text-end">
            ${s.is_current ? '' : `<button type="button" class="btn btn-sm btn-outline-danger session-terminate-btn" data-token="${escapeHtml(s.token)}">${t('sessions.terminate', 'Завершить')}</button>`}
          </td>
        </tr>`).join('');
    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="4" class="text-danger small">${t('sessions.load_error', 'Не удалось загрузить сессии')}</td></tr>`;
    }
  }

  async function terminate(token) {
    const res = await fetch(ENDPOINT, {
      method: 'POST',
      credentials: 'same-origin',
      headers: JSON_HEADERS,
      body: JSON.stringify({ action: 'terminate', token }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.success) throw new Error(data.error || t('sessions.error', 'Ошибка'));
  }

  async function terminateAll() {
    const res = await fetch(ENDPOINT, {
      method: 'POST',
      credentials: 'same-origin',
      headers: JSON_HEADERS,
      body: JSON.stringify({ action: 'terminate_all' }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.success) throw new Error(data.error || t('sessions.error', 'Ошибка'));
    return data.message;
  }

  document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('profileModal');
    const tbody = document.getElementById('sessionsTableBody');
    const allBtn = document.getElementById('sessionsTerminateAllBtn');
    if (!modal || !tbody) return;

    modal.addEventListener('shown.bs.modal', () => {
      hideAlert();
      loadSessions();
    });

    tbody.addEventListener('click', async (e) => {
      const btn = e.target.closest('.session-terminate-btn');
      if (!btn) return;
      btn.disabled = true;
      try {
        await terminate(btn.dataset.token);
        showAlert(t('sessions.terminated', 'Сессия завершена'), 'success');
        await loadSessions();
      } catch (err) {
        btn.disabled = false;
        showAlert(t('sessions.terminate_error', 'Не удалось завершить сессию'), 'danger');
      }
    });

    allBtn?.addEventListener('click', async () => {
      allBtn.disabled = true;
      try {
        const msg = await terminateAll();
        showAlert(msg || t('sessions.all_terminated', 'Другие сессии завершены'), 'success');
        await loadSessions();
      } catch (err) {
        showAlert(t('sessions.terminate_all_error', 'Не удалось завершить сессии'), 'danger');
      } finally {
        allBtn.disabled = false;
      }
    });
  });
})();

/**
 * Панель уведомлений: сроки + email-очередь.
 */
(function (window) {
  const API = '/api';

  function t(key, fallback) {
    return window.AppI18n?.t(key) || fallback;
  }

  function renderDeadlinesTab(box) {
    if (!window.store) window.store = { incoming: [], outgoing: [] };
    const today = new Date();
    const pending = (window.store.incoming || []).filter((i) => !i.linkedOutgoingId).map((i) => {
      const due = window.addWorkingDays ? window.addWorkingDays(i.date, 15) : new Date(i.date);
      const daysLeft = Math.ceil((due.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
      let status = 'normal';
      if (daysLeft < 0) status = 'overdue';
      else if (window.subtractWorkingDays) {
        const warnFrom = window.subtractWorkingDays(due, 3);
        if (today >= warnFrom && today <= due) status = 'warning';
      }
      return {
        seq: i.seq,
        date: i.date,
        org: i.organization || '',
        due,
        daysLeft,
        status,
        responsible: (i.members || []).map((m) => m.full_name).join(', ') || '—',
      };
    }).sort((a, b) => a.due - b.due).slice(0, 20);

    const overdueCount = pending.filter((p) => p.status === 'overdue').length;
    const warnCount = pending.filter((p) => p.status === 'warning').length;

    const rows = pending.map((p) => {
      const badgeClass = p.status === 'overdue' ? 'badge bg-danger'
        : p.status === 'warning' ? 'badge bg-warning text-dark' : 'badge bg-secondary';
      const daysTxt = p.daysLeft < 0 ? `-${Math.abs(p.daysLeft)}` : `${p.daysLeft}`;
      return `<tr>
        <td class="text-nowrap">Вх.${p.seq}</td>
        <td>${new Date(p.date).toLocaleDateString('ru-RU')}</td>
        <td>${p.org ? escapeHtml(p.org) : '—'}</td>
        <td><span class="${badgeClass}">${new Date(p.due).toLocaleDateString('ru-RU')}</span></td>
        <td class="text-end">${daysTxt}</td>
        <td>${escapeHtml(p.responsible)}</td>
      </tr>`;
    }).join('');

    box.innerHTML = `
      <div class="modal-notify-stats mb-3">
        <span class="stat-chip stat-chip--neutral">Без ответа: ${pending.length}</span>
        <span class="stat-chip stat-chip--danger">Просрочено: ${overdueCount}</span>
        <span class="stat-chip stat-chip--warning">Скоро срок: ${warnCount}</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr><th>Рег. №</th><th>Дата</th><th>От кого</th><th>Срок</th><th class="text-end">Дн.</th><th>Ответственные</th></tr>
          </thead>
          <tbody>${rows || '<tr><td colspan="6" class="text-center text-muted">Нет элементов</td></tr>'}</tbody>
        </table>
      </div>
      <div class="small text-muted mt-2">15 рабочих дней от даты входящего.</div>`;
  }

  async function renderEmailTab(box) {
    const canSend = window.canWrite?.();
    let items = [];
    try {
      const resp = await fetch(`${API}/notifications.php`);
      if (resp.ok) {
        const data = await resp.json();
        items = data.items || [];
      }
    } catch (e) {
      console.warn('notifications.php failed', e);
    }

    const queueRows = items.length
      ? items.slice(0, 30).map((row) => `
        <tr>
          <td class="small text-nowrap">${escapeHtml(row.created_at || '')}</td>
          <td>${escapeHtml(row.recipient_email || '')}</td>
          <td>${escapeHtml(row.subject || '')}</td>
          <td><span class="badge bg-light text-dark border">${escapeHtml(row.status || '')}</span></td>
        </tr>`).join('')
      : `<tr><td colspan="4" class="text-center text-muted">${t('notify.email.empty', 'Очередь пуста')}</td></tr>`;

    box.innerHTML = `
      ${canSend ? `
      <form id="notifyEmailForm" class="row g-2 mb-3">
        <div class="col-md-4">
          <label class="form-label small">${t('notify.email.to', 'Email получателя')}</label>
          <input type="email" class="form-control form-control-sm" id="notifyEmailTo" required />
        </div>
        <div class="col-md-8">
          <label class="form-label small">${t('notify.email.subject', 'Тема')}</label>
          <input type="text" class="form-control form-control-sm" id="notifyEmailSubject" value="Уведомление ОС" />
        </div>
        <div class="col-12">
          <label class="form-label small">${t('notify.email.body', 'Текст письма')}</label>
          <textarea class="form-control form-control-sm" id="notifyEmailBody" rows="3" required></textarea>
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-sm btn-primary">${t('notify.email.send', 'Отправить')}</button>
        </div>
      </form>` : '<p class="small text-muted">Отправка email доступна модераторам и админам.</p>'}
      <h6 class="small fw-semibold">${t('notify.email.queue', 'Очередь отправки')}</h6>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead class="table-light"><tr><th>Дата</th><th>Кому</th><th>Тема</th><th>Статус</th></tr></thead>
          <tbody>${queueRows}</tbody>
        </table>
      </div>`;

    document.getElementById('notifyEmailForm')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        const resp = await fetch(`${API}/notifications.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            to: document.getElementById('notifyEmailTo').value.trim(),
            subject: document.getElementById('notifyEmailSubject').value.trim(),
            body_html: document.getElementById('notifyEmailBody').value,
          }),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok) throw new Error(data.error || 'Ошибка отправки');
        window.showSuccess?.(data.message || 'Поставлено в очередь');
        await renderEmailTab(box);
      } catch (err) {
        window.showError?.(err.message) || alert(err.message);
      }
    });
  }

  async function openNotifyModal() {
    const modalEl = document.getElementById('notifyModal');
    if (!modalEl) return;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const deadlinesPane = document.getElementById('notifyDeadlinesPane');
    const emailPane = document.getElementById('notifyEmailPane');
    if (deadlinesPane) {
      deadlinesPane.textContent = 'Загрузка...';
      renderDeadlinesTab(deadlinesPane);
    }
    modal.show();

    const emailTab = document.getElementById('notify-tab-email');
    emailTab?.addEventListener('shown.bs.tab', async () => {
      if (emailPane) {
        emailPane.textContent = 'Загрузка...';
        await renderEmailTab(emailPane);
      }
    }, { once: true });
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('notifyBtn')?.addEventListener('click', openNotifyModal);
  });

  window.openNotifyModal = openNotifyModal;
})(window);

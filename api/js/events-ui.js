/**
 * UI мероприятий: форма, таблица, участники.
 */
(function (window) {
  const API_BASE = window.API_BASE;
  const store = window.store;
  const canDelete = () => window.canDelete?.() ?? false;
  const confirmDelete = (...args) => window.confirmDelete?.(...args);
  const formatDateISOtoRus = window.AppUtils?.formatDateISOtoRus
    || ((iso) => (iso ? new Date(iso).toLocaleDateString('ru-RU') : ''));
  const t = (key, fb) => window.AppI18n?.t(key, fb) ?? fb;

  const formEvent = document.getElementById('formEvent');
  const evTitle = document.getElementById('evTitle');
  const evDate = document.getElementById('evDate');
  const evLocation = document.getElementById('evLocation');
  const evNotes = document.getElementById('evNotes');
  const kpiList = document.getElementById('kpiList');
  const addKpiBtn = document.getElementById('addKpiBtn');
  const tableEventsBody = document.querySelector('#tableEvents tbody');
  const searchEvents = document.getElementById('searchEvents');
  const attChecklist = document.getElementById('attChecklist');
  const attSelectAll = document.getElementById('attSelectAll');
  const attClear = document.getElementById('attClear');
  const todayISO = new Date().toISOString().slice(0, 10);

  if (evDate) evDate.value = todayISO;
  store.events = store.events || [];

  async function refreshEvents() {
    const resp = await fetch(`${API_BASE}/events.php?limit=500`);
    if (!resp.ok) throw new Error('Не удалось загрузить мероприятия');
    const data = await resp.json();
    store.events = Array.isArray(data) ? data : (data.items || []);
  }

  function kpiFromForm() {
    return Array.from(kpiList?.querySelectorAll('.kpi-row') || []).map((row) => ({
      metric: row.querySelector('input[name="metric"]')?.value.trim() || '',
      value_numeric: row.querySelector('input[name="value_numeric"]')?.value !== ''
        ? Number(row.querySelector('input[name="value_numeric"]').value) : null,
      value_text: row.querySelector('input[name="value_text"]')?.value.trim() || null,
    })).filter((k) => k.metric);
  }

  function addKpiRow(metric = '', valueNumeric = '', valueText = '') {
    if (!kpiList) return;
    const wrapper = document.createElement('div');
    wrapper.className = 'kpi-row col-12 d-flex gap-2';
    wrapper.innerHTML = `
      <input class="form-control form-control-sm" name="metric" placeholder="${t('events.kpi_metric', 'Метрика')}" value="${metric}"/>
      <input class="form-control form-control-sm" name="value_numeric" type="number" step="0.01" placeholder="${t('events.kpi_number', 'Число')}" value="${valueNumeric}"/>
      <input class="form-control form-control-sm" name="value_text" placeholder="${t('events.kpi_text', 'Текст')}" value="${valueText}"/>
      <button type="button" class="btn btn-sm btn-outline-danger">×</button>`;
    wrapper.querySelector('button').onclick = () => wrapper.remove();
    kpiList.appendChild(wrapper);
  }

  function attendeesFromForm() {
    if (!attChecklist) return [];
    return Array.from(attChecklist.querySelectorAll('input.att-item'))
      .map((ch) => ({ full_name: ch.dataset.name, attended: ch.checked }));
  }

  function renderAttendeesChecklist(selectedNames = []) {
    if (!attChecklist) return;
    const catalog = window.membersCatalog || [];
    const byCommission = new Map();
    catalog.forEach((m) => {
      const key = m.commission_name || t('events.no_commission', 'Без комиссии');
      if (!byCommission.has(key)) byCommission.set(key, []);
      byCommission.get(key).push(m);
    });
    attChecklist.innerHTML = '';
    byCommission.forEach((list, commission) => {
      const col = document.createElement('div');
      col.className = 'col-12 col-md-6';
      const card = document.createElement('div');
      card.className = 'p-2 border rounded';
      const title = document.createElement('div');
      title.className = 'mb-1 fw-semibold';
      title.textContent = commission;
      card.appendChild(title);
      list.sort((a, b) => a.full_name.localeCompare(b.full_name)).forEach((m) => {
        const id = `att_${m.id}`;
        const wrap = document.createElement('div');
        wrap.className = 'form-check';
        wrap.innerHTML = `
          <input class="form-check-input att-item" type="checkbox" id="${id}" data-name="${escapeHtml(m.full_name)}">
          <label class="form-check-label" for="${id}">${escapeHtml(m.full_name)}</label>`;
        wrap.querySelector('input').checked = selectedNames.includes(m.full_name);
        card.appendChild(wrap);
      });
      col.appendChild(card);
      attChecklist.appendChild(col);
    });
  }

  function setAllAttendees(checked) {
    attChecklist?.querySelectorAll('input.att-item').forEach((ch) => { ch.checked = !!checked; });
  }

  async function handleEventSubmit(e) {
    e.preventDefault();
    const payload = {
      title: evTitle.value.trim(),
      event_date: evDate.value,
      location: evLocation.value.trim() || null,
      participants_total: 0,
      attendance_percent: 0,
      notes: evNotes.value.trim() || null,
      kpi: kpiFromForm(),
      attendees: attendeesFromForm(),
    };
    const totalInList = payload.attendees.length;
    const presentCount = payload.attendees.filter((a) => a.attended).length;
    payload.participants_total = presentCount;
    payload.attendance_percent = totalInList ? (presentCount * 100 / totalInList) : 0;
    try {
      const editId = formEvent?.dataset?.editId;
      const method = editId ? 'PUT' : 'POST';
      if (editId) payload.id = Number(editId);
      const resp = await fetch(`${API_BASE}/events.php`, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      if (!resp.ok) throw new Error('save failed');
      formEvent.reset();
      if (evDate) evDate.value = todayISO;
      kpiList.innerHTML = '';
      renderAttendeesChecklist([]);
      delete formEvent.dataset.editId;
      await refreshEvents();
      renderEvents();
      window.showSuccess?.(t('events.saved', 'Мероприятие успешно сохранено'));
    } catch (err) {
      console.error(err);
      window.showError?.(t('events.save_error', 'Не удалось сохранить мероприятие'));
    }
  }

  function renderEvents() {
    if (!tableEventsBody) return;
    const q = (searchEvents?.value || '').toLowerCase();
    const rows = (store.events || [])
      .filter((ev) => `${ev.title} ${ev.location || ''} ${ev.notes || ''}`.toLowerCase().includes(q))
      .map((ev) => {
        const present = Number(ev.attendees_present ?? 0);
        const total = Number(ev.attendees_total ?? 0);
        return `<tr>
          <td data-label="Дата">${formatDateISOtoRus(ev.event_date)}</td>
          <td data-label="Название">${escapeHtml(ev.title)}</td>
          <td data-label="Локация">${escapeHtml(ev.location || '')}</td>
          <td class="text-end" data-label="Присутствовало">${present}/${total}</td>
          <td class="text-end" data-label="% явки">${Number(ev.attendance_percent || 0).toFixed(2)}%</td>
          <td class="text-end table-actions" data-label="">
            <button class="btn btn-sm btn-outline-secondary" data-action="view-att" data-id="${ev.id}" title="${t('events.view_att', 'Участники')}"><i class="bi bi-people"></i></button>
            <button class="btn btn-sm btn-outline-primary" data-action="edit-event" data-id="${ev.id}" title="${t('action.edit', 'Изменить')}"><i class="bi bi-pencil"></i></button>
            ${canDelete() ? `<button class="btn btn-sm btn-outline-danger" data-action="del-event" data-id="${ev.id}" title="${t('action.delete', 'Удалить')}"><i class="bi bi-trash"></i></button>` : ''}
          </td></tr>`;
      }).join('');
    tableEventsBody.innerHTML = rows || `<tr><td colspan="6" class="text-center text-muted">${t('events.empty', 'Нет мероприятий')}</td></tr>`;
    tableEventsBody.querySelectorAll('[data-action="del-event"]').forEach((btn) => {
      btn.addEventListener('click', () => deleteEvent(btn.dataset.id));
    });
    tableEventsBody.querySelectorAll('[data-action="edit-event"]').forEach((btn) => {
      btn.addEventListener('click', () => editEvent(btn.dataset.id));
    });
    tableEventsBody.querySelectorAll('[data-action="view-att"]').forEach((btn) => {
      btn.addEventListener('click', () => viewEventAttendees(btn.dataset.id));
    });
  }

  async function deleteEvent(id) {
    const run = async () => {
      const resp = await fetch(`${API_BASE}/events.php?id=${id}`, { method: 'DELETE' });
      if (!resp.ok) throw new Error('delete failed');
      await refreshEvents();
      renderEvents();
      window.showSuccess?.(t('events.deleted', 'Мероприятие удалено'));
    };
    if (window.confirmDelete) {
      confirmDelete(t('events.delete_confirm', 'Удалить мероприятие?'), () => run().catch(() => window.showError?.(t('events.delete_error', 'Не удалось удалить'))));
    } else if (confirm(t('events.delete_confirm', 'Удалить мероприятие?'))) {
      try { await run(); } catch { alert(t('events.delete_error', 'Не удалось удалить')); }
    }
  }

  async function editEvent(id) {
    const resp = await fetch(`${API_BASE}/events.php?id=${id}`);
    if (!resp.ok) return alert(t('events.load_error', 'Не удалось загрузить'));
    const ev = await resp.json();
    evTitle.value = ev.title || '';
    evDate.value = (ev.event_date || todayISO).slice(0, 10);
    evLocation.value = ev.location || '';
    evNotes.value = ev.notes || '';
    kpiList.innerHTML = '';
    (ev.kpi || []).forEach((k) => addKpiRow(k.metric || '', k.value_numeric ?? '', k.value_text || ''));
    renderAttendeesChecklist((ev.attendees || []).map((a) => a.full_name).filter(Boolean));
    formEvent.dataset.editId = String(ev.id);
    document.getElementById('tab-events')?.click();
  }

  async function viewEventAttendees(eventId) {
    const resp = await fetch(`${API_BASE}/events.php?id=${eventId}`);
    if (!resp.ok) return;
    const ev = await resp.json();
    const body = document.getElementById('eventAttendeesBody');
    const present = (ev.attendees || []).filter((a) => a.attended).map((a) => a.full_name);
    const absent = (ev.attendees || []).filter((a) => !a.attended).map((a) => a.full_name);
    body.innerHTML = [
      `<div class="mb-2"><strong>${escapeHtml(ev.title || '')}</strong> — ${formatDateISOtoRus(ev.event_date)}</div>`,
      `<div class="mb-1"><span class="badge text-bg-success">${t('events.present', 'Присутствовали')}: ${present.length}</span></div>`,
      present.length ? `<div class="small">${present.map((n) => `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtml(n)}</span>`).join(' ')}</div>` : '',
      '<hr/>',
      `<div class="mb-1"><span class="badge text-bg-secondary">${t('events.absent', 'Отсутствовали')}: ${absent.length}</span></div>`,
      absent.length ? `<div class="small">${absent.map((n) => `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtml(n)}</span>`).join(' ')}</div>` : '',
    ].join('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('eventAttendeesModal')).show();
  }

  function bindEventsUI() {
    addKpiBtn?.addEventListener('click', () => addKpiRow());
    formEvent?.addEventListener('submit', handleEventSubmit);
    formEvent?.addEventListener('reset', () => {
      kpiList.innerHTML = '';
      renderAttendeesChecklist([]);
      delete formEvent.dataset.editId;
    });
    searchEvents?.addEventListener('input', renderEvents);
    attSelectAll?.addEventListener('click', () => setAllAttendees(true));
    attClear?.addEventListener('click', () => setAllAttendees(false));
  }

  bindEventsUI();

  window.refreshEvents = refreshEvents;
  window.renderEvents = renderEvents;
  window.renderAttendeesChecklist = renderAttendeesChecklist;
})(window);

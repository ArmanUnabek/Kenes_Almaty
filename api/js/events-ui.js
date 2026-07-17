/**
 * UI мероприятий: форма, таблица, участники, RSVP, KPI-карточки.
 */
(function (window) {
  const API_BASE = window.API_BASE;
  const store = window.store;
  const canWrite = () => window.canWrite?.() ?? false;
  const canDelete = () => window.canDelete?.() ?? false;
  const confirmDelete = (...args) => window.confirmDelete?.(...args);
  const formatDateISOtoRus = window.AppUtils?.formatDateISOtoRus
    || ((iso) => (iso ? new Date(iso).toLocaleDateString('ru-RU') : ''));
  const t = (key, fb) => window.AppI18n?.t(key, fb) ?? fb;

  window.AppI18n?.register?.({
    ru: {
      'events.kpi_section': 'KPI мероприятия',
      'events.rsvp_confirmed': 'Приду',
      'events.rsvp_maybe': 'Возможно',
      'events.rsvp_declined': 'Не смогу',
      'events.rsvp_confirmed_msg': 'Отклик: Приду',
      'events.rsvp_maybe_msg': 'Отклик: Возможно',
      'events.rsvp_declined_msg': 'Отклик: Не смогу',
      'events.rsvp_saved': 'Отклик сохранён',
      'events.rsvp_save_error': 'Не удалось сохранить отклик',
      'events.rsvp_section': 'Отклики членов ОС',
      'events.rsvp_will_come': 'Придут',
      'events.rsvp_cant_come': 'Не смогут',
      'events.open_2gis': 'Открыть в 2GIS',
      'events.th_date': 'Дата',
      'events.th_title': 'Название',
      'events.th_location': 'Локация',
      'events.th_present': 'Присутствовало',
      'events.th_attendance': '% явки',
      'events.load_error_short': 'Ошибка загрузки',
      'events.load_data_error': 'Ошибка загрузки данных',
    },
    kz: {
      'events.kpi_section': 'Іс-шара KPI',
      'events.rsvp_confirmed': 'Келемін',
      'events.rsvp_maybe': 'Мүмкін',
      'events.rsvp_declined': 'Келе алмаймын',
      'events.rsvp_confirmed_msg': 'Жауап: Келемін',
      'events.rsvp_maybe_msg': 'Жауап: Мүмкін',
      'events.rsvp_declined_msg': 'Жауап: Келе алмаймын',
      'events.rsvp_saved': 'Жауап сақталды',
      'events.rsvp_save_error': 'Жауапты сақтау сәтсіз',
      'events.rsvp_section': 'Кеңес мүшелерінің жауаптары',
      'events.rsvp_will_come': 'Келеді',
      'events.rsvp_cant_come': 'Келе алмайды',
      'events.open_2gis': '2GIS-те ашу',
      'events.th_date': 'Күні',
      'events.th_title': 'Атауы',
      'events.th_location': 'Орналасуы',
      'events.th_present': 'Қатысқаны',
      'events.th_attendance': '% қатысу',
      'events.load_error_short': 'Жүктеу қатесі',
      'events.load_data_error': 'Деректерді жүктеу қатесі',
    },
  });

  const formEvent = document.getElementById('formEvent');
  const evTitle = document.getElementById('evTitle');
  const evDate = document.getElementById('evDate');
  const evLocation = document.getElementById('evLocation');
  const evLocationUrl = document.getElementById('evLocationUrl');
  const evDescription = document.getElementById('evDescription');
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
    const PAGE_SIZE = 500;
    let page = 1;
    let all = [];
    try {
      while (true) {
        const data = await window.AppUtils.fetchJson(`${API_BASE}/events.php?limit=${PAGE_SIZE}&page=${page}`);
        if (!data) break;
        const items = window.AppUtils.asList(data);
        if (!items.length) break;
        all = all.concat(items);
        const total = data?.pagination?.total ?? 0;
        if (!total || all.length >= total) break;
        page++;
      }
    } catch (e) {
      console.warn('[events] fetch failed', e);
    }
    store.events = all;
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
    const escHtml = window.AppUtils?.escapeHtml || ((s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'));
    wrapper.innerHTML = `
      <input class="form-control form-control-sm" name="metric" placeholder="${t('events.kpi_metric', 'Метрика')}" value="${escHtml(String(metric ?? ''))}"/>
      <input class="form-control form-control-sm" name="value_numeric" type="number" step="0.01" placeholder="${t('events.kpi_number', 'Число')}" value="${escHtml(String(valueNumeric ?? ''))}"/>
      <input class="form-control form-control-sm" name="value_text" placeholder="${t('events.kpi_text', 'Текст')}" value="${escHtml(String(valueText ?? ''))}"/>
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

  let isEventSubmitting = false;
  async function handleEventSubmit(e) {
    e.preventDefault();
    if (isEventSubmitting) return;
    if (!canWrite()) {
      window.showError?.(t('events.save_error', 'Не удалось сохранить мероприятие'));
      return;
    }
    isEventSubmitting = true;
    const submitBtn = formEvent?.querySelector('[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    const payload = {
      title: evTitle.value.trim(),
      event_date: evDate.value,
      location: evLocation?.value.trim() || null,
      location_url: evLocationUrl?.value.trim() || null,
      description: evDescription?.value.trim() || null,
      participants_total: 0,
      attendance_percent: 0,
      notes: evNotes?.value.trim() || null,
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
      if (evLocationUrl) evLocationUrl.value = '';
      if (evDescription) evDescription.value = '';
      renderAttendeesChecklist([]);
      delete formEvent.dataset.editId;
      await refreshEvents();
      renderEvents();
      window.showSuccess?.(t('events.saved', 'Мероприятие успешно сохранено'));
    } catch (err) {
      console.error(err);
      window.showError?.(t('events.save_error', 'Не удалось сохранить мероприятие'));
    } finally {
      isEventSubmitting = false;
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  function renderKpiCards(kpiItems) {
    if (!kpiItems || kpiItems.length === 0) return '';
    const cards = kpiItems.map((k) => {
      const val = k.value_numeric !== null && k.value_numeric !== undefined
        ? Number(k.value_numeric).toLocaleString('ru-RU')
        : (k.value_text || '—');
      return `<div class="kpi-mini-card">
        <div class="kpi-mini-label">${escapeHtml(k.metric || '')}</div>
        <div class="kpi-mini-value">${escapeHtml(String(val))}</div>
      </div>`;
    }).join('');
    return `<div class="kpi-card-grid mb-3">${cards}</div>`;
  }

  function renderEvents() {
    if (!tableEventsBody) return;
    const q = (searchEvents?.value || '').toLowerCase();
    const memberId = window.sessionUser?.member_id ?? null;
    const rows = (store.events || [])
      .filter((ev) => `${ev.title} ${ev.location || ''} ${ev.notes || ''}`.toLowerCase().includes(q))
      .map((ev) => {
        const present = Number(ev.attendees_present ?? 0);
        const total = Number(ev.attendees_total ?? 0);
        const locationHtml = ev.location_url
          ? `${escapeHtml(ev.location || '')} <a href="${escapeHtml(ev.location_url)}" target="_blank" rel="noopener" class="ms-1 text-primary" title="${t('events.open_2gis', 'Открыть в 2GIS')}"><i class="bi bi-geo-alt-fill"></i></a>`
          : escapeHtml(ev.location || '');
        const rsvpRow = memberId
          ? `<div class="rsvp-bar mt-1" data-event-id="${ev.id}">
              <button class="rsvp-btn" data-action="rsvp" data-event-id="${ev.id}" data-status="confirmed" title="${t('events.rsvp_confirmed', 'Приду')}"><i class="bi bi-check-circle"></i></button>
              <button class="rsvp-btn" data-action="rsvp" data-event-id="${ev.id}" data-status="maybe" title="${t('events.rsvp_maybe', 'Возможно')}"><i class="bi bi-question-circle"></i></button>
              <button class="rsvp-btn" data-action="rsvp" data-event-id="${ev.id}" data-status="declined" title="${t('events.rsvp_declined', 'Не смогу')}"><i class="bi bi-x-circle"></i></button>
            </div>` : '';
        return `<tr>
          <td data-label="${t('events.th_date', 'Дата')}">${formatDateISOtoRus(ev.event_date)}</td>
          <td data-label="${t('events.th_title', 'Название')}">${escapeHtml(ev.title)}</td>
          <td data-label="${t('events.th_location', 'Локация')}">${locationHtml}</td>
          <td class="text-end" data-label="${t('events.th_present', 'Присутствовало')}">${present}/${total}</td>
          <td class="text-end" data-label="${t('events.th_attendance', '% явки')}">${Number(ev.attendance_percent || 0).toFixed(2)}%</td>
          <td class="table-actions" data-label="">
            ${rsvpRow}
            <div class="d-flex gap-1 justify-content-end mt-1">
              <button class="btn btn-sm btn-outline-secondary" data-action="view-att" data-id="${ev.id}" title="${t('events.view_att', 'Детали')}" aria-label="${t('events.view_att', 'Детали')}"><i class="bi bi-info-circle" aria-hidden="true"></i></button>
              ${canWrite() ? `<button class="btn btn-sm btn-outline-primary" data-action="edit-event" data-id="${ev.id}" title="${t('action.edit', 'Изменить')}" aria-label="${t('action.edit', 'Изменить')}"><i class="bi bi-pencil" aria-hidden="true"></i></button>` : ''}
              ${canDelete() ? `<button class="btn btn-sm btn-outline-danger" data-action="del-event" data-id="${ev.id}" title="${t('action.delete', 'Удалить')}" aria-label="${t('action.delete', 'Удалить')}"><i class="bi bi-trash" aria-hidden="true"></i></button>` : ''}
            </div>
          </td></tr>`;
      }).join('');
    tableEventsBody.innerHTML = rows || `<tr><td colspan="6" class="text-center text-muted">${t('events.empty', 'Нет мероприятий')}</td></tr>`;

    // Load my RSVP statuses after render
    if (window.sessionUser?.member_id) loadMyRsvpStatuses();
  }

  async function loadMyRsvpStatuses() {
    const bars = tableEventsBody?.querySelectorAll('.rsvp-bar[data-event-id]') || [];
    for (const bar of bars) {
      const eventId = bar.dataset.eventId;
      try {
        const res = await fetch(`${API_BASE}/event_rsvp.php?my=1&event_id=${eventId}`);
        if (!res.ok) continue;
        const data = await res.json();
        if (data.status) {
          const activeBtn = bar.querySelector(`[data-status="${data.status}"]`);
          activeBtn?.classList.add('rsvp-active');
        }
      } catch { /* non-critical */ }
    }
  }

  async function handleRsvp(eventId, status) {
    const bar = tableEventsBody?.querySelector(`.rsvp-bar[data-event-id="${eventId}"]`);
    try {
      const res = await fetch(`${API_BASE}/event_rsvp.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id: Number(eventId), status }),
      });
      if (!res.ok) throw new Error('rsvp failed');
      // Update UI: remove active from siblings, set on clicked
      bar?.querySelectorAll('.rsvp-btn').forEach((b) => b.classList.remove('rsvp-active'));
      bar?.querySelector(`[data-status="${status}"]`)?.classList.add('rsvp-active');
      const labels = { confirmed: t('events.rsvp_confirmed_msg', 'Отклик: Приду'), maybe: t('events.rsvp_maybe_msg', 'Отклик: Возможно'), declined: t('events.rsvp_declined_msg', 'Отклик: Не смогу') };
      window.showSuccess?.(labels[status] || t('events.rsvp_saved', 'Отклик сохранён'));
    } catch {
      window.showError?.(t('events.rsvp_save_error', 'Не удалось сохранить отклик'));
    }
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
      try { await run(); } catch { window.showError?.(t('events.delete_error', 'Не удалось удалить')); }
    }
  }

  async function editEvent(id) {
    if (!canWrite()) return;
    try {
      const resp = await fetch(`${API_BASE}/events.php?id=${id}`);
      if (!resp.ok) { window.showError?.(t('events.load_error', 'Не удалось загрузить')); return; }
      const ev = await resp.json();
      if (evTitle) evTitle.value = ev.title || '';
      if (evDate) evDate.value = (ev.event_date || todayISO).slice(0, 10);
      if (evLocation) evLocation.value = ev.location || '';
      if (evLocationUrl) evLocationUrl.value = ev.location_url || '';
      if (evDescription) evDescription.value = ev.description || '';
      if (evNotes) evNotes.value = ev.notes || '';
      kpiList.innerHTML = '';
      (ev.kpi || []).forEach((k) => addKpiRow(k.metric || '', k.value_numeric ?? '', k.value_text || ''));
      renderAttendeesChecklist((ev.attendees || []).map((a) => a.full_name).filter(Boolean));
      formEvent.dataset.editId = String(ev.id);
      const collapseEl = document.getElementById('collapseEventForm');
      if (collapseEl && !collapseEl.classList.contains('show')) {
        bootstrap.Collapse.getOrCreateInstance(collapseEl).show();
      }
      document.getElementById('tab-events')?.click();
      formEvent?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
      console.error('editEvent error:', e);
      window.showError?.(t('events.load_error', 'Не удалось загрузить мероприятие'));
    }
  }

  async function viewEventAttendees(eventId) {
    const body = document.getElementById('eventAttendeesBody');
    if (!body) return;
    body.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('eventAttendeesModal')).show();

    try {
      const [evResp, rsvpResp] = await Promise.all([
        fetch(`${API_BASE}/events.php?id=${eventId}`),
        fetch(`${API_BASE}/event_rsvp.php?event_id=${eventId}`).catch(() => null),
      ]);
      if (!evResp.ok) { body.textContent = t('events.load_error_short', 'Ошибка загрузки'); return; }
      const ev = await evResp.json();
      const rsvpData = rsvpResp?.ok ? await rsvpResp.json() : null;

      const present = (ev.attendees || []).filter((a) => a.attended).map((a) => a.full_name);
      const absent  = (ev.attendees || []).filter((a) => !a.attended).map((a) => a.full_name);
      const counts  = rsvpData?.counts || {};
      const rsvpItems = rsvpData?.items || [];

      let html = `<div class="mb-2"><strong>${escapeHtml(ev.title || '')}</strong> · ${formatDateISOtoRus(ev.event_date)}`;
      if (ev.location_url) {
        html += ` · <a href="${escapeHtml(ev.location_url)}" target="_blank" rel="noopener" class="ms-1"><i class="bi bi-geo-alt-fill text-primary"></i> 2GIS</a>`;
      } else if (ev.location) {
        html += ` · <span class="text-muted">${escapeHtml(ev.location)}</span>`;
      }
      html += '</div>';

      if (ev.description) {
        html += `<p class="text-muted small mb-3">${escapeHtml(ev.description)}</p>`;
      }

      // KPI mini cards
      if (ev.kpi && ev.kpi.length > 0) {
        html += `<div class="mb-2 fw-semibold text-uppercase small text-muted">${t('events.kpi_section', 'KPI мероприятия')}</div>`;
        html += renderKpiCards(ev.kpi);
      }

      // RSVP summary
      if (rsvpData) {
        const rsvpStatusMap = { confirmed: 'rsvp-confirmed', maybe: 'rsvp-maybe', declined: 'rsvp-declined' };
        const rsvpLabel = { confirmed: t('events.rsvp_will_come', 'Придут'), maybe: t('events.rsvp_maybe', 'Возможно'), declined: t('events.rsvp_cant_come', 'Не смогут') };
        html += `<div class="mb-2 fw-semibold text-uppercase small text-muted">${t('events.rsvp_section', 'Отклики членов ОС')}</div>`;
        html += '<div class="d-flex gap-2 mb-2">';
        ['confirmed', 'maybe', 'declined'].forEach((s) => {
          html += `<span class="badge rsvp-badge-${s} me-1">${rsvpLabel[s]}: ${counts[s] || 0}</span>`;
        });
        html += '</div>';
        if (rsvpItems.length > 0) {
          html += '<div class="small mb-3" style="max-height:120px;overflow-y:auto">';
          rsvpItems.forEach((r) => {
            const icon = r.status === 'confirmed' ? '✓' : r.status === 'maybe' ? '?' : '✗';
            html += `<div>${icon} ${escapeHtml(r.full_name || '')} <span class="text-muted">(${r.status})</span></div>`;
          });
          html += '</div>';
        }
      }

      // Attendance
      html += `<div class="mb-1"><span class="badge text-bg-success">${t('events.present', 'Присутствовали')}: ${present.length}</span></div>`;
      if (present.length) {
        html += `<div class="small mb-2">${present.map((n) => `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtml(n)}</span>`).join(' ')}</div>`;
      }
      html += '<hr class="my-2"/>';
      html += `<div class="mb-1"><span class="badge text-bg-secondary">${t('events.absent', 'Отсутствовали')}: ${absent.length}</span></div>`;
      if (absent.length) {
        html += `<div class="small">${absent.map((n) => `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtml(n)}</span>`).join(' ')}</div>`;
      }

      body.innerHTML = html;
    } catch (e) {
      console.error('viewEventAttendees error:', e);
      body.textContent = t('events.load_data_error', 'Ошибка загрузки данных');
    }
  }

  function bindEventsUI() {
    addKpiBtn?.addEventListener('click', () => addKpiRow());
    formEvent?.addEventListener('submit', handleEventSubmit);
    formEvent?.addEventListener('reset', () => {
      kpiList.innerHTML = '';
      if (evLocationUrl) evLocationUrl.value = '';
      if (evDescription) evDescription.value = '';
      renderAttendeesChecklist([]);
      delete formEvent.dataset.editId;
    });
    searchEvents?.addEventListener('input', renderEvents);
    attSelectAll?.addEventListener('click', () => setAllAttendees(true));
    attClear?.addEventListener('click', () => setAllAttendees(false));
    tableEventsBody?.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-action]');
      if (!btn) return;
      const action = btn.dataset.action;
      const id = btn.dataset.id || btn.dataset.eventId;
      if (action === 'del-event') deleteEvent(id);
      else if (action === 'edit-event') editEvent(id);
      else if (action === 'view-att') viewEventAttendees(id);
      else if (action === 'rsvp') handleRsvp(id, btn.dataset.status);
    });
  }

  bindEventsUI();

  window.refreshEvents = refreshEvents;
  window.renderEvents = renderEvents;
  window.renderAttendeesChecklist = renderAttendeesChecklist;
})(window);

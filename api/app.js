// Shell SPA: js/core.js, js/members-ui.js, js/dashboard.js, js/letters-ui.js


function getSessionUser() {
  try {
    const raw = localStorage.getItem('user');
    return raw ? JSON.parse(raw) : null;
  } catch (error) {
    console.warn('Не удалось прочитать пользователя из localStorage', error);
    return null;
  }
}

// Утилиты форматирования
function formatDateISOtoRus(iso) {
  if (!iso) return "";
  const d = new Date(iso);
  return d.toLocaleDateString("ru-RU");
}

// Рабочие дни
function parseToDate(value) {
  return value instanceof Date ? new Date(value.getTime()) : new Date(value);
}

function addWorkingDays(start, days) {
  let date = parseToDate(start);
  let remaining = Number(days) || 0;
  while (remaining > 0) {
    date.setDate(date.getDate() + 1);
    const day = date.getDay(); // 0=вс, 6=сб
    if (day !== 0 && day !== 6) {
      remaining -= 1;
    }
  }
  return date;
}

function subtractWorkingDays(start, days) {
  let date = parseToDate(start);
  let remaining = Number(days) || 0;
  while (remaining > 0) {
    date.setDate(date.getDate() - 1);
    const day = date.getDay();
    if (day !== 0 && day !== 6) {
      remaining -= 1;
    }
  }
  return date;
}
window.addWorkingDays = addWorkingDays;
window.subtractWorkingDays = subtractWorkingDays;


// обработчики сканов вешаем в bindEventListeners после инициализации DOM-элементов

// DOM элементы

// Мероприятия DOM
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

const exportJsonBtn = document.getElementById('exportJsonBtn');
const exportCsvBtn = document.getElementById('exportCsvBtn');
const importJsonInput = document.getElementById('importJsonInput');

const todayISO = new Date().toISOString().slice(0, 10);
if (evDate) evDate.value = todayISO;

// Events store
store.events = [];

// Realtime (Pusher)
let pusherClient = null;

// Autocomplete setup
function setupAutocomplete(inputId, dataGetter, filterKey = null) {
  const input = document.getElementById(inputId);
  if (!input) return;

  // Wrap input in a relative container if not already
  if (!input.parentElement.classList.contains('search-container')) {
    const wrapper = document.createElement('div');
    wrapper.className = 'search-container position-relative';
    // Preserve classes from parent if it's just a div
    if (input.parentElement.tagName === 'DIV' && input.parentElement.classList.contains('d-flex')) {
       // If inside a flex container, we might need adjustment, but let's try simply wrapping
    }
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);
  }

  let currentFocus = -1;

  input.addEventListener('input', function(e) {
    const val = this.value;
    closeAllLists();
    if (!val) return false;

    currentFocus = -1;
    const list = document.createElement('div');
    list.setAttribute('id', this.id + 'autocomplete-list');
    list.setAttribute('class', 'autocomplete-suggestions');
    this.parentNode.appendChild(list);

    const data = dataGetter();
    const matches = new Set();

    // Simple heuristic: match start or contains
    data.forEach(item => {
        const text = filterKey ? item[filterKey] : item;
        if (!text) return;
        const str = String(text);
        if (str.toLowerCase().includes(val.toLowerCase())) {
            matches.add(str);
        }
    });

    // Limit suggestions
    const arr = Array.from(matches).slice(0, 10);

    arr.forEach(match => {
        const item = document.createElement('div');
        item.className = 'autocomplete-suggestion';
        // Highlight match — escape regex special chars to prevent ReDoS
        const safeVal = val.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`(${safeVal})`, 'gi');
        item.innerHTML = escapeHtml(match).replace(regex, '<strong>$1</strong>');
        item.innerHTML += `<input type='hidden' value='${escapeHtml(match)}'>`;
        
        item.addEventListener('click', function(e) {
            input.value = this.getElementsByTagName('input')[0].value;
            closeAllLists();
            // Trigger change/input event to update filters
            input.dispatchEvent(new Event('input'));
        });
        list.appendChild(item);
    });
  });

  input.addEventListener('keydown', function(e) {
      let x = document.getElementById(this.id + 'autocomplete-list');
      if (x) x = x.getElementsByTagName('div');
      if (e.keyCode == 40) { // DOWN
        currentFocus++;
        addActive(x);
      } else if (e.keyCode == 38) { // UP
        currentFocus--;
        addActive(x);
      } else if (e.keyCode == 13) { // ENTER
        e.preventDefault();
        if (currentFocus > -1) {
          if (x) x[currentFocus].click();
        }
      }
  });

  function addActive(x) {
    if (!x) return false;
    removeActive(x);
    if (currentFocus >= x.length) currentFocus = 0;
    if (currentFocus < 0) currentFocus = (x.length - 1);
    x[currentFocus].classList.add('autocomplete-active');
    // scroll style if needed
    x[currentFocus].style.backgroundColor = 'var(--color-bg)'; 
  }

  function removeActive(x) {
    for (let i = 0; i < x.length; i++) {
      x[i].classList.remove('autocomplete-active');
      x[i].style.backgroundColor = '';
    }
  }

  function closeAllLists(elmnt) {
    const x = document.getElementsByClassName('autocomplete-suggestions');
    for (let i = 0; i < x.length; i++) {
      if (elmnt != x[i] && elmnt != input) {
        x[i].parentNode.removeChild(x[i]);
      }
    }
  }

  document.addEventListener('click', function (e) {
      closeAllLists(e.target);
  });
}

// Предзаполняем дату сегодня

async function initializeApp() {
  try {
    if (window.showLoading) showLoading('Загрузка данных...');
    await loadMembersCatalog();
    await loadCommissionsCatalog();
    if (typeof populateMemberSelects === 'function') populateMemberSelects();
    populateMembersCommissionFilter();
    populateMemberCommissionSelect();
    bindMembersCommissionsForms();
    if (sessionUser) applyPermissionsUI(sessionUser);
    renderMembersGrid();
    renderCommissionsGrid();
    initAppNavigation();
    // Рендерим чеклист участников ОС при загрузке
    renderAttendeesChecklist([]);
    if (typeof renderIncomingRecipients === 'function') renderIncomingRecipients();
    if (typeof renderOutgoingRecipients === 'function') renderOutgoingRecipients();
    await refreshLetters();
    await refreshEvents();
    initRealtime();
    renderAll();
    renderKpiExtra();
    updatePageHeader(document.querySelector('[data-bs-toggle="tab"].active')?.id || 'tab-dashboard');
    
    // Инициализация автодополнения после загрузки данных
    setupAutocomplete('searchIncoming', () => {
        return store.incoming.map(i => [i.organization, i.kkNumber, i.subject]).flat();
    });
    setupAutocomplete('searchOutgoing', () => {
        return store.outgoing.map(o => [o.outgoingNumber, o.subject]).flat();
    });
    setupAutocomplete('searchEvents', () => {
        return store.events.map(e => [e.title, e.location]).flat();
    });

    if (window.hideLoading) hideLoading();
    if (window.showSuccess) showSuccess('Данные успешно загружены');
  } catch (error) {
    console.error('Ошибка инициализации приложения', error);
    if (window.hideLoading) hideLoading();
    if (window.showError) {
      showError('Не удалось загрузить данные. Проверьте подключение к серверу.');
    } else {
      alert('Не удалось загрузить данные. Проверьте подключение к серверу.');
    }
  }
}

function bindEventListeners() {
  if (addKpiBtn) addKpiBtn.addEventListener('click', addKpiRow);
  if (formEvent) {
    formEvent.addEventListener('submit', handleEventSubmit);
    formEvent.addEventListener('reset', () => {
      kpiList.innerHTML = '';
      if (attChecklist) {
        attChecklist.innerHTML = '';
        renderAttendeesChecklist([]);
      }
      delete formEvent.dataset.editId;
    });
  }
  if (searchEvents) searchEvents.addEventListener('input', renderEvents);
  if (attSelectAll) attSelectAll.addEventListener('click', () => setAllAttendees(true));
  if (attClear) attClear.addEventListener('click', () => setAllAttendees(false));
  if (exportJsonBtn) exportJsonBtn.addEventListener('click', exportJson);
  if (exportCsvBtn) exportCsvBtn.addEventListener('click', exportCsv);
  if (importJsonInput) importJsonInput.addEventListener('change', importJson);
}

bindEventListeners();
if (typeof initLettersUI === 'function') initLettersUI();

function renderTableFooter(elementId, shown, total, label = 'записей') {
  const el = document.getElementById(elementId);
  if (!el) return;
  if (total === 0) {
    el.innerHTML = '<span class="text-muted">Нет записей для отображения</span>';
    return;
  }
  el.innerHTML = `<span>Показано <strong>${shown}</strong> из <strong>${total}</strong> ${label}</span>`;
}

function scrollToCollapse(targetSelector) {
  const target = document.querySelector(targetSelector);
  if (!target) return;
  if (target.classList.contains('collapse') && !target.classList.contains('show')) {
    bootstrap.Collapse.getOrCreateInstance(target).show();
  }
  target.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function updatePageContextActions(tabId) {
  const container = document.getElementById('pageContextActions');
  if (!container) return;

  const configs = {
    'tab-incoming': [
      { id: 'ctxAddIncoming', label: '+ Добавить письмо', primary: true, target: '#collapseIncomingForm' },
      { id: 'ctxShowPending', label: 'Без ответа', primary: false, action: 'pending' }
    ],
    'tab-outgoing': [
      { id: 'ctxAddOutgoing', label: '+ Ответ ОС', primary: true, target: '#collapseOutgoingForm' }
    ],
    'tab-events': [
      { id: 'ctxAddEvent', label: '+ Мероприятие', primary: true, target: '#formEvent' }
    ]
  };

  const actions = configs[tabId] || [];
  container.innerHTML = actions.map((action) =>
    `<button type="button" class="btn btn-sm ${action.primary ? 'btn-primary' : 'btn-outline-secondary'}" id="${action.id}">${action.label}</button>`
  ).join('');

  document.getElementById('ctxAddIncoming')?.addEventListener('click', () => scrollToCollapse('#collapseIncomingForm'));
  document.getElementById('ctxAddOutgoing')?.addEventListener('click', () => scrollToCollapse('#collapseOutgoingForm'));
  document.getElementById('ctxAddEvent')?.addEventListener('click', () => {
    document.getElementById('formEvent')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  document.getElementById('ctxShowPending')?.addEventListener('click', () => {
    document.getElementById('notifyBtn')?.click();
  });
}

const PAGE_META = {
  'tab-dashboard': {
    title: 'Статистика обращений',
    subtitle: 'Ключевые показатели и динамика обращений'
  },
  'tab-incoming': { title: 'Входящий журнал', subtitle: '' },
  'tab-outgoing': { title: 'Исходящий журнал', subtitle: 'Ответы Общественного Совета' },
  'tab-members': { title: 'Члены Общественного Совета', subtitle: '' },
  'tab-commissions': { title: 'Комиссии', subtitle: 'Структура Общественного Совета' },
  'tab-kpi': { title: 'KPI', subtitle: 'Показатели работы членов и комиссий' },
  'tab-events': { title: 'Мероприятия', subtitle: 'Учёт участия членов ОС' }
};

function updatePageHeader(tabId) {
  const meta = PAGE_META[tabId] || { title: 'Журнал ОС', subtitle: '' };
  const titleEl = document.getElementById('pageTitle');
  const subtitleEl = document.getElementById('pageSubtitle');
  if (titleEl) titleEl.textContent = meta.title;

  let subtitle = meta.subtitle;
  if (tabId === 'tab-incoming') {
    subtitle = `${store.incoming.length} писем · срок ответа 15 рабочих дней`;
  } else if (tabId === 'tab-outgoing') {
    subtitle = `${store.outgoing.length} писем`;
  } else if (tabId === 'tab-members') {
    subtitle = `${membersCatalog.length} членов · ${commissionsCatalog.length} комиссий`;
  }
  if (subtitleEl) subtitleEl.textContent = subtitle;
  updatePageContextActions(tabId);
}
window.updatePageHeader = updatePageHeader;
window.renderTableFooter = renderTableFooter;
window.scrollToCollapse = scrollToCollapse;

function initAppNavigation() {
  const sidebarEl = document.getElementById('sidebar');
  const backdropEl = document.getElementById('sidebarBackdrop');

  const closeSidebar = () => {
    sidebarEl?.classList.remove('show');
    backdropEl?.classList.remove('show');
  };

  const openSidebar = () => {
    sidebarEl?.classList.add('show');
    backdropEl?.classList.add('show');
  };

  document.querySelectorAll('[data-bs-toggle="tab"]').forEach((btn) => {
    btn.addEventListener('shown.bs.tab', (event) => {
      updatePageHeader(event.target.id);
      if (event.target.id === 'tab-members') renderMembersGrid();
      if (event.target.id === 'tab-commissions') renderCommissionsGrid();
      closeSidebar();
    });
  });

  document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    if (sidebarEl?.classList.contains('show')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  });
  backdropEl?.addEventListener('click', closeSidebar);

  updatePageHeader('tab-dashboard');
}




function renderAll() {
  if (typeof renderIncomingOptions === 'function') renderIncomingOptions();
  if (typeof renderIncoming === 'function') renderIncoming();
  if (typeof renderOutgoing === 'function') renderOutgoing();
  if (typeof renderKPIs === 'function') renderKPIs();
  if (typeof renderCharts === 'function') renderCharts();
  renderEvents();
}


function parseRealtimePayload(payload) {
  if (!payload) return {};
  if (typeof payload === 'string') {
    try {
      return JSON.parse(payload);
    } catch (err) {
      console.warn('Failed to parse realtime payload', err);
      return {};
    }
  }
  return payload;
}

function showDeadlineNotification(data) {
  const seqLabel = data.seq ? `Вх.${data.seq}` : `ID ${data.id}`;
  const dueText = data.due_date ? new Date(data.due_date).toLocaleDateString('ru-RU') : null;
  let message = `${seqLabel}: `;
  if (data.status === 'overdue') {
    const days = Math.abs(Number(data.days_left || 0));
    message += `срок просрочен на ${days} дн.`;
  } else {
    const days = Math.max(0, Number(data.days_left || 0));
    message += `до дедлайна ${days} дн.`;
  }
  if (dueText) {
    message += ` (срок: ${dueText})`;
  }
  if (data.organization) {
    message += ` • ${data.organization}`;
  }
  if (window.showWarning) {
    showWarning(message);
  } else {
    alert(message);
  }
}

function initRealtime() {
  try {
    if (!window.Pusher || !window.PUSHER_KEY) {
      console.warn('Pusher is not configured');
      return;
    }
    pusherClient = new Pusher(window.PUSHER_KEY, {
      cluster: window.PUSHER_CLUSTER || 'eu',
      forceTLS: true
    });
    const documentsChannelName = window.PUSHER_CHANNEL_DOCUMENTS || 'council-documents';
    const eventsChannelName = window.PUSHER_CHANNEL_EVENTS || 'council-events';
    const deadlinesChannelName = window.PUSHER_CHANNEL_DEADLINES || 'council-deadlines';

    const docsChannel = pusherClient.subscribe(documentsChannelName);
    docsChannel.bind('documents-updated', async (payload) => {
      const data = parseRealtimePayload(payload);
      await refreshLetters();
      renderAll();
      if (data && data.action && window.showInfo) {
        const typeText = data.type === 'incoming' ? 'Входящее письмо' : 'Исходящее письмо';
        const actionText = data.action === 'create' ? 'добавлено' :
                           data.action === 'update' ? 'обновлено' : 'удалено';
        showInfo(`${typeText} ${actionText} другим пользователем`);
      }
    });

    const eventsChannel = pusherClient.subscribe(eventsChannelName);
    eventsChannel.bind('events-updated', async (payload) => {
      const data = parseRealtimePayload(payload);
      await refreshEvents();
      renderEvents();
      if (data && data.action && window.showInfo) {
        const actionText = data.action === 'create' ? 'создано' :
                           data.action === 'update' ? 'обновлено' : 'удалено';
        showInfo(`Мероприятие ${actionText} другим пользователем`);
      }
    });

    const deadlinesChannel = pusherClient.subscribe(deadlinesChannelName);
    deadlinesChannel.bind('deadline-warning', (payload) => {
      const data = parseRealtimePayload(payload);
      if (!data) return;
      showDeadlineNotification(data);
    });
  } catch (e) {
    console.warn('Realtime disabled:', e);
  }
}
// ====== Events API ======
async function refreshEvents() {
  const resp = await fetch(`${API_BASE}/events.php`);
  if (!resp.ok) throw new Error('Не удалось загрузить мероприятия');
  const data = await resp.json();
  store.events = Array.isArray(data) ? data : (data.items || []);
}

function kpiFromForm() {
  const rows = Array.from(kpiList.querySelectorAll('.kpi-row'));
  return rows.map(row => {
    const metric = row.querySelector('input[name="metric"]').value.trim();
    const valueNumeric = row.querySelector('input[name="value_numeric"]').value;
    const valueText = row.querySelector('input[name="value_text"]').value.trim();
    const vn = valueNumeric !== '' ? Number(valueNumeric) : null;
    return { metric, value_numeric: vn, value_text: valueText || null };
  }).filter(k => k.metric);
}

function addKpiRow(metric = '', valueNumeric = '', valueText = '') {
  const wrapper = document.createElement('div');
  wrapper.className = 'kpi-row col-12 d-flex gap-2';
  wrapper.innerHTML = `
    <input class="form-control form-control-sm" name="metric" placeholder="Метрика (например, «Регистрации»)" value="${metric}"/>
    <input class="form-control form-control-sm" name="value_numeric" type="number" step="0.01" placeholder="Число" value="${valueNumeric}"/>
    <input class="form-control form-control-sm" name="value_text" placeholder="Текст (опц.)" value="${valueText}"/>
    <button type="button" class="btn btn-sm btn-outline-danger">×</button>
  `;
  wrapper.querySelector('button').onclick = () => wrapper.remove();
  kpiList.appendChild(wrapper);
}

function attendeesFromForm() {
  if (!attChecklist) return [];
  const checks = Array.from(attChecklist.querySelectorAll('input.att-item'));
  return checks.map(ch => ({ full_name: ch.dataset.name, attended: ch.checked }));
}

function renderAttendeesChecklist(selectedNames = []) {
  if (!attChecklist) return;
  const byCommission = new Map();
  membersCatalog.forEach(m => {
    const key = m.commission_name || 'Без комиссии';
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
    title.className = 'mb-1 fw-semibold d-flex align-items-center gap-2';
    const color = list[0]?.commission_color || '#0d6efd';
    const dot = document.createElement('span');
    dot.style.width = '10px';
    dot.style.height = '10px';
    dot.style.borderRadius = '50%';
    dot.style.background = color;
    title.appendChild(dot);
    title.appendChild(document.createTextNode(commission));
    card.appendChild(title);
    list.sort((a,b)=> a.full_name.localeCompare(b.full_name)).forEach(m => {
      const id = `att_${m.id}`;
      const wrap = document.createElement('div');
      wrap.className = 'form-check';
      wrap.innerHTML = `
        <input class="form-check-input att-item" type="checkbox" id="${id}" data-name="${escapeHtml(m.full_name)}">
        <label class="form-check-label" for="${id}">${escapeHtml(m.full_name)}</label>
      `;
      const input = wrap.querySelector('input');
      input.checked = selectedNames.includes(m.full_name);
      card.appendChild(wrap);
    });
    col.appendChild(card);
    attChecklist.appendChild(col);
  });
}


function setAllAttendees(checked) {
  if (!attChecklist) return;
  attChecklist.querySelectorAll('input.att-item').forEach(ch => ch.checked = !!checked);
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
    attendees: attendeesFromForm()
  };
  // Автовычисление явки
  const totalInList = payload.attendees.length;
  const presentCount = payload.attendees.filter(a => a.attended).length;
  payload.participants_total = presentCount;
  payload.attendance_percent = totalInList ? (presentCount * 100 / totalInList) : 0;
  try {
    const editId = formEvent.dataset.editId;
    if (editId) {
      payload.id = Number(editId);
      const resp = await fetch(`${API_BASE}/events.php`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      if (!resp.ok) throw new Error('Ошибка обновления');
    } else {
      const resp = await fetch(`${API_BASE}/events.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      if (!resp.ok) throw new Error('Ошибка сохранения');
    }
    formEvent.reset();
    kpiList.innerHTML = '';
    renderAttendeesChecklist([]);
    if (typeof renderIncomingRecipients === 'function') renderIncomingRecipients();
    if (typeof renderOutgoingRecipients === 'function') renderOutgoingRecipients();
    delete formEvent.dataset.editId;
    await refreshEvents();
    renderEvents();
    if (window.showSuccess) {
      showSuccess('Мероприятие успешно сохранено');
    }
  } catch (err) {
    console.error(err);
    if (window.showError) {
      showError('Не удалось сохранить мероприятие');
    } else {
      alert('Не удалось сохранить мероприятие');
    }
  }
}

function renderEvents() {
  if (!tableEventsBody) return;
  const q = (searchEvents?.value || '').toLowerCase();
  const rows = store.events
    .filter(ev => {
      const hay = `${ev.title} ${ev.location || ''} ${ev.notes || ''}`.toLowerCase();
      return hay.includes(q);
    })
    .map(ev => {
      const present = Number(ev.attendees_present ?? 0);
      const total = Number(ev.attendees_total ?? 0);
      return `
        <tr>
          <td>${formatDateISOtoRus(ev.event_date)}</td>
          <td>${escapeHtml(ev.title)}</td>
          <td>${escapeHtml(ev.location || '')}</td>
          <td class="text-end">${present}/${total}</td>
          <td class="text-end">${Number(ev.attendance_percent || 0).toFixed(2)}%</td>
          <td class="text-end table-actions">
            <button class="btn btn-sm btn-outline-secondary" title="Кто присутствовал" data-action="view-att" data-id="${ev.id}">
              <i class="bi bi-people"></i>
            </button>
            <button class="btn btn-sm btn-outline-primary" title="Изменить" data-action="edit-event" data-id="${ev.id}">
              <i class="bi bi-pencil"></i>
            </button>
            ${canDelete() ? `<button class="btn btn-sm btn-outline-danger" title="Удалить" data-action="del-event" data-id="${ev.id}">
              <i class="bi bi-trash"></i>
            </button>` : ''}
          </td>
        </tr>
      `;
    }).join('');
  tableEventsBody.innerHTML = rows || '<tr><td colspan="6" class="text-center text-muted">Нет мероприятий</td></tr>';

  tableEventsBody.querySelectorAll('[data-action="del-event"]').forEach(btn => {
    btn.addEventListener('click', () => deleteEvent(btn.dataset.id));
  });
  tableEventsBody.querySelectorAll('[data-action="edit-event"]').forEach(btn => {
    btn.addEventListener('click', () => editEvent(btn.dataset.id));
  });
  tableEventsBody.querySelectorAll('[data-action="view-att"]').forEach(btn => {
    btn.addEventListener('click', () => viewEventAttendees(btn.dataset.id));
  });
}

async function deleteEvent(id) {
  if (window.confirmDelete) {
    confirmDelete('Вы уверены, что хотите удалить это мероприятие?', async () => {
      const resp = await fetch(`${API_BASE}/events.php?id=${id}`, { method: 'DELETE' });
      if (!resp.ok) {
        if (window.showError) {
          showError('Не удалось удалить мероприятие');
        } else {
          alert('Не удалось удалить');
        }
        return;
      }
      await refreshEvents();
      renderEvents();
      if (window.showSuccess) {
        showSuccess('Мероприятие успешно удалено');
      }
    });
  } else {
    if (!confirm('Удалить мероприятие?')) return;
    const resp = await fetch(`${API_BASE}/events.php?id=${id}`, { method: 'DELETE' });
    if (!resp.ok) {
      alert('Не удалось удалить');
      return;
    }
    await refreshEvents();
    renderEvents();
  }
}

async function editEvent(id) {
  try {
    const resp = await fetch(`${API_BASE}/events.php?id=${id}`);
    if (!resp.ok) throw new Error('not found');
    const ev = await resp.json();
    evTitle.value = ev.title || '';
    evDate.value = (ev.event_date || todayISO).slice(0,10);
    evLocation.value = ev.location || '';
    evNotes.value = ev.notes || '';
    kpiList.innerHTML = '';
    (ev.kpi || []).forEach(k => addKpiRow(k.metric || '', k.value_numeric ?? '', k.value_text || ''));
    const selected = (ev.attendees || []).map(a => a.full_name).filter(Boolean);
    renderAttendeesChecklist(selected);
    formEvent.dataset.editId = String(ev.id);
    document.getElementById('tab-events')?.click();
  } catch (e) {
    console.error(e);
    alert('Не удалось загрузить мероприятие');
  }
}

async function viewEventAttendees(eventId) {
  try {
    const resp = await fetch(`${API_BASE}/events.php?id=${eventId}`);
    if (!resp.ok) throw new Error('not found');
    const ev = await resp.json();
    const body = document.getElementById('eventAttendeesBody');
    const present = (ev.attendees || []).filter(a => !!a.attended).map(a => a.full_name);
    const absent = (ev.attendees || []).filter(a => !a.attended).map(a => a.full_name);
    const blocks = [];
    blocks.push(`<div class="mb-2"><strong>${escapeHtml(ev.title || '')}</strong> — ${formatDateISOtoRus(ev.event_date)}</div>`);
    blocks.push(`<div class="mb-1"><span class="badge text-bg-success">Присутствовали: ${present.length}</span></div>`);
    if (present.length) {
      blocks.push(`<div class="small">${present.map(n => `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtml(n)}</span>`).join(' ')}</div>`);
    }
    blocks.push(`<hr/>`);
    blocks.push(`<div class="mb-1"><span class="badge text-bg-secondary">Отсутствовали: ${absent.length}</span></div>`);
    if (absent.length) {
      blocks.push(`<div class="small">${absent.map(n => `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtml(n)}</span>`).join(' ')}</div>`);
    }
    body.innerHTML = blocks.join('');
    const modal = new bootstrap.Modal(document.getElementById('eventAttendeesModal'));
    modal.show();
  } catch (e) {
    console.error(e);
    alert('Не удалось показать список присутствующих');
  }
}



// Экспорт / импорт реализация
function exportJson() {
  const blob = new Blob([JSON.stringify(store, null, 2)], { type: "application/json;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `os_journal_${new Date().toISOString().slice(0,10)}.json`;
  a.click();
  URL.revokeObjectURL(url);
}

function exportCsv() {
  // Две вкладки: incoming, outgoing
  const incHeader = ["Тип","РегНомер","Дата","Организация","Категория","Номер","Тема","Примечание"].join(",");
  const incRows = store.incoming.map(i => [
    "Входящее",
    `Вх.${i.seq}`,
    formatDateISOtoRus(i.date),
    sanitizeCsv(i.organization),
    sanitizeCsv(i.category || "KK"),
    sanitizeCsv(i.kkNumber),
    sanitizeCsv(i.subject || ""),
    sanitizeCsv(i.note || "")
  ].join(","));

  const outHeader = ["Тип","Порядк№","Дата","Исходящий№","Организация","Категория","Связ.Входящее","Тема","Примечание"].join(",");
  const outRows = store.outgoing.map(o => {
    const inc = store.incoming.find(i => i.id === o.incomingRefId);
    return [
      "Исходящее",
      `Исх.${o.seq}`,
      formatDateISOtoRus(o.date),
      sanitizeCsv(o.outgoingNumber),
      sanitizeCsv(inc?.organization || ""),
      sanitizeCsv(inc?.category || "KK"),
      sanitizeCsv(`Вх.${inc?.seq ?? "?"} ${inc?.kkNumber ?? ""}`),
      sanitizeCsv(o.subject || ""),
      sanitizeCsv(o.note || "")
    ].join(",");
  });

  const csv = [incHeader, ...incRows, "", outHeader, ...outRows].join("\n");
  const blob = new Blob(["\ufeff" + csv], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `os_journal_${new Date().toISOString().slice(0,10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

function importJson(ev) {
  alert('Импорт через файл временно недоступен в новой версии — используйте API или добавляйте письма вручную.');
  if (ev?.target) ev.target.value = "";
}

function sanitizeCsv(str) {
  const s = String(str ?? "");
  if (s.includes(",") || s.includes("\n") || s.includes('"')) {
    return '"' + s.replace(/"/g, '""') + '"';
  }
  return s;
}

// UI Notification Functions
function showLoading(message) {
  const overlay = document.getElementById('loadingOverlay');
  if (overlay) {
    overlay.style.display = 'flex';
    const text = overlay.querySelector('p');
    if (text) text.textContent = message || 'Загрузка...';
  }
}

function hideLoading() {
  const overlay = document.getElementById('loadingOverlay');
  if (overlay) {
    overlay.style.display = 'none';
  }
}

function showToast(message, type = 'info') {
  const container = document.querySelector('.toast-container');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} border-0`;
  toast.setAttribute('role', 'alert');
  toast.setAttribute('aria-live', 'assertive');
  toast.setAttribute('aria-atomic', 'true');

  toast.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${escapeHtml(message)}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Закрыть"></button>
    </div>
  `;

  container.appendChild(toast);
  const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
  bsToast.show();

  toast.addEventListener('hidden.bs.toast', () => {
    toast.remove();
  });
}

function showSuccess(message) {
  showToast(message, 'success');
}

function showError(message) {
  showToast(message, 'error');
}

function showWarning(message) {
  showToast(message, 'warning');
}

// Expose functions to window for backward compatibility
window.showLoading = showLoading;
window.hideLoading = hideLoading;
window.showSuccess = showSuccess;
window.showError = showError;
window.showWarning = showWarning;
window.renderAll = renderAll;
window.initializeApp = initializeApp;


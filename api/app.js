// Shell SPA: core, modules, app-shell, events-ui

function getSessionUser() {
  try {
    const raw = localStorage.getItem('user');
    return raw ? JSON.parse(raw) : null;
  } catch (error) {
    console.warn('Не удалось прочитать пользователя из localStorage', error);
    return null;
  }
}

let pusherClient = null;

async function initializeApp() {
  try {
    window.showLoading?.('Загрузка данных...');
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
    if (typeof renderAttendeesChecklist === 'function') renderAttendeesChecklist([]);
    if (typeof renderIncomingRecipients === 'function') renderIncomingRecipients();
    if (typeof renderOutgoingRecipients === 'function') renderOutgoingRecipients();
    await refreshLetters();
    await refreshEvents();
    initRealtime();
    renderAll();
    renderKpiExtra();
    updatePageHeader(document.querySelector('[data-bs-toggle="tab"].active')?.id || 'tab-dashboard');

    window.setupAutocomplete?.('searchIncoming', () =>
      store.incoming.flatMap((i) => [i.organization, i.kkNumber, i.subject]));
    window.setupAutocomplete?.('searchOutgoing', () =>
      store.outgoing.flatMap((o) => [o.outgoingNumber, o.subject]));
    window.setupAutocomplete?.('searchEvents', () =>
      (store.events || []).flatMap((e) => [e.title, e.location]));

    window.hideLoading?.();
    window.showSuccess?.('Данные успешно загружены');
  } catch (error) {
    console.error('Ошибка инициализации приложения', error);
    window.hideLoading?.();
    window.showError?.('Не удалось загрузить данные. Проверьте подключение к серверу.');
  }
}

function renderTableFooter(elementId, shown, total, label = 'записей', extraHtml = '') {
  const el = document.getElementById(elementId);
  if (!el) return;
  if (total === 0) {
    el.innerHTML = '<span class="text-muted">Нет записей для отображения</span>';
    return;
  }
  el.innerHTML = `<span>Показано <strong>${shown}</strong> из <strong>${total}</strong> ${label}</span>${extraHtml}`;
}

function scrollToCollapse(targetSelector) {
  const target = document.querySelector(targetSelector);
  if (!target) return;
  if (target.classList.contains('collapse') && !target.classList.contains('show')) {
    bootstrap.Collapse.getOrCreateInstance(target).show();
  }
  target.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function pageMeta() {
  const t = window.AppI18n?.t || ((_, fb) => fb);
  return {
    'tab-dashboard': { title: t('page.dashboard', 'Статистика обращений'), subtitle: t('page.dashboard_sub', 'Ключевые показатели и динамика обращений') },
    'tab-incoming': { title: t('page.incoming', 'Входящий журнал'), subtitle: '' },
    'tab-outgoing': { title: t('page.outgoing', 'Исходящий журнал'), subtitle: t('page.outgoing_sub', 'Ответы Общественного Совета') },
    'tab-members': { title: t('page.members', 'Члены Общественного Совета'), subtitle: '' },
    'tab-commissions': { title: t('page.commissions', 'Комиссии'), subtitle: t('page.commissions_sub', 'Структура Общественного Совета') },
    'tab-kpi': { title: t('page.kpi', 'KPI'), subtitle: t('page.kpi_sub', 'Показатели работы членов и комиссий') },
    'tab-events': { title: t('page.events', 'Мероприятия'), subtitle: t('page.events_sub', 'Учёт участия членов ОС') },
  };
}

function updatePageContextActions(tabId) {
  const t = window.AppI18n?.t || ((_, fb) => fb);
  const container = document.getElementById('pageContextActions');
  if (!container) return;
  const configs = {
    'tab-incoming': [
      { id: 'ctxAddIncoming', label: t('ctx.add_incoming', '+ Добавить письмо'), primary: true },
      { id: 'ctxShowPending', label: t('ctx.pending', 'Без ответа'), primary: false },
    ],
    'tab-outgoing': [{ id: 'ctxAddOutgoing', label: t('ctx.add_outgoing', '+ Ответ ОС'), primary: true }],
    'tab-events': [{ id: 'ctxAddEvent', label: t('ctx.add_event', '+ Мероприятие'), primary: true }],
  };
  container.innerHTML = (configs[tabId] || []).map((a) =>
    `<button type="button" class="btn btn-sm ${a.primary ? 'btn-primary' : 'btn-outline-secondary'}" id="${a.id}">${a.label}</button>`
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

function updatePageHeader(tabId) {
  const meta = pageMeta()[tabId] || { title: 'Журнал ОС', subtitle: '' };
  const titleEl = document.getElementById('pageTitle');
  const subtitleEl = document.getElementById('pageSubtitle');
  if (titleEl) titleEl.textContent = meta.title;
  let subtitle = meta.subtitle;
  if (tabId === 'tab-incoming') {
    subtitle = `${store.incoming.length} ${window.AppI18n?.t('letters.count', 'писем') || 'писем'} · ${window.AppI18n?.t('letters.deadline', 'срок ответа 15 рабочих дней') || 'срок ответа 15 рабочих дней'}`;
  } else if (tabId === 'tab-outgoing') {
    subtitle = `${store.outgoing.length} ${window.AppI18n?.t('letters.count', 'писем') || 'писем'}`;
  } else if (tabId === 'tab-members') {
    subtitle = `${(window.membersCatalog || []).length} ${window.AppI18n?.t('members.count', 'членов') || 'членов'} · ${(window.commissionsCatalog || []).length} ${window.AppI18n?.t('commissions.count', 'комиссий') || 'комиссий'}`;
  }
  if (subtitleEl) subtitleEl.textContent = subtitle;
  updatePageContextActions(tabId);
}

function initAppNavigation() {
  const sidebarEl = document.getElementById('sidebar');
  const backdropEl = document.getElementById('sidebarBackdrop');
  const closeSidebar = () => { sidebarEl?.classList.remove('show'); backdropEl?.classList.remove('show'); };
  const openSidebar = () => { sidebarEl?.classList.add('show'); backdropEl?.classList.add('show'); };
  document.querySelectorAll('[data-bs-toggle="tab"]').forEach((btn) => {
    btn.addEventListener('shown.bs.tab', (event) => {
      updatePageHeader(event.target.id);
      if (event.target.id === 'tab-members') renderMembersGrid();
      if (event.target.id === 'tab-commissions') renderCommissionsGrid();
      closeSidebar();
    });
  });
  document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    sidebarEl?.classList.contains('show') ? closeSidebar() : openSidebar();
  });
  backdropEl?.addEventListener('click', closeSidebar);
  updatePageHeader('tab-dashboard');
}

window.addEventListener('app:langchange', () => {
  updatePageHeader(document.querySelector('#sidebarNav .nav-link.active')?.id || 'tab-dashboard');
  if (typeof renderEvents === 'function') renderEvents();
  if (typeof renderIncoming === 'function') renderIncoming();
  if (typeof renderOutgoing === 'function') renderOutgoing();
});

function renderAll() {
  if (typeof renderIncomingOptions === 'function') renderIncomingOptions();
  if (typeof renderIncoming === 'function') renderIncoming();
  if (typeof renderOutgoing === 'function') renderOutgoing();
  if (typeof renderKPIs === 'function') renderKPIs();
  if (typeof renderCharts === 'function') renderCharts();
  if (typeof renderEvents === 'function') renderEvents();
}

function parseRealtimePayload(payload) {
  if (!payload) return {};
  if (typeof payload === 'string') {
    try { return JSON.parse(payload); } catch { return {}; }
  }
  return payload;
}

function showDeadlineNotification(data) {
  const seqLabel = data.seq ? `Вх.${data.seq}` : `ID ${data.id}`;
  const dueText = data.due_date ? new Date(data.due_date).toLocaleDateString('ru-RU') : null;
  let message = `${seqLabel}: `;
  if (data.status === 'overdue') {
    message += `срок просрочен на ${Math.abs(Number(data.days_left || 0))} дн.`;
  } else {
    message += `до дедлайна ${Math.max(0, Number(data.days_left || 0))} дн.`;
  }
  if (dueText) message += ` (срок: ${dueText})`;
  if (data.organization) message += ` • ${data.organization}`;
  window.showWarning ? window.showWarning(message) : alert(message);
}

function initRealtime() {
  try {
    if (!window.Pusher || !window.PUSHER_KEY) return;
    pusherClient = new Pusher(window.PUSHER_KEY, {
      cluster: window.PUSHER_CLUSTER || 'eu',
      forceTLS: true,
    });
    pusherClient.subscribe(window.PUSHER_CHANNEL_DOCUMENTS || 'council-documents')
      .bind('documents-updated', async (payload) => {
        await refreshLetters();
        renderAll();
        const data = parseRealtimePayload(payload);
        if (data?.action) {
          const typeText = data.type === 'incoming' ? 'Входящее письмо' : 'Исходящее письмо';
          const actionText = data.action === 'create' ? 'добавлено' : data.action === 'update' ? 'обновлено' : 'удалено';
          window.showInfo?.(`${typeText} ${actionText} другим пользователем`);
        }
      });
    pusherClient.subscribe(window.PUSHER_CHANNEL_EVENTS || 'council-events')
      .bind('events-updated', async (payload) => {
        await refreshEvents();
        renderEvents();
        const data = parseRealtimePayload(payload);
        if (data?.action) {
          const actionText = data.action === 'create' ? 'создано' : data.action === 'update' ? 'обновлено' : 'удалено';
          window.showInfo?.(`Мероприятие ${actionText} другим пользователем`);
        }
      });
    pusherClient.subscribe(window.PUSHER_CHANNEL_DEADLINES || 'council-deadlines')
      .bind('deadline-warning', (payload) => {
        const data = parseRealtimePayload(payload);
        if (data) showDeadlineNotification(data);
      });
  } catch (e) {
    console.warn('Realtime disabled:', e);
  }
}

if (typeof initLettersUI === 'function') initLettersUI();

window.updatePageHeader = updatePageHeader;
window.renderTableFooter = renderTableFooter;
window.scrollToCollapse = scrollToCollapse;
window.renderAll = renderAll;
window.initializeApp = initializeApp;

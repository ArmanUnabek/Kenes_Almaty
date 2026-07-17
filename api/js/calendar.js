/**
 * Календарь: письма, дедлайны ответов и мероприятия по датам.
 * Использует store.incoming, store.outgoing, store.events.
 *
 * ВАЖНО: этот файл подключается отдельным <script src> ПОСЛЕ dist/app.js
 * и переопределяет window.initCalendar / window.refreshCalendar из бандла
 * (старая календарная часть бандла становится мёртвым кодом: её initCalendar
 * никогда не вызывается, а её render не находит контейнер, пока разметку
 * строит эта версия).
 *
 * v2: месяц/неделя/список, статистика, drag-to-reschedule, поиск, печать,
 * быстрое создание события, клавиатурная навигация, ics-экспорт диапазона.
 */
(function (window) {
  'use strict';

  const esc = (s) => (window.escapeHtml || window.AppUtils?.escapeHtml
    || ((v) => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')))(s);
  const t = (k, fb) => window.AppI18n?.t(k, fb) ?? fb;
  const canWrite = () => window.canWrite?.() ?? false;

  // Локализация: регистрируем ключи в общем словаре проекта
  window.AppI18n?.register?.({
    ru: {
      'calendar.prev_month': 'Предыдущий месяц',
      'calendar.next_month': 'Следующий месяц',
      'calendar.today': 'Сегодня',
      'calendar.mine': 'Мои',
      'calendar.mine_title': 'Только письма, где я ответственный',
      'calendar.events_toggle': 'Показать/скрыть мероприятия',
      'calendar.export_ics': 'Экспорт .ics',
      'calendar.export_ics_title': 'Скачать мероприятия текущего периода в формате iCalendar',
      'calendar.events': 'Мероприятия',
      'calendar.incoming': 'Входящие',
      'calendar.outgoing': 'Исходящие',
      'calendar.deadlines': 'Срок ответа',
      'calendar.overdue': 'просрочено',
      'calendar.in_short': 'Вх.',
      'calendar.out_short': 'Исх.',
      'calendar.empty_day': 'Писем и мероприятий в этот день нет.',
      'calendar.empty_month': 'В этом месяце нет писем и мероприятий.',
      'calendar.open_letter': 'Открыть письмо',
      'calendar.find_event': 'Найти в списке мероприятий',
      'calendar.close': 'Закрыть',
      'calendar.legend_incoming': 'входящие',
      'calendar.legend_outgoing': 'исходящие',
      'calendar.legend_deadline': 'срок ответа',
      'calendar.legend_event': 'мероприятие',
      'calendar.letters_word': 'писем',
      'calendar.events_word': 'мероприятий',
      'calendar.deadlines_word': 'сроков',
      'calendar.view_month': 'Месяц',
      'calendar.view_week': 'Неделя',
      'calendar.view_list': 'Список',
      'calendar.stat_pending': 'Писем без ответа',
      'calendar.stat_deadlines_week': 'Дедлайнов на этой неделе',
      'calendar.stat_events_month': 'Событий в этом месяце',
      'calendar.stat_deadlines_title': 'Показать только дни со сроками ответа',
      'calendar.search_placeholder': 'Найти в календаре...',
      'calendar.print': 'Печать',
      'calendar.print_title': 'Распечатать текущий период',
      'calendar.add_event': '+ Событие',
      'calendar.add_event_title': 'Создать мероприятие на эту дату',
      'calendar.no_write': 'Недостаточно прав',
      'calendar.reschedule_ok': 'Мероприятие перенесено на {date}',
      'calendar.reschedule_fail': 'Не удалось перенести мероприятие',
      'calendar.list_empty': 'Ближайшие 14 дней свободны от писем и мероприятий.',
      'calendar.week_of': 'Неделя',
      'calendar.no_matches': 'Совпадений не найдено',
    },
    kz: {
      'calendar.prev_month': 'Алдыңғы ай',
      'calendar.next_month': 'Келесі ай',
      'calendar.today': 'Бүгін',
      'calendar.mine': 'Менікі',
      'calendar.mine_title': 'Тек мен жауапты хаттар',
      'calendar.events_toggle': 'Іс-шараларды көрсету/жасыру',
      'calendar.export_ics': '.ics экспорты',
      'calendar.export_ics_title': 'Ағымдағы кезең іс-шараларын iCalendar форматында жүктеу',
      'calendar.events': 'Іс-шаралар',
      'calendar.incoming': 'Кіріс',
      'calendar.outgoing': 'Шығыс',
      'calendar.deadlines': 'Жауап мерзімі',
      'calendar.overdue': 'мерзімі өткен',
      'calendar.in_short': 'Кір.',
      'calendar.out_short': 'Шығ.',
      'calendar.empty_day': 'Бұл күні хаттар мен іс-шаралар жоқ.',
      'calendar.empty_month': 'Бұл айда хаттар мен іс-шаралар жоқ.',
      'calendar.open_letter': 'Хатты ашу',
      'calendar.find_event': 'Іс-шаралар тізімінен табу',
      'calendar.close': 'Жабу',
      'calendar.legend_incoming': 'кіріс',
      'calendar.legend_outgoing': 'шығыс',
      'calendar.legend_deadline': 'жауап мерзімі',
      'calendar.legend_event': 'іс-шара',
      'calendar.letters_word': 'хат',
      'calendar.events_word': 'іс-шара',
      'calendar.deadlines_word': 'мерзім',
      'calendar.view_month': 'Ай',
      'calendar.view_week': 'Апта',
      'calendar.view_list': 'Тізім',
      'calendar.stat_pending': 'Жауапсыз хаттар',
      'calendar.stat_deadlines_week': 'Осы аптадағы мерзімдер',
      'calendar.stat_events_month': 'Осы айдағы іс-шаралар',
      'calendar.stat_deadlines_title': 'Тек жауап мерзімі бар күндерді көрсету',
      'calendar.search_placeholder': 'Күнтізбеден іздеу...',
      'calendar.print': 'Басып шығару',
      'calendar.print_title': 'Ағымдағы кезеңді басып шығару',
      'calendar.add_event': '+ Іс-шара',
      'calendar.add_event_title': 'Осы күнге іс-шара құру',
      'calendar.no_write': 'Құқық жеткіліксіз',
      'calendar.reschedule_ok': 'Іс-шара {date} күніне ауыстырылды',
      'calendar.reschedule_fail': 'Іс-шараны ауыстыру мүмкін болмады',
      'calendar.list_empty': 'Алдағы 14 күнде хаттар мен іс-шаралар жоқ.',
      'calendar.week_of': 'Апта',
      'calendar.no_matches': 'Сәйкестік табылмады',
    },
  });

  const DAYS_SHORT = {
    ru: ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'],
    kz: ['Дс', 'Сс', 'Ср', 'Бс', 'Жм', 'Сб', 'Жс'],
  };

  function currentLang() {
    const lang = window.AppI18n?.lang;
    return lang === 'kz' ? 'kz' : 'ru';
  }
  function getMonthNames() {
    const m = window.AppI18n?.MONTHS;
    // AppI18n.MONTHS — объект {ru:[...], kz:[...]}
    if (m && !Array.isArray(m)) return m[currentLang()] || m.ru;
    return Array.isArray(m) ? m : DAYS_FALLBACK_MONTHS;
  }
  const DAYS_FALLBACK_MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
    'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
  function getDayNames() {
    return DAYS_SHORT[currentLang()];
  }

  // Локальная (не UTC) дата -> 'YYYY-MM-DD'
  function toLocalISO(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  function mondayOf(d) {
    const r = new Date(d);
    let dow = r.getDay() - 1;
    if (dow < 0) dow = 6;
    r.setDate(r.getDate() - dow);
    r.setHours(0, 0, 0, 0);
    return r;
  }

  function addDays(d, n) {
    const r = new Date(d);
    r.setDate(r.getDate() + n);
    return r;
  }

  const VIEWS = ['month', 'week', 'list'];
  const VIEW_STORAGE_KEY = 'calendarView';

  let currentYear = new Date().getFullYear();
  let currentMonth = new Date().getMonth(); // 0-based
  let weekStart = mondayOf(new Date());
  let listAnchor = new Date(); listAnchor.setHours(0, 0, 0, 0);
  let showMine = false;
  let showEvents = true;
  let selectedDate = null;
  let view = 'month';
  try {
    const saved = window.localStorage?.getItem(VIEW_STORAGE_KEY);
    if (saved && VIEWS.includes(saved)) view = saved;
  } catch (_e) { /* localStorage недоступен */ }
  let deadlineFilterActive = false;
  let searchQuery = '';
  let matchedDates = new Set();
  let draggingEventId = null;

  function getStore() {
    return window.store || { incoming: [], outgoing: [], events: [] };
  }

  function currentMemberId() {
    return window.sessionUser?.member_id ?? window.getSessionUser?.()?.member_id ?? null;
  }

  function matchesMine(letter, memberId) {
    if (!memberId) return true;
    return (letter.members || []).some((m) => m.member_id === memberId || m.id === memberId);
  }

  function getLettersForDate(dateStr) {
    const store = getStore();
    const memberId = showMine ? currentMemberId() : null;
    const filter = (letters) => (letters || []).filter(
      (l) => l.date?.slice(0, 10) === dateStr && matchesMine(l, memberId)
    );
    return { incoming: filter(store.incoming), outgoing: filter(store.outgoing) };
  }

  // Входящие без ответа, срок которых (15 раб. дней) приходится на dateStr
  function getDeadlinesForDate(dateStr) {
    const u = window.AppUtils;
    if (!u?.getLetterDueDate || !u?.isLetterPending) return [];
    const store = getStore();
    const memberId = showMine ? currentMemberId() : null;
    const today = new Date(); today.setHours(0, 0, 0, 0);
    return (store.incoming || []).filter((l) => {
      if (!u.isLetterPending(l) || !l.date) return false;
      if (!matchesMine(l, memberId)) return false;
      const due = u.getLetterDueDate(l.date);
      return due && toLocalISO(due) === dateStr;
    }).map((l) => {
      const due = u.getLetterDueDate(l.date);
      return { letter: l, due, overdue: due < today };
    });
  }

  function getEventsForDate(dateStr) {
    if (!showEvents) return [];
    const store = getStore();
    return (store.events || []).filter((ev) => ev.event_date?.slice(0, 10) === dateStr);
  }

  function allPendingCount() {
    const u = window.AppUtils;
    const store = getStore();
    const memberId = showMine ? currentMemberId() : null;
    if (!u?.isLetterPending) return 0;
    return (store.incoming || []).filter((l) => u.isLetterPending(l) && matchesMine(l, memberId)).length;
  }

  function deadlinesThisWeekCount() {
    const u = window.AppUtils;
    if (!u?.getLetterDueDate || !u?.isLetterPending) return 0;
    const store = getStore();
    const memberId = showMine ? currentMemberId() : null;
    const ws = mondayOf(new Date());
    const we = addDays(ws, 6);
    return (store.incoming || []).filter((l) => {
      if (!u.isLetterPending(l) || !l.date) return false;
      if (!matchesMine(l, memberId)) return false;
      const due = u.getLetterDueDate(l.date);
      return due && due >= ws && due <= we;
    }).length;
  }

  function eventsThisMonthCount() {
    const store = getStore();
    const prefix = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}`;
    return (store.events || []).filter((ev) => ev.event_date?.slice(0, 7) === prefix).length;
  }

  function dateHasDeadline(dateStr) {
    return getDeadlinesForDate(dateStr).length > 0;
  }

  // --- Поиск ---------------------------------------------------------------

  function textMatches(hay, needle) {
    return hay && String(hay).toLowerCase().includes(needle);
  }

  function dateMatchesSearch(dateStr, q) {
    if (!q) return false;
    const { incoming, outgoing } = getLettersForDate(dateStr);
    const events = getEventsForDate(dateStr);
    const letters = [...incoming, ...outgoing];
    if (letters.some((l) => textMatches(l.organization, q) || textMatches(l.subject, q))) return true;
    if (events.some((ev) => textMatches(ev.title, q))) return true;
    return false;
  }

  function recomputeSearchMatches(rangeDates) {
    matchedDates = new Set();
    if (!searchQuery) return;
    const q = searchQuery.toLowerCase();
    rangeDates.forEach((dateStr) => {
      if (dateMatchesSearch(dateStr, q)) matchedDates.add(dateStr);
    });
  }

  // --- Заголовок / статистика ------------------------------------------------

  function renderStats() {
    const el = document.getElementById('calendarStats');
    if (!el) return;
    const pending = allPendingCount();
    const deadlinesWeek = deadlinesThisWeekCount();
    const eventsMonth = eventsThisMonthCount();
    el.innerHTML = `
      <span class="cal-stat-badge cal-stat-badge--pending" data-action="cal-stat-pending" title="${esc(t('calendar.stat_pending', 'Писем без ответа'))}">
        <i class="bi bi-envelope-exclamation" aria-hidden="true"></i> ${t('calendar.stat_pending', 'Писем без ответа')}: <strong>${pending}</strong>
      </span>
      <span class="cal-stat-badge cal-stat-badge--deadline${deadlineFilterActive ? ' cal-stat-badge--active' : ''}" data-action="cal-stat-deadlines" role="button" tabindex="0"
        title="${esc(t('calendar.stat_deadlines_title', 'Показать только дни со сроками ответа'))}" aria-pressed="${deadlineFilterActive}">
        <i class="bi bi-alarm" aria-hidden="true"></i> ${t('calendar.stat_deadlines_week', 'Дедлайнов на этой неделе')}: <strong>${deadlinesWeek}</strong>
      </span>
      <span class="cal-stat-badge cal-stat-badge--events" title="${esc(t('calendar.stat_events_month', 'Событий в этом месяце'))}">
        <i class="bi bi-calendar-event" aria-hidden="true"></i> ${t('calendar.stat_events_month', 'Событий в этом месяце')}: <strong>${eventsMonth}</strong>
      </span>`;

    const dBtn = el.querySelector('[data-action="cal-stat-deadlines"]');
    if (dBtn) {
      const toggle = () => {
        deadlineFilterActive = !deadlineFilterActive;
        renderView();
      };
      dBtn.addEventListener('click', toggle);
      dBtn.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
      });
    }
  }

  function updateTitle() {
    const titleEl = document.getElementById('calendarMonthTitle');
    if (!titleEl) return;
    const monthNames = getMonthNames();
    if (view === 'month') {
      titleEl.textContent = `${monthNames[currentMonth]} ${currentYear}`;
    } else if (view === 'week') {
      const we = addDays(weekStart, 6);
      const sameMonth = weekStart.getMonth() === we.getMonth();
      const label = sameMonth
        ? `${weekStart.getDate()}–${we.getDate()} ${monthNames[weekStart.getMonth()]} ${weekStart.getFullYear()}`
        : `${weekStart.getDate()} ${monthNames[weekStart.getMonth()]} – ${we.getDate()} ${monthNames[we.getMonth()]} ${we.getFullYear()}`;
      titleEl.textContent = `${t('calendar.week_of', 'Неделя')}: ${label}`;
    } else {
      const we = addDays(listAnchor, 13);
      titleEl.textContent = `${listAnchor.getDate()} ${monthNames[listAnchor.getMonth()]} – ${we.getDate()} ${monthNames[we.getMonth()]} ${we.getFullYear()}`;
    }
  }

  // --- Drag & drop мероприятий ------------------------------------------------

  function attachEventChipDnD(chipEl, ev) {
    if (!canWrite()) return;
    chipEl.setAttribute('draggable', 'true');
    chipEl.classList.add('cal-event-chip--draggable');
    chipEl.addEventListener('dragstart', (e) => {
      draggingEventId = ev.id;
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', String(ev.id)); } catch (_e) { /* noop */ }
      chipEl.classList.add('cal-event-chip--dragging');
    });
    chipEl.addEventListener('dragend', () => {
      draggingEventId = null;
      chipEl.classList.remove('cal-event-chip--dragging');
    });
  }

  function attachCellDropTarget(cellEl, dateStr) {
    if (!canWrite()) return;
    cellEl.addEventListener('dragover', (e) => {
      if (draggingEventId == null) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      cellEl.classList.add('cal-cell--drop-target');
    });
    cellEl.addEventListener('dragleave', () => {
      cellEl.classList.remove('cal-cell--drop-target');
    });
    cellEl.addEventListener('drop', (e) => {
      e.preventDefault();
      cellEl.classList.remove('cal-cell--drop-target');
      const idRaw = draggingEventId ?? e.dataTransfer.getData('text/plain');
      const id = Number(idRaw);
      draggingEventId = null;
      if (!id) return;
      rescheduleEvent(id, dateStr);
    });
  }

  async function rescheduleEvent(eventId, newDateStr) {
    const store = getStore();
    const ev = (store.events || []).find((e) => Number(e.id) === Number(eventId));
    if (!ev) return;
    const oldDate = ev.event_date;
    if (oldDate?.slice(0, 10) === newDateStr) return;
    if (!canWrite()) {
      window.showError?.(t('calendar.no_write', 'Недостаточно прав'));
      return;
    }

    // Optimistic UI
    ev.event_date = newDateStr;
    renderView();

    try {
      const resp = await fetch(`${window.API_BASE || '/api'}/events.php`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: eventId, event_date: newDateStr }),
      });
      if (!resp.ok) {
        let msg = '';
        try { msg = (await resp.json())?.error || ''; } catch (_e) { /* noop */ }
        throw new Error(msg || 'reschedule failed');
      }
      window.showSuccess?.(t('calendar.reschedule_ok', 'Мероприятие перенесено на {date}').replace('{date}', newDateStr));
      await window.refreshEvents?.();
      if (typeof window.renderEvents === 'function') window.renderEvents();
      renderView();
    } catch (err) {
      console.error(err);
      ev.event_date = oldDate;
      renderView();
      window.showError?.(t('calendar.reschedule_fail', 'Не удалось перенести мероприятие'));
    }
  }

  // --- Ячейка дня (используется в месяце и неделе) ----------------------------

  function buildDayCell(dateStr, day, opts) {
    opts = opts || {};
    const today = new Date();
    const todayStr = toLocalISO(today);
    const monthNames = getMonthNames();
    const { incoming, outgoing } = getLettersForDate(dateStr);
    const events = getEventsForDate(dateStr);
    const deadlines = getDeadlinesForDate(dateStr);
    const total = incoming.length + outgoing.length;
    const isToday = dateStr === todayStr;
    const isPast = dateStr < todayStr;
    const d = new Date(dateStr + 'T00:00:00');
    const dow = (d.getDay() + 6) % 7;

    let cellClass = 'cal-cell';
    if (isToday) cellClass += ' cal-cell--today';
    else if (isPast) cellClass += ' cal-cell--past';
    if (dow >= 5) cellClass += ' cal-cell--weekend';
    if (events.length > 0) cellClass += ' cal-cell--has-event';
    if (deadlines.some((x) => x.overdue)) cellClass += ' cal-cell--overdue';
    if (dateStr === selectedDate) cellClass += ' cal-cell--selected';
    if (deadlineFilterActive) cellClass += deadlines.length > 0 ? ' cal-cell--filter-match' : ' cal-cell--filter-dim';
    if (searchQuery) cellClass += matchedDates.has(dateStr) ? ' cal-cell--match' : '';

    const dots = (total > 0 || deadlines.length > 0 || events.length > 0)
      ? `<div class="cal-dots">
          ${incoming.length > 0 ? `<span class="cal-dot cal-dot--incoming" title="${incoming.length} ${t('calendar.in_short', 'Вх.')}"></span>` : ''}
          ${outgoing.length > 0 ? `<span class="cal-dot cal-dot--outgoing" title="${outgoing.length} ${t('calendar.out_short', 'Исх.')}"></span>` : ''}
          ${deadlines.length > 0 ? `<span class="cal-dot cal-dot--deadline" title="${deadlines.length} ${t('calendar.deadlines_word', 'сроков')}"></span>` : ''}
        </div>`
      : '';

    const badge = total > 0 ? `<span class="cal-badge">${total}</span>` : '';

    const maxChips = opts.maxChips ?? 2;
    const shownEvents = events.slice(0, maxChips);
    const eventChipsHtml = shownEvents.map((ev, i) => {
      const title = String(ev.title || '');
      const short = title.length > 14 ? title.slice(0, 13) + '…' : title;
      return `<div class="cal-event-chip" data-event-id="${esc(String(ev.id ?? ''))}" data-chip-idx="${i}" title="${esc(title)}">${esc(short)}</div>`;
    }).join('') + (events.length > maxChips ? `<div class="cal-event-chip cal-event-chip--more">+${events.length - maxChips}</div>` : '');

    const ariaParts = [`${day} ${monthNames[d.getMonth()]}`];
    if (total > 0) ariaParts.push(`${total} ${t('calendar.letters_word', 'писем')}`);
    if (events.length > 0) ariaParts.push(`${events.length} ${t('calendar.events_word', 'мероприятий')}`);
    if (deadlines.length > 0) ariaParts.push(`${deadlines.length} ${t('calendar.deadlines_word', 'сроков')}`);

    const html = `<div class="${cellClass}" data-date="${dateStr}" data-action="cal-day" role="button" tabindex="0" aria-label="${ariaParts.join(', ')}">
      <div class="cal-day-num" aria-hidden="true">${day}${badge}</div>
      ${dots}
      ${eventChipsHtml}
    </div>`;

    return { html, events: shownEvents };
  }

  function wireDayCell(cellEl) {
    const dateStr = cellEl.dataset.date;
    cellEl.addEventListener('click', (e) => {
      if (e.target.closest('[data-event-id]')) return; // клик по чипу мероприятия не открывает панель
      showDayDetail(dateStr);
    });
    cellEl.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); showDayDetail(dateStr); }
    });
    attachCellDropTarget(cellEl, dateStr);
    cellEl.querySelectorAll('[data-event-id]').forEach((chip) => {
      const idRaw = chip.dataset.eventId;
      if (!idRaw) return;
      const ev = (getStore().events || []).find((e) => String(e.id) === idRaw);
      if (ev) attachEventChipDnD(chip, ev);
    });
  }

  // --- Рендер: месяц -----------------------------------------------------------

  function renderMonth(container) {
    const lastDay = new Date(currentYear, currentMonth + 1, 0);
    let startDow = new Date(currentYear, currentMonth, 1).getDay() - 1;
    if (startDow < 0) startDow = 6;

    let monthTotal = 0;
    const rangeDates = [];
    let html = '<div class="calendar-grid" id="calendarGridInner">';

    getDayNames().forEach((d, i) => {
      html += `<div class="cal-header${i >= 5 ? ' cal-header--weekend' : ''}">${d}</div>`;
    });

    for (let i = 0; i < startDow; i++) {
      html += '<div class="cal-cell cal-cell--empty" aria-hidden="true"></div>';
    }

    for (let day = 1; day <= lastDay.getDate(); day++) {
      const dateStr = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
      rangeDates.push(dateStr);
      const { incoming, outgoing } = getLettersForDate(dateStr);
      const events = getEventsForDate(dateStr);
      const deadlines = getDeadlinesForDate(dateStr);
      monthTotal += incoming.length + outgoing.length + events.length + deadlines.length;
      html += buildDayCell(dateStr, day).html;
    }

    html += '</div>';

    recomputeSearchMatches(rangeDates);
    // Пересобрать с учётом найденных совпадений (класс cal-cell--match)
    if (searchQuery) {
      html = '<div class="calendar-grid" id="calendarGridInner">';
      getDayNames().forEach((d, i) => {
        html += `<div class="cal-header${i >= 5 ? ' cal-header--weekend' : ''}">${d}</div>`;
      });
      for (let i = 0; i < startDow; i++) {
        html += '<div class="cal-cell cal-cell--empty" aria-hidden="true"></div>';
      }
      for (let day = 1; day <= lastDay.getDate(); day++) {
        const dateStr = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        html += buildDayCell(dateStr, day).html;
      }
      html += '</div>';
    }

    html += `<div class="cal-legend text-muted small mt-2" aria-hidden="true">
      <span><span class="cal-dot cal-dot--incoming"></span> ${t('calendar.legend_incoming', 'входящие')}</span>
      <span><span class="cal-dot cal-dot--outgoing"></span> ${t('calendar.legend_outgoing', 'исходящие')}</span>
      <span><span class="cal-dot cal-dot--deadline"></span> ${t('calendar.legend_deadline', 'срок ответа')}</span>
      <span><span class="cal-legend-chip"></span> ${t('calendar.legend_event', 'мероприятие')}</span>
    </div>`;

    if (monthTotal === 0) {
      html += `<div class="text-center text-muted small py-2">${t('calendar.empty_month', 'В этом месяце нет писем и мероприятий.')}</div>`;
    }

    container.innerHTML = html;
    container.querySelectorAll('[data-action="cal-day"]').forEach(wireDayCell);

    if (selectedDate && selectedDate.slice(0, 7) !== `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}`) {
      hideDayDetail();
    }
  }

  // --- Рендер: неделя ------------------------------------------------------------

  function renderWeek(container) {
    const days = [];
    for (let i = 0; i < 7; i++) days.push(addDays(weekStart, i));
    const rangeDates = days.map(toLocalISO);
    recomputeSearchMatches(rangeDates);

    let html = '<div class="calendar-grid calendar-grid--week" id="calendarGridInner">';
    getDayNames().forEach((d, i) => {
      html += `<div class="cal-header${i >= 5 ? ' cal-header--weekend' : ''}">${d}</div>`;
    });
    days.forEach((d) => {
      html += buildDayCell(toLocalISO(d), d.getDate(), { maxChips: 5 }).html;
    });
    html += '</div>';

    html += `<div class="cal-legend text-muted small mt-2" aria-hidden="true">
      <span><span class="cal-dot cal-dot--incoming"></span> ${t('calendar.legend_incoming', 'входящие')}</span>
      <span><span class="cal-dot cal-dot--outgoing"></span> ${t('calendar.legend_outgoing', 'исходящие')}</span>
      <span><span class="cal-dot cal-dot--deadline"></span> ${t('calendar.legend_deadline', 'срок ответа')}</span>
      <span><span class="cal-legend-chip"></span> ${t('calendar.legend_event', 'мероприятие')}</span>
    </div>`;

    container.innerHTML = html;
    container.querySelectorAll('[data-action="cal-day"]').forEach(wireDayCell);
  }

  // --- Рендер: список (agenda, 14 дней) -------------------------------------------

  function renderList(container) {
    const days = [];
    for (let i = 0; i < 14; i++) days.push(addDays(listAnchor, i));
    const rangeDates = days.map(toLocalISO);
    recomputeSearchMatches(rangeDates);
    const monthNames = getMonthNames();
    const today = new Date(); const todayStr = toLocalISO(today);

    let html = '<div class="cal-agenda" id="calendarGridInner">';
    let any = false;

    days.forEach((d) => {
      const dateStr = toLocalISO(d);
      const { incoming, outgoing } = getLettersForDate(dateStr);
      const events = getEventsForDate(dateStr);
      const deadlines = getDeadlinesForDate(dateStr);
      const count = incoming.length + outgoing.length + events.length + deadlines.length;
      if (count === 0) return;
      any = true;
      const isToday = dateStr === todayStr;
      const dow = getDayNames()[(d.getDay() + 6) % 7];
      const isMatch = searchQuery && matchedDates.has(dateStr);

      html += `<div class="cal-agenda-group${isToday ? ' cal-agenda-group--today' : ''}${isMatch ? ' cal-agenda-group--match' : ''}" data-date="${dateStr}">
        <div class="cal-agenda-date">
          <span class="cal-agenda-daynum">${d.getDate()}</span>
          <span class="cal-agenda-daymeta">${dow}, ${monthNames[d.getMonth()]}${isToday ? ` · ${t('calendar.today', 'Сегодня')}` : ''}</span>
        </div>
        <div class="cal-agenda-items">`;

      deadlines.forEach(({ letter, overdue }) => {
        html += `<div class="cal-agenda-item cal-agenda-item--deadline" data-action="cal-open-letter" data-type="incoming" data-id="${esc(String(letter.id ?? ''))}" role="button" tabindex="0">
          <i class="bi bi-alarm" aria-hidden="true"></i>
          <span class="badge ${overdue ? 'bg-danger' : 'bg-warning text-dark'} me-1">${t('calendar.in_short', 'Вх.')}${letter.seq ?? ''}</span>
          <span class="text-truncate">${esc(letter.organization || '—')}</span>
        </div>`;
      });
      events.forEach((ev) => {
        html += `<div class="cal-agenda-item cal-agenda-item--event" data-action="cal-open-event" data-title="${esc(String(ev.title || ''))}" role="button" tabindex="0">
          <i class="bi bi-calendar-event" aria-hidden="true"></i> <span class="fw-semibold">${esc(ev.title)}</span>
          ${ev.location ? `<span class="text-muted small ms-1">${esc(ev.location)}</span>` : ''}
        </div>`;
      });
      incoming.forEach((l) => {
        html += `<div class="cal-agenda-item" data-action="cal-open-letter" data-type="incoming" data-id="${esc(String(l.id ?? ''))}" role="button" tabindex="0">
          <span class="badge badge-incoming me-1">${t('calendar.in_short', 'Вх.')}${l.seq ?? ''}</span>
          <span class="text-truncate">${esc(l.organization || '—')}</span>
        </div>`;
      });
      outgoing.forEach((l) => {
        html += `<div class="cal-agenda-item" data-action="cal-open-letter" data-type="outgoing" data-id="${esc(String(l.id ?? ''))}" role="button" tabindex="0">
          <span class="badge badge-outgoing me-1">${t('calendar.out_short', 'Исх.')}${l.seq ?? ''}</span>
          <span class="text-truncate">${esc(l.organization || l.outgoingNumber || '—')}</span>
        </div>`;
      });

      html += '</div></div>';
    });

    html += '</div>';

    if (!any) {
      html = `<div class="text-center text-muted small py-3">${t('calendar.list_empty', 'Ближайшие 14 дней свободны от писем и мероприятий.')}</div>`;
    }

    container.innerHTML = html;

    const activate = (el, fn) => {
      el.addEventListener('click', fn);
      el.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fn(); }
      });
    };
    container.querySelectorAll('[data-action="cal-open-letter"]').forEach((el) => {
      activate(el, () => {
        if (el.dataset.id && typeof window.openLetterDetailTabs === 'function') {
          window.openLetterDetailTabs(el.dataset.type, el.dataset.id);
        }
      });
    });
    container.querySelectorAll('[data-action="cal-open-event"]').forEach((el) => {
      activate(el, () => openEventInEventsTab(el.dataset.title));
    });
  }

  // --- Общий рендер вида ----------------------------------------------------------

  function renderView() {
    const container = document.getElementById('calendarGrid');
    if (!container) return;
    updateTitle();
    renderStats();

    const grid = document.getElementById('calendarGridInner');
    const gridWrap = container;
    if (view === 'month') renderMonth(gridWrap);
    else if (view === 'week') renderWeek(gridWrap);
    else renderList(gridWrap);

    const gridEl = document.getElementById('calendarGridInner');
    if (gridEl) gridEl.classList.toggle('cal-grid--filter-active', deadlineFilterActive);
  }

  // Обратная совместимость с прежним именем
  function renderCalendar() { renderView(); }

  function hideDayDetail() {
    selectedDate = null;
    const panel = document.getElementById('calendarDayPanel');
    if (panel) { panel.classList.add('d-none'); panel.innerHTML = ''; }
    document.querySelectorAll('.cal-cell--selected').forEach((c) => c.classList.remove('cal-cell--selected'));
  }

  function openEventInEventsTab(title) {
    document.getElementById('tab-events')?.click();
    const search = document.getElementById('searchEvents');
    if (search) {
      search.value = title || '';
      if (typeof window.renderEvents === 'function') window.renderEvents();
    }
  }

  function quickCreateEvent(dateStr) {
    if (!canWrite()) return;
    document.getElementById('tab-events')?.click();
    const collapseEl = document.getElementById('collapseEventForm');
    if (collapseEl && !collapseEl.classList.contains('show')) {
      if (window.bootstrap?.Collapse) {
        window.bootstrap.Collapse.getOrCreateInstance(collapseEl).show();
      } else {
        collapseEl.classList.add('show');
      }
    }
    setTimeout(() => {
      const evDate = document.getElementById('evDate');
      const evTitle = document.getElementById('evTitle');
      if (evDate) evDate.value = dateStr;
      if (evTitle) { evTitle.focus(); }
      collapseEl?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 150);
  }

  function showDayDetail(dateStr) {
    const panel = document.getElementById('calendarDayPanel');
    if (!panel) return;

    selectedDate = dateStr;
    document.querySelectorAll('.cal-cell--selected').forEach((c) => c.classList.remove('cal-cell--selected'));
    document.querySelector(`.cal-cell[data-date="${dateStr}"]`)?.classList.add('cal-cell--selected');

    const { incoming, outgoing } = getLettersForDate(dateStr);
    const events = getEventsForDate(dateStr);
    const deadlines = getDeadlinesForDate(dateStr);
    const d = new Date(dateStr + 'T00:00:00');
    const label = `${d.getDate()} ${getMonthNames()[d.getMonth()]} ${d.getFullYear()}`;

    const makeLetterRows = (letters, type) => letters.map((l) => {
      const prefix = type === 'incoming'
        ? `${t('calendar.in_short', 'Вх.')}${l.seq ?? ''}`
        : `${t('calendar.out_short', 'Исх.')}${l.seq ?? ''}`;
      const cls = type === 'incoming' ? 'badge-incoming' : 'badge-outgoing';
      const members = (l.members || []).map((m) => m.full_name).filter(Boolean).join(', ');
      return `<div class="cal-day-letter mb-2" data-action="cal-open-letter" data-type="${type}" data-id="${esc(String(l.id ?? ''))}" role="button" tabindex="0" title="${t('calendar.open_letter', 'Открыть письмо')}">
        <span class="badge ${cls} me-1">${esc(prefix)}</span>
        <span class="text-truncate">${esc(l.organization || l.outgoingNumber || '—')}</span>
        ${l.subject ? `<div class="text-muted small ms-1">${esc(l.subject)}</div>` : ''}
        ${members ? `<div class="text-muted small ms-1"><i class="bi bi-person" aria-hidden="true"></i> ${esc(members)}</div>` : ''}
      </div>`;
    }).join('');

    let html = '';

    if (deadlines.length > 0) {
      html += `<div class="fw-semibold mb-1 text-danger"><i class="bi bi-alarm" aria-hidden="true"></i> ${t('calendar.deadlines', 'Срок ответа')} (${deadlines.length})</div>`;
      deadlines.forEach(({ letter, overdue }) => {
        html += `<div class="cal-day-letter cal-day-deadline mb-2" data-action="cal-open-letter" data-type="incoming" data-id="${esc(String(letter.id ?? ''))}" role="button" tabindex="0" title="${t('calendar.open_letter', 'Открыть письмо')}">
          <span class="badge ${overdue ? 'bg-danger' : 'bg-warning text-dark'} me-1">${t('calendar.in_short', 'Вх.')}${letter.seq ?? ''}${overdue ? ' · ' + t('calendar.overdue', 'просрочено') : ''}</span>
          <span class="text-truncate">${esc(letter.organization || '—')}</span>
          ${letter.subject ? `<div class="text-muted small ms-1">${esc(letter.subject)}</div>` : ''}
        </div>`;
      });
    }

    if (events.length > 0) {
      if (html) html += '<hr class="my-2"/>';
      html += `<div class="fw-semibold mb-1 text-warning-emphasis"><i class="bi bi-calendar-event" aria-hidden="true"></i> ${t('calendar.events', 'Мероприятия')} (${events.length})</div>`;
      events.forEach((ev) => {
        html += `<div class="cal-day-event mb-2" data-action="cal-open-event" data-title="${esc(String(ev.title || ''))}" role="button" tabindex="0" title="${t('calendar.find_event', 'Найти в списке мероприятий')}">
          <div class="fw-semibold">${esc(ev.title)}</div>`;
        if (ev.location || ev.location_url) {
          html += '<div class="text-muted small">';
          if (ev.location_url) {
            html += `<a href="${esc(ev.location_url)}" target="_blank" rel="noopener" class="text-primary"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i> ${esc(ev.location || '2GIS')}</a>`;
          } else {
            html += `<i class="bi bi-geo-alt" aria-hidden="true"></i> ${esc(ev.location)}`;
          }
          html += '</div>';
        }
        if (ev.description) {
          html += `<div class="text-muted small">${esc(String(ev.description).slice(0, 120))}${ev.description.length > 120 ? '…' : ''}</div>`;
        }
        html += '</div>';
      });
    }

    if (incoming.length > 0) {
      if (html) html += '<hr class="my-2"/>';
      html += `<div class="fw-semibold mb-1 text-primary"><i class="bi bi-inbox" aria-hidden="true"></i> ${t('calendar.incoming', 'Входящие')} (${incoming.length})</div>`;
      html += makeLetterRows(incoming, 'incoming');
    }
    if (outgoing.length > 0) {
      if (html) html += '<hr class="my-2"/>';
      html += `<div class="fw-semibold mb-1 text-success"><i class="bi bi-send" aria-hidden="true"></i> ${t('calendar.outgoing', 'Исходящие')} (${outgoing.length})</div>`;
      html += makeLetterRows(outgoing, 'outgoing');
    }

    if (!html) {
      html = `<p class="text-muted mb-0">${t('calendar.empty_day', 'Писем и мероприятий в этот день нет.')}</p>`;
    }

    const addEventBtn = canWrite()
      ? `<button type="button" class="btn btn-sm btn-outline-success" data-action="cal-quick-add-event" title="${t('calendar.add_event_title', 'Создать мероприятие на эту дату')}">
          <i class="bi bi-plus-circle" aria-hidden="true"></i> ${t('calendar.add_event', '+ Событие')}
        </button>`
      : '';

    panel.innerHTML = `<div class="d-flex align-items-center border-bottom pb-1 mb-2 gap-2">
        <div class="fw-semibold flex-grow-1">${label}</div>
        ${addEventBtn}
        <button type="button" class="btn-close btn-close-sm" data-action="cal-close-panel" aria-label="${t('calendar.close', 'Закрыть')}"></button>
      </div>${html}`;
    panel.classList.remove('d-none');

    panel.querySelector('[data-action="cal-close-panel"]')?.addEventListener('click', hideDayDetail);
    panel.querySelector('[data-action="cal-quick-add-event"]')?.addEventListener('click', () => quickCreateEvent(dateStr));

    const activate = (el, fn) => {
      el.addEventListener('click', fn);
      el.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fn(); }
      });
    };
    panel.querySelectorAll('[data-action="cal-open-letter"]').forEach((el) => {
      activate(el, () => {
        if (el.dataset.id && typeof window.openLetterDetailTabs === 'function') {
          window.openLetterDetailTabs(el.dataset.type, el.dataset.id);
        }
      });
    });
    panel.querySelectorAll('[data-action="cal-open-event"]').forEach((el) => {
      activate(el, () => openEventInEventsTab(el.dataset.title));
    });
  }

  // --- Экспорт .ics с диапазоном текущего периода -----------------------------------

  function updateExportLink() {
    const a = document.getElementById('calExportIcs');
    if (!a) return;
    let from; let to;
    if (view === 'month') {
      from = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-01`;
      to = toLocalISO(new Date(currentYear, currentMonth + 1, 0));
    } else if (view === 'week') {
      from = toLocalISO(weekStart);
      to = toLocalISO(addDays(weekStart, 6));
    } else {
      from = toLocalISO(listAnchor);
      to = toLocalISO(addDays(listAnchor, 13));
    }
    a.href = `/api/calendar_export.php?from=${from}&to=${to}`;
  }

  // --- Печать -----------------------------------------------------------------------

  let printListenersInit = false;
  function initPrintHandling() {
    if (printListenersInit) return;
    printListenersInit = true;
    const enter = () => document.body.classList.add('printing-calendar');
    const leave = () => document.body.classList.remove('printing-calendar');
    window.addEventListener('beforeprint', enter);
    window.addEventListener('afterprint', leave);
    if (window.matchMedia) {
      const mq = window.matchMedia('print');
      const handler = (e) => { if (e.matches) enter(); else leave(); };
      if (mq.addEventListener) mq.addEventListener('change', handler);
      else if (mq.addListener) mq.addListener(handler);
    }
  }

  // --- Переключение видов -------------------------------------------------------------

  function setView(nextView) {
    if (!VIEWS.includes(nextView) || nextView === view) return;
    view = nextView;
    try { window.localStorage?.setItem(VIEW_STORAGE_KEY, view); } catch (_e) { /* noop */ }
    document.querySelectorAll('[data-view-btn]').forEach((btn) => {
      const active = btn.dataset.viewBtn === view;
      btn.classList.toggle('active', active);
      btn.setAttribute('aria-pressed', String(active));
    });
    hideDayDetail();
    renderView();
    updateExportLink();
  }

  function navPrev() {
    if (view === 'month') {
      currentMonth--;
      if (currentMonth < 0) { currentMonth = 11; currentYear--; }
    } else if (view === 'week') {
      weekStart = addDays(weekStart, -7);
    } else {
      listAnchor = addDays(listAnchor, -14);
    }
    renderView();
    updateExportLink();
  }

  function navNext() {
    if (view === 'month') {
      currentMonth++;
      if (currentMonth > 11) { currentMonth = 0; currentYear++; }
    } else if (view === 'week') {
      weekStart = addDays(weekStart, 7);
    } else {
      listAnchor = addDays(listAnchor, 14);
    }
    renderView();
    updateExportLink();
  }

  function navToday() {
    const now = new Date();
    currentYear = now.getFullYear();
    currentMonth = now.getMonth();
    weekStart = mondayOf(now);
    listAnchor = new Date(now); listAnchor.setHours(0, 0, 0, 0);
    renderView();
    updateExportLink();
    if (view !== 'list') showDayDetail(toLocalISO(now));
  }

  // --- Поиск --------------------------------------------------------------------------

  function focusFirstMatch() {
    if (!matchedDates.size) {
      window.showInfo?.(t('calendar.no_matches', 'Совпадений не найдено'));
      return;
    }
    const first = Array.from(matchedDates).sort()[0];
    if (view === 'list') {
      document.querySelector(`.cal-agenda-group[data-date="${first}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
      showDayDetail(first);
      document.querySelector(`.cal-cell[data-date="${first}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  // --- Клавиатурная навигация по сетке --------------------------------------------------

  function isInputLike(el) {
    if (!el) return false;
    const tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
  }

  function focusCellByDate(dateStr) {
    const targetMonth = dateStr.slice(0, 7);
    const thisMonth = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}`;
    if (view === 'month' && targetMonth !== thisMonth) {
      const [y, m] = dateStr.split('-').map(Number);
      currentYear = y; currentMonth = m - 1;
      renderView();
    } else if (view === 'week') {
      const d = new Date(dateStr + 'T00:00:00');
      if (d < weekStart || d > addDays(weekStart, 6)) {
        weekStart = mondayOf(d);
        renderView();
      }
    }
    requestAnimationFrame(() => {
      const cell = document.querySelector(`.cal-cell[data-date="${dateStr}"]`);
      cell?.focus();
    });
  }

  function handleGridKeydown(e) {
    if (isInputLike(document.activeElement)) return;
    if (view === 'list') return; // список: навигация стрелками не применяется к плоскому списку
    const active = document.activeElement;
    if (!active || !active.classList || !active.classList.contains('cal-cell') || !active.dataset.date) return;

    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'Home') {
      const d = new Date(active.dataset.date + 'T00:00:00');
      if (e.key === 'ArrowLeft') d.setDate(d.getDate() - 1);
      else if (e.key === 'ArrowRight') d.setDate(d.getDate() + 1);
      else { const now = new Date(); d.setFullYear(now.getFullYear(), now.getMonth(), now.getDate()); }
      e.preventDefault();
      focusCellByDate(toLocalISO(d));
    }
  }

  // --- Инициализация -------------------------------------------------------------------

  function initCalendar() {
    const pane = document.getElementById('pane-calendar');
    if (!pane) return;

    initPrintHandling();

    pane.innerHTML = `
      <div class="card mb-3 cal-print-root">
        <div class="card-header d-flex align-items-center gap-2 flex-wrap">
          <button type="button" class="btn btn-sm btn-outline-secondary" id="calPrev" aria-label="${t('calendar.prev_month', 'Предыдущий месяц')}"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
          <h5 class="mb-0 flex-grow-1 text-center" id="calendarMonthTitle" aria-live="polite"></h5>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="calNext" aria-label="${t('calendar.next_month', 'Следующий месяц')}"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
          <button type="button" class="btn btn-sm btn-outline-primary ms-2" id="calToday">${t('calendar.today', 'Сегодня')}</button>

          <div class="btn-group btn-group-sm cal-view-switch" role="group" aria-label="calendar view">
            <button type="button" class="btn btn-outline-secondary" data-view-btn="month">${t('calendar.view_month', 'Месяц')}</button>
            <button type="button" class="btn btn-outline-secondary" data-view-btn="week">${t('calendar.view_week', 'Неделя')}</button>
            <button type="button" class="btn btn-outline-secondary" data-view-btn="list">${t('calendar.view_list', 'Список')}</button>
          </div>

          <button type="button" class="btn btn-sm btn-outline-secondary" id="calMineToggle" aria-pressed="false" title="${t('calendar.mine_title', 'Только письма, где я ответственный')}">
            <i class="bi bi-person-check" aria-hidden="true"></i> ${t('calendar.mine', 'Мои')}
          </button>
          <button type="button" class="btn btn-sm btn-warning" id="calEventsToggle" aria-pressed="true" title="${t('calendar.events_toggle', 'Показать/скрыть мероприятия')}">
            <i class="bi bi-calendar-event" aria-hidden="true"></i>
          </button>

          <div class="cal-search-wrap">
            <input type="search" class="form-control form-control-sm" id="calSearch" placeholder="${t('calendar.search_placeholder', 'Найти в календаре...')}" aria-label="${t('calendar.search_placeholder', 'Найти в календаре...')}">
          </div>

          <button type="button" class="btn btn-sm btn-outline-secondary" id="calPrint" title="${t('calendar.print_title', 'Распечатать текущий период')}">
            <i class="bi bi-printer" aria-hidden="true"></i> ${t('calendar.print', 'Печать')}
          </button>
          <a class="btn btn-sm btn-outline-secondary" id="calExportIcs" href="/api/calendar_export.php" download title="${t('calendar.export_ics_title', 'Скачать мероприятия текущего периода в формате iCalendar')}">
            <i class="bi bi-download" aria-hidden="true"></i> ${t('calendar.export_ics', 'Экспорт .ics')}
          </a>
        </div>
        <div class="card-body p-2">
          <div class="cal-stats mb-2" id="calendarStats"></div>
          <div id="calendarGrid"></div>
          <hr class="my-2">
          <div id="calendarDayPanel" class="d-none px-1"></div>
        </div>
      </div>`;

    document.getElementById('calPrev')?.addEventListener('click', navPrev);
    document.getElementById('calNext')?.addEventListener('click', navNext);
    document.getElementById('calToday')?.addEventListener('click', navToday);

    document.querySelectorAll('[data-view-btn]').forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.viewBtn === view);
      btn.setAttribute('aria-pressed', String(btn.dataset.viewBtn === view));
      btn.addEventListener('click', () => setView(btn.dataset.viewBtn));
    });

    document.getElementById('calMineToggle')?.addEventListener('click', (e) => {
      showMine = !showMine;
      const btn = e.currentTarget;
      btn.classList.toggle('btn-outline-secondary', !showMine);
      btn.classList.toggle('btn-primary', showMine);
      btn.setAttribute('aria-pressed', String(showMine));
      renderView();
      if (selectedDate) showDayDetail(selectedDate);
    });
    document.getElementById('calEventsToggle')?.addEventListener('click', (e) => {
      showEvents = !showEvents;
      const btn = e.currentTarget;
      btn.classList.toggle('btn-outline-warning', !showEvents);
      btn.classList.toggle('btn-warning', showEvents);
      btn.setAttribute('aria-pressed', String(showEvents));
      renderView();
      if (selectedDate) showDayDetail(selectedDate);
    });

    const searchInput = document.getElementById('calSearch');
    searchInput?.addEventListener('input', (e) => {
      searchQuery = e.target.value.trim();
      renderView();
    });
    searchInput?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); focusFirstMatch(); }
    });

    document.getElementById('calPrint')?.addEventListener('click', () => window.print());

    const gridWrap = document.getElementById('calendarGrid');
    gridWrap?.addEventListener('keydown', handleGridKeydown);

    renderView();
    updateExportLink();
  }

  function refreshCalendarIfVisible() {
    const pane = document.getElementById('pane-calendar');
    if (!pane) return;
    if (pane.classList.contains('show') || pane.classList.contains('active')) {
      renderView();
      updateExportLink();
      if (selectedDate) showDayDetail(selectedDate);
    }
  }

  document.addEventListener('shown.bs.tab', (e) => {
    if (e.target?.id === 'tab-calendar') { renderView(); updateExportLink(); }
  });

  // Смена языка: перерисовать шапку/сетку/панель с новыми подписями
  window.addEventListener('app:langchange', () => {
    const pane = document.getElementById('pane-calendar');
    if (pane && pane.querySelector('#calendarGrid')) {
      const keepDate = selectedDate;
      initCalendar();
      if (keepDate) showDayDetail(keepDate);
    }
  });

  window.initCalendar = initCalendar;
  window.refreshCalendar = refreshCalendarIfVisible;
})(window);

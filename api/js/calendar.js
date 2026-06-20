/**
 * Календарь дедлайнов: показывает входящие и исходящие письма по датам.
 * Использует store.incoming и store.outgoing из core.js.
 */
(function (window) {
  const MONTH_NAMES = [
    'Январь','Февраль','Март','Апрель','Май','Июнь',
    'Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'
  ];
  const DAY_NAMES = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];

  let currentYear  = new Date().getFullYear();
  let currentMonth = new Date().getMonth(); // 0-based
  let showMine     = false;

  function getStore() {
    return window.store || { incoming: [], outgoing: [] };
  }

  function currentMemberId() {
    return window.sessionUser?.member_id ?? null;
  }

  function getLettersForDate(dateStr) {
    const store   = getStore();
    const memberId = showMine ? currentMemberId() : null;

    const filter = (letters) => letters.filter((l) => {
      if (l.date?.slice(0, 10) !== dateStr) return false;
      if (memberId) {
        return (l.members || []).some((m) => m.member_id === memberId || m.id === memberId);
      }
      return true;
    });

    return {
      incoming: filter(store.incoming || []),
      outgoing: filter(store.outgoing || []),
    };
  }

  function renderCalendar() {
    const container = document.getElementById('calendarGrid');
    if (!container) return;

    const today = new Date();
    const todayStr = today.toISOString().slice(0, 10);

    // Month header
    const titleEl = document.getElementById('calendarMonthTitle');
    if (titleEl) titleEl.textContent = `${MONTH_NAMES[currentMonth]} ${currentYear}`;

    // Build days grid
    const firstDay = new Date(currentYear, currentMonth, 1);
    const lastDay  = new Date(currentYear, currentMonth + 1, 0);
    // Day of week for first day (Mon=0)
    let startDow = firstDay.getDay() - 1;
    if (startDow < 0) startDow = 6;

    let html = '<div class="calendar-grid">';

    // Day name headers
    DAY_NAMES.forEach((d) => {
      html += `<div class="cal-header">${d}</div>`;
    });

    // Empty cells before first day
    for (let i = 0; i < startDow; i++) {
      html += '<div class="cal-cell cal-cell--empty"></div>';
    }

    // Day cells
    for (let day = 1; day <= lastDay.getDate(); day++) {
      const dateStr = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
      const { incoming, outgoing } = getLettersForDate(dateStr);
      const total   = incoming.length + outgoing.length;
      const isToday = dateStr === todayStr;
      const isPast  = new Date(dateStr) < today && !isToday;

      let cellClass = 'cal-cell';
      if (isToday) cellClass += ' cal-cell--today';
      else if (isPast) cellClass += ' cal-cell--past';

      const dots = total > 0
        ? `<div class="cal-dots">
            ${incoming.length > 0 ? `<span class="cal-dot cal-dot--incoming" title="${incoming.length} вх."></span>` : ''}
            ${outgoing.length > 0 ? `<span class="cal-dot cal-dot--outgoing" title="${outgoing.length} исх."></span>` : ''}
          </div>`
        : '';

      const badge = total > 0 ? `<span class="cal-badge">${total}</span>` : '';

      html += `<div class="${cellClass}" data-date="${dateStr}" data-action="cal-day">
        <div class="cal-day-num">${day}${badge}</div>
        ${dots}
      </div>`;
    }

    html += '</div>';
    container.innerHTML = html;

    // Bind clicks
    container.querySelectorAll('[data-action="cal-day"]').forEach((cell) => {
      cell.addEventListener('click', () => showDayDetail(cell.dataset.date));
    });
  }

  function showDayDetail(dateStr) {
    const { incoming, outgoing } = getLettersForDate(dateStr);
    const d     = new Date(dateStr);
    const label = `${d.getDate()} ${MONTH_NAMES[d.getMonth()]} ${d.getFullYear()}`;

    const panel = document.getElementById('calendarDayPanel');
    const title = document.getElementById('calendarDayTitle');
    if (!panel || !title) return;

    title.textContent = label;

    const makeRows = (letters, type) => letters.map((l) => {
      const prefix = type === 'incoming' ? `Вх.${l.seq}` : `Исх.${l.seq}`;
      const cls    = type === 'incoming' ? 'badge-incoming' : 'badge-outgoing';
      const members = (l.members || []).map((m) => m.full_name).join(', ');
      return `<div class="cal-day-letter mb-2">
        <span class="badge ${cls} me-1">${prefix}</span>
        <span class="text-truncate">${escapeHtml(l.organization || '—')}</span>
        ${l.subject ? `<div class="text-muted small ms-1">${escapeHtml(l.subject)}</div>` : ''}
        ${members ? `<div class="text-muted small ms-1"><i class="bi bi-person"></i> ${escapeHtml(members)}</div>` : ''}
      </div>`;
    }).join('');

    let html = '';
    if (incoming.length === 0 && outgoing.length === 0) {
      html = '<p class="text-muted">Писем в этот день нет.</p>';
    } else {
      if (incoming.length > 0) {
        html += `<div class="fw-semibold mb-1 text-primary"><i class="bi bi-inbox"></i> Входящие (${incoming.length})</div>`;
        html += makeRows(incoming, 'incoming');
      }
      if (outgoing.length > 0) {
        html += `<div class="fw-semibold mb-1 text-success mt-2"><i class="bi bi-send"></i> Исходящие (${outgoing.length})</div>`;
        html += makeRows(outgoing, 'outgoing');
      }
    }

    panel.innerHTML = html;
    panel.classList.remove('d-none');
  }

  function initCalendar() {
    const pane = document.getElementById('pane-calendar');
    if (!pane) return;

    pane.innerHTML = `
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2 flex-wrap">
          <button class="btn btn-sm btn-outline-secondary" id="calPrev"><i class="bi bi-chevron-left"></i></button>
          <h5 class="mb-0 flex-grow-1 text-center" id="calendarMonthTitle"></h5>
          <button class="btn btn-sm btn-outline-secondary" id="calNext"><i class="bi bi-chevron-right"></i></button>
          <button class="btn btn-sm btn-outline-primary ms-2" id="calToday">Сегодня</button>
          <button class="btn btn-sm btn-outline-secondary" id="calMineToggle">
            <i class="bi bi-person-check"></i> Мои
          </button>
        </div>
        <div class="card-body p-2">
          <div id="calendarGrid"></div>
          <hr class="my-2">
          <div id="calendarDayPanel" class="d-none px-1"></div>
        </div>
      </div>`;

    document.getElementById('calPrev')?.addEventListener('click', () => {
      currentMonth--;
      if (currentMonth < 0) { currentMonth = 11; currentYear--; }
      renderCalendar();
    });
    document.getElementById('calNext')?.addEventListener('click', () => {
      currentMonth++;
      if (currentMonth > 11) { currentMonth = 0; currentYear++; }
      renderCalendar();
    });
    document.getElementById('calToday')?.addEventListener('click', () => {
      currentYear  = new Date().getFullYear();
      currentMonth = new Date().getMonth();
      renderCalendar();
    });
    document.getElementById('calMineToggle')?.addEventListener('click', (e) => {
      showMine = !showMine;
      e.currentTarget.classList.toggle('btn-outline-secondary', !showMine);
      e.currentTarget.classList.toggle('btn-primary', showMine);
      renderCalendar();
    });

    renderCalendar();
  }

  // Re-render calendar when letters are refreshed
  function refreshCalendarIfVisible() {
    const pane = document.getElementById('pane-calendar');
    if (pane && !pane.classList.contains('d-none') && pane.classList.contains('show')) {
      renderCalendar();
    }
  }

  // Listen to tab show events
  document.addEventListener('shown.bs.tab', (e) => {
    if (e.target?.id === 'tab-calendar') {
      renderCalendar();
    }
  });

  window.initCalendar      = initCalendar;
  window.refreshCalendar   = refreshCalendarIfVisible;
})(window);

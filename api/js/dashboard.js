/**
 * Дашборд: KPI, графики Chart.js, уведомления о сроках.
 */
(function (window) {
  const API_BASE = window.API_BASE;
  const store = window.store;

  function t(key, fb) {
    return window.AppI18n?.t(key, fb) ?? fb;
  }

  function fmt(key, vars) {
    return window.AppI18n?.fmt?.(key, vars) ?? t(key);
  }

function monthKey(iso) {
  const d = new Date(iso);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

function unique(values) {
  return Array.from(new Set(values));
}

const MONTHS_RU = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];

function formatMonthLabel(key) {
  if (window.AppI18n?.formatMonthLabel) return window.AppI18n.formatMonthLabel(key);
  if (!key) return '';
  const [y, mm] = String(key).split('-');
  const m = Number(mm);
  if (!y || !Number.isFinite(m) || m < 1 || m > 12) return String(key);
  return `${MONTHS_RU[m - 1]} ${y}`;
}

function formatPeriodLabel(key, period) {
  if (window.AppI18n?.formatPeriodLabel) return window.AppI18n.formatPeriodLabel(key, period);
  if (!key) return '';
  if (period === 'month') return formatMonthLabel(key);
  if (period === 'quarter') {
    const [y, qPart] = String(key).split('-Q');
    const q = Number(qPart);
    return fmt('period.quarter_fmt', { q, y });
  }
  if (period === 'year') return fmt('period.year_fmt', { y: key });
  return String(key);
}

function getMonthKeys(items) {
  return unique((items || []).map(x => monthKey(x.date)))
    .filter(Boolean)
    .sort((a, b) => a.localeCompare(b));
}

function periodKeyFromDate(d, period) {
  const dt = new Date(d);
  const y = dt.getFullYear();
  const m = dt.getMonth(); // 0-11

  if (period === 'month') {
    return `${y}-${String(m + 1).padStart(2, '0')}`;
  }
  if (period === 'quarter') {
    const q = Math.floor(m / 3) + 1;
    return `${y}-Q${q}`;
  }
  // year
  return String(y);
}

function periodSortValue(key, period) {
  if (!key) return 0;
  if (period === 'month') {
    const [y, m] = String(key).split('-');
    return Number(y) * 100 + Number(m);
  }
  if (period === 'quarter') {
    const [y, qPart] = String(key).split('-Q');
    const q = Number(qPart);
    return Number(y) * 10 + q;
  }
  return Number(key);
}

function groupByPeriod(items, period, dateSelector) {
  const map = new Map();
  (items || []).forEach((it) => {
    const d = dateSelector(it);
    const key = periodKeyFromDate(d, period);
    if (!key) return;
    map.set(key, (map.get(key) || 0) + 1);
  });

  return Array.from(map.entries())
    .sort(([a], [b]) => periodSortValue(a, period) - periodSortValue(b, period));
}

function mergePeriodSeries(inc, out, period) {
  const map = new Map();
  (inc || []).forEach(([k, v]) => map.set(k, { in: v, out: 0 }));
  (out || []).forEach(([k, v]) => {
    const row = map.get(k) || { in: 0, out: 0 };
    row.out = v;
    map.set(k, row);
  });

  return Array.from(map.entries())
    .sort(([a], [b]) => periodSortValue(a, period) - periodSortValue(b, period));
}
function animateCounter(el, to) {
  const target = Number(to);
  if (!Number.isFinite(target)) return;
  const startRaw = Number(el.textContent || 0);
  const start = Number.isFinite(startRaw) ? startRaw : 0;
  const diff = target - start;
  const duration = 650;
  const t0 = performance.now();

  function tick(now) {
    const p = Math.min(1, (now - t0) / duration);
    const val = start + diff * (p < 1 ? (1 - Math.pow(1 - p, 3)) : 1);
    el.textContent = String(Math.round(val));
    if (p < 1) requestAnimationFrame(tick);
  }

  requestAnimationFrame(tick);
}
const kpiIncoming = document.getElementById("kpiIncoming");
const kpiOutgoing = document.getElementById("kpiOutgoing");
const kpiClosed = document.getElementById("kpiClosed");
const kpiAvgDays = document.getElementById("kpiAvgDays");
const kpiIncomingMay = document.getElementById("kpiIncomingMay");
const kpiIncomingPeriodLabel = document.getElementById("kpiIncomingPeriodLabel");
const kpiWithScans = document.getElementById("kpiWithScans");
const kpiTotalScans = document.getElementById("kpiTotalScans");
const kpiDbSize = document.getElementById("kpiDbSize");

const dashboardPeriodSelect = document.getElementById('dashboardPeriodSelect');
const listKpiRecipientsIncoming = document.getElementById('listKpiRecipientsIncoming');
const listKpiRecipientsOutgoing = document.getElementById('listKpiRecipientsOutgoing');

// Chart.js графики
let chartLettersTrend, chartTopOrgs, chartPieGov, chartPieOS;
const lastKpiValues = {};
async function loadKpi() {
  try {
    const response = await fetch(`${API_BASE}/kpi.php`);
    if (!response.ok) throw new Error('Ошибка загрузки KPI');
    return await response.json();
  } catch (e) {
    console.error('Ошибка KPI:', e);
    return { members: [], commissions: [], summary: { chair: null, chairs_commissions: [], others: [] } };
  }
}

async function renderKpiExtra() {
  const kpi = await loadKpi();
  const eventsMap = new Map((kpi.events_participation || []).map(e => [e.id, Number(e.events_attended || 0)]));
  // Таблица членов (топ 10)
  const tbodyMembers = document.querySelector('#tableKpiMembers tbody');
  if (tbodyMembers) {
    const rows = [...kpi.members].sort((a, b) => (b.outgoing_count - a.outgoing_count) || (b.incoming_count - a.incoming_count));
    tbodyMembers.innerHTML = rows.map(m => `
      <tr>
        <td>${escapeHtml(m.full_name || '')}</td>
        <td>${escapeHtml(m.commission_name || '')}</td>
        <td class="text-end">${Number(m.outgoing_count || 0)}</td>
        <td class="text-end">${Number(m.incoming_count || 0)}</td>
        <td class="text-end">${Number(m.lead_count || 0)}</td>
        <td class="text-end">${eventsMap.get(m.id) || 0}</td>
      </tr>
    `).join('');
  }

  // Таблица комиссий
  const tbodyComms = document.querySelector('#tableKpiCommissions tbody');
  if (tbodyComms) {
    const ordered = [...kpi.commissions].sort((a, b) => (b.outgoing_count - a.outgoing_count) || (b.incoming_count - a.incoming_count));
    tbodyComms.innerHTML = ordered.map(c => `
      <tr>
        <td><span class="badge" style="background:${escapeHtml(c.color || '#1D4ED8')}">${escapeHtml(c.name || '')}</span></td>
        <td class="text-end">${Number(c.outgoing_count || 0)}</td>
        <td class="text-end">${Number(c.incoming_count || 0)}</td>
        <td class="text-end">${Number(c.events_count || 0)}</td>
      </tr>
    `).join('');
  }

  // Сводка ответственных
  const summary = document.getElementById('summaryResponsible');
  if (summary) {
    const lines = [];
    if (kpi.summary?.chair) {
      lines.push(`<div><strong>${t('dash.chairman', 'Председатель ОС')}:</strong> ${escapeHtml(kpi.summary.chair.full_name)}${kpi.summary.chair.commission_name ? ' — ' + escapeHtml(kpi.summary.chair.commission_name) : ''}</div>`);
    }
    (kpi.summary?.chairs_commissions || []).slice(0, 6).forEach((p, idx) => {
      lines.push(`<div>${idx + 1}. ${escapeHtml(p.full_name)} — ${escapeHtml(p.commission_name || '')}</div>`);
    });
    (kpi.summary?.others || []).slice(0, 7).forEach((o) => {
      lines.push(`<div>• ${escapeHtml(o.full_name)} — ${escapeHtml(o.commission_name || '')}</div>`);
    });
    summary.innerHTML = lines.join('');
  }

  if (listKpiRecipientsIncoming) {
    const list = (kpi.recipients?.incoming || []).slice(0, 10);
    listKpiRecipientsIncoming.innerHTML = list.length
      ? list.map(item => `<li class="list-group-item d-flex justify-content-between align-items-center">
            <span>${escapeHtml(item.recipient || '')}</span>
            <span class="badge bg-primary rounded-pill">${Number(item.total || 0)}</span>
        </li>`).join('')
      : `<li class="list-group-item text-muted">${t('dash.no_data', 'Нет данных')}</li>`;
  }

  if (listKpiRecipientsOutgoing) {
    const list = (kpi.recipients?.outgoing || []).slice(0, 10);
    listKpiRecipientsOutgoing.innerHTML = list.length
      ? list.map(item => `<li class="list-group-item d-flex justify-content-between align-items-center">
            <span>${escapeHtml(item.recipient || '')}</span>
            <span class="badge bg-success rounded-pill">${Number(item.total || 0)}</span>
        </li>`).join('')
      : `<li class="list-group-item text-muted">${t('dash.no_data', 'Нет данных')}</li>`;
  }

  if (typeof window.refreshDashboardEnhanced !== 'function') {
    await renderCommissionsHighlights(kpi);
  }
}
function getPendingLettersSummary() {
  return window.AppUtils?.getPendingLettersSummary?.(store.incoming)
    || { pending: 0, overdue: 0, warning: 0 };
}

function updateNotifyBadge() {
  const badge = document.getElementById('notifyBadge');
  const { overdue, warning, pending } = getPendingLettersSummary();
  const alertCount = overdue + warning;
  if (badge) {
    if (alertCount > 0) {
      badge.textContent = String(alertCount);
      badge.classList.remove('d-none');
      badge.classList.toggle('notify-badge--warning', overdue === 0 && warning > 0);
    } else {
      badge.classList.add('d-none');
      badge.classList.remove('notify-badge--warning');
    }
  }

  const alerts = document.getElementById('dashboardAlerts');
  if (!alerts) return;
  if (pending === 0) {
    alerts.classList.add('d-none');
    alerts.innerHTML = '';
    return;
  }
  alerts.classList.remove('d-none');
  alerts.innerHTML = `
    <span><strong>${pending}</strong> ${t('dash.letters_no_reply', 'писем без ответа')}</span>
    ${overdue ? `<span class="badge badge-due-danger">${overdue} ${t('dash.overdue_badge', 'просрочено')}</span>` : ''}
    ${warning ? `<span class="badge badge-due">${warning} ${t('dash.due_soon_badge', 'скоро срок')}</span>` : ''}
    <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="dashViewPending">${t('dash.open_journal', 'Открыть журнал')}</button>
  `;
  document.getElementById('dashViewPending')?.addEventListener('click', () => {
    window.goToIncomingStatus('pending');
  });
}
async function renderCommissionsHighlights(kpi) {
  const grid = document.getElementById('commissionsKPI');
  const cardWrapper = document.getElementById('commissionsCard');
  if (!grid) return;
  let collection = Array.isArray(kpi?.commissions) ? [...kpi.commissions] : [];

  if (!collection.length && Array.isArray(kpi?.members)) {
    const map = new Map();
    kpi.members.forEach(member => {
      const key = member.commission_name || t('dash.no_commission', 'Без комиссии');
      if (!map.has(key)) {
        map.set(key, {
          name: key,
          outgoing_count: 0,
          incoming_count: 0,
          members_count: 0,
          lead_name: null,
          color: member.commission_color || '#4f46e5'
        });
      }
      const entry = map.get(key);
      entry.outgoing_count += Number(member.outgoing_count || 0);
      entry.incoming_count += Number(member.incoming_count || 0);
      entry.members_count += 1;
      if (!entry.lead_name && member.full_name) {
        entry.lead_name = member.full_name;
      }
      if ((!entry.color || entry.color === '#4f46e5') && member.commission_color) {
        entry.color = member.commission_color;
      }
    });
    collection = Array.from(map.values());
  }

  if (!collection.length) {
    try {
      const resp = await fetch(`${API_BASE}/commissions.php`);
      if (resp.ok) {
        const data = await resp.json();
        collection = (window.AppUtils?.asList(data) || []).map(c => ({
          name: c.name,
          outgoing_count: Number(c.outgoing_count || 0),
          incoming_count: Number(c.incoming_count || 0),
          members_count: Number(c.members_count || 0),
          lead_name: c.lead_name || null,
          color: c.color || '#4f46e5'
        }));
      }
    } catch (err) {
      console.warn('Не удалось загрузить комиссии', err);
    }
  }

  if (!collection.length) {
    grid.innerHTML = `<div class="text-center text-muted py-4">${t('dash.no_commission_data', 'Нет данных по комиссиям')}</div>`;
    if (cardWrapper) cardWrapper.classList.remove('d-none');
    return;
  }

  if (cardWrapper) cardWrapper.classList.remove('d-none');

  const ordered = collection.sort((a, b) => {
    const totalA = Number(a.outgoing_count || 0) + Number(a.incoming_count || 0);
    const totalB = Number(b.outgoing_count || 0) + Number(b.incoming_count || 0);
    return totalB - totalA;
  }).slice(0, 6);

  grid.innerHTML = ordered.map((comm) => {
    const outgoing = Number(comm.outgoing_count || 0);
    const incoming = Number(comm.incoming_count || 0);
    const members = Number(comm.members_count || comm.member_count || 0);
    const total = outgoing + incoming;
    const inPct = total ? Math.round((incoming / total) * 100) : 0;
    const outPct = total ? 100 - inPct : 0;
    const leader = comm.lead_name ? escapeHtml(comm.lead_name) : '—';
    return `
      <div class="dash-commission">
        <div class="dash-commission__head">
          <div>
            <div class="dash-commission__name">${escapeHtml(comm.name || t('dash.enh.commission', 'Комиссия'))}</div>
            <div class="dash-commission__meta">${members ? `${members} ${t('dash.members_count', 'членов')}` : t('dash.no_data', 'Без данных')} · ${leader}</div>
          </div>
          <span class="badge commission-count">${total} ${t('dash.letters_count', 'писем')}</span>
        </div>
        <div class="dash-commission__bar">
          ${total ? `<span class="dash-commission__seg dash-commission__seg--in" style="width:${inPct}%"></span><span class="dash-commission__seg dash-commission__seg--out" style="width:${outPct}%"></span>` : ''}
        </div>
        <div class="dash-commission__counts">
          <span>${t('dash.incoming_count', 'Входящих')}: ${incoming}</span>
          <span>${t('dash.outgoing_count', 'Исходящих')}: ${outgoing}</span>
        </div>
      </div>
    `;
  }).join('');
}
async function renderKPIs() {
  const period = dashboardPeriodSelect?.value || 'month';

  // 1) Server-side KPI values (no hardcoded client duplication).
  let stats = null;
  try {
    const resp = await fetch(`${API_BASE}/statistics.php`, { method: 'GET' });
    if (resp.ok) stats = await resp.json();
  } catch (e) {
    console.warn('statistics.php failed', e);
  }

  // 2) Fallbacks from store (keeps UI usable if statistics endpoint fails).
  const totalInc = store.incoming.length;
  const totalOut = store.outgoing.length;
  const closed = store.incoming.filter(i => i.linkedOutgoingId).length;

  const days = store.outgoing.map(o => {
    const inc = store.incoming.find(i => i.id === o.incomingRefId);
    if (!inc) return null;
    const diff = (new Date(o.date) - new Date(inc.date)) / (1000 * 3600 * 24);
    return diff >= 0 ? diff : null;
  }).filter(x => x !== null);
  const avgFallback = days.length ? (days.reduce((a, b) => a + b, 0) / days.length) : null;

  const pendingFallback = store.incoming.filter((i) => window.AppUtils?.isLetterPending?.(i)).length;
  const overdueFallback = store.incoming.filter((i) => window.AppUtils?.isLetterOverdue?.(i)).length;

  const withScansFallback = [...store.incoming, ...store.outgoing].filter(item => (item.scansCount || 0) > 0).length;
  const totalScansFallback = [...store.incoming, ...store.outgoing].reduce((sum, item) => sum + (item.scansCount || 0), 0);

  const dbSize = new Blob([JSON.stringify(store)]).size;
  const dbSizeMB = (dbSize / (1024 * 1024)).toFixed(2);
  const dbSizeKB = (dbSize / 1024).toFixed(1);

  // Values from statistics.php when available.
  const kpiIncomingVal = stats ? stats.total_incoming : totalInc;
  const kpiOutgoingVal = stats ? stats.total_outgoing : totalOut;
  const kpiClosedVal = stats ? stats.closed_letters : closed;
  const kpiAvgDaysVal = stats ? stats.avg_response_days : avgFallback;
  const pendingVal = stats ? stats.pending_letters : pendingFallback;
  const overdueVal = stats ? stats.overdue_letters : overdueFallback;
  const kpiWithScansVal = stats ? stats.letters_with_scans : withScansFallback;
  const kpiTotalScansVal = stats ? stats.total_scans : totalScansFallback;
  const membersCountVal = stats ? stats.members_count : (window.membersCatalog || []).length;
  const commissionsCountVal = stats ? stats.commissions_count : (window.commissionsCatalog || []).length;

  updateKpiValue(kpiIncoming, 'kpiIncoming', kpiIncomingVal);
  updateKpiValue(kpiOutgoing, 'kpiOutgoing', kpiOutgoingVal);
  updateKpiValue(kpiClosed, 'kpiClosed', kpiClosedVal);

  if (kpiWithScans) updateKpiValue(kpiWithScans, 'kpiWithScans', kpiWithScansVal);
  if (kpiTotalScans) updateKpiValue(kpiTotalScans, 'kpiTotalScans', kpiTotalScansVal);

  if (kpiAvgDays) {
    if (kpiAvgDaysVal === null || kpiAvgDaysVal === undefined || kpiAvgDaysVal === '' || !Number.isFinite(Number(kpiAvgDaysVal))) {
      updateKpiValue(kpiAvgDays, 'kpiAvgDays', '—', true);
    } else {
      updateKpiValue(kpiAvgDays, 'kpiAvgDays', Number(kpiAvgDaysVal), false);
    }
  }

  const closedPct = kpiIncomingVal ? Math.round((kpiClosedVal / kpiIncomingVal) * 100) : 0;
  const progressEl = document.getElementById('kpiClosedProgress');
  if (progressEl) progressEl.style.width = `${closedPct}%`;

  const pendingEl = document.getElementById('kpiPending');
  const overdueEl = document.getElementById('kpiOverdue');
  if (pendingEl) updateKpiValue(pendingEl, 'kpiPending', pendingVal);
  if (overdueEl) updateKpiValue(overdueEl, 'kpiOverdue', overdueVal);

  const membersEl = document.getElementById('kpiMembersCount');
  const commissionsEl = document.getElementById('kpiCommissionsCount');
  if (membersEl) membersEl.textContent = String(membersCountVal);
  if (commissionsEl) commissionsEl.textContent = String(commissionsCountVal);

  if (kpiDbSize) {
    if (dbSize > 1024 * 1024) kpiDbSize.textContent = `${dbSizeMB} МБ`;
    else kpiDbSize.textContent = `${dbSizeKB} КБ`;
  }

  // Latest period insight (replaces hardcoded "May 2024").
  if (kpiIncomingMay) {
    const incomingDates = store.incoming.map(i => new Date(i.date)).filter(d => !isNaN(d.valueOf()));
    const latestDate = incomingDates.length ? incomingDates.reduce((a, b) => (a > b ? a : b)) : null;

    const latestKey = latestDate ? periodKeyFromDate(latestDate, period) : null;
    const latestLabel = latestKey ? formatPeriodLabel(latestKey, period) : '';
    if (kpiIncomingPeriodLabel) kpiIncomingPeriodLabel.textContent = latestKey ? fmt('dash.for_month', { label: latestLabel }) : t('dash.for_period', 'За период');

    const latestCount = latestKey
      ? store.incoming.filter(i => periodKeyFromDate(i.date, period) === latestKey).length
      : 0;
    updateKpiValue(kpiIncomingMay, 'kpiIncomingMay', latestCount);
  }

  const totalLetters = kpiIncomingVal + kpiOutgoingVal;
  const setNote = (id, text) => {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  };
  setNote('kpiOutgoingNote', kpiOutgoingVal ? fmt('dash.outgoing_pct', { pct: Math.round((kpiOutgoingVal / Math.max(kpiIncomingVal, 1)) * 100) }) : t('dash.os_replies', 'ответы ОС'));
  setNote('kpiClosedNote', kpiIncomingVal ? fmt('dash.outgoing_pct', { pct: closedPct }) : '—');
  setNote('kpiWithScansNote', totalLetters ? fmt('dash.scans_pct', { pct: Math.round((kpiWithScansVal / totalLetters) * 100) }) : '—');

  // Click on "Без ответа"/"Просрочено" -> open Incoming with filter.
  const pendingItem = pendingEl?.closest('li');
  const overdueItem = overdueEl?.closest('li');
  if (pendingItem) {
    pendingItem.classList.add('dash-insight--clickable');
    pendingItem.style.cursor = 'pointer';
    pendingItem.onclick = () => window.goToIncomingStatus('pending');
  }
  if (overdueItem) {
    overdueItem.classList.add('dash-insight--clickable');
    overdueItem.style.cursor = 'pointer';
    overdueItem.onclick = () => window.goToIncomingStatus('overdue');
  }

  updateNotifyBadge();

  if (typeof window.refreshDashboardEnhanced === 'function') {
    window.refreshDashboardEnhanced();
  }
}

function updateKpiValue(element, key, value, nonNumeric = false) {
  if (!element) return;
  const numericValue = nonNumeric ? value : Number(value);
  if (!nonNumeric && !Number.isFinite(numericValue)) {
    element.textContent = '—';
    lastKpiValues[key] = null;
    return;
  }
  if (window.enableKpiCounterAnimation && !nonNumeric) {
    animateCounter(element, numericValue);
  } else {
    element.textContent = String(value);
  }
  const previous = lastKpiValues[key];
  if (previous !== undefined && previous !== numericValue) {
    element.classList.remove('kpi-pulse');
    void element.offsetWidth;
    element.classList.add('kpi-pulse');
  }
  lastKpiValues[key] = numericValue;
}

function topOrganizations() {
  const map = new Map();
  store.incoming.forEach((i) => {
    const key = i.organization.trim() || t('dash.org_unknown', '(не указано)');
    map.set(key, (map.get(key) || 0) + 1);
  });
  return Array.from(map.entries()).sort((a, b) => b[1] - a[1]).slice(0, 10);
}

function renderCharts() {
  const period = dashboardPeriodSelect?.value || 'month';
  const inc = groupByPeriod(store.incoming, period, (x) => x.date);
  const out = groupByPeriod(store.outgoing, period, (x) => x.date);
  const merged = mergePeriodSeries(inc, out, period);
  const orgs = topOrganizations();

  const trendLabels = merged.map(([k]) => formatPeriodLabel(k, period));
  const trendIn = merged.map(([, v]) => v.in);
  const trendOut = merged.map(([, v]) => v.out);

  const orgLabels = orgs.map(([name]) => name);
  const orgData = orgs.map(([, v]) => v);

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: 'rgba(15, 27, 51, 0.92)',
        padding: 12,
        cornerRadius: 8
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        grid: { borderDash: [2, 4], color: '#E5E7EB' },
        ticks: { color: '#6B7280', precision: 0 }
      },
      x: {
        grid: { display: false },
        ticks: { color: '#6B7280', maxRotation: 0 }
      }
    }
  };

  const ctxTrend = document.getElementById('chartLettersTrend');
  if (chartLettersTrend) chartLettersTrend.destroy();
  if (ctxTrend) {
    chartLettersTrend = new Chart(ctxTrend, {
      type: 'line',
      data: {
        labels: trendLabels,
        datasets: [
          {
            label: t('chart.incoming', 'Входящие'),
            data: trendIn,
            borderColor: '#1D4ED8',
            backgroundColor: 'rgba(29, 78, 216, 0.06)',
            pointBackgroundColor: '#fff',
            pointBorderColor: '#1D4ED8',
            pointRadius: 3,
            fill: true,
            tension: 0.35
          },
          {
            label: t('chart.outgoing', 'Исходящие'),
            data: trendOut,
            borderColor: '#D9A521',
            backgroundColor: 'transparent',
            pointBackgroundColor: '#fff',
            pointBorderColor: '#D9A521',
            pointRadius: 3,
            borderDash: [],
            tension: 0.35
          }
        ]
      },
      options: chartOptions
    });
  }

  // Top organizations
  const ctx3 = document.getElementById("chartTopOrgs");
  if (chartTopOrgs) chartTopOrgs.destroy();
  if (ctx3) {
    chartTopOrgs = new Chart(ctx3, {
      type: "bar",
      data: {
        labels: orgLabels,
        datasets: [{ 
            label: t('dash.chart_letters', 'Письма'), 
            data: orgData, 
            backgroundColor: "#1B2A4A",
            borderRadius: 4,
            hoverBackgroundColor: "#0F1B33"
        }]
      },
      options: { 
        indexAxis: 'y',
        maintainAspectRatio: false,
        responsive: true,
        animation: {
            duration: 700,
            easing: 'easeOutQuad'
        },
        plugins: { 
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(17, 24, 39, 0.9)',
                padding: 12,
                cornerRadius: 8
            }
        }, 
        scales: { 
            x: { 
                beginAtZero: true,
                grid: { borderDash: [2, 4], color: "#e2e8f0" },
                ticks: { color: "#64748b" }
            },
            y: {
                grid: { display: false },
                ticks: { color: "#64748b", autoSkip: false }
            }
        } 
      }
    });
    window.chartTopOrgs = chartTopOrgs;
  }

  // Pie (donut) charts
  const incomingKK = store.incoming.filter(i => (i.category || 'KK') === 'KK');
  const outgoingToKK = store.outgoing.filter(o => {
    const incRef = store.incoming.find(i => i.id === o.incomingRefId);
    return (incRef?.category || 'KK') === 'KK';
  });

  const pieGovData = [incomingKK.length, outgoingToKK.length];
  const ctxPieGov = document.getElementById("chartPieGov");
  if (chartPieGov) chartPieGov.destroy();
  if (ctxPieGov) {
    chartPieGov = new Chart(ctxPieGov, {
      type: 'doughnut',
      data: {
        labels: [t('dash.chart_incoming_gov', 'Входящие письма от гос. органов (ҚК)'), t('dash.chart_os_replies', 'Ответы от ОС')],
        datasets: [{
          data: pieGovData,
          backgroundColor: ["#ffd966", "#10b981"],
          borderWidth: 0,
          hoverOffset: 10
        }]
      },
      options: { 
        cutout: '65%', 
        animation: {
            animateScale: true,
            animateRotate: true,
            duration: 2000
        },
        plugins: { legend: { display: false } } 
      }
    });
  }

  const totalOutgoing = store.outgoing.length;
  const totalIncomingGov = incomingKK.length; // трактуем как ответы от гос. органов
  const pieOSData = [totalOutgoing, totalIncomingGov];
  const ctxPieOS = document.getElementById("chartPieOS");
  if (chartPieOS) chartPieOS.destroy();
  if (ctxPieOS) {
    chartPieOS = new Chart(ctxPieOS, {
      type: 'doughnut',
      data: {
        labels: [t('dash.chart_outgoing_os', 'Исходящие письма от ОС'), t('dash.chart_gov_replies', 'Ответы от гос органов (ҚК)')],
        datasets: [{
          data: pieOSData,
          backgroundColor: ["#5eead4", "#003c97"],
          borderWidth: 0,
          hoverOffset: 10
        }]
      },
      options: { 
        cutout: '65%', 
        animation: {
            animateScale: true,
            animateRotate: true,
            duration: 2000
        },
        plugins: { legend: { display: false } } 
      }
    });
  }
}


  if (dashboardPeriodSelect) {
    dashboardPeriodSelect.addEventListener('change', () => renderCharts());
  }

  window.addEventListener('app:langchange', () => {
    if (typeof window.renderKPIs === 'function') window.renderKPIs();
    if (typeof window.renderCharts === 'function') window.renderCharts();
    if (typeof window.renderKpiExtra === 'function') window.renderKpiExtra();
  });

  window.renderKPIs = renderKPIs;
  window.renderCharts = renderCharts;
  window.renderKpiExtra = renderKpiExtra;
  window.updateNotifyBadge = updateNotifyBadge;
  window.loadKpi = loadKpi;
  Object.defineProperty(window, 'chartTopOrgs', {
    get() { return chartTopOrgs; },
    set(v) { chartTopOrgs = v; },
    configurable: true
  });
})(window);

(function () {
  const API = '/api';
  let advancedStatsCache = null;

  async function loadAdvancedStats() {
    try {
      const resp = await fetch(`${API}/advanced_stats.php`);
      if (!resp.ok) return null;
      advancedStatsCache = await resp.json();
      return advancedStatsCache;
    } catch (e) {
      console.warn('advanced_stats.php failed', e);
      return null;
    }
  }

  function renderCommissionPerformance(stats) {
    const container = document.getElementById('commissionsKPI');
    if (!container || !stats?.commission_performance?.length) {
      if (container && !stats?.commission_performance?.length) {
        container.innerHTML = '<div class="text-muted small">Нет данных по комиссиям</div>';
      }
      return;
    }

    const maxLoad = Math.max(...stats.commission_performance.map((c) => {
      const total = (Number(c.incoming_count) || 0) + (Number(c.outgoing_count) || 0);
      return total || Number(c.total_letters) || 0;
    }), 1);
    container.innerHTML = stats.commission_performance.map((c) => {
      const totalLetters = (Number(c.incoming_count) || 0) + (Number(c.outgoing_count) || 0)
        || Number(c.total_letters) || 0;
      const pct = Math.round((totalLetters / maxLoad) * 100);
      const color = escapeHtml(c.color || '#1D4ED8');
      return `
        <div class="commission-kpi-row mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-semibold">${escapeHtml(c.name || 'Комиссия')}</span>
            <span class="small text-muted">${totalLetters} писем · ${c.members_count || 0} чел.</span>
          </div>
          <div class="progress" style="height:8px;">
            <div class="progress-bar" style="width:${pct}%;background:${color};"></div>
          </div>
        </div>`;
    }).join('');
  }

  function enhanceDashboardInsights(stats) {
    if (!stats) return;

    const avgEl = document.getElementById('kpiAvgDays');
    if (avgEl && stats.avg_response_days !== undefined) {
      avgEl.textContent = String(stats.avg_response_days);
    }

    const overdueEl = document.getElementById('kpiOverdue');
    if (overdueEl && stats.overdue_letters !== undefined) {
      overdueEl.textContent = String(stats.overdue_letters);
    }

    const scansNote = document.getElementById('kpiWithScansNote');
    if (scansNote && stats.scans_percentage !== undefined) {
      scansNote.textContent = `${stats.scans_percentage}% писем со сканами`;
      scansNote.classList.remove('d-none');
    } else if (stats.scans_percentage !== undefined) {
      const withScansEl = document.getElementById('kpiWithScans');
      if (withScansEl?.parentElement) {
        let note = withScansEl.parentElement.querySelector('.dash-insight__note');
        if (!note) {
          note = document.createElement('div');
          note.className = 'dash-insight__note small text-muted';
          withScansEl.parentElement.appendChild(note);
        }
        note.textContent = `${stats.scans_percentage}% со сканами`;
      }
    }

    const membersNote = document.getElementById('kpiMembersPhotoNote');
    if (membersNote && stats.members_with_photo_percentage !== undefined) {
      membersNote.textContent = `${stats.members_with_photo_percentage}% с фото`;
      membersNote.classList.remove('d-none');
    }

    const trendIncoming = stats.trend_comparison?.incoming_change;
    const trendOutgoing = stats.trend_comparison?.outgoing_change;
    const incomingNote = document.getElementById('kpiIncomingNote');
    const outgoingNote = document.getElementById('kpiOutgoingNote');
    if (incomingNote && trendIncoming !== undefined) {
      const sign = trendIncoming >= 0 ? '+' : '';
      incomingNote.textContent = `${sign}${trendIncoming}% за 30 дней`;
    }
    if (outgoingNote && trendOutgoing !== undefined) {
      const sign = trendOutgoing >= 0 ? '+' : '';
      outgoingNote.textContent = `${sign}${trendOutgoing}% за 30 дней`;
    }

    renderCommissionPerformance(stats);
  }

  function applyTopOrganizations(stats) {
    if (!stats?.top_senders?.length || typeof Chart === 'undefined') return;
    const ctx = document.getElementById('chartTopOrgs');
    if (!ctx || !window.chartTopOrgs) return;

    const labels = stats.top_senders.map((r) => r.organization || '—');
    const data = stats.top_senders.map((r) => Number(r.count) || 0);
    window.chartTopOrgs.data.labels = labels;
    window.chartTopOrgs.data.datasets[0].data = data;
    window.chartTopOrgs.update();
  }

  async function refreshDashboardEnhanced() {
    const stats = await loadAdvancedStats();
    if (!stats) return;
    enhanceDashboardInsights(stats);
    applyTopOrganizations(stats);
    return stats;
  }

  document.addEventListener('DOMContentLoaded', () => {
    const originalInit = window.initializeApp;
    if (typeof originalInit === 'function') {
      window.initializeApp = async function enhancedInit(...args) {
        const result = await originalInit.apply(this, args);
        await refreshDashboardEnhanced();
        return result;
      };
    }

    const periodSelect = document.getElementById('dashboardPeriodSelect');
    periodSelect?.addEventListener('change', () => {
      refreshDashboardEnhanced();
    });
  });

  window.refreshDashboardEnhanced = refreshDashboardEnhanced;
})();

(function () {
  const API = '/api';
  let advancedStatsCache = null;

  function t(key, fb) {
    return window.AppI18n?.t(key, fb) ?? fb;
  }

  function fmt(key, vars) {
    return window.AppI18n?.fmt?.(key, vars) ?? t(key);
  }

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
        container.innerHTML = `<div class="text-muted small">${t('dash.enh.no_commissions', 'Нет данных по комиссиям')}</div>`;
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
            <span class="small fw-semibold">${escapeHtml(c.name || t('dash.enh.commission', 'Комиссия'))}</span>
            <span class="small text-muted">${fmt('dash.enh.letters_people', { letters: totalLetters, people: c.members_count || 0 })}</span>
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
      scansNote.textContent = fmt('dash.enh.scans_pct_letters', { pct: stats.scans_percentage });
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
        note.textContent = fmt('dash.enh.scans_pct', { pct: stats.scans_percentage });
      }
    }

    const membersNote = document.getElementById('kpiMembersPhotoNote');
    if (membersNote && stats.members_with_photo_percentage !== undefined) {
      membersNote.textContent = fmt('dash.enh.photo_pct', { pct: stats.members_with_photo_percentage });
      membersNote.classList.remove('d-none');
    }

    const trendIncoming = stats.trend_comparison?.incoming_change;
    const trendOutgoing = stats.trend_comparison?.outgoing_change;
    const incomingNote = document.getElementById('kpiIncomingNote');
    const outgoingNote = document.getElementById('kpiOutgoingNote');
    if (incomingNote && trendIncoming !== undefined) {
      const sign = trendIncoming >= 0 ? '+' : '';
      incomingNote.textContent = fmt('dash.enh.trend_days', { sign, pct: trendIncoming });
    }
    if (outgoingNote && trendOutgoing !== undefined) {
      const sign = trendOutgoing >= 0 ? '+' : '';
      outgoingNote.textContent = fmt('dash.enh.trend_days', { sign, pct: trendOutgoing });
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
    const dashTab = document.getElementById('tab-dashboard');
    dashTab?.addEventListener('shown.bs.tab', () => refreshDashboardEnhanced());
    if (document.getElementById('pane-dashboard')?.classList.contains('active')) {
      refreshDashboardEnhanced();
    }
  });

  window.addEventListener('app:langchange', () => {
    if (advancedStatsCache) {
      enhanceDashboardInsights(advancedStatsCache);
    }
  });

  window.refreshDashboardEnhanced = refreshDashboardEnhanced;
})();

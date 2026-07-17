(function () {
  const API = window.AppCore?.API_BASE || '/api';
  let advancedStatsCache = null;
  let funnelChart = null;

  window.AppI18n?.register?.({
    ru: {
      'dash.funnel.title': 'Воронка сроков',
      'dash.funnel.on_track': 'В срок',
      'dash.funnel.due_soon': 'Дедлайн ≤3 дня',
      'dash.funnel.overdue': 'Просрочено',
      'dash.funnel.answered': 'Отвечено',
      'dash.funnel.letters': 'Писем',
      'dash.heatmap.title': 'Теплокарта нагрузки',
      'dash.heatmap.cell': '{letters} писем · {people} чел.',
      'dash.export.chart': 'Скачать график PNG'
    },
    kz: {
      'dash.funnel.title': 'Мерзімдер воронкасы',
      'dash.funnel.on_track': 'Мерзімінде',
      'dash.funnel.due_soon': 'Дедлайн ≤3 күн',
      'dash.funnel.overdue': 'Мерзімі өткен',
      'dash.funnel.answered': 'Жауап берілді',
      'dash.funnel.letters': 'Хаттар',
      'dash.heatmap.title': 'Жүктеме жылу картасы',
      'dash.heatmap.cell': '{letters} хат · {people} адам',
      'dash.export.chart': 'Графикті PNG форматында жүктеу'
    }
  });

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

  function renderDeadlineFunnel(stats) {
    const canvas = document.getElementById('chartDeadlineFunnel');
    const funnel = stats?.deadline_funnel;
    if (!canvas || !funnel || typeof Chart === 'undefined') return;

    const labels = [
      t('dash.funnel.on_track', 'В срок'),
      t('dash.funnel.due_soon', 'Дедлайн ≤3 дня'),
      t('dash.funnel.overdue', 'Просрочено'),
      t('dash.funnel.answered', 'Отвечено')
    ];
    const data = [
      Number(funnel.on_track) || 0,
      Number(funnel.due_soon) || 0,
      Number(funnel.overdue) || 0,
      Number(funnel.answered) || 0
    ];

    if (funnelChart) funnelChart.destroy();
    funnelChart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: t('dash.funnel.letters', 'Писем'),
          data,
          backgroundColor: ['#10B981', '#F59E0B', '#EF4444', '#1D4ED8'],
          borderRadius: 4
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { backgroundColor: 'rgba(15, 27, 51, 0.92)', padding: 12, cornerRadius: 8 }
        },
        scales: {
          x: { beginAtZero: true, ticks: { color: '#6B7280', precision: 0 }, grid: { borderDash: [2, 4], color: 'rgba(107,114,128,0.25)' } },
          y: { grid: { display: false }, ticks: { color: '#6B7280' } }
        }
      }
    });
  }

  function renderCommissionHeatmap(stats) {
    const wrap = document.getElementById('commissionHeatmapWrap');
    const grid = document.getElementById('commissionHeatmap');
    const rows = stats?.commission_performance;
    if (!wrap || !grid) return;
    if (!rows?.length) {
      wrap.classList.add('d-none');
      return;
    }
    const totals = rows.map((c) => (Number(c.incoming_count) || 0) + (Number(c.outgoing_count) || 0));
    const max = Math.max(...totals, 1);
    grid.innerHTML = rows.map((c, i) => {
      const total = totals[i];
      const level = Math.min(4, Math.round((total / max) * 4));
      const meta = fmt('dash.heatmap.cell', { letters: total, people: Number(c.members_count) || 0 });
      return `
        <div class="heatmap-cell heat-${level}" title="${escapeHtml(c.name || '')} — ${escapeHtml(meta)}">
          <div class="heatmap-cell__name">${escapeHtml(c.name || t('dash.enh.commission', 'Комиссия'))}</div>
          <div class="heatmap-cell__meta">${escapeHtml(meta)}</div>
        </div>`;
    }).join('');
    const title = document.getElementById('commissionHeatmapTitle');
    if (title) title.textContent = t('dash.heatmap.title', 'Теплокарта нагрузки');
    wrap.classList.remove('d-none');
  }

  function exportChartPng(canvasId) {
    const src = document.getElementById(canvasId);
    if (!src || !src.width) return;
    const tmp = document.createElement('canvas');
    tmp.width = src.width;
    tmp.height = src.height;
    const ctx = tmp.getContext('2d');
    ctx.fillStyle = document.body.classList.contains('dark-mode') ? '#1F2937' : '#FFFFFF';
    ctx.fillRect(0, 0, tmp.width, tmp.height);
    ctx.drawImage(src, 0, 0);
    const link = document.createElement('a');
    link.href = tmp.toDataURL('image/png');
    link.download = `${canvasId}-${new Date().toISOString().slice(0, 10)}.png`;
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  function updateExportButtonLabels() {
    const label = t('dash.export.chart', 'Скачать график PNG');
    document.querySelectorAll('.chart-export-btn').forEach((btn) => {
      btn.setAttribute('aria-label', label);
      btn.setAttribute('title', label);
    });
  }

  function setupChartExportButtons() {
    document.querySelectorAll('.chart-export-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const chartId = btn.getAttribute('data-chart');
        if (chartId) exportChartPng(chartId);
      });
    });
    updateExportButtonLabels();
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
    renderDeadlineFunnel(stats);
    renderCommissionHeatmap(stats);
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
    setupChartExportButtons();
    const dashTab = document.getElementById('tab-dashboard');
    dashTab?.addEventListener('shown.bs.tab', () => refreshDashboardEnhanced());
    if (document.getElementById('pane-dashboard')?.classList.contains('active')) {
      refreshDashboardEnhanced();
    }
  });

  window.addEventListener('app:langchange', () => {
    updateExportButtonLabels();
    if (advancedStatsCache) {
      enhanceDashboardInsights(advancedStatsCache);
    }
  });

  window.refreshDashboardEnhanced = refreshDashboardEnhanced;
})();

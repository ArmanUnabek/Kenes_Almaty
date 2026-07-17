/**
 * Экспорт-центр: единая модалка выгрузок (exportCenterModal в index.php).
 * Подключается отдельным <script> (НЕ в бандле), по образцу notify-feed.js.
 *
 * Переиспользует существующие эндпоинты:
 *   письма      → /api/export.php (csv/xlsx/json + direction/date_from/date_to/region_id)
 *   статистика  → /api/export_pdf.php (pdf: summary/kpi + date_from/date_to/region_id)
 *   календарь   → /api/calendar_export.php (ics + from/to/region_id)
 *   обращения   → /api/appeals.php?export=csv|json (+date_from/date_to/region_id)
 */
(function (window) {
  const API = window.API_BASE || '/api';

  const t = (key, fb) => window.AppI18n?.t(key, fb) ?? fb;

  window.AppI18n?.register?.({
    ru: {
      'exportcenter.open': 'Экспорт-центр',
      'exportcenter.open_item': 'Экспорт-центр…',
      'exportcenter.title': 'Экспорт-центр',
      'exportcenter.what': 'Что выгрузить',
      'exportcenter.type_letters': 'Письма',
      'exportcenter.type_stats': 'Статистика',
      'exportcenter.type_calendar': 'Календарь мероприятий',
      'exportcenter.type_appeals': 'Обращения граждан',
      'exportcenter.direction': 'Направление',
      'exportcenter.dir_all': 'Входящие и исходящие',
      'exportcenter.dir_in': 'Только входящие',
      'exportcenter.dir_out': 'Только исходящие',
      'exportcenter.format': 'Формат',
      'exportcenter.date_from': 'Дата от',
      'exportcenter.date_to': 'Дата до',
      'exportcenter.region': 'Регион',
      'exportcenter.region_all': 'Все регионы',
      'exportcenter.cancel': 'Отмена',
      'exportcenter.download': 'Скачать',
      'exportcenter.err_dates': 'Дата «от» должна быть не позже даты «до»',
      'exportcenter.err_download': 'Не удалось выполнить выгрузку',
      'exportcenter.preparing': 'Подготовка…',
    },
    kz: {
      'exportcenter.open': 'Экспорт орталығы',
      'exportcenter.open_item': 'Экспорт орталығы…',
      'exportcenter.title': 'Экспорт орталығы',
      'exportcenter.what': 'Нені жүктеп алу',
      'exportcenter.type_letters': 'Хаттар',
      'exportcenter.type_stats': 'Статистика',
      'exportcenter.type_calendar': 'Іс-шаралар күнтізбесі',
      'exportcenter.type_appeals': 'Азаматтардың өтініштері',
      'exportcenter.direction': 'Бағыты',
      'exportcenter.dir_all': 'Кіріс және шығыс',
      'exportcenter.dir_in': 'Тек кіріс',
      'exportcenter.dir_out': 'Тек шығыс',
      'exportcenter.format': 'Формат',
      'exportcenter.date_from': 'Басталу күні',
      'exportcenter.date_to': 'Аяқталу күні',
      'exportcenter.region': 'Аймақ',
      'exportcenter.region_all': 'Барлық аймақтар',
      'exportcenter.cancel': 'Болдырмау',
      'exportcenter.download': 'Жүктеп алу',
      'exportcenter.err_dates': '«Басталу» күні «аяқталу» күнінен кеш болмауы керек',
      'exportcenter.err_download': 'Жүктеп алу сәтсіз аяқталды',
      'exportcenter.preparing': 'Дайындалуда…',
    },
  });

  // Матрица доступных форматов по типу данных.
  // PDF писем/обращений и XLSX/ICS вне своих типов сознательно не предлагаем.
  const FORMATS = {
    letters:  ['csv', 'xlsx', 'json'],
    stats:    ['pdf'],
    calendar: ['ics'],
    appeals:  ['csv', 'json'],
  };

  const els = {};
  let modalInstance = null;

  function $(id) { return document.getElementById(id); }

  function currentType() { return els.dataType?.value || 'letters'; }

  function selectedFormat() {
    const checked = document.querySelector('input[name="ecFormat"]:checked');
    return checked ? checked.value : '';
  }

  function applyFormatAvailability() {
    const allowed = FORMATS[currentType()] || [];
    let firstEnabled = null;
    document.querySelectorAll('input[name="ecFormat"]').forEach((input) => {
      const ok = allowed.includes(input.value);
      input.disabled = !ok;
      const label = document.querySelector(`label[for="${input.id}"]`);
      label?.classList.toggle('disabled', !ok);
      if (ok && !firstEnabled) firstEnabled = input;
      if (!ok) input.checked = false;
    });
    if (!document.querySelector('input[name="ecFormat"]:checked') && firstEnabled) {
      firstEnabled.checked = true;
    }
    // Направление актуально только для писем
    els.directionWrap?.classList.toggle('d-none', currentType() !== 'letters');
  }

  function fillRegionSelect() {
    const user = window.getSessionUser?.() || window.__sessionUser;
    if (!user?.is_admin) {
      els.regionWrap?.classList.add('d-none');
      return;
    }
    const regions = (window.allRegions || []).filter((r) => r.is_active == 1 || r.is_active === true);
    if (!regions.length) {
      els.regionWrap?.classList.add('d-none');
      return;
    }
    const active = Number(user.active_region_id) || 0;
    els.regionSelect.innerHTML = `<option value="all">${t('exportcenter.region_all', 'Все регионы')}</option>`
      + regions.map((r) => `<option value="${Number(r.id)}"${active === Number(r.id) ? ' selected' : ''}>${window.escapeHtml ? window.escapeHtml(r.name_ru) : r.name_ru}</option>`).join('');
    els.regionWrap.classList.remove('d-none');
  }

  function setDefaultDates() {
    const year = new Date().getFullYear();
    if (els.dateFrom && !els.dateFrom.value) els.dateFrom.value = `${year}-01-01`;
    if (els.dateTo && !els.dateTo.value) els.dateTo.value = `${year}-12-31`;
  }

  function showError(msg) {
    if (!els.error) return;
    els.error.textContent = msg;
    els.error.classList.remove('d-none');
  }

  function hideError() {
    els.error?.classList.add('d-none');
  }

  function buildUrl() {
    const type = currentType();
    const format = selectedFormat();
    const from = els.dateFrom?.value || '';
    const to = els.dateTo?.value || '';
    const region = els.regionWrap && !els.regionWrap.classList.contains('d-none')
      ? (els.regionSelect?.value || '')
      : '';

    const params = new URLSearchParams();
    if (region) params.set('region_id', region);

    if (type === 'letters') {
      params.set('format', format);
      const dir = els.direction?.value || 'all';
      if (dir !== 'all') params.set('direction', dir);
      if (from) params.set('date_from', from);
      if (to) params.set('date_to', to);
      return { url: `${API}/export.php?${params}`, name: `os_journal_letters.${format}`, newTab: false };
    }
    if (type === 'stats') {
      params.set('type', 'summary');
      if (from) params.set('date_from', from);
      if (to) params.set('date_to', to);
      return { url: `${API}/export_pdf.php?${params}`, name: 'os_journal_report.pdf', newTab: true };
    }
    if (type === 'calendar') {
      if (from) params.set('from', from);
      if (to) params.set('to', to);
      return { url: `${API}/calendar_export.php?${params}`, name: 'events.ics', newTab: false };
    }
    // appeals
    params.set('export', format);
    if (from) params.set('date_from', from);
    if (to) params.set('date_to', to);
    return { url: `${API}/appeals.php?${params}`, name: `appeals.${format}`, newTab: false };
  }

  async function download() {
    hideError();
    const from = els.dateFrom?.value || '';
    const to = els.dateTo?.value || '';
    if (from && to && from > to) {
      showError(t('exportcenter.err_dates', 'Дата «от» должна быть не позже даты «до»'));
      return;
    }
    if (!selectedFormat()) return;

    const { url, name, newTab } = buildUrl();

    if (newTab) {
      // PDF открывается в новой вкладке (как существующий exportPdfBtn)
      window.open(url, '_blank', 'noopener');
      return;
    }

    const btn = els.downloadBtn;
    const prevHtml = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = t('exportcenter.preparing', 'Подготовка…');
    try {
      const resp = await fetch(url, { credentials: 'same-origin' });
      if (!resp.ok) {
        const err = await resp.json().catch(() => ({}));
        throw new Error(err.error || `HTTP ${resp.status}`);
      }
      const blob = await resp.blob();
      const cd = resp.headers.get('Content-Disposition') || '';
      const m = cd.match(/filename="([^"]+)"/);
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = m ? m[1] : name;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 5000);
    } catch (e) {
      showError((e && e.message) || t('exportcenter.err_download', 'Не удалось выполнить выгрузку'));
    } finally {
      btn.disabled = false;
      btn.innerHTML = prevHtml;
    }
  }

  function openModal() {
    hideError();
    setDefaultDates();
    fillRegionSelect();
    applyFormatAvailability();
    const modalEl = $('exportCenterModal');
    if (!modalEl) return;
    if (!modalInstance) modalInstance = new bootstrap.Modal(modalEl);
    modalInstance.show();
  }

  document.addEventListener('DOMContentLoaded', () => {
    els.dataType = $('ecDataType');
    els.direction = $('ecDirection');
    els.directionWrap = $('ecDirectionWrap');
    els.dateFrom = $('ecDateFrom');
    els.dateTo = $('ecDateTo');
    els.regionWrap = $('ecRegionWrap');
    els.regionSelect = $('ecRegionSelect');
    els.error = $('ecError');
    els.downloadBtn = $('ecDownloadBtn');
    if (!els.dataType || !els.downloadBtn) return;

    els.dataType.addEventListener('change', () => {
      hideError();
      applyFormatAvailability();
    });
    els.downloadBtn.addEventListener('click', download);

    // Открытие: кнопка в топбаре + пункт в мобильном dropdown (data-trigger)
    document.addEventListener('click', (e) => {
      if (e.target.closest('#exportCenterBtn') || e.target.closest('[data-trigger="exportCenterBtn"]')) {
        openModal();
      }
    });

    // При смене языка перезаполняем регион-селект (пункт «Все регионы»)
    window.addEventListener('app:langchange', () => {
      if (els.regionWrap && !els.regionWrap.classList.contains('d-none')) {
        fillRegionSelect();
      }
    });
  });
})(window);

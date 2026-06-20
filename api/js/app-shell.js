/**
 * UI shell: toast, loading, export/import, autocomplete.
 */
(function (window) {
  const formatDateISOtoRus = window.AppUtils?.formatDateISOtoRus
    || ((iso) => (iso ? new Date(iso).toLocaleDateString('ru-RU') : ''));

  function sanitizeCsv(str) {
    const s = String(str ?? '');
    if (s.includes(',') || s.includes('\n') || s.includes('"')) {
      return '"' + s.replace(/"/g, '""') + '"';
    }
    return s;
  }

  function showLoading(message) {
    const overlay = document.getElementById('loadingOverlay');
    if (!overlay) return;
    overlay.style.display = 'flex';
    const text = overlay.querySelector('p');
    if (text) text.textContent = message || 'Загрузка...';
  }

  function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.style.display = 'none';
  }

  function showToast(message, type = 'info') {
    const container = document.querySelector('.toast-container');
    if (!container) return;
    const bg = type === 'error' ? 'danger' : type === 'success' ? 'success' : type === 'warning' ? 'warning' : 'info';
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${bg} border-0`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
      <div class="d-flex">
        <div class="toast-body">${escapeHtml(message)}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Закрыть"></button>
      </div>`;
    container.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, { delay: 3500 });
    bsToast.show();
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
  }

  function showSuccess(message) { showToast(message, 'success'); }
  function showError(message) { showToast(message, 'error'); }
  function showWarning(message) { showToast(message, 'warning'); }
  function showInfo(message) { showToast(message, 'info'); }

  async function exportData(format) {
    const allowed = window.canExport?.() || window.AppCore?.canExport?.();
    if (!allowed) {
      showError('Экспорт данных доступен только администратору');
      return;
    }
    try {
      showLoading('Подготовка экспорта...');
      const resp = await fetch(`/api/export.php?format=${encodeURIComponent(format)}`);
      if (!resp.ok) {
        const err = await resp.json().catch(() => ({}));
        throw new Error(err.error || `Ошибка экспорта (${resp.status})`);
      }
      const blob = await resp.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      let filename = `os_journal_${new Date().toISOString().slice(0, 10)}.${format}`;
      const dispo = resp.headers.get('Content-Disposition');
      if (dispo) {
        const match = dispo.match(/filename="?([^";]+)"?/);
        if (match) filename = match[1];
      }
      a.download = filename;
      a.click();
      URL.revokeObjectURL(url);
      showSuccess('Экспорт выполнен. Операция записана в журнал аудита.');
    } catch (err) {
      console.error(err);
      showError(err.message || 'Не удалось экспортировать данные');
    } finally {
      hideLoading();
    }
  }

  function exportJson() {
    exportData('json');
  }

  function exportCsv() {
    exportData('csv');
  }

  async function importJson(ev) {
    const file = ev?.target?.files?.[0];
    if (!file) return;
    if (!window.canExport?.() && !window.AppCore?.canExport?.()) {
      showError('Импорт данных доступен только администратору');
      if (ev?.target) ev.target.value = '';
      return;
    }
    if (!window.confirm('Импорт добавит письма через API. Продолжить?')) {
      ev.target.value = '';
      return;
    }
    try {
      showLoading('Импорт данных...');
      const data = JSON.parse(await file.text());
      const user = JSON.parse(localStorage.getItem('user') || 'null');
      const regionId = user?.region?.id || user?.region_id || null;
      let ok = 0;
      let fail = 0;

      const postLetter = async (type, payload) => {
        const resp = await fetch(`/api/letters.php?type=${type}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        if (!resp.ok) throw new Error(await resp.text());
        ok += 1;
      };

      for (const item of data.incoming || []) {
        try {
          await postLetter('incoming', {
            seq: item.seq || null,
            date: item.date,
            organization: item.organization || '',
            kk_number: item.kkNumber || item.kk_number || '',
            category: item.category || 'KK',
            subject: item.subject || '',
            note: item.note || '',
            members: (item.members || []).map((m) => ({
              member_id: m.member_id || m.id,
              is_lead: !!m.is_lead,
            })),
            recipients: item.recipients || (item.organization ? [item.organization] : []),
            region_id: regionId,
            scans: [],
          });
        } catch (e) {
          console.warn('import incoming failed', item, e);
          fail += 1;
        }
      }

      for (const item of data.outgoing || []) {
        try {
          await postLetter('outgoing', {
            date: item.date,
            outgoing_number: item.outgoingNumber || item.outgoing_number || null,
            organization: item.organization || '',
            incoming_ref_id: item.incomingRefId || item.incoming_ref_id || null,
            subject: item.subject || '',
            note: item.note || '',
            outgoing_type: item.outgoingType || item.outgoing_type || 'gov',
            members: (item.members || []).map((m) => ({
              member_id: m.member_id || m.id,
              is_lead: !!m.is_lead,
            })),
            recipients: item.recipients || [],
            region_id: regionId,
            scans: [],
          });
        } catch (e) {
          console.warn('import outgoing failed', item, e);
          fail += 1;
        }
      }

      if (typeof window.refreshLetters === 'function') await window.refreshLetters();
      if (typeof window.renderAll === 'function') window.renderAll();
      hideLoading();
      showSuccess(`Импорт завершён: ${ok} записей${fail ? `, ошибок: ${fail}` : ''}`);
    } catch (err) {
      console.error(err);
      hideLoading();
      showError('Не удалось импортировать JSON');
    } finally {
      if (ev?.target) ev.target.value = '';
    }
  }

  function setupAutocomplete(inputId, dataGetter, filterKey = null) {
    const input = document.getElementById(inputId);
    if (!input) return;
    if (!input.parentElement.classList.contains('search-container')) {
      const wrapper = document.createElement('div');
      wrapper.className = 'search-container position-relative';
      input.parentNode.insertBefore(wrapper, input);
      wrapper.appendChild(input);
    }
    let currentFocus = -1;
    input.addEventListener('input', function onInput() {
      const val = this.value;
      closeAllLists();
      if (!val) return;
      currentFocus = -1;
      const list = document.createElement('div');
      list.id = this.id + 'autocomplete-list';
      list.className = 'autocomplete-suggestions';
      this.parentNode.appendChild(list);
      const matches = new Set();
      dataGetter().forEach((item) => {
        const text = filterKey ? item[filterKey] : item;
        if (text && String(text).toLowerCase().includes(val.toLowerCase())) matches.add(String(text));
      });
      Array.from(matches).slice(0, 10).forEach((match) => {
        const row = document.createElement('div');
        row.className = 'autocomplete-suggestion';
        const safeVal = val.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        row.innerHTML = `${escapeHtml(match).replace(new RegExp(`(${safeVal})`, 'gi'), '<strong>$1</strong>')}<input type="hidden" value="${escapeHtml(match)}">`;
        row.addEventListener('click', () => {
          input.value = row.querySelector('input').value;
          closeAllLists();
          input.dispatchEvent(new Event('input'));
        });
        list.appendChild(row);
      });
    });
    input.addEventListener('keydown', (e) => {
      const list = document.getElementById(input.id + 'autocomplete-list');
      const rows = list ? list.getElementsByTagName('div') : null;
      if (e.keyCode === 40 && rows) { currentFocus++; highlight(rows); }
      else if (e.keyCode === 38 && rows) { currentFocus--; highlight(rows); }
      else if (e.keyCode === 13 && rows && currentFocus > -1) { e.preventDefault(); rows[currentFocus]?.click(); }
    });
    function highlight(rows) {
      Array.from(rows).forEach((r, i) => r.classList.toggle('autocomplete-active', i === currentFocus));
    }
    function closeAllLists(el) {
      document.querySelectorAll('.autocomplete-suggestions').forEach((node) => {
        if (el !== node && el !== input) node.remove();
      });
    }
    document.addEventListener('click', (e) => closeAllLists(e.target));
  }

  async function importCsv(ev) {
    const file = ev?.target?.files?.[0];
    if (!file) return;
    if (!window.canExport?.() && !window.AppCore?.canExport?.()) {
      showError('Импорт CSV доступен только администратору или модератору');
      if (ev?.target) ev.target.value = '';
      return;
    }

    const typeChoice = window.prompt(
      'Тип писем для импорта:\n  incoming — входящие\n  outgoing — исходящие\n\nВведите incoming или outgoing:',
      'incoming'
    );
    if (!typeChoice || !['incoming', 'outgoing'].includes(typeChoice.trim().toLowerCase())) {
      if (ev?.target) ev.target.value = '';
      return;
    }
    const letterType = typeChoice.trim().toLowerCase();

    if (!window.confirm(
      `Импорт ${letterType === 'incoming' ? 'входящих' : 'исходящих'} писем из CSV?\n\n` +
      'Формат (разделитель — точка с запятой):\n' +
      (letterType === 'incoming'
        ? 'дата;организация;рег.номер;категория;тема;примечание'
        : 'дата;исх.номер;организация;тема;примечание;тип') +
      '\n\nПервая строка — заголовок (пропускается).'
    )) {
      if (ev?.target) ev.target.value = '';
      return;
    }

    try {
      showLoading('Импорт CSV...');
      const formData = new FormData();
      formData.append('file', file);
      formData.append('type', letterType);

      const resp = await fetch('/api/import.php', {
        method: 'POST',
        body: formData,
      });
      const data = await resp.json().catch(() => ({}));
      if (!resp.ok) {
        throw new Error(data.error || `Ошибка сервера (${resp.status})`);
      }
      const errSummary = data.errors?.length
        ? `\nОшибки (первые ${data.errors.length}):\n` +
          data.errors.slice(0, 5).map((e) => `  Строка ${e.row}: ${e.message}`).join('\n')
        : '';
      showSuccess(
        `CSV импорт завершён: импортировано ${data.imported}, пропущено ${data.skipped}.${errSummary ? ' Подробности в консоли.' : ''}`
      );
      if (errSummary) console.warn('CSV import errors:', data.errors);
      if (typeof window.refreshLetters === 'function') await window.refreshLetters();
      if (typeof window.renderAll === 'function') window.renderAll();
    } catch (err) {
      console.error(err);
      showError(err.message || 'Не удалось импортировать CSV');
    } finally {
      hideLoading();
      if (ev?.target) ev.target.value = '';
    }
  }

  function bindExportImport() {
    document.getElementById('exportJsonBtn')?.addEventListener('click', exportJson);
    document.getElementById('exportCsvBtn')?.addEventListener('click', exportCsv);
    document.getElementById('importJsonInput')?.addEventListener('change', importJson);
    document.getElementById('importCsvInput')?.addEventListener('change', importCsv);

    // Wire mobile dropdown triggers
    document.querySelectorAll('[data-trigger="importCsv"]').forEach((btn) => {
      btn.addEventListener('click', () => document.getElementById('importCsvInput')?.click());
    });
  }

  window.showLoading = showLoading;
  window.hideLoading = hideLoading;
  window.showSuccess = showSuccess;
  window.showError = showError;
  window.showWarning = showWarning;
  window.showInfo = showInfo;
  window.setupAutocomplete = setupAutocomplete;
  window.exportJson = exportJson;
  window.exportCsv = exportCsv;
  window.importJson = importJson;
  window.importCsv = importCsv;
  window.sanitizeCsv = sanitizeCsv;

  document.addEventListener('DOMContentLoaded', bindExportImport);
})(window);

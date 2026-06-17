/**
 * Утилиты фронтенда журнала ОС.
 */
(function (window) {
  function escapeHtml(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function formatDateISOtoRus(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleDateString('ru-RU');
  }

  function debounce(fn, delay) {
    let timer;
    return function debounced(...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  function parseToDate(value) {
    return value instanceof Date ? new Date(value.getTime()) : new Date(value);
  }

  function addWorkingDays(start, days) {
    let date = parseToDate(start);
    let remaining = Number(days) || 0;
    while (remaining > 0) {
      date.setDate(date.getDate() + 1);
      const day = date.getDay();
      if (day !== 0 && day !== 6) remaining -= 1;
    }
    return date;
  }

  function subtractWorkingDays(start, days) {
    let date = parseToDate(start);
    let remaining = Number(days) || 0;
    while (remaining > 0) {
      date.setDate(date.getDate() - 1);
      const day = date.getDay();
      if (day !== 0 && day !== 6) remaining -= 1;
    }
    return date;
  }

  window.AppUtils = {
    escapeHtml,
    formatDateISOtoRus,
    debounce,
    addWorkingDays,
    subtractWorkingDays,
  };

  window.escapeHtml = escapeHtml;
  window.addWorkingDays = addWorkingDays;
  window.subtractWorkingDays = subtractWorkingDays;
})(window);

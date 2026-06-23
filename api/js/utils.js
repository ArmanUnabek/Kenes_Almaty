/**
 * Утилиты фронтенда журнала ОС.
 */
(function (window) {
  const RESPONSE_WORKING_DAYS = 15;
  const WARN_WORKING_DAYS_BEFORE = 3;

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

  function isLetterPending(letter) {
    if (!letter) return false;
    return !letter.linkedOutgoingId && !letter.linked_outgoing_id;
  }

  function getLetterDueDate(letterDate) {
    return addWorkingDays(letterDate, RESPONSE_WORKING_DAYS);
  }

  function isLetterOverdue(letter, today = new Date()) {
    if (!isLetterPending(letter) || !letter?.date) return false;
    const due = getLetterDueDate(letter.date);
    const t = parseToDate(today);
    t.setHours(23, 59, 59, 999);
    return t > due;
  }

  function isLetterDueSoon(letter, today = new Date()) {
    if (!isLetterPending(letter) || !letter?.date) return false;
    const due = getLetterDueDate(letter.date);
    const warnFrom = subtractWorkingDays(due, WARN_WORKING_DAYS_BEFORE);
    const t = parseToDate(today);
    return t >= warnFrom && t <= due;
  }

  function syncUserToStorage(user) {
    if (!user) return;
    try {
      localStorage.setItem('user', JSON.stringify(user));
    } catch (_) { /* ignore */ }
  }

  function getPendingLettersSummary(incoming, today = new Date()) {
    let overdue = 0;
    let warning = 0;
    let pending = 0;
    (incoming || []).filter(isLetterPending).forEach((item) => {
      pending += 1;
      if (isLetterOverdue(item, today)) overdue += 1;
      else if (isLetterDueSoon(item, today)) warning += 1;
    });
    return { pending, overdue, warning };
  }

  /**
   * Нормализует ответ API к массиву. List-эндпоинты отдают то «голый массив»,
   * то объект `{items, pagination}` (или `{data}`) в зависимости от параметров —
   * этот хелпер делает потребителей устойчивыми к обоим форматам.
   */
  function asList(data) {
    const list = Array.isArray(data) ? data : (data?.items ?? data?.data ?? []);
    return Array.isArray(list) ? list : Object.values(list || {});
  }

  /**
   * fetch + разбор JSON с единой обработкой ошибок. Идёт через пропатченный
   * `window.fetch` из csrf-handler.js, поэтому CSRF-токен подставляется как обычно.
   */
  async function fetchJson(url, opts) {
    const response = await fetch(url, opts);
    if (!response.ok) {
      const body = await response.json().catch(() => ({}));
      throw new Error(body.error || body.message || `HTTP ${response.status}`);
    }
    return response.json().catch(() => ({}));
  }

  window.AppUtils = {
    escapeHtml,
    formatDateISOtoRus,
    debounce,
    addWorkingDays,
    subtractWorkingDays,
    isLetterPending,
    getLetterDueDate,
    isLetterOverdue,
    isLetterDueSoon,
    syncUserToStorage,
    getPendingLettersSummary,
    asList,
    fetchJson,
    RESPONSE_WORKING_DAYS,
    WARN_WORKING_DAYS_BEFORE,
  };

  window.escapeHtml = escapeHtml;
  window.addWorkingDays = addWorkingDays;
  window.subtractWorkingDays = subtractWorkingDays;
  window.syncUserToStorage = syncUserToStorage;
  window.asList = asList;
  // Глобальный apiFetch: ряд панелей (комментарии/аудит/архив/шаблоны) в
  // letters-ui.js вызывают apiFetch, который ранее нигде не был определён.
  window.apiFetch = fetchJson;
})(window);

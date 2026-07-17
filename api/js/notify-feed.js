/**
 * Лента уведомлений (вкладка «Лента» в notifyModal) + бейдж непрочитанных.
 * Подключается отдельным <script> (НЕ в бандле), по образцу pwa-install.js.
 * Polling каждые 60с только при видимой вкладке (document.visibilityState).
 * «Прочитанность» — клиентская: localStorage хранит timestamp последнего
 * открытия ленты, unread = элементы новее него.
 */
(function (window) {
  // Флаг для dashboard.js (в бандле): когда лента подключена, она —
  // единственный писатель бейджа #notifyBadge, дашборд его не перезаписывает.
  window.__notifyFeedActive = true;
  const API = window.API_BASE || '/api';
  const SEEN_KEY = 'notifyFeedSeenAt';
  const POLL_MS = 60000;
  let lastItems = [];

  const t = (key, fb) => window.AppI18n?.t(key, fb) ?? fb;
  const esc = (s) => (window.escapeHtml
    ? window.escapeHtml(String(s ?? ''))
    : String(s ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c])));

  window.AppI18n?.register?.({
    ru: {
      'feed.tab': 'Лента',
      'feed.empty': 'Новых событий нет',
      'feed.error': 'Не удалось загрузить ленту',
      'feed.loading': 'Загрузка...',
      'feed.letter_new': 'Новое входящее письмо',
      'feed.deadline_soon': 'Скоро срок ответа',
      'feed.deadline_overdue': 'Просрочен срок ответа',
      'feed.appeal_new': 'Новое обращение гражданина',
      'feed.event_upcoming': 'Ближайшее мероприятие',
      'feed.reg_no': 'Рег. №',
    },
    kz: {
      'feed.tab': 'Оқиғалар',
      'feed.empty': 'Жаңа оқиғалар жоқ',
      'feed.error': 'Ленханы жүктеу мүмкін болмады',
      'feed.loading': 'Жүктелуде...',
      'feed.letter_new': 'Жаңа кіріс хат',
      'feed.deadline_soon': 'Жауап мерзімі жақын',
      'feed.deadline_overdue': 'Жауап мерзімі өтіп кетті',
      'feed.appeal_new': 'Азаматтың жаңа өтініші',
      'feed.event_upcoming': 'Жақын арадағы іс-шара',
      'feed.reg_no': 'Тірк. №',
    },
  });

  const TYPE_META = {
    letter_new: { icon: 'bi-envelope-plus', cls: 'notify-feed-item--info' },
    deadline_soon: { icon: 'bi-alarm', cls: 'notify-feed-item--warning' },
    deadline_overdue: { icon: 'bi-exclamation-octagon', cls: 'notify-feed-item--danger' },
    appeal_new: { icon: 'bi-chat-left-text', cls: 'notify-feed-item--appeal' },
    event_upcoming: { icon: 'bi-calendar-event', cls: 'notify-feed-item--event' },
  };

  function getSeenAt() {
    try { return parseInt(localStorage.getItem(SEEN_KEY) || '0', 10) || 0; } catch (e) { return 0; }
  }

  function markSeen() {
    try { localStorage.setItem(SEEN_KEY, String(Date.now())); } catch (e) { /* ignore */ }
    updateBadge(0);
  }

  function itemTime(it) {
    const d = new Date(String(it.ts || it.date || '').replace(' ', 'T'));
    return Number.isNaN(d.getTime()) ? 0 : d.getTime();
  }

  function countUnread(items) {
    const seen = getSeenAt();
    return items.filter((it) => itemTime(it) > seen).length;
  }

  function updateBadge(n) {
    const badge = document.getElementById('notifyBadge');
    if (!badge) return;
    if (n > 0) {
      badge.textContent = n > 99 ? '99+' : String(n);
      badge.classList.remove('d-none');
    } else {
      badge.classList.add('d-none');
    }
  }

  async function fetchFeed() {
    const resp = await fetch(`${API}/notifications.php?feed=1`, { credentials: 'same-origin' });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    const data = await resp.json();
    lastItems = Array.isArray(data.items) ? data.items : [];
    return lastItems;
  }

  function fmtDate(s) {
    const d = new Date(String(s).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return esc(s);
    const lang = window.AppI18n?.getLang?.() === 'kz' ? 'kk-KZ' : 'ru-RU';
    return d.toLocaleDateString(lang);
  }

  function renderFeed(box, items) {
    if (!items.length) {
      box.innerHTML = `<p class="text-center text-muted my-4">${t('feed.empty', 'Новых событий нет')}</p>`;
      return;
    }
    const seen = getSeenAt();
    box.innerHTML = `<ul class="notify-feed list-unstyled mb-0">${items.map((it) => {
      const meta = TYPE_META[it.type] || { icon: 'bi-bell', cls: '' };
      const unread = itemTime(it) > seen;
      const seq = it.seq ? ` <span class="text-muted small">${t('feed.reg_no', 'Рег. №')}${esc(it.seq)}</span>` : '';
      return `<li>
        <button type="button" class="notify-feed-item ${meta.cls}${unread ? ' notify-feed-item--unread' : ''}" data-feed-tab="${esc(it.tab || '')}">
          <i class="bi ${meta.icon} notify-feed-item__icon" aria-hidden="true"></i>
          <span class="notify-feed-item__body">
            <span class="notify-feed-item__type">${t('feed.' + it.type, it.type)}${seq}</span>
            <span class="notify-feed-item__title">${esc(it.title || '')}</span>
          </span>
          <span class="notify-feed-item__date">${fmtDate(it.date)}</span>
        </button>
      </li>`;
    }).join('')}</ul>`;
  }

  async function loadIntoPane() {
    const box = document.getElementById('notifyFeedPane');
    if (!box) return;
    box.innerHTML = `<p class="text-center text-muted my-4">${t('feed.loading', 'Загрузка...')}</p>`;
    try {
      renderFeed(box, await fetchFeed());
    } catch (e) {
      box.innerHTML = `<p class="text-center text-muted my-4">${t('feed.error', 'Не удалось загрузить ленту')}</p>`;
    }
    markSeen();
  }

  async function poll() {
    if (document.visibilityState !== 'visible') return;
    try {
      updateBadge(countUnread(await fetchFeed()));
    } catch (e) { /* сеть недоступна — молча */ }
  }

  document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('notifyModal');
    const pane = document.getElementById('notifyFeedPane');
    if (!modalEl || !pane) return;

    // Загрузка ленты при открытии модалки (вкладка «Лента» активна по умолчанию)
    modalEl.addEventListener('shown.bs.modal', loadIntoPane);
    document.getElementById('notify-tab-feed')?.addEventListener('shown.bs.tab', loadIntoPane);

    // Клик по элементу ленты → переход на вкладку (как deep-link в pwa-install.js)
    pane.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-feed-tab]');
      if (!btn) return;
      const tab = btn.getAttribute('data-feed-tab');
      if (!tab) return;
      try { bootstrap.Modal.getInstance(modalEl)?.hide(); } catch (err) { /* ignore */ }
      document.getElementById('tab-' + tab)?.click();
    });

    window.addEventListener('app:langchange', () => {
      if (pane.innerHTML && lastItems.length) renderFeed(pane, lastItems);
    });

    // Polling 60с только при видимой вкладке + мгновенная проверка при возврате
    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') poll();
    });
    poll();
  });
})(window);

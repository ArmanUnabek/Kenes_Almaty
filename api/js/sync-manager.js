/**
 * Менеджер синхронизации: онлайн/офлайн, очередь записи, индикатор.
 */
(function (window) {
  const REPLAY_DELAY = 1000;
  let isOnline = navigator.onLine;
  let indicatorEl = null;
  let replayTimer = null;

  function createIndicator() {
    if (indicatorEl) return;
    indicatorEl = document.createElement('div');
    indicatorEl.id = 'offline-indicator';
    indicatorEl.className = 'offline-indicator offline-indicator--hidden';
    indicatorEl.innerHTML =
      '<span class="offline-indicator__dot"></span>' +
      '<span class="offline-indicator__text">Офлайн-режим</span>';
    document.body.appendChild(indicatorEl);
  }

  function updateIndicator() {
    if (!indicatorEl) return;
    if (isOnline) {
      indicatorEl.classList.add('offline-indicator--hidden');
      indicatorEl.classList.remove('offline-indicator--visible');
    } else {
      indicatorEl.classList.remove('offline-indicator--hidden');
      indicatorEl.classList.add('offline-indicator--visible');
    }
  }

  function setStatus(online) {
    isOnline = online;
    updateIndicator();
    if (online) {
      scheduleReplay();
    }
  }

  async function replayQueue() {
    const cache = window.AppOffline;
    if (!cache || !isOnline) return;

    const queue = await cache.getQueue();
    if (!queue.length) return;

    const API = window.API_BASE || '/api';
    for (const item of queue) {
      try {
        const resp = await fetch(`${API}${item.url}`, {
          method: item.method,
          headers: { 'Content-Type': 'application/json' },
          body: item.body ? JSON.stringify(item.body) : undefined,
        });
        if (!resp.ok) {
          const serverData = await resp.json().catch(() => ({}));
          if (resp.status === 409) {
            console.warn('[SyncManager] Conflict on', item.url, '— server wins');
          }
          console.warn('[SyncManager] Replay failed:', resp.status, serverData);
          continue;
        }
        await cache.removeQueueItem(item.queueId);
      } catch (err) {
        console.warn('[SyncManager] Replay error:', err);
        break;
      }
    }

    if (window.AppOffline) {
      window.AppOffline.syncAll().catch(() => {});
    }
  }

  function scheduleReplay() {
    clearTimeout(replayTimer);
    replayTimer = setTimeout(replayQueue, REPLAY_DELAY);
  }

  async function queueOrFetch(url, opts) {
    const method = (opts?.method || 'GET').toUpperCase();
    const isWrite = method !== 'GET' && method !== 'HEAD';

    if (!isOnline && isWrite) {
      const body = opts?.body ? JSON.parse(opts.body) : null;
      await window.AppOffline?.enqueue({
        url: url.replace(window.API_BASE || '/api', ''),
        method,
        body,
      });
      return { queued: true, message: 'Операция сохранена и будет выполнена при подключении' };
    }

    const resp = await fetch(url, opts);

    if (!isOnline || !resp.ok) {
      if (!isOnline && !isWrite) {
        throw new Error('offline');
      }
    }

    return resp;
  }

  function bindEvents() {
    window.addEventListener('online', () => setStatus(true));
    window.addEventListener('offline', () => setStatus(false));

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible' && navigator.onLine) {
        setStatus(true);
      }
    });
  }

  function init() {
    createIndicator();
    updateIndicator();
    bindEvents();
    if (isOnline) {
      window.AppOffline?.syncAll().catch(() => {});
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  const SyncManager = {
    get isOnline() { return isOnline; },
    setStatus,
    queueOrFetch,
    replayQueue,
    scheduleReplay,
  };

  window.AppSync = SyncManager;
})(window);

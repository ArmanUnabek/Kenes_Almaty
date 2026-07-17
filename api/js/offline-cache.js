/**
 * Офлайн-кеш на IndexedDB: письма, члены ОС, мероприятия.
 */
(function (window) {
  const DB_NAME = 'kenes-cache';
  const DB_VERSION = 1;

  const STORES = {
    incoming: 'letters-incoming',
    outgoing: 'letters-outgoing',
    members: 'members',
    events: 'events',
    queue: 'offline-queue',
  };

  let dbInstance = null;

  function openDB() {
    if (dbInstance) return Promise.resolve(dbInstance);
    return new Promise((resolve, reject) => {
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = (e) => {
        const db = e.target.result;
        if (!db.objectStoreNames.contains(STORES.incoming)) {
          db.createObjectStore(STORES.incoming, { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains(STORES.outgoing)) {
          db.createObjectStore(STORES.outgoing, { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains(STORES.members)) {
          db.createObjectStore(STORES.members, { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains(STORES.events)) {
          db.createObjectStore(STORES.events, { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains(STORES.queue)) {
          const store = db.createObjectStore(STORES.queue, {
            keyPath: 'queueId',
            autoIncrement: true,
          });
          store.createIndex('timestamp', 'timestamp');
        }
      };
      req.onsuccess = (e) => {
        dbInstance = e.target.result;
        resolve(dbInstance);
      };
      req.onerror = (e) => reject(e.target.error);
    });
  }

  async function putAll(storeName, items) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(storeName, 'readwrite');
      const store = tx.objectStore(storeName);
      store.clear();
      (items || []).forEach((item) => store.put(item));
      tx.oncomplete = () => resolve();
      tx.onerror = (e) => reject(e.target.error);
    });
  }

  async function getAll(storeName) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(storeName, 'readonly');
      const store = tx.objectStore(storeName);
      const req = store.getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = (e) => reject(e.target.error);
    });
  }

  async function enqueue(operation) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORES.queue, 'readwrite');
      const store = tx.objectStore(STORES.queue);
      store.add({ ...operation, timestamp: Date.now() });
      tx.oncomplete = () => resolve();
      tx.onerror = (e) => reject(e.target.error);
    });
  }

  async function getQueue() {
    return getAll(STORES.queue);
  }

  async function clearQueue() {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORES.queue, 'readwrite');
      tx.objectStore(STORES.queue).clear();
      tx.oncomplete = () => resolve();
      tx.onerror = (e) => reject(e.target.error);
    });
  }

  async function removeQueueItem(queueId) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORES.queue, 'readwrite');
      tx.objectStore(STORES.queue).delete(queueId);
      tx.oncomplete = () => resolve();
      tx.onerror = (e) => reject(e.target.error);
    });
  }

  function asList(data) {
    const list = Array.isArray(data) ? data : (data?.items ?? data?.data ?? []);
    return Array.isArray(list) ? list : Object.values(list || {});
  }

  async function fetchAndCache(url, storeName) {
    try {
      const resp = await fetch(url);
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const data = await resp.json().catch(() => []);
      const items = asList(data);
      await putAll(storeName, items);
      return items;
    } catch (err) {
      console.warn(`[OfflineCache] fetch failed for ${url}, using cache`, err);
      return getAll(storeName);
    }
  }

  async function fetchWithFallback(url, storeName) {
    try {
      const resp = await fetch(url);
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const data = await resp.json().catch(() => []);
      const items = asList(data);
      await putAll(storeName, items);
      return data;
    } catch (err) {
      console.warn(`[OfflineCache] network miss for ${url}, serving from cache`);
      return getAll(storeName);
    }
  }

  async function fetchAllPages(url, storeName) {
    const PAGE_SIZE = 500;
    let page = 1;
    let all  = [];
    try {
      while (true) {
        const sep  = url.includes('?') ? '&' : '?';
        const resp = await fetch(`${url}${sep}limit=${PAGE_SIZE}&page=${page}`);
        if (!resp.ok) break;
        const data  = await resp.json().catch(() => null);
        if (!data) break;
        const items = asList(data);
        if (!items.length) break;
        all = all.concat(items);
        const total = data?.pagination?.total ?? 0;
        if (!total || all.length >= total) break;
        page++;
      }
      await putAll(storeName, all);
    } catch (err) {
      console.warn(`[OfflineCache] paged fetch failed for ${url}, using cache`, err);
      return getAll(storeName);
    }
    return all;
  }

  async function syncAll() {
    const API = window.API_BASE || '/api';
    const results = await Promise.allSettled([
      fetchAllPages(`${API}/letters.php?type=incoming`, STORES.incoming),
      fetchAllPages(`${API}/letters.php?type=outgoing`, STORES.outgoing),
      fetchAllPages(`${API}/members.php`, STORES.members),
      fetchAndCache(`${API}/events.php?limit=500`, STORES.events),
    ]);
    return {
      incoming: results[0].status === 'fulfilled' ? results[0].value : [],
      outgoing: results[1].status === 'fulfilled' ? results[1].value : [],
      members: results[2].status === 'fulfilled' ? results[2].value : [],
      events: results[3].status === 'fulfilled' ? results[3].value : [],
    };
  }

  const OfflineCache = {
    openDB,
    putAll,
    getAll,
    enqueue,
    getQueue,
    clearQueue,
    removeQueueItem,
    fetchAndCache,
    fetchWithFallback,
    syncAll,
    STORES,
  };

  window.AppOffline = OfflineCache;
})(window);

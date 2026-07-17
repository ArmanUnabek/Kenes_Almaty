/**
 * PWA install UX: ловит beforeinstallprompt, показывает ненавязчивый баннер
 * «Установить приложение» и запускает нативный prompt() по клику.
 * Также обрабатывает deep-link ?tab=... из shortcuts манифеста.
 * Никаких inline/onclick — только addEventListener (nonce-CSP совместимо).
 */
(function (window) {
  'use strict';

  const document = window.document;
  const t = (key, fb) => window.AppI18n?.t(key, fb) ?? fb;

  window.AppI18n?.register?.({
    ru: {
      'pwa.install_title': 'Установить приложение',
      'pwa.install_text': 'Быстрый доступ с рабочего стола, работа офлайн.',
      'pwa.install_btn': 'Установить',
      'pwa.install_dismiss': 'Не сейчас',
    },
    kz: {
      'pwa.install_title': 'Қосымшаны орнату',
      'pwa.install_text': 'Жұмыс үстелінен жылдам қатынау, офлайн жұмыс.',
      'pwa.install_btn': 'Орнату',
      'pwa.install_dismiss': 'Қазір емес',
    },
  });

  let deferredPrompt = null;
  let banner = null;
  const DISMISS_KEY = 'pwa_install_dismissed';

  function isStandalone() {
    return window.matchMedia?.('(display-mode: standalone)').matches
      || window.navigator.standalone === true;
  }

  function removeBanner() {
    if (banner) {
      banner.remove();
      banner = null;
    }
  }

  function applyLabels() {
    if (!banner) return;
    banner.querySelector('.pwa-install__title').textContent = t('pwa.install_title', 'Установить приложение');
    banner.querySelector('.pwa-install__text').textContent = t('pwa.install_text', 'Быстрый доступ с рабочего стола, работа офлайн.');
    banner.querySelector('.pwa-install__accept').textContent = t('pwa.install_btn', 'Установить');
    banner.querySelector('.pwa-install__dismiss').textContent = t('pwa.install_dismiss', 'Не сейчас');
  }

  function buildBanner() {
    const el = document.createElement('div');
    el.className = 'pwa-install-banner';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-live', 'polite');

    const body = document.createElement('div');
    body.className = 'pwa-install__body';

    const title = document.createElement('div');
    title.className = 'pwa-install__title';
    const text = document.createElement('div');
    text.className = 'pwa-install__text';
    body.append(title, text);

    const actions = document.createElement('div');
    actions.className = 'pwa-install__actions';

    const accept = document.createElement('button');
    accept.type = 'button';
    accept.className = 'btn btn-primary btn-sm pwa-install__accept';

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'btn btn-link btn-sm pwa-install__dismiss';

    actions.append(accept, dismiss);
    el.append(body, actions);

    accept.addEventListener('click', doInstall);
    dismiss.addEventListener('click', () => {
      try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) { /* ignore */ }
      removeBanner();
    });

    return el;
  }

  function showBanner() {
    if (banner || isStandalone() || !deferredPrompt) return;
    try {
      if (localStorage.getItem(DISMISS_KEY) === '1') return;
    } catch (e) { /* ignore */ }
    banner = buildBanner();
    applyLabels();
    document.body.appendChild(banner);
    requestAnimationFrame(() => banner?.classList.add('pwa-install-banner--visible'));
  }

  async function doInstall() {
    if (!deferredPrompt) return;
    const promptEvent = deferredPrompt;
    deferredPrompt = null;
    removeBanner();
    try {
      promptEvent.prompt();
      await promptEvent.userChoice;
    } catch (e) { /* пользователь закрыл системный диалог */ }
  }

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', showBanner, { once: true });
    } else {
      showBanner();
    }
  });

  window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) { /* ignore */ }
    removeBanner();
  });

  window.addEventListener('app:langchange', applyLabels);

  // Deep-link из shortcuts манифеста: ?tab=incoming | calendar | ...
  // Кликаем по соответствующей вкладке Bootstrap (#tab-<name>).
  function handleTabDeepLink() {
    let tab;
    try {
      tab = new URLSearchParams(window.location.search).get('tab');
    } catch (e) { return; }
    if (!tab) return;
    const btn = document.getElementById('tab-' + tab);
    if (btn) btn.click();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', handleTabDeepLink, { once: true });
  } else {
    handleTabDeepLink();
  }
})(window);

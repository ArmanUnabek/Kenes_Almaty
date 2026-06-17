(function () {
    'use strict';

    const cfg = window.SITE_CONFIG || {};

    function applyConfig() {
        document.querySelectorAll('[data-site]').forEach((el) => {
            const key = el.getAttribute('data-site');
            if (cfg[key]) {
                el.textContent = cfg[key];
            }
        });
        document.querySelectorAll('[data-site-href]').forEach((el) => {
            const key = el.getAttribute('data-site-href');
            if (cfg[key]) {
                el.setAttribute('href', key === 'email' || key === 'dpoEmail' ? 'mailto:' + cfg[key] : cfg[key]);
            }
        });
    }

    function initLangToggle() {
        const buttons = document.querySelectorAll('[data-lang]');
        const blocks = document.querySelectorAll('[data-lang-block]');
        if (!buttons.length) return;

        function setLang(lang) {
            blocks.forEach((block) => {
                block.hidden = block.getAttribute('data-lang-block') !== lang;
            });
            buttons.forEach((btn) => {
                const active = btn.getAttribute('data-lang') === lang;
                btn.classList.toggle('active', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            try {
                localStorage.setItem('doc_lang', lang);
            } catch (_) { /* ignore */ }
        }

        buttons.forEach((btn) => {
            btn.addEventListener('click', () => setLang(btn.getAttribute('data-lang')));
        });

        let saved = 'ru';
        try {
            saved = localStorage.getItem('doc_lang') || 'ru';
        } catch (_) { /* ignore */ }
        setLang(saved === 'kk' ? 'kk' : 'ru');
    }

    document.addEventListener('DOMContentLoaded', () => {
        applyConfig();
        initLangToggle();
    });
})();

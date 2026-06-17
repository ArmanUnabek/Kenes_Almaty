/**
 * Локализация страницы входа RU / KZ
 */
(function () {
  const STORAGE_KEY = 'os_journal_lang';
  let lang = localStorage.getItem(STORAGE_KEY) || 'ru';

  const dict = {
    ru: {
      'page.title': 'Вход — Журнал ОС',
      'brand.title': 'Журнал Общественного Совета',
      'brand.subtitle': 'Система учёта входящих и исходящих писем',
      'form.title': 'Вход в систему',
      'form.username': 'Логин',
      'form.password': 'Пароль',
      'form.usernamePh': 'Введите логин',
      'form.passwordPh': 'Введите пароль',
      'form.remember': 'Запомнить меня',
      'form.consent': 'Я принимаю <a href="/legal/terms.html" target="_blank" rel="noopener">Пользовательское соглашение</a> и <a href="/legal/privacy.html" target="_blank" rel="noopener">Политику обработки персональных данных</a>',
      'form.submit': 'Войти',
      'footer.help': 'Справка',
      'footer.faq': 'FAQ',
      'footer.privacy': 'Политика ПДн',
      'footer.terms': 'Соглашение',
      'lang.switch': 'Қазақша',
      'err.fillAll': 'Пожалуйста, заполните все поля',
      'err.consent': 'Необходимо принять соглашение и политику обработки персональных данных',
      'err.login': 'Неверный логин или пароль',
      'err.network': 'Ошибка подключения к серверу. Попробуйте позже.',
      'info.success': 'Вход выполнен успешно! Перенаправление...',
    },
    kz: {
      'page.title': 'Кіру — Қоғамдық кеңес журналы',
      'brand.title': 'Қоғамдық кеңес журналы',
      'brand.subtitle': 'Кіріс және шығыс хаттарды есепке алу жүйесі',
      'form.title': 'Жүйеге кіру',
      'form.username': 'Логин',
      'form.password': 'Құпия сөз',
      'form.usernamePh': 'Логинді енгізіңіз',
      'form.passwordPh': 'Құпия сөзді енгізіңіз',
      'form.remember': 'Мені есте сақтау',
      'form.consent': 'Мен <a href="/legal/terms.html" target="_blank" rel="noopener">Пайдаланушы келісімін</a> және <a href="/legal/privacy.html" target="_blank" rel="noopener">Жеке деректерді өңдеу саясатын</a> қабылдаймын',
      'form.submit': 'Кіру',
      'footer.help': 'Анықтама',
      'footer.faq': 'FAQ',
      'footer.privacy': 'ЖД саясаты',
      'footer.terms': 'Келісім',
      'lang.switch': 'Русский',
      'err.fillAll': 'Барлық өрістерді толтырыңыз',
      'err.consent': 'Келісім мен жеке деректер саясатын қабылдау қажет',
      'err.login': 'Логин немесе құпия сөз дұрыс емес',
      'err.network': 'Серверге қосылу қатесі. Кейінірек көріңіз.',
      'info.success': 'Сәтті кірдіңіз! Бағыттау...',
    },
  };

  function t(key) {
    return dict[lang]?.[key] ?? dict.ru[key] ?? key;
  }

  function apply() {
    document.documentElement.lang = lang === 'kz' ? 'kk' : 'ru';
    document.title = t('page.title');
    const map = {
      loginBrandTitle: 'brand.title',
      loginBrandSubtitle: 'brand.subtitle',
      loginFormTitle: 'form.title',
      loginUsernameLabel: 'form.username',
      loginPasswordLabel: 'form.password',
      loginRememberLabel: 'form.remember',
      loginSubmitText: 'form.submit',
      loginLangBtn: 'lang.switch',
    };
    Object.entries(map).forEach(([id, key]) => {
      const el = document.getElementById(id);
      if (el) el.textContent = t(key);
    });
    const consent = document.getElementById('loginConsentLabel');
    if (consent) consent.innerHTML = t('form.consent');
    const username = document.getElementById('username');
    const password = document.getElementById('password');
    if (username) username.placeholder = t('form.usernamePh');
    if (password) password.placeholder = t('form.passwordPh');
    document.querySelectorAll('[data-login-i18n]').forEach((el) => {
      el.textContent = t(el.getAttribute('data-login-i18n'));
    });
  }

  function toggleLang() {
    lang = lang === 'ru' ? 'kz' : 'ru';
    localStorage.setItem(STORAGE_KEY, lang);
    apply();
  }

  document.addEventListener('DOMContentLoaded', () => {
    apply();
    document.getElementById('loginLangBtn')?.addEventListener('click', toggleLang);
  });

  window.LoginI18n = { t, apply, toggleLang, get lang() { return lang; } };
})();

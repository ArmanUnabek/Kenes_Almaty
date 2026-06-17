/**
 * Двуязычный интерфейс RU / KZ.
 */
(function (window) {
  const STORAGE_KEY = 'os_journal_lang';

  const dict = {
    ru: {
      'nav.dashboard': 'Статистика',
      'nav.incoming': 'Входящий журнал',
      'nav.outgoing': 'Исходящий журнал',
      'nav.members': 'Члены ОС',
      'nav.commissions': 'Комиссии',
      'nav.kpi': 'KPI',
      'nav.events': 'Мероприятия',
      'nav.admin': 'Админ-панель',
      'topbar.search': 'Поиск',
      'topbar.notify': 'Уведомления',
      'topbar.export_csv': 'CSV',
      'topbar.export_pdf': 'PDF',
      'topbar.export_json': 'JSON',
      'topbar.import': 'Импорт',
      'topbar.logout': 'Выход',
      'lang.switch': 'Қазақша',
      'members.add': 'Добавить члена ОС',
      'members.edit': 'Редактировать члена ОС',
      'members.empty': 'Нет членов для отображения',
      'members.all_commissions': 'Все комиссии',
      'members.default_role': 'Член совета',
      'members.photo': 'Фото',
      'members.photo_ok': 'Фото успешно загружено',
      'members.delete_confirm': 'Удалить',
      'members.deleted': 'Член ОС удалён',
      'members.updated': 'Член ОС обновлён',
      'members.created': 'Член ОС добавлен',
      'commissions.add': 'Добавить комиссию',
      'commissions.edit': 'Редактировать комиссию',
      'commissions.empty': 'Комиссии не найдены',
      'commissions.default_desc': 'Комиссия Общественного Совета',
      'commissions.delete_confirm': 'Удалить',
      'commissions.deleted': 'Комиссия удалена',
      'commissions.updated': 'Комиссия обновлена',
      'commissions.created': 'Комиссия добавлена',
      'action.edit': 'Изменить',
      'notify.title': 'Уведомления',
      'notify.tab.deadlines': 'Сроки',
      'notify.tab.email': 'Email',
      'notify.email.send': 'Отправить',
      'notify.email.to': 'Email получателя',
      'notify.email.subject': 'Тема',
      'notify.email.body': 'Текст письма',
      'notify.email.queue': 'Очередь отправки',
      'notify.email.empty': 'Очередь пуста',
    },
    kz: {
      'nav.dashboard': 'Статистика',
      'nav.incoming': 'Кіріс журналы',
      'nav.outgoing': 'Шығыс журналы',
      'nav.members': 'ОС мүшелері',
      'nav.commissions': 'Комиссиялар',
      'nav.kpi': 'KPI',
      'nav.events': 'Іс-шаралар',
      'nav.admin': 'Админ-панель',
      'topbar.search': 'Іздеу',
      'topbar.notify': 'Хабарламалар',
      'topbar.export_csv': 'CSV',
      'topbar.export_pdf': 'PDF',
      'topbar.export_json': 'JSON',
      'topbar.import': 'Импорт',
      'topbar.logout': 'Шығу',
      'lang.switch': 'Русский',
      'members.add': 'ОС мүшесін қосу',
      'members.edit': 'ОС мүшесін өңдеу',
      'members.empty': 'Көрсету үшін мүшелер жоқ',
      'members.all_commissions': 'Барлық комиссиялар',
      'members.default_role': 'Кеңес мүшесі',
      'members.photo': 'Фото',
      'members.photo_ok': 'Фото сәтті жүктелді',
      'members.delete_confirm': 'Жою',
      'members.deleted': 'ОС мүшесі жойылды',
      'members.updated': 'ОС мүшесі жаңартылды',
      'members.created': 'ОС мүшесі қосылды',
      'commissions.add': 'Комиссия қосу',
      'commissions.edit': 'Комиссияны өңдеу',
      'commissions.empty': 'Комиссиялар табылмады',
      'commissions.default_desc': 'Қоғамдық Кеңес комиссиясы',
      'commissions.delete_confirm': 'Жою',
      'commissions.deleted': 'Комиссия жойылды',
      'commissions.updated': 'Комиссия жаңартылды',
      'commissions.created': 'Комиссия қосылды',
      'action.edit': 'Өңдеу',
      'notify.title': 'Хабарламалар',
      'notify.tab.deadlines': 'Мерзімдер',
      'notify.tab.email': 'Email',
      'notify.email.send': 'Жіберу',
      'notify.email.to': 'Алушы email',
      'notify.email.subject': 'Тақырып',
      'notify.email.body': 'Хат мәтіні',
      'notify.email.queue': 'Жіберу кезегі',
      'notify.email.empty': 'Кезек бос',
    },
  };

  let lang = localStorage.getItem(STORAGE_KEY) || 'ru';
  if (!dict[lang]) lang = 'ru';

  function t(key, fallback) {
    return dict[lang]?.[key] ?? dict.ru[key] ?? fallback ?? key;
  }

  function setLang(next) {
    lang = dict[next] ? next : 'ru';
    localStorage.setItem(STORAGE_KEY, lang);
    apply();
    document.documentElement.lang = lang === 'kz' ? 'kk' : 'ru';
  }

  function toggleLang() {
    setLang(lang === 'ru' ? 'kz' : 'ru');
  }

  function apply() {
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      const key = el.getAttribute('data-i18n');
      const val = t(key, el.textContent);
      if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
        if (el.hasAttribute('placeholder')) el.placeholder = val;
      } else if (el.classList.contains('btn-label') || el.childNodes.length <= 1) {
        el.textContent = val;
      } else {
        const label = el.querySelector('.btn-label, .sidebar-label');
        if (label) label.textContent = val;
        else el.textContent = val;
      }
    });
    const switchBtn = document.getElementById('langToggleBtn');
    if (switchBtn) switchBtn.textContent = t('lang.switch', 'Қазақша');
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('langToggleBtn')?.addEventListener('click', toggleLang);
    apply();
  });

  window.AppI18n = { t, setLang, toggleLang, apply, get lang() { return lang; } };
})(window);

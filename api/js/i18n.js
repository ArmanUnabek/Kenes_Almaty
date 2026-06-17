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
      'notify.email.moderator_hint': 'Модератор: только email пользователей/членов ОС вашего региона или домены из NOTIFY_ALLOWED_DOMAINS.',
      'page.dashboard': 'Статистика обращений',
      'page.dashboard_sub': 'Ключевые показатели и динамика обращений',
      'page.incoming': 'Входящий журнал',
      'page.outgoing': 'Исходящий журнал',
      'page.outgoing_sub': 'Ответы Общественного Совета',
      'page.members': 'Члены Общественного Совета',
      'page.commissions': 'Комиссии',
      'page.commissions_sub': 'Структура Общественного Совета',
      'page.kpi': 'KPI',
      'page.kpi_sub': 'Показатели работы членов и комиссий',
      'page.events': 'Мероприятия',
      'page.events_sub': 'Учёт участия членов ОС',
      'letters.count': 'писем',
      'letters.deadline': 'срок ответа 15 рабочих дней',
      'members.count': 'членов',
      'commissions.count': 'комиссий',
      'ctx.add_incoming': '+ Добавить письмо',
      'ctx.pending': 'Без ответа',
      'ctx.add_outgoing': '+ Ответ ОС',
      'ctx.add_event': '+ Мероприятие',
      'events.empty': 'Нет мероприятий',
      'events.saved': 'Мероприятие успешно сохранено',
      'events.save_error': 'Не удалось сохранить мероприятие',
      'events.deleted': 'Мероприятие удалено',
      'events.delete_confirm': 'Удалить мероприятие?',
      'events.delete_error': 'Не удалось удалить',
      'events.load_error': 'Не удалось загрузить',
      'events.present': 'Присутствовали',
      'events.absent': 'Отсутствовали',
      'events.view_att': 'Участники',
      'events.kpi_metric': 'Метрика',
      'events.kpi_number': 'Число',
      'events.kpi_text': 'Текст',
      'events.no_commission': 'Без комиссии',
      'action.delete': 'Удалить',
      'error.no_permission': 'Недостаточно прав',
      'error.save_failed': 'Не удалось сохранить',
      'dash.incoming': 'Входящих',
      'dash.outgoing': 'Исходящих',
      'dash.closed': 'Закрыто ответом',
      'dash.avg_days': 'Средний срок ответа',
      'dash.dynamics': 'Динамика обращений',
      'dash.period': 'Период',
      'dash.working_days': 'рабочих дней',
      'dash.all_time': 'за всё время',
      'dash.os_replies': 'ответы ОС',
      'common.loading': 'Загрузка...',
      'common.cancel': 'Отмена',
      'common.close': 'Закрыть',
      'common.save': 'Сохранить',
      'common.confirm_delete': 'Вы уверены, что хотите удалить этот элемент?',
      'common.logout': 'Выйти',
      'common.guest': 'Гость',
      'common.more': 'Ещё',
      'search.title': 'Поиск по журналу',
      'search.placeholder': 'Введите запрос...',
      'filter.all': 'Все',
      'filter.year': 'Год',
      'filter.month': 'Месяц',
      'filter.status': 'Статус',
      'status.pending': 'Без ответа',
      'status.overdue': 'Просрочено',
      'status.due_soon': 'Скоро срок',
      'letters.incoming_add': 'Новое входящее',
      'letters.outgoing_add': 'Новый ответ ОС',
      'letters.save': 'Сохранить',
      'letters.reset': 'Сбросить',
      'mobile.incoming': 'Входящие',
      'mobile.outgoing': 'Исходящие',
      'mobile.members': 'Члены',
      'mobile.menu': 'Ещё',
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
      'notify.email.moderator_hint': 'Модератор: тек аймақтағы пайдаланушылар/ОС мүшелері email немесе NOTIFY_ALLOWED_DOMAINS домендері.',
      'page.dashboard': 'Статистика',
      'page.dashboard_sub': 'Негізгі көрсеткіштер мен динамика',
      'page.incoming': 'Кіріс журналы',
      'page.outgoing': 'Шығыс журналы',
      'page.outgoing_sub': 'Қоғамдық Кеңес жауаптары',
      'page.members': 'ОС мүшелері',
      'page.commissions': 'Комиссиялар',
      'page.commissions_sub': 'Қоғамдық Кеңес құрылымы',
      'page.kpi': 'KPI',
      'page.kpi_sub': 'Мүшелер мен комиссиялар көрсеткіштері',
      'page.events': 'Іс-шаралар',
      'page.events_sub': 'ОС мүшелерінің қатысуы',
      'letters.count': 'хат',
      'letters.deadline': 'жауап мерзімі 15 жұмыс күні',
      'members.count': 'мүше',
      'commissions.count': 'комиссия',
      'ctx.add_incoming': '+ Хат қосу',
      'ctx.pending': 'Жауапсыз',
      'ctx.add_outgoing': '+ ОС жауабы',
      'ctx.add_event': '+ Іс-шара',
      'events.empty': 'Іс-шаралар жоқ',
      'events.saved': 'Іс-шара сәтті сақталды',
      'events.save_error': 'Іс-шара сақталмады',
      'events.deleted': 'Іс-шара жойылды',
      'events.delete_confirm': 'Іс-шарады жою керек пе?',
      'events.delete_error': 'Жою сәтсіз',
      'events.load_error': 'Жүктеу сәтсіз',
      'events.present': 'Қатысты',
      'events.absent': 'Қатыспады',
      'events.view_att': 'Қатысушылар',
      'events.kpi_metric': 'Метрика',
      'events.kpi_number': 'Сан',
      'events.kpi_text': 'Мәтін',
      'events.no_commission': 'Комиссиясыз',
      'action.delete': 'Жою',
      'error.no_permission': 'Құқық жеткіліксіз',
      'error.save_failed': 'Сақтау сәтсіз',
      'dash.incoming': 'Кіріс',
      'dash.outgoing': 'Шығыс',
      'dash.closed': 'Жауаппен жабылды',
      'dash.avg_days': 'Орташа жауап мерзімі',
      'dash.dynamics': 'Өтініштер динамикасы',
      'dash.period': 'Кезең',
      'dash.working_days': 'жұмыс күні',
      'dash.all_time': 'барлық уақыт',
      'dash.os_replies': 'ОС жауаптары',
      'common.loading': 'Жүктелуде...',
      'common.cancel': 'Бас тарту',
      'common.close': 'Жабу',
      'common.save': 'Сақтау',
      'common.confirm_delete': 'Бұл элементті жоюға сенімдісіз бе?',
      'common.logout': 'Шығу',
      'common.guest': 'Қонақ',
      'common.more': 'Тағы',
      'search.title': 'Журнал бойынша іздеу',
      'search.placeholder': 'Сұрау енгізіңіз...',
      'filter.all': 'Барлығы',
      'filter.year': 'Жыл',
      'filter.month': 'Ай',
      'filter.status': 'Күйі',
      'status.pending': 'Жауапсыз',
      'status.overdue': 'Мерзімі өткен',
      'status.due_soon': 'Мерзімі жақында',
      'letters.incoming_add': 'Жаңа кіріс',
      'letters.outgoing_add': 'Жаңа ОС жауабы',
      'letters.save': 'Сақтау',
      'letters.reset': 'Тазалау',
      'mobile.incoming': 'Кіріс',
      'mobile.outgoing': 'Шығыс',
      'mobile.members': 'Мүшелер',
      'mobile.menu': 'Тағы',
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
    window.dispatchEvent(new CustomEvent('app:langchange'));
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
    document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
      const key = el.getAttribute('data-i18n-placeholder');
      el.placeholder = t(key, el.placeholder);
    });
    document.querySelectorAll('[data-i18n-title]').forEach((el) => {
      const key = el.getAttribute('data-i18n-title');
      el.title = t(key, el.title);
    });
    const switchBtn = document.getElementById('langToggleBtn');
    if (switchBtn) switchBtn.textContent = t('lang.switch', 'Қазақша');
    const confirmMsg = document.getElementById('confirmDeleteMessage');
    if (confirmMsg && !confirmMsg.dataset.i18nCustom) {
      confirmMsg.textContent = t('common.confirm_delete', confirmMsg.textContent);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('langToggleBtn')?.addEventListener('click', toggleLang);
    apply();
  });

  window.AppI18n = { t, setLang, toggleLang, apply, get lang() { return lang; } };
})(window);

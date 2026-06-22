/**
 * Admin panel i18n (RU / KK)
 */
const AdminI18n = (() => {
  const STORAGE_KEY = 'os_journal_lang';
  let lang = localStorage.getItem(STORAGE_KEY) || 'ru';

  const dict = {
    ru: {
      'admin.title': 'Админ-панель',
      'admin.subtitle': 'Управление системой',
      'nav.dashboard': 'Сводка',
      'nav.regions': 'Регионы',
      'nav.users': 'Пользователи',
      'nav.audit': 'Аудит',
      'nav.system': 'Система',
      'common.journal': 'Журнал',
      'footer.help': 'Справка',
      'footer.faq': 'FAQ',
      'footer.privacy': 'Политика ПДн',
      'footer.terms': 'Соглашение',
      'footer.support': 'Поддержка',
      'common.logout': 'Выход',
      'common.save': 'Сохранить',
      'common.cancel': 'Отмена',
      'common.refresh': 'Обновить',
      'common.search': 'Поиск…',
      'common.all': 'Все',
      'common.yes': 'Да',
      'common.no': 'Нет',
      'common.active': 'Активен',
      'common.inactive': 'Неактивен',
      'common.edit': 'Изменить',
      'common.back': 'Назад',
      'common.next': 'Вперёд',
      'common.loading': 'Загрузка…',
      'common.noData': 'Нет данных',
      'common.error': 'Ошибка',
      'common.success': 'Готово',
      'common.confirm': 'Подтвердите действие',
      'region.add': 'Добавить регион',
      'region.nameRu': 'Название (RU)',
      'region.nameKz': 'Название (KZ)',
      'region.code': 'Код',
      'region.create': 'Создать регион',
      'region.edit': 'Редактировать регион',
      'region.bootstrap': 'Инициализация региона',
      'region.members': 'Членов',
      'region.commissions': 'Комиссий',
      'region.incoming': 'Входящие',
      'region.outgoing': 'Исходящие',
      'region.openJournal': 'Открыть журнал',
      'region.init': 'Инициализировать',
      'region.export': 'Экспорт',
      'region.deactivate': 'Деактивировать',
      'region.activate': 'Активировать',
      'region.seqIncoming': 'Базовый № входящих',
      'region.seqOutgoing': 'Базовый № исходящих',
      'region.copyCommissions': 'Скопировать комиссии из шаблона',
      'region.template': 'Шаблонный регион',
      'region.created': 'Регион создан',
      'region.updated': 'Регион обновлён',
      'region.bootstrapped': 'Регион инициализирован',
      'region.deactivateConfirm': 'Деактивировать регион',
      'user.create': 'Создать пользователя',
      'user.list': 'Пользователи системы',
      'user.username': 'Логин',
      'user.email': 'Email',
      'user.fullName': 'ФИО',
      'user.password': 'Пароль',
      'user.newPassword': 'Новый пароль',
      'user.role': 'Роль',
      'user.region': 'Регион',
      'user.noRegion': '— без региона (админ) —',
      'user.edit': 'Редактировать пользователя',
      'user.deactivate': 'Деактивировать',
      'user.reactivate': 'Активировать',
      'user.created': 'Пользователь создан',
      'user.updated': 'Пользователь обновлён',
      'user.deactivated': 'Пользователь деактивирован',
      'user.reactivated': 'Пользователь активирован',
      'user.deactivateConfirm': 'Деактивировать пользователя?',
      'user.filterRole': 'Роль',
      'user.filterStatus': 'Статус',
      'user.lastLogin': 'Последний вход',
      'role.admin': 'Супер-админ',
      'role.moderator': 'Модератор',
      'role.viewer': 'Наблюдатель',
      'audit.title': 'Журнал аудита',
      'audit.allRegions': 'Все регионы',
      'audit.securityOnly': 'Только экспорт/скачивания',
      'audit.exportCsv': 'Экспорт CSV',
      'audit.date': 'Дата',
      'audit.user': 'Пользователь',
      'audit.table': 'Таблица',
      'audit.operation': 'Операция',
      'audit.recordId': 'ID записи',
      'audit.ip': 'IP',
      'audit.details': 'Детали аудита',
      'audit.was': 'Было',
      'audit.became': 'Стало',
      'audit.pageInfo': 'Страница {page} из {pages} · всего {total}',
      'dashboard.regions': 'Регионы',
      'dashboard.users': 'Пользователи',
      'dashboard.members': 'Члены ОС',
      'dashboard.letters': 'Письма',
      'dashboard.audit24h': 'Аудит за 24 ч',
      'dashboard.securityEvents': 'События безопасности',
      'dashboard.emailQueue': 'Очередь email',
      'dashboard.byRegion': 'По регионам',
      'system.title': 'Состояние системы',
      'system.health': 'Проверка здоровья',
      'system.database': 'База данных',
      'system.uploads': 'Каталог загрузок',
      'system.emailQueue': 'Очередь писем',
      'system.pending': 'В очереди',
      'system.sent': 'Отправлено',
      'system.failed': 'Ошибки',
      'system.checkHealth': 'Проверить',
      'system.statusOk': 'Система в норме',
      'system.statusDegraded': 'Есть проблемы',
      'system.retry': 'Повторить',
      'system.retryQueued': 'Письмо поставлено в очередь повторно',
      'header.activeRegion': 'Активный регион',
      'header.welcome': 'Добро пожаловать',
    },
    kk: {
      'admin.title': 'Әкімші панелі',
      'admin.subtitle': 'Жүйені басқару',
      'nav.dashboard': 'Қорытынды',
      'nav.regions': 'Аймақтар',
      'nav.users': 'Пайдаланушылар',
      'nav.audit': 'Аудит',
      'nav.system': 'Жүйе',
      'common.journal': 'Журнал',
      'footer.help': 'Анықтама',
      'footer.faq': 'FAQ',
      'footer.privacy': 'ЖД саясаты',
      'footer.terms': 'Келісім',
      'footer.support': 'Қолдау',
      'common.logout': 'Шығу',
      'common.save': 'Сақтау',
      'common.cancel': 'Болдырмау',
      'common.refresh': 'Жаңарту',
      'common.search': 'Іздеу…',
      'common.all': 'Барлығы',
      'common.yes': 'Иә',
      'common.no': 'Жоқ',
      'common.active': 'Белсенді',
      'common.inactive': 'Белсенді емес',
      'common.edit': 'Өзгерту',
      'common.back': 'Артқа',
      'common.next': 'Алға',
      'common.loading': 'Жүктелуде…',
      'common.noData': 'Деректер жоқ',
      'common.error': 'Қате',
      'common.success': 'Дайын',
      'common.confirm': 'Әрекетті растаңыз',
      'region.add': 'Аймақ қосу',
      'region.nameRu': 'Атауы (RU)',
      'region.nameKz': 'Атауы (KZ)',
      'region.code': 'Код',
      'region.create': 'Аймақ жасау',
      'region.edit': 'Аймақты өңдеу',
      'region.bootstrap': 'Аймақты инициализациялау',
      'region.members': 'Мүшелер',
      'region.commissions': 'Комиссиялар',
      'region.incoming': 'Кіріс',
      'region.outgoing': 'Шығыс',
      'region.openJournal': 'Журналды ашу',
      'region.init': 'Инициализациялау',
      'region.export': 'Экспорт',
      'region.deactivate': 'Өшіру',
      'region.activate': 'Қосу',
      'region.seqIncoming': 'Кіріс № базасы',
      'region.seqOutgoing': 'Шығыс № базасы',
      'region.copyCommissions': 'Үлгіден комиссияларды көшіру',
      'region.template': 'Үлгі аймақ',
      'region.created': 'Аймақ жасалды',
      'region.updated': 'Аймақ жаңартылды',
      'region.bootstrapped': 'Аймақ инициализацияланды',
      'region.deactivateConfirm': 'Аймақты өшіру',
      'user.create': 'Пайдаланушы жасау',
      'user.list': 'Жүйе пайдаланушылары',
      'user.username': 'Логин',
      'user.email': 'Email',
      'user.fullName': 'Аты-жөні',
      'user.password': 'Құпия сөз',
      'user.newPassword': 'Жаңа құпия сөз',
      'user.role': 'Рөл',
      'user.region': 'Аймақ',
      'user.noRegion': '— аймақсыз (әкімші) —',
      'user.edit': 'Пайдаланушыны өңдеу',
      'user.deactivate': 'Өшіру',
      'user.reactivate': 'Қосу',
      'user.created': 'Пайдаланушы жасалды',
      'user.updated': 'Пайдаланушы жаңартылды',
      'user.deactivated': 'Пайдаланушы өшірілді',
      'user.reactivated': 'Пайдаланушы қосылды',
      'user.deactivateConfirm': 'Пайдаланушыны өшіру керек пе?',
      'user.filterRole': 'Рөл',
      'user.filterStatus': 'Күйі',
      'user.lastLogin': 'Соңғы кіру',
      'role.admin': 'Супер-әкімші',
      'role.moderator': 'Модератор',
      'role.viewer': 'Бақылаушы',
      'audit.title': 'Аудит журналы',
      'audit.allRegions': 'Барлық аймақтар',
      'audit.securityOnly': 'Тек экспорт/жүктеу',
      'audit.exportCsv': 'CSV экспорт',
      'audit.date': 'Күні',
      'audit.user': 'Пайдаланушы',
      'audit.table': 'Кесте',
      'audit.operation': 'Операция',
      'audit.recordId': 'Жазба ID',
      'audit.ip': 'IP',
      'audit.details': 'Аудит мәліметтері',
      'audit.was': 'Болды',
      'audit.became': 'Болды',
      'audit.pageInfo': '{page} / {pages} бет · барлығы {total}',
      'dashboard.regions': 'Аймақтар',
      'dashboard.users': 'Пайдаланушылар',
      'dashboard.members': 'Кеңес мүшелері',
      'dashboard.letters': 'Хаттар',
      'dashboard.audit24h': '24 сағ. аудит',
      'dashboard.securityEvents': 'Қауіпсіздік оқиғалары',
      'dashboard.emailQueue': 'Email кезегі',
      'dashboard.byRegion': 'Аймақтар бойынша',
      'system.title': 'Жүйе күйі',
      'system.health': 'Денсаулық тексеруі',
      'system.database': 'Дерекқор',
      'system.uploads': 'Жүктеу каталогы',
      'system.emailQueue': 'Хат кезегі',
      'system.pending': 'Кезекте',
      'system.sent': 'Жіберілді',
      'system.failed': 'Қателер',
      'system.checkHealth': 'Тексеру',
      'system.statusOk': 'Жүйе қалыпты',
      'system.statusDegraded': 'Мәселелер бар',
      'system.retry': 'Қайталау',
      'system.retryQueued': 'Хат қайта кезекке қойылды',
      'header.activeRegion': 'Белсенді аймақ',
      'header.welcome': 'Қош келдіңіз',
    },
  };

  function t(key, vars = {}) {
    let text = (dict[lang] && dict[lang][key]) || dict.ru[key] || key;
    Object.entries(vars).forEach(([k, v]) => {
      text = text.replace(new RegExp(`\\{${k}\\}`, 'g'), String(v));
    });
    return text;
  }

  function getLang() {
    return lang;
  }

  function setLang(next) {
    if (!dict[next]) return;
    lang = next;
    localStorage.setItem(STORAGE_KEY, lang);
    document.documentElement.lang = lang === 'kk' ? 'kk' : 'ru';
    apply();
  }

  function apply() {
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      const key = el.getAttribute('data-i18n');
      const attr = el.getAttribute('data-i18n-attr');
      const text = t(key);
      if (attr) {
        el.setAttribute(attr, text);
      } else {
        el.textContent = text;
      }
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
      el.placeholder = t(el.getAttribute('data-i18n-placeholder'));
    });
    document.querySelectorAll('.lang-toggle [data-lang]').forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.lang === lang);
    });
  }

  function regionName(region) {
    if (!region) return '—';
    return lang === 'kk' && region.name_kz ? region.name_kz : (region.name_ru || region.name_kz || '—');
  }

  function roleLabel(role) {
    return t(`role.${role}`) || role;
  }

  return { t, getLang, setLang, apply, regionName, roleLabel };
})();

window.AdminI18n = AdminI18n;

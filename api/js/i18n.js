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
      'common.search': 'Поиск…',
      'common.add': 'Добавить',
      'common.clear': 'Очистить',
      'common.yes': 'Да',
      'common.no': 'Нет',
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
      'letters.incoming_saved': 'Входящее письмо успешно сохранено',
      'letters.outgoing_saved': 'Исходящее письмо успешно сохранено',
      'letters.save_incoming_error': 'Не удалось сохранить входящее письмо',
      'letters.save_outgoing_error': 'Не удалось сохранить исходящее письмо',
      'letters.linked_reply': 'Ответ',
      'letters.type_incoming': 'Входящее',
      'letters.type_outgoing': 'Исходящее',
      'letters.add_recipient_warn': 'Добавьте хотя бы одного адресата или укажите организацию',
      'letters.org_required': 'Укажите организацию получателя',
      'letters.linked_ok': 'Исходящее письмо успешно привязано',
      'mobile.incoming': 'Входящие',
      'mobile.outgoing': 'Исходящие',
      'mobile.members': 'Члены',
      'mobile.menu': 'Ещё',
      'translate.empty': 'Сначала заполните поле на русском',
      'translate.done': 'Перевод готов',
      'search.enter_query': 'Введите запрос для поиска',
      'search.nothing_found': 'Ничего не найдено',
      'search.searching': 'Поиск...',
      'search.error': 'Ошибка поиска',
      'search.source.incoming': 'Входящее',
      'search.source.outgoing': 'Исходящее',
      'search.source.member': 'Член ОС',
      'period.quarter_fmt': 'Квартал {q} {y}',
      'period.year_fmt': 'Год {y}',
      'dash.open_journal': 'Открыть журнал',
      'dash.letters_no_reply': 'писем без ответа',
      'dash.overdue_badge': 'просрочено',
      'dash.due_soon_badge': 'скоро срок',
      'dash.no_commission': 'Без комиссии',
      'dash.no_data': 'Нет данных',
      'dash.chairman': 'Председатель ОС',
      'dash.members_count': 'членов',
      'dash.no_commission_data': 'Нет данных по комиссиям',
      'dash.incoming_count': 'Входящих',
      'dash.outgoing_count': 'Исходящих',
      'dash.letters_count': 'писем',
      'dash.for_month': 'За {label}',
      'dash.outgoing_pct': '{pct}% от входящих',
      'dash.scans_pct': '{pct}% от всех писем',
      'dash.org_unknown': '(не указано)',
      'dash.chart_letters': 'Письма',
      'dash.chart_incoming_gov': 'Входящие письма от гос. органов (ҚК)',
      'dash.chart_os_replies': 'Ответы от ОС',
      'dash.chart_outgoing_os': 'Исходящие письма от ОС',
      'dash.chart_gov_replies': 'Ответы от гос органов (ҚК)',
      'dash.enh.no_commissions': 'Нет данных по комиссиям',
      'dash.enh.commission': 'Комиссия',
      'dash.enh.letters_people': '{letters} писем · {people} чел.',
      'dash.enh.scans_pct_letters': '{pct}% писем со сканами',
      'dash.enh.scans_pct': '{pct}% со сканами',
      'dash.enh.photo_pct': '{pct}% с фото',
      'dash.enh.trend_days': '{sign}{pct}% за 30 дней',
      'notify.dead.incoming_prefix': 'Вх.',
      'notify.dead.pending': 'Без ответа',
      'notify.dead.overdue': 'Просрочено',
      'notify.dead.due_soon': 'Скоро срок',
      'notify.dead.th_reg': 'Рег. №',
      'notify.dead.th_date': 'Дата',
      'notify.dead.th_from': 'От кого',
      'notify.dead.th_deadline': 'Срок',
      'notify.dead.th_days': 'Дн.',
      'notify.dead.th_responsible': 'Ответственные',
      'notify.dead.empty': 'Нет элементов',
      'notify.dead.hint': '15 рабочих дней от даты входящего.',
      'notify.email.default_subject': 'Уведомление ОС',
      'notify.email.no_access': 'Отправка email доступна модераторам и админам.',
      'notify.email.th_date': 'Дата',
      'notify.email.th_to': 'Кому',
      'notify.email.th_subject': 'Тема',
      'notify.email.th_status': 'Статус',
      'notify.send_error': 'Ошибка отправки',
      'letters.load_edit_error': 'Не удалось загрузить письмо для редактирования',
      'letters.load_error': 'Не удалось загрузить письмо',
      'letters.recipients_load_error': 'Не удалось загрузить адресатов',
      'letters.link_error': 'Не удалось привязать исходящее письмо',
      'letters.delete_incoming_error': 'Не удалось удалить входящее письмо',
      'letters.delete_outgoing_error': 'Не удалось удалить исходящее письмо',
      'letters.file_error': 'Ошибка при обработке файла {name}',
      'letters.delete_incoming_ok': 'Входящее письмо успешно удалено',
      'letters.delete_incoming_confirm': 'Удалить входящее письмо?',
      'letters.delete_outgoing_ok': 'Исходящее письмо успешно удалено',
      'letters.delete_outgoing_confirm': 'Удалить исходящее письмо?',
      'letters.processing_files': 'Обработка файлов...',
      'letters.selected_count': '{n} выбрано',
      'letters.archive_confirm': 'Переместить {n} писем(а) в архив?',
      'letters.archived_ok': 'Архивировано: {n}',
      'letters.delete_comment_confirm': 'Удалить комментарий?',
      'letters.restore_confirm': 'Восстановить письмо из архива?',
      'export.admin_only': 'Экспорт данных доступен только администратору',
      'export.done': 'Экспорт выполнен. Операция записана в журнал аудита.',
      'import.admin_only': 'Импорт данных доступен только администратору',
      'import.csv_role_error': 'Импорт CSV доступен только администратору или модератору',
      'import.confirm': 'Импорт добавит письма через API. Продолжить?',
      'import.done': 'Импорт завершён: {ok} записей',
      'import.errors_suffix': ', ошибок: {fail}',
      'import.json_error': 'Не удалось импортировать JSON',
      'footer.copyright_bin': 'БСН',
    },
    kz: {
      'nav.dashboard': 'Статистика',
      'nav.incoming': 'Кіріс журналы',
      'nav.outgoing': 'Шығыс журналы',
      'nav.members': 'Кеңес мүшелері',
      'common.search': 'Іздеу…',
      'common.add': 'Қосу',
      'common.clear': 'Тазалау',
      'common.yes': 'Иә',
      'common.no': 'Жоқ',
      'nav.commissions': 'Комиссиялар',
      'nav.kpi': 'KPI',
      'nav.events': 'Іс-шаралар',
      'nav.admin': 'Админ-панель',
      'topbar.search': 'Іздеу',
      'topbar.notify': 'Хабарламалар',
      'topbar.export_csv': 'CSV',
      'topbar.export_pdf': 'PDF',
      'topbar.export_json': 'JSON',
      'topbar.import': 'Импорттау',
      'topbar.logout': 'Шығу',
      'lang.switch': 'Русский',
      'members.add': 'Кеңес мүшесін қосу',
      'members.edit': 'Кеңес мүшесін өңдеу',
      'members.empty': 'Көрсету үшін мүшелер жоқ',
      'members.all_commissions': 'Барлық комиссиялар',
      'members.default_role': 'Кеңес мүшесі',
      'members.photo': 'Фото',
      'members.photo_ok': 'Фото сәтті жүктелді',
      'members.delete_confirm': 'Жою',
      'members.deleted': 'Кеңес мүшесі жойылды',
      'members.updated': 'Кеңес мүшесі жаңартылды',
      'members.created': 'Кеңес мүшесі қосылды',
      'commissions.add': 'Комиссия қосу',
      'commissions.edit': 'Комиссияны өңдеу',
      'commissions.empty': 'Комиссиялар табылмады',
      'commissions.default_desc': 'Қоғамдық кеңес комиссиясы',
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
      'page.outgoing_sub': 'Қоғамдық кеңес жауаптары',
      'page.members': 'Қоғамдық кеңес мүшелері',
      'page.commissions': 'Комиссиялар',
      'page.commissions_sub': 'Қоғамдық кеңес құрылымы',
      'page.kpi': 'KPI',
      'page.kpi_sub': 'Мүшелер мен комиссиялар көрсеткіштері',
      'page.events': 'Іс-шаралар',
      'page.events_sub': 'Кеңес мүшелерінің қатысуы',
      'letters.count': 'хат',
      'letters.deadline': 'жауап мерзімі 15 жұмыс күні',
      'members.count': 'мүше',
      'commissions.count': 'комиссия',
      'ctx.add_incoming': '+ Хат қосу',
      'ctx.pending': 'Жауапсыз',
      'ctx.add_outgoing': '+ Кеңес жауабы',
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
      'dash.os_replies': 'кеңес жауаптары',
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
      'letters.outgoing_add': 'Жаңа кеңес жауабы',
      'letters.save': 'Сақтау',
      'letters.reset': 'Тазалау',
      'letters.incoming_saved': 'Кіріс хат сәтті сақталды',
      'letters.outgoing_saved': 'Шығыс хат сәтті сақталды',
      'letters.save_incoming_error': 'Кіріс хат сақталмады',
      'letters.save_outgoing_error': 'Шығыс хат сақталмады',
      'letters.linked_reply': 'Жауап',
      'letters.type_incoming': 'Кіріс',
      'letters.type_outgoing': 'Шығыс',
      'letters.add_recipient_warn': 'Кем дегенде бір алушы қосыңыз немесе ұйымды көрсетіңіз',
      'letters.org_required': 'Алушы ұйымын көрсетіңіз',
      'letters.linked_ok': 'Шығыс хат сәтті байланыстырылды',
      'mobile.incoming': 'Кіріс',
      'mobile.outgoing': 'Шығыс',
      'mobile.members': 'Мүшелер',
      'mobile.menu': 'Тағы',
      'translate.empty': 'Алдымен орысша өрісті толтырыңыз',
      'translate.done': 'Аударма дайын',
      'search.enter_query': 'Іздеу сұрауын енгізіңіз',
      'search.nothing_found': 'Ештеңе табылмады',
      'search.searching': 'Іздеу...',
      'search.error': 'Іздеу қатесі',
      'search.source.incoming': 'Кіріс',
      'search.source.outgoing': 'Шығыс',
      'search.source.member': 'Кеңес мүшесі',
      'period.quarter_fmt': '{y} жылдың {q}-тоқсаны',
      'period.year_fmt': '{y} жыл',
      'dash.open_journal': 'Журналды ашу',
      'dash.letters_no_reply': 'хат жауапсыз',
      'dash.overdue_badge': 'мерзімі өткен',
      'dash.due_soon_badge': 'мерзімі жақында',
      'dash.no_commission': 'Комиссиясыз',
      'dash.no_data': 'Деректер жоқ',
      'dash.chairman': 'Кеңес төрағасы',
      'dash.members_count': 'мүше',
      'dash.no_commission_data': 'Комиссиялар бойынша деректер жоқ',
      'dash.incoming_count': 'Кіріс',
      'dash.outgoing_count': 'Шығыс',
      'dash.letters_count': 'хат',
      'dash.for_month': '{label} бойынша',
      'dash.outgoing_pct': 'кірістің {pct}%',
      'dash.scans_pct': 'барлық хаттың {pct}%',
      'dash.org_unknown': '(көрсетілмеген)',
      'dash.chart_letters': 'Хаттар',
      'dash.chart_incoming_gov': 'Мемл. органдардан кіріс хаттар (ҚК)',
      'dash.chart_os_replies': 'Кеңес жауаптары',
      'dash.chart_outgoing_os': 'Кеңестен шығыс хаттар',
      'dash.chart_gov_replies': 'Мемл. органдар жауаптары (ҚК)',
      'dash.enh.no_commissions': 'Комиссиялар бойынша деректер жоқ',
      'dash.enh.commission': 'Комиссия',
      'dash.enh.letters_people': '{letters} хат · {people} адам',
      'dash.enh.scans_pct_letters': 'сканмен {pct}% хат',
      'dash.enh.scans_pct': 'сканмен {pct}%',
      'dash.enh.photo_pct': 'фотомен {pct}%',
      'dash.enh.trend_days': '30 күнде {sign}{pct}%',
      'notify.dead.incoming_prefix': 'Кір.',
      'notify.dead.pending': 'Жауапсыз',
      'notify.dead.overdue': 'Мерзімі өткен',
      'notify.dead.due_soon': 'Мерзімі жақында',
      'notify.dead.th_reg': 'Тіркеу №',
      'notify.dead.th_date': 'Күні',
      'notify.dead.th_from': 'Кімнен',
      'notify.dead.th_deadline': 'Мерзім',
      'notify.dead.th_days': 'Күн',
      'notify.dead.th_responsible': 'Жауаптылар',
      'notify.dead.empty': 'Элементтер жоқ',
      'notify.dead.hint': 'Кіріс күнінен 15 жұмыс күні.',
      'notify.email.default_subject': 'Кеңес хабарламасы',
      'notify.email.no_access': 'Email жіберу модераторлар мен әкімшілерге қолжетімді.',
      'notify.email.th_date': 'Күні',
      'notify.email.th_to': 'Кімге',
      'notify.email.th_subject': 'Тақырып',
      'notify.email.th_status': 'Күйі',
      'notify.send_error': 'Жіберу қатесі',
      'letters.load_edit_error': 'Хатты өңдеуге жүктеу сәтсіз',
      'letters.load_error': 'Хат жүктелмеді',
      'letters.recipients_load_error': 'Алушылар жүктелмеді',
      'letters.link_error': 'Шығыс хат байланыстырылмады',
      'letters.delete_incoming_error': 'Кіріс хат жойылмады',
      'letters.delete_outgoing_error': 'Шығыс хат жойылмады',
      'letters.file_error': '{name} файлы өңделмеді',
      'letters.delete_incoming_ok': 'Кіріс хат сәтті жойылды',
      'letters.delete_incoming_confirm': 'Кіріс хатты жою керек пе?',
      'letters.delete_outgoing_ok': 'Шығыс хат сәтті жойылды',
      'letters.delete_outgoing_confirm': 'Шығыс хатты жою керек пе?',
      'letters.processing_files': 'Файлдар өңделуде...',
      'letters.selected_count': '{n} таңдалды',
      'letters.archive_confirm': '{n} хатты мұрағатқа жылжыту керек пе?',
      'letters.archived_ok': 'Мұрағатталды: {n}',
      'letters.delete_comment_confirm': 'Пікірді жою керек пе?',
      'letters.restore_confirm': 'Хатты мұрағаттан қалпына келтіру керек пе?',
      'export.admin_only': 'Деректерді экспорттау тек әкімшіге қолжетімді',
      'export.done': 'Экспорт орындалды. Операция аудит журналына жазылды.',
      'import.admin_only': 'Деректерді импорттау тек әкімшіге қолжетімді',
      'import.csv_role_error': 'CSV импорты тек әкімшіге немесе модераторға қолжетімді',
      'import.confirm': 'Импорт хаттарды API арқылы қосады. Жалғастыру керек пе?',
      'import.done': 'Импорт аяқталды: {ok} жазба',
      'import.errors_suffix': ', қателер: {fail}',
      'import.json_error': 'JSON импорттау сәтсіз аяқталды',
      'footer.copyright_bin': 'БСН',
    },
  };

  let lang = localStorage.getItem(STORAGE_KEY) || 'ru';
  if (!dict[lang]) lang = 'ru';

  const MONTHS = {
    ru: ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'],
    kz: ['Қаңтар', 'Ақпан', 'Наурыз', 'Сәуір', 'Мамыр', 'Маусым', 'Шілде', 'Тамыз', 'Қыркүйек', 'Қазан', 'Қараша', 'Желтоқсан'],
  };

  function register(extra) {
    if (!extra) return;
    if (extra.ru) Object.assign(dict.ru, extra.ru);
    if (extra.kz) Object.assign(dict.kz, extra.kz);
  }

  function t(key, fallback) {
    return dict[lang]?.[key] ?? dict.ru[key] ?? fallback ?? key;
  }

  function regionName(region) {
    if (!region) return '—';
    if (lang === 'kz' && region.name_kz) return region.name_kz;
    return region.name_ru || region.name_kz || '—';
  }

  function formatMonthLabel(key) {
    if (!key) return '';
    const [y, mm] = String(key).split('-');
    const m = Number(mm);
    const months = MONTHS[lang] || MONTHS.ru;
    if (!y || !Number.isFinite(m) || m < 1 || m > 12) return String(key);
    return `${months[m - 1]} ${y}`;
  }

  function fmt(key, vars) {
    let s = t(key);
    if (vars) {
      Object.entries(vars).forEach(([k, v]) => {
        s = s.replace(new RegExp(`\\{${k}\\}`, 'g'), String(v));
      });
    }
    return s;
  }

  function formatPeriodLabel(key, period) {
    if (!key) return '';
    if (period === 'month') return formatMonthLabel(key);
    if (period === 'quarter') {
      const [y, qPart] = String(key).split('-Q');
      const q = Number(qPart);
      return fmt('period.quarter_fmt', { q, y });
    }
    if (period === 'year') return fmt('period.year_fmt', { y: key });
    return String(key);
  }

  function updateUserLabels(user) {
    if (!user) return;
    window.__sessionUser = user;
    const nameEl = document.getElementById('userName');
    const regionEl = document.getElementById('userRegion');
    if (nameEl) nameEl.textContent = user.full_name || user.username || t('role.user');
    if (regionEl) {
      const activeId = user.active_region_id;
      const activeRegion = (window.allRegions || []).find((r) => Number(r.id) === Number(activeId));
      const region = activeRegion || user.region;
      if (region) {
        regionEl.textContent = `${t('role.region')}: ${regionName(region)}`;
      } else if (user.is_admin) {
        regionEl.textContent = t('role.superadmin');
      } else {
        regionEl.textContent = t('role.user');
      }
    }
    const regionSelect = document.getElementById('adminRegionSelect');
    if (regionSelect && window.allRegions?.length) {
      const prev = regionSelect.value;
      regionSelect.innerHTML = window.allRegions
        .filter((r) => r.is_active == 1 || r.is_active === true)
        .map((r) => `<option value="${r.id}">${regionName(r)}</option>`)
        .join('');
      if (prev) regionSelect.value = prev;
    }
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
    document.querySelectorAll('[data-i18n-attr]').forEach((el) => {
      const key = el.getAttribute('data-i18n');
      const attr = el.getAttribute('data-i18n-attr');
      if (key && attr) el.setAttribute(attr, t(key, el.getAttribute(attr) || ''));
    });
    const switchBtn = document.getElementById('langToggleBtn');
    if (switchBtn) {
      const label = switchBtn.querySelector('.sidebar-label');
      if (label) label.textContent = t('lang.switch', 'Қазақша');
      else switchBtn.textContent = t('lang.switch', 'Қазақша');
    }
    const confirmMsg = document.getElementById('confirmDeleteMessage');
    if (confirmMsg && !confirmMsg.dataset.i18nCustom) {
      confirmMsg.textContent = t('common.confirm_delete', confirmMsg.textContent);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('langToggleBtn')?.addEventListener('click', toggleLang);
    apply();
  });

  window.AppI18n = {
    t, setLang, toggleLang, apply, register, regionName, formatMonthLabel, formatPeriodLabel, fmt, updateUserLabels, MONTHS,
    get lang() { return lang; },
  };
})(window);

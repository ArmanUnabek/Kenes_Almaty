/**
 * Дополнительные ключи и автоперевод статического DOM журнала.
 */
(function (window) {
  if (!window.AppI18n) return;

  const extra = {
    ru: {
      'brand.title': 'Журнал ОС',
      'brand.subtitle': 'Общественный Совет',
      'doc.title': 'Журнал ОС — Статистика обращений',
      'role.superadmin': 'Супер-админ',
      'role.user': 'Пользователь',
      'role.region': 'Регион',
      'dash.insights': 'Оперативная сводка',
      'dash.with_scans': 'Со сканами',
      'dash.for_period': 'За период',
      'dash.members': 'Членов ОС',
      'dash.commissions': 'Комиссий',
      'dash.commission_activity': 'Активность комиссий',
      'dash.top_orgs': 'Топ организаций (входящие)',
      'chart.incoming': 'Входящие',
      'chart.outgoing': 'Исходящие',
      'period.month': 'Месяц',
      'period.quarter': 'Квартал',
      'period.year': 'Год',
      'filter.all_months': 'Все месяцы',
      'filter.all_statuses': 'Все статусы',
      'filter.all_letters': 'Все письма',
      'filter.with_scans': 'Только со сканами',
      'filter.without_scans': 'Без сканов',
      'filter.all_recipients': 'Все адресаты',
      'filter.show': 'Показать фильтры',
      'filter.hide': 'Скрыть фильтры',
      'events.add_edit': 'Добавить / Изменить мероприятие',
      'events.list': 'Список мероприятий',
      'events.title': 'Название',
      'events.date': 'Дата',
      'events.location': 'Локация',
      'events.participants': 'Участники (члены ОС)',
      'events.select_all': 'Выбрать всех',
      'events.clear': 'Очистить',
      'events.notes': 'Примечание',
      'events.kpi_custom': 'KPI (произвольные метрики)',
      'events.add_kpi': 'Добавить KPI',
      'events.attendance': 'Присутствовало/Всего',
      'events.attendance_pct': '% явки',
      'events.attendees_modal': 'Присутствующие',
      'letters.recipients_modal': 'Адресаты письма',
      'letters.add_incoming': 'Добавить входящее письмо',
      'letters.add_outgoing': 'Добавить исходящее письмо (Ответ ОС)',
      'letters.category': 'Категория',
      'letters.received_date': 'Дата получения',
      'letters.sent_date': 'Дата отправки',
      'letters.organization': 'Организация',
      'letters.number': 'Номер письма',
      'letters.link_outgoing': 'Ответ на исходящее письмо',
      'letters.select_outgoing': 'Выберите исходящее письмо',
      'letters.link_hint': 'Включите галочку, чтобы выбрать исходящее, и номер сформируется как «новый рег.№ / исходящий №».',
      'letters.reg_num': 'Рег. № во входящем',
      'letters.auto': 'Авто',
      'letters.subject': 'Тема/Краткое содержание',
      'letters.note': 'Примечание',
      'letters.recipients': 'Адресаты',
      'letters.recipient_ph': 'Добавьте организацию или адрес',
      'letters.recipient_add': 'Добавить',
      'letters.recipient_hint': 'Первый адрес будет сохранён как основная организация.',
      'letters.responsible': 'Ответственные члены ОС',
      'letters.responsible_hint': 'Можно выбрать нескольких ответственных, чтобы их отобразить в карточке письма.',
      'letters.responsible_out_hint': 'Выберите ответственных за подготовку и отправку этого письма.',
      'letters.scans': 'Сканы и вложения (несколько файлов)',
      'letters.scans_hint': 'Поддерживаются изображения, PDF, документы, видео и другие файлы.',
      'letters.add_btn': 'Добавить',
      'letters.clear_btn': 'Очистить',
      'letters.create_reply': 'Создать ответ ОС',
      'letters.link_incoming': 'Связать с входящим',
      'letters.link_incoming_hint': 'Можно оставить пустым для самостоятельного письма.',
      'letters.recipient_category': 'Категория адресата',
      'letters.out_number': 'Исходящий №',
      'letters.out_number_ph': 'Авто или введите вручную',
      'letters.recipients_out': 'Получатели (адресаты)',
      'letters.recipient_out_ph': 'Добавьте адресата',
      'letters.recipient_out_hint': 'Первый адресат заполнит поле «Организация».',
      'letters.cat_kk': 'Гос. органы (ҚК)',
      'letters.cat_n': 'Рекомендации (.Н)',
      'letters.cat_jt': 'Жители (ЖТ)',
      'letters.cat_zt': 'Организации (ЗТ)',
      'letters.journal_incoming': 'Входящий журнал',
      'letters.journal_outgoing': 'Исходящий журнал',
      'letters.th_reg': 'Рег. №',
      'letters.th_date': 'Дата',
      'letters.th_org': 'Организация',
      'letters.th_category': 'Категория',
      'letters.th_kk_num': 'Номер (ҚК)',
      'letters.th_subject': 'Тема',
      'letters.th_recipients': 'Адресаты',
      'letters.th_deadline': 'Срок',
      'letters.th_responsible': 'Ответственные',
      'letters.th_scans': 'Сканы',
      'letters.th_reply': 'Ответ',
      'letters.th_seq': 'Порядк. №',
      'letters.th_out_num': 'Исходящий №',
      'letters.th_linked': 'Связанное входящее',
      'letters.search_in': 'Поиск по номеру, организации, теме…',
      'letters.search_out': 'Поиск по номеру, теме, организации…',
      'members.full_name': 'ФИО',
      'members.commission': 'Комиссия',
      'members.not_assigned': '— не назначена —',
      'members.position': 'Должность',
      'members.organization': 'Организация',
      'members.phone': 'Телефон',
      'members.email': 'Email',
      'members.status': 'Статус',
      'members.status_active': 'Активен',
      'members.status_inactive': 'Неактивен',
      'commissions.name': 'Название',
      'commissions.sort': 'Порядок',
      'commissions.color': 'Цвет',
      'kpi.members': 'KPI по членам ОС',
      'kpi.commissions': 'KPI по комиссиям',
      'kpi.top_recipients': 'Топ адресатов писем',
      'kpi.incoming': 'Входящие',
      'kpi.outgoing': 'Исходящие',
      'kpi.th_out': 'Исходящих',
      'kpi.th_in': 'Входящих',
      'kpi.th_lead': 'Ведущий',
      'kpi.th_events': 'Меропр.',
      'modal.link_outgoing': 'Привязать исходящее письмо',
      'modal.link_search': 'Поиск по номеру, теме или организации',
      'modal.no_outgoing': 'Нет доступных исходящих',
      'modal.select': 'Выбрать',
      'modal.confirm_delete': 'Подтверждение удаления',
      'modal.scan_view': 'Просмотр сканов письма',
      'modal.download_scans': 'Скачать все сканы',
      'modal.prev': 'Предыдущий',
      'modal.next': 'Следующий',
      'search.global_ph': 'Письма, организации, члены ОС...',
      'search.hotkey': 'Горячая клавиша: Ctrl+K',
      'footer.help': 'Справка',
      'footer.faq': 'FAQ',
      'footer.privacy': 'Политика ПДн',
      'footer.terms': 'Соглашение',
      'footer.support': 'Поддержка',
      'loading.data': 'Загрузка данных...',
      'app.loaded': 'Данные успешно загружены',
      'app.load_error': 'Не удалось загрузить данные. Проверьте подключение к серверу.',
      'table.no_records': 'Нет записей для отображения',
      'table.shown': 'Показано',
      'table.of': 'из',
      'table.records': 'записей',
      'letters.view': 'Просмотр',
      'letters.no_scans': 'Сканы не найдены',
      'letters.scans_load_error': 'Не удалось загрузить сканы',
      'letters.no_permission': 'Недостаточно прав для сохранения',
      'notify.queued': 'Поставлено в очередь',
      'mobile.dashboard': 'Статистика',
      'aria.menu': 'Меню',
      'aria.more_actions': 'Ещё действия',
      'aria.main_nav': 'Основная навигация',
    },
    kz: {
      'brand.title': 'ОС журналы',
      'brand.subtitle': 'Қоғамдық кеңес',
      'doc.title': 'ОС журналы — Өтініштер статистикасы',
      'role.superadmin': 'Супер-әкімші',
      'role.user': 'Пайдаланушы',
      'role.region': 'Аймақ',
      'dash.insights': 'Жедел қорытынды',
      'dash.with_scans': 'Сканмен',
      'dash.for_period': 'Кезең бойынша',
      'dash.members': 'Кеңес мүшелері',
      'dash.commissions': 'Комиссиялар',
      'dash.commission_activity': 'Комиссиялар белсенділігі',
      'dash.top_orgs': 'Үздік ұйымдар (кіріс)',
      'chart.incoming': 'Кіріс',
      'chart.outgoing': 'Шығыс',
      'period.month': 'Ай',
      'period.quarter': 'Тоқсан',
      'period.year': 'Жыл',
      'filter.all_months': 'Барлық айлар',
      'filter.all_statuses': 'Барлық күйлер',
      'filter.all_letters': 'Барлық хаттар',
      'filter.with_scans': 'Тек сканмен',
      'filter.without_scans': 'Скансыз',
      'filter.all_recipients': 'Барлық алушылар',
      'filter.show': 'Сүзгілерді көрсету',
      'filter.hide': 'Сүзгілерді жасыру',
      'events.add_edit': 'Іс-шара қосу / өзгерту',
      'events.list': 'Іс-шаралар тізімі',
      'events.title': 'Атауы',
      'events.date': 'Күні',
      'events.location': 'Орны',
      'events.participants': 'Қатысушылар (кеңес мүшелері)',
      'events.select_all': 'Барлығын таңдау',
      'events.clear': 'Тазалау',
      'events.notes': 'Ескертпе',
      'events.kpi_custom': 'KPI (еркін метрикалар)',
      'events.add_kpi': 'KPI қосу',
      'events.attendance': 'Қатысқан/Барлығы',
      'events.attendance_pct': 'Қатысу %',
      'events.attendees_modal': 'Қатысқандар',
      'letters.recipients_modal': 'Хат алушылары',
      'letters.add_incoming': 'Кіріс хат қосу',
      'letters.add_outgoing': 'Шығыс хат қосу (Кеңес жауабы)',
      'letters.category': 'Санат',
      'letters.received_date': 'Алынған күні',
      'letters.sent_date': 'Жіберілген күні',
      'letters.organization': 'Ұйым',
      'letters.number': 'Хат нөмірі',
      'letters.link_outgoing': 'Шығыс хатқа жауап',
      'letters.select_outgoing': 'Шығыс хатты таңдаңыз',
      'letters.link_hint': 'Құсбелгіні қосып шығыс хатты таңдаңыз — нөмір «жаңа тіркеу № / шығыс №» түрінде құрылады.',
      'letters.reg_num': 'Кірістегі тіркеу №',
      'letters.auto': 'Авто',
      'letters.subject': 'Тақырып/Қысқаша мазмұн',
      'letters.note': 'Ескертпе',
      'letters.recipients': 'Алушылар',
      'letters.recipient_ph': 'Ұйым немесе мекенжай қосыңыз',
      'letters.recipient_add': 'Қосу',
      'letters.recipient_hint': 'Бірінші мекенжай негізгі ұйым ретінде сақталады.',
      'letters.responsible': 'Жауапты кеңес мүшелері',
      'letters.responsible_hint': 'Хат карточкасында көрсету үшін бірнеше жауапты таңдауға болады.',
      'letters.responsible_out_hint': 'Осы хатты дайындау мен жіберуге жауаптыларды таңдаңыз.',
      'letters.scans': 'Скандар мен тіркемелер (бірнеше файл)',
      'letters.scans_hint': 'Суреттер, PDF, құжаттар, бейне және басқа файлдар қолданылады.',
      'letters.add_btn': 'Қосу',
      'letters.clear_btn': 'Тазалау',
      'letters.create_reply': 'Кеңес жауабын жасау',
      'letters.link_incoming': 'Кіріс хатпен байланыстыру',
      'letters.link_incoming_hint': 'Бөлек хат үшін бос қалдыруға болады.',
      'letters.recipient_category': 'Алушы санаты',
      'letters.out_number': 'Шығыс №',
      'letters.out_number_ph': 'Авто немесе қолмен енгізіңіз',
      'letters.recipients_out': 'Алушылар',
      'letters.recipient_out_ph': 'Алушы қосыңыз',
      'letters.recipient_out_hint': 'Бірінші алушы «Ұйым» өрісін толтырады.',
      'letters.cat_kk': 'Мемл. органдар (ҚК)',
      'letters.cat_n': 'Ұсыныстар (.Н)',
      'letters.cat_jt': 'Тұрғындар (ЖТ)',
      'letters.cat_zt': 'Ұйымдар (ЗТ)',
      'letters.journal_incoming': 'Кіріс журналы',
      'letters.journal_outgoing': 'Шығыс журналы',
      'letters.th_reg': 'Тіркеу №',
      'letters.th_date': 'Күні',
      'letters.th_org': 'Ұйым',
      'letters.th_category': 'Санат',
      'letters.th_kk_num': 'Нөмір (ҚК)',
      'letters.th_subject': 'Тақырып',
      'letters.th_recipients': 'Алушылар',
      'letters.th_deadline': 'Мерзім',
      'letters.th_responsible': 'Жауаптылар',
      'letters.th_scans': 'Скандар',
      'letters.th_reply': 'Жауап',
      'letters.th_seq': 'Рет №',
      'letters.th_out_num': 'Шығыс №',
      'letters.th_linked': 'Байланысты кіріс',
      'letters.search_in': 'Нөмір, ұйым, тақырып бойынша іздеу…',
      'letters.search_out': 'Нөмір, тақырып, ұйым бойынша іздеу…',
      'members.full_name': 'Аты-жөні',
      'members.commission': 'Комиссия',
      'members.not_assigned': '— тағайындалмаған —',
      'members.position': 'Лауазымы',
      'members.organization': 'Ұйым',
      'members.phone': 'Телефон',
      'members.email': 'Email',
      'members.status': 'Күйі',
      'members.status_active': 'Белсенді',
      'members.status_inactive': 'Белсенді емес',
      'commissions.name': 'Атауы',
      'commissions.sort': 'Реті',
      'commissions.color': 'Түсі',
      'kpi.members': 'Кеңес мүшелері KPI',
      'kpi.commissions': 'Комиссиялар KPI',
      'kpi.top_recipients': 'Хат алушыларының үздігі',
      'kpi.incoming': 'Кіріс',
      'kpi.outgoing': 'Шығыс',
      'kpi.th_out': 'Шығыс',
      'kpi.th_in': 'Кіріс',
      'kpi.th_lead': 'Жетекші',
      'kpi.th_events': 'Іс-шара',
      'modal.link_outgoing': 'Шығыс хатты байланыстыру',
      'modal.link_search': 'Нөмір, тақырып немесе ұйым бойынша іздеу',
      'modal.no_outgoing': 'Қолжетімді шығыс хаттар жоқ',
      'modal.select': 'Таңдау',
      'modal.confirm_delete': 'Жоюды растау',
      'modal.scan_view': 'Хат скандарын қарау',
      'modal.download_scans': 'Барлық скандарды жүктеу',
      'modal.prev': 'Алдыңғы',
      'modal.next': 'Келесі',
      'search.global_ph': 'Хаттар, ұйымдар, кеңес мүшелері...',
      'search.hotkey': 'Жылдам перне: Ctrl+K',
      'footer.help': 'Анықтама',
      'footer.faq': 'FAQ',
      'footer.privacy': 'ЖД саясаты',
      'footer.terms': 'Келісім',
      'footer.support': 'Қолдау',
      'loading.data': 'Деректер жүктелуде...',
      'app.loaded': 'Деректер сәтті жүктелді',
      'app.load_error': 'Деректер жүктелмеді. Серверге қосылуды тексеріңіз.',
      'table.no_records': 'Көрсетуге жазбалар жоқ',
      'table.shown': 'Көрсетілді',
      'table.of': '/',
      'table.records': 'жазба',
      'letters.view': 'Қарау',
      'letters.no_scans': 'Скандар табылмады',
      'letters.scans_load_error': 'Скандар жүктелмеді',
      'letters.no_permission': 'Сақтауға құқық жеткіліксіз',
      'notify.queued': 'Кезекке қойылды',
      'mobile.dashboard': 'Статистика',
      'aria.menu': 'Мәзір',
      'aria.more_actions': 'Қосымша әрекеттер',
      'aria.main_nav': 'Негізгі навигация',
    },
  };

  AppI18n.register(extra);

  const DOM_MAP = [
    ['.sidebar-titles h1', 'brand.title'],
    ['.sidebar-titles p', 'brand.subtitle'],
    ['#dashInsightPending .dash-insight__label', 'status.pending'],
    ['#dashInsightOverdue .dash-insight__label', 'status.overdue'],
    ['#kpiIncomingPeriodLabel', 'dash.for_period'],
    ['#pane-dashboard .dash-insight:nth-child(4) .dash-insight__label', 'dash.with_scans'],
    ['#pane-dashboard .dash-insight:nth-child(5) .dash-insight__label', 'dash.members'],
    ['#pane-dashboard .dash-insight:nth-child(6) .dash-insight__label', 'dash.commissions'],
    ['#commissionsCard .card-header', 'dash.commission_activity'],
    ['#pane-dashboard .col-lg-5 .card-header', 'dash.top_orgs'],
    ['.dash-chart-legend__item--in', 'chart.incoming', true],
    ['.dash-chart-legend__item--out', 'chart.outgoing', true],
    ['#dashboardPeriodSelect option[value="month"]', 'period.month'],
    ['#dashboardPeriodSelect option[value="quarter"]', 'period.quarter'],
    ['#dashboardPeriodSelect option[value="year"]', 'period.year'],
    ['#pane-dashboard .dash-insights .card-header', 'dash.insights'],
    ['#collapseEventForm ~ .card-body, #pane-events .form-card-header span', 'events.add_edit'],
    ['#pane-events .data-card .card-header span', 'events.list'],
    ['#formEvent label[for="evTitle"], #formEvent .col-md-4:first-child .form-label', 'events.title'],
    ['#evDate', null],
    ['#attSelectAll', 'events.select_all'],
    ['#attClear', 'events.clear'],
    ['#addKpiBtn', 'events.add_kpi'],
    ['#eventAttendeesModal .modal-title', 'events.attendees_modal'],
    ['#letterRecipientsModal .modal-title', 'letters.recipients_modal'],
    ['#pane-incoming .form-card-header span', 'letters.add_incoming'],
    ['#pane-outgoing .form-card-header span', 'letters.add_outgoing'],
    ['#pane-incoming .data-card .card-header', 'letters.journal_incoming'],
    ['#pane-outgoing .data-card .card-header', 'letters.journal_outgoing'],
    ['#btnOpenOutgoingFromIncoming', 'letters.create_reply'],
    ['#linkOutgoingModal .modal-title', 'modal.link_outgoing'],
    ['#linkOutgoingSearch', null, false, 'placeholder', 'modal.link_search'],
    ['#linkOutgoingConfirm', 'modal.select'],
    ['#confirmDeleteModal .modal-title', 'modal.confirm_delete'],
    ['#confirmDeleteBtn', 'action.delete'],
    ['#scanViewerModal .modal-title', 'modal.scan_view'],
    ['#downloadAllScansBtn', 'modal.download_scans'],
    ['#globalSearchModal .modal-title', 'search.title'],
    ['#globalSearchInput', null, false, 'placeholder', 'search.global_ph'],
    ['#loadingOverlay p', 'loading.data'],
    ['#filterMembersCommission', null],
    ['.filter-toggle-label', 'filter.show'],
    ['.filter-toggle-label-close', 'filter.hide'],
    ['#filterMonthIncoming option[value="all"]', 'filter.all_months'],
    ['#filterMonthOutgoing option[value="all"]', 'filter.all_months'],
    ['#filterStatusIncoming option[value="all"]', 'filter.all_statuses'],
    ['#filterStatusIncoming option[value="pending"]', 'status.pending'],
    ['#filterStatusIncoming option[value="overdue"]', 'status.overdue'],
    ['#filterScansIncoming option[value="all"]', 'filter.all_letters'],
    ['#filterScansIncoming option[value="with-scans"]', 'filter.with_scans'],
    ['#filterScansIncoming option[value="without-scans"]', 'filter.without_scans'],
    ['#filterScansOutgoing option[value="all"]', 'filter.all_letters'],
    ['#filterScansOutgoing option[value="with-scans"]', 'filter.with_scans'],
    ['#filterScansOutgoing option[value="without-scans"]', 'filter.without_scans'],
    ['#filterRecipientIncoming option[value="all"]', 'filter.all_recipients'],
    ['#filterRecipientOutgoing option[value="all"]', 'filter.all_recipients'],
    ['#searchIncoming', null, false, 'placeholder', 'letters.search_in'],
    ['#searchOutgoing', null, false, 'placeholder', 'letters.search_out'],
    ['#searchEvents', null, false, 'placeholder', 'common.search'],
    ['#incType option[value="KK"]', 'letters.cat_kk'],
    ['#incType option[value="N"]', 'letters.cat_n'],
    ['#incType option[value="JT"]', 'letters.cat_jt'],
    ['#incType option[value="ZT"]', 'letters.cat_zt'],
    ['#outType option[value="gov"]', 'letters.cat_kk'],
    ['#outType option[value="jt"]', 'letters.cat_jt'],
    ['#outType option[value="zt"]', 'letters.cat_zt'],
    ['#memberStatus option[value="active"]', 'members.status_active'],
    ['#memberStatus option[value="inactive"]', 'members.status_inactive'],
    ['#memberCommissionId option[value=""]', 'members.not_assigned'],
    ['#filterMembersCommission option[value=""]', 'members.all_commissions'],
    ['#tableKpiMembers thead th:nth-child(3)', 'kpi.th_out'],
    ['#tableKpiMembers thead th:nth-child(4)', 'kpi.th_in'],
    ['#tableKpiMembers thead th:nth-child(5)', 'kpi.th_lead'],
    ['#tableKpiMembers thead th:nth-child(6)', 'kpi.th_events'],
    ['#tableKpiCommissions thead th:nth-child(2)', 'kpi.th_out'],
    ['#tableKpiCommissions thead th:nth-child(3)', 'kpi.th_in'],
    ['#tableKpiCommissions thead th:nth-child(4)', 'kpi.th_events'],
    ['#pane-kpi .card-header span', 'kpi.members'],
    ['#pane-kpi .col-lg-6:nth-child(2) .card-header', 'kpi.commissions'],
    ['#pane-kpi .col-12 .card-header', 'kpi.top_recipients'],
    ['.app-footer a[href="/help/"]', 'footer.help'],
    ['.app-footer a[href="/help/faq.html"]', 'footer.faq'],
    ['.app-footer a[href="/legal/privacy.html"]', 'footer.privacy'],
    ['.app-footer a[href="/legal/terms.html"]', 'footer.terms'],
    ['.app-footer a[data-site-href="email"]', 'footer.support'],
    ['#mobileBottomNav [data-tab="tab-dashboard"] span', 'mobile.dashboard'],
    ['#mobileBottomNav [data-tab="tab-incoming"] span', 'mobile.incoming'],
    ['#mobileBottomNav [data-tab="tab-outgoing"] span', 'mobile.outgoing'],
    ['#mobileBottomNav [data-tab="tab-members"] span', 'mobile.members'],
    ['#mobileBottomNav [data-tab="menu"] span', 'mobile.menu'],
  ];

  const FORM_LABELS = {
    '#formEvent': {
      'evTitle': 'events.title',
      'evDate': 'events.date',
      'evLocation': 'events.location',
      'evNotes': 'events.notes',
    },
    '#formIncoming': {
      'incType': 'letters.category',
      'incDate': 'letters.received_date',
      'incOrg': 'letters.organization',
      'incNumber': 'letters.number',
      'incSeq': 'letters.reg_num',
      'incSubject': 'letters.subject',
      'incNote': 'letters.note',
      'incRecipientInput': 'letters.recipients',
      'incMembers': 'letters.responsible',
      'incScans': 'letters.scans',
    },
    '#formOutgoing': {
      'outDate': 'letters.sent_date',
      'outLinkedIncoming': 'letters.link_incoming',
      'outType': 'letters.recipient_category',
      'outNumber': 'letters.out_number',
      'outSubject': 'letters.subject',
      'outRecipientInput': 'letters.recipients_out',
      'outNote': 'letters.note',
      'outMembers': 'letters.responsible',
      'outScans': 'letters.scans',
    },
    '#formMember': {
      'memberFullName': 'members.full_name',
      'memberCommissionId': 'members.commission',
      'memberPosition': 'members.position',
      'memberOrganization': 'members.organization',
      'memberPhone': 'members.phone',
      'memberEmail': 'members.email',
      'memberStatus': 'members.status',
    },
    '#formCommission': {
      'commissionName': 'commissions.name',
      'commissionSortOrder': 'commissions.sort',
      'commissionColor': 'commissions.color',
    },
  };

  const TABLE_HEADERS = {
    '#tableEvents': ['events.date', 'events.title', 'events.location', 'events.attendance', 'events.attendance_pct'],
    '#tableIncoming': ['letters.th_reg', 'letters.th_date', 'letters.th_org', 'letters.th_category', 'letters.th_kk_num', 'letters.th_subject', 'letters.th_recipients', 'letters.th_deadline', 'letters.th_responsible', 'letters.th_scans', 'letters.th_reply'],
    '#tableOutgoing': ['letters.th_seq', 'letters.th_date', 'letters.th_out_num', 'letters.th_org', 'letters.th_category', 'letters.th_linked', 'letters.th_subject', 'letters.th_recipients', 'letters.th_responsible', 'letters.th_scans'],
    '#tableKpiMembers': ['members.full_name', 'members.commission', 'kpi.th_out', 'kpi.th_in', 'kpi.th_lead', 'kpi.th_events'],
    '#tableKpiCommissions': ['members.commission', 'kpi.th_out', 'kpi.th_in', 'kpi.th_events'],
  };

  function applyDom() {
    const t = AppI18n.t;
    DOM_MAP.forEach((entry) => {
      const [sel, key, stripIcon, attr, attrKey] = entry;
      if (!key && !attrKey) return;
      document.querySelectorAll(sel).forEach((el) => {
        const text = t(attrKey || key, el.textContent);
        if (attr === 'placeholder') {
          el.placeholder = text;
        } else if (stripIcon) {
          const icon = el.querySelector('i');
          el.textContent = '';
          if (icon) el.appendChild(icon);
          el.append(' ', text.replace(/^[^\s]+\s/, ''));
        } else {
          el.textContent = text;
        }
      });
    });

    Object.entries(FORM_LABELS).forEach(([formSel, fields]) => {
      Object.entries(fields).forEach(([fieldId, i18nKey]) => {
        const input = document.getElementById(fieldId);
        const label = input?.closest('.col-12, .col-md-2, .col-md-3, .col-md-4, .col-md-6, .col-6')?.querySelector('.form-label');
        if (label && !label.querySelector('input[type=checkbox]')) {
          label.childNodes.forEach((n) => { if (n.nodeType === 3) n.textContent = ''; });
          const firstText = Array.from(label.childNodes).find((n) => n.nodeType === 3);
          if (firstText) firstText.textContent = t(i18nKey);
          else if (!label.querySelector('input')) label.textContent = t(i18nKey);
        }
      });
    });

    Object.entries(TABLE_HEADERS).forEach(([tableSel, keys]) => {
      const ths = document.querySelectorAll(`${tableSel} thead th`);
      keys.forEach((key, i) => {
        if (ths[i] && key) ths[i].textContent = t(key);
      });
    });

    document.querySelectorAll('#formEvent .form-label, #pane-events .col-12 > .form-label').forEach((label) => {
      const txt = label.textContent.trim();
      if (txt.includes('Участники') || txt.includes('KPI')) {
        label.textContent = txt.includes('KPI') ? t('events.kpi_custom') : t('events.participants');
      }
    });

    document.querySelectorAll('#formIncoming button[type="submit"], #formOutgoing button[type="submit"], #formEvent button[type="submit"], #formMember button[type="submit"], #formCommission button[type="submit"]').forEach((btn) => {
      if (btn.type === 'submit' && !btn.id) btn.textContent = t('common.save');
    });
    document.querySelectorAll('#formIncoming button[type="reset"], #formOutgoing button[type="reset"], #formEvent button[type="reset"], #memberFormReset, #commissionFormReset').forEach((btn) => {
      btn.textContent = t('letters.clear_btn');
    });
    document.querySelectorAll('#incRecipientAdd, #outRecipientAdd').forEach((btn) => {
      btn.textContent = t('letters.recipient_add');
    });

    document.querySelectorAll('#pane-kpi h6.fw-semibold').forEach((h, idx) => {
      h.textContent = idx === 0 ? t('kpi.incoming') : t('kpi.outgoing');
    });

    document.querySelectorAll('.modal-footer .btn-outline-secondary[data-bs-dismiss="modal"], .modal-header .btn-close').forEach((el) => {
      if (el.classList.contains('btn-close')) el.setAttribute('aria-label', t('common.close'));
    });
    document.querySelectorAll('.modal-footer .btn-outline-secondary[data-bs-dismiss="modal"]').forEach((btn) => {
      btn.textContent = t('common.close');
    });

    document.querySelectorAll('small.text-muted').forEach(() => {}); // hints stay RU for now unless keyed

    const hints = {
      '#formIncoming small.text-muted': ['letters.link_hint', 'letters.recipient_hint', 'letters.responsible_hint', 'letters.scans_hint'],
      '#formOutgoing small.text-muted': ['letters.link_incoming_hint', 'letters.recipient_out_hint', 'letters.responsible_out_hint', 'letters.scans_hint'],
    };
    Object.entries(hints).forEach(([scope, keys]) => {
      document.querySelectorAll(`${scope}`).forEach((el, i) => {
        if (keys[i]) el.textContent = t(keys[i]);
      });
    });

    document.getElementById('incLinkToggle')?.parentElement?.childNodes.forEach((n) => {
      if (n.nodeType === 3 && n.textContent.trim().startsWith('Ответ')) {
        n.textContent = ' ' + t('letters.link_outgoing');
      }
    });

    document.getElementById('incRespondsOutgoing')?.querySelector('option[value=""]') &&
      (document.querySelector('#incRespondsOutgoing option[value=""]').textContent = t('letters.select_outgoing'));

    document.getElementById('incSeq')?.setAttribute('placeholder', t('letters.auto'));
    document.getElementById('outNumber')?.setAttribute('placeholder', t('letters.out_number_ph'));
    document.getElementById('incRecipientInput')?.setAttribute('placeholder', t('letters.recipient_ph'));
    document.getElementById('outRecipientInput')?.setAttribute('placeholder', t('letters.recipient_out_ph'));
    document.querySelector('#globalSearchModal .form-text')?.replaceChildren(document.createTextNode(t('search.hotkey')));
    document.querySelector('#filterMembersCommission')?.previousElementSibling?.replaceChildren(document.createTextNode(t('members.commission')));
    document.querySelector('#sidebarToggle')?.setAttribute('aria-label', t('aria.menu'));
    document.querySelector('.topbar-actions .dropdown button[aria-label]')?.setAttribute('aria-label', t('aria.more_actions'));
    document.getElementById('mobileBottomNav')?.setAttribute('aria-label', t('aria.main_nav'));
    document.title = t('doc.title');
  }

  const origApply = AppI18n.apply.bind(AppI18n);
  AppI18n.apply = function applyAll() {
    origApply();
    applyDom();
    if (window.__sessionUser) AppI18n.updateUserLabels(window.__sessionUser);
    const tab = document.querySelector('[data-bs-toggle="tab"].active')?.id;
    if (tab && typeof updatePageHeader === 'function') updatePageHeader(tab);
    if (typeof renderAll === 'function') renderAll();
  };

  window.addEventListener('app:langchange', () => {
    applyDom();
    if (window.__sessionUser) AppI18n.updateUserLabels(window.__sessionUser);
  });

  AppI18n.applyDom = applyDom;
})();

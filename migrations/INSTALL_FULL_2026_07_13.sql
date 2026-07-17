-- ============================================================================
-- INSTALL FULL — ЧИСТАЯ УСТАНОВКА БД С НУЛЯ (2026-07-13)
-- Проект: "Журнал ОС". Target: MariaDB (прод) / MySQL. Engine InnoDB, utf8mb4.
-- ============================================================================
-- НАЗНАЧЕНИЕ:
--   Полная установка схемы на ПУСТУЮ базу одним файлом = базовая схема
--   (deploy_database.sql) + все дополнения из миграций 2026_07_10 и 2026_07_13,
--   уже влитые прямо в CREATE TABLE. FULLTEXT-индексы сразу создаются с составом
--   колонок, которого требует код (GlobalSearchService), — без пересоздания.
--
-- КАК ЗАПУСТИТЬ:
--   phpMyAdmin: выбрать нужную БД -> вкладка "Импорт" -> выбрать этот файл -> Вперёд.
--   CLI:        mysql -u USER -p DBNAME < migrations/INSTALL_FULL_2026_07_13.sql
--   Файл НЕ содержит CREATE DATABASE / USE — импортируется в уже выбранную БД.
--
-- ВНИМАНИЕ: файл рассчитан ТОЛЬКО на ПУСТУЮ базу (сверху вниз, без ошибок).
--   Повторный запуск на НЕПУСТОЙ базе НЕ предусмотрен. Для обновления уже
--   существующей БД используйте migrations/DEPLOY_2026_07_13_all.sql (идемпотентные ALTER).
--
-- ПЕРВЫЙ ВХОД (сид-данные ниже):
--   Создаются пользователи admin / moderator / viewer.
--   Пароль у всех троих: admin123  (bcrypt-хэш в INSERT). СМЕНИТЕ ПОСЛЕ ПЕРВОГО ВХОДА!
--   Также создаётся регион "Алматы" (id=1, активен) + справочник комиссий/членов ОС.
-- ============================================================================

SET NAMES utf8mb4;

-- Таблица регионов/городов
CREATE TABLE IF NOT EXISTS regions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name_kz VARCHAR(255) NOT NULL COMMENT 'Название на казахском',
    name_ru VARCHAR(255) NOT NULL COMMENT 'Название на русском',
    code VARCHAR(50) UNIQUE NOT NULL COMMENT 'Код региона (almaty, astana, etc)',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Активен ли регион',
    settings JSON COMMENT 'Настройки региона',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица пользователей (для аутентификации)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL COMMENT 'Логин',
    email VARCHAR(255) UNIQUE NOT NULL COMMENT 'Email',
    password_hash VARCHAR(255) NOT NULL COMMENT 'Хеш пароля',       
    full_name VARCHAR(255) NOT NULL COMMENT 'ФИО',
    role ENUM('admin', 'moderator', 'viewer') DEFAULT 'viewer' COMMENT 'Роль',
    region_id INT COMMENT 'ID региона (NULL для админов)',
    member_id INT NULL COMMENT 'ID связанного члена ОС (os_members.id)',
    photo VARCHAR(500) NULL COMMENT 'Путь к фото профиля',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Активен ли пользователь',
    last_login TIMESTAMP NULL COMMENT 'Последний вход',
    totp_secret VARCHAR(64) NULL COMMENT 'Base32 секрет TOTP (2FA)',
    totp_enabled BOOLEAN DEFAULT FALSE COMMENT 'Включена ли двухфакторная аутентификация',
    totp_backup_codes TEXT NULL COMMENT 'JSON: хэши резервных кодов 2FA',
    password_history TEXT NULL COMMENT 'JSON: хэши последних 5 паролей (для запрета повторного использования)',
    telegram_chat_id VARCHAR(50) NULL DEFAULT NULL COMMENT 'Telegram chat_id для уведомлений',
    allowed_ips TEXT NULL COMMENT 'JSON массив разрешённых IP (null = без ограничений)',
    sms_phone VARCHAR(20) NULL COMMENT 'Телефон для SMS-уведомлений',
    push_enabled BOOLEAN DEFAULT TRUE COMMENT 'Разрешить web push-уведомления',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL,
    INDEX idx_region (region_id),
    INDEX idx_role (role),
    INDEX idx_is_active (is_active),
    INDEX idx_full_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица сессий
CREATE TABLE IF NOT EXISTS user_sessions (
    id VARCHAR(128) PRIMARY KEY COMMENT 'ID сессии',
    user_id INT NULL COMMENT 'ID пользователя (NULL для анонимных сессий)',
    data TEXT COMMENT 'Данные сессии (PdoSessionHandler)',
    ip_address VARCHAR(45) COMMENT 'IP адрес',
    user_agent TEXT COMMENT 'User Agent',
    expires_at TIMESTAMP NOT NULL COMMENT 'Время истечения',
    last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Последняя активность',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at),
    INDEX idx_last_active (last_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица комиссий (теперь с привязкой к региону)
CREATE TABLE IF NOT EXISTS commissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT NOT NULL COMMENT 'ID региона',
    name VARCHAR(255) NOT NULL COMMENT 'Название комиссии',
    description TEXT COMMENT 'Описание комиссии',
    color VARCHAR(7) DEFAULT '#0d6efd' COMMENT 'Цвет для отображения',
    sort_order INT DEFAULT 0 COMMENT 'Порядок сортировки',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
    INDEX idx_region (region_id),
    INDEX idx_name (name),
    INDEX idx_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица членов ОС (теперь с привязкой к региону)
CREATE TABLE IF NOT EXISTS os_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT NOT NULL COMMENT 'ID региона',
    full_name VARCHAR(255) NOT NULL COMMENT 'ФИО члена ОС',
    position VARCHAR(255) COMMENT 'Должность',
    position_kz VARCHAR(255) NULL COMMENT 'Должность (KZ)',
    organization VARCHAR(255) COMMENT 'Организация',
    organization_kz VARCHAR(255) NULL COMMENT 'Организация (KZ)',
    commission_id INT COMMENT 'ID комиссии',
    photo_path VARCHAR(500) COMMENT 'Путь к фото',
    email VARCHAR(255) COMMENT 'Email',
    phone VARCHAR(50) COMMENT 'Телефон',
    birth_date DATE NULL COMMENT 'Дата рождения',
    facebook VARCHAR(255) NULL COMMENT 'Facebook (URL или @handle)',
    whatsapp VARCHAR(50) NULL COMMENT 'WhatsApp (номер телефона)',
    instagram VARCHAR(255) NULL COMMENT 'Instagram (URL или @handle)',
    status ENUM('active', 'inactive') DEFAULT 'active' COMMENT 'Статус',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
    FOREIGN KEY (commission_id) REFERENCES commissions(id) ON DELETE SET NULL,
    INDEX idx_region (region_id),
    INDEX idx_commission (commission_id),
    INDEX idx_status (status),
    INDEX idx_region_status (region_id, status),
    INDEX idx_email (email),
    INDEX idx_full_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица входящих писем (обновленная структура с регионом)
CREATE TABLE IF NOT EXISTS incoming_letters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT NOT NULL COMMENT 'ID региона',
    seq INT NOT NULL COMMENT 'Регистрационный номер',
    date DATE NOT NULL COMMENT 'Дата получения',
    organization VARCHAR(255) NOT NULL COMMENT 'Организация отправитель',
    kk_number VARCHAR(100) NOT NULL COMMENT 'Номер письма (ҚК, .Н, ЖТ)',
    category ENUM('KK', 'N', 'JT', 'ZT') DEFAULT 'KK' COMMENT 'Категория',
    subject TEXT COMMENT 'Тема/Краткое содержание',
    note TEXT COMMENT 'Примечание',
    deadline_date DATE NULL COMMENT 'Срок ответа (15 рабочих дней от date)',
    linked_outgoing_id INT NULL COMMENT 'ID связанного исходящего письма',
    responds_to_outgoing_id INT NULL COMMENT 'ID исходящего, на которое это входящее является ответом',
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Мягкое удаление',
    deleted_by INT NULL COMMENT 'Кто удалил',
    created_by INT COMMENT 'ID пользователя, создавшего запись',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_region (region_id),
    INDEX idx_date (date),
    INDEX idx_seq (seq),
    INDEX idx_category (category),
    INDEX idx_linked (linked_outgoing_id),
    INDEX idx_region_date (region_id, date),
    INDEX idx_kk_number (kk_number),
    INDEX idx_deadline (deadline_date),
    INDEX idx_deleted (deleted_at),
    INDEX idx_responds_to (responds_to_outgoing_id),
    UNIQUE KEY unique_region_seq (region_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица исходящих писем (с регионом)
CREATE TABLE IF NOT EXISTS outgoing_letters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT NOT NULL COMMENT 'ID региона',
    seq INT NOT NULL COMMENT 'Порядковый номер',
    date DATE NOT NULL COMMENT 'Дата отправки',
    outgoing_number VARCHAR(100) NOT NULL COMMENT 'Исходящий номер',
    organization VARCHAR(255) NOT NULL COMMENT 'Организация получатель',
    incoming_ref_id INT NULL COMMENT 'ID связанного входящего письма (может отсутствовать)',
    outgoing_type ENUM('gov','jt','zt','recommend','other') NOT NULL DEFAULT 'gov' COMMENT 'Тип исходящего письма',
    subject TEXT COMMENT 'Тема/Краткое содержание',
    note TEXT COMMENT 'Примечание',
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Мягкое удаление',
    deleted_by INT NULL COMMENT 'Кто удалил',
    created_by INT COMMENT 'ID пользователя, создавшего запись',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
    FOREIGN KEY (incoming_ref_id) REFERENCES incoming_letters(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_region (region_id),
    INDEX idx_date (date),
    INDEX idx_seq (seq),
    INDEX idx_incoming_ref (incoming_ref_id),
    INDEX idx_region_date (region_id, date),
    INDEX idx_outgoing_number (outgoing_number),
    INDEX idx_deleted (deleted_at),
    UNIQUE KEY unique_region_seq (region_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица сканов писем
CREATE TABLE IF NOT EXISTS letter_scans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    letter_type ENUM('incoming', 'outgoing') NOT NULL COMMENT 'Тип письма',
    letter_id INT NOT NULL COMMENT 'ID письма',
    file_path VARCHAR(500) NULL COMMENT 'Путь к файлу на диске (uploads/scans/)',
    scan_data LONGTEXT NULL COMMENT 'Base64 данные скана (legacy, если нет file_path)',
    scan_type VARCHAR(50) NOT NULL COMMENT 'Тип файла (image/jpeg, application/pdf)',
    file_name VARCHAR(255) COMMENT 'Имя файла',
    file_size INT COMMENT 'Размер файла в байтах',
    version INT NOT NULL DEFAULT 1 COMMENT 'Номер версии файла',
    parent_scan_id INT NULL COMMENT 'ID предыдущей версии (NULL для первой)',
    -- влито из 2026_07_10_pending_fixes.sql (см. src/Services/ScanVersionService.php)
    replaced_by INT NULL COMMENT 'letter_scans.id заменяющего скана',
    replaced_at DATETIME NULL COMMENT 'Когда скан был заменён',
    uploaded_by INT NULL COMMENT 'users.id загрузившего (LEFT JOIN users)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_letter (letter_type, letter_id),
    INDEX idx_created_at (created_at),
    INDEX idx_parent (parent_scan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица связи писем с комиссиями
CREATE TABLE IF NOT EXISTS letter_commissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    letter_type ENUM('incoming', 'outgoing') NOT NULL,
    letter_id INT NOT NULL,
    commission_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (commission_id) REFERENCES commissions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_letter_commission (letter_type, letter_id, commission_id),
    INDEX idx_commission (commission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица логов действий
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT COMMENT 'ID пользователя',
    region_id INT COMMENT 'ID региона',
    action VARCHAR(100) NOT NULL COMMENT 'Действие',
    entity_type VARCHAR(50) COMMENT 'Тип сущности (letter, member, etc)',
    entity_id INT COMMENT 'ID сущности',
    details JSON COMMENT 'Детали действия',
    ip_address VARCHAR(45) COMMENT 'IP адрес',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_region (region_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at),
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ответственные члены ОС по письмам
CREATE TABLE IF NOT EXISTS letter_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    letter_type ENUM('incoming', 'outgoing') NOT NULL COMMENT 'Тип письма',
    letter_id INT NOT NULL COMMENT 'ID письма',
    member_id INT NOT NULL COMMENT 'ID члена ОС',
    is_lead BOOLEAN DEFAULT FALSE COMMENT 'Главный ли ответственный',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_letter_member (letter_type, letter_id, member_id),
    INDEX idx_letter (letter_type, letter_id),
    INDEX idx_member_id (member_id),
    INDEX idx_is_lead (is_lead),
    CONSTRAINT fk_letter_members_member FOREIGN KEY (member_id) REFERENCES os_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Адресаты писем (получатели)
CREATE TABLE IF NOT EXISTS letter_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    letter_type ENUM('incoming','outgoing') NOT NULL COMMENT 'Тип письма',
    letter_id INT NOT NULL COMMENT 'ID письма',
    recipient VARCHAR(255) NOT NULL COMMENT 'Адресат',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_letter (letter_type, letter_id),
    INDEX idx_recipient (recipient)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кэш приложения
CREATE TABLE IF NOT EXISTS cache (
    key_name VARCHAR(255) PRIMARY KEY,
    value LONGTEXT NOT NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    request_count INT DEFAULT 1,
    reset_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_ip_endpoint (ip_address, endpoint),
    INDEX idx_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CSRF-токены
CREATE TABLE IF NOT EXISTS csrf_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(255) UNIQUE NOT NULL,
    user_id INT,
    ip_address VARCHAR(45),
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Аудит (расширенная версия activity_logs)
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    region_id INT,
    table_name VARCHAR(100),
    operation VARCHAR(50) COMMENT 'INSERT, UPDATE, DELETE, EXPORT, DOWNLOAD',
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_region (region_id),
    INDEX idx_table (table_name),
    INDEX idx_record_id (record_id),
    INDEX idx_operation (operation),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-app уведомления
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    type VARCHAR(50) COMMENT 'info, warning, error, success',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_read (is_read),
    INDEX idx_created (created_at),
    INDEX idx_user_read (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Статистика
CREATE TABLE IF NOT EXISTS statistics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT NOT NULL,
    metric_name VARCHAR(100) NOT NULL,
    metric_value INT DEFAULT 0,
    metric_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_metric (region_id, metric_name, metric_date),
    INDEX idx_region (region_id),
    INDEX idx_date (metric_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Мероприятия
CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT COMMENT 'ID региона',
    title VARCHAR(255) NOT NULL COMMENT 'Название мероприятия',
    event_date DATE NOT NULL COMMENT 'Дата проведения',
    location VARCHAR(255) COMMENT 'Место проведения',
    location_url VARCHAR(500) NULL COMMENT 'Ссылка 2GIS на место проведения',
    participants_total INT DEFAULT 0 COMMENT 'Всего участников',
    attendance_percent DECIMAL(5,2) DEFAULT 0 COMMENT 'Процент явки',
    notes TEXT COMMENT 'Примечание',
    description TEXT NULL COMMENT 'Описание мероприятия (программа, цели)',
    created_by INT COMMENT 'ID пользователя, создавшего запись',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_region (region_id),
    INDEX idx_event_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_kpi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL COMMENT 'ID мероприятия',
    metric VARCHAR(255) NOT NULL COMMENT 'Название метрики',
    value_numeric DECIMAL(15,2) NULL COMMENT 'Числовое значение',
    value_text VARCHAR(255) NULL COMMENT 'Текстовое значение',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    INDEX idx_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_attendees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL COMMENT 'ID мероприятия',
    full_name VARCHAR(255) NOT NULL COMMENT 'ФИО участника',
    attended TINYINT(1) DEFAULT 0 COMMENT 'Присутствовал ли (1/0)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    INDEX idx_event (event_id),
    INDEX idx_full_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RSVP членов ОС на мероприятия (предварительный отклик)
CREATE TABLE IF NOT EXISTS event_rsvp (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL COMMENT 'ID мероприятия',
    member_id INT NOT NULL COMMENT 'ID члена ОС',
    status ENUM('confirmed','declined','maybe') NOT NULL DEFAULT 'confirmed' COMMENT 'Отклик',
    responded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_member (event_id, member_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES os_members(id) ON DELETE CASCADE,
    INDEX idx_event (event_id),
    INDEX idx_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Отклики членов ОС на мероприятия';

-- Очередь исходящих email
CREATE TABLE IF NOT EXISTS email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(255) NOT NULL COMMENT 'Email получателя',
    subject VARCHAR(255) NOT NULL COMMENT 'Тема',
    body_html LONGTEXT COMMENT 'HTML тело письма',
    body_text LONGTEXT COMMENT 'Текстовое тело письма',
    message_id VARCHAR(255) NULL COMMENT 'RFC 2822 Message-ID header',
    in_reply_to VARCHAR(255) NULL COMMENT 'References original Message-ID for replies',
    thread_id VARCHAR(255) NULL COMMENT 'Groups related emails in same thread',
    -- 'processing' влито из 2026_07_13_queue_processing.sql (атомарный захват строки воркером)
    status ENUM('queued','processing','sent','failed') NOT NULL DEFAULT 'queued' COMMENT 'Статус отправки',
    error TEXT NULL COMMENT 'Текст ошибки при отправке',
    -- влито из 2026_07_13_queue_processing.sql (см. src/Services/EmailService.php)
    processing_at DATETIME NULL COMMENT 'Когда строка захвачена воркером',
    attempts INT NOT NULL DEFAULT 0 COMMENT 'Число попыток отправки',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL COMMENT 'Время отправки',
    INDEX idx_status (status),
    INDEX idx_created (created_at),
    INDEX idx_status_created (status, created_at),
    INDEX idx_status_processing (status, processing_at),
    INDEX idx_dedup (recipient_email, status, created_at),
    INDEX idx_message_id (message_id),
    INDEX idx_thread_id (thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Комментарии к письмам
CREATE TABLE IF NOT EXISTS letter_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    letter_type ENUM('incoming','outgoing') NOT NULL COMMENT 'Тип письма',
    letter_id INT NOT NULL COMMENT 'ID письма',
    user_id INT NOT NULL COMMENT 'ID пользователя',
    comment TEXT NOT NULL COMMENT 'Текст комментария',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_letter (letter_type, letter_id),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Шаблоны писем
CREATE TABLE IF NOT EXISTS letter_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT NOT NULL COMMENT 'ID региона',
    name VARCHAR(255) NOT NULL COMMENT 'Название шаблона',
    letter_type ENUM('incoming','outgoing') NOT NULL COMMENT 'Тип письма',
    organization VARCHAR(255) COMMENT 'Организация по умолчанию',
    subject TEXT COMMENT 'Тема по умолчанию',
    note TEXT COMMENT 'Примечание по умолчанию',
    category ENUM('KK','N','JT','ZT') DEFAULT 'KK' COMMENT 'Категория',
    created_by INT COMMENT 'ID пользователя, создавшего шаблон',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_region_type (region_id, letter_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сброс пароля
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prt_token (token),
    INDEX idx_prt_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Telegram: вход и привязка аккаунта
CREATE TABLE IF NOT EXISTS telegram_login_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tlt_token (token),
    INDEX idx_tlt_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_link_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tlc_code (code),
    INDEX idx_tlc_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- Новые таблицы расширенного функционала
-- =============================================================================

-- Активные сессии пользователей (реальное использование user_sessions)
-- Таблица уже создана выше; сессии теперь записываются при логине.

-- Webhook-система
CREATE TABLE IF NOT EXISTS webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    secret VARCHAR(128) NOT NULL COMMENT 'Для подписи HMAC-SHA256',
    events JSON NOT NULL COMMENT 'Массив событий: letter.created, letter.updated, etc.',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT NOT NULL,
    event VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,
    response_code SMALLINT NULL,
    response_body TEXT NULL,
    delivered_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE,
    INDEX idx_webhook (webhook_id),
    INDEX idx_event (event),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API-ключи для машинного доступа
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    key_hash CHAR(64) NOT NULL COMMENT 'SHA-256 хэш ключа',
    permissions JSON NULL COMMENT 'NULL = все права; иначе массив эндпоинтов',
    last_used_at TIMESTAMP NULL,
    expires_at DATETIME NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_key_hash (key_hash),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Маршруты согласования писем
CREATE TABLE IF NOT EXISTS approval_chains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    letter_type ENUM('incoming','outgoing','both') DEFAULT 'both',
    steps JSON NOT NULL COMMENT '[{role, user_id, label}]',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
    INDEX idx_region (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS letter_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    letter_type ENUM('incoming','outgoing') NOT NULL,
    letter_id INT NOT NULL,
    chain_id INT NOT NULL,
    current_step INT DEFAULT 0,
    status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
    started_by INT NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (chain_id) REFERENCES approval_chains(id),
    FOREIGN KEY (started_by) REFERENCES users(id),
    INDEX idx_letter (letter_type, letter_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    approval_id INT NOT NULL,
    step_index INT NOT NULL,
    user_id INT NOT NULL,
    decision ENUM('approved','rejected') NOT NULL,
    comment TEXT NULL,
    decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (approval_id) REFERENCES letter_approvals(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_approval (approval_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS-очередь
CREATE TABLE IF NOT EXISTS sms_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- user_id / attempts влиты из 2026_07_10_pending_fixes.sql (см. src/Services/SmsService.php)
    user_id INT NULL COMMENT 'ID отправителя',
    phone VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    -- 'processing' влит из 2026_07_13_queue_processing.sql (атомарный захват строки воркером)
    status ENUM('queued','processing','sent','failed') DEFAULT 'queued',
    provider VARCHAR(50) DEFAULT 'mobizon',
    attempts INT NOT NULL DEFAULT 0 COMMENT 'Число попыток отправки (< 3)',
    processing_at DATETIME NULL COMMENT 'Когда строка захвачена воркером',
    error TEXT NULL,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_created (created_at),
    INDEX idx_status_processing (status, processing_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Web Push подписки (VAPID)
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint TEXT NOT NULL,
    p256dh VARCHAR(512) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сохранённые поисковые запросы
CREATE TABLE IF NOT EXISTS saved_searches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    params JSON NOT NULL COMMENT 'Параметры: q, type, date_from, date_to, status, etc.',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Правила авто-назначения писем
CREATE TABLE IF NOT EXISTS auto_assignment_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    letter_type ENUM('incoming','outgoing','both') DEFAULT 'incoming',
    conditions JSON NOT NULL COMMENT '{category, keywords[], sender_pattern}',
    commission_id INT NULL,
    member_id INT NULL,
    priority INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
    FOREIGN KEY (commission_id) REFERENCES commissions(id) ON DELETE SET NULL,
    FOREIGN KEY (member_id) REFERENCES os_members(id) ON DELETE SET NULL,
    INDEX idx_region (region_id),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- История входов (обнаружение аномалий)
CREATE TABLE IF NOT EXISTS login_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    username VARCHAR(100) NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    status ENUM('success','failed','blocked') NOT NULL,
    failure_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_ip (ip_address),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Обращения граждан (публичная форма appeal.php)
CREATE TABLE IF NOT EXISTS citizen_appeals (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    appeal_number      VARCHAR(50)  UNIQUE NULL COMMENT 'ОС-2026-0001',
    region_id          INT          NULL COMMENT 'Район ОС',
    full_name          VARCHAR(255) NOT NULL COMMENT 'ФИО заявителя',
    email              VARCHAR(255) NULL,
    phone              VARCHAR(50)  NULL,
    subject            VARCHAR(500) NOT NULL COMMENT 'Тема обращения',
    message            TEXT         NOT NULL COMMENT 'Текст обращения',
    category           ENUM('complaint','suggestion','question','request','other') DEFAULT 'other',
    status             ENUM('new','in_review','responded','closed') DEFAULT 'new',
    assigned_member_id INT          NULL COMMENT 'Назначенный член ОС',
    response_text      TEXT         NULL COMMENT 'Текст ответа',
    responded_at       TIMESTAMP    NULL,
    responded_by       INT          NULL COMMENT 'ID пользователя, ответившего',
    ip_address         VARCHAR(45)  NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id)          REFERENCES regions(id)    ON DELETE SET NULL,
    FOREIGN KEY (assigned_member_id) REFERENCES os_members(id) ON DELETE SET NULL,
    FOREIGN KEY (responded_by)       REFERENCES users(id)      ON DELETE SET NULL,
    INDEX idx_status  (status),
    INDEX idx_region  (region_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Обращения граждан';

-- Система миграций схемы
CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Автоматический расчёт deadline_date (15 рабочих дней, пн–пт)
-- =============================================================================

DROP FUNCTION IF EXISTS fn_add_working_days;
DROP TRIGGER IF EXISTS trg_incoming_letters_deadline_ins;
DROP TRIGGER IF EXISTS trg_incoming_letters_deadline_upd;

DELIMITER //
CREATE FUNCTION fn_add_working_days(start_date DATE, num_days INT)
RETURNS DATE
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE result DATE;
    DECLARE remaining INT;
    SET result = start_date;
    SET remaining = num_days;
    WHILE remaining > 0 DO
        SET result = DATE_ADD(result, INTERVAL 1 DAY);
        IF DAYOFWEEK(result) NOT IN (1, 7) THEN
            SET remaining = remaining - 1;
        END IF;
    END WHILE;
    RETURN result;
END //
DELIMITER ;

DELIMITER //
CREATE TRIGGER trg_incoming_letters_deadline_ins
BEFORE INSERT ON incoming_letters
FOR EACH ROW
BEGIN
    IF NEW.date IS NOT NULL THEN
        SET NEW.deadline_date = fn_add_working_days(NEW.date, 15);
    END IF;
END //
DELIMITER ;

DELIMITER //
CREATE TRIGGER trg_incoming_letters_deadline_upd
BEFORE UPDATE ON incoming_letters
FOR EACH ROW
BEGIN
    IF NEW.date IS NOT NULL AND (OLD.date IS NULL OR NEW.date <> OLD.date) THEN
        SET NEW.deadline_date = fn_add_working_days(NEW.date, 15);
    END IF;
END //
DELIMITER ;

-- =============================================================================
-- Начальные данные
-- =============================================================================

-- Вставка регионов (начиная с Алматы)
INSERT INTO regions (name_kz, name_ru, code, is_active) VALUES
('Алматы', 'Алматы', 'almaty', TRUE),
('Астана', 'Астана', 'astana', FALSE),
('Шымкент', 'Шымкент', 'shymkent', FALSE),
('Ақтөбе', 'Актобе', 'aktobe', FALSE),
('Қарағанды', 'Караганда', 'karaganda', FALSE),
('Тараз', 'Тараз', 'taraz', FALSE),
('Павлодар', 'Павлодар', 'pavlodar', FALSE),
('Қызылорда', 'Кызылорда', 'kyzylorda', FALSE),
('Семей', 'Семей', 'semey', FALSE),
('Қостанай', 'Костанай', 'kostanay', FALSE)
ON DUPLICATE KEY UPDATE name_ru=name_ru;

-- Вставка реальных комиссий и членов ОС для Алматы (region_id = 1)
START TRANSACTION;
UPDATE os_members SET status='inactive' WHERE region_id=1;
UPDATE commissions SET sort_order=1 WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы';
INSERT INTO commissions (region_id, name, sort_order, color) SELECT 1, 'Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы', 1, '#0d6efd' WHERE NOT EXISTS (SELECT 1 FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы');
UPDATE commissions SET sort_order=2 WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств';
INSERT INTO commissions (region_id, name, sort_order, color) SELECT 1, 'Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств', 2, '#0d6efd' WHERE NOT EXISTS (SELECT 1 FROM commissions WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств');
UPDATE commissions SET sort_order=3 WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности';
INSERT INTO commissions (region_id, name, sort_order, color) SELECT 1, 'Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности', 3, '#0d6efd' WHERE NOT EXISTS (SELECT 1 FROM commissions WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности');
UPDATE commissions SET sort_order=4 WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии';
INSERT INTO commissions (region_id, name, sort_order, color) SELECT 1, 'Комиссия №4 по предпринимательству, инвестициям, туризму и экологии', 4, '#0d6efd' WHERE NOT EXISTS (SELECT 1 FROM commissions WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии');
UPDATE commissions SET sort_order=5 WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи';
INSERT INTO commissions (region_id, name, sort_order, color) SELECT 1, 'Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи', 5, '#0d6efd' WHERE NOT EXISTS (SELECT 1 FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи');
UPDATE commissions SET sort_order=6 WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости';
INSERT INTO commissions (region_id, name, sort_order, color) SELECT 1, 'Комиссия №6 по здравоохранению, образованию и занятости', 6, '#0d6efd' WHERE NOT EXISTS (SELECT 1 FROM commissions WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости');
UPDATE os_members SET position='Президент ОФ “Парасатты ұрпақ үшін”, руководитель проектного офиса “Алматы - адалдық алаңы”', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), photo_path='uploads/photos/member_калимолдин_марат_маутенович.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Калимолдин Марат Маутенович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Калимолдин Марат Маутенович', 'Президент ОФ “Парасатты ұрпақ үшін”, руководитель проектного офиса “Алматы - адалдық алаңы”', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), 'uploads/photos/member_калимолдин_марат_маутенович.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Калимолдин Марат Маутенович');
UPDATE os_members SET position='Член Женского крыла партии AMANAT, Предприниматель в сфере общественного питания.', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), photo_path='uploads/photos/member_курманбек_асель.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Курманбек Асель' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Курманбек Асель', 'Член Женского крыла партии AMANAT, Предприниматель в сфере общественного питания.', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), 'uploads/photos/member_курманбек_асель.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Курманбек Асель');
UPDATE os_members SET position='Президент Альянса Инновационно-индустриального развития, Основатель Академии IABA', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), photo_path='uploads/photos/member_ли_елена_эдуардовна.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Ли Елена Эдуардовна' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Ли Елена Эдуардовна', 'Президент Альянса Инновационно-индустриального развития, Основатель Академии IABA', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), 'uploads/photos/member_ли_елена_эдуардовна.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Ли Елена Эдуардовна');
UPDATE os_members SET position='Сеть ресторанов"Lanzhou Company", владелец, генеральный директор, Председатель Центрально-азиатской ассоциации франчайзинга', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), photo_path='uploads/photos/member_майгарина_гульбану_есеновна.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Майгарина Гульбану Есеновна' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Майгарина Гульбану Есеновна', 'Сеть ресторанов"Lanzhou Company", владелец, генеральный директор, Председатель Центрально-азиатской ассоциации франчайзинга', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), 'uploads/photos/member_майгарина_гульбану_есеновна.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Майгарина Гульбану Есеновна');
UPDATE os_members SET position='ОЮЛ «Ассоциация микрофинансовых организаций Казахстана», председатель', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), photo_path='uploads/photos/member_омарханов_ербол_секенович.jpeg', status='active', region_id=1 WHERE region_id=1 AND full_name='Омарханов Ербол Секенович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Омарханов Ербол Секенович', 'ОЮЛ «Ассоциация микрофинансовых организаций Казахстана», председатель', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), 'uploads/photos/member_омарханов_ербол_секенович.jpeg', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Омарханов Ербол Секенович');
UPDATE os_members SET position='Директор ТОО"Almaty Sport Family"', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), photo_path='uploads/photos/member_сеитов_совет_саутович.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Сеитов Совет Саутович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Сеитов Совет Саутович', 'Директор ТОО"Almaty Sport Family"', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), 'uploads/photos/member_сеитов_совет_саутович.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Сеитов Совет Саутович');
UPDATE os_members SET position='Председатель комиссии. Председатель совета директоров ТОО «АЗМК GROUP»', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), photo_path='uploads/photos/member_шардинов_шухрад_ахметжанович.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Шардинов Шухрад Ахметжанович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Шардинов Шухрад Ахметжанович', 'Председатель комиссии. Председатель совета директоров ТОО «АЗМК GROUP»', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №1 по экономике, финансам, активам, развитию государственного управления, общественной и сейсмической безопасности, цифровизации и АО "Центр развития города Алматы", районные акиматы' LIMIT 1), 'uploads/photos/member_шардинов_шухрад_ахметжанович.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Шардинов Шухрад Ахметжанович');
UPDATE os_members SET position='И. о. директора государственного фонда развития молодежной политики города Алматы', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств' LIMIT 1), photo_path='uploads/photos/member_анарбаева_багдат_бекболаткызы.jpeg', status='active', region_id=1 WHERE region_id=1 AND full_name='Анарбаева Багдат Бекболаткызы' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Анарбаева Багдат Бекболаткызы', 'И. о. директора государственного фонда развития молодежной политики города Алматы', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств' LIMIT 1), 'uploads/photos/member_анарбаева_багдат_бекболаткызы.jpeg', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Анарбаева Багдат Бекболаткызы');
UPDATE os_members SET position='Учредитель, Директор в ТОО «Qazaqstan Innovation Corporation»', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств' LIMIT 1), photo_path='uploads/photos/member_андреев_андрей_геннадьевич.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Андреев Андрей Геннадьевич' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Андреев Андрей Геннадьевич', 'Учредитель, Директор в ТОО «Qazaqstan Innovation Corporation»', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств' LIMIT 1), 'uploads/photos/member_андреев_андрей_геннадьевич.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Андреев Андрей Геннадьевич');
UPDATE os_members SET position='ТОО «BAU Group», директор', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств' LIMIT 1), photo_path='uploads/photos/member_исмагулов_марлен_бекшойынович.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Исмагулов Марлен Бекшойынович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Исмагулов Марлен Бекшойынович', 'ТОО «BAU Group», директор', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств' LIMIT 1), 'uploads/photos/member_исмагулов_марлен_бекшойынович.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Исмагулов Марлен Бекшойынович');
UPDATE os_members SET position='Председатель комиссии. ТОО"UV Group", Генеральный директор', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств' LIMIT 1), photo_path='uploads/photos/member_лактюшин_дмитрий_николаевич.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Лактюшин Дмитрий Николаевич' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Лактюшин Дмитрий Николаевич', 'Председатель комиссии. ТОО"UV Group", Генеральный директор', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств' LIMIT 1), 'uploads/photos/member_лактюшин_дмитрий_николаевич.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Лактюшин Дмитрий Николаевич');
UPDATE os_members SET position='Заместитель председателя комиссии. Директор ТОО «Timus Development»', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств' LIMIT 1), photo_path='uploads/photos/member_нуртаев_тимур_таймасович.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Нуртаев Тимур Таймасович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Нуртаев Тимур Таймасович', 'Заместитель председателя комиссии. Директор ТОО «Timus Development»', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств' LIMIT 1), 'uploads/photos/member_нуртаев_тимур_таймасович.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Нуртаев Тимур Таймасович');
UPDATE os_members SET position='Директор ЧУ «Институт профессиональных оценщиков Казахстана», директор ТОО «Market-Консалтинг»', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств' LIMIT 1), photo_path='uploads/photos/member_увайсова_хадижат_мусанниповна.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Увайсова Хадижат Мусанниповна' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Увайсова Хадижат Мусанниповна', 'Директор ЧУ «Институт профессиональных оценщиков Казахстана», директор ТОО «Market-Консалтинг»', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №2 по строительству, архитектуре, урбанистике, земельным отношениям и развитию общественных пространств' LIMIT 1), 'uploads/photos/member_увайсова_хадижат_мусанниповна.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Увайсова Хадижат Мусанниповна');
UPDATE os_members SET position='Казахстанская партия зелёных «Байтақ», член политического совета', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности' LIMIT 1), photo_path='uploads/photos/member_иманалиев_аскар_муратович.jpeg', status='active', region_id=1 WHERE region_id=1 AND full_name='Иманалиев Аскар Муратович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Иманалиев Аскар Муратович', 'Казахстанская партия зелёных «Байтақ», член политического совета', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности' LIMIT 1), 'uploads/photos/member_иманалиев_аскар_муратович.jpeg', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Иманалиев Аскар Муратович');
UPDATE os_members SET position='Председатель комиссии. ТОО"Qazaq Beton", Вице-президент попечительского совета', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности' LIMIT 1), photo_path='uploads/photos/member_кабашев_аскат_рахимжанович.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Кабашев Аскат Рахимжанович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Кабашев Аскат Рахимжанович', 'Председатель комиссии. ТОО"Qazaq Beton", Вице-президент попечительского совета', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности' LIMIT 1), 'uploads/photos/member_кабашев_аскат_рахимжанович.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Кабашев Аскат Рахимжанович');
UPDATE os_members SET position='Заместитель председателя комиссии. КГКП «Алматинский технологический колледж» Управления образования города Алматы, директор. Председатель Совета директоров колледжей г. Алматы.', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности' LIMIT 1), photo_path='uploads/photos/member_карагулов_ерулан_серикович.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Карагулов Ерулан Серикович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Карагулов Ерулан Серикович', 'Заместитель председателя комиссии. КГКП «Алматинский технологический колледж» Управления образования города Алматы, директор. Председатель Совета директоров колледжей г. Алматы.', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности' LIMIT 1), 'uploads/photos/member_карагулов_ерулан_серикович.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Карагулов Ерулан Серикович');
UPDATE os_members SET position='«Комьюнити центр» Жетысуского района, директор. Руководитель волонтёрского проекта по противодействию коррупции «ANTIKOR VOLUNTEERS»', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности' LIMIT 1), photo_path='uploads/photos/member_кенесов_акежан_бакытулы.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Кенесов Акежан Бакытулы' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Кенесов Акежан Бакытулы', '«Комьюнити центр» Жетысуского района, директор. Руководитель волонтёрского проекта по противодействию коррупции «ANTIKOR VOLUNTEERS»', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности' LIMIT 1), 'uploads/photos/member_кенесов_акежан_бакытулы.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Кенесов Акежан Бакытулы');
UPDATE os_members SET position='АО"НАК Казатомпром", АО"ИМиО"(Институт Металлургии и Обогащения)', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности' LIMIT 1), photo_path='uploads/photos/member_кожамсугиров_ермек_амирович.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Кожамсугиров Ермек Амирович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Кожамсугиров Ермек Амирович', 'АО"НАК Казатомпром", АО"ИМиО"(Институт Металлургии и Обогащения)', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности' LIMIT 1), 'uploads/photos/member_кожамсугиров_ермек_амирович.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Кожамсугиров Ермек Амирович');
UPDATE os_members SET position='Заместитель председателя Алматинского городского филиала Народной партии Казахстана', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности' LIMIT 1), photo_path='uploads/photos/member_султангалиев_тамерлан_мэлсович.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Султангалиев Тамерлан Мэлсович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Султангалиев Тамерлан Мэлсович', 'Заместитель председателя Алматинского городского филиала Народной партии Казахстана', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №3 по коммунальной инфраструктуре, энергетике, водоснабжению и городской мобильности' LIMIT 1), 'uploads/photos/member_султангалиев_тамерлан_мэлсович.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Султангалиев Тамерлан Мэлсович');
UPDATE os_members SET position='Председатель комиссии. Директор ТОО «Yunur Consulting», Председатель ОФ «Хак-Назар»', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии' LIMIT 1), photo_path='uploads/photos/member_жакупов_нуржан_бауржанович.jpeg', status='active', region_id=1 WHERE region_id=1 AND full_name='Жакупов Нуржан Бауржанович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Жакупов Нуржан Бауржанович', 'Председатель комиссии. Директор ТОО «Yunur Consulting», Председатель ОФ «Хак-Назар»', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии' LIMIT 1), 'uploads/photos/member_жакупов_нуржан_бауржанович.jpeg', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Жакупов Нуржан Бауржанович');
UPDATE os_members SET position='Представитель ОО"Jerdin dostary", Директор по развитию ИС zakon. kz', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии' LIMIT 1), photo_path='uploads/photos/member_капасов_думан_жандосович.jpeg', status='active', region_id=1 WHERE region_id=1 AND full_name='Капасов Думан Жандосович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Капасов Думан Жандосович', 'Представитель ОО"Jerdin dostary", Директор по развитию ИС zakon. kz', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии' LIMIT 1), 'uploads/photos/member_капасов_думан_жандосович.jpeg', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Капасов Думан Жандосович');
UPDATE os_members SET position='Генеральный директор ТОО «Фирма-Тигрохауд»', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии' LIMIT 1), photo_path='uploads/photos/member_курабаева_жанар_тукешкызы.jpeg', status='active', region_id=1 WHERE region_id=1 AND full_name='Курабаева Жанар Тукешкызы' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Курабаева Жанар Тукешкызы', 'Генеральный директор ТОО «Фирма-Тигрохауд»', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии' LIMIT 1), 'uploads/photos/member_курабаева_жанар_тукешкызы.jpeg', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Курабаева Жанар Тукешкызы');
UPDATE os_members SET position='ТОО «Гостиница Жетысу», директор', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии' LIMIT 1), photo_path='uploads/photos/member_мейрманов_тилектес_серикович.jpeg', status='active', region_id=1 WHERE region_id=1 AND full_name='Мейрманов Тилектес Серикович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Мейрманов Тилектес Серикович', 'ТОО «Гостиница Жетысу», директор', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии' LIMIT 1), 'uploads/photos/member_мейрманов_тилектес_серикович.jpeg', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Мейрманов Тилектес Серикович');
UPDATE os_members SET position='Заместитель председателя комиссии. АО"Центр развития Алматы", руководитель ПО"Зеленый Алматы"', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии' LIMIT 1), photo_path='uploads/photos/member_мухамеджанов_евгений_викторович.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Мухамеджанов Евгений Викторович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Мухамеджанов Евгений Викторович', 'Заместитель председателя комиссии. АО"Центр развития Алматы", руководитель ПО"Зеленый Алматы"', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии' LIMIT 1), 'uploads/photos/member_мухамеджанов_евгений_викторович.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Мухамеджанов Евгений Викторович');
UPDATE os_members SET position='Частный судебный исполнитель исполнительного округа г. Алматы', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии' LIMIT 1), photo_path=NULL, status='active', region_id=1 WHERE region_id=1 AND full_name='Сахов Нұрғали Қалмуратұлы' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Сахов Нұрғали Қалмуратұлы', 'Частный судебный исполнитель исполнительного округа г. Алматы', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №4 по предпринимательству, инвестициям, туризму и экологии' LIMIT 1), NULL, 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Сахов Нұрғали Қалмуратұлы');
UPDATE os_members SET position='PhD, председатель ОЮЛ"Гражданский Альянс Алматы", профессор КБТУ, член НПМ при Уполномоченном по правам Человека в РК, член КоорСовета по взаимодействия с НПО при Министерстве культуры и информации РК.', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), photo_path='uploads/photos/member_абдыхалыков_каиржан_саясатович.jpeg', status='active', region_id=1 WHERE region_id=1 AND full_name='Абдыхалыков Каиржан Саясатович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Абдыхалыков Каиржан Саясатович', 'PhD, председатель ОЮЛ"Гражданский Альянс Алматы", профессор КБТУ, член НПМ при Уполномоченном по правам Человека в РК, член КоорСовета по взаимодействия с НПО при Министерстве культуры и информации РК.', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), 'uploads/photos/member_абдыхалыков_каиржан_саясатович.jpeg', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Абдыхалыков Каиржан Саясатович');
UPDATE os_members SET position='Профессиональный тренер, ментор, фасилитатор', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), photo_path=NULL, status='active', region_id=1 WHERE region_id=1 AND full_name='Алпысова Сауле Муратовна' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Алпысова Сауле Муратовна', 'Профессиональный тренер, ментор, фасилитатор', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), NULL, 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Алпысова Сауле Муратовна');
UPDATE os_members SET position='Руководитель информационного агентства «Қамшы»', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), photo_path='uploads/photos/member_біләл_қуаныш.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Біләл Қуаныш' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Біләл Қуаныш', 'Руководитель информационного агентства «Қамшы»', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), 'uploads/photos/member_біләл_қуаныш.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Біләл Қуаныш');
UPDATE os_members SET position='Председатель МК «Жастар Рухы» филиала по городу Алматы', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), photo_path='uploads/photos/member_ділдәбек_дидарбек_жұмағалиұлы.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Ділдәбек Дидарбек Жұмағалиұлы' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Ділдәбек Дидарбек Жұмағалиұлы', 'Председатель МК «Жастар Рухы» филиала по городу Алматы', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), 'uploads/photos/member_ділдәбек_дидарбек_жұмағалиұлы.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Ділдәбек Дидарбек Жұмағалиұлы');
UPDATE os_members SET position='Заместитель председателя комиссии. ОО"Республиканский этнокультурный центр уйгуров Казахстана", заместитель председателя', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), photo_path='uploads/photos/member_кайрыев_рустам_абдусаламович.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Кайрыев Рустам Абдусаламович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Кайрыев Рустам Абдусаламович', 'Заместитель председателя комиссии. ОО"Республиканский этнокультурный центр уйгуров Казахстана", заместитель председателя', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), 'uploads/photos/member_кайрыев_рустам_абдусаламович.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Кайрыев Рустам Абдусаламович');
UPDATE os_members SET position='Руководитель ТОО «Alliance school»', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), photo_path='uploads/photos/member_мурзаева_алина_александровна.jpeg', status='active', region_id=1 WHERE region_id=1 AND full_name='Мурзаева Алина Александровна' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Мурзаева Алина Александровна', 'Руководитель ТОО «Alliance school»', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), 'uploads/photos/member_мурзаева_алина_александровна.jpeg', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Мурзаева Алина Александровна');
UPDATE os_members SET position='Председатель комиссии. Нотариус г. Алматы', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), photo_path='uploads/photos/member_рысбеков_марлен_жаксыбекович.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Рысбеков Марлен Жаксыбекович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Рысбеков Марлен Жаксыбекович', 'Председатель комиссии. Нотариус г. Алматы', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №5 по общественному развитию, культуре, спорту, религии и молодежи' LIMIT 1), 'uploads/photos/member_рысбеков_марлен_жаксыбекович.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Рысбеков Марлен Жаксыбекович');
UPDATE os_members SET position='Директор Алматинского филиала"Казахстанская ассоциация непрерывного образования"', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости' LIMIT 1), photo_path='uploads/photos/member_айтхожина_любовь_викторовна.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Айтхожина Любовь Викторовна' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Айтхожина Любовь Викторовна', 'Директор Алматинского филиала"Казахстанская ассоциация непрерывного образования"', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости' LIMIT 1), 'uploads/photos/member_айтхожина_любовь_викторовна.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Айтхожина Любовь Викторовна');
UPDATE os_members SET position='Председатель комиссии. Председатель Совета директоров АО «Научный центр акушерства, гинекологии и перинатологии»', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости' LIMIT 1), photo_path='uploads/photos/member_аманжолова_зауреш_джуманалиевна.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Аманжолова Зауреш Джуманалиевна' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Аманжолова Зауреш Джуманалиевна', 'Председатель комиссии. Председатель Совета директоров АО «Научный центр акушерства, гинекологии и перинатологии»', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости' LIMIT 1), 'uploads/photos/member_аманжолова_зауреш_джуманалиевна.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Аманжолова Зауреш Джуманалиевна');
UPDATE os_members SET position='Президент Фонда развития молодежи РК «Дорогу молодым», руководитель информационного агентства Independ. kz', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости' LIMIT 1), photo_path='uploads/photos/member_гасанов_рафаэль_руфикович.jpeg', status='active', region_id=1 WHERE region_id=1 AND full_name='Гасанов Рафаэль Руфикович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Гасанов Рафаэль Руфикович', 'Президент Фонда развития молодежи РК «Дорогу молодым», руководитель информационного агентства Independ. kz', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости' LIMIT 1), 'uploads/photos/member_гасанов_рафаэль_руфикович.jpeg', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Гасанов Рафаэль Руфикович');
UPDATE os_members SET position='Заместитель председателя комиссии. Член Алматинского общества инвалидов по реабилитации людей с инвалидностью, Общественный фонд Центр Социальных Инклюзивных программ. главный PR специалист', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости' LIMIT 1), photo_path='uploads/photos/member_джепка_богдан_игоревич.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Джепка Богдан Игоревич' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Джепка Богдан Игоревич', 'Заместитель председателя комиссии. Член Алматинского общества инвалидов по реабилитации людей с инвалидностью, Общественный фонд Центр Социальных Инклюзивных программ. главный PR специалист', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости' LIMIT 1), 'uploads/photos/member_джепка_богдан_игоревич.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Джепка Богдан Игоревич');
UPDATE os_members SET position='Председатель РОО"Патриотическое движение «Декабрьская правда»"', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости' LIMIT 1), photo_path='uploads/photos/member_курымбаев_болат_токтобаевич.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Курымбаев Болат Токтобаевич' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Курымбаев Болат Токтобаевич', 'Председатель РОО"Патриотическое движение «Декабрьская правда»"', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости' LIMIT 1), 'uploads/photos/member_курымбаев_болат_токтобаевич.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Курымбаев Болат Токтобаевич');
UPDATE os_members SET position='Кандидат филологических наук, директор Института языкознания имени А. Байтурсынулы', organization='', commission_id=(SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости' LIMIT 1), photo_path='uploads/photos/member_фазылжан_анар_муратовна.png', status='active', region_id=1 WHERE region_id=1 AND full_name='Фазылжан Анар Муратовна' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'Фазылжан Анар Муратовна', 'Кандидат филологических наук, директор Института языкознания имени А. Байтурсынулы', '', (SELECT id FROM commissions WHERE region_id=1 AND name='Комиссия №6 по здравоохранению, образованию и занятости' LIMIT 1), 'uploads/photos/member_фазылжан_анар_муратовна.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='Фазылжан Анар Муратовна');
UPDATE os_members SET position='Председатель Общественного совета', organization='Центральный аппарат партии "Respublica", советник руководителя', commission_id=NULL, photo_path='uploads/photos/member_хасенов_махамбет_кабдулкаирович.png', status='active', region_id=1 WHERE region_id=1 AND full_name='ХАСЕНОВ Махамбет Кабдулкаирович' LIMIT 1;
INSERT INTO os_members (region_id, full_name, position, organization, commission_id, photo_path, status) SELECT 1, 'ХАСЕНОВ Махамбет Кабдулкаирович', 'Председатель Общественного совета', 'Центральный аппарат партии "Respublica", советник руководителя', NULL, 'uploads/photos/member_хасенов_махамбет_кабдулкаирович.png', 'active' WHERE NOT EXISTS (SELECT 1 FROM os_members WHERE region_id=1 AND full_name='ХАСЕНОВ Махамбет Кабдулкаирович');
COMMIT;



-- Пользователи по умолчанию (пароль: admin123). Смените пароль после первого входа!
INSERT INTO users (username, email, password_hash, full_name, role, region_id, is_active) VALUES
('admin', 'admin@os.kz', '$2y$10$TbciE4B1a553Q.yz6lrMq.KmpA74QGWOxpPrn6nAHk8do3aDfk6mK', 'Администратор системы', 'admin', NULL, TRUE),
('moderator', 'moderator@os.kz', '$2y$10$TbciE4B1a553Q.yz6lrMq.KmpA74QGWOxpPrn6nAHk8do3aDfk6mK', 'Модератор', 'moderator', 1, TRUE),
('viewer', 'viewer@os.kz', '$2y$10$TbciE4B1a553Q.yz6lrMq.KmpA74QGWOxpPrn6nAHk8do3aDfk6mK', 'Просмотрщик', 'viewer', 1, TRUE)
ON DUPLICATE KEY UPDATE username=username;

-- =============================================================================
-- Дополнительные составные индексы (результат аудита)
-- =============================================================================

CREATE INDEX IF NOT EXISTS idx_incoming_region_deleted_date ON incoming_letters (region_id, deleted_at, date DESC);
CREATE INDEX IF NOT EXISTS idx_outgoing_region_deleted_date ON outgoing_letters (region_id, deleted_at, date DESC);
CREATE INDEX IF NOT EXISTS idx_letter_comments_created_at ON letter_comments (created_at);

-- =============================================================================
-- FULLTEXT индексы для поиска
-- MySQL не поддерживает "ADD FULLTEXT INDEX IF NOT EXISTS" — используем процедуру.
-- =============================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS _add_ft $$
CREATE PROCEDURE _add_ft(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_cols VARCHAR(255))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name   = p_table
          AND index_name   = p_index
          AND index_type   = 'FULLTEXT'
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD FULLTEXT INDEX `', p_index, '` (', p_cols, ')');
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

DELIMITER ;

-- ВНИМАНИЕ: состав колонок ниже — уже ИСПРАВЛЕННЫЙ (влито из 2026_07_13_fulltext.sql).
-- Код GlobalSearchService выполняет MATCH(subject, note, organization) для писем и
-- MATCH(full_name) для членов ОС, а имена индексов (ft_incoming_search, ft_outgoing_search,
-- ft_members_fullname, ft_events_search, ft_comments_comment) захардкожены в
-- GlobalSearchService::fulltextIndexExists. MySQL требует точного совпадения списка
-- колонок MATCH() с составом FULLTEXT-индекса (иначе ERROR 1191). Поэтому здесь сразу
-- правильный состав — старые (organization, kk_number, ...) НЕ создаём, DROP не нужен (база пустая).
CALL _add_ft('incoming_letters', 'ft_incoming_search',   'subject, note, organization');
CALL _add_ft('outgoing_letters', 'ft_outgoing_search',   'subject, note, organization');
CALL _add_ft('os_members',       'ft_members_fullname',  'full_name');
CALL _add_ft('events',           'ft_events_search',     'title, location, notes');
CALL _add_ft('letter_comments',  'ft_comments_comment',  'comment');
CALL _add_ft('citizen_appeals',  'ft_appeals_search',    'full_name, subject, message');

DROP PROCEDURE IF EXISTS _add_ft;

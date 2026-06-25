-- !!! УСТАРЕЛО: каноническая схема — deploy_database.sql !!!
-- Этот файл оставлен только для истории. Для развёртывания и как источник
-- истины по структуре БД используйте deploy_database.sql.
-- ===== БАЗА ДАННЫХ ДЛЯ MYSQL =====
-- Журнал Общественного Совета г. Алматы
-- Версия: 2.0
-- Дата: 2024

-- Создание базы данных
CREATE DATABASE IF NOT EXISTS os_journal 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE os_journal;

-- ===== ТАБЛИЦА РЕГИОНОВ =====
CREATE TABLE IF NOT EXISTS regions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== ТАБЛИЦА ПОЛЬЗОВАТЕЛЕЙ =====
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    full_name VARCHAR(255),
    role ENUM('admin', 'moderator', 'viewer') DEFAULT 'viewer',
    region_id INT,
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id),
    INDEX idx_username (username),
    INDEX idx_is_active (is_active),
    INDEX idx_region_id (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== ТАБЛИЦА СЕССИЙ ПОЛЬЗОВАТЕЛЕЙ =====
CREATE TABLE IF NOT EXISTS user_sessions (
    id VARCHAR(64) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== ТАБЛИЦА КОМИССИЙ =====
CREATE TABLE IF NOT EXISTS commissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    color VARCHAR(7) DEFAULT '#0d6efd',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id),
    INDEX idx_region_id (region_id),
    INDEX idx_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== ТАБЛИЦА ЧЛЕНОВ ОС =====
CREATE TABLE IF NOT EXISTS os_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT,
    commission_id INT,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(20),
    birth_date DATE NULL,
    facebook VARCHAR(255) NULL,
    whatsapp VARCHAR(50) NULL,
    instagram VARCHAR(255) NULL,
    position VARCHAR(255),
    organization VARCHAR(255),
    photo_path VARCHAR(500),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id),
    FOREIGN KEY (commission_id) REFERENCES commissions(id),
    INDEX idx_region_id (region_id),
    INDEX idx_commission_id (commission_id),
    INDEX idx_status (status),
    INDEX idx_region_status (region_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== ТАБЛИЦА ВХОДЯЩИХ ПИСЕМ =====
CREATE TABLE IF NOT EXISTS incoming_letters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT,
    seq INT NOT NULL,
    date DATE NOT NULL,
    organization VARCHAR(255),
    kk_number VARCHAR(50),
    category ENUM('KK', 'N', 'JT', 'ZT') DEFAULT 'KK',
    subject TEXT,
    note TEXT,
    responds_to_outgoing_id INT,
    linked_outgoing_id INT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_region_id (region_id),
    INDEX idx_date (date),
    INDEX idx_seq (seq),
    INDEX idx_region_date (region_id, date),
    INDEX idx_kk_number (kk_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== ТАБЛИЦА ИСХОДЯЩИХ ПИСЕМ =====
CREATE TABLE IF NOT EXISTS outgoing_letters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT,
    seq INT NOT NULL,
    date DATE NOT NULL,
    outgoing_number VARCHAR(50),
    organization VARCHAR(255),
    incoming_ref_id INT,
    outgoing_type ENUM('gov', 'jt', 'zt', 'recommend', 'other') DEFAULT 'gov',
    subject TEXT,
    note TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id),
    FOREIGN KEY (incoming_ref_id) REFERENCES incoming_letters(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_region_id (region_id),
    INDEX idx_date (date),
    INDEX idx_seq (seq),
    INDEX idx_region_date (region_id, date),
    INDEX idx_outgoing_number (outgoing_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== ТАБЛИЦА ЧЛЕНОВ ПИСЬМА =====
CREATE TABLE IF NOT EXISTS letter_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    letter_type ENUM('incoming', 'outgoing') NOT NULL,
    letter_id INT NOT NULL,
    member_id INT NOT NULL,
    is_lead BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES os_members(id) ON DELETE CASCADE,
    INDEX idx_letter (letter_type, letter_id),
    INDEX idx_member_id (member_id),
    INDEX idx_is_lead (is_lead)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== ТАБЛИЦА ПОЛУЧАТЕЛЕЙ ПИСЬМА =====
CREATE TABLE IF NOT EXISTS letter_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    letter_type ENUM('incoming', 'outgoing') NOT NULL,
    letter_id INT NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_letter (letter_type, letter_id),
    INDEX idx_recipient (recipient)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== ТАБЛИЦА СКАНОВ ПИСЕМ =====
CREATE TABLE IF NOT EXISTS letter_scans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    letter_type ENUM('incoming', 'outgoing') NOT NULL,
    letter_id INT NOT NULL,
    file_path VARCHAR(500),
    scan_data LONGBLOB,
    scan_type VARCHAR(50),
    file_name VARCHAR(255),
    file_size INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_letter (letter_type, letter_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== ТАБЛИЦА ЛОГОВ АУДИТА =====
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(255) NOT NULL,
    entity_id INT NOT NULL,
    action ENUM('CREATE', 'UPDATE', 'DELETE', 'VIEW') NOT NULL,
    old_data JSON,
    new_data JSON,
    user_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_table_name (table_name),
    INDEX idx_entity_id (entity_id),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== ВСТАВКА ТЕСТОВЫХ ДАННЫХ =====

-- Регионы
INSERT INTO regions (name, description, sort_order) VALUES
('Алатауский район', 'Алатауский район г. Алматы', 1),
('Алмалинский район', 'Алмалинский район г. Алматы', 2),
('Аузовский район', 'Аузовский район г. Алматы', 3),
('Бостандыский район', 'Бостандыский район г. Алматы', 4),
('Жетысуский район', 'Жетысуский район г. Алматы', 5),
('Медеуский район', 'Медеуский район г. Алматы', 6),
('Наурузбайский район', 'Наурузбайский район г. Алматы', 7),
('Турксибский район', 'Турксибский район г. Алматы', 8);

-- Пользователи (пароль: password)
INSERT INTO users (username, password_hash, email, full_name, role, region_id, is_active) VALUES
('admin', '$2y$10$YIjlrBxvxK8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8', 'admin@os-almaty.kz', 'Администратор', 'admin', NULL, TRUE),
('moderator', '$2y$10$YIjlrBxvxK8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8', 'moderator@os-almaty.kz', 'Модератор', 'moderator', 1, TRUE),
('viewer', '$2y$10$YIjlrBxvxK8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8.8', 'viewer@os-almaty.kz', 'Просмотрщик', 'viewer', 1, TRUE);

-- Комиссии
INSERT INTO commissions (region_id, name, description, color, sort_order) VALUES
(1, 'Комиссия по образованию', 'Занимается вопросами образования', '#2196F3', 1),
(1, 'Комиссия по здравоохранению', 'Занимается вопросами здравоохранения', '#4CAF50', 2),
(1, 'Комиссия по культуре', 'Занимается вопросами культуры', '#FF9800', 3),
(1, 'Комиссия по спорту', 'Занимается вопросами спорта', '#F44336', 4),
(1, 'Комиссия по экологии', 'Занимается вопросами экологии', '#8BC34A', 5),
(1, 'Комиссия по безопасности', 'Занимается вопросами безопасности', '#9C27B0', 6);

-- Члены ОС
INSERT INTO os_members (region_id, commission_id, full_name, email, phone, position, organization, status) VALUES
(1, 1, 'Иванов Иван Иванович', 'ivanov@example.com', '+7 (727) 123-45-67', 'Директор', 'ООО Компания 1', 'active'),
(1, 1, 'Петров Петр Петрович', 'petrov@example.com', '+7 (727) 234-56-78', 'Начальник', 'ООО Компания 2', 'active'),
(1, 2, 'Сидоров Сидор Сидорович', 'sidorov@example.com', '+7 (727) 345-67-89', 'Главный врач', 'Больница №1', 'active'),
(1, 2, 'Кузнецов Кузьма Кузьмич', 'kuznetsov@example.com', '+7 (727) 456-78-90', 'Врач', 'Поликлиника №1', 'active'),
(1, 3, 'Соколов Сокол Соколович', 'sokolov@example.com', '+7 (727) 567-89-01', 'Директор', 'Дворец культуры', 'active'),
(1, 3, 'Морозов Мороз Морозович', 'morozov@example.com', '+7 (727) 678-90-12', 'Режиссер', 'Театр', 'active'),
(1, 4, 'Волков Волк Волкович', 'volkov@example.com', '+7 (727) 789-01-23', 'Тренер', 'Спортивный клуб', 'active'),
(1, 4, 'Лисицын Лис Лисович', 'lisitsyn@example.com', '+7 (727) 890-12-34', 'Спортсмен', 'Спортивная школа', 'active'),
(1, 5, 'Медведев Медведь Медведович', 'medvedev@example.com', '+7 (727) 901-23-45', 'Эколог', 'Экологический центр', 'active'),
(1, 5, 'Зайцев Заяц Зайцович', 'zaitsev@example.com', '+7 (727) 012-34-56', 'Специалист', 'Природоохранное агентство', 'active');

-- Входящие письма
INSERT INTO incoming_letters (region_id, seq, date, organization, kk_number, category, subject, note, created_by) VALUES
(1, 1329, '2024-05-10', 'Министерство образования', 'КК-2024-001', 'KK', 'Письмо о сотрудничестве', 'Важное письмо', 1),
(1, 1330, '2024-05-11', 'Министерство здравоохранения', 'КК-2024-002', 'KK', 'Письмо о программе', 'Срочное письмо', 1),
(1, 1331, '2024-05-12', 'Акимат города', 'КК-2024-003', 'N', 'Письмо о встрече', 'Обычное письмо', 1),
(1, 1332, '2024-05-13', 'Управление культуры', 'КК-2024-004', 'JT', 'Письмо о мероприятии', 'Информационное письмо', 1),
(1, 1333, '2024-05-14', 'Управление спорта', 'КК-2024-005', 'KK', 'Письмо о турнире', 'Приглашение', 1);

-- Исходящие письма
INSERT INTO outgoing_letters (region_id, seq, date, outgoing_number, organization, incoming_ref_id, outgoing_type, subject, note, created_by) VALUES
(1, 1322, '2024-05-11', 'ИС-2024-001', 'Министерство образования', 1, 'gov', 'Ответ на письмо', 'Ответное письмо', 1),
(1, 1323, '2024-05-12', 'ИС-2024-002', 'Министерство здравоохранения', 2, 'gov', 'Ответ на письмо', 'Ответное письмо', 1),
(1, 1324, '2024-05-13', 'ИС-2024-003', 'Акимат города', 3, 'jt', 'Ответ на письмо', 'Ответное письмо', 1);

-- Связь входящих писем с исходящими
UPDATE incoming_letters SET linked_outgoing_id = 1 WHERE id = 1;
UPDATE incoming_letters SET linked_outgoing_id = 2 WHERE id = 2;
UPDATE incoming_letters SET linked_outgoing_id = 3 WHERE id = 3;

-- Члены писем
INSERT INTO letter_members (letter_type, letter_id, member_id, is_lead) VALUES
('incoming', 1, 1, TRUE),
('incoming', 1, 2, FALSE),
('incoming', 2, 3, TRUE),
('incoming', 2, 4, FALSE),
('outgoing', 1, 1, TRUE),
('outgoing', 2, 3, TRUE);

-- Получатели писем
INSERT INTO letter_recipients (letter_type, letter_id, recipient) VALUES
('incoming', 1, 'Иванов Иван Иванович'),
('incoming', 1, 'Петров Петр Петрович'),
('incoming', 2, 'Сидоров Сидор Сидорович'),
('outgoing', 1, 'Министерство образования'),
('outgoing', 2, 'Министерство здравоохранения');

-- ===== СОЗДАНИЕ ИНДЕКСОВ =====
ALTER TABLE os_members ADD INDEX idx_region_id (region_id);
ALTER TABLE os_members ADD INDEX idx_commission_id (commission_id);
ALTER TABLE incoming_letters ADD INDEX idx_region_id (region_id);
ALTER TABLE incoming_letters ADD INDEX idx_date (date);
ALTER TABLE outgoing_letters ADD INDEX idx_region_id (region_id);
ALTER TABLE outgoing_letters ADD INDEX idx_date (date);
ALTER TABLE letter_members ADD INDEX idx_letter (letter_type, letter_id);
ALTER TABLE letter_recipients ADD INDEX idx_letter (letter_type, letter_id);

-- ===== ГОТОВО =====
-- База данных успешно создана!
-- Пользователи для входа:
-- admin / password
-- moderator / password
-- viewer / password

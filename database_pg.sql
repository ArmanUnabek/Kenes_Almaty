-- !!! УСТАРЕЛО: каноническая схема — deploy_database.sql !!!
-- Этот файл оставлен только для истории. Для развёртывания и как источник
-- истины по структуре БД используйте deploy_database.sql.
-- ===== БАЗА ДАННЫХ ДЛЯ POSTGRESQL =====
-- Журнал Общественного Совета г. Алматы
-- Версия: 2.0
-- Дата: 2024

-- Создание базы данных
CREATE DATABASE os_journal
    WITH
    ENCODING = 'UTF8'
    LC_COLLATE = 'en_US.UTF-8'
    LC_CTYPE = 'en_US.UTF-8';

\c os_journal

-- ===== РАСШИРЕНИЯ =====
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ===== ТИПЫ ДАННЫХ =====
CREATE TYPE user_role AS ENUM ('admin', 'moderator', 'viewer');
CREATE TYPE member_status AS ENUM ('active', 'inactive');
CREATE TYPE letter_category AS ENUM ('KK', 'N', 'JT', 'ZT');
CREATE TYPE letter_type_enum AS ENUM ('incoming', 'outgoing');
CREATE TYPE outgoing_type_enum AS ENUM ('gov', 'jt', 'zt', 'recommend', 'other');
CREATE TYPE audit_action AS ENUM ('CREATE', 'UPDATE', 'DELETE', 'VIEW');

-- ===== ТАБЛИЦА РЕГИОНОВ =====
CREATE TABLE regions (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_regions_sort_order ON regions(sort_order);

-- ===== ТАБЛИЦА ПОЛЬЗОВАТЕЛЕЙ =====
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    full_name VARCHAR(255),
    role user_role DEFAULT 'viewer',
    region_id INTEGER REFERENCES regions(id),
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_users_is_active ON users(is_active);
CREATE INDEX idx_users_region_id ON users(region_id);

-- ===== ТАБЛИЦА СЕССИЙ ПОЛЬЗОВАТЕЛЕЙ =====
CREATE TABLE user_sessions (
    id VARCHAR(64) PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_user_sessions_user_id ON user_sessions(user_id);
CREATE INDEX idx_user_sessions_expires_at ON user_sessions(expires_at);

-- ===== ТАБЛИЦА КОМИССИЙ =====
CREATE TABLE commissions (
    id SERIAL PRIMARY KEY,
    region_id INTEGER REFERENCES regions(id),
    name VARCHAR(255) NOT NULL,
    description TEXT,
    color VARCHAR(7) DEFAULT '#0d6efd',
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_commissions_region_id ON commissions(region_id);
CREATE INDEX idx_commissions_sort_order ON commissions(sort_order);

-- ===== ТАБЛИЦА ЧЛЕНОВ ОС =====
CREATE TABLE os_members (
    id SERIAL PRIMARY KEY,
    region_id INTEGER REFERENCES regions(id),
    commission_id INTEGER REFERENCES commissions(id),
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(20),
    position VARCHAR(255),
    organization VARCHAR(255),
    photo_path VARCHAR(500),
    status member_status DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_os_members_region_id ON os_members(region_id);
CREATE INDEX idx_os_members_commission_id ON os_members(commission_id);
CREATE INDEX idx_os_members_status ON os_members(status);
CREATE INDEX idx_os_members_region_status ON os_members(region_id, status);

-- ===== ТАБЛИЦА ВХОДЯЩИХ ПИСЕМ =====
CREATE TABLE incoming_letters (
    id SERIAL PRIMARY KEY,
    region_id INTEGER REFERENCES regions(id),
    seq INTEGER NOT NULL,
    date DATE NOT NULL,
    organization VARCHAR(255),
    kk_number VARCHAR(50),
    category letter_category DEFAULT 'KK',
    subject TEXT,
    note TEXT,
    responds_to_outgoing_id INTEGER,
    linked_outgoing_id INTEGER,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_incoming_letters_region_id ON incoming_letters(region_id);
CREATE INDEX idx_incoming_letters_date ON incoming_letters(date);
CREATE INDEX idx_incoming_letters_seq ON incoming_letters(seq);
CREATE INDEX idx_incoming_letters_region_date ON incoming_letters(region_id, date);
CREATE INDEX idx_incoming_letters_kk_number ON incoming_letters(kk_number);

-- ===== ТАБЛИЦА ИСХОДЯЩИХ ПИСЕМ =====
CREATE TABLE outgoing_letters (
    id SERIAL PRIMARY KEY,
    region_id INTEGER REFERENCES regions(id),
    seq INTEGER NOT NULL,
    date DATE NOT NULL,
    outgoing_number VARCHAR(50),
    organization VARCHAR(255),
    incoming_ref_id INTEGER REFERENCES incoming_letters(id),
    outgoing_type outgoing_type_enum DEFAULT 'gov',
    subject TEXT,
    note TEXT,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_outgoing_letters_region_id ON outgoing_letters(region_id);
CREATE INDEX idx_outgoing_letters_date ON outgoing_letters(date);
CREATE INDEX idx_outgoing_letters_seq ON outgoing_letters(seq);
CREATE INDEX idx_outgoing_letters_region_date ON outgoing_letters(region_id, date);
CREATE INDEX idx_outgoing_letters_outgoing_number ON outgoing_letters(outgoing_number);

-- ===== ТАБЛИЦА ЧЛЕНОВ ПИСЬМА =====
CREATE TABLE letter_members (
    id SERIAL PRIMARY KEY,
    letter_type letter_type_enum NOT NULL,
    letter_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL REFERENCES os_members(id) ON DELETE CASCADE,
    is_lead BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_letter_members_letter ON letter_members(letter_type, letter_id);
CREATE INDEX idx_letter_members_member_id ON letter_members(member_id);
CREATE INDEX idx_letter_members_is_lead ON letter_members(is_lead);

-- ===== ТАБЛИЦА ПОЛУЧАТЕЛЕЙ ПИСЬМА =====
CREATE TABLE letter_recipients (
    id SERIAL PRIMARY KEY,
    letter_type letter_type_enum NOT NULL,
    letter_id INTEGER NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_letter_recipients_letter ON letter_recipients(letter_type, letter_id);
CREATE INDEX idx_letter_recipients_recipient ON letter_recipients(recipient);

-- ===== ТАБЛИЦА СКАНОВ ПИСЕМ =====
CREATE TABLE letter_scans (
    id SERIAL PRIMARY KEY,
    letter_type letter_type_enum NOT NULL,
    letter_id INTEGER NOT NULL,
    file_path VARCHAR(500),
    scan_data BYTEA,
    scan_type VARCHAR(50),
    file_name VARCHAR(255),
    file_size INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_letter_scans_letter ON letter_scans(letter_type, letter_id);
CREATE INDEX idx_letter_scans_created_at ON letter_scans(created_at);

-- ===== ТАБЛИЦА ЛОГОВ АУДИТА =====
CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY,
    table_name VARCHAR(255) NOT NULL,
    entity_id INTEGER NOT NULL,
    action audit_action NOT NULL,
    old_data JSONB,
    new_data JSONB,
    user_id INTEGER REFERENCES users(id),
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_audit_logs_table_name ON audit_logs(table_name);
CREATE INDEX idx_audit_logs_entity_id ON audit_logs(entity_id);
CREATE INDEX idx_audit_logs_user_id ON audit_logs(user_id);
CREATE INDEX idx_audit_logs_created_at ON audit_logs(created_at);
CREATE INDEX idx_audit_logs_action ON audit_logs(action);

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

-- ===== ГОТОВО =====
-- База данных успешно создана!
-- Пользователи для входа:
-- admin / password
-- moderator / password
-- viewer / password

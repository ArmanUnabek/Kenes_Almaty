-- Дополнительные индексы для оптимизации производительности.
-- ВАЖНО: базовые индексы уже создаются в deploy_database.sql (каноническая схема)
-- внутри CREATE TABLE. Здесь оставлены ТОЛЬКО индексы, которых там нет,
-- чтобы избежать дублей и ошибок "Duplicate key name".
--
-- MySQL не поддерживает "ADD INDEX IF NOT EXISTS", поэтому применяйте этот файл
-- один раз на «чистой» схеме. Повторный запуск на уже проиндексированной БД
-- завершится ошибкой дубликата — это ожидаемо.

-- OS Members: составной индекс (region_id, status) для частых выборок активных по региону
ALTER TABLE os_members ADD INDEX idx_region_status (region_id, status);

-- Incoming Letters: составной индекс по региону и дате + поиск по номеру
ALTER TABLE incoming_letters ADD INDEX idx_region_date (region_id, date);
ALTER TABLE incoming_letters ADD INDEX idx_kk_number (kk_number);

-- Outgoing Letters: составной индекс по региону и дате + поиск по номеру
ALTER TABLE outgoing_letters ADD INDEX idx_region_date (region_id, date);
ALTER TABLE outgoing_letters ADD INDEX idx_outgoing_number (outgoing_number);

-- Letter Members: поиск по члену ОС и признаку ведущего
ALTER TABLE letter_members ADD INDEX idx_member_id (member_id);
ALTER TABLE letter_members ADD INDEX idx_is_lead (is_lead);

-- Letter Recipients: поиск по адресату (idx_letter уже есть в схеме)
ALTER TABLE letter_recipients ADD INDEX idx_recipient (recipient);

-- Letter Scans: сортировка/выборка по дате (idx_letter уже есть в схеме)
ALTER TABLE letter_scans ADD INDEX idx_created_at (created_at);

-- Commissions: сортировка по порядку (idx_region уже есть в схеме)
ALTER TABLE commissions ADD INDEX idx_sort_order (sort_order);

-- Users: фильтр по активности (idx_username/idx_region уже есть в схеме)
ALTER TABLE users ADD INDEX idx_is_active (is_active);

-- Audit Logs: индексы по реальным колонкам канонической схемы
-- (record_id и operation; колонок entity_id/action в audit_logs нет)
ALTER TABLE audit_logs ADD INDEX idx_record_id (record_id);
ALTER TABLE audit_logs ADD INDEX idx_operation (operation);

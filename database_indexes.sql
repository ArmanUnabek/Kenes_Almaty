-- Дополнительные индексы для оптимизации производительности (MySQL / MariaDB).
-- ВАЖНО: базовые индексы уже создаются в deploy_database.sql (каноническая схема)
-- внутри CREATE TABLE. Здесь оставлены ТОЛЬКО индексы, которых там нет.
--
-- Идемпотентность: MySQL не поддерживает "ADD INDEX IF NOT EXISTS", поэтому
-- вспомогательная процедура проверяет information_schema и создаёт индекс лишь
-- если его ещё нет. Скрипт можно безопасно запускать повторно. Требуется
-- привилегия CREATE ROUTINE (есть на большинстве хостингов). Если хостинг не
-- разрешает процедуры — выполняйте ALTER TABLE ... ADD INDEX вручную, пропуская
-- уже существующие индексы.

DELIMITER $$

DROP PROCEDURE IF EXISTS add_index_if_missing $$
CREATE PROCEDURE add_index_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_cols  VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name   = p_table
          AND index_name   = p_index
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_cols, ')');
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

DELIMITER ;

-- OS Members: составной индекс (region_id, status) для частых выборок активных по региону
CALL add_index_if_missing('os_members', 'idx_region_status', 'region_id, status');

-- Incoming Letters: составной индекс по региону и дате + поиск по номеру
CALL add_index_if_missing('incoming_letters', 'idx_region_date', 'region_id, date');
CALL add_index_if_missing('incoming_letters', 'idx_kk_number', 'kk_number');

-- Outgoing Letters: составной индекс по региону и дате + поиск по номеру
CALL add_index_if_missing('outgoing_letters', 'idx_region_date', 'region_id, date');
CALL add_index_if_missing('outgoing_letters', 'idx_outgoing_number', 'outgoing_number');

-- Letter Members: поиск по члену ОС и признаку ведущего
CALL add_index_if_missing('letter_members', 'idx_member_id', 'member_id');
CALL add_index_if_missing('letter_members', 'idx_is_lead', 'is_lead');

-- Letter Recipients: поиск по адресату (idx_letter уже есть в схеме)
CALL add_index_if_missing('letter_recipients', 'idx_recipient', 'recipient');

-- Letter Scans: сортировка/выборка по дате (idx_letter уже есть в схеме)
CALL add_index_if_missing('letter_scans', 'idx_created_at', 'created_at');

-- Commissions: сортировка по порядку (idx_region уже есть в схеме)
CALL add_index_if_missing('commissions', 'idx_sort_order', 'sort_order');

-- Users: фильтр по активности (idx_username/idx_region уже есть в схеме)
CALL add_index_if_missing('users', 'idx_is_active', 'is_active');

-- Audit Logs: индексы по реальным колонкам канонической схемы
CALL add_index_if_missing('audit_logs', 'idx_record_id', 'record_id');
CALL add_index_if_missing('audit_logs', 'idx_operation', 'operation');

DROP PROCEDURE IF EXISTS add_index_if_missing;

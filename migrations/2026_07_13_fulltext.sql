-- Migration: 2026-07-13 FULLTEXT indexes for global search
-- Target: MySQL/MariaDB (production), engine InnoDB.
-- Запускается пользователем на проде ВРУЧНУЮ (mysql < migrations/2026_07_13_fulltext.sql).
--
-- Индексы покрывают поля, по которым ищет src/Services/GlobalSearchService.php
-- (и api/search.php). Код в рантайме через information_schema.STATISTICS проверяет
-- НЕ ТОЛЬКО имя индекса, но и ТОЧНЫЙ состав его колонок (MySQL требует, чтобы список
-- колонок в MATCH(...) совпадал с составом FULLTEXT-индекса, иначе ERROR 1191).
-- Без подходящего индекса код продолжает работать через LIKE, так что миграция
-- необязательна для работоспособности — только для скорости и релевантности.
-- sqlite эта миграция не касается (локально остаётся LIKE).
--
-- ------------------------------------------------------------------------------
-- ПОЧЕМУ ЗДЕСЬ DROP + CREATE, а не просто ADD IF NOT EXISTS
-- ------------------------------------------------------------------------------
-- Базовая схема deploy_database.sql (УЖЕ применена на проде) создала:
--   incoming_letters.ft_incoming_search (organization, kk_number, subject, note)
--   outgoing_letters.ft_outgoing_search (outgoing_number, subject, note)
-- Код же выполняет MATCH(subject, note, organization) — состав колонок НЕ совпадает,
-- поэтому одноимённый индекс надо ПЕРЕСОЗДАТЬ с правильным набором колонок.
-- Простой "ADD FULLTEXT INDEX IF NOT EXISTS" на проде был бы тихим no-op (имя занято),
-- а MATCH продолжал бы падать с ERROR 1191. Отсюда DROP INDEX IF EXISTS + ADD.
--
-- Номер письма (kk_number / outgoing_number) в индексе НЕ нужен: в коде номер ищется
-- отдельным "{numberCol} LIKE ?", а не через MATCH — поэтому в FULLTEXT его не включаем.
--
-- ------------------------------------------------------------------------------
-- ТРЕБОВАНИЯ К ВЕРСИИ / ИДЕМПОТЕНТНОСТЬ
-- ------------------------------------------------------------------------------
-- DROP INDEX IF EXISTS и ADD ... IF NOT EXISTS в ALTER TABLE:
--   MariaDB 10.5.2+  — поддерживает оба (прод — MariaDB, см. 2026_07_10_pending_fixes.sql).
--   MySQL            — НЕ поддерживает IF EXISTS/IF NOT EXISTS у ADD/DROP INDEX.
-- Повторный запуск на MariaDB безопасен (обе операции идемпотентны).
--
-- Для чистого MySQL (или MariaDB < 10.5.2) выполните для каждого индекса вручную:
--   -- проверить состав колонок существующего индекса:
--   SELECT COLUMN_NAME FROM information_schema.STATISTICS
--    WHERE table_schema = DATABASE()
--      AND table_name = 'incoming_letters' AND index_name = 'ft_incoming_search'
--    ORDER BY SEQ_IN_INDEX;
--   -- если состав != (subject, note, organization):
--   ALTER TABLE incoming_letters DROP INDEX ft_incoming_search;
--   -- если индекса нет ИЛИ он только что удалён:
--   ALTER TABLE incoming_letters ADD FULLTEXT INDEX ft_incoming_search (subject, note, organization);
-- (и аналогично для остальных индексов из этого файла).
--
-- ВАЖНО: имена индексов (ft_*) захардкожены в GlobalSearchService::fulltextIndexExists —
-- не переименовывать.

-- ============================================================================
-- Письма: MATCH(subject, note, organization). Пересоздаём с правильным составом,
-- т.к. deploy_database.sql создал одноимённые индексы с другими колонками.
-- ============================================================================
ALTER TABLE incoming_letters
    DROP INDEX IF EXISTS ft_incoming_search;
ALTER TABLE incoming_letters
    ADD FULLTEXT INDEX IF NOT EXISTS ft_incoming_search (subject, note, organization);

ALTER TABLE outgoing_letters
    DROP INDEX IF EXISTS ft_outgoing_search;
ALTER TABLE outgoing_letters
    ADD FULLTEXT INDEX IF NOT EXISTS ft_outgoing_search (subject, note, organization);

-- ============================================================================
-- Члены ОС: код ищет MATCH(full_name) под именем ft_members_fullname.
-- deploy_database.sql создал ОТДЕЛЬНЫЙ ft_members_search (full_name, position,
-- organization) — другое имя, коллизии нет, он остаётся неиспользуемым (безвредно).
-- Создаём именно ft_members_fullname (full_name), чтобы имя и состав совпали с кодом.
-- ============================================================================
ALTER TABLE os_members
    DROP INDEX IF EXISTS ft_members_fullname;
ALTER TABLE os_members
    ADD FULLTEXT INDEX IF NOT EXISTS ft_members_fullname (full_name);

-- ============================================================================
-- Мероприятия: MATCH(title, location, notes). В deploy_database.sql индекса нет.
-- ============================================================================
ALTER TABLE events
    DROP INDEX IF EXISTS ft_events_search;
ALTER TABLE events
    ADD FULLTEXT INDEX IF NOT EXISTS ft_events_search (title, location, notes);

-- ============================================================================
-- Комментарии к письмам: MATCH(comment). В deploy_database.sql индекса нет.
-- ============================================================================
ALTER TABLE letter_comments
    DROP INDEX IF EXISTS ft_comments_comment;
ALTER TABLE letter_comments
    ADD FULLTEXT INDEX IF NOT EXISTS ft_comments_comment (comment);

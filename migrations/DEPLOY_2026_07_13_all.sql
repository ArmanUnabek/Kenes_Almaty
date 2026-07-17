-- ============================================================================
-- DEPLOY MIGRATION: 2026-07-13 (объединяет 3 миграции для деплоя)
-- ============================================================================
-- Target: MySQL/MariaDB (production). Прод — MariaDB 10.5.2+ (требуется из-за
--   DROP INDEX IF EXISTS / ADD ... IF NOT EXISTS в Части 2 и 3, см. предупреждения ниже).
--
-- КАК ЗАПУСТИТЬ (один раз при деплое):
--   mysql -u USER -p DBNAME < migrations/DEPLOY_2026_07_13_all.sql
--   -- или через phpMyAdmin: вкладка "Импорт" -> выбрать этот файл -> Ok
--
-- Повторный запуск безопасен (идемпотентно): все ALTER используют
-- IF NOT EXISTS / DROP ... IF EXISTS, MODIFY идемпотентен по своей природе,
-- CREATE TABLE использует IF NOT EXISTS.
--
-- ВНИМАНИЕ ДЛЯ ЧИСТОГО MySQL (или MariaDB < 10.5.2):
--   MySQL не поддерживает IF EXISTS/IF NOT EXISTS у ADD/DROP INDEX и COLUMN
--   в некоторых версиях (ADD COLUMN IF NOT EXISTS — MySQL 8.0.29+; ADD/DROP
--   INDEX IF EXISTS — не поддерживается MySQL вовсе). Перед запуском на MySQL
--   проверяйте наличие колонок/индексов вручную через information_schema и
--   выполняйте "голые" ALTER без IF (см. подробные инструкции внутри частей 2 и 3).
--
-- Содержит (по порядку, без изменений по смыслу):
--   Часть 1: migrations/2026_07_10_pending_fixes.sql
--   Часть 2: migrations/2026_07_13_fulltext.sql
--   Часть 3: migrations/2026_07_13_queue_processing.sql
-- Дублей ALTER между файлами не обнаружено (проверено построчно) — все
-- операции перенесены как есть.
-- ============================================================================


-- ============================================================================
-- ===== Часть 1: 2026_07_10_pending_fixes.sql =====
-- ============================================================================

-- Migration: 2026-07-10 pending fixes
-- Target: MySQL/MariaDB (production)

-- 1. user_sessions: allow anonymous sessions
-- MODIFY идемпотентен по своей природе (повторный запуск даст ту же схему),
-- IF NOT EXISTS к MODIFY неприменим.
ALTER TABLE user_sessions MODIFY user_id INT NULL;

-- 2. letter_scans: scan versioning (see src/Services/ScanVersionService.php)
--    replaced_by  -> letter_scans.id of the replacing scan (INT NULL)
--    replaced_at  -> set via CURRENT_TIMESTAMP on replacement (DATETIME NULL)
--    uploaded_by  -> users.id of uploader, joined as LEFT JOIN users (INT NULL)
-- IF NOT EXISTS: поддерживается MariaDB 10.2+ (прод — MariaDB), повторный запуск безопасен
ALTER TABLE letter_scans
    ADD COLUMN IF NOT EXISTS replaced_by INT NULL,
    ADD COLUMN IF NOT EXISTS replaced_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS uploaded_by INT NULL;

-- 3. sms_queue: sender attribution and retry counter (see src/Services/SmsService.php)
--    attempts is incremented on every send attempt and compared with < 3
ALTER TABLE sms_queue
    ADD COLUMN IF NOT EXISTS user_id INT NULL,
    ADD COLUMN IF NOT EXISTS attempts INT NOT NULL DEFAULT 0;

-- 4. letter_comments: moved out of api/comments.php runtime DDL
CREATE TABLE IF NOT EXISTS letter_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    letter_type ENUM('incoming','outgoing') NOT NULL,
    letter_id INT NOT NULL,
    user_id INT NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_letter (letter_type, letter_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- ===== Часть 2: 2026_07_13_fulltext.sql =====
-- ============================================================================

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


-- ============================================================================
-- ===== Часть 3: 2026_07_13_queue_processing.sql =====
-- ============================================================================

-- Migration: 2026-07-13 queue processing (защита от двойной отправки при параллельных прогонах)
-- Target: MySQL/MariaDB (production). sqlite не затрагивается (status там TEXT,
--   недостающие колонки sms_queue добавляются в db.php::ensureSqliteSchema).
--
-- Запускается пользователем на проде ВРУЧНУЮ:
--   mysql <db> < migrations/2026_07_13_queue_processing.sql
--
-- Зачем: обработчики email_queue/sms_queue (src/Services/EmailService.php,
--   src/Services/SmsService.php) раньше защищались только flock() во временном
--   каталоге — это спасает лишь от параллельных процессов на ОДНОМ хосте.
--   При запуске cron на нескольких воркерах/контейнерах два процесса могли
--   выбрать одну и ту же строку 'queued' и отправить письмо дважды.
--   Теперь строка атомарно «захватывается» переводом в статус 'processing'
--   (UPDATE ... WHERE status='queued', проверка rowCount()==1) — отправляет
--   только выигравший процесс. processing_at нужен для восстановления строк,
--   зависших после падения воркера.
--
-- Идемпотентность:
--   * ADD COLUMN IF NOT EXISTS — MariaDB 10.2+ (прод — MariaDB, см. 2026_07_10_pending_fixes.sql).
--   * MODIFY ... ENUM — идемпотентен по природе (повторный запуск даёт ту же схему).
--   * ADD INDEX IF NOT EXISTS — MariaDB 10.0+.
--
-- Для чистого MySQL (< 8.0.29 нет IF NOT EXISTS у ADD COLUMN/INDEX):
--   проверьте наличие колонки/индекса в information_schema и выполните голый ALTER,
--   например:
--     SELECT COUNT(*) FROM information_schema.COLUMNS
--      WHERE table_schema=DATABASE() AND table_name='email_queue' AND column_name='processing_at';
--     -- если 0:  ALTER TABLE email_queue ADD COLUMN processing_at DATETIME NULL;
--   ENUM меняется через обычный MODIFY (см. ниже) в любом случае.
--
-- SELECT ... FOR UPDATE SKIP LOCKED требует MySQL 8.0+ / MariaDB 10.6+, но код
--   его НЕ использует — атомарного UPDATE-claim достаточно и он переносим на
--   старые версии и sqlite. Эта миграция под SKIP LOCKED ничего не требует.

-- ---------------------------------------------------------------------------
-- email_queue
-- ---------------------------------------------------------------------------

-- Расширяем ENUM статуса значением 'processing' (промежуточный «захват» строки).
ALTER TABLE email_queue
    MODIFY status ENUM('queued','processing','sent','failed')
        NOT NULL DEFAULT 'queued' COMMENT 'Статус отправки';

-- Отметка времени захвата строки воркером (для восстановления зависших) и счётчик попыток.
ALTER TABLE email_queue
    ADD COLUMN IF NOT EXISTS processing_at DATETIME NULL COMMENT 'Когда строка захвачена воркером',
    ADD COLUMN IF NOT EXISTS attempts INT NOT NULL DEFAULT 0 COMMENT 'Число попыток отправки';

-- Индекс под выборку кандидатов и восстановление зависших (status + processing_at).
ALTER TABLE email_queue
    ADD INDEX IF NOT EXISTS idx_status_processing (status, processing_at);

-- ---------------------------------------------------------------------------
-- sms_queue  (та же гонка: раньше защищала только flock)
-- ---------------------------------------------------------------------------
-- attempts / user_id уже добавлены миграцией 2026_07_10_pending_fixes.sql.

ALTER TABLE sms_queue
    MODIFY status ENUM('queued','processing','sent','failed')
        DEFAULT 'queued';

ALTER TABLE sms_queue
    ADD COLUMN IF NOT EXISTS processing_at DATETIME NULL COMMENT 'Когда строка захвачена воркером';

ALTER TABLE sms_queue
    ADD INDEX IF NOT EXISTS idx_status_processing (status, processing_at);

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

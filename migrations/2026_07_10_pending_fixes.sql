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

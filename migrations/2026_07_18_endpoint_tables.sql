-- Migration: 2026-07-18 — endpoint tables (formerly created at runtime)
-- Target: MySQL/MariaDB (production).
--
-- letter_comments, saved_searches and letter_templates used to be created
-- lazily ("self-healing") at request time inside api/comments.php,
-- api/saved_searches.php and api/templates.php. That runtime DDL has been
-- removed — the canonical definitions live in deploy_database.sql, and this
-- dated migration is the explicit artifact to run on any existing database that
-- predates these tables. Idempotent (IF NOT EXISTS) — safe to re-run.
--
--   mysql <db> < migrations/2026_07_18_endpoint_tables.sql
--
-- NOTE: these mirror deploy_database.sql exactly. A fresh import already has
-- them; this file is only for older databases that never ran the runtime path.

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

CREATE TABLE IF NOT EXISTS saved_searches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    params JSON NOT NULL COMMENT 'Параметры: q, type, date_from, date_to, status, etc.',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Migration: 2026-07-18 — Meetings & Protocols (заседания)
-- Target: MySQL/MariaDB. Also mirrored in deploy_database.sql for fresh imports.
-- Idempotent (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT NULL COMMENT 'Район ОС',
    title VARCHAR(255) NOT NULL COMMENT 'Тема заседания',
    meeting_date DATE NOT NULL COMMENT 'Дата проведения',
    meeting_time TIME NULL COMMENT 'Время начала',
    location VARCHAR(255) NULL COMMENT 'Место проведения',
    type ENUM('regular','extraordinary') NOT NULL DEFAULT 'regular' COMMENT 'Очередное/внеочередное',
    status ENUM('planned','in_progress','held','cancelled') NOT NULL DEFAULT 'planned' COMMENT 'Статус',
    protocol_number VARCHAR(50) NULL COMMENT 'Номер протокола',
    notes TEXT NULL COMMENT 'Примечание',
    created_by INT NULL COMMENT 'Кто создал',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_region (region_id),
    INDEX idx_meeting_date (meeting_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_agenda_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL,
    item_order INT NOT NULL DEFAULT 0,
    title VARCHAR(500) NOT NULL COMMENT 'Вопрос повестки',
    description TEXT NULL,
    presenter_member_id INT NULL COMMENT 'Докладчик (член ОС)',
    decision_text TEXT NULL COMMENT 'Принятое решение',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    FOREIGN KEY (presenter_member_id) REFERENCES os_members(id) ON DELETE SET NULL,
    INDEX idx_meeting (meeting_id, item_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL,
    member_id INT NOT NULL,
    present TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Присутствовал (1/0)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meeting_member (meeting_id, member_id),
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES os_members(id) ON DELETE CASCADE,
    INDEX idx_meeting (meeting_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

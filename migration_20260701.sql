-- Миграция 2026-07-01: Календарь мероприятий + RSVP + Обращения граждан
-- Запустить: mysql -u USER -p DB_NAME < migration_20260701.sql

-- 1. Расширение таблицы events
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS location_url VARCHAR(500) NULL COMMENT 'Ссылка 2GIS' AFTER location,
  ADD COLUMN IF NOT EXISTS description  TEXT         NULL COMMENT 'Описание мероприятия' AFTER notes;

-- 2. RSVP членов ОС
CREATE TABLE IF NOT EXISTS event_rsvp (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  event_id     INT NOT NULL,
  member_id    INT NOT NULL,
  status       ENUM('confirmed','declined','maybe') NOT NULL DEFAULT 'confirmed',
  responded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event_member (event_id, member_id),
  FOREIGN KEY (event_id)  REFERENCES events(id)     ON DELETE CASCADE,
  FOREIGN KEY (member_id) REFERENCES os_members(id) ON DELETE CASCADE,
  INDEX idx_event  (event_id),
  INDEX idx_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Отклики членов ОС на мероприятия';

-- 3. Обращения граждан
CREATE TABLE IF NOT EXISTS citizen_appeals (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  appeal_number      VARCHAR(50)  UNIQUE NULL,
  region_id          INT          NULL,
  full_name          VARCHAR(255) NOT NULL,
  email              VARCHAR(255) NULL,
  phone              VARCHAR(50)  NULL,
  subject            VARCHAR(500) NOT NULL,
  message            TEXT         NOT NULL,
  category           ENUM('complaint','suggestion','question','request','other') DEFAULT 'other',
  status             ENUM('new','in_review','responded','closed') DEFAULT 'new',
  assigned_member_id INT          NULL,
  response_text      TEXT         NULL,
  responded_at       TIMESTAMP    NULL,
  responded_by       INT          NULL,
  ip_address         VARCHAR(45)  NULL,
  created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (region_id)          REFERENCES regions(id)    ON DELETE SET NULL,
  FOREIGN KEY (assigned_member_id) REFERENCES os_members(id) ON DELETE SET NULL,
  FOREIGN KEY (responded_by)       REFERENCES users(id)      ON DELETE SET NULL,
  INDEX idx_status  (status),
  INDEX idx_region  (region_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Обращения граждан';

-- Migration: add birth date + social links to os_members (MySQL/MariaDB).
--
-- The app also adds these columns automatically at runtime
-- (db.php::ensureMemberProfileColumns) on the next DB connection, so applying
-- this file by hand is optional — useful when you prefer an explicit migration.
-- Re-runnable: each ADD COLUMN is guarded so a second run is a no-op.

DROP PROCEDURE IF EXISTS add_member_profile_columns;
DELIMITER //
CREATE PROCEDURE add_member_profile_columns()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'os_members' AND column_name = 'birth_date') THEN
        ALTER TABLE os_members ADD COLUMN birth_date DATE NULL COMMENT 'Дата рождения' AFTER phone;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'os_members' AND column_name = 'facebook') THEN
        ALTER TABLE os_members ADD COLUMN facebook VARCHAR(255) NULL COMMENT 'Facebook (URL или @handle)' AFTER birth_date;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'os_members' AND column_name = 'whatsapp') THEN
        ALTER TABLE os_members ADD COLUMN whatsapp VARCHAR(50) NULL COMMENT 'WhatsApp (номер телефона)' AFTER facebook;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'os_members' AND column_name = 'instagram') THEN
        ALTER TABLE os_members ADD COLUMN instagram VARCHAR(255) NULL COMMENT 'Instagram (URL или @handle)' AFTER whatsapp;
    END IF;
END //
DELIMITER ;
CALL add_member_profile_columns();
DROP PROCEDURE add_member_profile_columns;

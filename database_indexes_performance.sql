-- Database indexes for performance optimization (MySQL / MariaDB)
-- Run this script after database setup to improve query performance.
--
-- Idempotent: MySQL has no "ADD INDEX IF NOT EXISTS", so a helper procedure
-- checks information_schema and only creates each index when it is missing.
-- The script can therefore be re-run safely. Requires the CREATE ROUTINE
-- privilege (granted on most shared hosts). If your host disallows stored
-- procedures, run the individual ALTER TABLE ... ADD INDEX statements by hand
-- and skip any index that already exists.

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

-- Members table indexes
CALL add_index_if_missing('os_members', 'idx_region_id', 'region_id');
CALL add_index_if_missing('os_members', 'idx_commission_id', 'commission_id');
CALL add_index_if_missing('os_members', 'idx_email', 'email');

-- Incoming letters table indexes
CALL add_index_if_missing('incoming_letters', 'idx_region_id', 'region_id');
CALL add_index_if_missing('incoming_letters', 'idx_date', 'date');
CALL add_index_if_missing('incoming_letters', 'idx_region_date', 'region_id, date');

-- Outgoing letters table indexes
CALL add_index_if_missing('outgoing_letters', 'idx_region_id', 'region_id');
CALL add_index_if_missing('outgoing_letters', 'idx_date', 'date');
CALL add_index_if_missing('outgoing_letters', 'idx_region_date', 'region_id, date');

-- Letter members junction table indexes
CALL add_index_if_missing('letter_members', 'idx_letter_type_id', 'letter_type, letter_id');
CALL add_index_if_missing('letter_members', 'idx_member_id', 'member_id');

-- Letter recipients junction table indexes
CALL add_index_if_missing('letter_recipients', 'idx_letter_type_id', 'letter_type, letter_id');

-- Commissions table indexes
CALL add_index_if_missing('commissions', 'idx_name', 'name');

-- Regions table indexes
CALL add_index_if_missing('regions', 'idx_name', 'name');

-- Users table indexes
CALL add_index_if_missing('users', 'idx_email', 'email');
CALL add_index_if_missing('users', 'idx_region_id', 'region_id');

-- Letter cross-reference indexes
CALL add_index_if_missing('incoming_letters', 'idx_linked_outgoing', 'linked_outgoing_id');
CALL add_index_if_missing('outgoing_letters', 'idx_incoming_ref', 'incoming_ref_id');

-- Audit log indexes
CALL add_index_if_missing('audit_logs', 'idx_table_name', 'table_name');
CALL add_index_if_missing('audit_logs', 'idx_user_id', 'user_id');
CALL add_index_if_missing('audit_logs', 'idx_created_at', 'created_at');

DROP PROCEDURE IF EXISTS add_index_if_missing;

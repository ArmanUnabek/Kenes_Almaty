-- Database indexes for performance optimization
-- Run this script after database setup to improve query performance

-- Members table indexes
ALTER TABLE os_members ADD INDEX idx_region_id (region_id);
ALTER TABLE os_members ADD INDEX idx_commission_id (commission_id);
ALTER TABLE os_members ADD INDEX idx_email (email);

-- Incoming letters table indexes
ALTER TABLE incoming_letters ADD INDEX idx_region_id (region_id);
ALTER TABLE incoming_letters ADD INDEX idx_date (date);
ALTER TABLE incoming_letters ADD INDEX idx_region_date (region_id, date);

-- Outgoing letters table indexes
ALTER TABLE outgoing_letters ADD INDEX idx_region_id (region_id);
ALTER TABLE outgoing_letters ADD INDEX idx_date (date);
ALTER TABLE outgoing_letters ADD INDEX idx_region_date (region_id, date);

-- Letter members junction table indexes
ALTER TABLE letter_members ADD INDEX idx_letter_type_id (letter_type, letter_id);
ALTER TABLE letter_members ADD INDEX idx_member_id (member_id);

-- Letter recipients junction table indexes
ALTER TABLE letter_recipients ADD INDEX idx_letter_type_id (letter_type, letter_id);

-- Commissions table indexes
ALTER TABLE commissions ADD INDEX idx_name (name);

-- Regions table indexes
ALTER TABLE regions ADD INDEX idx_name (name);

-- Users table indexes
ALTER TABLE users ADD INDEX idx_email (email);
ALTER TABLE users ADD INDEX idx_region_id (region_id);

ALTER TABLE incoming_letters ADD INDEX idx_linked_outgoing (linked_outgoing_id);
ALTER TABLE outgoing_letters ADD INDEX idx_incoming_ref (incoming_ref_id);

ALTER TABLE audit_logs ADD INDEX idx_table_name (table_name);
ALTER TABLE audit_logs ADD INDEX idx_user_id (user_id);
ALTER TABLE audit_logs ADD INDEX idx_created_at (created_at);

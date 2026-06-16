-- PostgreSQL indexes for performance optimization
-- Run this script for PostgreSQL databases

CREATE INDEX idx_os_members_region_id ON os_members(region_id);
CREATE INDEX idx_os_members_commission_id ON os_members(commission_id);
CREATE INDEX idx_os_members_email ON os_members(email);

CREATE INDEX idx_incoming_letters_region_id ON incoming_letters(region_id);
CREATE INDEX idx_incoming_letters_date ON incoming_letters(date);
CREATE INDEX idx_incoming_letters_region_date ON incoming_letters(region_id, date);

CREATE INDEX idx_outgoing_letters_region_id ON outgoing_letters(region_id);
CREATE INDEX idx_outgoing_letters_date ON outgoing_letters(date);
CREATE INDEX idx_outgoing_letters_region_date ON outgoing_letters(region_id, date);

CREATE INDEX idx_letter_members_letter ON letter_members(letter_type, letter_id);
CREATE INDEX idx_letter_members_member_id ON letter_members(member_id);

CREATE INDEX idx_letter_recipients_letter ON letter_recipients(letter_type, letter_id);

CREATE INDEX idx_commissions_name ON commissions(name);

CREATE INDEX idx_regions_name ON regions(name);

CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_region_id ON users(region_id);

CREATE INDEX idx_audit_logs_table_name ON audit_logs(table_name);
CREATE INDEX idx_audit_logs_user_id ON audit_logs(user_id);
CREATE INDEX idx_audit_logs_created_at ON audit_logs(created_at);

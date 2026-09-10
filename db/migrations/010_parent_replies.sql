-- Migration 010 — Parent replies in the messaging system
-- Lets families answer management from the parent portal instead of the
-- conversation being one-way (admin/teacher → parent only).
--
-- Applied automatically at runtime by initializeMessagingTable(); this file
-- exists for DBAs who prefer applying migrations manually.

ALTER TABLE messages
    MODIFY COLUMN sender_type ENUM('admin','teacher','parent') NOT NULL;

ALTER TABLE messages
    ADD COLUMN recipient_admin_id INT NULL AFTER sender_id;

ALTER TABLE messages
    ADD INDEX idx_messages_recipient_admin (recipient_admin_id, is_read);

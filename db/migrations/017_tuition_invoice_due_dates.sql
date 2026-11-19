-- Existing plans stay undated until staff set their actual due dates.
SET @roma_add_due_date = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'tuition_plans' AND COLUMN_NAME = 'due_date'),
    'SELECT 1', 'ALTER TABLE tuition_plans ADD COLUMN due_date DATE DEFAULT NULL'
);
PREPARE roma_invoice_migration FROM @roma_add_due_date;
EXECUTE roma_invoice_migration;
DEALLOCATE PREPARE roma_invoice_migration;

SET @roma_add_discrepancy = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'tuition_plans' AND COLUMN_NAME = 'discrepancy_note'),
    'SELECT 1', 'ALTER TABLE tuition_plans ADD COLUMN discrepancy_note VARCHAR(500) DEFAULT NULL'
);
PREPARE roma_invoice_migration FROM @roma_add_discrepancy;
EXECUTE roma_invoice_migration;
DEALLOCATE PREPARE roma_invoice_migration;

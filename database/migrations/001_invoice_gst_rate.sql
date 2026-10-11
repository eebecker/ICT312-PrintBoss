-- Run once on an existing PrintBoss database before using the updated app.
-- Old invoices keep NULL: their original rate was not stored.
ALTER TABLE invoices ADD COLUMN gst_rate DECIMAL(5,2) NULL DEFAULT NULL AFTER gst_enabled;

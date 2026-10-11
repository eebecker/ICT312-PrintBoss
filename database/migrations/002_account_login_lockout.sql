-- Run once on an existing database before using the updated login page.
ALTER TABLE users
  ADD COLUMN failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER password_hash,
  ADD COLUMN login_locked_until DATETIME NULL AFTER failed_login_attempts;

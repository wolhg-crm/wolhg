SET NAMES utf8mb4;

-- Idempotent: add token_cipher only if missing (for installs that already ran older 008).
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'account_invitations'
    AND COLUMN_NAME = 'token_cipher'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE account_invitations ADD COLUMN token_cipher TEXT NULL AFTER token_hash',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

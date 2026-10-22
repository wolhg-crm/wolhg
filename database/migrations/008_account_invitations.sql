SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS account_invitations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  token_cipher TEXT NULL,
  created_by INT UNSIGNED NULL,
  expires_at TIMESTAMP NOT NULL,
  used_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_account_invite_token (token_hash),
  INDEX (person_id, expires_at),
  INDEX (created_by, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

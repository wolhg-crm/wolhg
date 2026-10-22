SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS church_invitations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash VARCHAR(64) NOT NULL,
  token_cipher TEXT NULL,
  note VARCHAR(190) NULL,
  created_by INT UNSIGNED NULL,
  expires_at TIMESTAMP NOT NULL,
  used_at TIMESTAMP NULL,
  used_by_user_id INT UNSIGNED NULL,
  used_church_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_church_invite_token (token_hash),
  INDEX (created_by, created_at),
  INDEX (expires_at, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

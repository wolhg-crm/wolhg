-- MCP API keys for remote agents (Cursor, ChatGPT, etc.).
-- Token plaintext is shown once; only SHA-256 hash is stored.

CREATE TABLE IF NOT EXISTS user_api_keys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  token_prefix VARCHAR(24) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  scopes JSON NOT NULL,
  last_used_at TIMESTAMP NULL,
  expires_at TIMESTAMP NULL,
  revoked_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY user_api_keys_token_hash_uindex (token_hash),
  KEY user_api_keys_user_id_idx (user_id),
  KEY user_api_keys_active_idx (user_id, created_at),
  CONSTRAINT user_api_keys_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

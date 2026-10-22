SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN login VARCHAR(80) NULL AFTER name;

UPDATE users
SET login = LOWER(SUBSTRING_INDEX(email, '@', 1))
WHERE login IS NULL OR login = '';

UPDATE users u
JOIN (
  SELECT id, CONCAT(login, '-', id) AS fixed_login
  FROM users
  WHERE login IN (SELECT login FROM (SELECT login FROM users GROUP BY login HAVING COUNT(*) > 1) dup)
) d ON d.id = u.id
SET u.login = d.fixed_login;

ALTER TABLE users
  MODIFY login VARCHAR(80) NOT NULL,
  ADD UNIQUE KEY uniq_users_login (login);

ALTER TABLE participant_requests
  ADD COLUMN latitude DECIMAL(10,7) NULL AFTER district,
  ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
  ADD COLUMN source_provider ENUM('site','vk','max') NOT NULL DEFAULT 'site' AFTER longitude,
  ADD COLUMN source_external_id VARCHAR(190) NULL AFTER source_provider,
  ADD COLUMN recommended_group_id INT UNSIGNED NULL AFTER source_external_id;

ALTER TABLE notification_events
  ADD COLUMN actions JSON NULL AFTER status;

CREATE TABLE IF NOT EXISTS social_identities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  provider ENUM('yandex','vk') NOT NULL,
  external_user_id VARCHAR(190) NOT NULL,
  profile_name VARCHAR(190) NULL,
  linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_social_provider_user (provider, external_user_id),
  UNIQUE KEY uniq_user_provider (user_id, provider),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_states (
  state VARCHAR(64) PRIMARY KEY,
  provider ENUM('yandex','vk') NOT NULL,
  mode ENUM('login','link') NOT NULL DEFAULT 'login',
  user_id INT UNSIGNED NULL,
  return_to VARCHAR(255) NOT NULL DEFAULT '/',
  code_verifier VARCHAR(128) NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  provider ENUM('max','vk') NOT NULL,
  chat_id VARCHAR(190) NOT NULL,
  external_user_id VARCHAR(190) NULL,
  display_name VARCHAR(190) NULL,
  private_chat TINYINT(1) NOT NULL DEFAULT 1,
  linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NULL,
  UNIQUE KEY uniq_bot_provider_chat (provider, chat_id),
  UNIQUE KEY uniq_user_bot_provider (user_id, provider),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_link_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  provider ENUM('max','vk') NULL,
  expires_at TIMESTAMP NOT NULL,
  used_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_bot_link_token (token_hash),
  INDEX (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_inbound_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider ENUM('max','vk') NOT NULL,
  chat_id VARCHAR(190) NOT NULL,
  external_user_id VARCHAR(190) NULL,
  user_id INT UNSIGNED NULL,
  message_text TEXT NULL,
  payload JSON NULL,
  handled_as VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (provider, chat_id),
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yandex_geocode_cache (
  cache_key VARCHAR(64) PRIMARY KEY,
  query_text VARCHAR(255) NOT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  resolved_address VARCHAR(255) NULL,
  raw_response JSON NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 038: MCP OAuth 2.1 tables (RFC 9728 / RFC 8414 / DCR RFC 7591)
-- Up

CREATE TABLE IF NOT EXISTS mcp_oauth_clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id VARCHAR(512) NOT NULL,
  client_id_hash CHAR(64) NOT NULL,
  client_secret_hash CHAR(64) NULL COMMENT 'NULL = public client',
  client_name VARCHAR(191) NOT NULL DEFAULT 'MCP Client',
  redirect_uris LONGTEXT NOT NULL COMMENT 'JSON array',
  grant_types LONGTEXT NOT NULL COMMENT 'JSON array',
  response_types LONGTEXT NOT NULL COMMENT 'JSON array',
  token_endpoint_auth_method VARCHAR(64) NOT NULL DEFAULT 'none',
  registration_type VARCHAR(32) NOT NULL DEFAULT 'dcr' COMMENT 'dcr or cimd',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at TIMESTAMP NULL,
  UNIQUE KEY mcp_oauth_clients_hash_uq (client_id_hash),
  KEY mcp_oauth_clients_created_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_oauth_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code_hash CHAR(64) NOT NULL,
  client_id_hash CHAR(64) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  redirect_uri TEXT NOT NULL,
  code_challenge VARCHAR(191) NOT NULL,
  code_challenge_method VARCHAR(10) NOT NULL DEFAULT 'S256',
  scopes LONGTEXT NOT NULL COMMENT 'JSON array',
  resource VARCHAR(512) NOT NULL,
  code_expires_at TIMESTAMP NOT NULL,
  consumed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY mcp_oauth_codes_hash_uq (code_hash),
  KEY mcp_oauth_codes_expires_idx (code_expires_at),
  CONSTRAINT mcp_oauth_codes_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_oauth_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  access_token_hash CHAR(64) NOT NULL,
  refresh_token_hash CHAR(64) NULL,
  client_id_hash CHAR(64) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  scopes LONGTEXT NOT NULL COMMENT 'JSON array',
  resource VARCHAR(512) NOT NULL,
  access_expires_at TIMESTAMP NOT NULL,
  refresh_expires_at TIMESTAMP NULL,
  revoked_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL,
  UNIQUE KEY mcp_oauth_tokens_access_uq (access_token_hash),
  KEY mcp_oauth_tokens_refresh_idx (refresh_token_hash),
  KEY mcp_oauth_tokens_expires_idx (access_expires_at),
  CONSTRAINT mcp_oauth_tokens_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_oauth_cimd (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id_hash CHAR(64) NOT NULL,
  client_id VARCHAR(512) NOT NULL,
  document_json LONGTEXT NOT NULL,
  etag VARCHAR(191) NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  UNIQUE KEY mcp_oauth_cimd_hash_uq (client_id_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS role_invitation_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  invited_by INT UNSIGNED NULL,
  requested_role ENUM('assistant','leader','regional_leader','pastor') NOT NULL,
  church_id INT UNSIGNED NULL,
  group_id INT UNSIGNED NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_by INT UNSIGNED NULL,
  approved_at TIMESTAMP NULL,
  rejected_by INT UNSIGNED NULL,
  rejected_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (status, created_at),
  INDEX (user_id, status),
  INDEX (church_id, status),
  INDEX (invited_by, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

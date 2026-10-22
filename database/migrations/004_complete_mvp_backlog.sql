SET NAMES utf8mb4;

ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(32) NULL AFTER two_factor_enabled;
ALTER TABLE home_groups ADD COLUMN latitude DECIMAL(10,7) NULL AFTER address;
ALTER TABLE home_groups ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude;
ALTER TABLE home_groups ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER closed_at;
ALTER TABLE people ADD COLUMN spiritual_stage_id INT UNSIGNED NULL AFTER ministry;
ALTER TABLE people ADD COLUMN sensitive_notes_encrypted TEXT NULL AFTER notes;
ALTER TABLE people ADD COLUMN latitude DECIMAL(10,7) NULL AFTER sensitive_notes_encrypted;
ALTER TABLE people ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude;
ALTER TABLE people ADD COLUMN retention_until DATE NULL AFTER longitude;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(120) PRIMARY KEY,
  setting_value TEXT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role ENUM('member','assistant','leader','regional_leader','pastor','admin') NOT NULL,
  action VARCHAR(120) NOT NULL,
  allowed TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_role_action (role, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS field_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role ENUM('member','assistant','leader','regional_leader','pastor','admin') NOT NULL,
  field_name VARCHAR(120) NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 1,
  can_edit TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_role_field (role, field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  kind ENUM('export','restore') NOT NULL DEFAULT 'export',
  filename VARCHAR(190) NULL,
  status ENUM('created','restored','failed') NOT NULL DEFAULT 'created',
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_deletion_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NOT NULL,
  requested_by INT UNSIGNED NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('requested','approved','completed','cancelled') NOT NULL DEFAULT 'requested',
  confirmation_token VARCHAR(64) NOT NULL,
  completed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (person_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS person_change_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  field_name VARCHAR(120) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (person_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spiritual_stages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  church_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_final TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_church_stage (church_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  church_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  required_stage_id INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (church_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_lessons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  quiz_question VARCHAR(255) NULL,
  quiz_answer VARCHAR(120) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_progress (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NOT NULL,
  lesson_id INT UNSIGNED NOT NULL,
  status ENUM('open','passed') NOT NULL DEFAULT 'open',
  passed_at TIMESTAMP NULL,
  UNIQUE KEY uniq_person_lesson (person_id, lesson_id),
  INDEX (person_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id INT UNSIGNED NOT NULL,
  submitted_by INT UNSIGNED NULL,
  summary TEXT NOT NULL,
  attendance_count INT UNSIGNED NOT NULL DEFAULT 0,
  newcomers_count INT UNSIGNED NOT NULL DEFAULT 0,
  pastor_help_needed TINYINT(1) NOT NULL DEFAULT 0,
  sent_to_user_id INT UNSIGNED NULL,
  sent_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_meeting_report (meeting_id),
  INDEX (sent_to_user_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  source_name VARCHAR(190) NOT NULL,
  rows_total INT UNSIGNED NOT NULL DEFAULT 0,
  rows_imported INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('preview','imported','failed') NOT NULL DEFAULT 'imported',
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


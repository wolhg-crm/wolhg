SET NAMES utf8mb4;

CREATE TABLE schema_migrations (
  version VARCHAR(190) NOT NULL PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE churches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  city VARCHAR(120) NULL,
  slug VARCHAR(64) NOT NULL,
  timezone VARCHAR(80) NOT NULL DEFAULT 'Europe/Moscow',
  personal_data_consent_text TEXT NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  demo_token CHAR(64) NULL,
  demo_seen_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uniq_churches_slug (slug),
  UNIQUE KEY uniq_churches_demo_token (demo_token),
  INDEX idx_churches_deleted_at (deleted_at),
  INDEX idx_churches_is_demo (is_demo),
  INDEX idx_churches_demo_seen_at (demo_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE church_landing (
  church_id INT UNSIGNED NOT NULL,
  seo_title VARCHAR(190) NULL,
  seo_description TEXT NULL,
  og_image VARCHAR(500) NULL,
  logo VARCHAR(500) NULL,
  description TEXT NULL,
  how_to_get TEXT NULL,
  address_text VARCHAR(500) NULL,
  address_lat DECIMAL(10,7) NULL,
  address_lng DECIMAL(10,7) NULL,
  donation_label VARCHAR(120) NULL,
  donation_url VARCHAR(500) NULL,
  contacts_json JSON NULL,
  gallery_json JSON NULL,
  pastors_json JSON NULL,
  schedule_json JSON NULL,
  events_json JSON NULL,
  ministries_json JSON NULL,
  faq_json JSON NULL,
  buttons_json JSON NULL,
  sections_json JSON NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (church_id),
  CONSTRAINT fk_church_landing_church
    FOREIGN KEY (church_id) REFERENCES churches (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  login VARCHAR(80) NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('member','assistant','leader','district_leader','pastor','admin','super_admin') NOT NULL DEFAULT 'member',
  church_id INT UNSIGNED NULL,
  group_id INT UNSIGNED NULL,
  two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
  two_factor_secret VARCHAR(32) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_users_email_church (email, church_id),
  INDEX (church_id),
  INDEX (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE districts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  church_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  source ENUM('osm', 'manual') NOT NULL DEFAULT 'manual',
  external_ref VARCHAR(64) NULL,
  polygon_geojson JSON NOT NULL,
  bbox_min_lat DECIMAL(10,7) NOT NULL,
  bbox_min_lon DECIMAL(10,7) NOT NULL,
  bbox_max_lat DECIMAL(10,7) NOT NULL,
  bbox_max_lon DECIMAL(10,7) NOT NULL,
  color VARCHAR(7) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_districts_church (church_id),
  INDEX idx_districts_church_active (church_id, is_active),
  INDEX idx_districts_bbox (church_id, bbox_min_lat, bbox_max_lat, bbox_min_lon, bbox_max_lon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE district_leaders (
  district_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (district_id, user_id),
  INDEX idx_district_leaders_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE district_catalog_cache (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cache_key VARCHAR(190) NOT NULL,
  city_label VARCHAR(160) NOT NULL,
  payload JSON NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  UNIQUE KEY uniq_district_catalog_cache_key (cache_key),
  INDEX idx_district_catalog_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE home_groups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  church_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  leader_person_id INT UNSIGNED NULL,
  assistant_person_id INT UNSIGNED NULL,
  district VARCHAR(160) NULL,
  district_id INT UNSIGNED NULL,
  address VARCHAR(255) NULL,
  short_address VARCHAR(255) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  weekday VARCHAR(40) NULL,
  age_category VARCHAR(32) NULL,
  group_types JSON NOT NULL DEFAULT ('[]'),
  meeting_weekday TINYINT UNSIGNED NULL,
  meeting_time_start TIME NULL,
  meeting_time_end TIME NULL,
  max_people INT UNSIGNED NOT NULL DEFAULT 18,
  opened_at DATE NULL,
  closed_at DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (church_id),
  INDEX idx_home_groups_district (district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE people (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  group_id INT UNSIGNED NULL,
  full_name VARCHAR(190) NOT NULL,
  phone VARCHAR(80) NULL,
  email VARCHAR(190) NULL,
  telegram VARCHAR(120) NULL,
  birth_date DATE NULL,
  address VARCHAR(255) NULL,
  marital_status VARCHAR(80) NULL,
  first_visit_date DATE NULL,
  repentance_date DATE NULL,
  baptism_date DATE NULL,
  membership_date DATE NULL,
  ministry VARCHAR(160) NULL,
  spiritual_stage_id INT UNSIGNED NULL,
  is_leader TINYINT(1) NOT NULL DEFAULT 0,
  is_potential_leader TINYINT(1) NOT NULL DEFAULT 0,
  training_stage VARCHAR(120) NULL,
  notes TEXT NULL,
  sensitive_notes_encrypted TEXT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  retention_until DATE NULL,
  deleted_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (group_id),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE person_group_memberships (
  person_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (person_id, group_id),
  INDEX (group_id),
  INDEX (person_id, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meetings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id INT UNSIGNED NOT NULL,
  meeting_date DATE NOT NULL,
  topic VARCHAR(190) NULL,
  host_person_id INT UNSIGNED NULL,
  atmosphere ENUM('excellent','good','normal','hard') NULL,
  had_conflicts TINYINT(1) NOT NULL DEFAULT 0,
  pastor_help_needed TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (group_id, meeting_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance (
  meeting_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  status ENUM('present','absent','new') NOT NULL DEFAULT 'present',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (meeting_id, person_id),
  INDEX (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prayer_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NULL,
  meeting_id INT UNSIGNED NULL,
  group_id INT UNSIGNED NULL,
  church_id INT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  submitter_name VARCHAR(190) NULL,
  is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('new','praying','testimony','closed') NOT NULL DEFAULT 'new',
  next_reminder_at DATE NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at TIMESTAMP NULL,
  INDEX (person_id),
  INDEX (group_id),
  INDEX (church_id),
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE miracles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  church_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NULL,
  person_id INT UNSIGNED NULL,
  prayer_request_id INT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  submitter_name VARCHAR(190) NULL,
  is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('published','archived') NOT NULL DEFAULT 'published',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  published_at TIMESTAMP NULL,
  UNIQUE KEY uniq_miracle_prayer (prayer_request_id),
  INDEX (church_id, status, published_at),
  INDEX (group_id),
  INDEX (person_id),
  INDEX (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  contact_date DATE NOT NULL,
  type ENUM('call','telegram','meeting','note','visit') NOT NULL DEFAULT 'note',
  summary VARCHAR(255) NOT NULL,
  absence_reason VARCHAR(190) NULL,
  next_contact_at DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (person_id, contact_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NULL,
  group_id INT UNSIGNED NULL,
  assigned_to INT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  due_date DATE NOT NULL,
  type ENUM('newcomer','absence','prayer','service','meeting','custom') NOT NULL DEFAULT 'custom',
  status ENUM('open','done','cancelled') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  active_task_key VARCHAR(8) GENERATED ALWAYS AS (CASE WHEN status IN ('open','done') THEN 'active' ELSE NULL END) STORED,
  UNIQUE KEY uniq_active_task (person_id, assigned_to, type, due_date, active_task_key),
  INDEX (assigned_to, due_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_schedule (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  service_role_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (person_id),
  INDEX (meeting_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_settings (
  setting_key VARCHAR(120) PRIMARY KEY,
  setting_value TEXT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role ENUM('member','assistant','leader','district_leader','pastor','admin','super_admin') NOT NULL,
  action VARCHAR(120) NOT NULL,
  allowed TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_role_action (role, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE field_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role ENUM('member','assistant','leader','district_leader','pastor','admin','super_admin') NOT NULL,
  field_name VARCHAR(120) NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 1,
  can_edit TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_role_field (role, field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE backup_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  kind ENUM('export','restore') NOT NULL DEFAULT 'export',
  filename VARCHAR(190) NULL,
  status ENUM('created','restored','failed') NOT NULL DEFAULT 'created',
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE data_deletion_requests (
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

CREATE TABLE person_change_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  field_name VARCHAR(120) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (person_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE spiritual_stages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  church_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_final TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_church_stage (church_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE courses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  church_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  required_stage_id INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (church_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_lessons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  quiz_question VARCHAR(255) NULL,
  quiz_answer VARCHAR(120) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_progress (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NOT NULL,
  lesson_id INT UNSIGNED NOT NULL,
  status ENUM('open','passed') NOT NULL DEFAULT 'open',
  passed_at TIMESTAMP NULL,
  UNIQUE KEY uniq_person_lesson (person_id, lesson_id),
  INDEX (person_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meeting_reports (
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

CREATE TABLE import_batches (
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

CREATE TABLE knowledge_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type ENUM('training','faq','article','icebreaker','template') NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  image_path VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE user_notification_settings (
  user_id INT UNSIGNED PRIMARY KEY,
  email_enabled TINYINT(1) NOT NULL DEFAULT 1,
  webpush_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  used_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_password_reset_token (token_hash),
  INDEX (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE account_invitations (
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

CREATE TABLE church_invitations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash VARCHAR(64) NOT NULL,
  token_cipher TEXT NULL,
  note VARCHAR(190) NULL,
  created_by INT UNSIGNED NULL,
  expires_at TIMESTAMP NULL,
  used_at TIMESTAMP NULL,
  used_by_user_id INT UNSIGNED NULL,
  used_church_id INT UNSIGNED NULL,
  revoked_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_church_invite_token (token_hash),
  INDEX (created_by, created_at),
  INDEX (revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE church_invitation_uses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invite_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  church_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (invite_id, created_at),
  INDEX (user_id),
  INDEX (church_id),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_invitation_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  invited_by INT UNSIGNED NULL,
  requested_role ENUM('assistant','leader','district_leader','pastor','admin') NOT NULL,
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

CREATE TABLE participant_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  church_id INT UNSIGNED NULL,
  full_name VARCHAR(190) NOT NULL,
  first_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(80) NULL,
  telegram VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  district VARCHAR(160) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  source_provider ENUM('site','yandex','vk','max','telegram') NOT NULL DEFAULT 'site',
  source_external_id VARCHAR(190) NULL,
  recommended_group_id INT UNSIGNED NULL,
  target_group_id INT UNSIGNED NULL,
  message TEXT NULL,
  consent_given TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('new','approved','declined') NOT NULL DEFAULT 'new',
  person_id INT UNSIGNED NULL,
  handled_by INT UNSIGNED NULL,
  handled_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (status, created_at),
  INDEX (church_id, status),
  INDEX (target_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  to_email VARCHAR(190) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  type VARCHAR(80) NOT NULL DEFAULT 'system',
  status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL,
  INDEX (status, created_at),
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE web_push_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth VARCHAR(255) NOT NULL,
  user_agent VARCHAR(255) NULL,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_endpoint (user_id, endpoint(191)),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  url VARCHAR(255) NOT NULL DEFAULT '/',
  channel ENUM('webpush','telegram','vk','max','in_app') NOT NULL DEFAULT 'in_app',
  status ENUM('pending','sent','failed','read') NOT NULL DEFAULT 'pending',
  actions JSON NULL,
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL,
  read_at TIMESTAMP NULL,
  INDEX (user_id, status),
  INDEX (channel, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE social_identities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  provider ENUM('yandex','vk') NOT NULL,
  external_user_id VARCHAR(190) NOT NULL,
  profile_name VARCHAR(190) NULL,
  addresses_json JSON NULL,
  linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_social_provider_user (provider, external_user_id),
  UNIQUE KEY uniq_user_provider (user_id, provider),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oauth_states (
  state VARCHAR(64) PRIMARY KEY,
  provider ENUM('yandex','vk') NOT NULL,
  mode ENUM('login','link','join') NOT NULL DEFAULT 'login',
  user_id INT UNSIGNED NULL,
  return_to VARCHAR(255) NOT NULL DEFAULT '/',
  code_verifier VARCHAR(128) NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bot_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  provider ENUM('max','vk','telegram') NOT NULL,
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

CREATE TABLE bot_link_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  provider ENUM('max','vk','telegram') NULL,
  expires_at TIMESTAMP NOT NULL,
  used_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_bot_link_token (token_hash),
  INDEX (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bot_inbound_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider ENUM('max','vk','telegram') NOT NULL,
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

CREATE TABLE yandex_geocode_cache (
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

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id INT UNSIGNED NULL,
  details JSON NULL,
  ip VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_api_keys (
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

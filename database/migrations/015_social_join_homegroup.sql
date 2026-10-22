SET NAMES utf8mb4;

ALTER TABLE oauth_states
  MODIFY mode ENUM('login','link','join') NOT NULL DEFAULT 'login';

ALTER TABLE participant_requests
  MODIFY source_provider ENUM('site','yandex','vk','max') NOT NULL DEFAULT 'site';

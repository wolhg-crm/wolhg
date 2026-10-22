SET NAMES utf8mb4;

-- Чудеса / свидетельства: ответы Господа на молитвенные нужды и самостоятельные истории.
CREATE TABLE IF NOT EXISTS miracles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  church_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NULL,
  person_id INT UNSIGNED NULL,
  prayer_request_id INT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
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

SET NAMES utf8mb4;

INSERT INTO users (name, login, email, password_hash, role, church_id, group_id)
VALUES (
  'Базовый суперадминистратор',
  'admin',
  'admin@example.test',
  '$2y$10$3m3bPaZrVY6V3ZKs0h6FAupfPjAqKp0IELcYorImV2neCRjfDJQGe',
  'super_admin',
  (SELECT id FROM churches ORDER BY id LIMIT 1),
  NULL
)
ON DUPLICATE KEY UPDATE
  role = 'super_admin',
  church_id = COALESCE(church_id, VALUES(church_id)),
  group_id = NULL;

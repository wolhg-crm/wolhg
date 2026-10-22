CREATE TABLE IF NOT EXISTS person_group_memberships (
  person_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (person_id, group_id),
  INDEX (group_id),
  INDEX (person_id, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO person_group_memberships (person_id, group_id, is_primary)
SELECT p.id, p.group_id, 1
FROM people p
WHERE p.group_id IS NOT NULL
  AND p.deleted_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM person_group_memberships pgm
    WHERE pgm.person_id = p.id AND pgm.group_id = p.group_id
  );

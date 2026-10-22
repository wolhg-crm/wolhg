SET NAMES utf8mb4;

-- Публичные молитвенные нужды: имя гостя, анонимность и церковь для видимости.
ALTER TABLE prayer_requests
  ADD COLUMN submitter_name VARCHAR(190) NULL AFTER description,
  ADD COLUMN is_anonymous TINYINT(1) NOT NULL DEFAULT 0 AFTER submitter_name,
  ADD COLUMN church_id INT UNSIGNED NULL AFTER group_id,
  ADD INDEX (church_id);

UPDATE prayer_requests pr
JOIN home_groups g ON g.id = pr.group_id
SET pr.church_id = g.church_id
WHERE pr.church_id IS NULL;

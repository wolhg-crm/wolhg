SET NAMES utf8mb4;

ALTER TABLE home_groups
  ADD COLUMN church_id INT UNSIGNED NULL AFTER id;

UPDATE home_groups g
JOIN regions r ON r.id = g.region_id
SET g.church_id = r.church_id;

ALTER TABLE home_groups
  MODIFY church_id INT UNSIGNED NOT NULL,
  DROP INDEX region_id,
  DROP COLUMN region_id,
  ADD INDEX (church_id);

ALTER TABLE users
  DROP INDEX region_id,
  DROP COLUMN region_id;

DROP TABLE regions;

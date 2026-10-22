-- Church URL slug for multi-city path-prefix routing (/{slug}/...).

ALTER TABLE churches
  ADD COLUMN slug VARCHAR(64) NULL AFTER city;

UPDATE churches
SET slug = 'example'
WHERE id = 1 AND (slug IS NULL OR slug = '');

UPDATE churches
SET slug = CONCAT('church-', id)
WHERE slug IS NULL OR slug = '';

ALTER TABLE churches
  MODIFY slug VARCHAR(64) NOT NULL,
  ADD UNIQUE KEY uniq_churches_slug (slug);

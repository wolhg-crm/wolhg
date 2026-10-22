-- Scope participant join requests to a church.

ALTER TABLE participant_requests
  ADD COLUMN church_id INT UNSIGNED NULL AFTER id,
  ADD INDEX (church_id, status);

UPDATE participant_requests pr
LEFT JOIN home_groups tg ON tg.id = pr.target_group_id
LEFT JOIN home_groups rg ON rg.id = pr.recommended_group_id
SET pr.church_id = COALESCE(tg.church_id, rg.church_id, 1)
WHERE pr.church_id IS NULL;

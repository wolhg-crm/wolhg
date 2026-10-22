-- Soft-delete for churches (platform admin). Active tenants keep deleted_at IS NULL.

ALTER TABLE churches
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER created_at,
  ADD INDEX idx_churches_deleted_at (deleted_at);

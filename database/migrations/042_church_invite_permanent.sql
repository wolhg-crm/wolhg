SET NAMES utf8mb4;

-- Permanent reusable pastor signup links: revoke explicitly, never auto-expire/consume.

ALTER TABLE church_invitations
  ADD COLUMN revoked_at TIMESTAMP NULL AFTER used_church_id,
  MODIFY expires_at TIMESTAMP NULL;

CREATE TABLE IF NOT EXISTS church_invitation_uses (
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

-- Old revoke = used_at without a created user.
UPDATE church_invitations
SET revoked_at = used_at
WHERE used_at IS NOT NULL AND used_by_user_id IS NULL AND revoked_at IS NULL;

-- Preserve historical consumes before clearing one-shot flags.
INSERT INTO church_invitation_uses (invite_id, user_id, church_id, created_at)
SELECT id, used_by_user_id, used_church_id, used_at
FROM church_invitations
WHERE used_by_user_id IS NOT NULL
  AND used_church_id IS NOT NULL
  AND used_at IS NOT NULL;

-- Reactivate non-revoked invites as permanent multi-use links.
UPDATE church_invitations
SET expires_at = NULL,
    used_at = NULL,
    used_by_user_id = NULL,
    used_church_id = NULL
WHERE revoked_at IS NULL;

-- Demo church workspaces: template (slug=demo) + per-browser clones (demo_token).

ALTER TABLE churches
  ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER personal_data_consent_text,
  ADD COLUMN demo_token CHAR(64) NULL AFTER is_demo,
  ADD COLUMN demo_seen_at TIMESTAMP NULL DEFAULT NULL AFTER demo_token,
  ADD UNIQUE KEY uniq_churches_demo_token (demo_token),
  ADD INDEX idx_churches_is_demo (is_demo),
  ADD INDEX idx_churches_demo_seen_at (demo_seen_at);

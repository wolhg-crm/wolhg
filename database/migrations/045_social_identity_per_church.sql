SET NAMES utf8mb4;

-- Same Yandex/VK profile may be linked to one CRM user per church.
-- Drop the global (provider, external_user_id) unique; keep one link per user+provider.

ALTER TABLE social_identities
  DROP INDEX uniq_social_provider_user,
  ADD INDEX idx_social_provider_external (provider, external_user_id);

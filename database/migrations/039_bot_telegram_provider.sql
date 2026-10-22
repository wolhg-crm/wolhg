SET NAMES utf8mb4;

-- Telegram joins Max/VK as a first-class private bot channel.
ALTER TABLE bot_accounts
  MODIFY provider ENUM('max','vk','telegram') NOT NULL;

ALTER TABLE bot_link_tokens
  MODIFY provider ENUM('max','vk','telegram') NULL;

ALTER TABLE bot_inbound_messages
  MODIFY provider ENUM('max','vk','telegram') NOT NULL;

ALTER TABLE participant_requests
  MODIFY source_provider ENUM('site','yandex','vk','max','telegram') NOT NULL DEFAULT 'site';

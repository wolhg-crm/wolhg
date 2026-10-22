SET NAMES utf8mb4;

ALTER TABLE churches
  ADD COLUMN personal_data_consent_text TEXT NULL AFTER timezone;

ALTER TABLE home_groups
  ADD COLUMN short_address VARCHAR(255) NULL AFTER address,
  ADD COLUMN age_category VARCHAR(32) NULL AFTER weekday,
  ADD COLUMN group_types JSON NOT NULL DEFAULT ('[]') AFTER age_category,
  ADD COLUMN meeting_weekday TINYINT UNSIGNED NULL COMMENT '1=Mon..7=Sun' AFTER group_types,
  ADD COLUMN meeting_time_start TIME NULL AFTER meeting_weekday,
  ADD COLUMN meeting_time_end TIME NULL AFTER meeting_time_start;

ALTER TABLE participant_requests
  ADD COLUMN first_name VARCHAR(100) NULL AFTER full_name,
  ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name,
  ADD COLUMN target_group_id INT UNSIGNED NULL AFTER recommended_group_id,
  ADD COLUMN consent_given TINYINT(1) NOT NULL DEFAULT 0 AFTER message,
  ADD INDEX (target_group_id);

UPDATE home_groups SET meeting_weekday = 2, meeting_time_start = '19:00:00', meeting_time_end = '21:00:00', age_category = 'adults', group_types = '["general"]', short_address = district WHERE id = 1;
UPDATE home_groups SET meeting_weekday = 4, meeting_time_start = '19:30:00', meeting_time_end = '21:30:00', age_category = 'youth_under25', group_types = '["general"]', short_address = district WHERE id = 2;
UPDATE home_groups SET meeting_weekday = 3, meeting_time_start = '19:00:00', meeting_time_end = '21:00:00', age_category = 'adults', group_types = '["general"]', short_address = district WHERE id = 3;

UPDATE churches SET personal_data_consent_text = 'Настоящим я даю согласие религиозной организации на обработку моих персональных данных (имя, фамилия, телефон, Telegram), указанных в форме заявки, в целях связи со мной и рассмотрения моего участия в домашней группе.' WHERE personal_data_consent_text IS NULL;

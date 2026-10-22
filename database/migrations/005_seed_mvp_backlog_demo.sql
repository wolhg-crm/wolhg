SET NAMES utf8mb4;

UPDATE home_groups SET latitude = 56.0189000, longitude = 37.8486000 WHERE id = 1;
UPDATE home_groups SET latitude = 56.0067000, longitude = 37.8421000 WHERE id = 2;
UPDATE home_groups SET latitude = 56.0105000, longitude = 37.8479000 WHERE id = 3;

INSERT IGNORE INTO app_settings (setting_key, setting_value, is_secret) VALUES
  ('vapid_subject', '', 0);

INSERT IGNORE INTO spiritual_stages (id, church_id, name, sort_order, is_final) VALUES
  (1, 1, 'Новичок', 10, 0),
  (2, 1, 'Первый месяц', 20, 0),
  (3, 1, 'Членство', 30, 0),
  (4, 1, 'Служение', 40, 0),
  (5, 1, 'Ученик лидера', 50, 0),
  (6, 1, 'Лидер', 60, 1);

UPDATE people SET spiritual_stage_id = 6 WHERE id IN (1,9);
UPDATE people SET spiritual_stage_id = 5 WHERE id IN (2,7);
UPDATE people SET spiritual_stage_id = 1 WHERE id IN (3,5,10);
UPDATE people SET spiritual_stage_id = 4 WHERE id IN (4,6);
UPDATE people SET spiritual_stage_id = 2 WHERE id = 8;

INSERT IGNORE INTO courses (id, church_id, title, required_stage_id, sort_order) VALUES
  (1, 1, 'Первые шаги в домашней группе', 1, 10),
  (2, 1, 'Основы служения и заботы', 3, 20),
  (3, 1, 'Школа будущего лидера', 5, 30);

INSERT IGNORE INTO course_lessons (id, course_id, title, body, quiz_question, quiz_answer, sort_order) VALUES
  (1, 1, 'Как устроена домашняя группа', 'Короткий урок о цели группы, заботе и регулярности.', 'Что важно сделать после первого посещения?', 'контакт', 10),
  (2, 1, 'Как оставаться на связи', 'Практика мягкого контакта с лидером и группой.', 'Какой канал связи выбрать?', 'удобный', 20),
  (3, 2, 'Как замечать нужды людей', 'Собирайте факты, молитесь и возвращайтесь к человеку.', 'Где фиксировать нужду?', 'карточка', 10),
  (4, 3, 'Как готовить помощника', 'Давайте ответственность постепенно и обсуждайте обратную связь.', 'Что передавать будущему лидеру?', 'ответственность', 10);

INSERT IGNORE INTO course_progress (person_id, lesson_id, status, passed_at) VALUES
  (3, 1, 'passed', NOW()),
  (2, 3, 'passed', NOW());

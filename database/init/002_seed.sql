SET NAMES utf8mb4;

INSERT INTO churches (id, name, city, slug, personal_data_consent_text) VALUES
  (1, 'Примерная церковь', 'Примерск', 'example', 'Настоящим я даю согласие религиозной организации «Примерная церковь» на обработку моих персональных данных (имя, фамилия, телефон, Telegram), указанных в форме заявки, в целях связи со мной и рассмотрения моего участия в домашней группе. Согласие действует до его отзыва и может быть отозвано путём обращения к администратору церкви.');

INSERT INTO users (id, name, login, email, password_hash, role, church_id, group_id) VALUES
  (1, 'Пастор Алексей', 'pastor', 'pastor@church.local', '$2y$10$3m3bPaZrVY6V3ZKs0h6FAupfPjAqKp0IELcYorImV2neCRjfDJQGe', 'pastor', 1, NULL),
  (2, 'Лидер Андрей', 'leader', 'leader@church.local', '$2y$10$3m3bPaZrVY6V3ZKs0h6FAupfPjAqKp0IELcYorImV2neCRjfDJQGe', 'leader', 1, 1),
  (3, 'Помощник Мария', 'helper', 'helper@church.local', '$2y$10$3m3bPaZrVY6V3ZKs0h6FAupfPjAqKp0IELcYorImV2neCRjfDJQGe', 'assistant', 1, 1),
  (4, 'Участник Иван', 'member', 'member@church.local', '$2y$10$3m3bPaZrVY6V3ZKs0h6FAupfPjAqKp0IELcYorImV2neCRjfDJQGe', 'member', 1, 1),
  (5, 'Окружной лидер Елена', 'district', 'regional@church.local', '$2y$10$3m3bPaZrVY6V3ZKs0h6FAupfPjAqKp0IELcYorImV2neCRjfDJQGe', 'district_leader', 1, NULL),
  (6, 'Суперадминистратор', 'admin', 'admin@church.local', '$2y$10$3m3bPaZrVY6V3ZKs0h6FAupfPjAqKp0IELcYorImV2neCRjfDJQGe', 'super_admin', 1, NULL);

INSERT INTO home_groups (id, church_id, name, leader_person_id, assistant_person_id, district, address, short_address, weekday, age_category, group_types, meeting_weekday, meeting_time_start, meeting_time_end, max_people, opened_at) VALUES
  (1, 1, 'Домашняя группа №5', 1, 2, 'Северный', 'Примерск, ул. Набережная, 12', 'Примерск / Северный', 'вторник', 'adults', '["general"]', 2, '19:00:00', '21:00:00', 18, '2025-09-01'),
  (2, 1, 'Домашняя группа Молодежь', 9, NULL, 'Западный', 'Примерск, ул. Садовая, 5', 'Примерск / Западный', 'четверг', 'youth_under25', '["general"]', 4, '19:30:00', '21:30:00', 16, '2026-02-01'),
  (3, 1, 'Домашняя группа Центр', NULL, NULL, 'Центр', 'Примерск, Центральный проспект, 20', 'Примерск / Центр', 'среда', 'adults', '["family","general"]', 3, '19:00:00', '21:00:00', 14, '2026-04-15');

INSERT INTO people (id, user_id, group_id, full_name, phone, telegram, birth_date, address, marital_status, first_visit_date, repentance_date, baptism_date, membership_date, ministry, is_leader, is_potential_leader, training_stage, notes) VALUES
  (1, 2, 1, 'Андрей Смирнов', '+79990000001', 'andrey_leader', '1988-04-20', 'Примерск, Северный', 'женат', '2023-01-15', '2023-02-01', '2023-06-10', '2023-09-01', 'ведущий группы', 1, 0, 'лидер', 'Лидер домашней группы.'),
  (2, 3, 1, 'Мария Павлова', '+79990000002', 'maria_helper', '1991-11-08', 'Примерск', 'замужем', '2023-03-10', '2023-03-20', '2023-08-12', '2024-01-10', 'гостеприимство', 0, 1, 'помощник лидера', 'Помощник лидера.'),
  (3, 4, 1, 'Иван Кузнецов', '+79990000003', 'ivan_k', '1995-06-04', 'Примерск, Новая деревня', 'не женат', '2025-10-07', '2025-11-01', NULL, NULL, NULL, 0, 0, 'новичок', 'Нужна поддержка в регулярности.'),
  (4, NULL, 1, 'Сергей Иванов', '+79990000004', 'sergey_i', '1984-02-12', 'Соседний город', 'женат', '2024-12-03', '2025-01-12', '2025-05-17', NULL, 'поклонение', 0, 0, 'служение', NULL),
  (5, NULL, 1, 'Ольга Соколова', '+79990000005', 'olga_s', '1998-09-17', 'Примерск', 'не замужем', CURDATE() - INTERVAL 21 DAY, NULL, NULL, NULL, NULL, 0, 0, 'первый месяц', 'Первый контакт после встречи обязателен.'),
  (6, NULL, 1, 'Дмитрий Орлов', '+79990000006', 'd_orlov', '1979-01-30', 'Примерск', 'женат', '2023-11-21', '2023-12-02', '2024-04-14', '2024-07-01', 'чай', 0, 0, 'членство', NULL),
  (7, NULL, 1, 'Екатерина Белова', '+79990000007', 'katya_b', '1993-12-11', 'Примерск', 'замужем', '2024-08-13', '2024-09-01', NULL, NULL, 'детская комната', 0, 1, 'обучение лидера', NULL),
  (8, NULL, 1, 'Николай Морозов', '+79990000008', 'n_morozov', '1986-07-25', 'Соседний город', 'женат', '2025-05-20', NULL, NULL, NULL, NULL, 0, 0, 'наблюдение', 'Давно нет контакта.'),
  (9, NULL, 2, 'Павел Федоров', '+79990000009', 'pavel_f', '1990-03-03', 'Примерск', 'женат', '2024-04-04', '2024-05-01', '2024-09-21', NULL, 'молодежь', 1, 0, 'лидер', NULL),
  (10, NULL, 2, 'Анна Лебедева', '+79990000010', 'anna_l', '2001-10-10', 'Примерск', 'не замужем', CURDATE() - INTERVAL 12 DAY, NULL, NULL, NULL, NULL, 0, 0, 'новичок', NULL);

INSERT INTO meetings (id, group_id, meeting_date, topic, host_person_id, atmosphere, had_conflicts, pastor_help_needed, notes, created_by) VALUES
  (1, 1, CURDATE() - INTERVAL 42 DAY, 'Общение и молитва', 1, 'good', 0, 0, 'Теплая встреча.', 2),
  (2, 1, CURDATE() - INTERVAL 28 DAY, 'Вера в повседневности', 1, 'normal', 0, 0, NULL, 2),
  (3, 1, CURDATE() - INTERVAL 14 DAY, 'Служение друг другу', 2, 'good', 0, 0, NULL, 2),
  (4, 1, CURDATE() - INTERVAL 7 DAY, 'Молитва за город', 1, 'excellent', 0, 0, 'Были новые гости.', 2),
  (5, 1, CURDATE(), 'Подготовка к умножению', 1, NULL, 0, 0, NULL, 2),
  (6, 2, CURDATE() - INTERVAL 7 DAY, 'Старт молодежной группы', 9, 'good', 0, 0, NULL, 1);

INSERT INTO attendance (meeting_id, person_id, status) VALUES
  (1,1,'present'),(1,2,'present'),(1,3,'present'),(1,4,'present'),(1,6,'present'),(1,8,'present'),
  (2,1,'present'),(2,2,'present'),(2,3,'absent'),(2,4,'present'),(2,6,'present'),(2,7,'present'),
  (3,1,'present'),(3,2,'present'),(3,3,'absent'),(3,4,'present'),(3,5,'new'),(3,7,'present'),
  (4,1,'present'),(4,2,'present'),(4,3,'absent'),(4,5,'present'),(4,6,'present'),(4,7,'present'),
  (6,9,'present'),(6,10,'new');

INSERT INTO contact_logs (person_id, user_id, contact_date, type, summary, absence_reason, next_contact_at) VALUES
  (3, 2, CURDATE() - INTERVAL 35 DAY, 'call', 'Созвонились, обещал прийти после смены графика.', 'работа', CURDATE() - INTERVAL 20 DAY),
  (5, 2, CURDATE() - INTERVAL 5 DAY, 'telegram', 'Поблагодарили за визит и пригласили снова.', NULL, CURDATE() + INTERVAL 2 DAY),
  (8, 3, CURDATE() - INTERVAL 55 DAY, 'meeting', 'Встретились после служения, нужна поддержка.', NULL, CURDATE() - INTERVAL 35 DAY),
  (10, 1, CURDATE() - INTERVAL 3 DAY, 'telegram', 'Новичок молодежной группы, тепло ответила.', NULL, CURDATE() + INTERVAL 4 DAY);

INSERT INTO prayer_requests (person_id, meeting_id, group_id, title, description, status, next_reminder_at, created_by) VALUES
  (5, 4, 1, 'Адаптация в новой команде', 'Просит молиться за работу и мир в сердце.', 'praying', CURDATE() + INTERVAL 7 DAY, 2),
  (7, 3, 1, 'Здоровье мамы', 'Операция прошла, молимся за восстановление.', 'praying', CURDATE() + INTERVAL 2 DAY, 2),
  (4, 2, 1, 'Свидетельство по работе', 'Получил ответ на молитву по проекту.', 'testimony', NULL, 2),
  (8, NULL, 1, 'Вернуться в общение', 'Давно не был, важно мягко связаться.', 'new', CURDATE(), 3);

INSERT INTO miracles (church_id, group_id, person_id, prayer_request_id, title, body, is_anonymous, status, created_by, published_at)
SELECT 1, 1, 4, pr.id, 'Свидетельство по работе',
       'Молились за проект и мир в сердце. Господь открыл дверь: предложение приняли, а напряжение ушло. Слава Богу за ответ!',
       0, 'published', 2, NOW()
FROM prayer_requests pr
WHERE pr.title = 'Свидетельство по работе'
ORDER BY pr.id
LIMIT 1;

INSERT INTO tasks (person_id, group_id, assigned_to, title, due_date, type, status) VALUES
  (5, 1, 2, 'Написать новичку Ольге после первого посещения', CURDATE(), 'newcomer', 'open'),
  (3, 1, 2, 'Связаться с Иваном: не был больше месяца', CURDATE(), 'absence', 'open'),
  (8, 1, 2, 'Назначить встречу с Николаем', CURDATE() + INTERVAL 1 DAY, 'absence', 'open');

INSERT INTO service_roles (id, name) VALUES
  (1, 'Поклонение'),
  (2, 'Чай'),
  (3, 'Детская комната'),
  (4, 'Слово'),
  (5, 'Гостеприимство');

INSERT INTO service_schedule (meeting_id, person_id, service_role_id) VALUES
  (3, 4, 1),
  (3, 6, 2),
  (4, 7, 3),
  (4, 2, 5);

INSERT INTO knowledge_items (type, title, body, sort_order) VALUES
  ('training', 'Шаг 1. Как вести домашнюю группу', 'Цель встречи: люди встречаются со Христом и друг с другом. Держите простую структуру: приветствие, ледокол, Слово, обсуждение, молитва, следующий шаг.', 10),
  ('training', 'Шаг 2. Как принимать новичков', 'Назначьте человека, который встретит гостя, объяснит формат и напишет ему в течение двух дней после встречи.', 20),
  ('training', 'Шаг 3. Как проводить молитву', 'Собирайте конкретные нужды, уточняйте срок следующего контакта и возвращайтесь к свидетельствам.', 30),
  ('training', 'Шаг 4. Как решать конфликты', 'Не обсуждайте человека без него. Слушайте стороны отдельно, затем помогайте договориться о следующем честном шаге.', 40),
  ('article', 'Как организовать умножение группы', 'Следите за будущими лидерами, давайте им маленькие зоны ответственности, готовьте помощника и мягко проговаривайте видение новой группы.', 50),
  ('icebreaker', 'Три благодарности', 'Каждый называет три вещи, за которые благодарен Богу за последнюю неделю.', 10),
  ('icebreaker', 'История имени', 'Каждый коротко рассказывает, почему его так назвали или что ему нравится в имени.', 20),
  ('template', 'Сообщение новичку после встречи', 'Привет! Было приятно видеть тебя на домашней группе. Будем рады встретиться снова в следующий вторник.', 10),
  ('template', 'Сообщение человеку, которого давно не было', 'Привет! Мы давно тебя не видели. Всё ли хорошо? Чем можем помочь?', 20),
  ('template', 'Отчет после встречи', 'Встреча прошла: [атмосфера]. Присутствовали: [количество]. Новые люди: [имена]. Нужна помощь пастора: [да/нет].', 30),
  ('faq', 'Кто видит мои данные?', 'Участник видит только свою карточку. Лидер и помощник видят свою группу. Окружной лидер и пастор видят все группы церкви.', 10),
  ('faq', 'Как быстро отметить посещаемость?', 'Откройте встречу, отметьте чекбоксы участников и нажмите Сохранить. Список отсортирован по регулярности и последнему посещению.', 20);

UPDATE home_groups SET latitude = 56.0189000, longitude = 37.8486000 WHERE id = 1;
UPDATE home_groups SET latitude = 56.0067000, longitude = 37.8421000 WHERE id = 2;
UPDATE home_groups SET latitude = 56.0105000, longitude = 37.8479000 WHERE id = 3;

INSERT INTO districts (id, church_id, name, source, external_ref, polygon_geojson, bbox_min_lat, bbox_min_lon, bbox_max_lat, bbox_max_lon, color, is_active, sort_order) VALUES
  (1, 1, 'Северный', 'manual', NULL,
   '{"type":"Polygon","coordinates":[[[37.8300,56.0000],[37.8600,56.0000],[37.8600,56.0200],[37.8300,56.0200],[37.8300,56.0000]]]}',
   56.0000000, 37.8300000, 56.0200000, 37.8600000, '#E87722', 1, 10),
  (2, 1, 'Западный', 'manual', NULL,
   '{"type":"Polygon","coordinates":[[[37.8300,55.9900],[37.8600,55.9900],[37.8600,56.0050],[37.8300,56.0050],[37.8300,55.9900]]]}',
   55.9900000, 37.8300000, 56.0050000, 37.8600000, '#2F6FED', 1, 20),
  (3, 1, 'Центр', 'manual', NULL,
   '{"type":"Polygon","coordinates":[[[37.8400,56.0050],[37.8600,56.0050],[37.8600,56.0150],[37.8400,56.0150],[37.8400,56.0050]]]}',
   56.0050000, 37.8400000, 56.0150000, 37.8600000, '#1A9B6C', 1, 30);

INSERT INTO district_leaders (district_id, user_id) VALUES
  (1, 5),
  (2, 5),
  (3, 5);

UPDATE home_groups SET district_id = 1 WHERE id = 1;
UPDATE home_groups SET district_id = 2 WHERE id = 2;
UPDATE home_groups SET district_id = 3 WHERE id = 3;

INSERT INTO app_settings (setting_key, setting_value, is_secret) VALUES
  ('vapid_subject', '', 0);

INSERT INTO role_permissions (role, action, allowed) VALUES
  ('member','manage_structure',0),('member','manage_security',0),('member','edit_people',0),('member','delete_people',0),('member','manage_prayers',0),('member','manage_services',0),('member','import_data',0),('member','view_church_analytics',0),
  ('assistant','manage_structure',0),('assistant','manage_security',0),('assistant','edit_people',1),('assistant','delete_people',0),('assistant','manage_prayers',1),('assistant','manage_services',1),('assistant','import_data',0),('assistant','view_church_analytics',0),
  ('leader','manage_structure',0),('leader','manage_security',0),('leader','edit_people',1),('leader','delete_people',0),('leader','manage_prayers',1),('leader','manage_services',1),('leader','import_data',0),('leader','view_church_analytics',0),
  ('district_leader','manage_structure',0),('district_leader','manage_security',0),('district_leader','edit_people',1),('district_leader','delete_people',0),('district_leader','manage_prayers',1),('district_leader','manage_services',1),('district_leader','import_data',0),('district_leader','view_church_analytics',1),
  ('pastor','manage_structure',0),('pastor','manage_security',1),('pastor','edit_people',1),('pastor','delete_people',1),('pastor','manage_prayers',1),('pastor','manage_services',1),('pastor','import_data',0),('pastor','view_church_analytics',1),
  ('admin','manage_structure',1),('admin','manage_security',1),('admin','edit_people',1),('admin','delete_people',1),('admin','manage_prayers',1),('admin','manage_services',1),('admin','import_data',1),('admin','view_church_analytics',1),
  ('super_admin','manage_structure',1),('super_admin','manage_security',1),('super_admin','edit_people',1),('super_admin','delete_people',1),('super_admin','manage_prayers',1),('super_admin','manage_services',1),('super_admin','import_data',1),('super_admin','view_church_analytics',1);

INSERT INTO field_permissions (role, field_name, can_view, can_edit) VALUES
  ('member','sensitive_notes',0,0),('assistant','sensitive_notes',1,1),('leader','sensitive_notes',1,1),('district_leader','sensitive_notes',1,0),('pastor','sensitive_notes',1,1),('admin','sensitive_notes',1,1),('super_admin','sensitive_notes',1,1);

INSERT INTO spiritual_stages (id, church_id, name, sort_order, is_final) VALUES
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

INSERT INTO courses (id, church_id, title, required_stage_id, sort_order) VALUES
  (1, 1, 'Первые шаги в домашней группе', 1, 10),
  (2, 1, 'Основы служения и заботы', 3, 20),
  (3, 1, 'Школа будущего лидера', 5, 30);

INSERT INTO course_lessons (course_id, title, body, quiz_question, quiz_answer, sort_order) VALUES
  (1, 'Как устроена домашняя группа', 'Короткий урок о цели группы, заботе и регулярности.', 'Что важно сделать после первого посещения?', 'контакт', 10),
  (1, 'Как оставаться на связи', 'Практика мягкого контакта с лидером и группой.', 'Какой канал связи выбрать?', 'удобный', 20),
  (2, 'Как замечать нужды людей', 'Собирайте факты, молитесь и возвращайтесь к человеку.', 'Где фиксировать нужду?', 'карточка', 10),
  (3, 'Как готовить помощника', 'Давайте ответственность постепенно и обсуждайте обратную связь.', 'Что передавать будущему лидеру?', 'ответственность', 10);

INSERT INTO course_progress (person_id, lesson_id, status, passed_at) VALUES
  (3, 1, 'passed', NOW()),
  (2, 3, 'passed', NOW());

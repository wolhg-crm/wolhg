SET NAMES utf8mb4;

-- Minimal church for empty installs (no demo people/groups).
-- Use with: 001_schema.sql → this file → 003_bootstrap_admin.sql
-- Then create real churches via /admin or permanent /regchurch invite.

INSERT INTO churches (id, name, city, slug, personal_data_consent_text) VALUES
  (1, 'Примерная церковь', 'Примерск', 'example', 'Настоящим я даю согласие религиозной организации «Примерная церковь» на обработку моих персональных данных (имя, фамилия, телефон, Telegram), указанных в форме заявки, в целях связи со мной и рассмотрения моего участия в домашней группе. Согласие действует до его отзыва и может быть отозвано путём обращения к администратору церкви.');

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

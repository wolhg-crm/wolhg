-- Platform super_admin vs church-scoped admin.

ALTER TABLE users
  MODIFY role ENUM(
    'member',
    'assistant',
    'leader',
    'regional_leader',
    'pastor',
    'admin',
    'super_admin'
  ) NOT NULL DEFAULT 'member';

ALTER TABLE role_permissions
  MODIFY role ENUM(
    'member',
    'assistant',
    'leader',
    'regional_leader',
    'pastor',
    'admin',
    'super_admin'
  ) NOT NULL;

ALTER TABLE field_permissions
  MODIFY role ENUM(
    'member',
    'assistant',
    'leader',
    'regional_leader',
    'pastor',
    'admin',
    'super_admin'
  ) NOT NULL;

-- Existing platform admins become super_admins (preserve prod access).
UPDATE users SET role = 'super_admin' WHERE role = 'admin';

INSERT INTO role_permissions (role, action, allowed)
SELECT 'super_admin', action, allowed FROM role_permissions WHERE role = 'admin'
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

INSERT INTO field_permissions (role, field_name, can_view, can_edit)
SELECT 'super_admin', field_name, can_view, can_edit FROM field_permissions WHERE role = 'admin'
ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit);

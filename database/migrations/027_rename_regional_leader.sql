SET NAMES utf8mb4;

-- Step 1: allow both old and new role values.
ALTER TABLE users
  MODIFY role ENUM(
    'member',
    'assistant',
    'leader',
    'regional_leader',
    'district_leader',
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
    'district_leader',
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
    'district_leader',
    'pastor',
    'admin',
    'super_admin'
  ) NOT NULL;

ALTER TABLE role_invitation_requests
  MODIFY requested_role ENUM(
    'assistant',
    'leader',
    'regional_leader',
    'district_leader',
    'pastor'
  ) NOT NULL;

-- Step 2: migrate data.
UPDATE users SET role = 'district_leader' WHERE role = 'regional_leader';
UPDATE role_invitation_requests SET requested_role = 'district_leader' WHERE requested_role = 'regional_leader';

INSERT INTO role_permissions (role, action, allowed)
SELECT 'district_leader', action, allowed FROM role_permissions WHERE role = 'regional_leader'
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

INSERT INTO field_permissions (role, field_name, can_view, can_edit)
SELECT 'district_leader', field_name, can_view, can_edit FROM field_permissions WHERE role = 'regional_leader'
ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit);

DELETE FROM role_permissions WHERE role = 'regional_leader';
DELETE FROM field_permissions WHERE role = 'regional_leader';

-- Step 3: drop the old ENUM value.
ALTER TABLE users
  MODIFY role ENUM(
    'member',
    'assistant',
    'leader',
    'district_leader',
    'pastor',
    'admin',
    'super_admin'
  ) NOT NULL DEFAULT 'member';

ALTER TABLE role_permissions
  MODIFY role ENUM(
    'member',
    'assistant',
    'leader',
    'district_leader',
    'pastor',
    'admin',
    'super_admin'
  ) NOT NULL;

ALTER TABLE field_permissions
  MODIFY role ENUM(
    'member',
    'assistant',
    'leader',
    'district_leader',
    'pastor',
    'admin',
    'super_admin'
  ) NOT NULL;

ALTER TABLE role_invitation_requests
  MODIFY requested_role ENUM(
    'assistant',
    'leader',
    'district_leader',
    'pastor'
  ) NOT NULL;

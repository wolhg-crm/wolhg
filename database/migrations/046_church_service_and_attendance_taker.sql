SET NAMES utf8mb4;

-- Flag Sunday / church-wide attendance groups separately from home groups.
ALTER TABLE home_groups
  ADD COLUMN is_church_service TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

-- Expand role ENUMs for attendance_taker (служитель учёта посещаемости).
ALTER TABLE users
  MODIFY role ENUM(
    'member',
    'attendance_taker',
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
    'attendance_taker',
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
    'attendance_taker',
    'assistant',
    'leader',
    'district_leader',
    'pastor',
    'admin',
    'super_admin'
  ) NOT NULL;

ALTER TABLE role_invitation_requests
  MODIFY requested_role ENUM(
    'attendance_taker',
    'assistant',
    'leader',
    'district_leader',
    'pastor',
    'admin'
  ) NOT NULL;

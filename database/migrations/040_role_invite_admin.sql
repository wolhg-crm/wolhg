SET NAMES utf8mb4;

-- Super-admin may issue an invite for church administrator; ENUM must accept it.
ALTER TABLE role_invitation_requests
  MODIFY requested_role ENUM(
    'assistant',
    'leader',
    'district_leader',
    'pastor',
    'admin'
  ) NOT NULL;

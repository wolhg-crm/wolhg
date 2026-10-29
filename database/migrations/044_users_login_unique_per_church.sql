SET NAMES utf8mb4;

-- Multi-tenant isolation: the same login may exist in different churches.
-- Login lookup is always church-scoped (see find_user_for_church_login).
-- Composite starts with login so WHERE login = ? AND church_id = ? uses the index.

ALTER TABLE users
  DROP INDEX uniq_users_login,
  ADD UNIQUE KEY uniq_users_login_church (login, church_id);

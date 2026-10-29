SET NAMES utf8mb4;

-- Multi-tenant: the same email may register in different churches.
-- Uniqueness is (email, church_id). Login stays globally unique for auth.
-- Composite starts with email so WHERE email = ? still uses the index
-- (password reset, lookups). Leftmost prefix of (email, church_id).

ALTER TABLE users
  DROP INDEX email,
  ADD UNIQUE KEY uniq_users_email_church (email, church_id);

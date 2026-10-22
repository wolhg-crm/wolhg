ALTER TABLE social_identities
  ADD COLUMN addresses_json JSON NULL AFTER profile_name;

-- Public church mini-landing content (1:1 with churches).

CREATE TABLE church_landing (
  church_id INT UNSIGNED NOT NULL,
  seo_title VARCHAR(190) NULL,
  seo_description TEXT NULL,
  og_image VARCHAR(500) NULL,
  description TEXT NULL,
  how_to_get TEXT NULL,
  address_text VARCHAR(500) NULL,
  address_lat DECIMAL(10,7) NULL,
  address_lng DECIMAL(10,7) NULL,
  donation_label VARCHAR(120) NULL,
  donation_url VARCHAR(500) NULL,
  contacts_json JSON NULL,
  gallery_json JSON NULL,
  pastors_json JSON NULL,
  schedule_json JSON NULL,
  events_json JSON NULL,
  ministries_json JSON NULL,
  faq_json JSON NULL,
  buttons_json JSON NULL,
  sections_json JSON NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (church_id),
  CONSTRAINT fk_church_landing_church
    FOREIGN KEY (church_id) REFERENCES churches (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

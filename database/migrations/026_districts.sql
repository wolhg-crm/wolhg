SET NAMES utf8mb4;

CREATE TABLE districts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  church_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  source ENUM('osm', 'manual') NOT NULL DEFAULT 'manual',
  external_ref VARCHAR(64) NULL,
  polygon_geojson JSON NOT NULL,
  bbox_min_lat DECIMAL(10,7) NOT NULL,
  bbox_min_lon DECIMAL(10,7) NOT NULL,
  bbox_max_lat DECIMAL(10,7) NOT NULL,
  bbox_max_lon DECIMAL(10,7) NOT NULL,
  color VARCHAR(7) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_districts_church (church_id),
  INDEX idx_districts_church_active (church_id, is_active),
  INDEX idx_districts_bbox (church_id, bbox_min_lat, bbox_max_lat, bbox_min_lon, bbox_max_lon),
  CONSTRAINT fk_districts_church FOREIGN KEY (church_id) REFERENCES churches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE district_leaders (
  district_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (district_id, user_id),
  INDEX idx_district_leaders_user (user_id),
  CONSTRAINT fk_district_leaders_district FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE CASCADE,
  CONSTRAINT fk_district_leaders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE district_catalog_cache (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cache_key VARCHAR(190) NOT NULL,
  city_label VARCHAR(160) NOT NULL,
  payload JSON NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  UNIQUE KEY uniq_district_catalog_cache_key (cache_key),
  INDEX idx_district_catalog_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE home_groups
  ADD COLUMN district_id INT UNSIGNED NULL AFTER district,
  ADD INDEX idx_home_groups_district (district_id),
  ADD CONSTRAINT fk_home_groups_district FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE SET NULL;

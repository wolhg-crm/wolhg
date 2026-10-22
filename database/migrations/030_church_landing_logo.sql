-- Church landing logo (per-church mark for brand / about page).

ALTER TABLE church_landing
  ADD COLUMN logo VARCHAR(500) NULL AFTER og_image;

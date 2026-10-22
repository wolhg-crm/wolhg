-- Optional cover image for knowledge / training materials.
ALTER TABLE knowledge_items
  ADD COLUMN image_path VARCHAR(255) NULL AFTER body;

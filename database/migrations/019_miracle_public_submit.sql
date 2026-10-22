SET NAMES utf8mb4;

-- Имя гостя для публичной формы свидетельства (/miracle-share).
ALTER TABLE miracles
  ADD COLUMN submitter_name VARCHAR(190) NULL AFTER body;

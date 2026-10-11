-- Grizzly Music Archive
-- Migration: configurable scanner directory exclusions
-- 2026-10-11
--
-- media_scan_ignored remains unchanged and keeps its tombstone semantics.
-- media_scan_excluded contains preventive configuration rules:
-- the scanner must not enter these directories or any descendant.

CREATE TABLE IF NOT EXISTS `media_scan_excluded` (
  `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `path_hash`   CHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_path` VARCHAR(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_media_scan_excluded_hash` (`path_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

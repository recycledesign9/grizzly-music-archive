-- Grizzly Music Archive 0.4.0
-- Upgrade migration: automatic media scanner / external audio
--
-- Compatible with MySQL 5.7+, MySQL 8.0+ and MariaDB 10.6+.
-- Designed to be safe to run more than once.
--
-- IMPORTANT:
-- - Run this only on an EXISTING Grizzly database when upgrading to 0.4.0.
-- - New installations do not need this file: docker/db/01_schema.sql already
--   contains the current schema.
-- - This migration preserves existing albums, tracks, audio and settings.

SET @grizzly_schema = DATABASE();

-- ---------------------------------------------------------------------------
-- 1. Reference format required by scanner fallback
-- ---------------------------------------------------------------------------

INSERT INTO `formats` (`id`, `name`)
SELECT 4, 'Digital'
WHERE NOT EXISTS (
    SELECT 1
    FROM `formats`
    WHERE `name` = 'Digital'
);

-- ---------------------------------------------------------------------------
-- 2. Album scanner review metadata
-- ---------------------------------------------------------------------------

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'albums'
        AND COLUMN_NAME = 'needs_review') = 0,
    'ALTER TABLE `albums` ADD COLUMN `needs_review` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Set to 1 by the folder scanner; 0 for manually entered albums'' AFTER `mbid`',
    'SELECT 1'
);
PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'albums'
        AND COLUMN_NAME = 'review_note') = 0,
    'ALTER TABLE `albums` ADD COLUMN `review_note` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Why the scanner flagged this album (deduced artist, missing year, no cover)'' AFTER `needs_review`',
    'SELECT 1'
);
PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'albums'
        AND INDEX_NAME = 'idx_album_needs_review') = 0,
    'ALTER TABLE `albums` ADD KEY `idx_album_needs_review` (`needs_review`)',
    'SELECT 1'
);
PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

-- ---------------------------------------------------------------------------
-- 3. Per-format provenance
--    Existing rows are considered manual catalogue data.
-- ---------------------------------------------------------------------------

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'album_formats'
        AND COLUMN_NAME = 'is_manual') = 0,
    'ALTER TABLE `album_formats` ADD COLUMN `is_manual` TINYINT(1) NOT NULL DEFAULT 1 AFTER `format_id`',
    'SELECT 1'
);
PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'album_formats'
        AND COLUMN_NAME = 'is_scanner') = 0,
    'ALTER TABLE `album_formats` ADD COLUMN `is_scanner` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_manual`',
    'SELECT 1'
);
PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

-- Preserve existing catalogue provenance explicitly.
UPDATE `album_formats`
   SET `is_manual` = 1
 WHERE `is_manual` IS NULL;

UPDATE `album_formats`
   SET `is_scanner` = 0
 WHERE `is_scanner` IS NULL;

-- ---------------------------------------------------------------------------
-- 4. External/index-in-place audio metadata
-- ---------------------------------------------------------------------------

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'audio_files'
        AND COLUMN_NAME = 'source_hash') = 0,
    'ALTER TABLE `audio_files` ADD COLUMN `source_hash` CHAR(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `filesize`',
    'SELECT 1'
);
PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'audio_files'
        AND COLUMN_NAME = 'source_path') = 0,
    'ALTER TABLE `audio_files` ADD COLUMN `source_path` VARCHAR(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `source_hash`',
    'SELECT 1'
);
PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'audio_files'
        AND COLUMN_NAME = 'source_mtime') = 0,
    'ALTER TABLE `audio_files` ADD COLUMN `source_mtime` INT(10) UNSIGNED DEFAULT NULL AFTER `source_path`',
    'SELECT 1'
);
PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'audio_files'
        AND COLUMN_NAME = 'storage_type') = 0,
    'ALTER TABLE `audio_files` ADD COLUMN `storage_type` ENUM(''managed'',''external'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''managed'' AFTER `source_mtime`',
    'SELECT 1'
);
PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

-- Historical audio was uploaded/managed by Grizzly.
UPDATE `audio_files`
   SET `storage_type` = 'managed'
 WHERE `storage_type` IS NULL OR `storage_type` = '';

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'audio_files'
        AND INDEX_NAME = 'idx_audio_source_path') = 0,
    'ALTER TABLE `audio_files` ADD KEY `idx_audio_source_path` (`source_path`(255))',
    'SELECT 1'
);
PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

-- source_hash is deliberately NON-UNIQUE: identical audio content may legally
-- exist in more than one album/source location.
SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'audio_files'
        AND INDEX_NAME = 'idx_audio_source_hash') = 0,
    'ALTER TABLE `audio_files` ADD KEY `idx_audio_source_hash` (`source_hash`)',
    'SELECT 1'
);
PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

-- ---------------------------------------------------------------------------
-- 5. Scanner tombstones / ignored source folders
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `media_scan_ignored` (
    `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `path_hash`   CHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL,
    `source_path` VARCHAR(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_media_scan_ignored_hash` (`path_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. Scanner settings
--    INSERT IGNORE means an existing user's configuration is never overwritten.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO `settings` (`key`, `value`, `label`) VALUES
('media_scan_enabled', '0', 'Enable automatic media folder scan'),
('media_scan_path', '', 'Folder to scan automatically'),
('media_scan_interval', '10', 'Scanner interval in seconds'),
('media_scan_stable_seconds', '30', 'Seconds a folder must remain unchanged before import');

-- ---------------------------------------------------------------------------
-- 7. Align artist metadata defaults with the 0.4.0 clean schema.
--    Existing values are preserved; only defaults for future rows change.
-- ---------------------------------------------------------------------------

ALTER TABLE `artists`
    MODIFY `bio_status`
        VARCHAR(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none'
        COMMENT 'ok | error | none — outcome of the last bio fetch attempt',
    MODIFY `disco_status`
        VARCHAR(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none'
        COMMENT 'ok | error | none — outcome of the last discography fetch attempt',
    MODIFY `disco_fetch_version`
        SMALLINT UNSIGNED NOT NULL DEFAULT 5
        COMMENT 'Fetch-logic version at save time (ArtistMetadataService::DISCOGRAPHY_LOGIC_VERSION)';

-- ---------------------------------------------------------------------------
-- End of migration
-- ---------------------------------------------------------------------------

SELECT 'Grizzly Music Archive 0.4.0 migration completed.' AS result;

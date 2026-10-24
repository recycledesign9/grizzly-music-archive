-- Grizzly Music Archive 0.6.0
-- Cumulative upgrade migration
--
-- Brings an existing Grizzly database to the 0.6.0 schema in a single run.
-- Supported starting versions: 0.4.0, 0.4.1, 0.5.0 and 0.6.0 itself.
-- Older databases are not covered by this file without specific verification.
-- Compatible with MySQL 5.7+, MySQL 8.0+ and MariaDB 10.6+.
--
-- IMPORTANT:
-- - Back up the database before running it. MySQL commits every ALTER/CREATE
--   immediately, so an interrupted run cannot be rolled back automatically.
--   The file can simply be run again: each step checks the current state first.
-- - New installations do not need this file: docker/db/01_schema.sql already
--   contains the 0.6.0 schema.
-- - Historical migrations remain available in earlier Git release tags.
--   No earlier migration files need to be executed separately.
--
-- What it changes:
-- - adds only the tables, columns, indexes, settings and reference rows that
--   are missing;
-- - applies the 0.5.0 artist-cache baseline only to rows that never received it;
-- - never modifies albums, tracks, audio paths, playlists or existing settings
--   values. A second run changes nothing.

SET NAMES utf8mb4;
SET @grizzly_schema = DATABASE();

-- State before any change. Used to apply the one-time 0.5.0 data correction
-- only on databases that have not completed the 0.5.0 upgrade.
-- image_fetch_version is the last artist column added by 0.5.0 (both by the
-- historical file and by this one): when it exists, image_fetched_at and
-- image_status exist too, so the checks in section 7 never read a missing
-- column, even after an upgrade interrupted half-way through the columns.
SET @grizzly_pre_050 = (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'artists' AND COLUMN_NAME = 'image_fetch_version'
);

-- ---------------------------------------------------------------------------
-- 1. 0.4.0 - Reference format used by the scanner
-- ---------------------------------------------------------------------------

INSERT INTO `formats` (`id`, `name`)
SELECT 4, 'Digital' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `formats` WHERE `name` = 'Digital');

-- ---------------------------------------------------------------------------
-- 2. 0.4.0 / 0.5.0 - Albums: scanner review and release-group identity
--    Existing albums keep NULL release groups: none is guessed.
-- ---------------------------------------------------------------------------

SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'albums' AND COLUMN_NAME = 'needs_review') = 0,
    'ALTER TABLE `albums` ADD COLUMN `needs_review` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Set to 1 when scanner metadata needs manual verification'' AFTER `mbid`',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'albums' AND COLUMN_NAME = 'review_note') = 0,
    'ALTER TABLE `albums` ADD COLUMN `review_note` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Scanner note describing uncertain or unmatched metadata'' AFTER `needs_review`',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'albums' AND COLUMN_NAME = 'mb_release_group') = 0,
    'ALTER TABLE `albums` ADD COLUMN `mb_release_group` VARCHAR(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `mbid`',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'albums' AND INDEX_NAME = 'idx_album_needs_review') = 0,
    'ALTER TABLE `albums` ADD KEY `idx_album_needs_review` (`needs_review`)',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'albums' AND INDEX_NAME = 'idx_albums_mb_release_group') = 0,
    'ALTER TABLE `albums` ADD KEY `idx_albums_mb_release_group` (`mb_release_group`)',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;

-- ---------------------------------------------------------------------------
-- 3. 0.4.0 - Per-format provenance
--    New columns default to manual for existing rows.
-- ---------------------------------------------------------------------------

SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'album_formats' AND COLUMN_NAME = 'is_manual') = 0,
    'ALTER TABLE `album_formats` ADD COLUMN `is_manual` TINYINT(1) NOT NULL DEFAULT 1 AFTER `format_id`',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'album_formats' AND COLUMN_NAME = 'is_scanner') = 0,
    'ALTER TABLE `album_formats` ADD COLUMN `is_scanner` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_manual`',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;

-- ---------------------------------------------------------------------------
-- 4. 0.4.0 - External (index-in-place) audio
--    New columns default to 'managed' for existing uploads.
--    source_hash is deliberately non-unique.
-- ---------------------------------------------------------------------------

SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'audio_files' AND COLUMN_NAME = 'source_hash') = 0,
    'ALTER TABLE `audio_files` ADD COLUMN `source_hash` CHAR(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''SHA-1 fingerprint of scanner source content'' AFTER `filesize`',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'audio_files' AND COLUMN_NAME = 'source_path') = 0,
    'ALTER TABLE `audio_files` ADD COLUMN `source_path` VARCHAR(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''Absolute source path for external scanner media'' AFTER `source_hash`',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'audio_files' AND COLUMN_NAME = 'source_mtime') = 0,
    'ALTER TABLE `audio_files` ADD COLUMN `source_mtime` INT(10) UNSIGNED DEFAULT NULL AFTER `source_path`',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'audio_files' AND COLUMN_NAME = 'storage_type') = 0,
    'ALTER TABLE `audio_files` ADD COLUMN `storage_type` ENUM(''managed'',''external'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''managed'' AFTER `source_mtime`',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'audio_files' AND INDEX_NAME = 'idx_audio_source_path') = 0,
    'ALTER TABLE `audio_files` ADD KEY `idx_audio_source_path` (`source_path`(255))',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'audio_files' AND INDEX_NAME = 'idx_audio_source_hash') = 0,
    'ALTER TABLE `audio_files` ADD KEY `idx_audio_source_hash` (`source_hash`)',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;

-- ---------------------------------------------------------------------------
-- 5. 0.4.0 / 0.6.0 - Scanner tables
--    media_scan_ignored: folders of albums deleted from Grizzly (tombstones).
--    media_scan_excluded: folders the scanner must never enter.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `media_scan_ignored` (
  `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `path_hash`   CHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_path` VARCHAR(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_media_scan_ignored_hash` (`path_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `media_scan_excluded` (
  `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `path_hash`   CHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_path` VARCHAR(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_media_scan_excluded_hash` (`path_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 6. 0.4.0 - Artist metadata column defaults
--    Only the defaults for future rows change; existing values are kept.
--    Skipped when the defaults are already correct.
-- ---------------------------------------------------------------------------

SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'artists'
        AND (
              (COLUMN_NAME IN ('bio_status', 'disco_status')
                 AND IFNULL(TRIM(BOTH '''' FROM COLUMN_DEFAULT), '') <> 'none')
           OR (COLUMN_NAME = 'disco_fetch_version'
                 AND IFNULL(TRIM(BOTH '''' FROM COLUMN_DEFAULT), '') <> '5')
        )) > 0,
    'ALTER TABLE `artists`
       MODIFY `bio_status` VARCHAR(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''none''
         COMMENT ''ok | error | none — outcome of the last bio fetch attempt'',
       MODIFY `disco_status` VARCHAR(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''none''
         COMMENT ''ok | error | none — outcome of the last discography fetch attempt'',
       MODIFY `disco_fetch_version` SMALLINT UNSIGNED NOT NULL DEFAULT 5
         COMMENT ''Fetch-logic version at save time (ArtistMetadataService::DISCOGRAPHY_LOGIC_VERSION)''',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;

-- ---------------------------------------------------------------------------
-- 7. 0.5.0 - Biography cache marker
--    Pre-0.5.0 development builds stored biography cache versions above 2.
--    Runs only on a database that has not completed the 0.5.0 upgrade:
--    before 0.5.0 (image columns missing or incomplete) or after an
--    interrupted 0.5.0 upgrade (columns present, cache baseline missing).
--    It runs before the columns are added, so an interruption cannot skip it.
--    Biography texts, sources and dates are not touched.
-- ---------------------------------------------------------------------------

SET @grizzly_sql = IF(
    @grizzly_pre_050 = 0,
    'SELECT COUNT(*) INTO @grizzly_partial_050 FROM `artists`
      WHERE ((`image_local` IS NOT NULL AND `image_local` <> '''')
          OR (`image_url` IS NOT NULL AND `image_url` <> ''''))
        AND `image_fetched_at` IS NULL
        AND `image_fetch_version` = 0
        AND `image_status` = ''none''',
    'SELECT 0 INTO @grizzly_partial_050'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;

UPDATE `artists`
   SET `bio_fetch_version` = 2
 WHERE `bio_fetch_version` > 2
   AND (@grizzly_pre_050 = 1 OR @grizzly_partial_050 > 0);

-- ---------------------------------------------------------------------------
-- 8. 0.5.0 - Artist identity and image cache columns
--    deezer_artist_id stays NULL: no Deezer identity is guessed.
-- ---------------------------------------------------------------------------

SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'artists' AND COLUMN_NAME = 'deezer_artist_id') = 0,
    'ALTER TABLE `artists` ADD COLUMN `deezer_artist_id` VARCHAR(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `mb_artist_id`',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'artists' AND COLUMN_NAME = 'image_fetched_at') = 0,
    'ALTER TABLE `artists` ADD COLUMN `image_fetched_at` TIMESTAMP NULL DEFAULT NULL AFTER `image_source`',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'artists' AND COLUMN_NAME = 'image_status') = 0,
    'ALTER TABLE `artists` ADD COLUMN `image_status` VARCHAR(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''none'' COMMENT ''ok | error | none - outcome of the last artist-image fetch'' AFTER `image_fetched_at`',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'artists' AND COLUMN_NAME = 'image_fetch_version') = 0,
    'ALTER TABLE `artists` ADD COLUMN `image_fetch_version` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Artist image fetch logic version'' AFTER `image_status`',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;
SET @grizzly_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @grizzly_schema AND TABLE_NAME = 'artists' AND INDEX_NAME = 'uq_deezer_artist_id') = 0,
    'ALTER TABLE `artists` ADD UNIQUE KEY `uq_deezer_artist_id` (`deezer_artist_id`)',
    'DO 0'
);
PREPARE grizzly_stmt FROM @grizzly_sql; EXECUTE grizzly_stmt; DEALLOCATE PREPARE grizzly_stmt;

-- ---------------------------------------------------------------------------
-- 9. 0.5.0 - Image cache baseline for existing artist images
--    Existing images are marked as valid cache, so no mass re-download starts.
--    Only rows that never received the baseline are updated: an image
--    already fetched or refreshed by Grizzly is left as it is.
-- ---------------------------------------------------------------------------

UPDATE `artists`
   SET `image_fetched_at`    = COALESCE(`bio_fetched_at`, `created_at`, CURRENT_TIMESTAMP),
       `image_status`        = 'ok',
       `image_fetch_version` = 1
 WHERE ((`image_local` IS NOT NULL AND `image_local` <> '')
     OR (`image_url` IS NOT NULL AND `image_url` <> ''))
   AND `image_fetched_at` IS NULL
   AND `image_fetch_version` = 0
   AND `image_status` = 'none';

-- ---------------------------------------------------------------------------
-- 10. Settings
--    INSERT IGNORE: existing values are never overwritten.
--    media_scan_bound_root starts empty on purpose: the worker binds it only
--    after verifying that indexed external paths belong to the scanned root.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO `settings` (`key`, `value`, `label`) VALUES
('audio_path', '', 'Audio folder path (leave empty to use default)'),
('media_scan_enabled', '0', 'Enable automatic media folder scan'),
('media_scan_path', '', 'Folder to scan automatically'),
('media_scan_bound_root', '', 'Scanner root bound to indexed external audio paths'),
('media_scan_interval', '10', 'Scanner interval in seconds'),
('media_scan_stable_seconds', '30', 'Seconds a folder must remain unchanged before import');

-- ---------------------------------------------------------------------------
-- 11. Verification
-- ---------------------------------------------------------------------------

SELECT
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema AND (
            (TABLE_NAME = 'albums'        AND COLUMN_NAME IN ('needs_review', 'review_note', 'mb_release_group'))
         OR (TABLE_NAME = 'album_formats' AND COLUMN_NAME IN ('is_manual', 'is_scanner'))
         OR (TABLE_NAME = 'audio_files'   AND COLUMN_NAME IN ('source_hash', 'source_path', 'source_mtime', 'storage_type'))
         OR (TABLE_NAME = 'artists'       AND COLUMN_NAME IN ('deezer_artist_id', 'image_fetched_at', 'image_status', 'image_fetch_version'))
      )) AS columns_present_of_13,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME IN ('media_scan_ignored', 'media_scan_excluded')) AS scanner_tables_of_2,
    (SELECT COUNT(*) FROM `settings`
      WHERE `key` IN ('audio_path', 'media_scan_enabled', 'media_scan_path', 'media_scan_bound_root',
                      'media_scan_interval', 'media_scan_stable_seconds')) AS settings_present_of_6;

SELECT 'Grizzly Music Archive 0.6.0 migration completed.' AS result;

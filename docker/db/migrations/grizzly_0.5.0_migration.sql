-- Grizzly Music Archive 0.5.0
-- Upgrade migration: release-group identity / deterministic artist image identity
--
-- Compatible with MySQL 5.7+, MySQL 8.0+ and MariaDB 10.6+.
-- Designed to be safe to run more than once.
--
-- IMPORTANT:
-- - Run this only on an EXISTING Grizzly 0.4.0 database when upgrading to 0.5.0.
-- - New installations do not need this file: docker/db/01_schema.sql already
--   contains the current schema.
-- - This migration preserves existing albums, tracks, audio, settings, bios and images.
-- - It never guesses a Deezer artist ID and never replaces an existing artist image.

SET @grizzly_schema = DATABASE();

-- ---------------------------------------------------------------------------
-- 1. MusicBrainz release-group identity for albums
--    Existing albums are left NULL unless the application already populated
--    the value. No release-group is guessed from title/year alone.
-- ---------------------------------------------------------------------------

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'albums'
        AND COLUMN_NAME = 'mb_release_group') = 0,
    'ALTER TABLE `albums`
       ADD COLUMN `mb_release_group`
       VARCHAR(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL
       AFTER `mbid`',
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
        AND INDEX_NAME = 'idx_albums_mb_release_group') = 0,
    'ALTER TABLE `albums`
       ADD KEY `idx_albums_mb_release_group` (`mb_release_group`)',
    'SELECT 1'
);

PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

-- ---------------------------------------------------------------------------
-- 2. Persisted Deezer artist identity
--    NULL means unresolved/ambiguous. The migration never invents an ID.
-- ---------------------------------------------------------------------------

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'artists'
        AND COLUMN_NAME = 'deezer_artist_id') = 0,
    'ALTER TABLE `artists`
       ADD COLUMN `deezer_artist_id`
       VARCHAR(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL
       AFTER `mb_artist_id`',
    'SELECT 1'
);

PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

-- ---------------------------------------------------------------------------
-- 3. Independent artist-image cache metadata
-- ---------------------------------------------------------------------------

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'artists'
        AND COLUMN_NAME = 'image_fetched_at') = 0,
    'ALTER TABLE `artists`
       ADD COLUMN `image_fetched_at`
       TIMESTAMP NULL DEFAULT NULL
       AFTER `image_source`',
    'SELECT 1'
);

PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'artists'
        AND COLUMN_NAME = 'image_status') = 0,
    'ALTER TABLE `artists`
       ADD COLUMN `image_status`
       VARCHAR(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''none''
       COMMENT ''ok | error | none - outcome of the last artist-image fetch''
       AFTER `image_fetched_at`',
    'SELECT 1'
);

PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'artists'
        AND COLUMN_NAME = 'image_fetch_version') = 0,
    'ALTER TABLE `artists`
       ADD COLUMN `image_fetch_version`
       SMALLINT UNSIGNED NOT NULL DEFAULT 0
       COMMENT ''Artist image fetch logic version''
       AFTER `image_status`',
    'SELECT 1'
);

PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

-- ---------------------------------------------------------------------------
-- 4. Deezer artist identity must be unique when present.
--    MySQL permits multiple NULL values in a UNIQUE index.
-- ---------------------------------------------------------------------------

SET @grizzly_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @grizzly_schema
        AND TABLE_NAME = 'artists'
        AND INDEX_NAME = 'uq_deezer_artist_id') = 0,
    'ALTER TABLE `artists`
       ADD UNIQUE KEY `uq_deezer_artist_id` (`deezer_artist_id`)',
    'SELECT 1'
);

PREPARE grizzly_stmt FROM @grizzly_sql;
EXECUTE grizzly_stmt;
DEALLOCATE PREPARE grizzly_stmt;

-- ---------------------------------------------------------------------------
-- 5. Existing artist images become the 0.5.0 image-cache baseline.
--    Do not trigger a mass refetch after upgrade.
-- ---------------------------------------------------------------------------

UPDATE `artists`
SET
    `image_fetched_at` = COALESCE(
        `image_fetched_at`,
        `bio_fetched_at`,
        `created_at`,
        CURRENT_TIMESTAMP
    ),
    `image_status` = 'ok',
    `image_fetch_version` = 1
WHERE
    (`image_local` IS NOT NULL AND `image_local` <> '')
    OR (`image_url` IS NOT NULL AND `image_url` <> '');

UPDATE `artists`
SET
    `image_status` = 'none',
    `image_fetch_version` = 0
WHERE
    (`image_local` IS NULL OR `image_local` = '')
    AND (`image_url` IS NULL OR `image_url` = '');

-- ---------------------------------------------------------------------------
-- 6. Normalize only the discarded experimental bio cache marker.
--    BIO_LOGIC_VERSION for 0.5.0 is 2. Bio content/source/url/timestamps
--    are intentionally preserved.
-- ---------------------------------------------------------------------------

UPDATE `artists`
SET `bio_fetch_version` = 2
WHERE `bio_fetch_version` > 2;

-- ---------------------------------------------------------------------------
-- 7. Verification
-- ---------------------------------------------------------------------------

SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @grizzly_schema
  AND (
        (TABLE_NAME = 'albums' AND COLUMN_NAME = 'mb_release_group')
        OR
        (TABLE_NAME = 'artists' AND COLUMN_NAME IN (
            'deezer_artist_id',
            'image_fetched_at',
            'image_status',
            'image_fetch_version'
        ))
      )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @grizzly_schema
  AND (
        (TABLE_NAME = 'albums' AND INDEX_NAME = 'idx_albums_mb_release_group')
        OR
        (TABLE_NAME = 'artists' AND INDEX_NAME = 'uq_deezer_artist_id')
      )
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT
    COUNT(*) AS bio_versions_above_current
FROM `artists`
WHERE `bio_fetch_version` > 2;

SELECT
    COUNT(*) AS artists_with_existing_image_baseline
FROM `artists`
WHERE `image_status` = 'ok'
  AND `image_fetch_version` = 1;

-- ---------------------------------------------------------------------------
-- End of migration
-- ---------------------------------------------------------------------------

SELECT 'Grizzly Music Archive 0.5.0 migration completed.' AS result;

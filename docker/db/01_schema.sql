-- ─────────────────────────────────────────────────────────────────────────────
-- Grizzly Music Archive — Database Schema
-- MySQL 8.0+ / MariaDB 10.6+ / MySQL 5.7 compatible
--
-- Public clean schema only: no personal albums, no local paths, no private data.
-- Docker imports this file automatically on first database startup.
-- ─────────────────────────────────────────────────────────────────────────────

SET SQL_MODE  = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- Disable FK checks while recreating tables
SET FOREIGN_KEY_CHECKS = 0;

-- Drop children first, then parent tables
DROP TABLE IF EXISTS `playlist_tracks`;
DROP TABLE IF EXISTS `playlists`;
DROP TABLE IF EXISTS `audio_files`;
DROP TABLE IF EXISTS `tracks`;
DROP TABLE IF EXISTS `artist_discography`;
DROP TABLE IF EXISTS `album_formats`;
DROP TABLE IF EXISTS `albums`;
DROP TABLE IF EXISTS `artists`;
DROP TABLE IF EXISTS `formats`;
DROP TABLE IF EXISTS `genres`;
DROP TABLE IF EXISTS `labels`;
DROP TABLE IF EXISTS `media_scan_ignored`;
DROP TABLE IF EXISTS `settings`;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: artists
-- Local artists plus optional metadata cache from MusicBrainz/Wikidata/Wikipedia.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `artists` (
  `id`                INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`              VARCHAR(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`              VARCHAR(220) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mb_artist_id`      VARCHAR(36)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deezer_artist_id`  VARCHAR(32)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio`               TEXT         COLLATE utf8mb4_unicode_ci,
  `bio_source`        VARCHAR(20)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio_lang`          VARCHAR(5)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio_url`           VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio_fetched_at`    TIMESTAMP NULL DEFAULT NULL,
  `bio_status`        VARCHAR(10)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none'
                      COMMENT 'ok | error | none — outcome of the last bio fetch attempt',
  `bio_fetch_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1
                      COMMENT 'Fetch-logic version at save time (ArtistMetadataService::BIO_LOGIC_VERSION)',
  `disco_fetched_at`  TIMESTAMP NULL DEFAULT NULL,
  `disco_status`      VARCHAR(10)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none'
                      COMMENT 'ok | error | none — outcome of the last discography fetch attempt',
  `disco_fetch_version` SMALLINT UNSIGNED NOT NULL DEFAULT 5
                      COMMENT 'Fetch-logic version at save time (ArtistMetadataService::DISCOGRAPHY_LOGIC_VERSION)',
  `image_url`         VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_local`       VARCHAR(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_source`      VARCHAR(40)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_fetched_at`  TIMESTAMP NULL DEFAULT NULL,
  `image_status`      VARCHAR(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none'
                      COMMENT 'ok | error | none - outcome of the last artist-image fetch',
  `image_fetch_version` SMALLINT UNSIGNED NOT NULL DEFAULT 0
                      COMMENT 'Artist image fetch logic version',
  `country`           VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active_from`       YEAR(4) DEFAULT NULL,
  `active_to`         YEAR(4) DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  UNIQUE KEY `uq_mb_artist_id` (`mb_artist_id`),
  UNIQUE KEY `uq_deezer_artist_id` (`deezer_artist_id`),
  KEY `idx_artist_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: formats
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `formats` (
  `id`   TINYINT(3) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Required application formats. These are reference data, not demo content.
-- Keep stable IDs because albums.format_id and scanner format mapping use them.
INSERT INTO `formats` (`id`, `name`) VALUES
(1, 'Vinile'),
(2, 'CD'),
(3, 'Musicassetta'),
(4, 'Digital');

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: genres
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `genres` (
  `id`   SMALLINT(5) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: labels
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `labels` (
  `id`      SMALLINT(5) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`    VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `country` VARCHAR(60)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_label_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: albums
-- Main physical/digital album archive.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `albums` (
  `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `artist_id`   INT(10) UNSIGNED NOT NULL,
  `genre_id`    SMALLINT(5) UNSIGNED DEFAULT NULL,
  `label_id`    SMALLINT(5) UNSIGNED DEFAULT NULL,
  `format_id`   TINYINT(3) UNSIGNED NOT NULL,
  `title`       VARCHAR(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`        VARCHAR(280) COLLATE utf8mb4_unicode_ci NOT NULL,
  `year`        YEAR(4) DEFAULT NULL,
  `condition`   ENUM('Mint','Near Mint','Very Good Plus','Very Good','Good Plus','Good','Fair','Poor')
                COLLATE utf8mb4_unicode_ci DEFAULT 'Very Good',
  `copies`      TINYINT(3) UNSIGNED NOT NULL DEFAULT '1',
  `notes`       TEXT COLLATE utf8mb4_unicode_ci,
  `cover_url`   VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cover_local` VARCHAR(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mbid`        VARCHAR(36)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mb_release_group` VARCHAR(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `needs_review` TINYINT(1) NOT NULL DEFAULT 0
                 COMMENT 'Set to 1 when scanner metadata needs manual verification',
  `review_note` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                 COMMENT 'Scanner note describing uncertain or unmatched metadata',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_album_artist` (`artist_id`),
  KEY `idx_album_format` (`format_id`),
  KEY `idx_album_year` (`year`),
  KEY `idx_album_title` (`title`),
  KEY `idx_albums_mb_release_group` (`mb_release_group`),
  KEY `fk_album_genre` (`genre_id`),
  KEY `fk_album_label` (`label_id`),
  KEY `idx_album_needs_review` (`needs_review`),
  FULLTEXT KEY `ft_album_search` (`title`),
  CONSTRAINT `fk_album_artist` FOREIGN KEY (`artist_id`) REFERENCES `artists` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_album_format` FOREIGN KEY (`format_id`) REFERENCES `formats` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_album_genre`  FOREIGN KEY (`genre_id`)  REFERENCES `genres`  (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_album_label`  FOREIGN KEY (`label_id`)  REFERENCES `labels`  (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: album_formats
-- Bridge table: formats owned per album (one card, multiple formats).
-- Source of truth for formats; albums.format_id is kept in sync as the
-- primary format (lowest format id) for legacy/display purposes.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `album_formats` (
  `album_id`   INT(10)    UNSIGNED NOT NULL,
  `format_id`  TINYINT(3) UNSIGNED NOT NULL,
  `is_manual`  TINYINT(1) NOT NULL DEFAULT 1,
  `is_scanner` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`album_id`, `format_id`),
  KEY `idx_af_format` (`format_id`),
  CONSTRAINT `fk_af_album`
    FOREIGN KEY (`album_id`)  REFERENCES `albums` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_af_format`
    FOREIGN KEY (`format_id`) REFERENCES `formats` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: artist_discography
-- Cached official artist discography from MusicBrainz release-groups.
-- This is separate from local albums: it can contain albums not owned yet.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `artist_discography` (
  `id`                  INT(11) NOT NULL AUTO_INCREMENT,
  `artist_id`           INT(10) UNSIGNED NOT NULL,
  `mb_release_group_id` VARCHAR(36)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title`               VARCHAR(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `year`                SMALLINT(6) DEFAULT NULL,
  `cover_local`         VARCHAR(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_disco_rg` (`artist_id`, `mb_release_group_id`),
  KEY `idx_disco_artist` (`artist_id`),
  CONSTRAINT `fk_disco_artist` FOREIGN KEY (`artist_id`) REFERENCES `artists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: tracks
-- Album tracklist with optional cached YouTube video ID.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `tracks` (
  `id`           INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `album_id`     INT(10) UNSIGNED NOT NULL,
  `position`     TINYINT(3) UNSIGNED NOT NULL DEFAULT '1',
  `title`        VARCHAR(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration_sec` SMALLINT(5) UNSIGNED DEFAULT NULL,
  `youtube_id`   VARCHAR(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                 COMMENT 'Cached YouTube videoId — avoids repeated API calls',
  `youtube_status` VARCHAR(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                 COMMENT 'auto | confirmed | not_found | rejected',
  `youtube_checked_at` DATETIME DEFAULT NULL
                 COMMENT 'Last YouTube search timestamp (negative cache)',
  PRIMARY KEY (`id`),
  KEY `idx_track_album` (`album_id`),
  KEY `idx_tracks_youtube_id` (`youtube_id`),
  CONSTRAINT `fk_track_album` FOREIGN KEY (`album_id`) REFERENCES `albums` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: audio_files
-- Uploaded audio files linked to albums and optionally to single tracks.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `audio_files` (
  `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `album_id`      INT(10) UNSIGNED NOT NULL,
  `track_id`      INT(10) UNSIGNED DEFAULT NULL,
  `filename`      VARCHAR(300) COLLATE utf8mb4_unicode_ci NOT NULL
                  COMMENT 'Stored filename/path relative to the configured audio folder',
  `original_name` VARCHAR(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `filesize`      INT(10) UNSIGNED DEFAULT NULL,
  `source_hash`   CHAR(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                  COMMENT 'SHA-1 fingerprint of scanner source content',
  `source_path`   VARCHAR(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                  COMMENT 'Absolute source path for external scanner media',
  `source_mtime`  INT(10) UNSIGNED DEFAULT NULL,
  `storage_type`  ENUM('managed','external') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'managed',
  `uploaded_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audio_album` (`album_id`),
  KEY `idx_audio_track` (`track_id`),
  KEY `idx_audio_source_path` (`source_path`(255)),
  KEY `idx_audio_source_hash` (`source_hash`),
  CONSTRAINT `fk_audio_album` FOREIGN KEY (`album_id`) REFERENCES `albums` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_audio_track` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: playlists
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `playlists` (
  `id`         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: playlist_tracks
-- Many-to-many relation between playlists and tracks.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `playlist_tracks` (
  `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `playlist_id` INT(10) UNSIGNED NOT NULL,
  `track_id`    INT(10) UNSIGNED NOT NULL,
  `position`    SMALLINT(5) UNSIGNED NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_playlist_track` (`playlist_id`, `track_id`),
  KEY `idx_pt_playlist` (`playlist_id`),
  KEY `idx_pt_track` (`track_id`),
  CONSTRAINT `fk_pt_playlist` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pt_track`    FOREIGN KEY (`track_id`)    REFERENCES `tracks`    (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: media_scan_ignored
-- Tombstones for external album folders intentionally removed from Grizzly.
-- Prevents immediate re-import while the source folder still exists.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `media_scan_ignored` (
  `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `path_hash`   CHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_path` VARCHAR(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_media_scan_ignored_hash` (`path_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Table: settings
-- Application settings stored as key/value pairs.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE `settings` (
  `key`        VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value`      TEXT COLLATE utf8mb4_unicode_ci,
  `label`      VARCHAR(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL
               COMMENT 'Human-readable label for the UI',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default setting rows required by the application.
-- Scanner is intentionally disabled on first start and media_scan_path is blank.
-- The user chooses the real host path from Settings; in Docker the worker/app
-- resolve that path through MEDIA_SCAN_HOST_PREFIX without changing the DB value.
INSERT INTO `settings` (`key`, `value`, `label`) VALUES
('audio_path', '', 'Audio folder path (leave empty to use default)'),
('media_scan_enabled', '0', 'Enable automatic media folder scan'),
('media_scan_path', '', 'Folder to scan automatically'),
('media_scan_interval', '10', 'Scanner interval in seconds'),
('media_scan_stable_seconds', '30', 'Seconds a folder must remain unchanged before import');

SET FOREIGN_KEY_CHECKS = 1;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

<?php

/**
 * Canonical external-source ownership resolver.
 *
 * Active ownership exists only for storage_type='external'.
 * Historical source_path values on managed rows are provenance only.
 */
class ExternalSourceOwnershipService
{
    public const UNOWNED = 'UNOWNED';
    public const OWNED_EXACT = 'OWNED_EXACT';
    public const OWNED_PARTIAL = 'OWNED_PARTIAL';
    public const CONFLICT = 'CONFLICT';
    public const INVALID = 'INVALID';
    public const UNAVAILABLE = 'UNAVAILABLE';

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array<string,mixed> */
    public function inspectCandidate(string $importRoot, string $candidatePath): array
    {
        $importReal = realpath($importRoot);
        $candidateReal = realpath($candidatePath);

        if ($importReal === false || $candidateReal === false || !is_dir($candidateReal)) {
            return $this->result(self::UNAVAILABLE, null, 'path-unavailable');
        }

        $importReal = $this->normalizeAbsolutePath($importReal);
        $candidateReal = $this->normalizeAbsolutePath($candidateReal);

        if (!$this->within($candidateReal, $importReal)) {
            return $this->result(self::INVALID, null, 'candidate-outside-root');
        }

        $files = $this->candidateAudioFiles($candidateReal);
        if (empty($files)) {
            return $this->result(self::UNAVAILABLE, null, 'no-audio-after-discovery');
        }

        $lookup = $this->db->prepare("
            SELECT
                af.id,
                af.album_id,
                af.track_id,
                af.filesize,
                af.source_hash,
                af.source_mtime,
                t.album_id AS track_album_id
            FROM audio_files af
            LEFT JOIN tracks t ON t.id = af.track_id
            WHERE af.storage_type = 'external'
              AND af.source_path = :source_path
            ORDER BY af.id ASC
        ");

        $candidateDbPaths = [];
        $albumIds = [];
        $ownedCount = 0;
        $unownedCount = 0;
        $verifiedHashes = 0;
        $contentChanged = false;

        foreach ($files as $file) {
            if (!is_file($file) || !is_readable($file)) {
                return $this->result(self::UNAVAILABLE, null, 'audio-unreadable');
            }

            $dbPath = $this->dbPath($file);
            if ($dbPath === '') {
                return $this->result(self::INVALID, null, 'db-path-unavailable');
            }

            $candidateDbPaths[$dbPath] = true;
            $lookup->execute([':source_path' => $dbPath]);
            $rows = $lookup->fetchAll(PDO::FETCH_ASSOC);

            if (count($rows) === 0) {
                $unownedCount++;
                continue;
            }

            if (count($rows) > 1) {
                return $this->result(
                    self::CONFLICT,
                    null,
                    'source-path-multiple-owners',
                    [
                        'source_path' => $dbPath,
                        'row_count' => count($rows),
                        'candidate_album_ids' => $this->albumIdsFromRows($rows),
                    ]
                );
            }

            $row = $rows[0];
            $albumId = (int)($row['album_id'] ?? 0);
            $trackId = (int)($row['track_id'] ?? 0);
            $trackAlbumId = (int)($row['track_album_id'] ?? 0);

            if ($albumId <= 0 || $trackId <= 0 || $trackAlbumId <= 0) {
                return $this->result(
                    self::INVALID,
                    null,
                    'external-source-not-track-bound',
                    ['source_path' => $dbPath]
                );
            }

            if ($albumId !== $trackAlbumId) {
                return $this->result(
                    self::INVALID,
                    null,
                    'audio-track-album-mismatch',
                    [
                        'source_path' => $dbPath,
                        'audio_album_id' => $albumId,
                        'track_album_id' => $trackAlbumId,
                    ]
                );
            }

            $albumIds[$albumId] = true;
            if (count($albumIds) > 1) {
                return $this->result(
                    self::CONFLICT,
                    null,
                    'candidate-owned-by-multiple-albums',
                    ['candidate_album_ids' => array_map('intval', array_keys($albumIds))]
                );
            }

            $ownedCount++;

            $size = @filesize($file);
            $mtime = @filemtime($file);
            if ($size === false || $mtime === false) {
                return $this->result(
                    self::UNAVAILABLE,
                    $albumId,
                    'filesystem-fingerprint-unavailable',
                    ['source_path' => $dbPath]
                );
            }

            $dbSize = isset($row['filesize']) ? (int)$row['filesize'] : 0;
            $dbMtime = isset($row['source_mtime']) && $row['source_mtime'] !== null
                ? (int)$row['source_mtime']
                : 0;
            $dbHash = strtolower(trim((string)($row['source_hash'] ?? '')));

            if ($dbSize <= 0 || $dbMtime <= 0 || $dbHash === '') {
                return $this->result(
                    self::INVALID,
                    $albumId,
                    'external-fingerprint-incomplete',
                    ['source_path' => $dbPath]
                );
            }

            if ((int)$size !== $dbSize) {
                $contentChanged = true;
                continue;
            }

            if ((int)$mtime === $dbMtime) {
                continue;
            }

            $hash = @sha1_file($file);
            if ($hash === false) {
                return $this->result(
                    self::UNAVAILABLE,
                    $albumId,
                    'content-hash-unavailable',
                    ['source_path' => $dbPath]
                );
            }

            $hash = strtolower($hash);
            if (!hash_equals($dbHash, $hash)) {
                $contentChanged = true;
                continue;
            }

            $verifiedHashes++;
        }

        if ($ownedCount === 0) {
            return $this->result(
                self::UNOWNED,
                null,
                'no-active-external-owner',
                [
                    'audio_files' => count($candidateDbPaths),
                    'unowned_files' => $unownedCount,
                ]
            );
        }

        if (count($albumIds) !== 1) {
            return $this->result(
                self::CONFLICT,
                null,
                'owner-unresolved',
                ['candidate_album_ids' => array_map('intval', array_keys($albumIds))]
            );
        }

        $albumId = (int)array_key_first($albumIds);

        if ($unownedCount > 0) {
            return $this->result(
                self::OWNED_PARTIAL,
                $albumId,
                'mixed-owned-unowned-sources',
                [
                    'audio_files' => count($candidateDbPaths),
                    'owned_files' => $ownedCount,
                    'unowned_files' => $unownedCount,
                    'verified_hashes' => $verifiedHashes,
                ]
            );
        }

        if ($contentChanged) {
            return $this->result(
                self::OWNED_PARTIAL,
                $albumId,
                'owned-source-content-changed',
                [
                    'audio_files' => count($candidateDbPaths),
                    'verified_hashes' => $verifiedHashes,
                ]
            );
        }

        $candidateDbRoot = $this->dbPath($candidateReal);
        if ($candidateDbRoot === '') {
            return $this->result(self::INVALID, $albumId, 'candidate-db-path-unavailable');
        }

        $albumPaths = $this->db->prepare("
            SELECT source_path
            FROM audio_files
            WHERE album_id = :album_id
              AND storage_type = 'external'
              AND source_path IS NOT NULL
              AND source_path <> ''
        ");
        $albumPaths->execute([':album_id' => $albumId]);

        $dbUnderCandidate = [];
        foreach ($albumPaths->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $path = $this->normalizeAbsolutePath((string)$path);
            if ($path !== '' && $this->within($path, $candidateDbRoot)) {
                $dbUnderCandidate[$path] = true;
            }
        }

        if (count($dbUnderCandidate) !== count($candidateDbPaths)) {
            return $this->result(
                self::OWNED_PARTIAL,
                $albumId,
                'candidate-path-set-differs',
                [
                    'candidate_paths' => count($candidateDbPaths),
                    'database_paths' => count($dbUnderCandidate),
                ]
            );
        }

        foreach ($candidateDbPaths as $path => $_) {
            if (!isset($dbUnderCandidate[$path])) {
                return $this->result(
                    self::OWNED_PARTIAL,
                    $albumId,
                    'candidate-path-set-differs'
                );
            }
        }

        return $this->result(
            self::OWNED_EXACT,
            $albumId,
            'all-current-external-sources-owned-and-identical',
            [
                'audio_files' => count($candidateDbPaths),
                'verified_hashes' => $verifiedHashes,
            ]
        );
    }

    /** @param array<string,mixed> $extra
     *  @return array<string,mixed>
     */
    private function result(string $state, ?int $albumId, string $reason, array $extra = []): array
    {
        return array_merge([
            'state' => $state,
            'album_id' => $albumId,
            'reason' => $reason,
        ], $extra);
    }

    /** @param array<int,array<string,mixed>> $rows
     *  @return array<int,int>
     */
    private function albumIdsFromRows(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row['album_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        $out = array_map('intval', array_keys($ids));
        sort($out, SORT_NUMERIC);
        return $out;
    }

    /** @return array<int,string> */
    private function candidateAudioFiles(string $candidateRoot): array
    {
        $all = $this->listAudioDirect($candidateRoot);
        $entries = @scandir($candidateRoot);

        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || ($entry !== '' && $entry[0] === '.')) {
                    continue;
                }

                $child = $candidateRoot . '/' . $entry;
                if (!is_dir($child) || is_link($child) || $this->discNumber($entry) === null) {
                    continue;
                }

                $all = array_merge($all, $this->listAudioDirect($child));
            }
        }

        $selected = [];
        foreach ($all as $path) {
            $dir = str_replace('\\', '/', dirname($path));
            $stem = pathinfo(basename($path), PATHINFO_FILENAME);
            $stem = function_exists('mb_strtolower')
                ? mb_strtolower($stem, 'UTF-8')
                : strtolower($stem);
            $key = $dir . "\0" . $stem;
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $priority = $ext === 'flac' ? 20 : ($ext === 'mp3' ? 10 : 0);

            if (!isset($selected[$key]) || $priority > $selected[$key]['priority']) {
                $selected[$key] = ['path' => $path, 'priority' => $priority];
            }
        }

        $out = [];
        foreach ($selected as $row) {
            $out[] = $row['path'];
        }

        sort($out, SORT_STRING);
        return $out;
    }

    /** @return array<int,string> */
    private function listAudioDirect(string $dir): array
    {
        $out = [];
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return $out;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || ($entry !== '' && $entry[0] === '.')) {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if ($ext === 'mp3' || $ext === 'flac') {
                $out[] = $path;
            }
        }

        sort($out, SORT_STRING);
        return $out;
    }

    private function discNumber(string $name): ?int
    {
        if (preg_match('/^(cd|disc|disco|disk)\s*[-_. ]?\s*(\d{1,2})$/i', trim($name), $m)) {
            return (int)$m[2];
        }

        return null;
    }

    private function dbPath(string $runtimePath): string
    {
        $real = realpath($runtimePath);
        $path = $real !== false ? $real : $runtimePath;
        $path = str_replace('\\', '/', $path);

        $prefix = getenv('MEDIA_SCAN_HOST_PREFIX');
        $prefix = $prefix === false
            ? ''
            : rtrim(str_replace('\\', '/', trim($prefix)), '/');

        if ($prefix === '') {
            return $this->normalizeAbsolutePath($path);
        }

        if ($path === $prefix) {
            return '/';
        }

        if (strpos($path, $prefix . '/') === 0) {
            return $this->normalizeAbsolutePath(substr($path, strlen($prefix)));
        }

        return $this->normalizeAbsolutePath($path);
    }

    private function normalizeAbsolutePath(string $path): string
    {
        // Filesystem paths are byte-significant. Never trim whitespace here:
        // '/Album ' and '/Album' are different directories.
        $path = str_replace('\\', '/', $path);
        if ($path === '' || $path[0] !== '/') {
            return '';
        }

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function within(string $path, string $root): bool
    {
        $path = $this->normalizeAbsolutePath($path);
        $root = $this->normalizeAbsolutePath($root);

        if ($path === '' || $root === '') {
            return false;
        }

        if ($path === $root) {
            return true;
        }

        return strpos($path, rtrim($root, '/') . '/') === 0;
    }
}

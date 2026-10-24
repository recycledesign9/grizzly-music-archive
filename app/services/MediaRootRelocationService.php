<?php

/**
 * MediaRootRelocationService
 *
 * Gestisce esclusivamente il cambio della watched root dello scanner.
 * Non importa album e non modifica albums/tracks/artists/metadata.
 *
 * Un cambio root viene accettato soltanto quando ogni audio external salvato
 * sotto la vecchia root esiste allo stesso path relativo nella nuova root e
 * coincide per filesize e, quando disponibile, SHA-1 persistito.
 *
 * DB writes consentiti:
 *   - audio_files.source_path
 *   - media_scan_ignored.source_path + path_hash
 *   - settings.media_scan_bound_root
 *
 * Qualunque anomalia abortisce prima della transazione oppure produce ROLLBACK.
 */
class MediaRootRelocationService
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Restituisce la root durevole a cui i source_path external sono attualmente
     * vincolati. media_scan_path e' la scelta corrente dell'utente; questa setting
     * interna rappresenta invece lo stato del DB e non dipende dal file /tmp del
     * worker.
     *
     * Bootstrap upgrade-safe:
     *   - se non esistono external, la root corrente puo' essere adottata;
     *   - se tutti gli external sono gia' sotto media_scan_path, adotta quella;
     *   - altrimenti puo' usare la precedente root del vecchio worker state SOLO
     *     come prova iniziale, verificando che TUTTI gli external siano sotto di essa;
     *   - in ogni altro caso fallisce chiuso.
     */
    public function resolveBoundRoot(string $configuredRoot, string $legacyStateRoot = ''): string
    {
        $configuredRoot = $this->normalizeAbsolutePath($configuredRoot);
        $legacyStateRoot = $this->normalizeAbsolutePath($legacyStateRoot);

        if ($configuredRoot === '' || $configuredRoot === '/') {
            throw new RuntimeException('Configured watched root is invalid for durable binding.');
        }

        $stmt = $this->db->prepare("\n            SELECT `value`\n            FROM settings\n            WHERE `key` = 'media_scan_bound_root'\n            LIMIT 1\n        ");
        $stmt->execute();
        $stored = $stmt->fetchColumn();
        $storedRoot = $stored === false
            ? ''
            : $this->normalizeAbsolutePath((string)$stored);

        if ($storedRoot !== '' && $storedRoot !== '/') {
            return $storedRoot;
        }

        $paths = $this->db->query("\n            SELECT source_path\n            FROM audio_files\n            WHERE storage_type = 'external'\n              AND source_path IS NOT NULL\n              AND source_path <> ''\n            ORDER BY id ASC\n        ")->fetchAll(PDO::FETCH_COLUMN);

        if (empty($paths)) {
            $this->persistBoundRoot($configuredRoot);
            return $configuredRoot;
        }

        $candidates = [$configuredRoot];
        if ($legacyStateRoot !== ''
            && $legacyStateRoot !== '/'
            && $legacyStateRoot !== $configuredRoot) {
            $candidates[] = $legacyStateRoot;
        }

        foreach ($candidates as $candidateRoot) {
            $allInside = true;
            foreach ($paths as $rawPath) {
                $path = $this->normalizeAbsolutePath((string)$rawPath);
                if ($path === '' || !$this->within($path, $candidateRoot)) {
                    $allInside = false;
                    break;
                }
            }

            if ($allInside) {
                $this->persistBoundRoot($candidateRoot);
                return $candidateRoot;
            }
        }

        throw new RuntimeException(
            'Cannot determine durable scanner bound root from current DB paths. '
            . 'Refusing discovery until root binding is repaired.'
        );
    }

    /** @return array<string,mixed> */
    public function rebind(string $oldConfiguredRoot, string $newConfiguredRoot): array
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Root relocation requires no active transaction.');
        }

        $oldRoot = $this->normalizeAbsolutePath($oldConfiguredRoot);
        $newRoot = $this->normalizeAbsolutePath($newConfiguredRoot);

        if ($oldRoot === '' || $newRoot === '') {
            throw new RuntimeException('Watched roots must be absolute paths.');
        }
        if ($oldRoot === $newRoot) {
            return [
                'status' => 'unchanged',
                'audio_paths_rebound' => 0,
                'ignored_paths_rebound' => 0,
                'verified_files' => 0,
                'verified_hashes' => 0,
            ];
        }
        if ($oldRoot === '/' || $newRoot === '/') {
            throw new RuntimeException('Filesystem root / cannot be used for automatic relocation.');
        }

        $newRuntimeRoot = $this->runtimePath($newRoot);
        $newRuntimeReal = realpath($newRuntimeRoot);
        if ($newRuntimeReal === false || !is_dir($newRuntimeReal) || !is_readable($newRuntimeReal)) {
            throw new RuntimeException('New watched root is missing or unreadable: ' . $newRoot);
        }

        $rows = $this->db->query("
            SELECT id, source_path, source_hash, filesize
            FROM audio_files
            WHERE storage_type = 'external'
              AND source_path IS NOT NULL
              AND source_path <> ''
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $mapping = [];
        $targets = [];
        $verifiedFiles = 0;
        $verifiedHashes = 0;

        $collision = $this->db->prepare("
            SELECT id
            FROM audio_files
            WHERE source_path = :source_path
              AND id <> :id
            LIMIT 1
        ");

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $oldPath = $this->normalizeAbsolutePath((string)($row['source_path'] ?? ''));

            if ($id <= 0 || $oldPath === '') {
                throw new RuntimeException('Invalid external audio source row during relocation.');
            }
            if (!$this->within($oldPath, $oldRoot)) {
                throw new RuntimeException(
                    'External audio is outside durable bound root: id=' . $id
                    . ' path=' . $oldPath
                );
            }
            if ($oldPath === $oldRoot) {
                throw new RuntimeException('External audio points directly at watched root: id=' . $id);
            }

            $relative = substr($oldPath, strlen($oldRoot));
            if ($relative === '' || $relative[0] !== '/') {
                throw new RuntimeException('Cannot derive relative path for audio id=' . $id);
            }

            $newPath = $newRoot . $relative;
            if (isset($targets[$newPath]) && $targets[$newPath] !== $id) {
                throw new RuntimeException('Target path collision: ' . $newPath);
            }
            $targets[$newPath] = $id;

            $collision->execute([
                ':source_path' => $newPath,
                ':id' => $id,
            ]);
            $collisionId = $collision->fetchColumn();
            if ($collisionId !== false) {
                throw new RuntimeException(
                    'Target path already used by audio id=' . (int)$collisionId . ': ' . $newPath
                );
            }

            $runtime = $this->runtimePath($newPath);
            $real = realpath($runtime);
            if ($real === false || !is_file($real) || !is_readable($real)) {
                throw new RuntimeException('Target file missing: ' . $newPath);
            }

            $size = @filesize($real);
            if ($size === false) {
                throw new RuntimeException('Cannot read target filesize: ' . $newPath);
            }

            $expectedSize = (int)($row['filesize'] ?? 0);
            if ($expectedSize > 0 && (int)$size !== $expectedSize) {
                throw new RuntimeException(
                    'Filesize mismatch for audio id=' . $id
                    . ' expected=' . $expectedSize
                    . ' actual=' . (int)$size
                );
            }

            $expectedHash = strtolower(trim((string)($row['source_hash'] ?? '')));
            if ($expectedHash !== '') {
                $actualHash = @sha1_file($real);
                if ($actualHash === false || !hash_equals($expectedHash, strtolower($actualHash))) {
                    throw new RuntimeException('SHA-1 mismatch for audio id=' . $id);
                }
                $verifiedHashes++;
            }

            $mtime = @filemtime($real);
            $mapping[] = [
                'id' => $id,
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'runtime_path' => $real,
                'size' => (int)$size,
                'mtime' => $mtime === false ? null : (int)$mtime,
            ];
            $verifiedFiles++;
        }

        $ignoredMapping = [];
        $ignoredRows = $this->db->query("
            SELECT id, source_path
            FROM media_scan_ignored
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $ignoredCollision = $this->db->prepare("
            SELECT id
            FROM media_scan_ignored
            WHERE path_hash = :path_hash
              AND id <> :id
            LIMIT 1
        ");

        foreach ($ignoredRows as $row) {
            $id = (int)($row['id'] ?? 0);
            $oldPath = $this->normalizeAbsolutePath((string)($row['source_path'] ?? ''));
            if ($id <= 0 || $oldPath === '' || !$this->within($oldPath, $oldRoot)) {
                continue;
            }

            $relative = substr($oldPath, strlen($oldRoot));
            $newPath = $relative === '' ? $newRoot : $newRoot . $relative;
            $pathHash = sha1($newPath);

            $ignoredCollision->execute([
                ':path_hash' => $pathHash,
                ':id' => $id,
            ]);
            if ($ignoredCollision->fetchColumn() !== false) {
                throw new RuntimeException('Ignored-path collision: ' . $newPath);
            }

            $ignoredMapping[] = [
                'id' => $id,
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'path_hash' => $pathHash,
            ];
        }

        // TOCTOU guard immediately before mutation.
        foreach ($mapping as $item) {
            if (!is_file($item['runtime_path']) || !is_readable($item['runtime_path'])) {
                throw new RuntimeException('Target disappeared: ' . $item['new_path']);
            }
            $sizeNow = @filesize($item['runtime_path']);
            $mtimeNow = @filemtime($item['runtime_path']);
            if ($sizeNow === false || (int)$sizeNow !== (int)$item['size']) {
                throw new RuntimeException('Target changed during verification: ' . $item['new_path']);
            }
            if ($item['mtime'] !== null && $mtimeNow !== false && (int)$mtimeNow !== (int)$item['mtime']) {
                throw new RuntimeException('Target mtime changed during verification: ' . $item['new_path']);
            }
        }

        $this->db->beginTransaction();
        try {
            $updAudio = $this->db->prepare("
                UPDATE audio_files
                SET source_path = :new_path
                WHERE id = :id
                  AND storage_type = 'external'
                  AND source_path = :old_path
            ");

            foreach ($mapping as $item) {
                $updAudio->execute([
                    ':new_path' => $item['new_path'],
                    ':id' => $item['id'],
                    ':old_path' => $item['old_path'],
                ]);
                if ($updAudio->rowCount() !== 1) {
                    throw new RuntimeException('DB guard lost for audio id=' . $item['id']);
                }
            }

            $updIgnored = $this->db->prepare("
                UPDATE media_scan_ignored
                SET source_path = :new_path,
                    path_hash = :path_hash
                WHERE id = :id
                  AND source_path = :old_path
            ");

            foreach ($ignoredMapping as $item) {
                $updIgnored->execute([
                    ':new_path' => $item['new_path'],
                    ':path_hash' => $item['path_hash'],
                    ':id' => $item['id'],
                    ':old_path' => $item['old_path'],
                ]);
                if ($updIgnored->rowCount() !== 1) {
                    throw new RuntimeException('DB ignored-path guard lost for id=' . $item['id']);
                }
            }

            // Il binding durevole cambia nella STESSA transazione dei source_path.
            // Se il processo cade dopo COMMIT ma prima del salvataggio dello state
            // /tmp, il run successivo sa comunque che il DB e' gia' sulla nuova root.
            $this->persistBoundRoot($newRoot);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'status' => empty($mapping) ? 'no-external-sources' : 'rebound',
            'audio_paths_rebound' => count($mapping),
            'ignored_paths_rebound' => count($ignoredMapping),
            'verified_files' => $verifiedFiles,
            'verified_hashes' => $verifiedHashes,
        ];
    }

    /**
     * DB-backed idempotency recovery for a candidate whose worker state is
     * missing/stale. Read-only: never changes the archive.
     *
     * fully_indexed=true only when every scanner-selected MP3/FLAC is already
     * an external source_path for exactly one album/track and its content still
     * matches filesize+mtime or the persisted SHA-1 fallback. The set of paths
     * under this candidate must also match the DB set exactly.
     *
     * @return array<string,mixed>
     */
    public function inspectIndexedCandidate(string $importRoot, string $candidatePath): array
    {
        $importReal = realpath($importRoot);
        $candidateReal = realpath($candidatePath);
        if ($importReal === false || $candidateReal === false || !is_dir($candidateReal)) {
            return ['fully_indexed' => false, 'reason' => 'path-unavailable'];
        }

        $importReal = rtrim(str_replace('\\', '/', $importReal), '/');
        $candidateReal = rtrim(str_replace('\\', '/', $candidateReal), '/');
        if (!$this->within($candidateReal, $importReal)) {
            return ['fully_indexed' => false, 'reason' => 'candidate-outside-root'];
        }

        $files = $this->candidateAudioFiles($candidateReal);
        if (empty($files)) {
            return ['fully_indexed' => false, 'reason' => 'no-audio'];
        }

        $lookup = $this->db->prepare("
            SELECT id, album_id, track_id, filesize, source_hash, source_mtime
            FROM audio_files
            WHERE storage_type = 'external'
              AND source_path = :source_path
            ORDER BY id ASC
        ");

        $candidateDbPaths = [];
        $albumIds = [];
        $verifiedHashes = 0;

        foreach ($files as $file) {
            if (!is_file($file) || !is_readable($file)) {
                return ['fully_indexed' => false, 'reason' => 'audio-unreadable'];
            }

            $dbPath = $this->dbPath($file);
            if ($dbPath === '') {
                return ['fully_indexed' => false, 'reason' => 'db-path-unavailable'];
            }
            $candidateDbPaths[$dbPath] = true;

            $lookup->execute([':source_path' => $dbPath]);
            $rows = $lookup->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                return ['fully_indexed' => false, 'reason' => 'source-path-not-unique'];
            }

            $row = $rows[0];
            $albumId = (int)($row['album_id'] ?? 0);
            $trackId = (int)($row['track_id'] ?? 0);
            if ($albumId <= 0 || $trackId <= 0) {
                return ['fully_indexed' => false, 'reason' => 'source-not-track-bound'];
            }
            $albumIds[$albumId] = true;
            if (count($albumIds) > 1) {
                return ['fully_indexed' => false, 'reason' => 'multiple-albums'];
            }

            $size = @filesize($file);
            $mtime = @filemtime($file);
            if ($size === false) {
                return ['fully_indexed' => false, 'reason' => 'filesize-unavailable'];
            }

            $dbSize = (int)($row['filesize'] ?? 0);
            if ($dbSize > 0 && (int)$size !== $dbSize) {
                return ['fully_indexed' => false, 'reason' => 'filesize-changed'];
            }

            $dbMtime = isset($row['source_mtime']) && $row['source_mtime'] !== null
                ? (int)$row['source_mtime']
                : null;
            $fastSame = $dbSize > 0
                && $dbMtime !== null
                && $mtime !== false
                && (int)$size === $dbSize
                && (int)$mtime === $dbMtime;

            if (!$fastSame) {
                $dbHash = strtolower(trim((string)($row['source_hash'] ?? '')));
                if ($dbHash === '') {
                    return ['fully_indexed' => false, 'reason' => 'fingerprint-unavailable'];
                }
                $hash = @sha1_file($file);
                if ($hash === false || !hash_equals($dbHash, strtolower($hash))) {
                    return ['fully_indexed' => false, 'reason' => 'content-changed'];
                }
                $verifiedHashes++;
            }
        }

        if (count($albumIds) !== 1) {
            return ['fully_indexed' => false, 'reason' => 'album-unresolved'];
        }

        $albumId = (int)array_key_first($albumIds);
        $candidateDbRoot = $this->dbPath($candidateReal);
        if ($candidateDbRoot === '') {
            return ['fully_indexed' => false, 'reason' => 'candidate-db-path-unavailable'];
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
            return ['fully_indexed' => false, 'reason' => 'candidate-path-set-differs'];
        }
        foreach ($candidateDbPaths as $path => $_) {
            if (!isset($dbUnderCandidate[$path])) {
                return ['fully_indexed' => false, 'reason' => 'candidate-path-set-differs'];
            }
        }

        return [
            'fully_indexed' => true,
            'reason' => 'all-current-source-paths-indexed',
            'album_id' => $albumId,
            'audio_files' => count($candidateDbPaths),
            'verified_hashes' => $verifiedHashes,
        ];
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

        // Same scanner rule: same stem in same directory -> FLAC wins over MP3.
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
        $prefix = $prefix === false ? '' : rtrim(str_replace('\\', '/', trim($prefix)), '/');
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

    private function persistBoundRoot(string $root): void
    {
        $root = $this->normalizeAbsolutePath($root);
        if ($root === '' || $root === '/') {
            throw new RuntimeException('Cannot persist invalid scanner bound root.');
        }

        $stmt = $this->db->prepare("\n            INSERT INTO settings (`key`, `value`)\n            VALUES ('media_scan_bound_root', :value)\n            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)\n        ");
        $stmt->execute([':value' => $root]);
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
        return $path === $root || strpos($path . '/', $root . '/') === 0;
    }

    private function runtimePath(string $dbPath): string
    {
        $dbPath = $this->normalizeAbsolutePath($dbPath);
        if ($dbPath === '') {
            return '';
        }

        $prefix = getenv('MEDIA_SCAN_HOST_PREFIX');
        $prefix = $prefix === false ? '' : rtrim(str_replace('\\', '/', trim($prefix)), '/');
        if ($prefix === '') {
            return $dbPath;
        }
        if ($dbPath === '/') {
            return $prefix;
        }
        return $prefix . $dbPath;
    }
}

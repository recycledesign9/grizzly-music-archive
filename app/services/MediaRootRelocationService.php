<?php

/**
 * MediaRootRelocationService
 *
 * Gestisce esclusivamente il cambio della watched root dello scanner.
 * Non importa album e non modifica albums/tracks/artists/metadata.
 *
 * Un cambio root non richiede una copia 1:1 della vecchia libreria.
 * I source_path vengono riallineati automaticamente soltanto quando esiste una
 * prova conservativa (stesso path relativo verificato oppure SHA-1 univoco).
 * I riferimenti non risolti restano intatti come storico e saranno riconciliati
 * dalla normale pipeline scanner dopo la riscansione della nuova root.
 *
 * DB writes consentiti:
 *   - audio_files.source_path/source_mtime/filesize per mapping certi
 *   - media_scan_ignored.source_path + path_hash per mapping certi
 *   - settings.media_scan_bound_root
 *
 * Nessun file fisico viene copiato, spostato o cancellato.
 * Qualunque errore di scrittura produce ROLLBACK.
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

    /**
     * Cambia la watched root senza richiedere che la nuova libreria sia una
     * copia 1:1 della precedente.
     *
     * Strategia:
     *   1. stesso path relativo = fast-path, ma solo con contenuto verificato;
     *   2. fallback SHA-1 soltanto quando il fingerprint e' univoco sia nel DB
     *      storico sia nella nuova root;
     *   3. sorgenti non riallineate NON vengono cancellate o svuotate: restano
     *      nel DB come storico e, dopo il cambio di bound root, risultano
     *      logicamente inattive finche' il normale scanner non le riconcilia;
     *   4. i file nuovi non vengono toccati qui: saranno elaborati dalla normale
     *      pipeline Readiness -> Identity -> CREATE/RECONCILE.
     *
     * In dry-run non viene eseguita alcuna scrittura DB.
     *
     * @return array<string,mixed>
     */
    public function rebind(
        string $oldConfiguredRoot,
        string $newConfiguredRoot,
        bool $dryRun = false,
        ?callable $progress = null
    ): array {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Root relocation requires no active transaction.');
        }

        $oldRoot = $this->normalizeAbsolutePath($oldConfiguredRoot);
        $newRoot = $this->normalizeAbsolutePath($newConfiguredRoot);

        if ($oldRoot === '' || $newRoot === '') {
            throw new RuntimeException('Watched roots must be absolute paths.');
        }
        if ($oldRoot === '/' || $newRoot === '/') {
            throw new RuntimeException('Filesystem root / cannot be used for automatic relocation.');
        }

        if ($oldRoot === $newRoot) {
            return [
                'status' => $dryRun ? 'preview-unchanged' : 'unchanged',
                'audio_paths_rebound' => 0,
                'audio_paths_relative' => 0,
                'audio_paths_hash' => 0,
                'audio_paths_unresolved' => 0,
                'audio_paths_outside_old_root' => 0,
                'audio_hash_ambiguous' => 0,
                'ignored_paths_rebound' => 0,
                'ignored_paths_unresolved' => 0,
                'verified_files' => 0,
                'verified_hashes' => 0,
                'scanned_files' => 0,
            ];
        }

        $newRuntimeRoot = $this->runtimePath($newRoot);
        $newRuntimeReal = realpath($newRuntimeRoot);
        if (
            $newRuntimeReal === false
            || !is_dir($newRuntimeReal)
            || !is_readable($newRuntimeReal)
        ) {
            throw new RuntimeException('New watched root is missing or unreadable: ' . $newRoot);
        }
        $newRuntimeReal = rtrim(str_replace('\\', '/', $newRuntimeReal), '/');

        $this->reportProgress($progress, 'db-load-start');

        $rows = $this->db->query("
            SELECT id, source_path, source_hash, filesize
            FROM audio_files
            WHERE storage_type = 'external'
              AND source_path IS NOT NULL
              AND source_path <> ''
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $this->reportProgress($progress, 'db-load-done', ['audio_rows' => count($rows)]);

        $activeRows = [];
        $outsideOldRoot = 0;

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $oldPath = $this->normalizeAbsolutePath((string)($row['source_path'] ?? ''));

            if ($id <= 0 || $oldPath === '') {
                // Dato storico incoerente: non deve impedire di adottare una nuova root.
                $outsideOldRoot++;
                continue;
            }

            if (!$this->within($oldPath, $oldRoot) || $oldPath === $oldRoot) {
                // Con il nuovo modello possono esistere riferimenti storici fuori
                // dalla root attualmente vincolata. Restano intatti.
                $outsideOldRoot++;
                continue;
            }

            $row['id'] = $id;
            $row['old_path'] = $oldPath;
            $row['source_hash'] = strtolower(trim((string)($row['source_hash'] ?? '')));
            $row['filesize'] = (int)($row['filesize'] ?? 0);
            $activeRows[] = $row;
        }

        $mapping = [];
        $mappedIds = [];
        $usedTargets = [];
        $relativeMatches = 0;
        $hashMatches = 0;
        $hashAmbiguous = 0;
        $verifiedFiles = 0;
        $verifiedHashes = 0;

        $collision = $this->db->prepare("
            SELECT id
            FROM audio_files
            WHERE source_path = :source_path
              AND id <> :id
            LIMIT 1
        ");

        $this->reportProgress($progress, 'relative-start', ['rows' => count($activeRows)]);

        // --------------------------------------------------------------
        // Fast-path: stessa posizione relativa, ma mai fidarsi del path
        // da solo. Verifica dimensione e, quando disponibile, SHA-1.
        // --------------------------------------------------------------
        $relativeChecked = 0;
        foreach ($activeRows as $row) {
            $relativeChecked++;
            if ($relativeChecked === 1 || $relativeChecked % 50 === 0 || $relativeChecked === count($activeRows)) {
                $this->reportProgress($progress, 'relative-progress', [
                    'checked' => $relativeChecked,
                    'total' => count($activeRows),
                    'matched' => $relativeMatches,
                ]);
            }

            $id = (int)$row['id'];
            $oldPath = (string)$row['old_path'];
            $relative = substr($oldPath, strlen($oldRoot));

            if ($relative === '' || $relative[0] !== '/') {
                continue;
            }

            $newPath = $newRoot . $relative;
            $runtime = $this->runtimePath($newPath);
            $real = realpath($runtime);

            if ($real === false || !is_file($real) || !is_readable($real)) {
                continue;
            }

            $size = @filesize($real);
            if ($size === false) {
                continue;
            }
            $size = (int)$size;

            $expectedSize = (int)$row['filesize'];
            if ($expectedSize > 0 && $size !== $expectedSize) {
                continue;
            }

            $expectedHash = (string)$row['source_hash'];
            if ($expectedHash !== '') {
                $actualHash = @sha1_file($real);
                if (
                    $actualHash === false
                    || !hash_equals($expectedHash, strtolower((string)$actualHash))
                ) {
                    continue;
                }
                $verifiedHashes++;
            } elseif ($expectedSize <= 0) {
                // Senza hash e senza size non c'e' prova sufficiente, anche se
                // il path relativo e' identico.
                continue;
            }

            $collision->execute([
                ':source_path' => $newPath,
                ':id' => $id,
            ]);
            if ($collision->fetchColumn() !== false) {
                continue;
            }

            $mtime = @filemtime($real);
            $real = str_replace('\\', '/', $real);

            $mapping[$id] = [
                'id' => $id,
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'runtime_path' => $real,
                'size' => $size,
                'mtime' => $mtime === false ? null : (int)$mtime,
                'method' => 'relative',
            ];
            $mappedIds[$id] = true;
            $usedTargets[$newPath] = $id;
            $relativeMatches++;
            $verifiedFiles++;
        }

        // --------------------------------------------------------------
        // Fallback conservativo per path arbitrariamente cambiati.
        // SHA-1 e' prova di relocation, NON chiave globale di deduplica:
        // auto-map solo se il fingerprint e' univoco tra i vecchi record
        // ancora da risolvere e compare una sola volta nella nuova root.
        // --------------------------------------------------------------
        $oldByHash = [];
        $neededSizes = [];
        $hashAnySize = false;

        foreach ($activeRows as $row) {
            $id = (int)$row['id'];
            if (isset($mappedIds[$id])) {
                continue;
            }

            $hash = (string)$row['source_hash'];
            if ($hash === '') {
                continue;
            }

            if (!isset($oldByHash[$hash])) {
                $oldByHash[$hash] = [];
            }
            $oldByHash[$hash][] = $row;

            $size = (int)$row['filesize'];
            if ($size > 0) {
                $neededSizes[$size] = true;
            } else {
                $hashAnySize = true;
            }
        }

        $eligibleHashes = [];
        foreach ($oldByHash as $hash => $hashRows) {
            if (count($hashRows) === 1) {
                $eligibleHashes[$hash] = true;
            } else {
                $hashAmbiguous += count($hashRows);
            }
        }

        $newByHash = [];
        $scannedFiles = 0;

        if (!empty($eligibleHashes)) {
            $this->reportProgress($progress, 'discovery-start', [
                'eligible_hashes' => count($eligibleHashes),
            ]);
            $allNewAudio = $this->listAllAudioRecursive($newRuntimeReal, $progress);
            $this->reportProgress($progress, 'discovery-done', [
                'audio_files' => count($allNewAudio),
            ]);

            foreach ($allNewAudio as $runtimeFile) {
                $scannedFiles++;
                if ($scannedFiles === 1 || $scannedFiles % 25 === 0 || $scannedFiles === count($allNewAudio)) {
                    $this->reportProgress($progress, 'hash-progress', [
                        'scanned' => $scannedFiles,
                        'total' => count($allNewAudio),
                        'matched' => $hashMatches,
                    ]);
                }

                $real = realpath($runtimeFile);
                if ($real === false || !is_file($real) || !is_readable($real)) {
                    continue;
                }
                $real = str_replace('\\', '/', $real);

                $size = @filesize($real);
                if ($size === false) {
                    continue;
                }
                $size = (int)$size;

                if (!$hashAnySize && !isset($neededSizes[$size])) {
                    continue;
                }

                $dbPath = $this->dbPath($real);
                if ($dbPath === '' || isset($usedTargets[$dbPath])) {
                    continue;
                }

                $hash = @sha1_file($real);
                if ($hash === false || $hash === '') {
                    continue;
                }
                $hash = strtolower((string)$hash);

                if (!isset($eligibleHashes[$hash])) {
                    continue;
                }

                if (!isset($newByHash[$hash])) {
                    $newByHash[$hash] = [];
                }

                $mtime = @filemtime($real);
                $newByHash[$hash][] = [
                    'db_path' => $dbPath,
                    'runtime_path' => $real,
                    'size' => $size,
                    'mtime' => $mtime === false ? null : (int)$mtime,
                ];
            }

            foreach ($eligibleHashes as $hash => $_) {
                $oldRows = $oldByHash[$hash] ?? [];
                $candidates = $newByHash[$hash] ?? [];

                if (count($oldRows) !== 1) {
                    continue;
                }

                if (count($candidates) !== 1) {
                    if (count($candidates) > 1) {
                        $hashAmbiguous++;
                    }
                    continue;
                }

                $row = $oldRows[0];
                $candidate = $candidates[0];

                $id = (int)$row['id'];
                $newPath = (string)$candidate['db_path'];

                if (isset($mappedIds[$id]) || isset($usedTargets[$newPath])) {
                    continue;
                }

                $expectedSize = (int)$row['filesize'];
                if ($expectedSize > 0 && (int)$candidate['size'] !== $expectedSize) {
                    continue;
                }

                $collision->execute([
                    ':source_path' => $newPath,
                    ':id' => $id,
                ]);
                if ($collision->fetchColumn() !== false) {
                    continue;
                }

                $mapping[$id] = [
                    'id' => $id,
                    'old_path' => (string)$row['old_path'],
                    'new_path' => $newPath,
                    'runtime_path' => (string)$candidate['runtime_path'],
                    'size' => (int)$candidate['size'],
                    'mtime' => $candidate['mtime'],
                    'method' => 'hash',
                ];
                $mappedIds[$id] = true;
                $usedTargets[$newPath] = $id;
                $hashMatches++;
                $verifiedFiles++;
                $verifiedHashes++;
            }
        }

        $unresolved = max(0, count($activeRows) - count($mapping));

        // --------------------------------------------------------------
        // Ignored album paths: mantieni il tombstone solo quando la stessa
        // directory relativa esiste davvero nella nuova root. In caso contrario
        // il vecchio tombstone resta storico e non blocca nuovi percorsi.
        // --------------------------------------------------------------
        $ignoredMapping = [];
        $ignoredUnresolved = 0;
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
            if ($relative === '' || $relative[0] !== '/') {
                $ignoredUnresolved++;
                continue;
            }

            $newPath = $newRoot . $relative;
            $runtime = $this->runtimePath($newPath);
            $real = realpath($runtime);

            if ($real === false || !is_dir($real) || !is_readable($real)) {
                $ignoredUnresolved++;
                continue;
            }

            $pathHash = sha1($newPath);
            $ignoredCollision->execute([
                ':path_hash' => $pathHash,
                ':id' => $id,
            ]);
            if ($ignoredCollision->fetchColumn() !== false) {
                $ignoredUnresolved++;
                continue;
            }

            $ignoredMapping[] = [
                'id' => $id,
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'path_hash' => $pathHash,
            ];
        }

        // TOCTOU guard sui soli target che stiamo per committare.
        foreach ($mapping as $item) {
            $runtimePath = (string)$item['runtime_path'];
            if (!is_file($runtimePath) || !is_readable($runtimePath)) {
                throw new RuntimeException('Target disappeared: ' . $item['new_path']);
            }

            $sizeNow = @filesize($runtimePath);
            $mtimeNow = @filemtime($runtimePath);

            if ($sizeNow === false || (int)$sizeNow !== (int)$item['size']) {
                throw new RuntimeException('Target changed during verification: ' . $item['new_path']);
            }

            if (
                $item['mtime'] !== null
                && $mtimeNow !== false
                && (int)$mtimeNow !== (int)$item['mtime']
            ) {
                throw new RuntimeException('Target mtime changed during verification: ' . $item['new_path']);
            }
        }

        $result = [
            'status' => $dryRun ? 'preview-rescan' : 'rebound-rescan',
            'audio_paths_rebound' => count($mapping),
            'audio_paths_relative' => $relativeMatches,
            'audio_paths_hash' => $hashMatches,
            'audio_paths_unresolved' => $unresolved,
            'audio_paths_outside_old_root' => $outsideOldRoot,
            'audio_hash_ambiguous' => $hashAmbiguous,
            'ignored_paths_rebound' => count($ignoredMapping),
            'ignored_paths_unresolved' => $ignoredUnresolved,
            'verified_files' => $verifiedFiles,
            'verified_hashes' => $verifiedHashes,
            'scanned_files' => $scannedFiles,
        ];

        $this->reportProgress($progress, 'plan-done', $result);

        if ($dryRun) {
            return $result;
        }

        $this->db->beginTransaction();
        try {
            $updAudio = $this->db->prepare("
                UPDATE audio_files
                SET source_path = :new_path,
                    source_mtime = :source_mtime,
                    filesize = :filesize
                WHERE id = :id
                  AND storage_type = 'external'
                  AND source_path = :old_path
            ");

            foreach ($mapping as $item) {
                $updAudio->execute([
                    ':new_path' => $item['new_path'],
                    ':source_mtime' => $item['mtime'],
                    ':filesize' => $item['size'],
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

            // Il binding cambia atomicamente con i soli mapping certi.
            // Le sorgenti non risolte restano intatte nel DB, ma sono fuori dal
            // nuovo bound root e quindi non vengono piu' considerate sorgenti vive.
            $this->persistBoundRoot($newRoot);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    /**
     * Elenca ricorsivamente tutti gli MP3/FLAC leggibili sotto la nuova root.
     * Non segue symlink: una relocation non deve uscire dalla watched root.
     *
     * @return array<int,string>
     */
    private function listAllAudioRecursive(string $root, ?callable $progress = null): array
    {
        $out = [];
        $stack = [$root];

        try {
            while (!empty($stack)) {
                $dir = array_pop($stack);
                $entries = @scandir($dir);
                if (!is_array($entries)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    if (
                        $entry === '.'
                        || $entry === '..'
                        || ($entry !== '' && $entry[0] === '.')
                    ) {
                        continue;
                    }

                    $path = rtrim($dir, '/') . '/' . $entry;

                    if (is_link($path)) {
                        continue;
                    }

                    if (is_dir($path)) {
                        if (is_readable($path)) {
                            $stack[] = $path;
                        }
                        continue;
                    }

                    if (!is_file($path) || !is_readable($path)) {
                        continue;
                    }

                    $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                    if ($ext === 'mp3' || $ext === 'flac') {
                        $out[] = str_replace('\\', '/', $path);
                        $found = count($out);
                        if ($found === 1 || $found % 250 === 0) {
                            $this->reportProgress($progress, 'discovery-progress', ['found' => $found]);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Cannot scan new watched root: ' . $e->getMessage(),
                0,
                $e
            );
        }

        sort($out, SORT_STRING);
        return $out;
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

    /** @param array<string,mixed> $data */
    private function reportProgress(?callable $progress, string $phase, array $data = []): void
    {
        if ($progress === null) {
            return;
        }

        $progress($phase, $data);
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

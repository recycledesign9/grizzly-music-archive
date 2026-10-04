<?php

/**
 * MediaController
 *
 * Streama file audio con supporto HTTP Range (seek nel player).
 * Funziona sia con file nella webroot che su path esterni.
 *
 * Routes:
 *   GET  ?route=media/audio/{filename}    → stream (inline)
 *   GET  ?route=media/download/{filename} → download (attachment)
 *   GET  ?route=media/test-path           → AJAX: verifica path configurato
 *   POST ?route=media/set-path            → AJAX: salva nuovo path
 *   POST ?route=media/migrate             → AJAX: copia file sul nuovo path
 *
 * Posizionare in: app/controllers/MediaController.php
 */
class MediaController
{
    public function dispatch(string $action, ?int $id): void
    {
        // Per le route audio/download l'$id non è usato — il filename
        // viene dall'$action stessa dopo lo split in index.php
        switch ($action) {
            case 'audio':
                $this->streamFile($this->filenameFromRequest(), false);
                break;
            case 'download':
                $this->streamFile($this->filenameFromRequest(), true);
                break;
            case 'test-path':
                $this->testPath();
                break;
            case 'test-path-value':
                $this->testPathValue();
                break;
            case 'set-path':
                $this->setPath();
                break;
            case 'migrate':
                $this->migrate();
                break;
            case 'migrate-chunk':
                $this->migrateChunk();
                break;
            case 'migrate-count':
                $this->migrateCount();
                break;
            case 'browse-dir':
                $this->browseDir();
                break;
            default:
                http_response_code(404);
                exit;
        }
    }

    // ----------------------------------------------------------
    // Stream audio con Range support
    // ----------------------------------------------------------
    private function streamFile(string $filename, bool $download): void
    {
        // Rilascia subito la sessione — lo streaming può durare minuti
        // e la sessione bloccata impedirebbe qualsiasi altra request PHP.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // La route pubblica continua ad accettare SOLO un basename/token.
        // Nessun path del filesystem può arrivare direttamente dalla request.
        $filename = basename($filename);

        if (!preg_match('/\.(mp3|flac|ogg|wav|m4a)$/i', $filename)) {
            http_response_code(400);
            exit;
        }

        $path        = null;
        $displayName = $filename;

        // Fase A: se il token appartiene a un record external, il path reale
        // viene letto esclusivamente dal DB. I record managed continuano invece
        // a usare MediaPathResolver come prima.
        $audioRow = $this->findAudioFileByToken($filename);

        if ($audioRow && (($audioRow['storage_type'] ?? 'managed') === 'external')) {
            $path = $this->resolveExternalPath($audioRow);

            if (!empty($audioRow['original_name'])) {
                $displayName = basename((string)$audioRow['original_name']);
            }
        } else {
            $path = MediaPathResolver::getAudioAbsPath($filename);
        }

        if ($path === null || !is_file($path) || !is_readable($path)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'File audio non trovato']);
            exit;
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            http_response_code(404);
            exit;
        }

        $mimeType = $this->getMime($displayName);
        $start    = 0;
        $end      = $size - 1;

        header('Content-Type: ' . $mimeType);
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-store');

        $safeDownloadName = str_replace(['"', "\r", "\n"], '', basename($displayName));
        if ($download) {
            header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
        } else {
            header('Content-Disposition: inline; filename="' . $safeDownloadName . '"');
        }

        // Gestione Range request (seek nel player HTML5)
        if (isset($_SERVER['HTTP_RANGE'])) {
            $range = $_SERVER['HTTP_RANGE'];
            if (preg_match('/bytes=(\d*)-(\d*)/i', $range, $m)) {
                $start = $m[1] !== '' ? (int)$m[1] : 0;
                $end   = $m[2] !== '' ? (int)$m[2] : $size - 1;

                if ($start > $end || $start >= $size || $end >= $size) {
                    http_response_code(416);
                    header('Content-Range: bytes */' . $size);
                    exit;
                }

                http_response_code(206);
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            }
        } else {
            http_response_code(200);
        }

        $length = $end - $start + 1;
        header('Content-Length: ' . $length);

        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            http_response_code(404);
            exit;
        }

        fseek($fp, $start);

        $bufferSize = 8192;
        $remaining  = $length;

        while (!feof($fp) && $remaining > 0 && connection_status() === 0) {
            $chunk = min($bufferSize, $remaining);
            $data  = fread($fp, $chunk);
            if ($data === false || $data === '') {
                break;
            }
            echo $data;
            $remaining -= strlen($data);
            flush();
        }

        fclose($fp);
        exit;
    }

    /**
     * Recupera il record audio associato al token pubblico.
     *
     * Finché la colonna storage_type non esiste (prima dell'ALTER della Fase A)
     * restituisce null e lascia funzionare normalmente tutto lo storage managed.
     */
    private function findAudioFileByToken(string $filename): ?array
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT id, filename, original_name, storage_type, source_path
                FROM audio_files
                WHERE filename = :filename
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([':filename' => $filename]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            // Compatibilità durante la finestra pre-ALTER: tutti i file esistenti
            // sono managed e continuano a essere risolti dal vecchio percorso.
            return null;
        }
    }

    /**
     * Risolve un file external in modo sicuro.
     *
     * source_path contiene il path host assoluto. In Docker viene applicato
     * MEDIA_SCAN_HOST_PREFIX (es. /hostfs) solo a runtime.
     *
     * Il realpath del file DEVE ricadere dentro il realpath della watched folder
     * configurata. In questo modo anche symlink che puntano fuori dalla root
     * vengono rifiutati.
     */
    private function resolveExternalPath(array $audioRow): ?string
    {
        $sourcePath = trim((string)($audioRow['source_path'] ?? ''));
        if ($sourcePath === '') {
            return null;
        }

        $scanRoot = $this->getSettingValue('media_scan_path');
        if ($scanRoot === '') {
            return null;
        }

        $runtimeSource = $this->hostPathToRuntime($sourcePath);
        $runtimeRoot   = $this->hostPathToRuntime($scanRoot);

        $realSource = realpath($runtimeSource);
        $realRoot   = realpath($runtimeRoot);

        if ($realSource === false || $realRoot === false) {
            return null;
        }

        if (!is_file($realSource) || !is_readable($realSource) || !is_dir($realRoot)) {
            return null;
        }

        $realSource = $this->normalizeFsPath($realSource);
        $realRoot   = rtrim($this->normalizeFsPath($realRoot), '/');

        if (!$this->pathIsInside($realSource, $realRoot)) {
            error_log('[media] external source rifiutata fuori dalla watched folder: ' . $realSource);
            return null;
        }

        // La validazione dell'estensione viene ripetuta sul vero file sorgente,
        // non soltanto sul token pubblico.
        if (!preg_match('/\.(mp3|flac|ogg|wav|m4a)$/i', $realSource)) {
            return null;
        }

        return $realSource;
    }

    private function getSettingValue(string $key): string
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = :key LIMIT 1");
            $stmt->execute([':key' => $key]);
            $value = $stmt->fetchColumn();
            return $value === false ? '' : trim((string)$value);
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Traduce un path host in path runtime Docker.
     * Esempio:
     *   DB    /storage/music/a.flac
     *   ENV   MEDIA_SCAN_HOST_PREFIX=/hostfs
     *   PHP   /hostfs/storage/music/a.flac
     *
     * Su MAMP l'env è assente e il path rimane invariato.
     */
    private function hostPathToRuntime(string $hostPath): string
    {
        $hostPath = $this->normalizeFsPath($hostPath);
        $prefix   = trim((string)getenv('MEDIA_SCAN_HOST_PREFIX'));

        if ($prefix === '') {
            return $hostPath;
        }

        $prefix = rtrim($this->normalizeFsPath($prefix), '/');

        // Se il valore è già runtime non prefissarlo due volte.
        if ($hostPath === $prefix || strpos($hostPath, $prefix . '/') === 0) {
            return $hostPath;
        }

        if ($hostPath === '/') {
            return $prefix;
        }

        return $prefix . '/' . ltrim($hostPath, '/');
    }

    private function normalizeFsPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function pathIsInside(string $file, string $root): bool
    {
        $file = $this->normalizeFsPath($file);
        $root = rtrim($this->normalizeFsPath($root), '/');

        // Su Windows il filesystem normalmente non distingue maiuscole/minuscole.
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $file = strtolower($file);
            $root = strtolower($root);
        }

        if ($root === '') {
            return false;
        }

        if ($root === '/') {
            return strpos($file, '/') === 0;
        }

        return strpos($file, $root . '/') === 0;
    }

    // ----------------------------------------------------------
    // AJAX: testa il path configurato
    // ----------------------------------------------------------
    private function testPath(): void
    {
        header('Content-Type: application/json');
        $result = MediaPathResolver::testAudioPath();
        echo json_encode($result);
        exit;
    }

    // ----------------------------------------------------------
    // GET AJAX: testa un path arbitrario SENZA salvarlo nel DB
    // Usato dal bottone "Testa" nella pagina Settings
    // ----------------------------------------------------------
    private function testPathValue(): void
    {
        header('Content-Type: application/json');

        $path = trim($_GET['path'] ?? '');

        // La traduzione host -> runtime è centralizzata nel resolver:
        // l'utente vede sempre il percorso reale del server, mai /hostfs.
        $result = MediaPathResolver::testPath($path);

        echo json_encode($result);
        exit;
    }

    // ----------------------------------------------------------
    // AJAX POST: salva nuovo audio_path in settings
    // ----------------------------------------------------------
    private function setPath(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'message' => 'Metodo non consentito']);
            exit;
        }

        // CSRF
        if (
            empty($_POST['csrf_token']) ||
            empty($_SESSION['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            echo json_encode(['ok' => false, 'message' => 'Token CSRF non valido']);
            exit;
        }

        session_write_close();

        $newPath = trim($_POST['audio_path'] ?? '');

        try {
            MediaPathResolver::setConfiguredPath($newPath);
            $test = MediaPathResolver::testAudioPath();
            echo json_encode([
                'ok'      => true,
                'message' => 'Percorso salvato correttamente.',
                'test'    => $test,
            ]);
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        }

        exit;
    }

    // ----------------------------------------------------------
    // AJAX POST: copia i file audio sul nuovo path
    // ----------------------------------------------------------
    private function migrate(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'message' => 'Metodo non consentito']);
            exit;
        }

        // CSRF
        if (
            empty($_POST['csrf_token']) ||
            empty($_SESSION['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            echo json_encode(['ok' => false, 'message' => 'Token CSRF non valido']);
            exit;
        }

        $targetDir = trim($_POST['target_dir'] ?? '');

        if ($targetDir === '') {
            echo json_encode(['ok' => false, 'message' => 'Nessun percorso di destinazione specificato']);
            exit;
        }

        // set_time_limit generoso per archivi grandi
        set_time_limit(300);

        $result = MediaPathResolver::migrateAudioFiles($targetDir);
        echo json_encode($result);
        exit;
    }

    // ----------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------

    /**
     * Estrae il filename dalla route.
     * La route arriva come ?route=media/audio/nomefile.mp3
     * index.php fa: $action = $segments[1], ma il filename è in $segments[2]
     * Per semplicità lo leggiamo direttamente dalla $_GET['route']
     */
    private function filenameFromRequest(): string
    {
        $route    = $_GET['route'] ?? '';
        $segments = explode('/', $route);
        // segments: [0]=media, [1]=audio|download, [2]=filename
        return isset($segments[2]) ? basename(urldecode($segments[2])) : '';
    }

    private function getMime(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = [
            'mp3'  => 'audio/mpeg',
            'flac' => 'audio/flac',
            'ogg'  => 'audio/ogg',
            'wav'  => 'audio/wav',
            'm4a'  => 'audio/mp4',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }
    // ----------------------------------------------------------
    // GET AJAX: naviga cartelle del filesystem del server
    // ----------------------------------------------------------
    private function browseDir(): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        $prevError = error_reporting(0);
        header('Content-Type: application/json');

        $requested = trim($_GET['path'] ?? '');
        $requested = str_replace('\\', '/', $requested);

        $hostMapped = MediaPathResolver::hasHostMapping();

        // Punto di partenza:
        // - Docker: root del filesystem HOST, esposta internamente sotto /hostfs
        // - macOS nativo: /Volumes
        // - Windows: C:/
        // - Linux nativo: /
        if ($requested === '') {
            if ($hostMapped) {
                $requested = '/';
            } elseif (PHP_OS === 'Darwin') {
                $requested = '/Volumes';
            } elseif (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $requested = 'C:/';
            } else {
                $requested = '/';
            }
        }

        $runtimeRequested = MediaPathResolver::toRuntimePath($requested);
        $realRuntime = realpath($runtimeRequested);

        // Bookmarks costruiti in coordinate HOST. In Docker vengono verificati
        // passando automaticamente dal mapping /hostfs.
        $bookmarks = [];
        $candidates = [];

        $configuredAudio = MediaPathResolver::getConfiguredDbPath();
        if ($configuredAudio !== '') {
            $candidates[$configuredAudio] = 'Libreria audio attuale';
        }

        $scanRoot = $this->getSettingValue('media_scan_path');
        if ($scanRoot !== '') {
            $candidates[$scanRoot] = 'Cartella scansione automatica';
        }

        if ($hostMapped) {
            $candidates['/home'] = 'Home utenti';
            $candidates['/mnt'] = 'Dischi e mount in /mnt';
            $candidates['/media'] = 'Dischi e mount in /media';
            $candidates['/srv'] = 'Dati in /srv';
            $candidates['/storage'] = 'Storage';
            $candidates['/data'] = 'Data';
            $candidates['/run/media'] = 'Dischi rimovibili';
        } elseif (PHP_OS === 'Darwin') {
            $candidates['/Volumes'] = 'Volumi e dischi esterni';
            $candidates['/Users'] = 'Utenti';
        } elseif (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            foreach (['C:/', 'D:/', 'E:/', 'F:/', 'G:/'] as $drive) {
                $candidates[$drive] = $drive;
            }
        } else {
            $candidates['/home'] = 'Home utenti';
            $candidates['/mnt'] = 'Dischi e mount in /mnt';
            $candidates['/media'] = 'Dischi e mount in /media';
            $candidates['/srv'] = 'Dati in /srv';
            $candidates['/storage'] = 'Storage';
            $candidates['/data'] = 'Data';
            $candidates['/run/media'] = 'Dischi rimovibili';
        }

        foreach ($candidates as $displayPath => $label) {
            $displayPath = rtrim(str_replace('\\', '/', $displayPath), '/');
            if ($displayPath === '') {
                $displayPath = '/';
            }

            $runtimePath = MediaPathResolver::toRuntimePath($displayPath);
            if (!is_dir($runtimePath) || !is_readable($runtimePath)) {
                continue;
            }

            $bookmarks[] = [
                'path' => $displayPath,
                'label' => $label,
            ];
        }

        if ($realRuntime === false || !is_dir($realRuntime)) {
            ob_end_clean();
            error_reporting($prevError);
            echo json_encode([
                'ok' => false,
                'error' => 'Percorso non valido o non accessibile: ' . $requested,
                'bookmarks' => $bookmarks,
            ]);
            exit;
        }

        if (!is_readable($realRuntime)) {
            ob_end_clean();
            error_reporting($prevError);
            echo json_encode([
                'ok' => false,
                'error' => 'Permessi insufficienti per leggere: ' . $requested,
                'bookmarks' => $bookmarks,
            ]);
            exit;
        }

        $realRuntime = rtrim(str_replace('\\', '/', $realRuntime), '/');
        if ($realRuntime === '') {
            $realRuntime = '/';
        }

        $currentDisplay = MediaPathResolver::toDisplayPath($realRuntime);
        $entries = @scandir($realRuntime);
        $dirs = [];

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                // Cartelle nascoste e metadata di sistema non aiutano nella scelta
                // di una libreria musicale e rendono il picker molto rumoroso.
                if (isset($entry[0]) && $entry[0] === '.') {
                    continue;
                }

                $fullRuntime = rtrim($realRuntime, '/') . '/' . $entry;
                if ($realRuntime === '/') {
                    $fullRuntime = '/' . $entry;
                }

                if (!is_dir($fullRuntime) || !is_readable($fullRuntime)) {
                    continue;
                }

                $fullDisplay = MediaPathResolver::toDisplayPath($fullRuntime);

                $dirs[] = [
                    'name' => $entry,
                    'path' => $fullDisplay,
                    'writable' => is_writable($fullRuntime),
                ];
            }
        }

        usort($dirs, static function (array $a, array $b): int {
            return strnatcasecmp((string)$a['name'], (string)$b['name']);
        });

        $parentDisplay = null;
        if ($realRuntime !== '/' && $realRuntime !== MediaPathResolver::toRuntimePath('/')) {
            $parentRuntime = dirname($realRuntime);
            $parentDisplay = MediaPathResolver::toDisplayPath($parentRuntime);
        }

        // Mostra "Posizioni" soltanto all'apertura/root: non ripeterle in ogni
        // sottocartella evita salti e rende la navigazione prevedibile.
        $showBookmarks = ($requested === '/' || $requested === '' || $currentDisplay === '/');

        ob_end_clean();
        error_reporting($prevError);

        echo json_encode([
            'ok' => true,
            'current' => $currentDisplay,
            'parent' => $parentDisplay,
            'dirs' => $dirs,
            'bookmarks' => $bookmarks,
            'show_bookmarks' => $showBookmarks,
            'current_writable' => is_writable($realRuntime),
        ]);
        exit;
    }

    // ----------------------------------------------------------
    // GET AJAX: conta i file audio nella cartella corrente
    // Usato dal frontend prima di avviare la migrazione a chunk
    // ----------------------------------------------------------
    private function migrateCount(): void
    {
        header('Content-Type: application/json');

        $dir = MediaPathResolver::getAudioDir();
        $mp3 = glob($dir . '/*.mp3') ?: [];
        $flac = glob($dir . '/*.flac') ?: [];
        $files = array_merge($mp3, $flac);

        echo json_encode([
            'ok' => true,
            'total' => count($files),
            // Non esporre il path runtime Docker (/hostfs/...) alla UI.
            'dir' => MediaPathResolver::getConfiguredPath(),
        ]);
        exit;
    }

    // ----------------------------------------------------------
    // POST AJAX: copia un singolo chunk di file (offset + limit)
    // Il frontend chiama questo endpoint in loop fino a completamento
    // ----------------------------------------------------------
    private function migrateChunk(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'message' => 'Metodo non consentito']);
            exit;
        }

        if (
            empty($_POST['csrf_token']) ||
            empty($_SESSION['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            echo json_encode(['ok' => false, 'message' => 'Token CSRF non valido']);
            exit;
        }

        session_write_close();

        $targetDir = trim($_POST['target_dir'] ?? '');
        $offset    = max(0, (int)($_POST['offset'] ?? 0));
        $limit     = max(1, min(3, (int)($_POST['limit'] ?? 3)));

        if ($targetDir === '') {
            echo json_encode(['ok' => false, 'message' => 'Nessun percorso destinazione']);
            exit;
        }

        $targetDisplay = rtrim(str_replace('\\', '/', $targetDir), '/');
        $targetRuntime = MediaPathResolver::toRuntimePath($targetDisplay);

        if (!is_dir($targetRuntime)) {
            if (!@mkdir($targetRuntime, 0755, true) && !is_dir($targetRuntime)) {
                echo json_encode([
                    'ok' => false,
                    'message' => 'Impossibile creare la cartella: ' . $targetDisplay,
                ]);
                exit;
            }
        }

        if (!is_writable($targetRuntime)) {
            echo json_encode([
                'ok' => false,
                'message' => 'Cartella non scrivibile: ' . $targetDisplay,
            ]);
            exit;
        }

        // Nessun timeout PHP — lascia gestire ad Apache il timeout globale
        set_time_limit(0);

        $srcDir = MediaPathResolver::getAudioDir();

        // Ordine stabile: sort alfabetico esplicito per evitare
        // comportamenti diversi tra filesystem (HFS+, APFS, ext4)
        $mp3   = glob($srcDir . '/*.mp3') ?: [];
        $flac  = glob($srcDir . '/*.flac') ?: [];
        $files = array_merge($mp3, $flac);
        sort($files);
        $total = count($files);
        $chunk = array_slice($files, $offset, $limit);

        $moved   = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($chunk as $srcFile) {
            $filename = basename($srcFile);
            $destFile = $targetRuntime . '/' . $filename;

            // Salta se già presente e dimensione identica (evita copia inutile)
            if (file_exists($destFile) && filesize($destFile) === filesize($srcFile)) {
                $skipped++;
                continue;
            }

            // Verifica che il file sorgente sia leggibile
            if (!is_readable($srcFile)) {
                $errors[] = $filename . ' (non leggibile)';
                continue;
            }

            // stream_copy_to_stream è più efficiente di copy() per file grandi
            // su disco esterno USB — legge/scrive in chunk di 8KB nativamente
            $src = @fopen($srcFile, 'rb');
            $dst = @fopen($destFile, 'wb');
            if ($src && $dst) {
                $bytes = stream_copy_to_stream($src, $dst);
                fclose($src);
                fclose($dst);
                if ($bytes > 0) {
                    $moved++;
                } else {
                    @unlink($destFile);
                    $errors[] = $filename . ' (0 byte copiati)';
                }
            } else {
                if ($src) fclose($src);
                if ($dst) {
                    fclose($dst);
                    @unlink($destFile);
                }
                $errors[] = $filename . ' (apertura file fallita)';
            }
        }

        $nextOffset = $offset + $limit;
        $done       = $nextOffset >= $total;

        echo json_encode([
            'ok'         => true,
            'moved'      => $moved,
            'skipped'    => $skipped,
            'errors'     => $errors,
            'offset'     => $offset,
            'next_offset' => $done ? $total : $nextOffset,
            'total'      => $total,
            'done'       => $done,
        ]);
        exit;
    }
}

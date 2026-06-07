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
        // e la sessione bloccata impedirebbe qualsiasi altra request PHP
        session_write_close();

        // Sicurezza: solo il basename, niente path traversal
        $filename = basename($filename);

        if (!preg_match('/\.(mp3|flac|ogg|wav|m4a)$/i', $filename)) {
            http_response_code(400);
            exit;
        }

        $path = MediaPathResolver::getAudioAbsPath($filename);

        if (!file_exists($path) || !is_readable($path)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'File non trovato: ' . $filename]);
            exit;
        }

        $size     = filesize($path);
        $mimeType = $this->getMime($filename);
        $start    = 0;
        $end      = $size - 1;

        header('Content-Type: ' . $mimeType);
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-store');

        if ($download) {
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        } else {
            header('Content-Disposition: inline; filename="' . $filename . '"');
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

        $fp = fopen($path, 'rb');
        fseek($fp, $start);

        $bufferSize = 8192;
        $remaining  = $length;

        while (!feof($fp) && $remaining > 0 && connection_status() === 0) {
            $chunk     = min($bufferSize, $remaining);
            $data      = fread($fp, $chunk);
            echo $data;
            $remaining -= strlen($data);
            flush();
        }

        fclose($fp);
        exit;
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
        $path = str_replace('\\', '/', $path);
        $path = rtrim($path, '/');

        // Se vuoto: testa il path attualmente configurato (default o DB)
        if ($path === '') {
            $result = MediaPathResolver::testAudioPath();
            echo json_encode($result);
            exit;
        }

        // Testa il path specificato senza tocccare il DB
        if (!is_dir($path)) {
            echo json_encode([
                'ok'      => false,
                'path'    => $path,
                'message' => 'Cartella non trovata: ' . $path,
            ]);
            exit;
        }
        if (!is_writable($path)) {
            echo json_encode([
                'ok'      => false,
                'path'    => $path,
                'message' => 'Cartella non scrivibile: ' . $path,
            ]);
            exit;
        }

        $files = glob($path . '/*.mp3') ?: [];
        $count = count($files);

        if ($count === 0) {
            echo json_encode([
                'ok'      => false,
                'path'    => $path,
                'message' => 'Nessun file MP3 trovato in: ' . $path,
                'count'   => 0,
            ]);
            exit;
        }

        echo json_encode([
            'ok'      => true,
            'path'    => $path,
            'message' => 'OK — ' . $count . ' file MP3 presenti',
            'count'   => $count,
        ]);
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
        // Pulisce qualsiasi output precedente (warning PHP ecc.)
        while (ob_get_level()) ob_end_clean();
        ob_start();

        // Silenzia warning PHP — li gestiamo manualmente
        $prevError = error_reporting(0);

        header('Content-Type: application/json');

        $raw = trim($_GET['path'] ?? '');
        $raw = str_replace('\\', '/', $raw);

        // Se vuoto, usa /Volumes su Mac (dove stanno i dischi esterni),
        // oppure / su Linux, oppure C:/ su Windows
        if ($raw === '') {
            if (PHP_OS === 'Darwin') {
                $raw = '/Volumes';
            } elseif (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $raw = 'C:/';
            } else {
                $raw = '/';
            }
        }

        $real = realpath($raw);

        if ($real === false || !is_dir($real)) {
            ob_end_clean();
            error_reporting($prevError);
            echo json_encode([
                'ok'    => false,
                'error' => 'Percorso non valido o non accessibile: ' . basename($raw),
            ]);
            exit;
        }

        $real = rtrim(str_replace('\\', '/', $real), '/');

        // Verifica permessi di lettura prima di scandir
        if (!is_readable($real)) {
            ob_end_clean();
            error_reporting($prevError);
            echo json_encode([
                'ok'    => false,
                'error' => 'Permessi insufficienti per leggere: ' . $real,
            ]);
            exit;
        }

        $entries = scandir($real);
        $dirs    = [];

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.') continue;
                $fullPath = $real . '/' . $entry;

                // Salta se non è una directory o non è leggibile
                if (!is_dir($fullPath)) continue;

                // Nasconde cartelle nascoste Unix/Mac (tranne "..")
                if ($entry !== '..' && isset($entry[0]) && $entry[0] === '.') continue;

                // Salta cartelle di sistema Mac note
                $systemDirs = ['Trashes', 'Spotlight-V100', 'fseventsd', 'MobileBackups'];
                if (in_array($entry, $systemDirs)) continue;

                $dirs[] = [
                    'name' => $entry,
                    'path' => $entry === '..' ? dirname($real) : $real . '/' . $entry,
                    'up'   => $entry === '..',
                ];
            }
        }

        $parent = ($real !== dirname($real)) ? dirname($real) : null;

        // Bookmark rapidi: percorsi utili sempre visibili in cima
        $bookmarks = [];
        $candidates = [];

        if (PHP_OS === 'Darwin') {
            // Mac: dischi esterni in /Volumes, home utente Apache
            $candidates = [
                '/Volumes'              => 'Volumi e dischi esterni',
                '/Users'                => 'Utenti',
            ];
            // Aggiunge ogni disco montato in /Volumes come scorciatoia diretta
            if (is_dir('/Volumes') && is_readable('/Volumes')) {
                $vols = scandir('/Volumes') ?: [];
                foreach ($vols as $vol) {
                    if ($vol === '.' || $vol === '..') continue;
                    $vp = '/Volumes/' . $vol;
                    if (is_dir($vp) && is_readable($vp)) {
                        $candidates[$vp] = $vol . ' (disco)';
                    }
                }
            }
        } elseif (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: lettere di unità comuni
            foreach (['C:/', 'D:/', 'E:/', 'F:/', 'G:/'] as $drive) {
                if (is_dir($drive)) {
                    $candidates[$drive] = $drive;
                }
            }
        } else {
            // Linux
            $candidates = [
                '/home'  => 'Home utenti',
                '/media' => 'Media (dischi montati)',
                '/mnt'   => 'Mount points',
            ];
        }

        foreach ($candidates as $path => $label) {
            if (is_dir($path)) {
                $bookmarks[] = [
                    'path'  => $path,
                    'label' => $label,
                ];
            }
        }

        ob_end_clean();
        error_reporting($prevError);

        echo json_encode([
            'ok'        => true,
            'current'   => $real,
            'parent'    => $parent,
            'dirs'      => $dirs,
            'bookmarks' => $bookmarks,
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
        $dir   = MediaPathResolver::getAudioDir();
        $files = glob($dir . '/*.mp3') ?: [];
        echo json_encode([
            'ok'    => true,
            'total' => count($files),
            'dir'   => $dir,
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

        $targetDir = rtrim(str_replace('\\', '/', $targetDir), '/');

        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                echo json_encode(['ok' => false, 'message' => 'Impossibile creare la cartella: ' . $targetDir]);
                exit;
            }
        }

        if (!is_writable($targetDir)) {
            echo json_encode(['ok' => false, 'message' => 'Cartella non scrivibile: ' . $targetDir]);
            exit;
        }

        // Nessun timeout PHP — lascia gestire ad Apache il timeout globale
        set_time_limit(0);

        $srcDir = MediaPathResolver::getAudioDir();

        // Ordine stabile: sort alfabetico esplicito per evitare
        // comportamenti diversi tra filesystem (HFS+, APFS, ext4)
        $files = glob($srcDir . '/*.mp3') ?: [];
        sort($files);
        $total = count($files);
        $chunk = array_slice($files, $offset, $limit);

        $moved   = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($chunk as $srcFile) {
            $filename = basename($srcFile);
            $destFile = $targetDir . '/' . $filename;

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
                if ($dst) { fclose($dst); @unlink($destFile); }
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
            'next_offset'=> $done ? $total : $nextOffset,
            'total'      => $total,
            'done'       => $done,
        ]);
        exit;
    }

}

    // Aggiunto in fondo — vedere dispatch() per la route
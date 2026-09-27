<?php

/**
 * SettingsController
 *
 * Gestisce la pagina delle impostazioni di Grizzly Music Archive.
 * - configurazione percorso audio
 * - configurazione scansione automatica media
 * - export / import completo dell'archivio (dati + immagini)
 *
 * Route: ?route=settings
 *
 * Posizionare in: app/controllers/SettingsController.php
 */
class SettingsController
{
    public function dispatch(string $action, ?int $id): void
    {
        switch ($action) {
            case 'export':
                $this->export();
                break;
            case 'import':
                $this->import();
                break;
            case 'clear-wiki-cache':
                $this->clearWikiCache();
                break;
            case 'save-media-scan':
                $this->saveMediaScan();
                break;
            case 'toggle-media-scan':
                $this->toggleMediaScan();
                break;
            case 'scanner-status':
                $this->scannerStatus();
                break;
            case 'ignored-list':
                $this->ignoredMediaSourcesList();
                break;
            case 'restore-ignored':
                $this->restoreIgnoredMediaSource();
                break;
            case 'restore-all-ignored':
                $this->restoreAllIgnoredMediaSources();
                break;
            case 'index':
            default:
                $this->index();
                break;
        }
    }

    private function index(): void
    {
        $pageTitle = 'Impostazioni';

        // Path attualmente configurato (grezzo dal DB o vuoto)
        $db          = Database::getInstance();
        $stmt        = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'audio_path' LIMIT 1");
        $stmt->execute();
        $row         = $stmt->fetch(PDO::FETCH_ASSOC);
        $audioPathDb = ($row && trim($row['value']) !== '') ? trim($row['value']) : '';

        // Statistiche cartella audio attuale
        $audioStats  = MediaPathResolver::getAudioStats();
        $audioTest   = MediaPathResolver::testAudioPath();

        // Path effettivo usato dall'app (DB o default config.php)
        $audioPathActive = MediaPathResolver::getAudioDir();

        // Impostazioni scanner automatico. Le leggiamo dal DB perché il worker
        // le rilegge ad ogni ciclo: una modifica dalla UI non richiede restart.
        $scannerDefaults = [
            'media_scan_enabled'        => '0',
            'media_scan_path'           => '',
            'media_scan_interval'       => '10',
            'media_scan_stable_seconds' => '30',
        ];
        $scannerSettings = $scannerDefaults;

        $stmt = $db->prepare(
            "SELECT `key`, `value`
             FROM settings
             WHERE `key` IN (
                 'media_scan_enabled',
                 'media_scan_path',
                 'media_scan_interval',
                 'media_scan_stable_seconds'
             )"
        );
        $stmt->execute();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $settingRow) {
            $key = (string)($settingRow['key'] ?? '');
            if (array_key_exists($key, $scannerSettings)) {
                $scannerSettings[$key] = (string)($settingRow['value'] ?? '');
            }
        }

        $mediaScanEnabled = $scannerSettings['media_scan_enabled'] === '1';
        $mediaScanPath = trim($scannerSettings['media_scan_path']);
        $mediaScanInterval = max(5, (int)$scannerSettings['media_scan_interval']);
        $mediaScanStableSeconds = max(10, (int)$scannerSettings['media_scan_stable_seconds']);

        $workerStatus = $this->readScannerWorkerRuntimeStatus();
        $mediaScanWorkerAlive = (bool)$workerStatus['alive'];
        $mediaScanWorkerHeartbeatAge = $workerStatus['age'];

        // Nella pagina Settings carichiamo soltanto il conteggio.
        // L'elenco completo viene richiesto via AJAX con ricerca e paginazione,
        // così anche migliaia di tombstone non appesantiscono il rendering.
        $ignoredMediaSourcesCount = (int)$db
            ->query("SELECT COUNT(*) FROM media_scan_ignored")
            ->fetchColumn();

        require BASE_PATH . '/views/settings.php';
    }

    /**
     * Stato reale del processo worker.
     *
     * Il worker scrive un heartbeat locale in storage/ ogni pochi secondi.
     * Lo stato runtime non viene salvato nel DB e non entra in export/import.
     */
    private function readScannerWorkerRuntimeStatus(): array
    {
        $file = BASE_PATH . '/storage/media-scan-worker-heartbeat.json';
        $maxAge = 60;

        if (!is_file($file) || !is_readable($file)) {
            return ['alive' => false, 'age' => null, 'timestamp' => null];
        }

        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return ['alive' => false, 'age' => null, 'timestamp' => null];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['alive' => false, 'age' => null, 'timestamp' => null];
        }

        $timestamp = isset($data['timestamp']) ? (int)$data['timestamp'] : 0;
        if ($timestamp <= 0) {
            return ['alive' => false, 'age' => null, 'timestamp' => null];
        }

        $age = max(0, time() - $timestamp);

        return [
            'alive' => $age <= $maxAge,
            'age' => $age,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * GET /index.php?route=settings/scanner-status
     * Restituisce separatamente configurazione ON/OFF e stato reale del worker.
     */
    private function scannerStatus(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'Metodo non consentito.']);
            exit;
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'media_scan_enabled' LIMIT 1");
            $stmt->execute();
            $enabled = (string)$stmt->fetchColumn() === '1';

            $worker = $this->readScannerWorkerRuntimeStatus();

            echo json_encode([
                'ok' => true,
                'enabled' => $enabled,
                'worker_alive' => (bool)$worker['alive'],
                'heartbeat_age' => $worker['age'],
                'heartbeat_at' => $worker['timestamp'],
            ]);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Impossibile leggere lo stato del worker.'
                    . (defined('DEBUG') && DEBUG ? ' ' . $e->getMessage() : '')
            ]);
            exit;
        }
    }

    // ----------------------------------------------------------
    // SCANNER MEDIA: salva configurazione watched folder.
    // POST /index.php?route=settings/save-media-scan
    // ----------------------------------------------------------
    private function saveMediaScan(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'Metodo non consentito.']);
            exit;
        }

        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        $postedToken  = (string)($_POST['csrf_token'] ?? '');

        if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Token CSRF non valido. Ricarica la pagina e riprova.']);
            exit;
        }

        $path = trim((string)($_POST['media_scan_path'] ?? ''));
        $interval = (int)($_POST['media_scan_interval'] ?? 10);
        $stableSeconds = (int)($_POST['media_scan_stable_seconds'] ?? 30);

        // Lo stato ON/OFF è gestito esclusivamente da toggleMediaScan().
        // Qui lo leggiamo solo per impedire di salvare un path vuoto mentre
        // lo scanner è attivo, senza mai sovrascrivere media_scan_enabled.
        $db = Database::getInstance();
        $enabledStmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'media_scan_enabled' LIMIT 1");
        $enabledStmt->execute();
        $enabled = (string)$enabledStmt->fetchColumn() === '1';

        // Il path deve essere assoluto. Non usiamo realpath()/is_dir() qui:
        // in Docker il percorso salvato è quello dell'HOST e viene mappato
        // dal worker tramite MEDIA_SCAN_HOST_PREFIX.
        if ($path !== '') {
            $isUnixAbsolute = isset($path[0]) && $path[0] === '/';
            $isWindowsAbsolute = (bool)preg_match('~^[A-Za-z]:[\\\\/]~', $path);

            if (!$isUnixAbsolute && !$isWindowsAbsolute) {
                http_response_code(422);
                echo json_encode([
                    'ok' => false,
                    'message' => 'Inserisci un percorso assoluto del filesystem.'
                ]);
                exit;
            }

            // Normalizza solo gli slash finali, senza cambiare il significato
            // del path host salvato in Settings.
            if ($path !== '/' && !preg_match('~^[A-Za-z]:[\\\\/]?$~', $path)) {
                $path = rtrim($path, "/\\");
            }
        }

        if ($enabled && $path === '') {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'message' => 'Per attivare la scansione automatica devi indicare la cartella da scansionare.'
            ]);
            exit;
        }

        if ($interval < 5 || $interval > 3600) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'message' => 'L\'intervallo di scansione deve essere compreso tra 5 e 3600 secondi.'
            ]);
            exit;
        }

        if ($stableSeconds < 10 || $stableSeconds > 3600) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'message' => 'Il tempo di stabilità deve essere compreso tra 10 e 3600 secondi.'
            ]);
            exit;
        }

        try {
            $db->beginTransaction();

            $sql = "INSERT INTO settings (`key`, `value`, `label`)
                    VALUES (:key, :value, :label)
                    ON DUPLICATE KEY UPDATE
                        `value` = VALUES(`value`),
                        `label` = VALUES(`label`)";
            $stmt = $db->prepare($sql);

            $rows = [
                ['media_scan_path', $path, 'Folder to scan automatically'],
                ['media_scan_interval', (string)$interval, 'Scanner interval in seconds'],
                ['media_scan_stable_seconds', (string)$stableSeconds, 'Seconds a folder must remain unchanged before import'],
            ];

            foreach ($rows as $row) {
                $stmt->execute([
                    ':key'   => $row[0],
                    ':value' => $row[1],
                    ':label' => $row[2],
                ]);
            }

            $db->commit();

            $rebound = ['audio_files' => 0, 'ignored' => 0, 'total' => 0];
            if ($path !== '') {
                require_once BASE_PATH . '/app/services/ArchiveTransfer.php';
                $transfer = new ArchiveTransfer();
                $rebound = $transfer->rebindPortableLibraryPaths($path);
            }

            $message = 'Configurazione scanner salvata. Il worker userà i nuovi parametri dal prossimo ciclo.';
            if ((int)$rebound['total'] > 0) {
                $message .= ' Rimappati ' . (int)$rebound['audio_files']
                    . ' file external e ' . (int)$rebound['ignored']
                    . ' cartelle ignorate sulla nuova root.';
            }

            echo json_encode([
                'ok' => true,
                'message' => $message,
                'rebound' => $rebound,
                'settings' => [
                    'enabled' => $enabled,
                    'path' => $path,
                    'interval' => $interval,
                    'stable_seconds' => $stableSeconds,
                ],
            ]);
            exit;
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }

            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Errore nel salvataggio delle impostazioni scanner.'
                    . (defined('DEBUG') && DEBUG ? ' ' . $e->getMessage() : '')
            ]);
            exit;
        }
    }

    // ----------------------------------------------------------
    // SCANNER MEDIA: abilita/disabilita immediatamente la scansione.
    // Modifica SOLO media_scan_enabled: path/intervallo/stabilità restano
    // affidati al pulsante "Salva scansione automatica".
    // POST /index.php?route=settings/toggle-media-scan
    // ----------------------------------------------------------
    private function toggleMediaScan(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'Metodo non consentito.']);
            exit;
        }

        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        $postedToken  = (string)($_POST['csrf_token'] ?? '');
        if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Token CSRF non valido. Ricarica la pagina e riprova.']);
            exit;
        }

        $enabled = !empty($_POST['enabled']) && (string)$_POST['enabled'] === '1';
        $db = Database::getInstance();

        if ($enabled) {
            $pathStmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'media_scan_path' LIMIT 1");
            $pathStmt->execute();
            $savedPath = trim((string)$pathStmt->fetchColumn());
            if ($savedPath === '') {
                http_response_code(422);
                echo json_encode([
                    'ok' => false,
                    'message' => 'Salva prima una cartella da scansionare, poi attiva lo scanner.'
                ]);
                exit;
            }

            require_once BASE_PATH . '/app/services/ArchiveTransfer.php';
            $transfer = new ArchiveTransfer();
            $pending = $transfer->countPortableLibraryPaths();

            if ((int)$pending['total'] > 0) {
                http_response_code(422);
                echo json_encode([
                    'ok' => false,
                    'message' => 'Il backup importato contiene riferimenti alla libreria da rimappare. '
                        . 'Premi prima "Salva configurazione scanner" per confermare la nuova cartella.'
                ]);
                exit;
            }
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO settings (`key`, `value`, `label`)
                VALUES ('media_scan_enabled', :value, 'Enable automatic media folder scan')
                ON DUPLICATE KEY UPDATE
                    `value` = VALUES(`value`),
                    `label` = VALUES(`label`)
            ");
            $stmt->execute([':value' => $enabled ? '1' : '0']);

            echo json_encode([
                'ok' => true,
                'enabled' => $enabled,
                'message' => $enabled
                    ? 'Scansione automatica attivata.'
                    : 'Scansione automatica disattivata.'
            ]);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Errore durante il cambio di stato dello scanner.'
                    . (defined('DEBUG') && DEBUG ? ' ' . $e->getMessage() : '')
            ]);
            exit;
        }
    }

    // ----------------------------------------------------------
    // SCANNER MEDIA: elenco paginato delle directory ignorate.
    // GET /index.php?route=settings/ignored-list&q=&page=1&per_page=20
    // ----------------------------------------------------------
    private function ignoredMediaSourcesList(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'Metodo non consentito.']);
            exit;
        }

        $query = trim((string)($_GET['q'] ?? ''));
        if (function_exists('mb_substr')) {
            $query = mb_substr($query, 0, 500, 'UTF-8');
        } else {
            $query = substr($query, 0, 500);
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 20);
        if (!in_array($perPage, [20, 50], true)) {
            $perPage = 20;
        }

        try {
            $db = Database::getInstance();

            $where = '';
            $params = [];
            if ($query !== '') {
                $where = ' WHERE source_path LIKE :query';
                $params[':query'] = '%' . $query . '%';
            }

            $countStmt = $db->prepare('SELECT COUNT(*) FROM media_scan_ignored' . $where);
            foreach ($params as $name => $value) {
                $countStmt->bindValue($name, $value, PDO::PARAM_STR);
            }
            $countStmt->execute();

            $total = (int)$countStmt->fetchColumn();
            $pages = $total > 0 ? (int)ceil($total / $perPage) : 0;

            if ($pages > 0 && $page > $pages) {
                $page = $pages;
            }

            $offset = ($page - 1) * $perPage;

            $sql = 'SELECT id, source_path, created_at
                    FROM media_scan_ignored'
                 . $where
                 . ' ORDER BY created_at DESC, id DESC
                     LIMIT :limit OFFSET :offset';

            $stmt = $db->prepare($sql);
            foreach ($params as $name => $value) {
                $stmt->bindValue($name, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            echo json_encode([
                'ok' => true,
                'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $pages,
            ]);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Errore durante il caricamento delle cartelle ignorate.'
                    . (defined('DEBUG') && DEBUG ? ' ' . $e->getMessage() : '')
            ]);
            exit;
        }
    }

    // ----------------------------------------------------------
    // SCANNER MEDIA: rimuove una directory dalla blacklist.
    // Alla scansione successiva la sorgente torna eleggibile all'import.
    // POST /index.php?route=settings/restore-ignored
    // ----------------------------------------------------------
    private function restoreIgnoredMediaSource(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'Metodo non consentito.']);
            exit;
        }

        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        $postedToken  = (string)($_POST['csrf_token'] ?? '');
        if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Token CSRF non valido. Ricarica la pagina e riprova.']);
            exit;
        }

        $ignoredId = (int)($_POST['id'] ?? 0);
        if ($ignoredId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Esclusione non valida.']);
            exit;
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("DELETE FROM media_scan_ignored WHERE id = :id");
            $stmt->execute([':id' => $ignoredId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'Esclusione non trovata.']);
                exit;
            }

            $remaining = (int)$db
                ->query("SELECT COUNT(*) FROM media_scan_ignored")
                ->fetchColumn();

            echo json_encode([
                'ok' => true,
                'remaining' => $remaining,
                'message' => 'Esclusione rimossa. La cartella potrà essere importata alla prossima scansione.'
            ]);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Errore durante il ripristino della cartella.'
                    . (defined('DEBUG') && DEBUG ? ' ' . $e->getMessage() : '')
            ]);
            exit;
        }
    }

    // ----------------------------------------------------------
    // SCANNER MEDIA: rimuove TUTTE le directory dalla blacklist.
    // Le directory torneranno eleggibili alla scansione successiva.
    // POST /index.php?route=settings/restore-all-ignored
    // ----------------------------------------------------------
    private function restoreAllIgnoredMediaSources(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'Metodo non consentito.']);
            exit;
        }

        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        $postedToken  = (string)($_POST['csrf_token'] ?? '');
        if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Token CSRF non valido. Ricarica la pagina e riprova.']);
            exit;
        }

        if ((string)($_POST['confirm'] ?? '') !== 'RESTORE_ALL') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Conferma mancante.']);
            exit;
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("DELETE FROM media_scan_ignored");
            $stmt->execute();
            $deleted = $stmt->rowCount();

            echo json_encode([
                'ok' => true,
                'deleted' => $deleted,
                'remaining' => 0,
                'message' => $deleted > 0
                    ? 'Tutte le esclusioni sono state rimosse. Le cartelle potranno essere importate alla prossima scansione.'
                    : 'Non ci sono cartelle ignorate da ripristinare.'
            ]);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Errore durante il ripristino delle cartelle.'
                    . (defined('DEBUG') && DEBUG ? ' ' . $e->getMessage() : '')
            ]);
            exit;
        }
    }

    // ----------------------------------------------------------
    // EXPORT: genera lo ZIP e lo invia al browser come download.
    // GET /index.php?route=settings/export
    // ----------------------------------------------------------
    private function export(): void
    {
        // Rilascia il lock della sessione: l'export può durare alcuni
        // secondi e, senza questo, terrebbe bloccate tutte le altre
        // richieste (stessa accortezza dello streaming audio).
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        require_once BASE_PATH . '/app/services/ArchiveTransfer.php';

        try {
            $transfer = new ArchiveTransfer();
            $zipPath  = $transfer->export();

            $filename = 'grizzly-export-' . date('Ymd-His') . '.zip';

            // Pulisce eventuale output bufferizzato prima del binario
            while (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($zipPath));
            header('Cache-Control: no-store');

            readfile($zipPath);
            @unlink($zipPath); // pulizia file temporaneo
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Export fallito: ' . $e->getMessage();
            header('Location: ' . BASE_URL . '/index.php?route=settings');
            exit;
        }
    }

    // ----------------------------------------------------------
    // IMPORT: riceve lo ZIP caricato, valida e sostituisce l'archivio.
    // POST /index.php?route=settings/import  (multipart, campo "archive")
    // ----------------------------------------------------------
    private function import(): void
    {
        // Solo POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . '/index.php?route=settings');
            exit;
        }

        // Verifica conferma esplicita
        if (empty($_POST['confirm']) || $_POST['confirm'] !== 'REPLACE') {
            $_SESSION['flash_error'] = 'Import annullato: conferma mancante.';
            header('Location: ' . BASE_URL . '/index.php?route=settings');
            exit;
        }

        // Verifica file caricato
        if (empty($_FILES['archive']) || $_FILES['archive']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Nessun file valido caricato.';
            header('Location: ' . BASE_URL . '/index.php?route=settings');
            exit;
        }

        $tmp  = $_FILES['archive']['tmp_name'];
        $name = $_FILES['archive']['name'] ?? '';

        // Controllo estensione di base
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            $_SESSION['flash_error'] = 'Il file deve essere un archivio .zip esportato da Grizzly.';
            header('Location: ' . BASE_URL . '/index.php?route=settings');
            exit;
        }

        require_once BASE_PATH . '/app/services/ArchiveTransfer.php';

        try {
            $transfer = new ArchiveTransfer();
            $result   = $transfer->import($tmp);

            $tot = 0;
            foreach ($result['counts'] as $c) {
                $tot += (int) $c;
            }
            $pending = (int)($result['portable_pending']['total'] ?? 0);

            $_SESSION['flash_success'] = 'Import completato: ' . $tot . ' record ripristinati. '
                . 'Lo scanner automatico è stato disattivato per sicurezza. '
                . ($pending > 0
                    ? 'Sono presenti ' . $pending . ' riferimenti alla libreria external: '
                        . 'verifica la cartella da scansionare e premi "Salva configurazione scanner" per rimapparli. '
                    : '')
                . 'I file audio non sono inclusi nello ZIP e vanno trasferiti separatamente.';
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Import fallito: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . '/index.php?route=settings');
        exit;
    }

    // ----------------------------------------------------------
    // Svuota la cache delle descrizioni Wikipedia (cache/wiki/*).
    // POST /index.php?route=settings/clear-wiki-cache
    // Usata dal pulsante "Svuota cache Wikipedia" nelle Impostazioni,
    // utile dopo modifiche al codice di ricerca o quando un disco
    // mostra ingiustamente "nessuna descrizione disponibile" per via
    // di un esito negativo cachato in precedenza (TTL fino a 3 giorni).
    // ----------------------------------------------------------
    private function clearWikiCache(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
            exit;
        }

        $dir = BASE_PATH . '/cache/wiki';

        if (!is_dir($dir)) {
            echo json_encode(['ok' => true, 'deleted' => 0, 'message' => 'Nessuna cache da svuotare.']);
            exit;
        }

        $files   = glob($dir . '/*.json') ?: [];
        $deleted = 0;

        foreach ($files as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }

        echo json_encode([
            'ok'      => true,
            'deleted' => $deleted,
            'message' => $deleted > 0
                ? "Cache svuotata: {$deleted} file rimossi."
                : 'La cache era già vuota.',
        ]);
        exit;
    }
}
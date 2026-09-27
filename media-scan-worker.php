<?php
/**
 * media-scan-worker.php
 *
 * Watched-folder worker for Grizzly Music Archive.
 * PHP 7.4 compatible.
 *
 * Normal mode:
 *   - reads media_scan_* configuration from the `settings` DB table
 *   - re-reads settings on every cycle, so path changes do not require restart
 *   - in Docker, MEDIA_SCAN_HOST_PREFIX maps host paths into the scanner
 *     container (e.g. /storage/music -> /hostfs/storage/music)
 *
 * CLI overrides remain available for diagnostics:
 *   php media-scan-worker.php --once
 *   php media-scan-worker.php --dir=/path/import --interval=10 --stable=30
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Eseguire solo da riga di comando.\n");
    exit(1);
}

$root = __DIR__;
require_once $root . '/config/database.php';

foreach (glob($root . '/app/models/*.php') as $f) {
    require_once $f;
}

// Load services while avoiding duplicate MediaImportService backup copies.
foreach (glob($root . '/app/services/*.php') as $f) {
    $base = basename($f);
    if (stripos($base, 'MediaImportService') !== false) {
        continue;
    }
    require_once $f;
}
require_once $root . '/app/services/MediaImportService.php';

if (!class_exists('MediaImportService')) {
    fwrite(STDERR, "MediaImportService non disponibile.\n");
    exit(1);
}

$db = Database::getInstance();

$options = [
    'dir_override'      => null,
    'interval_override' => null,
    'stable_override'   => null,
    'once'              => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--once') {
        $options['once'] = true;
    } elseif (strpos($arg, '--dir=') === 0) {
        $options['dir_override'] = substr($arg, 6);
    } elseif (strpos($arg, '--interval=') === 0) {
        $options['interval_override'] = max(5, (int)substr($arg, 11));
    } elseif (strpos($arg, '--stable=') === 0) {
        $options['stable_override'] = max(10, (int)substr($arg, 9));
    }
}

// L'heartbeat rappresenta solo il worker normale persistente, non i run diagnostici.
$heartbeatEnabled = !$options['once'] && $options['dir_override'] === null;

function workerLog(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}


/**
 * Stato runtime del worker per la pagina Settings.
 *
 * Non viene salvato nel DB e non entra nei backup: è un heartbeat locale
 * all'installazione corrente. La scrittura è atomica.
 */
function writeWorkerHeartbeat(
    string $root,
    bool $enabled,
    string $configuredPath
): void {
    $storageDir = rtrim($root, '/') . '/storage';

    if (!is_dir($storageDir) && !@mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
        return;
    }

    $file = $storageDir . '/media-scan-worker-heartbeat.json';
    $tmp  = $file . '.tmp.' . getmypid();

    $payload = [
        'timestamp'       => time(),
        'pid'             => getmypid(),
        'enabled'         => $enabled,
        'configured_path' => $configuredPath,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $file);
    } else {
        @unlink($tmp);
    }
}

/**
 * Rispetta l'intervallo configurato ma aggiorna l'heartbeat durante l'attesa.
 */
function workerSleepWithHeartbeat(
    string $root,
    int $seconds,
    bool $enabled,
    string $configuredPath,
    bool $heartbeatEnabled
): void {
    $remaining = max(0, $seconds);

    while ($remaining > 0) {
        $chunk = min(5, $remaining);
        sleep($chunk);
        $remaining -= $chunk;

        if ($heartbeatEnabled) {
            writeWorkerHeartbeat($root, $enabled, $configuredPath);
        }
    }
}

function settingValue(PDO $db, string $key, ?string $default = null): ?string
{
    try {
        $stmt = $db->prepare("SELECT `value` FROM `settings` WHERE `key` = :k LIMIT 1");
        $stmt->execute([':k' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
}

function settingExists(PDO $db, string $key): bool
{
    try {
        $stmt = $db->prepare("SELECT 1 FROM `settings` WHERE `key` = :k LIMIT 1");
        $stmt->execute([':k' => $key]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function settingBool(PDO $db, string $key, bool $default): bool
{
    $raw = settingValue($db, $key, $default ? '1' : '0');
    if ($raw === null) {
        return $default;
    }
    $v = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $v === null ? $default : $v;
}

function normalizedConfiguredPath(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
        return '';
    }

    // Grizzly watched folders are absolute filesystem paths.
    if ($path[0] !== '/') {
        return '';
    }

    return $path === '/' ? '/' : rtrim($path, '/');
}

/**
 * Convert a host path stored in Settings to the path visible by the worker.
 *
 * MAMP:
 *   MEDIA_SCAN_HOST_PREFIX=''      /Users/me/Music -> /Users/me/Music
 *
 * Docker:
 *   MEDIA_SCAN_HOST_PREFIX=/hostfs /storage/music  -> /hostfs/storage/music
 */
function runtimeScanPath(string $configuredPath): string
{
    $configuredPath = normalizedConfiguredPath($configuredPath);
    if ($configuredPath === '') {
        return '';
    }

    $prefix = getenv('MEDIA_SCAN_HOST_PREFIX');
    $prefix = $prefix === false ? '' : rtrim(str_replace('\\', '/', trim($prefix)), '/');

    if ($prefix === '') {
        return $configuredPath;
    }

    if ($configuredPath === '/') {
        return $prefix;
    }

    return $prefix . '/' . ltrim($configuredPath, '/');
}


/**
 * Verifica per confronto lessicale che un percorso assoluto appartenga alla
 * root configurata. Non usa realpath() sul percorso figlio perché una cartella
 * ignored può legittimamente non esistere più.
 */
function pathBelongsToConfiguredRoot(string $path, string $configuredRoot): bool
{
    $path = normalizedConfiguredPath($path);
    $configuredRoot = normalizedConfiguredPath($configuredRoot);

    if ($path === '' || $configuredRoot === '') {
        return false;
    }

    // Non accettare segmenti "." o "..": un tombstone deve contenere un
    // percorso assoluto normalizzato e non deve poter uscire dalla root.
    if (preg_match('~(^|/)\.\.?(/|$)~', $path)
        || preg_match('~(^|/)\.\.?(/|$)~', $configuredRoot)) {
        return false;
    }

    if ($configuredRoot === '/') {
        return isset($path[0]) && $path[0] === '/';
    }

    return $path === $configuredRoot
        || strpos($path, $configuredRoot . '/') === 0;
}

/**
 * Rimuove da media_scan_ignored soltanto i tombstone diventati orfani.
 *
 * Sicurezze:
 *   1. la root deve essere configurata;
 *   2. la root runtime deve esistere ed essere leggibile;
 *   3. il source_path ignored deve appartenere alla root configurata;
 *   4. il corrispondente percorso runtime non deve più essere una directory.
 *
 * Se la root non è disponibile (NAS/mount offline, permessi, ecc.) la funzione
 * non elimina nulla.
 */

/**
 * Traduce un albumKey visto dal processo worker nel corrispondente path host
 * salvato nel DB. In MAMP i due path coincidono; in Docker rimuove il prefisso
 * runtime (es. /hostfs) e ricostruisce il path sotto media_scan_path.
 */
function configuredAlbumPathFromRuntime(
    string $runtimeAlbumPath,
    string $runtimeRoot,
    string $configuredRoot
): string {
    $runtimeAlbumPath = rtrim(str_replace('\\', '/', trim($runtimeAlbumPath)), '/');
    $runtimeRoot      = rtrim(str_replace('\\', '/', trim($runtimeRoot)), '/');
    $configuredRoot   = normalizedConfiguredPath($configuredRoot);

    if ($runtimeAlbumPath === '' || $runtimeRoot === '' || $configuredRoot === '') {
        return '';
    }

    if ($runtimeAlbumPath === $runtimeRoot) {
        return $configuredRoot;
    }

    if (strpos($runtimeAlbumPath, $runtimeRoot . '/') !== 0) {
        return '';
    }

    $relative = ltrim(substr($runtimeAlbumPath, strlen($runtimeRoot)), '/');
    if ($relative === '' || preg_match('~(^|/)\.\.?(/|$)~', $relative)) {
        return '';
    }

    return $configuredRoot === '/'
        ? '/' . $relative
        : rtrim($configuredRoot, '/') . '/' . $relative;
}

/**
 * Verifica se la directory-album è attualmente nella blacklist dello scanner.
 */
function isIgnoredAlbumPath(PDO $db, string $configuredAlbumPath): bool
{
    $configuredAlbumPath = normalizedConfiguredPath($configuredAlbumPath);
    if ($configuredAlbumPath === '') {
        return false;
    }

    try {
        $stmt = $db->prepare("
            SELECT 1
            FROM media_scan_ignored
            WHERE path_hash = :path_hash
               OR source_path = :source_path
            LIMIT 1
        ");
        $stmt->execute([
            ':path_hash'   => sha1($configuredAlbumPath),
            ':source_path' => $configuredAlbumPath,
        ]);
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        // Se la tabella non è disponibile non alterare lo stato del worker.
        return false;
    }
}

function cleanupOrphanIgnoredMediaSources(PDO $db, string $configuredRoot): int
{
    $configuredRoot = normalizedConfiguredPath($configuredRoot);
    if ($configuredRoot === '') {
        return 0;
    }

    $runtimeRoot = runtimeScanPath($configuredRoot);
    if ($runtimeRoot === '') {
        return 0;
    }

    $realRoot = realpath($runtimeRoot);
    if ($realRoot === false || !is_dir($realRoot) || !is_readable($realRoot)) {
        return 0;
    }

    $deleted = 0;

    try {
        $stmt = $db->query("
            SELECT id, source_path
            FROM media_scan_ignored
            ORDER BY id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return 0;
        }

        $deleteStmt = $db->prepare("
            DELETE FROM media_scan_ignored
            WHERE id = :id
        ");

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $sourcePath = normalizedConfiguredPath((string)($row['source_path'] ?? ''));

            if ($id <= 0 || $sourcePath === '') {
                continue;
            }

            // Tombstone fuori dalla watched root: non toccarlo.
            if (!pathBelongsToConfiguredRoot($sourcePath, $configuredRoot)) {
                continue;
            }

            $runtimeIgnoredPath = runtimeScanPath($sourcePath);
            if ($runtimeIgnoredPath === '') {
                continue;
            }

            // Se la directory esiste ancora, il tombstone serve e rimane.
            if (@is_dir($runtimeIgnoredPath)) {
                continue;
            }

            $deleteStmt->execute([':id' => $id]);

            if ($deleteStmt->rowCount() > 0) {
                $deleted++;
                workerLog('[CLEANUP] Tombstone rimosso: ' . $sourcePath);
            }
        }
    } catch (Throwable $e) {
        workerLog('[WARN] Pulizia cartelle ignorate non riuscita: ' . $e->getMessage());
        return 0;
    }

    return $deleted;
}

function loadWorkerState(string $file): array
{
    if (!is_file($file)) {
        return ['configured_path' => null, 'albums' => []];
    }

    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return ['configured_path' => null, 'albums' => []];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['configured_path' => null, 'albums' => []];
}

function saveWorkerState(string $file, array $state): void
{
    $tmp = $file . '.tmp.' . getmypid();
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        return;
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $file);
    } else {
        @unlink($tmp);
    }
}

/**
 * Lightweight album-directory signature.
 * Only files relevant to import are included.
 */
function albumSnapshot(string $albumDir): string
{
    $allowed = ['mp3', 'flac', 'jpg', 'jpeg', 'png', 'webp'];
    $rows = [];

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($albumDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }

            $name = $file->getFilename();
            if ($name !== '' && $name[0] === '.') {
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $base = rtrim(str_replace('\\', '/', $albumDir), '/');
            $rel  = strpos($path, $base . '/') === 0
                ? substr($path, strlen($base) + 1)
                : $name;

            $rows[] = $rel . '|' . $file->getSize() . '|' . $file->getMTime() . '|' . $file->getCTime();
        }
    } catch (Throwable $e) {
        return '';
    }

    sort($rows, SORT_STRING);
    return sha1(implode("\n", $rows));
}

// One worker per Grizzly installation, regardless of which folder is selected.
$tmpDir    = rtrim(sys_get_temp_dir(), '/');
$stateFile = $tmpDir . '/grizzly-media-import-state.json';
$lockFile  = $tmpDir . '/grizzly-media-import-worker.lock';

$lockHandle = @fopen($lockFile, 'c');
if (!$lockHandle || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Scanner Grizzly gia' attivo.\n");
    exit(0);
}

$service = new MediaImportService();
$state   = loadWorkerState($stateFile);
$lastNotice = null;
$lastActiveConfiguredPath = null;

workerLog('Media scanner avviato; configurazione letta da Settings.');

while (true) {
    // Backward compatibility: before the new DB settings exist, use BASE_PATH/import.
    $hasScannerSettings = settingExists($db, 'media_scan_path');

    if ($options['dir_override'] !== null) {
        $enabled = true;
        $configuredPath = $options['dir_override'];
    } elseif ($hasScannerSettings) {
        $enabled = settingBool($db, 'media_scan_enabled', false);
        $configuredPath = (string)settingValue($db, 'media_scan_path', '');
    } else {
        $enabled = true;
        $configuredPath = (defined('BASE_PATH') ? BASE_PATH : $root) . '/import';
    }

    $interval = $options['interval_override'] !== null
        ? $options['interval_override']
        : max(5, (int)settingValue($db, 'media_scan_interval', '10'));

    $stable = $options['stable_override'] !== null
        ? $options['stable_override']
        : max(10, (int)settingValue($db, 'media_scan_stable_seconds', '30'));

    $configuredPath = normalizedConfiguredPath($configuredPath);

    if ($heartbeatEnabled) {
        writeWorkerHeartbeat($root, $enabled, $configuredPath);
    }

    if (!$enabled) {
        $notice = '[IDLE] Scansione automatica disattivata da Settings.';
        if ($notice !== $lastNotice) {
            workerLog($notice);
            $lastNotice = $notice;
        }

        if ($options['once']) {
            break;
        }
        workerSleepWithHeartbeat($root, $interval, $enabled, $configuredPath, $heartbeatEnabled);
        continue;
    }

    if ($configuredPath === '') {
        $notice = '[IDLE] Nessuna cartella da scansionare configurata in Settings.';
        if ($notice !== $lastNotice) {
            workerLog($notice);
            $lastNotice = $notice;
        }

        if ($options['once']) {
            break;
        }
        workerSleepWithHeartbeat($root, $interval, $enabled, $configuredPath, $heartbeatEnabled);
        continue;
    }

    $runtimePath = runtimeScanPath($configuredPath);
    $realImport  = realpath($runtimePath);

    if ($realImport === false || !is_dir($realImport) || !is_readable($realImport)) {
        $notice = '[WAIT] Cartella configurata non accessibile: ' . $configuredPath;
        if ($runtimePath !== $configuredPath) {
            $notice .= ' (runtime: ' . $runtimePath . ')';
        }

        if ($notice !== $lastNotice) {
            workerLog($notice);
            $lastNotice = $notice;
        }

        if ($options['once']) {
            break;
        }
        workerSleepWithHeartbeat($root, $interval, $enabled, $configuredPath, $heartbeatEnabled);
        continue;
    }

    $realImport = rtrim(str_replace('\\', '/', $realImport), '/');

    if ($lastActiveConfiguredPath !== $configuredPath) {
        workerLog('[CONFIG] Watched folder: ' . $configuredPath);
        workerLog('[CONFIG] Intervallo: ' . $interval . 's | stabilita: ' . $stable . 's');

        if ($runtimePath !== $configuredPath) {
            workerLog('[CONFIG] Path runtime container: ' . $realImport);
        }

        // NON azzerare lo state a ogni riavvio del worker.
        // Lo state persistito è valido finché media_scan_path non cambia davvero.
        // In precedenza $lastActiveConfiguredPath partiva da null e questo blocco
        // cancellava tutte le imported_signature al primo ciclo di OGNI restart,
        // provocando una scansione completa dell'intera libreria.
        $stateConfiguredPath = normalizedConfiguredPath(
            isset($state['configured_path']) ? (string)$state['configured_path'] : ''
        );

        if ($stateConfiguredPath !== $configuredPath) {
            workerLog('[CONFIG] Watched folder cambiata: reset dello state scanner.');
            $state = [
                'configured_path' => $configuredPath,
                'albums'          => [],
            ];
        } else {
            // Stessa root del file state: conserva signature/imported_signature.
            $state['configured_path'] = $configuredPath;
            if (!isset($state['albums']) || !is_array($state['albums'])) {
                $state['albums'] = [];
            }
        }

        $lastActiveConfiguredPath = $configuredPath;
    }

    $lastNotice = null;

    // Pulizia automatica dei tombstone orfani SOLO in modalità normale:
    // deve esistere una media_scan_path salvata in Settings e non deve essere
    // in uso un override CLI --dir.
    if ($options['dir_override'] === null && $hasScannerSettings) {
        cleanupOrphanIgnoredMediaSources($db, $configuredPath);
    }

    if (!isset($state['albums']) || !is_array($state['albums'])) {
        $state['albums'] = [];
    }

    $now        = time();
    $albumKeys  = $service->discoverAlbumKeys($realImport);
    $stillAlive = [];

    foreach ($albumKeys as $albumKey) {
        $albumKey = rtrim(str_replace('\\', '/', $albumKey), '/');

        // Una directory ignorata NON deve conservare imported_signature nel
        // worker state. Così, quando l'utente preme "Ripristina", la cartella
        // ricompare come nuova sorgente, passa di nuovo dalla stability window
        // e viene reimportata senza dover modificare fisicamente i file.
        $configuredAlbumKey = configuredAlbumPathFromRuntime(
            $albumKey,
            $realImport,
            $configuredPath
        );

        if ($configuredAlbumKey !== '' && isIgnoredAlbumPath($db, $configuredAlbumKey)) {
            if (isset($state['albums'][$albumKey])) {
                unset($state['albums'][$albumKey]);
                workerLog('[IGNORED] Stato scanner azzerato: ' . basename($albumKey));
            }
            continue;
        }

        $stillAlive[$albumKey] = true;

        $signature = albumSnapshot($albumKey);
        if ($signature === '') {
            workerLog('[SKIP] Impossibile calcolare snapshot: ' . $albumKey);
            continue;
        }

        $previous = isset($state['albums'][$albumKey]) && is_array($state['albums'][$albumKey])
            ? $state['albums'][$albumKey]
            : [];

        if (!isset($previous['signature']) || $previous['signature'] !== $signature) {
            $state['albums'][$albumKey] = [
                'signature'          => $signature,
                'stable_since'       => $now,
                'imported_signature' => isset($previous['imported_signature']) ? $previous['imported_signature'] : null,
                'last_result'        => 'pending',
                'last_scan'          => isset($previous['last_scan']) ? $previous['last_scan'] : null,
            ];

            workerLog('[PENDING] Modifiche rilevate: ' . $albumKey);
            continue;
        }

        $stableSince = isset($previous['stable_since']) ? (int)$previous['stable_since'] : $now;
        $stableFor   = $now - $stableSince;
        $alreadyDone = isset($previous['imported_signature'])
            && $previous['imported_signature'] === $signature;

        if ($alreadyDone) {
            continue;
        }

        if ($stableFor < $stable) {
            workerLog('[WAIT] ' . basename($albumKey) . ' stabile da ' . $stableFor . 's');
            continue;
        }

        workerLog('[IMPORT] ' . $albumKey);
        if ($heartbeatEnabled) {
            writeWorkerHeartbeat($root, $enabled, $configuredPath);
        }

        $report = $service->scan($realImport, false, 0, [$albumKey]);

        if ($heartbeatEnabled) {
            writeWorkerHeartbeat($root, $enabled, $configuredPath);
        }

        $ok = empty($report['error'])
            && isset($report['totals'])
            && (int)$report['totals']['errors'] === 0
            && (int)$report['totals']['albums_found'] > 0;

        $state['albums'][$albumKey]['last_scan']   = $now;
        $state['albums'][$albumKey]['last_result'] = $ok ? 'ok' : 'error';

        if ($ok) {
            $state['albums'][$albumKey]['imported_signature'] = $signature;

            workerLog('[OK] ' . basename($albumKey)
                . ' | creati=' . (int)$report['totals']['albums_created']
                . ' esistenti=' . (int)$report['totals']['albums_existing']
                . ' audio+=' . (int)$report['totals']['audio_imported']
                . ' skip=' . (int)$report['totals']['audio_skipped']);
        } else {
            $error = !empty($report['error']) ? $report['error'] : 'errore durante import';
            workerLog('[ERROR] ' . basename($albumKey) . ' | ' . $error);
        }
    }

    foreach (array_keys($state['albums']) as $known) {
        if (!isset($stillAlive[$known])) {
            unset($state['albums'][$known]);
        }
    }

    $state['configured_path'] = $configuredPath;
    saveWorkerState($stateFile, $state);

    if ($heartbeatEnabled) {
        writeWorkerHeartbeat($root, $enabled, $configuredPath);
    }

    if ($options['once']) {
        break;
    }

    workerSleepWithHeartbeat($root, $interval, $enabled, $configuredPath, $heartbeatEnabled);
}

@flock($lockHandle, LOCK_UN);
@fclose($lockHandle);

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
 *
 * Managed mode (installations without Docker):
 *   php media-scan-worker.php --managed
 *   Started automatically by MediaScanWorkerSupervisor from the web app.
 *   The process stops by itself when scanning is disabled in Settings, when
 *   the database is no longer reachable (server stopped), when Grizzly's code
 *   changes (update) or when the installation is removed. The next web
 *   request starts it again when needed.
 *
 * In every mode the worker holds storage/media-scan-worker.lock for its whole
 * life, so only one worker per installation can run, whoever started it.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Eseguire solo da riga di comando.\n");
    exit(1);
}

$root = __DIR__;
require_once $root . '/config/database.php';

// Carica solo il file canonico associato alla classe richiesta.
// Evita di eseguire copie/backup .php presenti nelle cartelle applicative.
spl_autoload_register(function (string $class) use ($root): void {
    foreach (['/app/models/', '/app/services/'] as $dir) {
        $file = $root . $dir . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

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
    'managed'           => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--once') {
        $options['once'] = true;
    } elseif ($arg === '--managed') {
        $options['managed'] = true;
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

// La modalità gestita ha senso solo per il worker persistente normale.
if ($options['once'] || $options['dir_override'] !== null) {
    $options['managed'] = false;
}

// Chi ha avviato il worker: riportato nell'heartbeat per la pagina Settings.
$hostPrefixEnv = getenv('MEDIA_SCAN_HOST_PREFIX');
if ($options['managed']) {
    define('GRIZZLY_WORKER_MODE', 'managed');
} elseif ($hostPrefixEnv !== false && trim($hostPrefixEnv) !== '') {
    define('GRIZZLY_WORKER_MODE', 'docker');
} else {
    define('GRIZZLY_WORKER_MODE', 'manual');
}
unset($hostPrefixEnv);

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
        'mode'            => defined('GRIZZLY_WORKER_MODE') ? GRIZZLY_WORKER_MODE : 'manual',
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
 * Alla chiusura ordinata marca l'heartbeat come "stopped", solo se appartiene
 * a questo processo: la pagina Settings mostra subito il worker come fermo e
 * il supervisore conserva la prova che l'avvio era riuscito.
 */
function markWorkerHeartbeatStopped(string $root): void
{
    $file = rtrim($root, '/') . '/storage/media-scan-worker-heartbeat.json';
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['pid']) || (int)$data['pid'] !== getmypid()) {
        return;
    }

    $data['timestamp'] = time();
    $data['stopped'] = true;

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }

    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $file);
    } else {
        @unlink($tmp);
    }
}

/**
 * Rispetta l'intervallo configurato ma aggiorna l'heartbeat durante l'attesa.
 *
 * $shouldStop (facoltativo) viene valutato a ogni frazione di attesa e
 * restituisce il motivo di arresto oppure stringa vuota. Restituisce il
 * motivo che ha interrotto l'attesa, stringa vuota se l'attesa è terminata.
 */
function workerSleepWithHeartbeat(
    string $root,
    int $seconds,
    bool $enabled,
    string $configuredPath,
    bool $heartbeatEnabled,
    ?callable $shouldStop = null
): string {
    $remaining = max(0, $seconds);

    while ($remaining > 0) {
        $chunk = min(5, $remaining);
        sleep($chunk);
        $remaining -= $chunk;

        if ($shouldStop !== null) {
            $reason = (string)$shouldStop();
            if ($reason !== '') {
                return $reason;
            }
        }

        if ($heartbeatEnabled) {
            writeWorkerHeartbeat($root, $enabled, $configuredPath);
        }
    }

    return '';
}

/**
 * Verifica che la connessione al database sia ancora utilizzabile.
 * Le funzioni settingValue()/settingExists() intercettano gli errori e
 * restituirebbero valori di default: senza questo controllo, dopo un riavvio
 * di MySQL il worker continuerebbe a girare su una configurazione fittizia.
 */
function databaseAlive(PDO $db): bool
{
    try {
        $db->query('SELECT 1')->fetchColumn();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Impronta del codice da cui dipende il worker. In modalità gestita, se
 * cambia (aggiornamento, nuova .env) o un file sparisce (disinstallazione),
 * il worker termina e la richiesta web successiva lo riavvia con il codice
 * aggiornato.
 */
function workerCodeFingerprint(string $root): string
{
    clearstatcache();

    $files = [
        $root . '/media-scan-worker.php',
        $root . '/config/config.php',
        $root . '/config/database.php',
        $root . '/VERSION',
        $root . '/.env',
    ];
    foreach (['/app/services/*.php', '/app/models/*.php'] as $pattern) {
        $found = glob($root . $pattern);
        if (is_array($found)) {
            $files = array_merge($files, $found);
        }
    }

    sort($files, SORT_STRING);

    $rows = [];
    foreach ($files as $file) {
        $mtime = @filemtime($file);
        $rows[] = $file . '|' . ($mtime === false ? 'missing' : $mtime . ':' . (int)@filesize($file));
    }

    return sha1(implode("\n", $rows));
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
    // Filesystem paths are byte-significant. Do NOT trim whitespace:
    // a directory name may legitimately end with a space on Unix/macOS.
    $path = str_replace('\\', '/', $path);
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
    // Preserve whitespace that belongs to real filesystem names.
    $runtimeAlbumPath = rtrim(str_replace('\\', '/', $runtimeAlbumPath), '/');
    $runtimeRoot      = rtrim(str_replace('\\', '/', $runtimeRoot), '/');
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

/**
 * Sposta una path runtime da una watched root a un'altra mantenendo il relativo.
 */
function rebaseWorkerPath(string $path, string $oldRoot, string $newRoot): string
{
    // Preserve whitespace in discovered candidate paths. Only slash
    // normalization/trailing slash removal is allowed here.
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $oldRoot = rtrim(str_replace('\\', '/', $oldRoot), '/');
    $newRoot = rtrim(str_replace('\\', '/', $newRoot), '/');

    if ($path === '' || $oldRoot === '' || $newRoot === '') {
        return '';
    }
    if ($path !== $oldRoot && strpos($path, $oldRoot . '/') !== 0) {
        return '';
    }

    return $newRoot . substr($path, strlen($oldRoot));
}

/**
 * Migra lo state del worker dopo un root rebind verificato.
 *
 * Gli album gia' importati restano no-op, ma la signature viene ricalcolata
 * sulla nuova posizione. Stati non conclusi tornano pending e dovranno passare
 * nuovamente dalla stability/readiness window.
 */
function migrateWorkerStateRoot(
    array $state,
    string $oldRuntimeRoot,
    string $newRuntimeRoot,
    string $newConfiguredRoot
): array {
    $now = time();
    $out = [
        'configured_path' => $newConfiguredRoot,
        'albums' => [],
    ];

    $albums = isset($state['albums']) && is_array($state['albums'])
        ? $state['albums']
        : [];

    foreach ($albums as $oldKey => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $newKey = rebaseWorkerPath(
            (string)$oldKey,
            $oldRuntimeRoot,
            $newRuntimeRoot
        );
        if ($newKey === '' || !is_dir($newKey) || !is_readable($newKey)) {
            continue;
        }

        $newSignature = albumSnapshot($newKey);
        if ($newSignature === '') {
            continue;
        }

        $oldSignature = trim((string)($entry['signature'] ?? ''));
        $oldImported = trim((string)($entry['imported_signature'] ?? ''));
        $oldBootstrap = trim((string)($entry['readiness_bootstrap_signature'] ?? ''));

        $wasImported = $oldSignature !== ''
            && $oldImported !== ''
            && hash_equals($oldSignature, $oldImported);
        $wasBootstrap = $oldSignature !== ''
            && $oldBootstrap !== ''
            && hash_equals($oldSignature, $oldBootstrap);

        $entry['signature'] = $newSignature;
        $entry['deferred_sources'] = [];

        if ($wasImported) {
            $entry['imported_signature'] = $newSignature;
            $entry['last_result'] = 'ok';
            if ($wasBootstrap) {
                $entry['readiness_bootstrap_signature'] = $newSignature;
                $entry['readiness_stable'] = true;
            } else {
                unset($entry['readiness_bootstrap_signature']);
            }
        } else {
            $entry['imported_signature'] = null;
            $entry['last_result'] = 'pending';
            $entry['stable_since'] = $now;
            $entry['last_scan'] = null;
            unset(
                $entry['readiness_bootstrap_signature'],
                $entry['readiness_stable'],
                $entry['readiness_status'],
                $entry['candidate_signature'],
                $entry['gate_key']
            );
        }

        $out['albums'][$newKey] = $entry;
    }

    return $out;
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

// Lock d'installazione in storage/: garantisce un solo worker per
// installazione anche quando i processi usano TMPDIR diversi (terminale,
// launchd, avvio gestito da Grizzly). La web app lo usa anche per sapere
// se il worker è vivo durante import lunghi.
$installLockHandle = null;
if ($heartbeatEnabled) {
    $storageDir = $root . '/storage';
    if (is_dir($storageDir) || @mkdir($storageDir, 0775, true) || is_dir($storageDir)) {
        $installLockHandle = @fopen($storageDir . '/media-scan-worker.lock', 'c');
        if ($installLockHandle && !@flock($installLockHandle, LOCK_EX | LOCK_NB)) {
            fwrite(STDERR, "Scanner Grizzly gia' attivo per questa installazione.\n");
            exit(0);
        }
    }
    unset($storageDir);
}

if ($options['managed'] && function_exists('posix_setsid')) {
    // Sessione propria: i segnali inviati al gruppo di processi del server
    // web (graceful restart di Apache) non devono raggiungere il worker.
    @posix_setsid();
}

$codeFingerprint = $options['managed'] ? workerCodeFingerprint($root) : '';

/**
 * Motivo di arresto della modalità gestita, stringa vuota se il worker deve
 * continuare. Il controllo sul DB vale anche durante le attese.
 */
$managedStopReason = function () use ($db, $root, &$codeFingerprint, $options): string {
    if (!$options['managed']) {
        return '';
    }
    if (!databaseAlive($db)) {
        return 'database non raggiungibile';
    }
    if (!settingBool($db, 'media_scan_enabled', false)) {
        return 'scansione disattivata da Settings';
    }
    if (workerCodeFingerprint($root) !== $codeFingerprint) {
        return 'codice di Grizzly modificato o rimosso';
    }
    return '';
};

$stopReason = '';
$exitCode = 0;

$service = new MediaImportService();
$exclusionService = new MediaScanExclusionService($db);
$rootRelocationService = new MediaRootRelocationService($db);

// Canonical production gate state. It is deliberately separate from the
// historical worker snapshot and from shadow/diagnostic readiness state.
$readinessStateFile = $root . '/storage/media-scan-readiness-state.json';
$readinessStateRepository = new AlbumReadinessStateRepository($readinessStateFile);
$readinessService = new AlbumReadinessService($db, $root);
$readinessService->setStateRepository($readinessStateRepository);

$scanGate = new AlbumScanGate($db, $root);
$importExecutor = new AlbumImportExecutor($db, $root);

$state   = loadWorkerState($stateFile);
$lastNotice = null;
$lastActiveConfiguredPath = null;

workerLog('Media scanner avviato (' . GRIZZLY_WORKER_MODE . ', PID ' . getmypid() . '); configurazione letta da Settings.');

while (true) {
    // Database perso (MySQL fermo o riavviato): la connessione PDO non si
    // ripristina da sola. Il worker termina; Docker, launchd o la web app
    // (modalità gestita) lo riavviano quando il database torna disponibile.
    if (!databaseAlive($db)) {
        $stopReason = 'database non raggiungibile';
        $exitCode = 1;
        break;
    }

    if ($options['managed']) {
        $stopReason = $managedStopReason();
        if ($stopReason !== '') {
            break;
        }
    }

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
        $stopReason = workerSleepWithHeartbeat($root, $interval, $enabled, $configuredPath, $heartbeatEnabled, $managedStopReason);
        if ($stopReason !== '') {
            break;
        }
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
        $stopReason = workerSleepWithHeartbeat($root, $interval, $enabled, $configuredPath, $heartbeatEnabled, $managedStopReason);
        if ($stopReason !== '') {
            break;
        }
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
        $stopReason = workerSleepWithHeartbeat($root, $interval, $enabled, $configuredPath, $heartbeatEnabled, $managedStopReason);
        if ($stopReason !== '') {
            break;
        }
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

        if ($options['dir_override'] === null && $hasScannerSettings) {
            try {
                // Durable source of truth: the DB binding, not /tmp worker state.
                // legacy state is consulted only once for upgrade/bootstrap and
                // only after every current external source_path proves that root.
                $boundConfiguredPath = $rootRelocationService->resolveBoundRoot(
                    $configuredPath,
                    $stateConfiguredPath
                );
            } catch (Throwable $e) {
                workerLog(
                    '[ERROR] Binding watched folder non determinabile: '
                    . $e->getMessage()
                );
                $stopReason = 'scanner root binding non verificato';
                $exitCode = 1;
                break;
            }

            if ($boundConfiguredPath !== $configuredPath) {
                $oldRuntimeRoot = runtimeScanPath($boundConfiguredPath);
                $oldRuntimeReal = realpath($oldRuntimeRoot);
                if ($oldRuntimeReal !== false && is_dir($oldRuntimeReal)) {
                    $oldRuntimeRoot = rtrim(str_replace('\\', '/', $oldRuntimeReal), '/');
                }

                workerLog(
                    '[CONFIG] Watched folder cambiata: root relocation '
                    . $boundConfiguredPath . ' -> ' . $configuredPath
                );

                try {
                    $relocation = $rootRelocationService->rebind(
                        $boundConfiguredPath,
                        $configuredPath
                    );

                    // /tmp state is only cache: migrate it when available. If it
                    // is absent/stale, DB-backed [STATE] recovery below rebuilds it.
                    $state = migrateWorkerStateRoot(
                        $state,
                        $oldRuntimeRoot,
                        $realImport,
                        $configuredPath
                    );
                    saveWorkerState($stateFile, $state);

                    workerLog(
                        '[CONFIG] Root relocation completata'
                        . ' | audio_paths=' . (int)($relocation['audio_paths_rebound'] ?? 0)
                        . ' | ignored_paths=' . (int)($relocation['ignored_paths_rebound'] ?? 0)
                        . ' | verified_files=' . (int)($relocation['verified_files'] ?? 0)
                    );
                } catch (Throwable $e) {
                    workerLog(
                        '[ERROR] Cambio watched folder rifiutato: '
                        . $e->getMessage()
                    );
                    $stopReason = 'root relocation non verificata';
                    $exitCode = 1;
                    break;
                }
            } elseif ($stateConfiguredPath !== $configuredPath) {
                // DB e' gia' legato alla root corrente ma il cache state e'
                // assente/stale (es. /tmp pulito o crash dopo COMMIT). Non c'e'
                // nessuna operazione sull'archivio: riallinea solo il cache.
                if ($stateConfiguredPath !== '') {
                    $oldRuntimeRoot = runtimeScanPath($stateConfiguredPath);
                    $oldRuntimeReal = realpath($oldRuntimeRoot);
                    if ($oldRuntimeReal !== false && is_dir($oldRuntimeReal)) {
                        $oldRuntimeRoot = rtrim(str_replace('\\', '/', $oldRuntimeReal), '/');
                    }

                    $state = migrateWorkerStateRoot(
                        $state,
                        $oldRuntimeRoot,
                        $realImport,
                        $configuredPath
                    );
                } else {
                    $state = [
                        'configured_path' => $configuredPath,
                        'albums' => [],
                    ];
                }

                saveWorkerState($stateFile, $state);
                workerLog('[CONFIG] Worker state riallineato alla watched folder corrente.');
            } else {
                $state['configured_path'] = $configuredPath;
                if (!isset($state['albums']) || !is_array($state['albums'])) {
                    $state['albums'] = [];
                }
            }
        } else {
            // CLI --dir is diagnostic/override state only. It must never change
            // the durable DB root binding selected from Settings.
            if ($stateConfiguredPath !== $configuredPath) {
                workerLog('[CONFIG] Override CLI: inizializzazione state scanner diagnostico.');
                $state = [
                    'configured_path' => $configuredPath,
                    'albums' => [],
                ];
            } else {
                $state['configured_path'] = $configuredPath;
                if (!isset($state['albums']) || !is_array($state['albums'])) {
                    $state['albums'] = [];
                }
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

    // Le esclusioni configurate sono HARD EXCLUSIONS:
    // - MediaImportService le pota durante la discovery e non entra nei subtree;
    // - il worker non ne legge contenuti e non le usa per correlare altri album;
    // - qui eliminiamo soltanto eventuale state storico già presente sotto
    //   una directory che l'utente ha appena escluso.
    $excludedRuntimeRoots = $exclusionService->runtimeExcludedRoots($realImport);
    if (!empty($excludedRuntimeRoots) && !empty($state['albums'])) {
        foreach (array_keys($state['albums']) as $knownAlbumKey) {
            if (MediaScanExclusionService::pathInAnySubtree((string)$knownAlbumKey, $excludedRuntimeRoots)) {
                unset($state['albums'][$knownAlbumKey]);
                workerLog('[EXCLUDED] Stato scanner rimosso: ' . $knownAlbumKey);
            }
        }
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

        $signatureChanged = !isset($previous['signature'])
            || $previous['signature'] !== $signature;

        $stableSince = isset($previous['stable_since'])
            ? (int)$previous['stable_since']
            : $now;
        $stableFor = max(0, $now - $stableSince);

        $alreadyDone = isset($previous['imported_signature'])
            && $previous['imported_signature'] === $signature;

        // Worker state is only a cache. After a verified root relocation,
        // manual state cleanup or tmp-state loss, recover idempotency directly
        // from DB source_path evidence. This check is read-only. If every current
        // scanner-selected audio is already indexed and unchanged, rebuild the
        // imported signatures and skip Readiness/Identity/Executor completely.
        if (!$alreadyDone) {
            try {
                $indexedCandidate = $rootRelocationService->inspectIndexedCandidate(
                    $realImport,
                    $albumKey
                );
            } catch (Throwable $e) {
                $indexedCandidate = [
                    'fully_indexed' => false,
                    'reason' => 'inspection-error:' . $e->getMessage(),
                ];
            }

            if (!empty($indexedCandidate['fully_indexed'])) {
                $state['albums'][$albumKey] = array_merge($previous, [
                    'signature' => $signature,
                    'stable_since' => isset($previous['stable_since'])
                        ? (int)$previous['stable_since']
                        : $now,
                    'imported_signature' => $signature,
                    'readiness_bootstrap_signature' => $signature,
                    'readiness_stable' => true,
                    'last_result' => 'ok',
                    'last_scan' => $now,
                    'deferred_sources' => [],
                ]);

                if (($previous['last_result'] ?? '') !== 'ok'
                    || (($previous['imported_signature'] ?? '') !== $signature)) {
                    workerLog(
                        '[STATE] ' . basename($albumKey)
                        . ' | gia indicizzato album_id='
                        . (int)($indexedCandidate['album_id'] ?? 0)
                        . ' | audio='
                        . (int)($indexedCandidate['audio_files'] ?? 0)
                    );
                }

                continue;
            }
        }

        // Un album già importato e invariato resta un no-op SOLO dopo che il
        // nuovo Readiness repository ha osservato e stabilizzato questa stessa
        // worker signature. Questo permette il bootstrap del nuovo state sulle
        // librerie già completamente importate senza rieseguire il gate.
        $readinessBootstrapDone = isset($previous['readiness_bootstrap_signature'])
            && hash_equals(
                (string)$previous['readiness_bootstrap_signature'],
                $signature
            );

        if (
            $alreadyDone
            && $readinessBootstrapDone
        ) {
            continue;
        }

        // Preserva il lifecycle relocation esistente. Finché tutte le vecchie
        // sorgenti external sono ancora vive non serve rieseguire Readiness,
        // Identity o l'Executor.
        if (
            !$signatureChanged
            && (($previous['last_result'] ?? '') === 'deferred')
        ) {
            $waiting = isset($previous['deferred_sources'])
                && is_array($previous['deferred_sources'])
                    ? $previous['deferred_sources']
                    : [];

            if (!empty($waiting)) {
                $allStillLive = true;

                foreach ($waiting as $waitPath) {
                    $waitPath = normalizedConfiguredPath((string)$waitPath);
                    $runtimeWait = $waitPath !== ''
                        ? runtimeScanPath($waitPath)
                        : '';

                    if (
                        $runtimeWait === ''
                        || !is_file($runtimeWait)
                        || !is_readable($runtimeWait)
                    ) {
                        $allStillLive = false;
                        break;
                    }
                }

                if ($allStillLive) {
                    continue;
                }

                workerLog(
                    "[RECONCILE] Sorgente precedente non piu' raggiungibile: "
                    . basename($albumKey)
                );
            }
        }

        // Costruisce le stesse parti candidate usate dal Readiness engine.
        // MediaImportService continua a essere la discovery authority per la
        // lista albumKeys, quindi le esclusioni configurate restano applicate
        // esattamente come prima.
        try {
            $candidateGroups = $readinessService->discoverCandidates($albumKey);
            $readinessParts = null;

            foreach ($candidateGroups as $candidateKey => $candidateParts) {
                $candidateKey = rtrim(
                    str_replace('\\\\', '/', (string)$candidateKey),
                    '/'
                );

                if ($candidateKey === $albumKey) {
                    $readinessParts = $candidateParts;
                    break;
                }
            }

            if (!is_array($readinessParts) || empty($readinessParts)) {
                throw new RuntimeException(
                    'Readiness grouping non coerente con discovery per: '
                    . $albumKey
                );
            }

            // Questa osservazione avviene anche mentre la vecchia stability
            // window non è ancora trascorsa. Così la persistent signature
            // parte dal primo ciclo utile e non raddoppia artificialmente
            // media_scan_stable_seconds.
            $readinessCandidate = $readinessService->analyzeCandidate(
                $realImport,
                $albumKey,
                $readinessParts,
                $stable
            );
        } catch (Throwable $e) {
            $state['albums'][$albumKey]['last_result'] = 'error';
            $state['albums'][$albumKey]['last_scan'] = $now;

            workerLog(
                '[ERROR] ' . basename($albumKey)
                . ' | readiness: ' . $e->getMessage()
            );
            continue;
        }

        $readinessStatus = strtoupper(
            trim((string)($readinessCandidate['status'] ?? ''))
        );
        $candidateSignature = trim(
            (string)($readinessCandidate['candidate_signature'] ?? '')
        );
        $readinessStable = !empty($readinessCandidate['stable']);

        // Bootstrap/migrazione del nuovo readiness state per album già
        // importati dal worker precedente. Questi candidati vengono osservati
        // fino a quando la persistent signature è temporalmente stabile, ma
        // NON passano mai da Identity/Gate/Executor finché la worker signature
        // coincide con imported_signature.
        if (
            $alreadyDone
            && !$signatureChanged
        ) {
            $firstBootstrapObservation =
                empty($previous['candidate_signature']);

            $state['albums'][$albumKey]['last_scan'] = $now;
            $state['albums'][$albumKey]['readiness_status'] = $readinessStatus;
            $state['albums'][$albumKey]['candidate_signature'] =
                $candidateSignature;
            $state['albums'][$albumKey]['readiness_stable'] =
                $readinessStable;

            if ($readinessStable) {
                $state['albums'][$albumKey]['readiness_bootstrap_signature'] =
                    $signature;
            }

            if ($firstBootstrapObservation) {
                workerLog(
                    '[READINESS] ' . basename($albumKey)
                    . ' | bootstrap=observing'
                    . ' | status='
                    . ($readinessStatus !== '' ? $readinessStatus : 'UNKNOWN')
                );
            } elseif (
                $readinessStable
                && !$readinessBootstrapDone
            ) {
                workerLog(
                    '[READINESS] ' . basename($albumKey)
                    . ' | bootstrap=complete'
                    . ' | status='
                    . ($readinessStatus !== '' ? $readinessStatus : 'UNKNOWN')
                );
            }

            continue;
        }

        // La lightweight snapshot del worker resta un ulteriore guardrail per
        // restart/ignored/excluded lifecycle. Non decide più la completezza:
        // quella decisione appartiene esclusivamente ad AlbumReadinessService.
        if ($signatureChanged) {
            $state['albums'][$albumKey] = [
                'signature'           => $signature,
                'stable_since'        => $now,
                'imported_signature'  => isset($previous['imported_signature'])
                    ? $previous['imported_signature']
                    : null,
                'last_result'         => 'pending',
                'last_scan'           => isset($previous['last_scan'])
                    ? $previous['last_scan']
                    : null,
                'deferred_sources'    => [],
                'readiness_status'    => $readinessStatus,
                'candidate_signature' => $candidateSignature,
            ];

            workerLog('[PENDING] Modifiche rilevate: ' . $albumKey);
            continue;
        }

        // Manteniamo anche la stability window storica come guardrail locale.
        // Non può rendere READY un candidato: può soltanto ritardarlo.
        if ($stableFor < $stable) {
            workerLog(
                '[WAIT] ' . basename($albumKey)
                . ' stabile da ' . $stableFor . 's'
            );
            continue;
        }

        // Canonical gate:
        // non-READY => Identity NOT_RUN;
        // READY => IdentityResolver;
        // solo CREATE/RECONCILE producono un AlbumImportPlan eseguibile.
        try {
            $gateResult = $scanGate->evaluate(
                $realImport,
                $readinessCandidate
            );
        } catch (Throwable $e) {
            $state['albums'][$albumKey]['last_result'] = 'error';
            $state['albums'][$albumKey]['last_scan'] = $now;

            workerLog(
                '[ERROR] ' . basename($albumKey)
                . ' | gate: ' . $e->getMessage()
            );
            continue;
        }

        $gateAction = strtoupper(
            trim((string)($gateResult['action'] ?? 'REVIEW'))
        );
        $gateReason = trim((string)($gateResult['reason'] ?? ''));
        $gateKey = implode('|', [
            $gateAction,
            $gateReason,
            $readinessStatus,
            $candidateSignature,
        ]);

        $previousGateKey = (string)($previous['gate_key'] ?? '');

        if ($gateAction === 'NONE') {
            $state['albums'][$albumKey]['last_result'] =
                $readinessStatus === 'INCOMPLETE'
                    ? 'incomplete'
                    : 'pending';
            $state['albums'][$albumKey]['last_scan'] = $now;
            $state['albums'][$albumKey]['deferred_sources'] = [];
            $state['albums'][$albumKey]['readiness_status'] = $readinessStatus;
            $state['albums'][$albumKey]['candidate_signature'] =
                $candidateSignature;
            $state['albums'][$albumKey]['gate_key'] = $gateKey;

            if ($previousGateKey !== $gateKey) {
                workerLog(
                    '[' . ($readinessStatus !== '' ? $readinessStatus : 'PENDING')
                    . '] ' . basename($albumKey)
                    . ($gateReason !== '' ? ' | ' . $gateReason : '')
                );
            }
            continue;
        }

        if ($gateAction === 'REVIEW') {
            $state['albums'][$albumKey]['last_result'] = 'review';
            $state['albums'][$albumKey]['last_scan'] = $now;
            $state['albums'][$albumKey]['deferred_sources'] = [];
            $state['albums'][$albumKey]['readiness_status'] = $readinessStatus;
            $state['albums'][$albumKey]['candidate_signature'] =
                $candidateSignature;
            $state['albums'][$albumKey]['gate_key'] = $gateKey;

            if ($previousGateKey !== $gateKey) {
                $identityDecision = (string)(
                    $gateResult['identity']['decision'] ?? 'NOT_RUN'
                );

                workerLog(
                    '[REVIEW] ' . basename($albumKey)
                    . ' | reason=' . $gateReason
                    . ' | identity=' . $identityDecision
                );
            }
            continue;
        }

        if (!in_array($gateAction, ['CREATE', 'RECONCILE'], true)) {
            $state['albums'][$albumKey]['last_result'] = 'error';
            $state['albums'][$albumKey]['last_scan'] = $now;

            workerLog(
                '[ERROR] ' . basename($albumKey)
                . ' | azione gate sconosciuta=' . $gateAction
            );
            continue;
        }

        $plan = $gateResult['plan'] ?? null;
        if (!$plan instanceof AlbumImportPlan) {
            $state['albums'][$albumKey]['last_result'] = 'error';
            $state['albums'][$albumKey]['last_scan'] = $now;

            workerLog(
                '[ERROR] ' . basename($albumKey)
                . ' | piano import assente per ' . $gateAction
            );
            continue;
        }

        workerLog(
            '[IMPORT] ' . $albumKey
            . ' | action=' . $gateAction
            . (
                $plan->targetAlbumId() !== null
                    ? ' target_album_id=' . $plan->targetAlbumId()
                    : ''
            )
        );

        if ($heartbeatEnabled) {
            writeWorkerHeartbeat($root, $enabled, $configuredPath);
        }

        try {
            $execution = $importExecutor->execute($plan);
        } catch (Throwable $e) {
            $state['albums'][$albumKey]['last_result'] = 'error';
            $state['albums'][$albumKey]['last_scan'] = $now;

            workerLog(
                '[ERROR] ' . basename($albumKey)
                . ' | executor: ' . $e->getMessage()
            );

            if ($heartbeatEnabled) {
                writeWorkerHeartbeat($root, $enabled, $configuredPath);
            }

            continue;
        }

        if ($heartbeatEnabled) {
            writeWorkerHeartbeat($root, $enabled, $configuredPath);
        }

        $state['albums'][$albumKey]['last_scan'] = $now;
        $state['albums'][$albumKey]['readiness_status'] = $readinessStatus;
        $state['albums'][$albumKey]['candidate_signature'] =
            $candidateSignature;
        $state['albums'][$albumKey]['gate_key'] = $gateKey;

        $executionStatus = strtolower(
            trim((string)($execution['status'] ?? ''))
        );
        $entry = isset($execution['result']) && is_array($execution['result'])
            ? $execution['result']
            : [];

        if (
            !empty($execution['ok'])
            && $executionStatus === 'committed'
        ) {
            $state['albums'][$albumKey]['last_result'] = 'ok';
            $state['albums'][$albumKey]['imported_signature'] = $signature;
            $state['albums'][$albumKey]['readiness_bootstrap_signature'] =
                $signature;
            $state['albums'][$albumKey]['readiness_stable'] = true;
            $state['albums'][$albumKey]['deferred_sources'] = [];

            workerLog(
                '[OK] ' . basename($albumKey)
                . ' | action=' . $gateAction
                . ' audio+=' . (int)($entry['audio_imported'] ?? 0)
                . ' skip=' . (int)($entry['audio_skipped'] ?? 0)
            );
        } elseif ($executionStatus === 'deferred') {
            $waitSources = [];

            foreach ((array)($entry['deferred_sources'] ?? []) as $waitPath) {
                $waitPath = normalizedConfiguredPath((string)$waitPath);
                if ($waitPath !== '') {
                    $waitSources[$waitPath] = true;
                }
            }

            $state['albums'][$albumKey]['last_result'] = 'deferred';
            $state['albums'][$albumKey]['deferred_sources'] =
                array_keys($waitSources);

            workerLog(
                '[DEFER] ' . basename($albumKey)
                . ' | external in attesa='
                . (int)($entry['audio_deferred'] ?? 0)
                . ' | sorgenti monitorate=' . count($waitSources)
            );
        } elseif ($executionStatus === 'review') {
            $state['albums'][$albumKey]['last_result'] = 'review';
            $state['albums'][$albumKey]['deferred_sources'] = [];

            workerLog(
                '[REVIEW] ' . basename($albumKey)
                . ' | executor='
                . (string)($entry['execution_reason'] ?? 'review')
            );
        } else {
            $state['albums'][$albumKey]['last_result'] = 'error';

            workerLog(
                '[ERROR] ' . basename($albumKey)
                . ' | risultato executor non valido: '
                . ($executionStatus !== '' ? $executionStatus : '<empty>')
            );
        }

        // Modalità gestita: tra un album e l'altro rispetta subito una
        // disattivazione o un aggiornamento, senza attendere la fine del ciclo.
        if ($options['managed']) {
            $stopReason = $managedStopReason();
            if ($stopReason !== '') {
                break;
            }
        }
    }

    // Se il ciclo è stato interrotto non tutte le directory sono state
    // visitate: la pulizia delle voci scomparse vale solo per cicli completi.
    if ($stopReason === '') {
        foreach (array_keys($state['albums']) as $known) {
            if (!isset($stillAlive[$known])) {
                unset($state['albums'][$known]);
            }
        }
    }

    $state['configured_path'] = $configuredPath;

    $readinessSaveError = '';
    try {
        $readinessStateRepository->save();
    } catch (Throwable $e) {
        $readinessSaveError = $e->getMessage();
        workerLog('[ERROR] Readiness state non salvato: ' . $readinessSaveError);
    }

    // Il worker state viene comunque salvato anche se il readiness state fallisce:
    // un import già COMMITtato non deve perdere imported_signature.
    saveWorkerState($stateFile, $state);

    if ($readinessSaveError !== '' && $stopReason === '') {
        $stopReason = 'impossibile salvare readiness state';
        $exitCode = 1;
    }

    if ($stopReason !== '') {
        break;
    }

    if ($heartbeatEnabled) {
        writeWorkerHeartbeat($root, $enabled, $configuredPath);
    }

    if ($options['once']) {
        break;
    }

    $stopReason = workerSleepWithHeartbeat($root, $interval, $enabled, $configuredPath, $heartbeatEnabled, $managedStopReason);
    if ($stopReason !== '') {
        break;
    }
}

if ($stopReason !== '') {
    workerLog('[STOP] Worker terminato: ' . $stopReason . '.');
}

if ($heartbeatEnabled && ($options['managed'] || $exitCode !== 0)) {
    markWorkerHeartbeatStopped($root);
}

if ($installLockHandle) {
    @flock($installLockHandle, LOCK_UN);
    @fclose($installLockHandle);
}

@flock($lockHandle, LOCK_UN);
@fclose($lockHandle);

exit($exitCode);

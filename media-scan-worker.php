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
 * Semantic decisions are delegated to AlbumScanDecisionService; the worker
 * remains the scheduler/executor orchestrator.
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
 * Dati aggiuntivi riportati in ogni heartbeat. Oggi contiene solo la
 * riscansione in corso dopo un cambio della watched folder (rescan_path,
 * rescan_from), che la pagina Settings usa per il box "Cambio cartella".
 * Con $set sostituisce i dati correnti; senza argomenti li restituisce.
 */
function workerHeartbeatExtra(?array $set = null): array
{
    static $extra = [];
    if ($set !== null) {
        $extra = $set;
    }
    return $extra;
}

/**
 * Dati heartbeat della riscansione registrata nello state del worker.
 * Vuoto se non c'è una riscansione in corso.
 */
function relocationRescanHeartbeat(array $state): array
{
    $rescan = isset($state['relocation_rescan']) && is_array($state['relocation_rescan'])
        ? $state['relocation_rescan']
        : [];
    $path = (string)($rescan['path'] ?? '');

    if ($path === '') {
        return [];
    }

    return [
        'rescan_path' => $path,
        'rescan_from' => (string)($rescan['from'] ?? ''),
    ];
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

    $payload = array_merge(workerHeartbeatExtra(), [
        'timestamp'       => time(),
        'pid'             => getmypid(),
        'mode'            => defined('GRIZZLY_WORKER_MODE') ? GRIZZLY_WORKER_MODE : 'manual',
        'enabled'         => $enabled,
        'configured_path' => $configuredPath,
    ]);

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
 * Deduce il formato AUTOMATICO dello scanner dalla posizione della cartella.
 *
 * Contratto:
 *   .../CD/...            => CD
 *   .../Vinile/...        => Vinile
 *   .../Musicassetta/...  => Musicassetta/Tape
 *   .../Digital/...       => Digital
 *   qualunque altro path  => Digital
 *
 * Questo e soltanto il candidato automatico dello scanner.
 * Album::syncScannerFormat() resta l'autorita finale e NON sovrascrive
 * un formato scelto manualmente dall'utente.
 */
function scannerRelocationFormatId(
    PDO $db,
    string $albumDir,
    string $importDir
): int {
    $albumDir = str_replace('\\', '/', $albumDir);
    $importDir = rtrim(str_replace('\\', '/', $importDir), '/');

    $normalize = static function (string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    };

    $formatMap = [];
    $rows = $db->query('SELECT id, name FROM formats')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $name = $normalize((string)($row['name'] ?? ''));
        $id = (int)($row['id'] ?? 0);
        if ($name !== '' && $id > 0) {
            $formatMap[$name] = $id;
        }
    }

    if (empty($formatMap)) {
        throw new RuntimeException('Tabella formats vuota: formato relocation non determinabile.');
    }

    $aliases = [
        'vinile' => 'vinile',
        'vinili' => 'vinile',
        'vinyl' => 'vinile',
        'lp' => 'vinile',
        '12"' => 'vinile',
        '7"' => 'vinile',
        '45 giri' => 'vinile',
        '33 giri' => 'vinile',
        'cd' => 'cd',
        'cds' => 'cd',
        'compact disc' => 'cd',
        'musicassetta' => 'musicassetta',
        'musicassette' => 'musicassetta',
        'cassetta' => 'musicassetta',
        'cassette' => 'musicassetta',
        'mc' => 'musicassetta',
        'k7' => 'musicassetta',
        'tape' => 'tape',
        'tapes' => 'tape',
        'digital' => 'digital',
        'digitale' => 'digital',
        'flac' => 'digital',
        'web' => 'digital',
        'mp3' => 'digital',
        'lossless' => 'digital',
    ];

    if (
        $albumDir !== ''
        && $importDir !== ''
        && (
            $albumDir === $importDir
            || strpos($albumDir . '/', $importDir . '/') === 0
        )
    ) {
        $dir = dirname($albumDir);

        while (
            $dir !== ''
            && $dir !== '.'
            && $dir !== $importDir
            && strpos($dir . '/', $importDir . '/') === 0
        ) {
            $name = $normalize(basename($dir));
            $canonical = $aliases[$name] ?? null;

            if ($canonical === null && isset($formatMap[$name])) {
                $canonical = $name;
            }

            if ($canonical === 'musicassetta' || $canonical === 'tape') {
                if (isset($formatMap['musicassetta'])) {
                    return (int)$formatMap['musicassetta'];
                }
                if (isset($formatMap['tape'])) {
                    return (int)$formatMap['tape'];
                }
            } elseif ($canonical !== null && isset($formatMap[$canonical])) {
                return (int)$formatMap[$canonical];
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }

    if (isset($formatMap['digital'])) {
        return (int)$formatMap['digital'];
    }

    throw new RuntimeException('Formato Digital non presente nella tabella formats.');
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
 * Worker state: cache/telemetry only.
 *
 * A cached terminal result may be used as an optimization only when BOTH the
 * candidate filesystem signature and the scanner-relevant database revision
 * are unchanged. If either side changes, canonical classification is rebuilt
 * from filesystem + database.
 */

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
 * Fingerprint read-only dei dati relazionali che possono cambiare la
 * classificazione scanner. Viene calcolato una sola volta per ciclo, non per
 * album. Lo state puo' quindi saltare un candidato invariato soltanto se anche
 * questo fingerprint coincide con quello del ciclo precedente.
 *
 * Il fingerprint include soltanto le colonne che partecipano alle decisioni
 * scanner; cache editoriali (bio, cover, YouTube) non lo invalidano.
 */
function scannerSemanticDbRevision(PDO $db): string
{
    // Solo colonne che possono cambiare ownership, identity, readiness o
    // copertura audio. Bio, cover e cache YouTube non devono invalidare lo
    // scanner e provocare una full classification inutile.
    $queries = [
        'artists' => "
            SELECT id, name, mb_artist_id
            FROM artists
            ORDER BY id ASC
        ",
        'albums' => "
            SELECT id, artist_id, title, year, mbid, mb_release_group, needs_review
            FROM albums
            ORDER BY id ASC
        ",
        'tracks' => "
            SELECT id, album_id, position, title, duration_sec
            FROM tracks
            ORDER BY id ASC
        ",
        'audio_files' => "
            SELECT id, album_id, track_id, filename, original_name, filesize,
                   source_hash, source_path, source_mtime, storage_type
            FROM audio_files
            ORDER BY id ASC
        ",
    ];

    try {
        $ctx = hash_init('sha256');

        foreach ($queries as $table => $sql) {
            $stmt = $db->query($sql);
            if (!$stmt) {
                return '';
            }

            hash_update($ctx, "TABLE\0" . $table . "\n");

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!is_array($row)) {
                    continue;
                }

                foreach ($row as $column => $value) {
                    hash_update($ctx, (string)$column . "=");
                    if ($value === null) {
                        hash_update($ctx, "<NULL>");
                    } else {
                        // bin2hex preserva anche VARBINARY/source_path e byte non UTF-8.
                        hash_update($ctx, bin2hex((string)$value));
                    }
                    hash_update($ctx, "\0");
                }

                hash_update($ctx, "\n");
            }
        }

        return hash_final($ctx);
    } catch (Throwable $e) {
        // Fingerprint non disponibile => fast-path disabilitato, fail-closed.
        return '';
    }
}


/**
 * Fingerprint semantico del solo album target di un DEFERRED.
 *
 * Un defer di relocation/reconcile dipende esclusivamente da:
 * - identita' dell'album target;
 * - sua tracklist;
 * - sue associazioni audio correnti.
 *
 * Non deve essere invalidato da modifiche a album estranei. Se il target cambia
 * (audio managed aggiunto, source_path modificato, tracklist/identita' cambiata)
 * il fingerprint cambia e il worker torna alla classificazione canonica.
 */
function scannerAlbumSemanticRevision(PDO $db, int $albumId): string
{
    if ($albumId <= 0) {
        return '';
    }

    $queries = [
        'album' => [
            'sql' => "
                SELECT a.id, a.artist_id, ar.name AS artist_name,
                       ar.mb_artist_id, a.title, a.year, a.mbid,
                       a.mb_release_group, a.needs_review
                FROM albums a
                JOIN artists ar ON ar.id = a.artist_id
                WHERE a.id = :album_id
                LIMIT 1
            ",
            'required' => true,
        ],
        'tracks' => [
            'sql' => "
                SELECT id, album_id, position, title, duration_sec
                FROM tracks
                WHERE album_id = :album_id
                ORDER BY id ASC
            ",
            'required' => false,
        ],
        'audio_files' => [
            'sql' => "
                SELECT id, album_id, track_id, filename, original_name, filesize,
                       source_hash, source_path, source_mtime, storage_type
                FROM audio_files
                WHERE album_id = :album_id
                ORDER BY id ASC
            ",
            'required' => false,
        ],
    ];

    try {
        $ctx = hash_init('sha256');

        foreach ($queries as $section => $spec) {
            $stmt = $db->prepare((string)$spec['sql']);
            if (!$stmt) {
                return '';
            }

            $stmt->execute([':album_id' => $albumId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!is_array($rows)) {
                return '';
            }
            if (!empty($spec['required']) && count($rows) !== 1) {
                return '';
            }

            hash_update($ctx, "SECTION\0" . $section . "\n");

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                foreach ($row as $column => $value) {
                    hash_update($ctx, (string)$column . '=');
                    if ($value === null) {
                        hash_update($ctx, '<NULL>');
                    } else {
                        hash_update($ctx, bin2hex((string)$value));
                    }
                    hash_update($ctx, "\0");
                }

                hash_update($ctx, "\n");
            }
        }

        return hash_final($ctx);
    } catch (Throwable $e) {
        // Fingerprint non disponibile => nessun fast-path DEFERRED.
        return '';
    }
}

/**
 * Safe no-op cache contract for the dominant OWNED_EXACT/SAME case.
 *
 * Worker state is never semantic authority. A cached SAME may only avoid a
 * canonical reclassification when all of the following are true:
 * - the current candidate filesystem signature is unchanged;
 * - no scanner write has already changed the DB during this cycle;
 * - the cycle-wide semantic DB fingerprint is unchanged from the previous
 *   completed healthy cycle;
 * - the cached canonical decision was itself computed against exactly that
 *   same semantic DB fingerprint;
 * - the cached decision is the terminal OWNED_EXACT result and its last
 *   processing outcome was successful.
 *
 * Old state entries do not contain canonical_db_revision and therefore fail
 * closed: they are classified once before they can become cacheable.
 */
function workerCanReuseCanonicalSame(
    array $previous,
    bool $signatureChanged,
    string $cycleDbRevision,
    bool $dbRevisionUnchanged,
    bool $cycleDbMutated
): bool {
    if (
        $cycleDbMutated
        || $signatureChanged
        || !$dbRevisionUnchanged
        || $cycleDbRevision === ''
    ) {
        return false;
    }

    $decisionDbRevision = trim((string)(
        $previous['canonical_db_revision'] ?? ''
    ));

    if (
        $decisionDbRevision === ''
        || !hash_equals($cycleDbRevision, $decisionDbRevision)
    ) {
        return false;
    }

    if (
        strtoupper((string)($previous['canonical_class'] ?? '')) !== 'SAME'
        || (int)($previous['canonical_album_id'] ?? 0) <= 0
        || (($previous['last_result'] ?? '') !== 'ok')
    ) {
        return false;
    }

    // Only terminal SAME decisions whose authority is entirely local and
    // deterministic under unchanged filesystem + unchanged semantic DB may
    // bypass AlbumScanDecisionService. NEW/CHANGED/PENDING/REVIEW/DEFERRED
    // are deliberately excluded.
    return in_array(
        (string)($previous['canonical_reason'] ?? ''),
        [
            'external-source-set-identical',
            'managed-album-complete',
            'mixed-album-complete',
        ],
        true
    );
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

/**
 * Snapshot limitato alle parti assegnate al candidato dalla discovery.
 *
 * Per un candidato standard con una sola part coincidente con albumKey mantiene
 * la signature storica. Per i gruppi multi-disco osserva solo le part CDn/DiscN
 * assegnate, senza inglobare eventuali album fratelli sotto lo stesso parent.
 */
function candidateSnapshot(string $albumDir, array $parts): string
{
    $base = rtrim(str_replace('\\', '/', $albumDir), '/');

    if ($base === '' || empty($parts)) {
        return '';
    }

    $normalizedParts = [];
    foreach ($parts as $part) {
        if (!is_array($part)) {
            return '';
        }

        $dir = isset($part['dir'])
            ? rtrim(str_replace('\\', '/', (string)$part['dir']), '/')
            : '';

        if ($dir === '') {
            return '';
        }

        $normalizedParts[$dir] = true;
    }

    // Candidato standard: evita churn delle imported_signature esistenti.
    if (count($normalizedParts) === 1 && isset($normalizedParts[$base])) {
        return albumSnapshot($base);
    }

    $allowed = ['mp3', 'flac', 'jpg', 'jpeg', 'png', 'webp'];
    $rows = [];
    $seen = [];

    $appendPath = static function (string $path) use (
        $base,
        $allowed,
        &$rows,
        &$seen
    ): bool {
        if (!is_file($path) || is_link($path)) {
            return true;
        }

        $name = basename($path);
        if ($name !== '' && $name[0] === '.') {
            return true;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return true;
        }

        $path = str_replace('\\', '/', $path);
        if ($path !== $base && strpos($path, $base . '/') !== 0) {
            return false;
        }

        if (isset($seen[$path])) {
            return true;
        }
        $seen[$path] = true;

        $size = @filesize($path);
        $mtime = @filemtime($path);
        $ctime = @filectime($path);
        if ($size === false || $mtime === false || $ctime === false) {
            return false;
        }

        $rel = strpos($path, $base . '/') === 0
            ? substr($path, strlen($base) + 1)
            : $name;

        $rows[] = $rel . '|' . (int)$size . '|' . (int)$mtime . '|' . (int)$ctime;
        return true;
    };

    try {
        foreach (array_keys($normalizedParts) as $dir) {
            if (!is_dir($dir) || !is_readable($dir)) {
                return '';
            }

            if ($dir !== $base && strpos($dir, $base . '/') !== 0) {
                return '';
            }

            if ($dir === $base) {
                // Gruppo misto (audio diretto + CDn): leggi solo i file diretti
                // del parent. I subtree non assegnati possono essere altri album.
                $entries = @scandir($dir);
                if ($entries === false) {
                    return '';
                }

                foreach ($entries as $entry) {
                    if (
                        $entry === '.'
                        || $entry === '..'
                        || ($entry !== '' && $entry[0] === '.')
                    ) {
                        continue;
                    }

                    if (!$appendPath($dir . '/' . $entry)) {
                        return '';
                    }
                }

                continue;
            }

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($it as $file) {
                if (!$appendPath($file->getPathname())) {
                    return '';
                }
            }
        }
    } catch (Throwable $e) {
        return '';
    }

    sort($rows, SORT_STRING);
    return sha1(implode("\n", $rows));
}

/**
 * Canonical scanner classification.
 *
 * Semantic input:
 *   - current filesystem candidate
 *   - current relational database
 *
 * Historical worker/readiness state is deliberately NOT an input.
 *
 * @return array<string,mixed>
 */
function classifyScanCandidate(
    PDO $db,
    AlbumReadinessService $evidenceService,
    AlbumIdentityResolver $identityResolver,
    AlbumAudioCoverageService $audioCoverageService,
    ExternalSourceOwnershipService $ownershipService,
    MediaRootRelocationService $rootRelocationService,
    string $importRoot,
    string $albumKey,
    array $candidateParts
): array {
    // Canonical active ownership is resolved before Readiness and Identity.
    // OWNED_EXACT / CONFLICT / INVALID are semantic terminal states.
    // UNAVAILABLE is observational only: the worker must WAIT without changing
    // canonical state. OWNED_PARTIAL is resolved
    // here from authoritative managed coverage first, then as MOVED/CHANGED.
    // Only UNOWNED may continue to Readiness/Identity.
    $ownership = $ownershipService->inspectCandidate(
        $importRoot,
        $albumKey
    );

    $ownershipState = strtoupper(trim((string)($ownership['state'] ?? '')));

    switch ($ownershipState) {
        case ExternalSourceOwnershipService::OWNED_EXACT:
            return [
                'classification' => 'SAME',
                'album_id' => (int)($ownership['album_id'] ?? 0),
                'reason' => 'external-source-set-identical',
                'identity_decision' => 'NOT_RUN',
                'ownership_state' => $ownershipState,
            ];

        case ExternalSourceOwnershipService::OWNED_PARTIAL:
            $ownedAlbumId = (int)($ownership['album_id'] ?? 0);

            if ($ownedAlbumId <= 0) {
                return [
                    'classification' => 'AMBIGUOUS',
                    'album_id' => null,
                    'reason' => 'ownership-partial-without-album-id',
                    'identity_decision' => 'NOT_RUN',
                    'ownership_state' => $ownershipState,
                ];
            }

            // OWNED_PARTIAL means active external ownership is known, but the
            // candidate is not an exact external-source set. Before treating it
            // as relocation/change, preserve the v7 managed-authority contract:
            // a mixed managed+external album whose tracks are all already covered
            // is SAME, and a missing managed file is AMBIGUOUS. Identity remains
            // deliberately NOT_RUN because ownership already established album_id.
            $coverage = $audioCoverageService->inspectCandidateCoverage(
                $ownedAlbumId,
                $albumKey
            );

            $managedRows = (int)($coverage['managed_rows'] ?? 0);
            $managedMissing = (int)($coverage['managed_missing_tracks'] ?? 0);
            $albumTrackCount = (int)($coverage['track_count'] ?? 0);
            $candidateAudioCount = (int)($ownership['audio_files'] ?? 0);

            if ($managedRows > 0 && $managedMissing > 0) {
                return [
                    'classification' => 'AMBIGUOUS',
                    'album_id' => $ownedAlbumId,
                    'reason' => 'managed-audio-missing',
                    'identity_decision' => 'NOT_RUN',
                    'ownership_state' => $ownershipState,
                ];
            }

            if (
                $managedRows > 0
                && $albumTrackCount > 0
                && $candidateAudioCount === $albumTrackCount
                && !empty($coverage['complete'])
            ) {
                return [
                    'classification' => 'SAME',
                    'album_id' => $ownedAlbumId,
                    'reason' => 'mixed-album-complete',
                    'identity_decision' => 'NOT_RUN',
                    'ownership_state' => $ownershipState,
                ];
            }

            // If managed coverage did not settle the candidate, a verified
            // content relocation is MOVED; otherwise fail closed as CHANGED.
            $relocation = $rootRelocationService->verifyCandidateRelocation(
                $importRoot,
                $albumKey,
                $ownedAlbumId
            );

            if (!empty($relocation['verified'])) {
                return [
                    'classification' => 'MOVED',
                    'album_id' => $ownedAlbumId,
                    'reason' => 'verified-relocation-by-hash',
                    'identity_decision' => 'NOT_RUN',
                    'ownership_state' => $ownershipState,
                ];
            }

            return [
                'classification' => 'CHANGED',
                'album_id' => $ownedAlbumId,
                'reason' => 'external-source-owned-partial:' . (string)($ownership['reason'] ?? '')
                    . ';relocation-unverified:' . (string)($relocation['reason'] ?? 'unknown'),
                'identity_decision' => 'NOT_RUN',
                'ownership_state' => $ownershipState,
            ];

        case ExternalSourceOwnershipService::UNAVAILABLE:
            return [
                'classification' => 'UNAVAILABLE',
                'album_id' => isset($ownership['album_id'])
                    && $ownership['album_id'] !== null
                        ? (int)$ownership['album_id']
                        : null,
                'reason' => 'ownership-unavailable:'
                    . (string)($ownership['reason'] ?? 'filesystem-unavailable'),
                'identity_decision' => 'NOT_RUN',
                'ownership_state' => $ownershipState,
            ];

        case ExternalSourceOwnershipService::CONFLICT:
        case ExternalSourceOwnershipService::INVALID:
            return [
                'classification' => 'AMBIGUOUS',
                'album_id' => null,
                'reason' => 'ownership-' . strtolower($ownershipState)
                    . ':' . (string)($ownership['reason'] ?? ''),
                'identity_decision' => 'NOT_RUN',
                'ownership_state' => $ownershipState,
            ];

        case ExternalSourceOwnershipService::UNOWNED:
            break;

        default:
            return [
                'classification' => 'AMBIGUOUS',
                'album_id' => null,
                'reason' => 'ownership-contract-invalid',
                'identity_decision' => 'NOT_RUN',
                'ownership_state' => $ownershipState,
            ];
    }

    // Build candidate evidence without persistent readiness state and without
    // network access. This is classification, not temporal readiness.
    $candidate = $evidenceService->analyzeCandidate(
        $importRoot,
        $albumKey,
        $candidateParts,
        0,
        true
    );

    $identity = $identityResolver->resolveCandidate(
        $candidate,
        $importRoot
    );

    $identityDecision = strtoupper(trim((string)(
        $identity['decision'] ?? ''
    )));

    if ($identityDecision === AlbumIdentityResolver::NEW_RELEASE) {
        return [
            'classification' => 'NEW',
            'album_id' => null,
            'reason' => 'identity-new-release',
            'identity_decision' => $identityDecision,
        ];
    }

    if (
        $identityDecision === AlbumIdentityResolver::AMBIGUOUS
        || $identityDecision === AlbumIdentityResolver::CONFLICT
    ) {
        return [
            'classification' => 'AMBIGUOUS',
            'album_id' => null,
            'reason' => 'identity-' . strtolower($identityDecision),
            'identity_decision' => $identityDecision,
        ];
    }

    if ($identityDecision !== AlbumIdentityResolver::EXACT_EXISTING) {
        return [
            'classification' => 'AMBIGUOUS',
            'album_id' => null,
            'reason' => 'identity-unresolved',
            'identity_decision' => $identityDecision,
        ];
    }

    $albumId = (int)($identity['album_id'] ?? 0);
    if ($albumId <= 0) {
        return [
            'classification' => 'AMBIGUOUS',
            'album_id' => null,
            'reason' => 'identity-existing-without-album-id',
            'identity_decision' => $identityDecision,
        ];
    }

    // Album-level DB facts. This also handles albums whose playable audio is
    // fully managed and therefore intentionally has no external source_path.
    $stats = $db->prepare("
        SELECT
            (SELECT COUNT(*)
             FROM tracks
             WHERE album_id = :tracks_album_id) AS track_count,
            SUM(CASE WHEN storage_type = 'managed' THEN 1 ELSE 0 END) AS managed_audio,
            SUM(CASE WHEN storage_type = 'external' THEN 1 ELSE 0 END) AS external_audio
        FROM audio_files
        WHERE album_id = :audio_album_id
    ");
    $stats->execute([
        ':tracks_album_id' => $albumId,
        ':audio_album_id' => $albumId,
    ]);
    $row = $stats->fetch(PDO::FETCH_ASSOC);
    $row = is_array($row) ? $row : [];

    $trackCount = (int)($row['track_count'] ?? 0);
    $managedAudio = (int)($row['managed_audio'] ?? 0);
    $externalAudio = (int)($row['external_audio'] ?? 0);
    $candidateTrackCount = (int)($identity['candidate_track_count'] ?? 0);

    // Quando esiste almeno un audio managed, la decisione SAME e' basata
    // sulla copertura per track, non sul tipo di storage dell'intero album.
    // Questo rende stabile anche il caso mixed managed+external.
    if (
        $trackCount > 0
        && $candidateTrackCount === $trackCount
        && $managedAudio > 0
    ) {
        $coverage = $audioCoverageService->inspectCandidateCoverage(
            $albumId,
            $albumKey
        );

        if ((int)($coverage['managed_missing_tracks'] ?? 0) > 0) {
            return [
                'classification' => 'AMBIGUOUS',
                'album_id' => $albumId,
                'reason' => 'managed-audio-missing',
                'identity_decision' => $identityDecision,
            ];
        }

        if (!empty($coverage['complete'])) {
            return [
                'classification' => 'SAME',
                'album_id' => $albumId,
                'reason' => $externalAudio > 0
                    ? 'mixed-album-complete'
                    : 'managed-album-complete',
                'identity_decision' => $identityDecision,
            ];
        }

        // Nessun managed risulta fisicamente mancante: le track non coperte
        // possono essere completate/reconciliate dalla normale pipeline CHANGED.
    }

    if ($externalAudio <= 0) {
        return [
            'classification' => 'CHANGED',
            'album_id' => $albumId,
            'reason' => 'existing-album-new-source',
            'identity_decision' => $identityDecision,
        ];
    }

    // Existing external album but current candidate did not match its persisted
    // source set. Distinguish a true move from a second/live source.
    $pathsStmt = $db->prepare("
        SELECT source_path
        FROM audio_files
        WHERE album_id = :album_id
          AND storage_type = 'external'
          AND source_path IS NOT NULL
          AND source_path <> ''
        ORDER BY id ASC
    ");
    $pathsStmt->execute([':album_id' => $albumId]);

    $liveOldSources = 0;
    $knownOldSources = 0;

    foreach ($pathsStmt->fetchAll(PDO::FETCH_COLUMN) as $oldPath) {
        $oldPath = normalizedConfiguredPath((string)$oldPath);
        if ($oldPath === '') {
            continue;
        }

        $knownOldSources++;

        $runtimeOldPath = runtimeScanPath($oldPath);
        if (
            $runtimeOldPath !== ''
            && is_file($runtimeOldPath)
            && is_readable($runtimeOldPath)
        ) {
            $liveOldSources++;
        }
    }

    if ($knownOldSources > 0 && $liveOldSources === 0) {
        $relocation = $rootRelocationService->verifyCandidateRelocation(
            $importRoot,
            $albumKey,
            $albumId
        );

        if (!empty($relocation['verified'])) {
            return [
                'classification' => 'MOVED',
                'album_id' => $albumId,
                'reason' => 'verified-relocation-by-hash',
                'identity_decision' => $identityDecision,
            ];
        }

        return [
            'classification' => 'CHANGED',
            'album_id' => $albumId,
            'reason' => 'relocation-unverified:'
                . (string)($relocation['reason'] ?? 'unknown'),
            'identity_decision' => $identityDecision,
        ];
    }

    return [
        'classification' => 'CHANGED',
        'album_id' => $albumId,
        'reason' => 'existing-album-source-differs',
        'identity_decision' => $identityDecision,
    ];
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

// Persistent candidate observation. AlbumReadinessService owns evidence and
// temporal readiness; the repository is persisted by the worker at cycle end.
$readinessStateFile = $root . '/storage/media-scan-readiness-state.json';
$readinessStateRepository = new AlbumReadinessStateRepository($readinessStateFile);
$readinessService = new AlbumReadinessService($db, $root);
$readinessService->setStateRepository($readinessStateRepository);

// Single authoritative semantic pipeline. The worker orchestrates the result;
// it no longer performs a second Readiness/Identity pass on actionable albums.
$decisionService = new AlbumScanDecisionService(
    $db,
    $readinessService,
    null,
    null,
    null,
    $rootRelocationService,
    null,
    $root
);

$importExecutor = new AlbumImportExecutor($db, $root);

$state   = loadWorkerState($stateFile);
// Una riscansione dopo cambio cartella interrotta da un riavvio resta
// segnalata a Settings finché non viene completata.
workerHeartbeatExtra(relocationRescanHeartbeat($state));
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
        // azzerava la cache del worker al primo ciclo di OGNI restart,
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
                workerLog(
                    '[CONFIG] Watched folder cambiata: rescan/relink '
                    . $boundConfiguredPath . ' -> ' . $configuredPath
                );

                // Riscansione della nuova cartella da segnalare a Settings
                // fino al primo ciclo completo. Il marcatore viene salvato
                // prima del rebind: se il processo cade dopo il COMMIT, il
                // ramo di recovery qui sotto lo ritrova nello state.
                $state['relocation_rescan'] = [
                    'path'  => $configuredPath,
                    'from'  => $boundConfiguredPath,
                    'since' => time(),
                ];
                saveWorkerState($stateFile, $state);
                workerHeartbeatExtra(relocationRescanHeartbeat($state));
                if ($heartbeatEnabled) {
                    writeWorkerHeartbeat($root, $enabled, $configuredPath);
                }

                // Il rebind adotta la nuova root e riallinea soltanto i path
                // dimostrati con evidenza conservativa. I riferimenti non
                // risolti restano storici e non bloccano la nuova scansione.
                try {
                    $relocation = $rootRelocationService->rebind(
                        $boundConfiguredPath,
                        $configuredPath
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

                // Una nuova root puo' avere una struttura completamente diversa.
                // Non migrare cache per path relativo: potrebbe associare stato storico
                // a contenuto differente. Ripartiamo
                // dalla discovery completa; il DB e l'identity resolver
                // ricostruiscono l'idempotenza in modo autorevole.
                $state = [
                    'configured_path' => $configuredPath,
                    'albums' => [],
                    'relocation_rescan' => $state['relocation_rescan'],
                ];
                saveWorkerState($stateFile, $state);

                // Anche la stability/readiness window deve ripartire sulla nuova
                // root. Un errore di salvataggio qui non annulla il rebind DB
                // gia' committato: il run successivo riprovera' il reset.
                try {
                    $readinessStateRepository->reset();
                    $readinessStateRepository->save();
                } catch (Throwable $e) {
                    workerLog(
                        '[WARN] Readiness state non azzerato dopo cambio root: '
                        . $e->getMessage()
                    );
                }

                // MediaImportService mantiene una cache per istanza della bound
                // root. Ricrearlo evita che il ciclo corrente continui a vedere
                // come vive le sorgenti della vecchia root.
                $service = new MediaImportService();

                workerLog(
                    '[CONFIG] Nuova watched folder adottata; rescan completo'
                    . ' | relink=' . (int)($relocation['audio_paths_rebound'] ?? 0)
                    . ' | relative=' . (int)($relocation['audio_paths_relative'] ?? 0)
                    . ' | hash=' . (int)($relocation['audio_paths_hash'] ?? 0)
                    . ' | unresolved=' . (int)($relocation['audio_paths_unresolved'] ?? 0)
                    . ' | outside_old_root=' . (int)($relocation['audio_paths_outside_old_root'] ?? 0)
                    . ' | hash_ambiguous=' . (int)($relocation['audio_hash_ambiguous'] ?? 0)
                    . ' | ignored=' . (int)($relocation['ignored_paths_rebound'] ?? 0)
                );
            } elseif ($stateConfiguredPath !== $configuredPath) {
                // Il DB e' gia' sulla root corrente (es. crash subito dopo il
                // COMMIT del rebind) ma il cache state e' vecchio. Non tentare
                // una migrazione per path relativo: ricostruisci da discovery.
                // Il marcatore di riscansione resta solo se riguarda questa
                // stessa cartella.
                $pendingRescan = isset($state['relocation_rescan'])
                    && is_array($state['relocation_rescan'])
                    && (string)($state['relocation_rescan']['path'] ?? '') === $configuredPath
                        ? $state['relocation_rescan']
                        : null;

                $state = [
                    'configured_path' => $configuredPath,
                    'albums' => [],
                ];
                if ($pendingRescan !== null) {
                    $state['relocation_rescan'] = $pendingRescan;
                }
                saveWorkerState($stateFile, $state);
                workerHeartbeatExtra(relocationRescanHeartbeat($state));

                try {
                    $readinessStateRepository->reset();
                    $readinessStateRepository->save();
                } catch (Throwable $e) {
                    workerLog(
                        '[WARN] Readiness state non azzerato durante recovery root: '
                        . $e->getMessage()
                    );
                }

                $service = new MediaImportService();
                workerLog(
                    '[CONFIG] Worker state azzerato sulla watched folder corrente; '
                    . 'rescan completo richiesto.'
                );
            } else {
                $state['configured_path'] = $configuredPath;
                if (!isset($state['albums']) || !is_array($state['albums'])) {
                    $state['albums'] = [];
                }

                // Cambio annullato prima del rebind (tornati alla cartella
                // vincolata): il marcatore riguarda un'altra cartella.
                if (isset($state['relocation_rescan'])
                    && (string)($state['relocation_rescan']['path'] ?? '') !== $configuredPath) {
                    unset($state['relocation_rescan']);
                    workerHeartbeatExtra([]);
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
                workerLog(
                    '[EXCLUDED] Stato scanner rimosso: '
                    . MediaScanWorkerStatePolicy::label((string)$knownAlbumKey, $realImport)
                );
            }
        }
    }

    $now = time();

    // Discovery must be fail-closed. A removable/network filesystem can remain
    // mounted while one or more subdirectories temporarily stop responding.
    // A partial list must never be interpreted as authoritative absence.
    try {
        // Una sola osservazione filesystem autorevole per ciclo: candidate key,
        // parts e signature provengono dalla stessa traversata. L'API mantiene
        // un fallback esplicito per i rari casi non riproducibili senza il
        // candidateSnapshot storico.
        $discoveryObservation = $service->discoverAlbumCandidatesWithSignatures(
            $realImport,
            true
        );

        if (
            !isset($discoveryObservation['candidates'])
            || !is_array($discoveryObservation['candidates'])
            || !isset($discoveryObservation['signatures'])
            || !is_array($discoveryObservation['signatures'])
            || !isset($discoveryObservation['signature_fallback'])
            || !is_array($discoveryObservation['signature_fallback'])
        ) {
            throw new RuntimeException(
                'Discovery single-pass returned an invalid observation payload.'
            );
        }

        $albumCandidates = $discoveryObservation['candidates'];
        $albumSignatures = $discoveryObservation['signatures'];
        $albumSignatureFallback =
            $discoveryObservation['signature_fallback'];
    } catch (Throwable $e) {
        workerLog(
            '[WAIT] Discovery filesystem non affidabile: ' . $e->getMessage()
        );

        if ($heartbeatEnabled) {
            writeWorkerHeartbeat($root, $enabled, $configuredPath);
        }

        if ($options['once']) {
            break;
        }

        $stopReason = workerSleepWithHeartbeat(
            $root,
            $interval,
            $enabled,
            $configuredPath,
            $heartbeatEnabled,
            $managedStopReason
        );
        if ($stopReason !== '') {
            break;
        }
        continue;
    }

    $stillAlive = [];
    $cycleFilesystemHealthy = true;

    // Una sola fotografia semantica del DB per ciclo. Il fast-path e' ammesso
    // soltanto se coincide con quella salvata al termine del ciclo precedente.
    $cycleDbRevision = scannerSemanticDbRevision($db);
    $previousDbRevision = isset($state['semantic_db_revision'])
        ? (string)$state['semantic_db_revision']
        : '';
    $dbRevisionUnchanged = $cycleDbRevision !== ''
        && $previousDbRevision !== ''
        && hash_equals($previousDbRevision, $cycleDbRevision);
    $cycleDbMutated = false;

    // Candidati ancora nella finestra di stabilità del worker (nuovi,
    // modificati o non ancora stabili). Un ciclo completo senza candidati
    // in questa condizione chiude la riscansione dopo un cambio cartella.
    $cycleWaitingCandidates = 0;
    // Un errore applicativo (gate/executor) significa che il ciclo ha visto
    // tutta la root ma non l'ha elaborata completamente: il box relocation
    // deve restare in corso e il ciclo successivo deve poter riprovare.
    $cycleRescanHadError = false;

    foreach ($albumCandidates as $albumKey => $candidateParts) {
        $albumKey = rtrim(str_replace('\\', '/', (string)$albumKey), '/');
        $albumLabel = MediaScanWorkerStatePolicy::label($albumKey, $realImport);

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
                workerLog('[IGNORED] Stato scanner azzerato: ' . $albumLabel);
            }
            continue;
        }

        $stillAlive[$albumKey] = true;

        if (isset($state['albums'][$albumKey]) && is_array($state['albums'][$albumKey])) {
            $state['albums'][$albumKey] = MediaScanWorkerStatePolicy::markSeen(
                $state['albums'][$albumKey]
            );
        }

        if (
            array_key_exists($albumKey, $albumSignatures)
            && is_string($albumSignatures[$albumKey])
            && !isset($albumSignatureFallback[$albumKey])
        ) {
            $signature = (string)$albumSignatures[$albumKey];
        } else {
            // Compatibility fallback only: the normal path receives its
            // signature from the same filesystem observation as discovery.
            $signature = candidateSnapshot($albumKey, $candidateParts);
        }

        if ($signature === '') {
            $cycleFilesystemHealthy = false;
            workerLog(
                '[WAIT] ' . $albumLabel
                . ' | snapshot non leggibile; ciclo state non potabile'
            );
            break;
        }

        $previous = isset($state['albums'][$albumKey]) && is_array($state['albums'][$albumKey])
            ? $state['albums'][$albumKey]
            : [];

        $signatureChanged = !isset($previous['signature'])
            || !hash_equals((string)$previous['signature'], $signature);

        // IMPORTANT:
        // Worker state remains cache/telemetry, never semantic authority.
        // A historical SAME can only be reused through the guarded predicate
        // below, which also binds the decision to the DB revision that produced
        // it. Without that binding a reverse relocation could revive stale state.

        // Safe SAME fast-path. Unlike the older shortcut, the cached decision
        // is bound to the exact semantic DB revision against which it was
        // calculated. A reverse relocation or any other semantic DB change
        // therefore invalidates the cache before ownership/classification can
        // be skipped. Worker state remains an optimization only (I09/I20).
        if (workerCanReuseCanonicalSame(
            $previous,
            $signatureChanged,
            $cycleDbRevision,
            $dbRevisionUnchanged,
            $cycleDbMutated
        )) {
            $state['albums'][$albumKey]['last_scan'] = $now;
            continue;
        }

        // Un RECONCILE gia' deferito non va rieseguito a ogni polling. DEFERRED
        // significa che il target possiede ancora vecchie sorgenti external vive:
        // la transizione utile e' locale al candidato/album target, non globale.
        //
        // Quindi il fast-path dipende da:
        // - signature filesystem del candidato invariata;
        // - revisione semantica del SOLO album target invariata;
        // - nessun audio managed comparso sul target;
        // - tutte le vecchie sorgenti che causarono il DEFER ancora vive.
        //
        // Modifiche ad album estranei NON devono risvegliare questo DEFER.
        if (
            !$signatureChanged
            && (($previous['last_result'] ?? '') === 'deferred')
            && strtoupper((string)($previous['canonical_class'] ?? '')) === 'CHANGED'
            && (int)($previous['canonical_album_id'] ?? 0) > 0
        ) {
            $targetAlbumId = (int)$previous['canonical_album_id'];
            $waiting = isset($previous['deferred_sources'])
                && is_array($previous['deferred_sources'])
                    ? $previous['deferred_sources']
                    : [];
            $storedTargetRevision = trim((string)(
                $previous['deferred_target_revision'] ?? ''
            ));
            $currentTargetRevision = $storedTargetRevision !== ''
                ? scannerAlbumSemanticRevision($db, $targetAlbumId)
                : '';
            $targetRevisionUnchanged = $storedTargetRevision !== ''
                && $currentTargetRevision !== ''
                && hash_equals($storedTargetRevision, $currentTargetRevision);

            if (!empty($waiting) && $targetRevisionUnchanged) {
                $managedCount = 1;
                try {
                    $managedStmt = $db->prepare(
                        "SELECT COUNT(*) FROM audio_files "
                        . "WHERE album_id = :album_id AND storage_type = 'managed'"
                    );
                    $managedStmt->execute([
                        ':album_id' => $targetAlbumId,
                    ]);
                    $managedCount = (int)$managedStmt->fetchColumn();
                } catch (Throwable $e) {
                    $managedCount = 1;
                }

                if ($managedCount === 0) {
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
                        $state['albums'][$albumKey]['last_scan'] = $now;
                        continue;
                    }
                }
            }
        }

        // Se uno dei due input semantici e' cambiato, ricostruiamo sempre la
        // decisione canonica attraverso l'unica pipeline autorevole. Lo state
        // accelera soltanto il no-op e non partecipa alla semantica.
        try {
            $canonical = $decisionService->evaluate(
                $realImport,
                $albumKey,
                is_array($candidateParts) ? $candidateParts : [],
                $stable
            );
        } catch (Throwable $e) {
            $state['albums'][$albumKey] = array_merge($previous, [
                'signature' => $signature,
                'last_result' => 'error',
                'last_scan' => $now,
            ]);
            $cycleRescanHadError = true;

            workerLog(
                '[ERROR] ' . $albumLabel
                . ' | classification: ' . $e->getMessage()
            );
            continue;
        }

        $canonicalClass = strtoupper(trim((string)(
            $canonical['classification'] ?? 'AMBIGUOUS'
        )));
        $canonicalAlbumId = isset($canonical['album_id'])
            && $canonical['album_id'] !== null
                ? (int)$canonical['album_id']
                : null;
        $canonicalReason = trim((string)(
            $canonical['reason'] ?? ''
        ));

        // A removable/network filesystem can remain mounted while directory
        // entries or files are temporarily unavailable (for example during an
        // SMB/Wi-Fi reconnect). This is not a semantic album decision. Preserve
        // the previous canonical state, mark this cycle unhealthy and retry only
        // after the filesystem becomes observable again.
        if ($canonicalClass === 'UNAVAILABLE') {
            $cycleFilesystemHealthy = false;
            $state['albums'][$albumKey] = array_merge($previous, [
                'last_scan' => $now,
            ]);

            workerLog(
                '[WAIT] ' . $albumLabel
                . ' | filesystem non affidabile: ' . $canonicalReason
            );
            break;
        }

        $canonicalKey = implode('|', [
            $canonicalClass,
            $canonicalAlbumId !== null ? (string)$canonicalAlbumId : '',
            $canonicalReason,
        ]);
        $previousCanonicalKey = (string)($previous['canonical_key'] ?? '');

        // Persistiamo il risultato solo come cache verificabile. Al prossimo
        // ciclo sara' riusato esclusivamente se FS + DB sono identici.
        // Bind the cached canonical decision to the semantic DB snapshot used
        // to calculate it. If this cycle already mutated the DB, the revision
        // captured at cycle start is stale and must NOT authorize a future skip.
        $canonicalDbRevisionForState = (!$cycleDbMutated && $cycleDbRevision !== '')
            ? $cycleDbRevision
            : '';

        $previous = array_merge($previous, [
            'signature' => $signature,
            'canonical_class' => $canonicalClass,
            'canonical_album_id' => $canonicalAlbumId,
            'canonical_reason' => $canonicalReason,
            'canonical_key' => $canonicalKey,
            'canonical_db_revision' => $canonicalDbRevisionForState,
        ]);
        $state['albums'][$albumKey] = $previous;

        // Non inondare il log con la stessa classificazione a ogni polling.
        if ($previousCanonicalKey !== $canonicalKey) {
            workerLog(
                '[CLASSIFY] ' . $albumLabel
                . ' | class=' . $canonicalClass
                . ($canonicalAlbumId !== null
                    ? ' album_id=' . $canonicalAlbumId
                    : '')
                . ($canonicalReason !== ''
                    ? ' reason=' . $canonicalReason
                    : '')
            );
        }

        if ($canonicalClass === 'SAME') {
            $state['albums'][$albumKey] = array_merge($previous, [
                'last_result' => 'ok',
                'last_scan' => $now,
                'deferred_sources' => [],
                'deferred_target_revision' => null,
            ]);
            continue;
        }

        if ($canonicalClass === 'AMBIGUOUS') {
            $state['albums'][$albumKey] = array_merge($previous, [
                'signature' => $signature,
                'last_result' => 'review',
                'last_scan' => $now,
                'deferred_sources' => [],
                'deferred_target_revision' => null,
            ]);

            if ($previousCanonicalKey !== $canonicalKey) {
                workerLog(
                    '[REVIEW] ' . $albumLabel
                    . ' | canonical='
                    . ($canonicalReason !== '' ? $canonicalReason : 'ambiguous')
                );
            }
            continue;
        }

        if ($canonicalClass === 'MOVED') {
            if ($canonicalAlbumId === null || $canonicalAlbumId <= 0) {
                $state['albums'][$albumKey] = array_merge($previous, [
                    'signature' => $signature,
                    'last_result' => 'error',
                    'last_scan' => $now,
                ]);
                $cycleRescanHadError = true;
                workerLog(
                    '[ERROR] ' . $albumLabel
                    . ' | MOVED senza album_id valido'
                );
                continue;
            }

            try {
                $relocationFormatId = scannerRelocationFormatId(
                    $db,
                    $albumKey,
                    $realImport
                );

                $relocationResult = $rootRelocationService
                    ->applyCandidateRelocation(
                        $realImport,
                        $albumKey,
                        $canonicalAlbumId
                    );

                // MOVED riallinea il SOLO formato automatico dello scanner.
                // Se esiste un formato manuale, Album::syncScannerFormat()
                // non lo modifica: la scelta dell'utente resta autorevole,
                // anche quando il path ricadrebbe nel fallback Digital.
                if ($relocationFormatId !== null && $relocationFormatId > 0) {
                    $albumModel = new Album();
                    $albumModel->syncScannerFormat(
                        $canonicalAlbumId,
                        $relocationFormatId
                    );
                }
            } catch (Throwable $e) {
                $state['albums'][$albumKey] = array_merge($previous, [
                    'signature' => $signature,
                    'last_result' => 'error',
                    'last_scan' => $now,
                ]);
                $cycleRescanHadError = true;
                workerLog(
                    '[ERROR] ' . $albumLabel
                    . ' | relocation: ' . $e->getMessage()
                );
                continue;
            }

            $state['albums'][$albumKey] = array_merge($previous, [
                'signature' => $signature,
                'last_result' => 'ok',
                'last_scan' => $now,
                'deferred_sources' => [],
                'deferred_target_revision' => null,
            ]);
            $cycleDbMutated = true;

            workerLog(
                '[MOVED] ' . $albumLabel
                . ' | reconciled='
                . (int)($relocationResult['updated_files'] ?? 0)
            );
            continue;
        }

        if (!in_array(
            $canonicalClass,
            ['NEW', 'CHANGED'],
            true
        )) {
            $state['albums'][$albumKey] = array_merge($previous, [
                'signature' => $signature,
                'last_result' => 'error',
                'last_scan' => $now,
            ]);
            $cycleRescanHadError = true;

            workerLog(
                '[ERROR] ' . $albumLabel
                . ' | classificazione sconosciuta=' . $canonicalClass
            );
            continue;
        }

        // NEW/CHANGED hanno già attraversato la pipeline lineare una sola
        // volta. Riutilizziamo ESATTAMENTE il candidate snapshot e il gate
        // prodotti da AlbumScanDecisionService: nessuna seconda Readiness e
        // nessuna seconda Identity nel worker.
        $readinessCandidate = isset($canonical['candidate'])
            && is_array($canonical['candidate'])
                ? $canonical['candidate']
                : null;
        $gateResult = isset($canonical['gate_result'])
            && is_array($canonical['gate_result'])
                ? $canonical['gate_result']
                : null;

        if ($readinessCandidate === null || $gateResult === null) {
            $state['albums'][$albumKey]['last_result'] = 'error';
            $state['albums'][$albumKey]['last_scan'] = $now;
            $cycleRescanHadError = true;

            workerLog(
                '[ERROR] ' . $albumLabel
                . ' | decision pipeline incompleta per ' . $canonicalClass
            );
            continue;
        }

        $readinessStatus = strtoupper(
            trim((string)($readinessCandidate['status'] ?? ''))
        );
        $candidateSignature = trim(
            (string)($readinessCandidate['candidate_signature'] ?? '')
        );

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
            unset($state['albums'][$albumKey]['deferred_target_revision']);
            $state['albums'][$albumKey]['readiness_status'] = $readinessStatus;
            $state['albums'][$albumKey]['candidate_signature'] =
                $candidateSignature;
            $state['albums'][$albumKey]['gate_key'] = $gateKey;

            if ($previousGateKey !== $gateKey) {
                workerLog(
                    '[' . ($readinessStatus !== '' ? $readinessStatus : 'PENDING')
                    . '] ' . $albumLabel
                    . ($gateReason !== '' ? ' | ' . $gateReason : '')
                );
            }
            continue;
        }

        if ($gateAction === 'REVIEW') {
            $state['albums'][$albumKey]['last_result'] = 'review';
            $state['albums'][$albumKey]['last_scan'] = $now;
            $state['albums'][$albumKey]['deferred_sources'] = [];
            unset($state['albums'][$albumKey]['deferred_target_revision']);
            $state['albums'][$albumKey]['readiness_status'] = $readinessStatus;
            $state['albums'][$albumKey]['candidate_signature'] =
                $candidateSignature;
            $state['albums'][$albumKey]['gate_key'] = $gateKey;

            if ($previousGateKey !== $gateKey) {
                $identityDecision = (string)(
                    $gateResult['identity']['decision'] ?? 'NOT_RUN'
                );

                workerLog(
                    '[REVIEW] ' . $albumLabel
                    . ' | reason=' . $gateReason
                    . ' | identity=' . $identityDecision
                );
            }
            continue;
        }

        if (!in_array($gateAction, ['CREATE', 'RECONCILE'], true)) {
            $state['albums'][$albumKey]['last_result'] = 'error';
            $state['albums'][$albumKey]['last_scan'] = $now;
            $cycleRescanHadError = true;

            workerLog(
                '[ERROR] ' . $albumLabel
                . ' | azione gate sconosciuta=' . $gateAction
            );
            continue;
        }

        $plan = $gateResult['plan'] ?? null;
        if (!$plan instanceof AlbumImportPlan) {
            $state['albums'][$albumKey]['last_result'] = 'error';
            $state['albums'][$albumKey]['last_scan'] = $now;
            $cycleRescanHadError = true;

            workerLog(
                '[ERROR] ' . $albumLabel
                . ' | piano import assente per ' . $gateAction
            );
            continue;
        }

        if (
            $canonicalClass === 'NEW'
            && $gateAction !== 'CREATE'
        ) {
            $state['albums'][$albumKey]['last_result'] = 'review';
            $state['albums'][$albumKey]['last_scan'] = $now;
            workerLog(
                '[REVIEW] ' . $albumLabel
                . ' | canonical NEW incompatible with gate=' . $gateAction
            );
            continue;
        }

        if (
            $canonicalClass === 'CHANGED'
            && (
                $gateAction !== 'RECONCILE'
                || $canonicalAlbumId === null
                || $canonicalAlbumId <= 0
                || $plan->targetAlbumId() !== $canonicalAlbumId
            )
        ) {
            $state['albums'][$albumKey]['last_result'] = 'review';
            $state['albums'][$albumKey]['last_scan'] = $now;
            workerLog(
                '[REVIEW] ' . $albumLabel
                . ' | canonical CHANGED target mismatch'
            );
            continue;
        }

        workerLog(
            '[IMPORT] ' . $albumLabel
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
            $cycleRescanHadError = true;

            workerLog(
                '[ERROR] ' . $albumLabel
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
            $cycleDbMutated = true;
            $state['albums'][$albumKey]['last_result'] = 'ok';
            $state['albums'][$albumKey]['deferred_sources'] = [];
            unset($state['albums'][$albumKey]['deferred_target_revision']);

            workerLog(
                '[OK] ' . $albumLabel
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

            $deferredTargetRevision = $canonicalAlbumId !== null
                && $canonicalAlbumId > 0
                    ? scannerAlbumSemanticRevision($db, $canonicalAlbumId)
                    : '';

            if ($deferredTargetRevision !== '') {
                $state['albums'][$albumKey]['deferred_target_revision'] =
                    $deferredTargetRevision;
            } else {
                unset($state['albums'][$albumKey]['deferred_target_revision']);
            }

            workerLog(
                '[DEFER] ' . $albumLabel
                . ' | external in attesa='
                . (int)($entry['audio_deferred'] ?? 0)
                . ' | sorgenti monitorate=' . count($waitSources)
            );
        } elseif ($executionStatus === 'review') {
            $state['albums'][$albumKey]['last_result'] = 'review';
            $state['albums'][$albumKey]['deferred_sources'] = [];
            unset($state['albums'][$albumKey]['deferred_target_revision']);

            workerLog(
                '[REVIEW] ' . $albumLabel
                . ' | executor='
                . (string)($entry['execution_reason'] ?? 'review')
            );
        } else {
            $state['albums'][$albumKey]['last_result'] = 'error';
            $cycleRescanHadError = true;

            workerLog(
                '[ERROR] ' . $albumLabel
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

    // Worker state is a cache. Absence from one discovery pass is not proof of
    // deletion: removable/network roots can become partially visible while the
    // mount point itself still exists. Only a healthy cycle may advance the
    // delayed cleanup policy, and even then deletion requires repeated explicit
    // absence from a readable parent directory plus a grace period.
    if ($stopReason === '' && $cycleFilesystemHealthy) {
        foreach (array_keys($state['albums']) as $known) {
            if (isset($stillAlive[$known])) {
                continue;
            }

            $entry = isset($state['albums'][$known])
                && is_array($state['albums'][$known])
                    ? $state['albums'][$known]
                    : [];

            $missingDecision = MediaScanWorkerStatePolicy::evaluateMissing(
                $entry,
                (string)$known,
                $realImport,
                $now
            );

            if (!empty($missingDecision['remove'])) {
                unset($state['albums'][$known]);
                workerLog(
                    '[STATE] ' . MediaScanWorkerStatePolicy::label(
                        (string)$known,
                        $realImport
                    )
                    . ' | state obsoleto rimosso dopo assenza confermata'
                );
                continue;
            }

            $state['albums'][$known] = $missingDecision['entry'];
        }
    } elseif (!$cycleFilesystemHealthy) {
        workerLog(
            '[STATE] Cleanup state differito: ciclo filesystem non affidabile.'
        );
    }

    $state['configured_path'] = $configuredPath;

    // La riscansione può chiudersi soltanto dopo un ciclo completo e sano.
    // La rimozione effettiva del marcatore avviene DOPO il salvataggio del
    // readiness state: un errore di persistenza non deve far diventare verde
    // il box mentre il worker sta per fermarsi.
    $rescanCanComplete = isset($state['relocation_rescan'])
        && $stopReason === ''
        && $cycleFilesystemHealthy
        && !$cycleRescanHadError
        && $cycleWaitingCandidates === 0;

    $readinessSaveError = '';
    try {
        $readinessStateRepository->save();
    } catch (Throwable $e) {
        $readinessSaveError = $e->getMessage();
        workerLog('[ERROR] Readiness state non salvato: ' . $readinessSaveError);
    }

    if ($rescanCanComplete && $readinessSaveError === '') {
        unset($state['relocation_rescan']);
        workerHeartbeatExtra([]);
        workerLog('[CONFIG] Riscansione della nuova watched folder completata.');
    }

    // Il fast-path del ciclo successivo e' valido solo se il DB non e' cambiato
    // durante questo ciclo. Una scrittura dello scanner o una modifica concorrente
    // invalida il fingerprint: il prossimo ciclo fara' una classificazione piena
    // e ricostruira' la cache da fonti autorevoli.
    if (!$cycleDbMutated && $cycleDbRevision !== '') {
        $endDbRevision = scannerSemanticDbRevision($db);
        if ($endDbRevision !== '' && hash_equals($cycleDbRevision, $endDbRevision)) {
            $state['semantic_db_revision'] = $endDbRevision;
        } else {
            unset($state['semantic_db_revision']);
        }
    } else {
        unset($state['semantic_db_revision']);
    }

    // Il worker state viene comunque salvato anche se il readiness state fallisce:
    // contiene solo cache/telemetria e deve restare coerente con il ciclo appena eseguito.
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

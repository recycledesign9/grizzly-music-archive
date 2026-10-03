<?php

/**
 * MediaScanWorkerSupervisor
 *
 * Avvia e sorveglia media-scan-worker.php nelle installazioni senza Docker
 * (MAMP, LAMP, Apache locale), così che l'utente non debba lanciare comandi,
 * creare file .plist/.service o configurare servizi del sistema operativo.
 *
 * Ciclo di vita scelto
 * --------------------
 * Il worker legge la configurazione dal DB a ogni ciclo e scrive nel DB di
 * Grizzly: senza Apache/MySQL attivi non ha nulla da fare. Per questo non viene
 * registrato come servizio di sistema, ma segue il ciclo di vita del server
 * Grizzly, come già accade in Docker con il servizio `worker`:
 *
 *   - parte alla prima richiesta servita da Grizzly quando la scansione è
 *     attiva, oppure subito quando l'utente la attiva da Impostazioni;
 *   - è un processo staccato dalla richiesta HTTP: chiudere il browser non lo
 *     ferma;
 *   - si ferma da solo (modalità --managed) quando la scansione viene
 *     disattivata, quando il database non è più raggiungibile (server
 *     spento), quando il codice di Grizzly cambia (aggiornamento) o quando
 *     l'installazione viene rimossa; dopo un aggiornamento la richiesta
 *     successiva lo riavvia con il codice nuovo.
 *
 * Nessun file viene scritto fuori da BASE_PATH/storage, quindi la
 * disinstallazione consiste nel rimuovere la cartella di Grizzly.
 *
 * In Docker il worker è già un servizio Compose con restart automatico:
 * il supervisore non avvia mai nulla e si limita a riportarne lo stato.
 *
 * Compatibile PHP 7.4.
 *
 * Posizionare in: app/services/MediaScanWorkerSupervisor.php
 */
class MediaScanWorkerSupervisor
{
    /** Età massima dell'heartbeat per considerare vivo il worker. */
    const HEARTBEAT_MAX_AGE = 60;

    /** Intervallo minimo tra due controlli eseguiti dalle richieste web. */
    const CHECK_INTERVAL = 15;

    /** Secondi concessi a un worker appena avviato per scrivere l'heartbeat. */
    const START_GRACE = 45;

    /** Attesa massima tra due tentativi di avvio falliti. */
    const MAX_BACKOFF = 900;

    /**
     * Anche un avvio forzato non parte se un altro avvio è avvenuto in
     * questo intervallo: nella stessa richiesta tick() può averlo già fatto.
     */
    const MIN_SPAWN_GAP = 5;

    /** Oltre questa dimensione il log viene ruotato al successivo avvio. */
    const LOG_MAX_BYTES = 2097152;

    // ------------------------------------------------------------------
    // Percorsi (tutti dentro storage/, già escluso da git e da Docker build)
    // ------------------------------------------------------------------

    private static function storageDir(): string
    {
        return BASE_PATH . '/storage';
    }

    private static function heartbeatFile(): string
    {
        return self::storageDir() . '/media-scan-worker-heartbeat.json';
    }

    /** Lock d'installazione tenuto dal worker per tutta la sua vita. */
    private static function installLockFile(): string
    {
        return self::storageDir() . '/media-scan-worker.lock';
    }

    private static function supervisorStateFile(): string
    {
        return self::storageDir() . '/media-scan-worker-supervisor.json';
    }

    private static function supervisorLockFile(): string
    {
        return self::storageDir() . '/media-scan-worker-supervisor.lock';
    }

    /** TMPDIR del worker gestito: lock e state persistenti dell'installazione. */
    private static function workerStateDir(): string
    {
        return self::storageDir() . '/media-scan-worker';
    }

    private static function logFile(): string
    {
        return self::storageDir() . '/logs/media-scan-worker.log';
    }

    private static function workerScript(): string
    {
        return BASE_PATH . '/media-scan-worker.php';
    }

    // ------------------------------------------------------------------
    // Modalità
    // ------------------------------------------------------------------

    /**
     * True quando il worker è gestito dal container (servizio Compose).
     * MEDIA_SCAN_HOST_PREFIX è fissato nel docker-compose.yml di Grizzly;
     * i file marker coprono anche compose personalizzati e Podman.
     */
    public static function isContainerManaged(): bool
    {
        $prefix = getenv('MEDIA_SCAN_HOST_PREFIX');
        if ($prefix !== false && trim($prefix) !== '') {
            return true;
        }

        return @is_file('/.dockerenv') || @is_file('/run/.containerenv');
    }

    /**
     * Opt-out per chi preferisce gestire il worker con un proprio servizio
     * (systemd, launchd): GRIZZLY_WORKER_AUTOSTART=0 nel file .env.
     */
    private static function autostartAllowed(): bool
    {
        $raw = function_exists('_env') ? _env('GRIZZLY_WORKER_AUTOSTART', '1') : '1';
        $v = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $v === null ? true : $v;
    }

    public static function mode(): string
    {
        if (self::isContainerManaged()) {
            return 'docker';
        }
        return self::autostartAllowed() ? 'managed' : 'manual';
    }

    // ------------------------------------------------------------------
    // Hook leggero per ogni richiesta web (index.php)
    // ------------------------------------------------------------------

    /**
     * Da chiamare prima di session_start(): il processo figlio non deve
     * ereditare il file di sessione bloccato.
     * Non lancia mai eccezioni e nel caso normale costa una stat().
     */
    public static function tick(): void
    {
        try {
            if (PHP_SAPI === 'cli' || self::mode() !== 'managed') {
                return;
            }

            $stateFile = self::supervisorStateFile();
            $lastCheck = @filemtime($stateFile);
            if ($lastCheck !== false && (time() - $lastCheck) < self::CHECK_INTERVAL) {
                return;
            }

            if (self::isWorkerAlive()) {
                self::touchState();
                return;
            }

            if (!self::scanEnabled()) {
                self::touchState();
                return;
            }

            self::ensureRunning(false);
        } catch (Throwable $e) {
            // La supervisione non deve mai interrompere una pagina di Grizzly.
        }
    }

    // ------------------------------------------------------------------
    // Avvio
    // ------------------------------------------------------------------

    /**
     * Avvia il worker se serve. Con $force ignora l'attesa tra tentativi
     * falliti (azione esplicita dell'utente da Impostazioni).
     * Il chiamante deve aver già chiuso la sessione PHP.
     *
     * @return array stato come status()
     */
    public static function ensureRunning(bool $force): array
    {
        if (self::mode() !== 'managed') {
            return self::status();
        }

        if (!self::ensureDir(self::storageDir())) {
            self::saveState(array_merge(self::loadState(), [
                'last_error' => 'La cartella storage/ di Grizzly non è scrivibile dal server web.',
                'last_error_kind' => 'unavailable',
                'checked_at' => time(),
            ]));
            return self::status(true);
        }

        $lock = @fopen(self::supervisorLockFile(), 'c');
        if (!$lock) {
            return self::status(true);
        }

        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            // Un'altra richiesta sta già avviando il worker.
            @fclose($lock);
            return self::status(true);
        }

        try {
            $state = self::loadState();
            $state['checked_at'] = time();

            if (self::isWorkerAlive()) {
                $state['failures'] = 0;
                $state['last_error'] = '';
                $state['last_error_kind'] = '';
                self::saveState($state);
                return self::status(true);
            }

            $now = time();
            $lastSpawn = (int)($state['last_spawn_at'] ?? 0);
            $heartbeat = self::readHeartbeat();
            $cameUp = $heartbeat['timestamp'] !== null && $heartbeat['timestamp'] >= $lastSpawn;

            if ($lastSpawn > 0 && !$cameUp && ($now - $lastSpawn) < self::MIN_SPAWN_GAP) {
                // Avvio appena eseguito (anche nella stessa richiesta):
                // non lanciarne un secondo, nemmeno su richiesta esplicita.
                self::saveState($state);
                return self::status(true);
            }

            if ($lastSpawn > 0 && !$cameUp) {
                if (!$force && ($now - $lastSpawn) < self::START_GRACE) {
                    // Avvio in corso: non lanciarne un secondo. Con $force si
                    // riprova comunque: un eventuale doppione esce subito
                    // trovando il lock d'installazione occupato.
                    self::saveState($state);
                    return self::status(true);
                }

                if ((int)($state['failure_recorded_for'] ?? 0) !== $lastSpawn) {
                    $state['failures'] = (int)($state['failures'] ?? 0) + 1;
                    $state['failure_recorded_for'] = $lastSpawn;
                    $state['last_error'] = 'Il worker è stato avviato ma non ha segnalato la propria attività.';
                    $state['last_error_kind'] = 'failed';
                }

                $delay = self::backoffDelay((int)$state['failures']);
                if (!$force && ($now - $lastSpawn) < (self::START_GRACE + $delay)) {
                    self::saveState($state);
                    return self::status(true);
                }
            } elseif ($cameUp) {
                $state['failures'] = 0;
            }

            if ($force) {
                $state['failures'] = 0;
            }

            $problem = self::spawnUnavailableReason();
            if ($problem !== '') {
                $state['last_error'] = $problem;
                $state['last_error_kind'] = 'unavailable';
                self::saveState($state);
                return self::status(true);
            }

            $php = self::findPhpCli($state);
            if ($php === '') {
                $state['last_error'] = 'Non è stato trovato un eseguibile PHP da riga di comando (7.4 o successivo, con pdo_mysql).';
                $state['last_error_kind'] = 'unavailable';
                self::saveState($state);
                return self::status(true);
            }

            if (!self::ensureDir(self::workerStateDir()) || !self::ensureDir(dirname(self::logFile()))) {
                $state['last_error'] = 'Impossibile creare le cartelle del worker in storage/.';
                $state['last_error_kind'] = 'unavailable';
                self::saveState($state);
                return self::status(true);
            }

            self::rotateLogIfNeeded();
            self::seedLegacyWorkerState();

            if (!self::spawn($php)) {
                $state['last_error'] = 'Il sistema non ha permesso di avviare il processo del worker.';
                $state['last_error_kind'] = 'unavailable';
                self::saveState($state);
                return self::status(true);
            }

            $state['last_spawn_at'] = time();
            // Ogni avvio viene valutato una sola volta: il marcatore riparte
            // da zero, così anche un avvio fallito nello stesso secondo di un
            // fallimento precedente viene contato.
            $state['failure_recorded_for'] = 0;
            $state['last_error'] = '';
            $state['last_error_kind'] = '';
            self::saveState($state);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }

        return self::status(true);
    }

    private static function backoffDelay(int $failures): int
    {
        if ($failures <= 0) {
            return 0;
        }
        $delay = 60 * (int)pow(2, min(5, $failures - 1));
        return min(self::MAX_BACKOFF, $delay);
    }

    /** Stringa vuota se il server consente di avviare processi. */
    private static function spawnUnavailableReason(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'L\'avvio automatico del worker non è supportato su Windows.';
        }

        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            return 'La configurazione PHP del server web disabilita proc_open().';
        }

        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('proc_open', $disabled, true)) {
            return 'La configurazione PHP del server web disabilita proc_open().';
        }

        if (!is_file(self::workerScript())) {
            return 'File media-scan-worker.php non trovato nella cartella di Grizzly.';
        }

        if (!is_executable('/bin/sh')) {
            return 'Shell di sistema /bin/sh non disponibile.';
        }

        return '';
    }

    /**
     * Lancia il worker staccato dalla richiesta corrente.
     *
     * - i descrittori ereditati dal server web (socket, file di sessione)
     *   vengono chiusi prima dell'avvio;
     * - il worker parte in una sessione propria (perl POSIX::setsid o
     *   setsid), così i segnali che Apache invia al proprio gruppo di processi
     *   durante un graceful restart non lo raggiungono;
     * - TMPDIR punta a storage/media-scan-worker: lock e state restano
     *   persistenti e separati per ogni installazione (stesso meccanismo del
     *   volume worker_state in Docker).
     */
    private static function spawn(string $php): bool
    {
        // perl crea la nuova sessione e chiude tutti i descrittori ereditati
        // oltre stdin/stdout/stderr; è presente su macOS e su quasi tutte le
        // distribuzioni Linux. In alternativa setsid (util-linux), che però
        // non chiude i descrittori: in quel caso restano chiusi solo 3-9.
        $detach = '';
        foreach (['/usr/bin/perl', '/bin/perl'] as $perl) {
            if (@is_executable($perl)) {
                $detach = escapeshellarg($perl) . ' -MPOSIX -e '
                    . escapeshellarg('POSIX::setsid(); POSIX::close($_) for 3 .. 1023; exec @ARGV or exit 1;')
                    . ' -- ';
                break;
            }
        }
        if ($detach === '') {
            foreach (['/usr/bin/setsid', '/bin/setsid'] as $candidate) {
                if (@is_executable($candidate)) {
                    $detach = escapeshellarg($candidate) . ' ';
                    break;
                }
            }
        }

        // POSIX sh accetta solo descrittori a una cifra nei redirect.
        $script = 'PATH=/usr/bin:/bin:/usr/sbin:/sbin:$PATH; export PATH; '
            . 'exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&-; '
            . 'cd ' . escapeshellarg(BASE_PATH) . ' || exit 1; '
            . 'TMPDIR=' . escapeshellarg(self::workerStateDir()) . '; export TMPDIR; '
            . 'nohup ' . $detach
            . escapeshellarg($php) . ' '
            . escapeshellarg(self::workerScript()) . ' --managed'
            . ' </dev/null >>' . escapeshellarg(self::logFile()) . ' 2>&1 &';

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $proc = @proc_open(['/bin/sh', '-c', $script], $descriptors, $pipes, BASE_PATH);
        if (!is_resource($proc)) {
            return false;
        }

        // /bin/sh termina subito dopo aver messo il worker in background.
        return proc_close($proc) === 0;
    }

    /**
     * Individua l'eseguibile PHP CLI. Sotto mod_php PHP_BINARY è vuoto e sotto
     * PHP-FPM punta a php-fpm: si parte quindi da PHP_BINDIR, che in MAMP è
     * la cartella bin della stessa versione PHP usata da Apache.
     *
     * Requisiti minimi: SAPI cli, PHP 7.4+, pdo_mysql. Tra i candidati validi
     * si preferisce il primo con openssl, necessario per scaricare i metadati
     * via HTTPS (AlbumMetadataService). Se nessuno lo ha si usa comunque il
     * primo valido: l'import funziona, ma senza dati dai servizi esterni, e
     * Impostazioni lo segnala. GRIZZLY_PHP_CLI è sempre rispettato.
     */
    private static function findPhpCli(array &$state): string
    {
        $override = function_exists('_env') ? trim(_env('GRIZZLY_PHP_CLI', '')) : '';

        // Il binario in cache si riusa solo se ha openssl (o è l'override):
        // un binario senza openssl viene riverificato a ogni avvio, così
        // un'estensione installata in seguito viene rilevata.
        $cached = (string)($state['php_binary'] ?? '');
        $cachedSig = (string)($state['php_binary_sig'] ?? '');
        $cachedSsl = !empty($state['php_binary_openssl']);
        // Uno state scritto da versioni precedenti non ha il campo openssl:
        // in quel caso il binario va riverificato.
        if ($cached !== ''
            && array_key_exists('php_binary_openssl', $state)
            && ($override === '' || $override === $cached)
            && ($cachedSsl || $override === $cached)
            && @is_executable($cached)
            && $cachedSig === self::binarySignature($cached)) {
            return $cached;
        }

        // Override valido: usato così com'è, anche senza openssl (scelta
        // esplicita; Impostazioni mostra l'avviso). Se non è valido si
        // prosegue con la ricerca automatica.
        if ($override !== '' && @is_file($override) && @is_executable($override)) {
            $probe = self::probePhpCli($override);
            if ($probe['ok']) {
                return self::rememberPhpCli($state, $override, $probe['openssl']);
            }
        }

        $ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $candidates = [];

        if (defined('PHP_BINARY') && PHP_BINARY !== ''
            && preg_match('~^php(\d+(\.\d+)?)?$~', basename(PHP_BINARY))) {
            $candidates[] = PHP_BINARY;
        }

        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $candidates[] = PHP_BINDIR . '/php';
            $candidates[] = PHP_BINDIR . '/php' . $ver;
        }

        $candidates[] = '/usr/bin/php' . $ver;
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/opt/homebrew/bin/php';
        $candidates[] = '/usr/bin/php';

        $fallback = '';
        foreach (array_unique($candidates) as $bin) {
            if ($bin === $override || !@is_file($bin) || !@is_executable($bin)) {
                continue;
            }

            $probe = self::probePhpCli($bin);
            if (!$probe['ok']) {
                continue;
            }

            if ($probe['openssl']) {
                return self::rememberPhpCli($state, $bin, true);
            }

            if ($fallback === '') {
                $fallback = $bin;
            }
        }

        if ($fallback !== '') {
            return self::rememberPhpCli($state, $fallback, false);
        }

        $state['php_binary'] = '';
        $state['php_binary_sig'] = '';
        $state['php_binary_openssl'] = false;
        return '';
    }

    private static function rememberPhpCli(array &$state, string $bin, bool $openssl): string
    {
        $state['php_binary'] = $bin;
        $state['php_binary_sig'] = self::binarySignature($bin);
        $state['php_binary_openssl'] = $openssl;
        return $bin;
    }

    private static function binarySignature(string $bin): string
    {
        $mtime = @filemtime($bin);
        $size = @filesize($bin);
        return (string)$mtime . ':' . (string)$size;
    }

    /**
     * Verifica SAPI, versione minima, driver PDO MySQL e openssl
     * dell'eseguibile candidato.
     *
     * @return array{ok:bool,openssl:bool}
     */
    private static function probePhpCli(string $bin): array
    {
        $fail = ['ok' => false, 'openssl' => false];
        $code = 'echo PHP_SAPI, "|", PHP_VERSION_ID, "|", (extension_loaded("pdo_mysql") ? 1 : 0),'
            . ' "|", (extension_loaded("openssl") ? 1 : 0);';
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $proc = @proc_open([$bin, '-r', $code], $descriptors, $pipes);
        if (!is_resource($proc)) {
            return $fail;
        }

        stream_set_blocking($pipes[1], false);
        $out = '';
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $read = [$pipes[1]];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000);
            if ($ready === false) {
                break;
            }
            if ($ready > 0) {
                $chunk = fread($pipes[1], 1024);
                if ($chunk === false || ($chunk === '' && feof($pipes[1]))) {
                    break;
                }
                $out .= $chunk;
                if (strlen($out) > 256) {
                    break;
                }
            }
            if (feof($pipes[1])) {
                break;
            }
        }

        fclose($pipes[1]);
        $status = proc_get_status($proc);
        if (!empty($status['running'])) {
            @proc_terminate($proc);
        }
        proc_close($proc);

        $parts = explode('|', trim($out));
        if (count($parts) !== 4) {
            return $fail;
        }

        return [
            'ok'      => $parts[0] === 'cli' && (int)$parts[1] >= 70400 && $parts[2] === '1',
            'openssl' => $parts[3] === '1',
        ];
    }

    private static function rotateLogIfNeeded(): void
    {
        $log = self::logFile();
        $size = @filesize($log);
        if ($size !== false && $size > self::LOG_MAX_BYTES) {
            @rename($log, $log . '.1');
        }
    }

    /**
     * Prima installazione della modalità gestita su una macchina dove il
     * worker girava a mano (terminale, launchd): riusa lo state esistente per
     * evitare una nuova verifica completa della libreria. Best effort.
     */
    private static function seedLegacyWorkerState(): void
    {
        $target = self::workerStateDir() . '/grizzly-media-import-state.json';
        if (is_file($target)) {
            return;
        }

        $legacy = rtrim(sys_get_temp_dir(), '/') . '/grizzly-media-import-state.json';
        if ($legacy !== $target && @is_file($legacy) && @is_readable($legacy)) {
            @copy($legacy, $target);
        }
    }

    // ------------------------------------------------------------------
    // Stato
    // ------------------------------------------------------------------

    /**
     * Stato completo per Impostazioni.
     *
     * state:
     *   running     worker attivo (heartbeat recente o lock d'installazione tenuto)
     *   starting    avviato da poco, in attesa del primo heartbeat
     *   stopped     scansione disattivata, nessun worker attivo
     *   failed      avviato ma non risponde; nuovo tentativo programmato
     *   unavailable il server non permette l'avvio automatico
     *   offline     scansione attiva ma worker non attivo (Docker o manuale)
     */
    public static function status(?bool $enabled = null): array
    {
        if ($enabled === null) {
            try {
                $enabled = self::scanEnabled();
            } catch (Throwable $e) {
                $enabled = false;
            }
        }

        $mode = self::mode();
        $hb = self::readHeartbeat();
        $lockHeld = self::installLockHeld();
        $fresh = !$hb['stopped'] && $hb['age'] !== null && $hb['age'] <= self::HEARTBEAT_MAX_AGE;
        $alive = $fresh || $lockHeld;
        $hbMode = (string)($hb['mode'] ?? '');

        $result = [
            'mode'          => $mode,
            'enabled'       => (bool)$enabled,
            'alive'         => $alive,
            'state'         => 'stopped',
            'pid'           => $alive ? $hb['pid'] : null,
            'heartbeat_age' => $hb['age'],
            'heartbeat_at'  => $hb['timestamp'],
            'external'      => $alive && $mode === 'managed' && $hbMode !== '' && $hbMode !== 'managed',
            'message'       => '',
            'retry_in'      => null,
            'log_tail'      => '',
            'command'       => '',
            'warning'       => '',
        ];

        // Avviso valido in ogni stato della modalità gestita: riguarda il
        // binario che Grizzly usa (o userà) per avviare il worker.
        if ($mode === 'managed' && !$result['external']) {
            $result['warning'] = self::opensslWarning();
        }

        if ($alive) {
            $result['state'] = 'running';
            if ($mode === 'docker') {
                $result['message'] = 'Gestito da Docker (servizio worker).';
            } elseif ($mode === 'manual') {
                $result['message'] = $enabled
                    ? 'In esecuzione come processo gestito esternamente.'
                    : 'In esecuzione come processo gestito esternamente; scansione disattivata.';
            } elseif ($result['external']) {
                $result['message'] = 'In esecuzione come processo avviato fuori da Grizzly. Grizzly non ne avvia un secondo.';
            } else {
                $result['message'] = $enabled
                    ? 'In esecuzione, avviato da Grizzly.'
                    : 'In esecuzione, si arresta entro pochi secondi.';
            }
            if (!$fresh && $lockHeld) {
                $result['message'] .= ' Import in corso.';
            }
            return $result;
        }

        if (!$enabled) {
            $result['state'] = 'stopped';
            $result['message'] = $mode === 'managed'
                ? 'Fermo. Grizzly lo avvia quando attivi la scansione.'
                : 'Fermo.';
            return $result;
        }

        if ($mode === 'docker') {
            $result['state'] = 'offline';
            $result['message'] = 'Il servizio worker di Docker non risponde. Verifica che il container sia in esecuzione.';
            return $result;
        }

        if ($mode === 'manual') {
            $result['state'] = 'offline';
            $result['message'] = 'Avvio automatico disattivato (GRIZZLY_WORKER_AUTOSTART=0). Avvia il worker con il servizio che hai configurato.';
            $result['command'] = self::manualCommand();
            return $result;
        }

        $state = self::loadState();
        $kind = (string)($state['last_error_kind'] ?? '');
        $lastSpawn = (int)($state['last_spawn_at'] ?? 0);
        $now = time();

        if ($kind === 'unavailable') {
            $result['state'] = 'unavailable';
            $result['message'] = (string)$state['last_error'];
            $result['command'] = self::manualCommand();
            return $result;
        }

        if ($lastSpawn > 0 && $hb['timestamp'] !== null && $hb['timestamp'] >= $lastSpawn) {
            // L'ultimo avvio era riuscito e il worker si è chiuso in modo
            // ordinato (aggiornamento, database temporaneamente assente):
            // la prossima richiesta a Grizzly lo riavvia.
            $result['state'] = 'starting';
            $result['message'] = 'In attesa di riavvio.';
            return $result;
        }

        if ($lastSpawn > 0 && ($now - $lastSpawn) < self::START_GRACE) {
            $result['state'] = 'starting';
            $result['message'] = 'Avvio in corso.';
            return $result;
        }

        if ($lastSpawn > 0) {
            $failures = max(1, (int)($state['failures'] ?? 0));
            $nextAt = $lastSpawn + self::START_GRACE + self::backoffDelay($failures);
            $result['state'] = 'failed';
            $result['retry_in'] = max(0, $nextAt - $now);
            $result['message'] = 'Il worker è stato avviato ma non risponde.';
            $result['log_tail'] = self::logTail();
            return $result;
        }

        // Scansione attiva, nessun tentativo ancora: avverrà alla prossima richiesta.
        $result['state'] = 'starting';
        $result['message'] = 'In attesa di avvio.';
        return $result;
    }

    /**
     * Testo di avviso se il PHP CLI scelto non ha openssl, altrimenti vuoto.
     */
    private static function opensslWarning(): string
    {
        $state = self::loadState();
        $bin = (string)($state['php_binary'] ?? '');
        if ($bin === '' || !array_key_exists('php_binary_openssl', $state) || !empty($state['php_binary_openssl'])) {
            return '';
        }

        return 'Il PHP da riga di comando usato dal worker (' . $bin . ') non ha l\'estensione openssl: '
            . 'gli album vengono importati senza i dati scaricati dai servizi esterni. '
            . 'Abilita openssl per quel PHP, oppure indica in .env un altro eseguibile con GRIZZLY_PHP_CLI.';
    }

    public static function manualCommand(): string
    {
        return 'php ' . escapeshellarg(self::workerScript());
    }

    public static function isWorkerAlive(): bool
    {
        $hb = self::readHeartbeat();
        if (!$hb['stopped'] && $hb['age'] !== null && $hb['age'] <= self::HEARTBEAT_MAX_AGE) {
            return true;
        }
        return self::installLockHeld();
    }

    /**
     * Il worker tiene il lock d'installazione per tutta la sua vita, anche
     * durante import lunghi in cui l'heartbeat può invecchiare.
     */
    private static function installLockHeld(): bool
    {
        $file = self::installLockFile();
        if (!is_file($file)) {
            return false;
        }

        $h = @fopen($file, 'r');
        if (!$h) {
            return false;
        }

        $held = !@flock($h, LOCK_EX | LOCK_NB);
        if (!$held) {
            @flock($h, LOCK_UN);
        }
        @fclose($h);

        return $held;
    }

    /**
     * Heartbeat scritto dal worker in storage/. Non entra nel DB né nei backup.
     *
     * Un heartbeat con stopped=true è stato scritto da un worker che si è
     * chiuso in modo ordinato: prova che l'avvio era riuscito, ma il processo
     * non è più attivo.
     *
     * @return array{timestamp:?int,age:?int,pid:?int,mode:?string,stopped:bool}
     */
    public static function readHeartbeat(): array
    {
        $empty = ['timestamp' => null, 'age' => null, 'pid' => null, 'mode' => null, 'stopped' => false];
        $file = self::heartbeatFile();

        clearstatcache(true, $file);
        if (!is_file($file) || !is_readable($file)) {
            return $empty;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return $empty;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $empty;
        }

        $timestamp = isset($data['timestamp']) ? (int)$data['timestamp'] : 0;
        if ($timestamp <= 0) {
            return $empty;
        }

        return [
            'timestamp' => $timestamp,
            'age'       => max(0, time() - $timestamp),
            'pid'       => isset($data['pid']) ? (int)$data['pid'] : null,
            'mode'      => isset($data['mode']) ? (string)$data['mode'] : null,
            'stopped'   => !empty($data['stopped']),
        ];
    }

    private static function logTail(int $maxLines = 8): string
    {
        $file = self::logFile();
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }

        $size = (int)@filesize($file);
        $h = @fopen($file, 'r');
        if (!$h) {
            return '';
        }

        $read = min($size, 4096);
        if ($read > 0) {
            fseek($h, -$read, SEEK_END);
        }
        $chunk = (string)fread($h, max(1, $read));
        fclose($h);

        $lines = preg_split('~\R~', trim($chunk));
        if (!is_array($lines)) {
            return '';
        }

        return implode("\n", array_slice($lines, -$maxLines));
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private static function scanEnabled(): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'media_scan_enabled' LIMIT 1");
        $stmt->execute();
        return (string)$stmt->fetchColumn() === '1';
    }

    private static function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        return @mkdir($dir, 0775, true) || is_dir($dir);
    }

    private static function loadState(): array
    {
        $raw = @file_get_contents(self::supervisorStateFile());
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private static function saveState(array $state): void
    {
        if (!self::ensureDir(self::storageDir())) {
            return;
        }

        $file = self::supervisorStateFile();
        $tmp = $file . '.tmp.' . getmypid();
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }

    /** Aggiorna solo l'istante dell'ultimo controllo (throttling di tick()). */
    private static function touchState(): void
    {
        $file = self::supervisorStateFile();
        if (is_file($file)) {
            @touch($file);
            return;
        }
        self::saveState(['checked_at' => time()]);
    }
}

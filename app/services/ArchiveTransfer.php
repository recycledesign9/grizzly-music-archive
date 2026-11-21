<?php

/**
 * ArchiveTransfer
 * ------------------------------------------------------------
 * Export / Import completo dell'archivio Grizzly (dati + immagini).
 *
 * EXPORT: crea uno ZIP contenente
 *   - data.json   -> dump di tutte le tabelle (ordine FK-safe)
 *   - manifest.json -> metadati (versione, data, conteggi)
 *   - uploads/covers/*   -> cover album
 *   - uploads/artists/*  -> immagini artista
 *   - uploads/disco/*    -> miniature discografia ufficiale (cache
 *                           riscaricabile, ma includerla rende la
 *                           migrazione a piena fedeltà e indipendente
 *                           dalla disponibilità di Cover Art Archive)
 *
 * IMPORT: legge lo ZIP, valida, fa un backup di sicurezza del DB,
 *   poi SOSTITUISCE i dati (azzera e reinserisce) in transazione,
 *   infine ripristina le immagini.
 *
 * NON include l'audio (gestito a parte). NON include il percorso
 * audio nelle settings (specifico per server).
 *
 * PERCORSI DELLA LIBRERIA EXTERNAL: i source_path vengono resi relativi
 * (@library/...) rispetto a media_scan_bound_root, cioè la cartella a cui
 * i percorsi del DB sono effettivamente legati (la stessa usata dallo
 * streaming). Le righe che si trovano fuori da quella cartella, per esempio
 * file non ritrovati dopo un cambio della cartella da scansionare, restano
 * con il percorso assoluto originale: vengono contate nel manifest
 * (unportable) e segnalate all'utente, ma non bloccano l'export.
 *
 * Agnostico rispetto all'ambiente: usa BASE_PATH / UPLOAD_PATH,
 * quindi funziona identico su MAMP e in container Docker.
 *
 * Compatibile PHP 7.4.
 */
class ArchiveTransfer
{
    /**
     * Versione del formato di export.
     * v1: formati come colonna albums.format_id (schede separate)
     * v2: formati multipli nella tabella ponte album_formats
     * v3: percorsi libreria external/ignored portabili (@library/...)
     * v4: albums.mb_release_group per l'identita' logica MusicBrainz
     * v5: artists.deezer_artist_id + cache immagine indipendente
     *     (image_fetched_at, image_status, image_fetch_version)
     *
     * L'import accetta anche gli archivi precedenti: per gli archivi v1 la tabella ponte
     * viene ricostruita dalla colonna legacy (vedi import()); per v1-v4 le immagini
     * artista gia' presenti vengono marcate come baseline valida della cache v1.
     */
    private const FORMAT_VERSION = 5;

    /** Prefisso interno usato nello ZIP per i path relativi alla root della libreria. */
    private const PORTABLE_LIBRARY_PREFIX = '@library/';

    /** Numero massimo di percorsi non portabili elencati nel manifest. */
    private const UNPORTABLE_SAMPLE_LIMIT = 50;

    /**
     * Tabelle nell'ORDINE DI IMPORT (FK-safe).
     * In export l'ordine è indifferente; in import questo ordine
     * garantisce che le tabelle "padre" vengano prima delle "figlie".
     */
    private const TABLES = [
        'formats',
        'genres',
        'labels',
        'artists',
        'albums',
        'album_formats',
        'artist_discography',
        'tracks',
        'audio_files',
        'playlists',
        'playlist_tracks',
        'media_scan_ignored',
        'settings',
    ];

    /** Sottocartelle immagini da includere (NO audio) */
    private const IMAGE_DIRS = ['covers', 'artists', 'disco'];

    /**
     * Chiavi settings specifiche del server.
     *
     * Non vengono esportate e, durante l'import, vengono preservate dalla
     * macchina di destinazione. media_scan_enabled viene sempre forzata a 0.
     *
     * media_scan_bound_root è la cartella a cui sono legati i source_path di
     * QUESTA macchina: nel backup i percorsi viaggiano come @library/...,
     * quindi il binding della macchina di origine non ha significato altrove.
     */
    private const SETTINGS_SKIP = [
        'audio_path',
        'media_scan_path',
        'media_scan_bound_root',
        'media_scan_enabled',
    ];

    /**
     * Prefisso delle chiavi settings che contengono credenziali dei servizi
     * esterni (api_discogs_token, api_youtube_key, api_listenbrainz_token…).
     * Le credenziali non vengono mai esportate, non vengono accettate da un
     * archivio importato, non finiscono nel backup JSON locale e, durante
     * l'import, restano quelle della macchina di destinazione. La regola
     * vale per prefisso: un servizio aggiunto o tolto non richiede di
     * aggiornare questo file, e le chiavi di servizi dismessi presenti in
     * archivi vecchi vengono scartate allo stesso modo.
     */
    private const CREDENTIAL_PREFIX = 'api_';

    private PDO $db;

    /**
     * Esito dell'ultimo export: percorsi external/ignored rimasti assoluti
     * perché fuori dalla root della libreria. Letto da SettingsController
     * per avvisare l'utente dopo il download.
     */
    private array $lastExportReport = [
        'library_root'       => '',
        'unportable_audio'   => 0,
        'unportable_ignored' => 0,
        'unportable_total'   => 0,
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Riepilogo dell'ultimo export eseguito da questa istanza.
     *
     * @return array{library_root: string, unportable_audio: int, unportable_ignored: int, unportable_total: int}
     */
    public function getLastExportReport(): array
    {
        return $this->lastExportReport;
    }

    // ============================================================
    // EXPORT
    // ============================================================

    /**
     * Crea il file ZIP di export e ne restituisce il percorso assoluto.
     * Il chiamante si occupa di inviarlo al browser e poi eliminarlo.
     *
     * @throws RuntimeException se ZipArchive non è disponibile o fallisce
     */
    public function export(): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Estensione ZIP non disponibile su questo server.');
        }

        // Dump dati in una singola snapshot REPEATABLE READ. Il worker può
        // continuare a scrivere nel DB, ma l'export vede una fotografia coerente
        // dall'inizio alla fine del dump.
        $data = ['__format' => self::FORMAT_VERSION, 'tables' => []];
        $counts = [];
        $sourceLibraryRoot = '';
        $unportable = [
            'audio_files' => 0,
            'ignored'     => 0,
            'samples'     => [],
        ];

        try {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->db->beginTransaction();

            $sourceLibraryRoot = $this->resolveLibraryRoot();

            foreach (self::TABLES as $table) {
                $rows = $this->dumpTable($table);

                if ($table === 'audio_files') {
                    $rows = $this->makeExternalAudioPathsPortable($rows, $sourceLibraryRoot, $unportable);
                } elseif ($table === 'media_scan_ignored') {
                    $rows = $this->makeIgnoredPathsPortable($rows, $sourceLibraryRoot, $unportable);
                }

                $data['tables'][$table] = $rows;
                $counts[$table] = count($rows);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $unportableTotal = (int)$unportable['audio_files'] + (int)$unportable['ignored'];

        $manifest = [
            'format'              => self::FORMAT_VERSION,
            'created_at'          => date('c'),
            'app'                 => 'Grizzly Music Archive',
            'counts'              => $counts,
            'library_path_mode'   => 'relative-v1',
            'scanner_restores_on' => false,
            // Percorsi rimasti assoluti perché fuori dalla root della libreria.
            // Nel nuovo server non vengono rimappati: restano come riferimenti
            // storici, esattamente come sulla macchina di origine.
            'unportable'          => [
                'audio_files' => (int)$unportable['audio_files'],
                'ignored'     => (int)$unportable['ignored'],
                'total'       => $unportableTotal,
                'samples'     => $unportable['samples'],
            ],
        ];

        // Serializzazione PRIMA di creare lo ZIP. json_encode() restituisce
        // false con byte non UTF-8 (per esempio un source_path binario): senza
        // questo controllo lo ZIP conterrebbe un data.json vuoto e l'errore
        // emergerebbe solo al momento dell'import.
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($manifestJson === false) {
            throw new RuntimeException('Export impossibile: manifest non serializzabile (' . json_last_error_msg() . ').');
        }

        $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($dataJson === false) {
            throw new RuntimeException('Export impossibile: dati non serializzabili in JSON (' . json_last_error_msg() . ').');
        }

        // File ZIP temporaneo
        $tmpDir = sys_get_temp_dir();
        $zipPath = $tmpDir . '/grizzly-export-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile creare il file ZIP.');
        }

        $zip->addFromString('manifest.json', $manifestJson);
        $zip->addFromString('data.json', $dataJson);
        unset($dataJson);

        // Immagini
        foreach (self::IMAGE_DIRS as $sub) {
            $dir = UPLOAD_PATH . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $files = scandir($dir);
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                $full = $dir . '/' . $f;
                if (is_file($full)) {
                    $zip->addFile($full, 'uploads/' . $sub . '/' . $f);
                }
            }
        }

        $zip->close();

        $this->lastExportReport = [
            'library_root'       => $sourceLibraryRoot,
            'unportable_audio'   => (int)$unportable['audio_files'],
            'unportable_ignored' => (int)$unportable['ignored'],
            'unportable_total'   => $unportableTotal,
        ];

        return $zipPath;
    }

    /**
     * Root rispetto alla quale i percorsi external diventano @library/...
     *
     * È media_scan_bound_root, cioè la cartella a cui i source_path del DB
     * sono realmente legati: la stessa usata da MediaController per lo
     * streaming. media_scan_path è invece la cartella scelta in Settings e,
     * durante un cambio cartella non ancora completato dal worker, punta già
     * alla nuova posizione mentre i source_path sono ancora sulla vecchia.
     * Installazioni precedenti alla durable binding: fallback su
     * media_scan_path.
     */
    private function resolveLibraryRoot(): string
    {
        $bound = (string)$this->getSettingValue('media_scan_bound_root', '');
        if ($bound !== '') {
            return $bound;
        }

        return (string)$this->getSettingValue('media_scan_path', '');
    }

    /**
     * Estrae tutte le righe di una tabella. Per `settings` salta le
     * chiavi specifiche del server (audio_path).
     */
    private function dumpTable(string $table): array
    {
        // Whitelist tabella (evita injection sul nome tabella)
        if (!in_array($table, self::TABLES, true)) {
            return [];
        }

        // Tabella assente (DB non ancora migrato): dump vuoto, non fatale
        if (!$this->tableExists($table)) {
            return [];
        }

        $rows = $this->db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);

        if ($table === 'settings') {
            $rows = array_values(array_filter($rows, function ($r) {
                $key = (string)($r['key'] ?? '');
                return !in_array($key, self::SETTINGS_SKIP, true)
                    && !$this->isCredentialKey($key);
            }));
        }

        return $rows;
    }

    /** Vero se la chiave settings contiene la credenziale di un servizio esterno. */
    private function isCredentialKey(string $key): bool
    {
        return strpos($key, self::CREDENTIAL_PREFIX) === 0;
    }

    /**
     * Restituisce una setting senza assumere che la riga esista.
     */
    private function getSettingValue(string $key, ?string $default = null): ?string
    {
        if (!$this->tableExists('settings')) {
            return $default;
        }

        $stmt = $this->db->prepare("SELECT `value` FROM settings WHERE `key` = :key LIMIT 1");
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? $default : (string)$value;
    }

    /**
     * Normalizza un path solo a livello lessicale, senza richiedere che esista.
     */
    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '') {
            return '';
        }

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    /**
     * Converte un path assoluto appartenente alla root della libreria in
     * @library/<percorso-relativo>.
     *
     * Restituisce null quando il path non è convertibile: root non
     * configurata, path fuori dalla root o con segmenti "." / "..". Il
     * chiamante conserva allora il valore originale e lo conta come non
     * portabile. Un riferimento storico fuori root è uno stato previsto da
     * MediaRootRelocationService e non deve impedire il backup.
     */
    private function tryPortableLibraryPath(string $path, string $root): ?string
    {
        $path = $this->normalizePath($path);
        if ($path === '') {
            return null;
        }

        if (strpos($path, self::PORTABLE_LIBRARY_PREFIX) === 0) {
            return $path;
        }

        $root = $this->normalizePath($root);
        if ($root === '' || $path[0] !== '/') {
            return null;
        }

        if ($root === '/') {
            $relative = ltrim($path, '/');
        } elseif (strpos($path, $root . '/') === 0) {
            $relative = substr($path, strlen($root) + 1);
        } else {
            return null;
        }

        if ($relative === '' || preg_match('~(^|/)\.\.?(/|$)~', $relative)) {
            return null;
        }

        return self::PORTABLE_LIBRARY_PREFIX . $relative;
    }

    /**
     * Registra un percorso rimasto assoluto. Nel manifest finiscono solo
     * percorsi UTF-8 validi, fino a UNPORTABLE_SAMPLE_LIMIT: il conteggio
     * resta comunque esatto.
     */
    private function noteUnportable(array &$unportable, string $bucket, string $path): void
    {
        $unportable[$bucket] = (int)($unportable[$bucket] ?? 0) + 1;

        if (
            count($unportable['samples']) < self::UNPORTABLE_SAMPLE_LIMIT
            && preg_match('//u', $path) === 1
        ) {
            $unportable['samples'][] = $path;
        }
    }

    private function makeExternalAudioPathsPortable(array $rows, string $root, array &$unportable): array
    {
        foreach ($rows as &$row) {
            if (!is_array($row) || ($row['storage_type'] ?? '') !== 'external') {
                continue;
            }

            $sourcePath = (string)($row['source_path'] ?? '');
            if ($sourcePath === '') {
                continue;
            }

            $portable = $this->tryPortableLibraryPath($sourcePath, $root);
            if ($portable === null) {
                // Valore originale invariato, byte per byte.
                $this->noteUnportable($unportable, 'audio_files', $sourcePath);
                continue;
            }

            $row['source_path'] = $portable;
        }
        unset($row);

        return $rows;
    }

    private function makeIgnoredPathsPortable(array $rows, string $root, array &$unportable): array
    {
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $sourcePath = (string)($row['source_path'] ?? '');
            if ($sourcePath === '') {
                continue;
            }

            $portable = $this->tryPortableLibraryPath($sourcePath, $root);
            if ($portable === null) {
                $this->noteUnportable($unportable, 'ignored', $sourcePath);
                continue;
            }

            $row['source_path'] = $portable;
        }
        unset($row);

        return $rows;
    }

    /**
     * Durante l'import non accettiamo mai settings server-specifiche provenienti
     * dallo ZIP, anche se l'archivio è stato creato da una versione precedente.
     */
    private function filterIncomingSettingsRows(array $rows): array
    {
        return array_values(array_filter($rows, function ($row) {
            if (!is_array($row)) {
                return false;
            }
            $key = (string)($row['key'] ?? '');
            return !in_array($key, self::SETTINGS_SKIP, true)
                && !$this->isCredentialKey($key);
        }));
    }

    private function readLocalServerSettings(): array
    {
        $out = [
            'audio_path'            => null,
            'media_scan_path'       => null,
            'media_scan_bound_root' => null,
        ];

        if (!$this->tableExists('settings')) {
            return $out;
        }

        $stmt = $this->db->prepare(
            "SELECT `key`, `value`
             FROM settings
             WHERE `key` IN (
                 'audio_path',
                 'media_scan_path',
                 'media_scan_bound_root'
             )"
        );
        $stmt->execute();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['key'] ?? '');
            if (array_key_exists($key, $out)) {
                $out[$key] = (string)($row['value'] ?? '');
            }
        }

        return $out;
    }

    /**
     * Credenziali dei servizi esterni salvate su QUESTA macchina, con la
     * loro etichetta, da ripristinare identiche dopo un import.
     *
     * @return array<int,array{key:string,value:string,label:string}>
     */
    private function readLocalCredentials(): array
    {
        if (!$this->tableExists('settings')) {
            return [];
        }

        $out = [];
        foreach ($this->db->query("SELECT `key`, `value`, `label` FROM settings")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['key'] ?? '');
            if ($this->isCredentialKey($key)) {
                $out[] = [
                    'key'   => $key,
                    'value' => (string)($row['value'] ?? ''),
                    'label' => (string)($row['label'] ?? ''),
                ];
            }
        }

        return $out;
    }

    private function upsertSetting(string $key, string $value, string $label): void
    {
        if (!$this->tableExists('settings')) {
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO settings (`key`, `value`, `label`)
             VALUES (:key, :value, :label)
             ON DUPLICATE KEY UPDATE
                 `value` = VALUES(`value`),
                 `label` = VALUES(`label`)"
        );
        $stmt->execute([
            ':key'   => $key,
            ':value' => $value,
            ':label' => $label,
        ]);
    }

    private function isPortableLibraryPath(string $path): bool
    {
        return strpos($path, self::PORTABLE_LIBRARY_PREFIX) === 0;
    }

    private function portableRelativePart(string $path): ?string
    {
        if (!$this->isPortableLibraryPath($path)) {
            return null;
        }

        $relative = substr($path, strlen(self::PORTABLE_LIBRARY_PREFIX));
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative === '' || preg_match('~(^|/)\.\.?(/|$)~', $relative)) {
            return null;
        }

        return $relative;
    }

    /**
     * Conta i riferimenti ancora in forma @library/... .
     */
    public function countPortableLibraryPaths(): array
    {
        $audio = 0;
        $ignored = 0;

        if ($this->tableExists('audio_files')) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM audio_files
                 WHERE storage_type = 'external'
                   AND source_path LIKE :prefix"
            );
            $stmt->execute([':prefix' => self::PORTABLE_LIBRARY_PREFIX . '%']);
            $audio = (int)$stmt->fetchColumn();
        }

        if ($this->tableExists('media_scan_ignored')) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM media_scan_ignored
                 WHERE source_path LIKE :prefix"
            );
            $stmt->execute([':prefix' => self::PORTABLE_LIBRARY_PREFIX . '%']);
            $ignored = (int)$stmt->fetchColumn();
        }

        return [
            'audio_files' => $audio,
            'ignored'     => $ignored,
            'total'       => $audio + $ignored,
        ];
    }

    /**
     * Converte i riferimenti @library/... importati nello ZIP nel nuovo path
     * assoluto scelto sulla macchina di destinazione.
     *
     * Viene richiamato quando l'utente salva esplicitamente media_scan_path.
     */
    public function rebindPortableLibraryPaths(string $newRoot): array
    {
        $newRoot = $this->normalizePath($newRoot);
        if ($newRoot === '' || ($newRoot[0] ?? '') !== '/') {
            throw new RuntimeException('La nuova root della libreria deve essere un percorso assoluto.');
        }

        $audioUpdated = 0;
        $ignoredUpdated = 0;

        try {
            $this->db->beginTransaction();

            if ($this->tableExists('audio_files')) {
                $stmt = $this->db->prepare(
                    "SELECT id, source_path
                     FROM audio_files
                     WHERE storage_type = 'external'
                       AND source_path LIKE :prefix"
                );
                $stmt->execute([':prefix' => self::PORTABLE_LIBRARY_PREFIX . '%']);
                $update = $this->db->prepare(
                    "UPDATE audio_files SET source_path = :source_path WHERE id = :id"
                );

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $relative = $this->portableRelativePart((string)($row['source_path'] ?? ''));
                    if ($relative === null) {
                        continue;
                    }

                    $update->execute([
                        ':source_path' => rtrim($newRoot, '/') . '/' . $relative,
                        ':id'          => (int)$row['id'],
                    ]);
                    $audioUpdated += $update->rowCount();
                }
            }

            if ($this->tableExists('media_scan_ignored')) {
                $stmt = $this->db->prepare(
                    "SELECT id, source_path
                     FROM media_scan_ignored
                     WHERE source_path LIKE :prefix"
                );
                $stmt->execute([':prefix' => self::PORTABLE_LIBRARY_PREFIX . '%']);
                $pendingIgnored = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$this->columnExists('media_scan_ignored', 'path_hash')) {
                    // Schema senza path_hash: comportamento storico.
                    $update = $this->db->prepare(
                        "UPDATE media_scan_ignored SET source_path = :source_path WHERE id = :id"
                    );

                    foreach ($pendingIgnored as $row) {
                        $relative = $this->portableRelativePart((string)($row['source_path'] ?? ''));
                        if ($relative === null) {
                            continue;
                        }

                        $update->execute([
                            ':source_path' => rtrim($newRoot, '/') . '/' . $relative,
                            ':id'          => (int)$row['id'],
                        ]);
                        $ignoredUpdated += $update->rowCount();
                    }
                } else {
                    // path_hash è sha1(percorso assoluto) ed è la chiave con cui
                    // MediaImportService riconosce le cartelle ignorate: va
                    // ricalcolato insieme al percorso, come fa
                    // MediaRootRelocationService::rebind(). Dallo ZIP arriva
                    // l'hash del percorso sulla macchina di origine.
                    //
                    // Fase 1: gli hash di origine delle righe da rimappare
                    // vengono sostituiti con valori provvisori univoci. Senza
                    // questo passaggio un hash di origine potrebbe coincidere
                    // per caso con quello nuovo di un'altra riga (cartelle
                    // annidate) e violare l'indice univoco.
                    $park = $this->db->prepare(
                        "UPDATE media_scan_ignored SET path_hash = :path_hash WHERE id = :id"
                    );
                    foreach ($pendingIgnored as $row) {
                        // Le righe con percorso relativo non valido restano
                        // intatte, come prima.
                        if ($this->portableRelativePart((string)($row['source_path'] ?? '')) === null) {
                            continue;
                        }
                        $park->execute([
                            ':path_hash' => sha1(self::PORTABLE_LIBRARY_PREFIX . 'pending/' . (int)$row['id']),
                            ':id'        => (int)$row['id'],
                        ]);
                    }

                    // Fase 2: percorso e hash definitivi. Se un'altra riga
                    // rappresenta già la stessa cartella, questa è un doppione
                    // e viene eliminata: lasciarla in forma @library/...
                    // impedirebbe per sempre di riattivare lo scanner.
                    $collision = $this->db->prepare(
                        "SELECT id FROM media_scan_ignored
                         WHERE path_hash = :path_hash AND id <> :id
                         LIMIT 1"
                    );
                    $update = $this->db->prepare(
                        "UPDATE media_scan_ignored
                         SET source_path = :source_path, path_hash = :path_hash
                         WHERE id = :id"
                    );
                    $dropDuplicate = $this->db->prepare(
                        "DELETE FROM media_scan_ignored WHERE id = :id"
                    );

                    foreach ($pendingIgnored as $row) {
                        $id = (int)$row['id'];
                        $relative = $this->portableRelativePart((string)($row['source_path'] ?? ''));
                        if ($relative === null) {
                            continue;
                        }

                        $newPath = rtrim($newRoot, '/') . '/' . $relative;
                        $newHash = sha1($newPath);

                        $collision->execute([':path_hash' => $newHash, ':id' => $id]);
                        $duplicate = $collision->fetchColumn() !== false;
                        $collision->closeCursor();

                        if ($duplicate) {
                            $dropDuplicate->execute([':id' => $id]);
                            continue;
                        }

                        $update->execute([
                            ':source_path' => $newPath,
                            ':path_hash'   => $newHash,
                            ':id'          => $id,
                        ]);
                        $ignoredUpdated += $update->rowCount();
                    }
                }
            }

            // I percorsi appena rimappati sono ora legati a $newRoot: il
            // binding va aggiornato nella stessa transazione. Senza questo
            // passaggio lo streaming, che serve solo file dentro
            // media_scan_bound_root, rifiuterebbe i file appena rimappati
            // finché il worker non riallinea il binding. Vale solo quando
            // esistevano riferimenti @library/... (cioè dopo un import): in un
            // normale cambio cartella il binding resta compito del worker.
            if ($audioUpdated + $ignoredUpdated > 0) {
                $this->upsertSetting(
                    'media_scan_bound_root',
                    $newRoot,
                    'Scanner root bound to indexed external audio paths'
                );
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'audio_files' => $audioUpdated,
            'ignored'     => $ignoredUpdated,
            'total'       => $audioUpdated + $ignoredUpdated,
        ];
    }

    // ============================================================
    // IMPORT
    // ============================================================

    /**
     * Importa un archivio ZIP precedentemente esportato.
     * SOSTITUISCE tutti i dati. Prima crea un backup di sicurezza.
     *
     * @param string $zipPath percorso del file ZIP caricato
     * @return array ['ok'=>bool, 'message'=>string, 'counts'=>array, 'backup'=>string]
     * @throws RuntimeException su errori bloccanti
     */
    public function import(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Estensione ZIP non disponibile su questo server.');
        }
        if (!is_file($zipPath)) {
            throw new RuntimeException('File di import non trovato.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Il file caricato non è un archivio ZIP valido.');
        }

        // Legge e valida data.json
        $rawData = $zip->getFromName('data.json');
        if ($rawData === false) {
            $zip->close();
            throw new RuntimeException('Archivio non valido: manca data.json.');
        }
        $payload = json_decode($rawData, true);
        if (!is_array($payload) || empty($payload['tables']) || !is_array($payload['tables'])) {
            $zip->close();
            throw new RuntimeException('Archivio non valido: dati illeggibili.');
        }

        // Archivi storici privi di __format vengono trattati come v1.
        // Un archivio creato da una versione FUTURA non va importato alla
        // cieca: insertRows() usa le colonne presenti nel payload e una
        // colonna sconosciuta al DB corrente farebbe fallire il restore
        // dopo aver gia' iniziato a sostituire i dati.
        $incomingFormat = isset($payload['__format']) ? (int)$payload['__format'] : 1;
        if ($incomingFormat < 1) {
            $zip->close();
            throw new RuntimeException('Archivio non valido: versione formato non riconosciuta.');
        }
        if ($incomingFormat > self::FORMAT_VERSION) {
            $zip->close();
            throw new RuntimeException(
                'Archivio creato da una versione piu recente di Grizzly '
                . '(formato v' . $incomingFormat . '; supportato fino a v' . self::FORMAT_VERSION . ').'
            );
        }

        // Settings specifiche della macchina di destinazione: non devono
        // essere sovrascritte dal backup importato.
        $localServerSettings = $this->readLocalServerSettings();
        $localCredentials    = $this->readLocalCredentials();

        // Backup di sicurezza del DB attuale (prima di toccare nulla)
        $backupPath = $this->backupCurrentData();

        // Ripristino dati in transazione, con FK temporaneamente disattivate
        $counts = [];
        try {
            $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->db->beginTransaction();

            foreach (self::TABLES as $table) {
                // Tabella assente su questo DB (non ancora migrato):
                // la si salta senza far fallire l'intero import
                if (!$this->tableExists($table)) {
                    $counts[$table] = 0;
                    continue;
                }
                $rows = $payload['tables'][$table] ?? [];

                if ($table === 'settings') {
                    $rows = $this->filterIncomingSettingsRows($rows);
                }

                // svuota la tabella
                $this->db->exec("DELETE FROM `$table`");
                // reinserisce
                $inserted = $this->insertRows($table, $rows);
                $counts[$table] = $inserted;
            }

            // Retrocompatibilità archivi v1 (senza album_formats):
            // ricostruisce la tabella ponte dalla colonna legacy
            // albums.format_id, così ogni album importato mantiene
            // il suo formato. INSERT IGNORE: innocuo sugli archivi v2.
            if ($this->tableExists('album_formats') && empty($counts['album_formats'])) {
                $this->db->exec("
                    INSERT IGNORE INTO album_formats (album_id, format_id)
                    SELECT id, format_id FROM albums WHERE format_id IS NOT NULL
                ");
                $counts['album_formats'] = (int)$this->db
                    ->query('SELECT COUNT(*) FROM album_formats')->fetchColumn();
            }

            // Retrocompatibilita' archivi v1-v4:
            // prima del formato v5 non esistevano deezer_artist_id e la cache
            // immagine indipendente. Gli URL/file immagine, pero', erano gia'
            // parte della riga artists e dello ZIP. Se li importassimo lasciando
            // i nuovi default (status=none/version=0), una foto valida risulterebbe
            // semanticamente "mai risolta". La marchiamo quindi come baseline
            // valida SENZA rifetch e SENZA inventare un Deezer ID.
            if (
                $incomingFormat < 5
                && $this->tableExists('artists')
                && $this->columnExists('artists', 'image_fetched_at')
                && $this->columnExists('artists', 'image_status')
                && $this->columnExists('artists', 'image_fetch_version')
            ) {
                $this->db->exec("
                    UPDATE artists
                    SET
                        image_fetched_at = COALESCE(
                            image_fetched_at,
                            bio_fetched_at,
                            created_at,
                            CURRENT_TIMESTAMP
                        ),
                        image_status = 'ok',
                        image_fetch_version = 1
                    WHERE
                        (image_local IS NOT NULL AND image_local <> '')
                        OR (image_url IS NOT NULL AND image_url <> '')
                ");

                $this->db->exec("
                    UPDATE artists
                    SET
                        image_status = 'none',
                        image_fetch_version = 0
                    WHERE
                        (image_local IS NULL OR image_local = '')
                        AND (image_url IS NULL OR image_url = '')
                ");

                // La v3 della bio e' esistita solo nella patch sperimentale
                // scartata prima della finalizzazione del formato v5.
                if ($this->columnExists('artists', 'bio_fetch_version')) {
                    $this->db->exec("
                        UPDATE artists
                        SET bio_fetch_version = 2
                        WHERE bio_fetch_version > 2
                    ");
                }
            }

            // Ripristina le settings specifiche del server di destinazione.
            if ($localServerSettings['audio_path'] !== null) {
                $this->upsertSetting(
                    'audio_path',
                    (string)$localServerSettings['audio_path'],
                    'Custom audio storage path'
                );
            }

            if ($localServerSettings['media_scan_path'] !== null) {
                $this->upsertSetting(
                    'media_scan_path',
                    (string)$localServerSettings['media_scan_path'],
                    'Folder to scan automatically'
                );
            }

            if ($localServerSettings['media_scan_bound_root'] !== null) {
                $this->upsertSetting(
                    'media_scan_bound_root',
                    (string)$localServerSettings['media_scan_bound_root'],
                    'Scanner root bound to indexed external audio paths'
                );
            }

            // Credenziali dei servizi esterni: restano quelle di questa
            // macchina, con la loro etichetta.
            foreach ($localCredentials as $cred) {
                $this->upsertSetting($cred['key'], $cred['value'], $cred['label']);
            }

            // Dopo un import lo scanner resta SEMPRE spento. I riferimenti
            // @library/... vengono rimappati solo quando l'utente conferma
            // esplicitamente la nuova root salvando la configurazione scanner.
            $this->upsertSetting(
                'media_scan_enabled',
                '0',
                'Enable automatic media folder scan'
            );

            $this->db->commit();
            $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
            $zip->close();
            throw new RuntimeException('Import fallito (ripristino annullato): ' . $e->getMessage());
        }

        // Ripristino immagini: estrae uploads/* nelle cartelle locali
        $this->restoreImages($zip);
        $zip->close();

        $portablePending = $this->countPortableLibraryPaths();

        return [
            'ok'               => true,
            'message'          => 'Import completato.',
            'counts'           => $counts,
            'backup'           => $backupPath,
            'portable_pending' => $portablePending,
            'scanner_disabled' => true,
        ];
    }

    /**
     * Inserisce le righe in una tabella usando prepared statement
     * costruito dinamicamente sulle colonne presenti.
     */
    private function insertRows(string $table, array $rows): int
    {
        if (!in_array($table, self::TABLES, true) || empty($rows)) {
            return 0;
        }

        $n = 0;
        $stmt = null;
        $cols = null;

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row)) {
                continue;
            }

            $rowCols = array_keys($row);

            // (Ri)prepara lo statement se cambiano le colonne
            if ($cols !== $rowCols) {
                $cols = $rowCols;
                $colList = '`' . implode('`,`', $cols) . '`';
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $stmt = $this->db->prepare("INSERT INTO `$table` ($colList) VALUES ($placeholders)");
            }

            $stmt->execute(array_values($row));
            $n++;
        }

        return $n;
    }

    /**
     * Backup di sicurezza dei dati attuali in un JSON su disco,
     * prima dell'import. Restituisce il percorso del file.
     */
    private function backupCurrentData(): string
    {
        $dir = UPLOAD_PATH . '/_backups';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            // se non riesce a creare la cartella, backup non bloccante
            return '';
        }

        $data = ['__format' => self::FORMAT_VERSION, 'tables' => []];
        foreach (self::TABLES as $table) {
            $data['tables'][$table] = $this->dumpTableRaw($table);
        }

        $path = $dir . '/backup-before-import-' . date('Ymd-His') . '.json';
        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $path;
    }

    /**
     * Verifica l'esistenza di una tabella nel database corrente.
     * Usa information_schema e NON "SHOW TABLES LIKE ?": quest'ultima
     * non è supportata dai prepared statement NATIVI di MySQL/MariaDB
     * (l'app gira con PDO::ATTR_EMULATE_PREPARES = false) e mandava
     * in errore l'export alla prima chiamata.
     */
    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name   = :t
        ');
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Verifica l'esistenza di una colonna senza usare SHOW ... LIKE,
     * mantenendo la compatibilita' con prepared statement nativi PDO.
     */
    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name   = :t
              AND column_name  = :c
        ');
        $stmt->execute([
            ':t' => $table,
            ':c' => $column,
        ]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Dump grezzo di una tabella per il backup pre-import.
     * Le credenziali API vengono escluse anche da questo backup locale
     * di sicurezza; le altre settings mantengono il comportamento storico.
     */
    private function dumpTableRaw(string $table): array
    {
        if (!in_array($table, self::TABLES, true) || !$this->tableExists($table)) {
            return [];
        }

        $rows = $this->db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);

        if ($table === 'settings') {
            $rows = array_values(array_filter($rows, function ($row) {
                return !$this->isCredentialKey((string)($row['key'] ?? ''));
            }));
        }

        return $rows;
    }

    /**
     * Estrae le immagini dallo ZIP nelle rispettive cartelle uploads/.
     * Sovrascrive i file con lo stesso nome.
     */
    private function restoreImages(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            // solo le entry dentro le cartelle ammesse (IMAGE_DIRS)
            if (strpos($name, 'uploads/') !== 0) {
                continue;
            }

            // path relativo dopo "uploads/"
            $rel = substr($name, strlen('uploads/'));
            if ($rel === '' || substr($rel, -1) === '/') {
                continue; // è una cartella
            }

            // Sicurezza: niente path traversal, solo cartelle ammesse
            $parts = explode('/', $rel);
            if (count($parts) < 2 || !in_array($parts[0], self::IMAGE_DIRS, true)) {
                continue;
            }
            if (strpos($rel, '..') !== false) {
                continue;
            }

            $destDir = UPLOAD_PATH . '/' . $parts[0];
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                continue;
            }

            $contents = $zip->getFromIndex($i);
            if ($contents !== false) {
                @file_put_contents(UPLOAD_PATH . '/' . $rel, $contents);
            }
        }
    }
}
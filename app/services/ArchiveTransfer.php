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

    /** Prefisso interno usato nello ZIP per i path relativi alla media_scan_path. */
    private const PORTABLE_LIBRARY_PREFIX = '@library/';

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
     */
    private const SETTINGS_SKIP = [
        'audio_path',
        'media_scan_path',
        'media_scan_enabled',
        'api_lastfm_key',
        'api_discogs_token',
        'api_youtube_key',
    ];

    /** Credenziali che non devono finire nemmeno nel backup JSON locale. */
    private const SECRET_SETTINGS = [
        'api_lastfm_key',
        'api_discogs_token',
        'api_youtube_key',
    ];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
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

        try {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->db->beginTransaction();

            $sourceLibraryRoot = trim((string)$this->getSettingValue('media_scan_path', ''));

            foreach (self::TABLES as $table) {
                $rows = $this->dumpTable($table);

                if ($table === 'audio_files') {
                    $rows = $this->makeExternalAudioPathsPortable($rows, $sourceLibraryRoot);
                } elseif ($table === 'media_scan_ignored') {
                    $rows = $this->makeIgnoredPathsPortable($rows, $sourceLibraryRoot);
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

        $manifest = [
            'format'              => self::FORMAT_VERSION,
            'created_at'          => date('c'),
            'app'                 => 'Grizzly Music Archive',
            'counts'              => $counts,
            'library_path_mode'   => 'relative-v1',
            'scanner_restores_on' => false,
        ];

        // File ZIP temporaneo
        $tmpDir = sys_get_temp_dir();
        $zipPath = $tmpDir . '/grizzly-export-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile creare il file ZIP.');
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('data.json', json_encode($data, JSON_UNESCAPED_UNICODE));

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
        return $zipPath;
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
                return !in_array($r['key'] ?? '', self::SETTINGS_SKIP, true);
            }));
        }

        return $rows;
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
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    /**
     * Converte un path assoluto appartenente alla media_scan_path in
     * @library/<percorso-relativo>. Se il path è fuori dalla root l'export
     * viene fermato: meglio un errore esplicito che un backup non portabile.
     */
    private function toPortableLibraryPath(string $path, string $root): string
    {
        $path = $this->normalizePath($path);
        if ($path === '') {
            return '';
        }

        if (strpos($path, self::PORTABLE_LIBRARY_PREFIX) === 0) {
            return $path;
        }

        $root = $this->normalizePath($root);
        if ($root === '') {
            throw new RuntimeException(
                'Export portabile impossibile: esistono riferimenti alla libreria external, '
                . 'ma media_scan_path non è configurata.'
            );
        }

        if ($root === '/') {
            if ($path[0] !== '/') {
                throw new RuntimeException('Percorso external non valido: ' . $path);
            }
            $relative = ltrim($path, '/');
        } else {
            if ($path === $root) {
                $relative = '';
            } elseif (strpos($path, $root . '/') === 0) {
                $relative = substr($path, strlen($root) + 1);
            } else {
                throw new RuntimeException(
                    'Export portabile impossibile: il percorso non appartiene a media_scan_path: ' . $path
                );
            }
        }

        if ($relative === '' || preg_match('~(^|/)\.\.?(/|$)~', $relative)) {
            throw new RuntimeException('Percorso relativo libreria non valido: ' . $path);
        }

        return self::PORTABLE_LIBRARY_PREFIX . $relative;
    }

    private function makeExternalAudioPathsPortable(array $rows, string $root): array
    {
        foreach ($rows as &$row) {
            if (!is_array($row) || ($row['storage_type'] ?? '') !== 'external') {
                continue;
            }

            $sourcePath = trim((string)($row['source_path'] ?? ''));
            if ($sourcePath === '') {
                continue;
            }

            $row['source_path'] = $this->toPortableLibraryPath($sourcePath, $root);
        }
        unset($row);

        return $rows;
    }

    private function makeIgnoredPathsPortable(array $rows, string $root): array
    {
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $sourcePath = trim((string)($row['source_path'] ?? ''));
            if ($sourcePath === '') {
                continue;
            }

            $row['source_path'] = $this->toPortableLibraryPath($sourcePath, $root);
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
            return is_array($row)
                && !in_array((string)($row['key'] ?? ''), self::SETTINGS_SKIP, true);
        }));
    }

    private function readLocalServerSettings(): array
    {
        $out = [
            'audio_path'        => null,
            'media_scan_path'   => null,
            'api_lastfm_key'    => null,
            'api_discogs_token' => null,
            'api_youtube_key'   => null,
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
                 'api_lastfm_key',
                 'api_discogs_token',
                 'api_youtube_key'
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
                $update = $this->db->prepare(
                    "UPDATE media_scan_ignored SET source_path = :source_path WHERE id = :id"
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
                    $ignoredUpdated += $update->rowCount();
                }
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

            if ($localServerSettings['api_lastfm_key'] !== null) {
                $this->upsertSetting(
                    'api_lastfm_key',
                    (string)$localServerSettings['api_lastfm_key'],
                    'Last.fm API key'
                );
            }

            if ($localServerSettings['api_discogs_token'] !== null) {
                $this->upsertSetting(
                    'api_discogs_token',
                    (string)$localServerSettings['api_discogs_token'],
                    'Discogs personal access token'
                );
            }

            if ($localServerSettings['api_youtube_key'] !== null) {
                $this->upsertSetting(
                    'api_youtube_key',
                    (string)$localServerSettings['api_youtube_key'],
                    'YouTube Data API key'
                );
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
                return !in_array((string)($row['key'] ?? ''), self::SECRET_SETTINGS, true);
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
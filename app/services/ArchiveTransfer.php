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
     * L'import accetta entrambe: per gli archivi v1 la tabella ponte
     * viene ricostruita dalla colonna legacy (vedi import()).
     */
    private const FORMAT_VERSION = 2;

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
        'settings',
    ];

    /** Sottocartelle immagini da includere (NO discography, NO audio) */
    private const IMAGE_DIRS = ['covers', 'artists'];

    /** Chiavi settings da NON esportare (specifiche del server) */
    private const SETTINGS_SKIP = ['audio_path'];

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

        // Dump dati
        $data = ['__format' => self::FORMAT_VERSION, 'tables' => []];
        $counts = [];
        foreach (self::TABLES as $table) {
            $rows = $this->dumpTable($table);
            $data['tables'][$table] = $rows;
            $counts[$table] = count($rows);
        }

        $manifest = [
            'format'     => self::FORMAT_VERSION,
            'created_at' => date('c'),
            'app'        => 'Grizzly Music Archive',
            'counts'     => $counts,
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

        return [
            'ok'      => true,
            'message' => 'Import completato.',
            'counts'  => $counts,
            'backup'  => $backupPath,
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

    /** Verifica l'esistenza di una tabella nel database corrente. */
    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare('SHOW TABLES LIKE :t');
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Dump grezzo di una tabella per il backup pre-import
     * (senza il filtro settings di dumpTable). Tabella assente → [].
     */
    private function dumpTableRaw(string $table): array
    {
        if (!in_array($table, self::TABLES, true) || !$this->tableExists($table)) {
            return [];
        }
        return $this->db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
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
            // solo le entry dentro uploads/covers/ e uploads/artists/
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
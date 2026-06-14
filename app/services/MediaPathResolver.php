<?php

/**
 * MediaPathResolver
 *
 * Astrae il percorso fisico e gli URL dei file audio.
 * Le cover restano sempre in public/uploads/covers/ (webroot),
 * servite direttamente da Apache senza overhead PHP.
 *
 * L'audio può essere ricollocato su un path esterno configurabile
 * tramite la tabella `settings` (chiave: audio_path).
 *
 * Logica di risoluzione:
 *   1. Legge `audio_path` dalla tabella settings (cache in memoria per request)
 *   2. Se vuoto/non impostato, usa la costante AUDIO_PATH da config.php
 *   3. Gli URL audio passano sempre per MediaController (?route=media/audio/filename)
 *      in modo da funzionare sia con path dentro che fuori dalla webroot
 *
 * Posizionare in: app/services/MediaPathResolver.php
 */
class MediaPathResolver
{
    /** @var string|null Cache per la request corrente */
    private static $resolvedBasePath = null;

    // ----------------------------------------------------------
    // Path fisico assoluto della cartella audio
    // ----------------------------------------------------------

    /**
     * Restituisce il path assoluto della cartella audio.
     * Il path salvato in settings è già la cartella finale dei file —
     * nessuna concatenazione aggiuntiva viene effettuata.
     * Es: /Applications/MAMP/htdocs/supergrizzly/public/uploads/audio
     *     oppure /Volumes/WDBlack/test
     */
    public static function getAudioDir(): string
    {
        return rtrim(self::resolveBasePath(), '/');
    }

    /**
     * Path assoluto completo per un singolo file audio (solo filename, no path).
     */
    public static function getAudioAbsPath(string $filename): string
    {
        return self::getAudioDir() . '/' . basename($filename);
    }

    // ----------------------------------------------------------
    // URL pubblico per il browser
    // ----------------------------------------------------------

    /**
     * URL dello streaming PHP per un file audio.
     * Funziona sia con file nella webroot che fuori.
     * Il MediaController legge il file e lo streama con Range support.
     */
    public static function getStreamUrl(string $filename): string
    {
        return BASE_URL . '/index.php?route=media/audio/' . urlencode(basename($filename));
    }

    /**
     * URL per download diretto (Content-Disposition: attachment).
     */
    public static function getDownloadUrl(string $filename): string
    {
        return BASE_URL . '/index.php?route=media/download/' . urlencode(basename($filename));
    }

    // ----------------------------------------------------------
    // Lettura e test del path configurato
    // ----------------------------------------------------------

    /**
     * Restituisce il path configurato (dalla tabella settings o fallback config.php).
     */
    public static function getConfiguredPath(): string
    {
        return self::resolveBasePath();
    }

    /**
     * Aggiorna il path nella tabella settings.
     * Passa una stringa vuota per tornare al default (AUDIO_PATH da config.php).
     *
     * @throws RuntimeException se il path non è valido o non scrivibile
     */
    public static function setConfiguredPath(string $path): void
    {
        $path = trim($path);

        if ($path !== '') {
            // Normalizza separatori (compatibilità Windows/Mac)
            $path = str_replace('\\', '/', $path);
            $path = rtrim($path, '/');

            // Validazione base
            if (!is_dir($path)) {
                throw new RuntimeException('Il percorso non esiste o non è una cartella: ' . $path);
            }
            if (!is_writable($path)) {
                throw new RuntimeException('Il percorso non è scrivibile: ' . $path);
            }
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO settings (`key`, `value`)
            VALUES ('audio_path', :val_insert)
            ON DUPLICATE KEY UPDATE `value` = :val_update
        ");
        $stmt->execute([':val_insert' => $path, ':val_update' => $path]);

        // Invalida la cache per la request corrente
        self::$resolvedBasePath = null;
    }

    /**
     * Verifica che la cartella audio (quella effettivamente usata) esista e sia scrivibile.
     * Utile per la pagina Settings.
     *
     * @return array{ok: bool, path: string, message: string}
     */
    public static function testAudioPath(): array
    {
        $dir = self::getAudioDir();

        if (!is_dir($dir)) {
            return [
                'ok'      => false,
                'path'    => $dir,
                'message' => 'Cartella non trovata: ' . $dir,
            ];
        }
        if (!is_writable($dir)) {
            return [
                'ok'      => false,
                'path'    => $dir,
                'message' => 'Cartella non scrivibile: ' . $dir,
            ];
        }

        // Conta tutti i file audio presenti (MP3 + FLAC)
        $files = self::getAudioFiles($dir);
        $count = count($files);

        if ($count === 0) {
            return [
                'ok'      => false,
                'path'    => $dir,
                'message' => 'Nessun file audio trovato in: ' . $dir,
                'count'   => 0,
            ];
        }

        return [
            'ok'      => true,
            'path'    => $dir,
            'message' => 'OK — ' . $count . ' file audio presenti',
            'count'   => $count,
        ];
    }

    /**
     * Conta i file audio e calcola la dimensione totale nella cartella corrente.
     *
     * @return array{count: int, size_bytes: int, size_human: string}
     */
    public static function getAudioStats(): array
    {
        $dir   = self::getAudioDir();
        $files = is_dir($dir) ? self::getAudioFiles($dir) : [];
        $total = 0;
        foreach ($files as $f) {
            $total += filesize($f);
        }
        return [
            'count'      => count($files),
            'size_bytes' => $total,
            'size_human' => self::humanBytes($total),
        ];
    }

    // ----------------------------------------------------------
    // Migrazione file
    // ----------------------------------------------------------

    /**
     * Copia tutti i file audio dal path attuale al nuovo path.
     * NON cambia il setting — chiama setConfiguredPath() separatamente
     * solo dopo che la copia è andata a buon fine.
     *
     * @param  string $newDir  Path assoluto della nuova cartella audio
     * @return array{ok: bool, moved: int, errors: string[], skipped: int}
     */
    public static function migrateAudioFiles(string $newDir): array
    {
        $newDir = rtrim(str_replace('\\', '/', $newDir), '/');
        $srcDir = self::getAudioDir();
        $result = ['ok' => true, 'moved' => 0, 'errors' => [], 'skipped' => 0];

        if (!is_dir($newDir)) {
            if (!mkdir($newDir, 0755, true)) {
                $result['ok']       = false;
                $result['errors'][] = 'Impossibile creare la cartella di destinazione: ' . $newDir;
                return $result;
            }
        }

        if (!is_writable($newDir)) {
            $result['ok']       = false;
            $result['errors'][] = 'Cartella di destinazione non scrivibile: ' . $newDir;
            return $result;
        }

        // Migra MP3 e FLAC
        $files = self::getAudioFiles($srcDir);

        foreach ($files as $srcFile) {
            $filename = basename($srcFile);
            $destFile = $newDir . '/' . $filename;

            if (file_exists($destFile)) {
                $result['skipped']++;
                continue;
            }

            if (copy($srcFile, $destFile)) {
                $result['moved']++;
            } else {
                $result['ok']       = false;
                $result['errors'][] = 'Copia fallita: ' . $filename;
            }
        }

        return $result;
    }

    // ----------------------------------------------------------
    // Internals
    // ----------------------------------------------------------

    /**
     * Restituisce tutti i file audio (MP3 + FLAC) presenti in una cartella.
     *
     * @param  string $dir  Path assoluto della cartella
     * @return string[]     Array di path assoluti
     */
    private static function getAudioFiles(string $dir): array
    {
        $dir   = rtrim($dir, '/');
        $mp3   = glob($dir . '/*.mp3')  ?: [];
        $flac  = glob($dir . '/*.flac') ?: [];
        return array_merge($mp3, $flac);
    }

    /**
     * Risolve il path base con cache per la request corrente.
     * Legge da DB, fallback a costante AUDIO_PATH.
     */
    private static function resolveBasePath(): string
    {
        if (self::$resolvedBasePath !== null) {
            return self::$resolvedBasePath;
        }

        try {
            $db   = Database::getInstance();
            $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'audio_path' LIMIT 1");
            $stmt->execute();
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && trim($row['value']) !== '') {
                self::$resolvedBasePath = rtrim(trim($row['value']), '/');
                return self::$resolvedBasePath;
            }
        } catch (Exception $e) {
            // Tabella settings non ancora creata o errore DB: usa fallback
        }

        // Fallback: AUDIO_PATH da config.php punta già alla cartella finale dei file audio
        self::$resolvedBasePath = defined('AUDIO_PATH') ? AUDIO_PATH : (BASE_PATH . '/public/uploads/audio');
        return self::$resolvedBasePath;
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
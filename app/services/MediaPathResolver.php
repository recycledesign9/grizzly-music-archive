<?php

/**
 * MediaPathResolver
 *
 * Astrae il percorso configurato dall'utente dal percorso fisico usato
 * dal processo PHP.
 *
 * In installazioni native i due percorsi coincidono.
 *
 * In Docker il percorso scelto dall'utente resta sempre il percorso reale
 * dell'host (es. /mnt/media/Grizzly/audio), mentre a runtime viene tradotto
 * automaticamente sotto MEDIA_HOST_PREFIX / MEDIA_SCAN_HOST_PREFIX
 * (es. /hostfs/mnt/media/Grizzly/audio).
 *
 * Il path predefinito interno di Grizzly (AUDIO_PATH) non viene mai
 * prefissato: continua a vivere nel volume uploads_data.
 */
class MediaPathResolver
{
    /** @var string|null Cache del valore DB per la request corrente. */
    private static $configuredDbPath = null;

    /** @var bool */
    private static $configuredDbPathLoaded = false;

    // ----------------------------------------------------------
    // Path audio
    // ----------------------------------------------------------

    /**
     * Restituisce il percorso fisico realmente usato da PHP.
     *
     * - path personalizzato: host path -> runtime path Docker
     * - default: AUDIO_PATH interno all'applicazione
     */
    public static function getAudioDir(): string
    {
        $configured = self::getConfiguredDbPath();

        if ($configured !== '') {
            return rtrim(self::toRuntimePath($configured), '/');
        }

        return rtrim(self::defaultAudioPath(), '/');
    }

    /**
     * Path fisico completo per un file managed.
     */
    public static function getAudioAbsPath(string $filename): string
    {
        return self::getAudioDir() . '/' . basename($filename);
    }

    // ----------------------------------------------------------
    // URL pubblici
    // ----------------------------------------------------------

    public static function getStreamUrl(string $filename): string
    {
        return BASE_URL . '/index.php?route=media/audio/' . urlencode(basename($filename));
    }

    public static function getDownloadUrl(string $filename): string
    {
        return BASE_URL . '/index.php?route=media/download/' . urlencode(basename($filename));
    }

    // ----------------------------------------------------------
    // Path configurato / mapping host <-> runtime
    // ----------------------------------------------------------

    /**
     * Restituisce il percorso da mostrare all'utente.
     *
     * Se audio_path è configurato ritorna esattamente il path host salvato.
     * In assenza di override ritorna il default interno di Grizzly.
     */
    public static function getConfiguredPath(): string
    {
        $configured = self::getConfiguredDbPath();

        return $configured !== '' ? $configured : self::defaultAudioPath();
    }

    /**
     * Restituisce solo il valore personalizzato salvato nel DB.
     * Stringa vuota = usa il default interno.
     */
    public static function getConfiguredDbPath(): string
    {
        if (self::$configuredDbPathLoaded) {
            return (string)self::$configuredDbPath;
        }

        self::$configuredDbPathLoaded = true;
        self::$configuredDbPath = '';

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'audio_path' LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && trim((string)$row['value']) !== '') {
                self::$configuredDbPath = self::normalizePath((string)$row['value']);
            }
        } catch (Exception $e) {
            // DB/settings non disponibili: resta attivo il default.
        }

        return (string)self::$configuredDbPath;
    }

    /**
     * True quando Docker espone il filesystem host sotto un prefisso runtime.
     */
    public static function hasHostMapping(): bool
    {
        return self::hostPrefix() !== '';
    }

    /**
     * Traduce un percorso visibile all'utente nel percorso realmente usato
     * dal container.
     *
     * I path interni all'applicazione non vengono prefissati.
     */
    public static function toRuntimePath(string $path): string
    {
        $path = self::normalizePath($path);

        if ($path === '') {
            return '';
        }

        $prefix = self::hostPrefix();

        if ($prefix === '' || self::isInternalAppPath($path)) {
            return $path;
        }

        // Evita doppi prefissi in caso di chiamate interne.
        if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
            return $path;
        }

        if ($path === '/') {
            return $prefix;
        }

        return $prefix . '/' . ltrim($path, '/');
    }

    /**
     * Converte un path runtime Docker nel path host mostrato all'utente.
     */
    public static function toDisplayPath(string $path): string
    {
        $path = self::normalizePath($path);
        $prefix = self::hostPrefix();

        if ($prefix === '') {
            return $path;
        }

        if ($path === $prefix) {
            return '/';
        }

        if (strpos($path, $prefix . '/') === 0) {
            $display = substr($path, strlen($prefix));
            return $display === '' ? '/' : $display;
        }

        return $path;
    }

    /**
     * Salva un nuovo path utente.
     *
     * Il DB conserva sempre il path host leggibile dall'utente, mai /hostfs.
     */
    public static function setConfiguredPath(string $path): void
    {
        $path = self::normalizePath($path);

        if ($path !== '') {
            $runtime = self::toRuntimePath($path);

            if (!is_dir($runtime)) {
                throw new RuntimeException('Il percorso non esiste o non è una cartella: ' . $path);
            }

            if (!is_writable($runtime)) {
                throw new RuntimeException(
                    'La cartella esiste ma Grizzly non può scriverci: ' . $path
                );
            }
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO settings (`key`, `value`)
            VALUES ('audio_path', :val_insert)
            ON DUPLICATE KEY UPDATE `value` = :val_update
        ");
        $stmt->execute([
            ':val_insert' => $path,
            ':val_update' => $path,
        ]);

        self::$configuredDbPath = $path;
        self::$configuredDbPathLoaded = true;
    }

    /**
     * Testa un percorso mostrato all'utente senza salvarlo.
     *
     * @return array{ok: bool, path: string, message: string, count?: int, writable?: bool}
     */
    public static function testPath(string $path): array
    {
        $display = self::normalizePath($path);

        if ($display === '') {
            $display = self::getConfiguredPath();
        }

        $runtime = self::pathIsConfiguredDefault($display)
            ? self::defaultAudioPath()
            : self::toRuntimePath($display);

        if (!is_dir($runtime)) {
            return [
                'ok' => false,
                'path' => $display,
                'message' => 'Cartella non trovata: ' . $display,
                'writable' => false,
            ];
        }

        if (!is_readable($runtime)) {
            return [
                'ok' => false,
                'path' => $display,
                'message' => 'Cartella non leggibile: ' . $display,
                'writable' => false,
            ];
        }

        if (!is_writable($runtime)) {
            return [
                'ok' => false,
                'path' => $display,
                'message' => 'Cartella raggiungibile ma non scrivibile da Grizzly: ' . $display,
                'writable' => false,
            ];
        }

        $files = self::getAudioFiles($runtime);
        $count = count($files);

        return [
            'ok' => true,
            'path' => $display,
            'message' => $count > 0
                ? 'OK — ' . $count . ' file audio presenti'
                : 'OK — cartella raggiungibile e scrivibile',
            'count' => $count,
            'writable' => true,
        ];
    }

    /**
     * Testa la cartella attualmente attiva.
     */
    public static function testAudioPath(): array
    {
        return self::testPath(self::getConfiguredPath());
    }

    /**
     * Conta MP3/FLAC e dimensione totale della cartella attiva.
     *
     * @return array{count: int, size_bytes: int, size_human: string}
     */
    public static function getAudioStats(): array
    {
        $dir = self::getAudioDir();
        $files = is_dir($dir) ? self::getAudioFiles($dir) : [];
        $total = 0;

        foreach ($files as $file) {
            $size = @filesize($file);
            if ($size !== false) {
                $total += $size;
            }
        }

        return [
            'count' => count($files),
            'size_bytes' => $total,
            'size_human' => self::humanBytes($total),
        ];
    }

    // ----------------------------------------------------------
    // Migrazione file
    // ----------------------------------------------------------

    /**
     * Copia tutti i file audio managed nel nuovo path.
     * Il path passato è sempre quello visibile all'utente.
     */
    public static function migrateAudioFiles(string $newDir): array
    {
        $displayTarget = self::normalizePath($newDir);
        $runtimeTarget = self::toRuntimePath($displayTarget);
        $srcDir = self::getAudioDir();

        $result = [
            'ok' => true,
            'moved' => 0,
            'errors' => [],
            'skipped' => 0,
        ];

        if (!is_dir($runtimeTarget)) {
            if (!@mkdir($runtimeTarget, 0755, true) && !is_dir($runtimeTarget)) {
                $result['ok'] = false;
                $result['errors'][] =
                    'Impossibile creare la cartella di destinazione: ' . $displayTarget;
                return $result;
            }
        }

        if (!is_writable($runtimeTarget)) {
            $result['ok'] = false;
            $result['errors'][] =
                'Cartella di destinazione non scrivibile: ' . $displayTarget;
            return $result;
        }

        foreach (self::getAudioFiles($srcDir) as $srcFile) {
            $filename = basename($srcFile);
            $destFile = rtrim($runtimeTarget, '/') . '/' . $filename;

            if (file_exists($destFile)) {
                $result['skipped']++;
                continue;
            }

            if (@copy($srcFile, $destFile)) {
                $result['moved']++;
            } else {
                $result['ok'] = false;
                $result['errors'][] = 'Copia fallita: ' . $filename;
            }
        }

        return $result;
    }

    // ----------------------------------------------------------
    // Internals
    // ----------------------------------------------------------

    private static function defaultAudioPath(): string
    {
        return defined('AUDIO_PATH')
            ? rtrim((string)AUDIO_PATH, '/')
            : rtrim(BASE_PATH . '/public/uploads/audio', '/');
    }

    private static function pathIsConfiguredDefault(string $path): bool
    {
        return self::normalizePath($path) === self::normalizePath(self::defaultAudioPath());
    }

    private static function hostPrefix(): string
    {
        $prefix = trim((string)getenv('MEDIA_HOST_PREFIX'));

        if ($prefix === '') {
            // Compatibilità con le installazioni Docker già esistenti.
            $prefix = trim((string)getenv('MEDIA_SCAN_HOST_PREFIX'));
        }

        return $prefix === '' ? '' : rtrim(self::normalizePath($prefix), '/');
    }

    private static function isInternalAppPath(string $path): bool
    {
        $base = rtrim(self::normalizePath(BASE_PATH), '/');
        $path = self::normalizePath($path);

        if ($base === '' || $path === '') {
            return false;
        }

        return $path === $base || strpos($path, $base . '/') === 0;
    }

    private static function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '') {
            return '';
        }

        // Mantiene intatta la root Unix.
        if ($path === '/') {
            return '/';
        }

        // Mantiene C:/ su Windows.
        if (preg_match('/^[A-Za-z]:\/$/', $path)) {
            return $path;
        }

        return rtrim($path, '/');
    }

    /**
     * @return string[]
     */
    private static function getAudioFiles(string $dir): array
    {
        $dir = rtrim($dir, '/');
        $mp3 = glob($dir . '/*.mp3') ?: [];
        $flac = glob($dir . '/*.flac') ?: [];

        return array_merge($mp3, $flac);
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}

<?php

/**
 * MediaScanExclusionService
 *
 * Gestisce le directory escluse esplicitamente dalla scansione automatica.
 *
 * Queste esclusioni NON sono tombstone: media_scan_ignored continua a
 * rappresentare esclusivamente le sorgenti eliminate volontariamente
 * dall'archivio e che non devono essere ricreate.
 *
 * I path persistiti sono sempre in coordinate host. In Docker vengono
 * convertiti nel path runtime tramite MEDIA_SCAN_HOST_PREFIX.
 */
class MediaScanExclusionService
{
    /** @var PDO */
    private $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    /**
     * Normalizza soltanto la rappresentazione del path.
     * Non richiede che il percorso esista.
     */
    public static function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }

        if ($path === '/') {
            return '/';
        }

        if (preg_match('~^[A-Za-z]:/$~', $path)) {
            return strtoupper($path[0]) . ':/';
        }

        if (preg_match('~^[A-Za-z]:/~', $path)) {
            $path = strtoupper($path[0]) . substr($path, 1);
        }

        return rtrim($path, '/');
    }

    public static function isAbsolutePath(string $path): bool
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            return false;
        }

        return $path[0] === '/'
            || (bool)preg_match('~^[A-Za-z]:/~', $path);
    }

    public static function hasTraversalSegments(string $path): bool
    {
        $path = self::normalizePath($path);
        return $path !== '' && (bool)preg_match('~(^|/)\.\.?(/|$)~', $path);
    }

    private static function comparisonKey(string $path): string
    {
        $path = self::normalizePath($path);

        // I drive Windows sono case-insensitive nella configurazione tipica.
        if (preg_match('~^[A-Za-z]:/~', $path)) {
            return strtolower($path);
        }

        return $path;
    }

    /**
     * True se $path coincide con $root oppure è un suo discendente reale
     * dal punto di vista lessicale del path. Il separatore evita falsi match
     * come /import/cache-old per /import/cache.
     */
    public static function isSameOrDescendant(string $path, string $root): bool
    {
        $path = self::comparisonKey($path);
        $root = self::comparisonKey($root);

        if ($path === '' || $root === '') {
            return false;
        }

        if ($path === $root) {
            return true;
        }

        if ($root === '/') {
            return isset($path[0]) && $path[0] === '/';
        }

        if (preg_match('~^[a-z]:/$~i', $root)) {
            return strpos($path, $root) === 0 && $path !== $root;
        }

        return strpos($path, $root . '/') === 0;
    }

    public static function isStrictDescendant(string $path, string $root): bool
    {
        $pathKey = self::comparisonKey($path);
        $rootKey = self::comparisonKey($root);

        return $pathKey !== ''
            && $rootKey !== ''
            && $pathKey !== $rootKey
            && self::isSameOrDescendant($path, $root);
    }

    /**
     * Traduce il path host salvato nel DB nel path visibile dal processo.
     */
    public static function runtimePathFromHost(string $hostPath): string
    {
        $hostPath = self::normalizePath($hostPath);
        if ($hostPath === '') {
            return '';
        }

        $prefix = getenv('MEDIA_SCAN_HOST_PREFIX');
        $prefix = $prefix === false ? '' : self::normalizePath((string)$prefix);

        if ($prefix === '') {
            return $hostPath;
        }

        if ($hostPath === '/') {
            return $prefix;
        }

        return $prefix . '/' . ltrim($hostPath, '/');
    }

    /**
     * Restituisce tutte le esclusioni configurate.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listAll(): array
    {
        try {
            $stmt = $this->db->query("
                SELECT id, path_hash, source_path, created_at
                FROM media_scan_excluded
                ORDER BY source_path ASC, id ASC
            ");

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            // Compatibilità durante l'installazione della migration.
            return [];
        }
    }

    public function count(): int
    {
        try {
            return (int)$this->db
                ->query("SELECT COUNT(*) FROM media_scan_excluded")
                ->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Valida una nuova esclusione rispetto alla watched root.
     *
     * Il path può non esistere ancora: in quel caso il controllo resta
     * lessicale e viene restituito un warning, non un errore.
     *
     * @return array{ok:bool,path:string,exists:bool,warning:string,message:string}
     */
    public function validate(string $path, string $watchedRoot): array
    {
        $path = self::normalizePath($path);
        $watchedRoot = self::normalizePath($watchedRoot);

        $result = [
            'ok'      => false,
            'path'    => $path,
            'exists'  => false,
            'warning' => '',
            'message' => '',
        ];

        if ($watchedRoot === '') {
            $result['message'] = 'Configura prima la cartella da scansionare.';
            return $result;
        }

        if (!self::isAbsolutePath($watchedRoot) || self::hasTraversalSegments($watchedRoot)) {
            $result['message'] = 'La cartella da scansionare configurata non è valida.';
            return $result;
        }

        if ($path === '') {
            $result['message'] = 'Inserisci una cartella da escludere.';
            return $result;
        }

        if (!self::isAbsolutePath($path)) {
            $result['message'] = 'La cartella esclusa deve essere un percorso assoluto.';
            return $result;
        }

        if (self::hasTraversalSegments($path)) {
            $result['message'] = 'Il percorso escluso non può contenere segmenti "." o "..".';
            return $result;
        }

        if (!self::isStrictDescendant($path, $watchedRoot)) {
            $result['message'] = self::comparisonKey($path) === self::comparisonKey($watchedRoot)
                ? 'Non puoi escludere l’intera cartella da scansionare.'
                : 'La cartella esclusa deve trovarsi dentro la cartella da scansionare.';
            return $result;
        }

        $runtimeRoot = self::runtimePathFromHost($watchedRoot);
        $runtimePath = self::runtimePathFromHost($path);

        $realRoot = $runtimeRoot !== '' ? realpath($runtimeRoot) : false;
        $realPath = $runtimePath !== '' ? realpath($runtimePath) : false;

        if ($realPath !== false && !is_dir($realPath)) {
            $result['message'] = 'Il percorso indicato esiste ma non è una directory.';
            return $result;
        }

        $result['exists'] = $realPath !== false && is_dir($realPath);

        // Se entrambi esistono, risolviamo i symlink e verifichiamo che il
        // percorso reale non esca dalla watched root.
        if ($realRoot !== false && $realPath !== false) {
            $canonicalRoot = self::normalizePath((string)$realRoot);
            $canonicalPath = self::normalizePath((string)$realPath);

            if (!self::isStrictDescendant($canonicalPath, $canonicalRoot)) {
                $result['message'] = 'La cartella risolta dal filesystem non si trova realmente dentro la cartella da scansionare.';
                return $result;
            }
        }

        if (!$result['exists']) {
            $result['warning'] = 'La cartella non esiste ancora o non è raggiungibile. L’esclusione verrà comunque applicata quando il percorso sarà disponibile.';
        }

        $result['ok'] = true;
        return $result;
    }

    /**
     * Inserisce o aggiorna un'esclusione.
     *
     * @return array{ok:bool,row:?array,warning:string,message:string}
     */
    public function add(string $path, string $watchedRoot): array
    {
        $validation = $this->validate($path, $watchedRoot);

        if (!$validation['ok']) {
            return [
                'ok'      => false,
                'row'     => null,
                'warning' => '',
                'message' => $validation['message'],
            ];
        }

        $normalized = $validation['path'];
        $hash = sha1($normalized);

        try {
            $stmt = $this->db->prepare("
                INSERT INTO media_scan_excluded (path_hash, source_path)
                VALUES (:path_hash, :source_path)
                ON DUPLICATE KEY UPDATE
                    source_path = VALUES(source_path),
                    created_at = created_at
            ");
            $stmt->execute([
                ':path_hash'   => $hash,
                ':source_path' => $normalized,
            ]);

            $rowStmt = $this->db->prepare("
                SELECT id, path_hash, source_path, created_at
                FROM media_scan_excluded
                WHERE path_hash = :path_hash
                LIMIT 1
            ");
            $rowStmt->execute([':path_hash' => $hash]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC);

            return [
                'ok'      => true,
                'row'     => $row ?: null,
                'warning' => $validation['warning'],
                'message' => 'Cartella esclusa dalla scansione.',
            ];
        } catch (Throwable $e) {
            return [
                'ok'      => false,
                'row'     => null,
                'warning' => '',
                'message' => 'Impossibile salvare la cartella esclusa.'
                    . (defined('DEBUG') && DEBUG ? ' ' . $e->getMessage() : ''),
            ];
        }
    }

    public function remove(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        try {
            $stmt = $this->db->prepare("DELETE FROM media_scan_excluded WHERE id = :id");
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Restituisce le esclusioni convertite nel sistema di coordinate runtime
     * e pertinenti alla root che stiamo realmente scansionando.
     *
     * @return array<int,string>
     */
    public function runtimeExcludedRoots(string $runtimeImportRoot): array
    {
        $realRoot = realpath($runtimeImportRoot);
        $runtimeRoot = self::normalizePath(
            $realRoot !== false ? (string)$realRoot : $runtimeImportRoot
        );

        if ($runtimeRoot === '') {
            return [];
        }

        $out = [];

        foreach ($this->listAll() as $row) {
            $hostPath = self::normalizePath((string)($row['source_path'] ?? ''));
            if ($hostPath === '') {
                continue;
            }

            $runtimePath = self::runtimePathFromHost($hostPath);
            if ($runtimePath === '') {
                continue;
            }

            $realExcluded = realpath($runtimePath);
            $runtimePath = self::normalizePath(
                $realExcluded !== false ? (string)$realExcluded : $runtimePath
            );

            // L'esclusione è pertinente quando si trova dentro la root corrente
            // oppure quando la root corrente è stata aperta direttamente dentro
            // un sottoalbero escluso (scan manuale/CLI di una directory esclusa).
            if (!self::isSameOrDescendant($runtimePath, $runtimeRoot)
                && !self::isSameOrDescendant($runtimeRoot, $runtimePath)) {
                continue;
            }

            $out[$runtimePath] = true;
        }

        $roots = array_keys($out);

        // Prima i path più corti: un padre rende ridondanti i figli.
        usort($roots, function (string $a, string $b): int {
            $la = strlen($a);
            $lb = strlen($b);
            if ($la === $lb) {
                return strcmp($a, $b);
            }
            return $la <=> $lb;
        });

        $collapsed = [];
        foreach ($roots as $root) {
            if (!self::pathInAnySubtree($root, $collapsed)) {
                $collapsed[] = $root;
            }
        }

        return $collapsed;
    }

    public static function pathInAnySubtree(string $path, array $roots): bool
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            return false;
        }

        foreach ($roots as $root) {
            $root = self::normalizePath((string)$root);
            if ($root !== '' && self::isSameOrDescendant($path, $root)) {
                return true;
            }
        }

        return false;
    }
}

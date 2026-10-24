<?php

class AlbumReadinessStateRepository
{
    private string $stateFile;

    /** @var array<string,mixed> */
    private array $state = [
        'version' => 1,
        'updated_at' => null,
        'candidates' => [],
    ];

    private bool $dirty = false;

    public function __construct(string $stateFile)
    {
        $this->stateFile = $stateFile;
        $this->ensureParentDirectory();
        $this->load();
    }

    public function getStateFile(): string
    {
        return $this->stateFile;
    }

    public function getVersion(): int
    {
        return (int)($this->state['version'] ?? 1);
    }

    /**
     * @return array<string,mixed>
     */
    public function observe(
        string $key,
        string $root,
        string $relativePath,
        string $signature,
        int $stableSeconds,
        ?int $now = null
    ): array {
        $now = $now ?? time();
        $stableSeconds = max(0, $stableSeconds);

        if (!isset($this->state['candidates']) || !is_array($this->state['candidates'])) {
            $this->state['candidates'] = [];
        }

        $previous = $this->state['candidates'][$key] ?? null;
        $validPrevious = is_array($previous)
            && isset($previous['signature'])
            && is_string($previous['signature'])
            && $previous['signature'] !== '';

        $changed = true;
        $firstSeenAt = $now;
        $lastChangedAt = $now;
        $scanCount = 1;

        if ($validPrevious) {
            $firstSeenAt = isset($previous['first_seen_at'])
                ? (int)$previous['first_seen_at']
                : $now;

            $scanCount = max(0, (int)($previous['scan_count'] ?? 0)) + 1;

            if (hash_equals((string)$previous['signature'], $signature)) {
                $changed = false;
                $lastChangedAt = isset($previous['last_changed_at'])
                    ? (int)$previous['last_changed_at']
                    : $now;
            }
        }

        if ($changed) {
            $lastChangedAt = $now;
        }

        $stableFor = max(0, $now - $lastChangedAt);

        $stable = $scanCount >= 2
            && !$changed
            && $stableFor >= $stableSeconds;

        $this->state['candidates'][$key] = [
            'root' => $root,
            'path' => $relativePath,
            'signature' => $signature,
            'first_seen_at' => $firstSeenAt,
            'last_seen_at' => $now,
            'last_changed_at' => $lastChangedAt,
            'scan_count' => $scanCount,
        ];

        $this->state['updated_at'] = $now;
        $this->dirty = true;

        return [
            'key' => $key,
            'signature' => $signature,
            'changed' => $changed,
            'first_seen_at' => $firstSeenAt,
            'last_seen_at' => $now,
            'last_changed_at' => $lastChangedAt,
            'scan_count' => $scanCount,
            'stable_for' => $stableFor,
            'stable' => $stable,
        ];
    }

    public function reset(): void
    {
        $this->state = [
            'version' => 1,
            'updated_at' => time(),
            'candidates' => [],
        ];
        $this->dirty = true;
    }

    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        $dir = dirname($this->stateFile);
        $lockPath = $this->stateFile . '.lock';
        $lock = @fopen($lockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException('Impossibile aprire lock state: ' . $lockPath);
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Impossibile acquisire lock state: ' . $lockPath);
            }

            $json = json_encode(
                $this->state,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            if ($json === false) {
                throw new RuntimeException('Impossibile serializzare readiness shadow state.');
            }

            $tmp = tempnam($dir, '.readiness-shadow-');
            if ($tmp === false) {
                throw new RuntimeException('Impossibile creare file temporaneo state in: ' . $dir);
            }

            try {
                if (file_put_contents($tmp, $json . PHP_EOL) === false) {
                    throw new RuntimeException('Impossibile scrivere state temporaneo: ' . $tmp);
                }

                @chmod($tmp, 0600);

                if (!@rename($tmp, $this->stateFile)) {
                    throw new RuntimeException(
                        'Impossibile sostituire atomicamente state file: ' . $this->stateFile
                    );
                }

                @chmod($this->stateFile, 0600);
            } finally {
                if (isset($tmp) && is_string($tmp) && file_exists($tmp)) {
                    @unlink($tmp);
                }
            }

            $this->dirty = false;
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function ensureParentDirectory(): void
    {
        $dir = dirname($this->stateFile);

        if (is_dir($dir)) {
            return;
        }

        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Impossibile creare directory state: ' . $dir);
        }
    }

    private function load(): void
    {
        if (!is_file($this->stateFile)) {
            return;
        }

        $raw = @file_get_contents($this->stateFile);
        if ($raw === false || trim($raw) === '') {
            return;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Readiness shadow state JSON non valido: ' . $this->stateFile
            );
        }

        if ((int)($decoded['version'] ?? 0) !== 1) {
            throw new RuntimeException(
                'Versione readiness shadow state non supportata: '
                . (string)($decoded['version'] ?? 'missing')
            );
        }

        if (!isset($decoded['candidates']) || !is_array($decoded['candidates'])) {
            $decoded['candidates'] = [];
        }

        $this->state = $decoded;
    }
}

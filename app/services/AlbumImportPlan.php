<?php

/**
 * Immutable execution contract between:
 *
 *   READINESS -> IDENTITY -> DECISION
 *
 * and the future AlbumImportExecutor.
 *
 * This class performs NO database writes and NO filesystem writes.
 * It accepts only executable decisions:
 *
 *   READY + NEW_RELEASE     -> CREATE
 *   READY + EXACT_EXISTING  -> RECONCILE(target album id)
 *
 * REVIEW / NONE / PENDING / INCOMPLETE / AMBIGUOUS are not executable plans.
 */
final class AlbumImportPlan implements JsonSerializable
{
    public const VERSION = 1;

    public const ACTION_CREATE = 'CREATE';
    public const ACTION_RECONCILE = 'RECONCILE';

    public const IDENTITY_NEW_RELEASE = 'NEW_RELEASE';
    public const IDENTITY_EXACT_EXISTING = 'EXACT_EXISTING';

    private string $action;
    private string $importRoot;
    private string $candidatePath;
    private array $parts;

    private string $readinessStatus;
    private string $evidenceStatus;
    private bool $stable;

    private string $identityDecision;
    private ?int $targetAlbumId;
    private ?string $matchSource;

    private string $artist;
    private string $title;
    private ?int $year;
    private ?string $releaseMbid;
    private ?string $releaseGroup;

    private int $observedTracks;
    private ?int $expectedTracks;

    private ?string $candidateKey;
    private ?string $candidateSignature;
    private string $snapshotHash;

    private array $candidateSnapshot;
    private array $identitySnapshot;

    private function __construct()
    {
    }

    /**
     * Build a write-authorized import plan from an already resolved decision.
     *
     * @throws InvalidArgumentException when the decision is not executable or
     *                                  the invariants are inconsistent.
     */
    public static function fromResolvedDecision(
        string $action,
        string $importRoot,
        array $candidate,
        array $identity
    ): self {
        $action = strtoupper(trim($action));
        $importRoot = self::normalizePath($importRoot);

        if ($importRoot === '') {
            throw new InvalidArgumentException('Import root is empty.');
        }

        if ($action !== self::ACTION_CREATE && $action !== self::ACTION_RECONCILE) {
            throw new InvalidArgumentException(
                'Non-executable action: ' . ($action !== '' ? $action : '<empty>')
            );
        }

        $readinessStatus = strtoupper(trim((string)($candidate['status'] ?? '')));
        $evidenceStatus = strtoupper(trim((string)($candidate['evidence_status'] ?? '')));
        $stable = (bool)($candidate['stable'] ?? false);

        if ($readinessStatus !== 'READY') {
            throw new InvalidArgumentException(
                'Executable plan requires readiness=READY; got ' . ($readinessStatus ?: '<empty>')
            );
        }

        if ($evidenceStatus !== '' && $evidenceStatus !== 'READY') {
            throw new InvalidArgumentException(
                'Executable plan requires evidence_status=READY when present; got '
                . $evidenceStatus
            );
        }

        if (!$stable) {
            throw new InvalidArgumentException('Executable plan requires stable=true.');
        }

        $candidatePath = self::normalizePath((string)($candidate['path'] ?? ''));
        if ($candidatePath === '') {
            throw new InvalidArgumentException('Candidate path is empty.');
        }

        $parts = isset($candidate['parts']) && is_array($candidate['parts'])
            ? array_values($candidate['parts'])
            : [];

        if (empty($parts)) {
            throw new InvalidArgumentException('Candidate has no parts.');
        }

        foreach ($parts as $idx => $part) {
            if (!is_array($part)) {
                throw new InvalidArgumentException('Candidate part #' . $idx . ' is invalid.');
            }

            $dir = self::normalizePath((string)($part['dir'] ?? ''));
            if ($dir === '') {
                throw new InvalidArgumentException(
                    'Candidate part #' . $idx . ' has an empty directory.'
                );
            }

            $parts[$idx]['dir'] = $dir;
            if (isset($parts[$idx]['disc'])) {
                $parts[$idx]['disc'] = max(1, (int)$parts[$idx]['disc']);
            }
        }

        $identityDecision = strtoupper(trim((string)($identity['decision'] ?? '')));
        $albumId = isset($identity['album_id']) && $identity['album_id'] !== null
            ? (int)$identity['album_id']
            : null;

        if ($action === self::ACTION_CREATE) {
            if ($identityDecision !== self::IDENTITY_NEW_RELEASE) {
                throw new InvalidArgumentException(
                    'CREATE requires identity=NEW_RELEASE; got '
                    . ($identityDecision ?: '<empty>')
                );
            }

            if ($albumId !== null && $albumId > 0) {
                throw new InvalidArgumentException(
                    'CREATE must not carry a target album id.'
                );
            }

            $albumId = null;
        }

        if ($action === self::ACTION_RECONCILE) {
            if ($identityDecision !== self::IDENTITY_EXACT_EXISTING) {
                throw new InvalidArgumentException(
                    'RECONCILE requires identity=EXACT_EXISTING; got '
                    . ($identityDecision ?: '<empty>')
                );
            }

            if ($albumId === null || $albumId <= 0) {
                throw new InvalidArgumentException(
                    'RECONCILE requires a positive target album id.'
                );
            }
        }

        $plan = new self();

        $plan->action = $action;
        $plan->importRoot = $importRoot;
        $plan->candidatePath = $candidatePath;
        $plan->parts = $parts;

        $plan->readinessStatus = $readinessStatus;
        $plan->evidenceStatus = $evidenceStatus !== '' ? $evidenceStatus : 'READY';
        $plan->stable = $stable;

        $plan->identityDecision = $identityDecision;
        $plan->targetAlbumId = $albumId;

        $matchSource = trim((string)($identity['match_source'] ?? ''));
        $plan->matchSource = $matchSource !== '' ? $matchSource : null;

        $plan->artist = trim((string)($candidate['artist'] ?? ''));
        $plan->title = trim((string)($candidate['title'] ?? ''));

        $year = $candidate['year'] ?? null;
        $plan->year = ($year !== null && (int)$year > 0) ? (int)$year : null;

        $releaseMbid = trim((string)($candidate['release_mbid'] ?? ''));
        $plan->releaseMbid = $releaseMbid !== '' ? $releaseMbid : null;

        $releaseGroup = trim((string)($candidate['release_group'] ?? ''));
        $plan->releaseGroup = $releaseGroup !== '' ? $releaseGroup : null;

        $plan->observedTracks = max(0, (int)($candidate['observed_tracks'] ?? 0));

        $expected = $candidate['expected_tracks'] ?? null;
        $plan->expectedTracks = ($expected !== null && (int)$expected > 0)
            ? (int)$expected
            : null;

        $candidateKey = trim((string)(
            $candidate['candidate_key']
            ?? $candidate['key']
            ?? ''
        ));
        $plan->candidateKey = $candidateKey !== '' ? $candidateKey : null;

        // AlbumReadinessService v4.1 exposes the persistent fingerprint as
        // `candidate_signature`. Keep `signature` only as a compatibility
        // fallback for synthetic/older fixtures.
        $candidateSignature = trim((string)(
            $candidate['candidate_signature']
            ?? $candidate['signature']
            ?? ''
        ));

        if ($candidateSignature === '') {
            throw new InvalidArgumentException(
                'Executable plan requires candidate_signature.'
            );
        }

        $plan->candidateSignature = $candidateSignature;

        // Preserve the exact evidence used to authorize the future write.
        // The executor must not run identity matching again.
        $plan->candidateSnapshot = $candidate;
        $plan->candidateSnapshot['path'] = $candidatePath;
        $plan->candidateSnapshot['parts'] = $parts;
        $plan->identitySnapshot = $identity;

        $plan->snapshotHash = self::hashSnapshot([
            'version' => self::VERSION,
            'action' => $plan->action,
            'import_root' => $plan->importRoot,
            'candidate' => $plan->candidateSnapshot,
            'identity' => $plan->identitySnapshot,
            'target_album_id' => $plan->targetAlbumId,
        ]);

        return $plan;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function importRoot(): string
    {
        return $this->importRoot;
    }

    public function candidatePath(): string
    {
        return $this->candidatePath;
    }

    public function parts(): array
    {
        return $this->parts;
    }

    public function targetAlbumId(): ?int
    {
        return $this->targetAlbumId;
    }

    public function readinessStatus(): string
    {
        return $this->readinessStatus;
    }

    public function identityDecision(): string
    {
        return $this->identityDecision;
    }

    public function candidateSnapshot(): array
    {
        return $this->candidateSnapshot;
    }

    public function identitySnapshot(): array
    {
        return $this->identitySnapshot;
    }

    public function snapshotHash(): string
    {
        return $this->snapshotHash;
    }

    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'action' => $this->action,
            'import_root' => $this->importRoot,
            'candidate_path' => $this->candidatePath,
            'parts' => $this->parts,

            'readiness' => [
                'status' => $this->readinessStatus,
                'evidence_status' => $this->evidenceStatus,
                'stable' => $this->stable,
                'observed_tracks' => $this->observedTracks,
                'expected_tracks' => $this->expectedTracks,
                'candidate_key' => $this->candidateKey,
                'candidate_signature' => $this->candidateSignature,
            ],

            'identity' => [
                'decision' => $this->identityDecision,
                'target_album_id' => $this->targetAlbumId,
                'match_source' => $this->matchSource,
                'reasons' => array_values((array)($this->identitySnapshot['reasons'] ?? [])),
            ],

            'metadata' => [
                'artist' => $this->artist,
                'title' => $this->title,
                'year' => $this->year,
                'release_mbid' => $this->releaseMbid,
                'release_group' => $this->releaseGroup,
            ],

            'snapshot_hash' => $this->snapshotHash,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function normalizePath(string $path): string
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

    private static function hashSnapshot(array $payload): string
    {
        $normalized = self::normalizeForHash($payload);

        $json = json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            $json = serialize($normalized);
        }

        return hash('sha256', $json);
    }

    private static function normalizeForHash($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (self::isList($value)) {
            return array_map([self::class, 'normalizeForHash'], array_values($value));
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalizeForHash($item);
        }

        return $value;
    }

    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }
}

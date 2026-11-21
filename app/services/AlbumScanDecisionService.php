<?php

/**
 * AlbumScanDecisionService
 *
 * Single authoritative semantic pipeline for one discovered album candidate:
 *
 *   Ownership
 *      -> one Readiness/Evidence observation when required
 *      -> at most one Identity resolution (UNOWNED only)
 *      -> canonical verdict
 *      -> temporal write gate
 *      -> immutable AlbumImportPlan
 *
 * The service is READ-ONLY. It never executes an import, never applies a
 * relocation and never writes DB rows. AlbumReadinessService may update its
 * dedicated observation repository in memory; persistence remains owned by the
 * worker, exactly as before.
 *
 * Returned `classification` is semantic state:
 *   SAME, AMBIGUOUS, MOVED, NEW, CHANGED, UNAVAILABLE
 *
 * Returned `gate_result` is execution authorization:
 *   NONE, REVIEW, CREATE, RECONCILE
 *
 * This separation is deliberate: temporal stability can veto a write but must
 * not hide a read-only SAME/AMBIGUOUS/MOVED verdict.
 *
 * PHP 7.4 compatible.
 */
final class AlbumScanDecisionService
{
    private PDO $db;
    private AlbumReadinessService $readiness;
    private AlbumIdentityResolver $identity;
    private AlbumAudioCoverageService $coverage;
    private ExternalSourceOwnershipService $ownership;
    private MediaRootRelocationService $relocation;
    private AlbumScanGate $gate;

    public function __construct(
        PDO $db,
        AlbumReadinessService $readiness,
        ?AlbumIdentityResolver $identity = null,
        ?AlbumAudioCoverageService $coverage = null,
        ?ExternalSourceOwnershipService $ownership = null,
        ?MediaRootRelocationService $relocation = null,
        ?AlbumScanGate $gate = null,
        ?string $basePath = null
    ) {
        $this->db = $db;
        $this->readiness = $readiness;

        $basePath = $basePath !== null && trim($basePath) !== ''
            ? rtrim(str_replace('\\', '/', $basePath), '/')
            : (defined('BASE_PATH')
                ? rtrim(str_replace('\\', '/', (string)BASE_PATH), '/')
                : dirname(__DIR__, 2));

        $this->identity = $identity
            ?? new AlbumIdentityResolver($db, $basePath);
        $this->coverage = $coverage
            ?? new AlbumAudioCoverageService($db);
        $this->ownership = $ownership
            ?? new ExternalSourceOwnershipService($db);
        $this->relocation = $relocation
            ?? new MediaRootRelocationService($db);
        $this->gate = $gate
            ?? new AlbumScanGate($db, $basePath);
    }

    /**
     * @param array<int,array<string,mixed>> $candidateParts
     * @return array<string,mixed>
     */
    public function evaluate(
        string $importRoot,
        string $albumKey,
        array $candidateParts,
        int $stableSeconds
    ): array {
        $stableSeconds = max(0, $stableSeconds);

        if (empty($candidateParts)) {
            throw new RuntimeException(
                'Candidate parts assenti per: ' . $albumKey
            );
        }

        $ownership = $this->ownership->inspectCandidate(
            $importRoot,
            $albumKey
        );

        $ownershipState = strtoupper(trim((string)(
            $ownership['state'] ?? ''
        )));

        switch ($ownershipState) {
            case ExternalSourceOwnershipService::OWNED_EXACT:
                return $this->result(
                    'SAME',
                    (int)($ownership['album_id'] ?? 0),
                    'external-source-set-identical',
                    $ownershipState,
                    $ownership,
                    null,
                    $this->identityNotRun(),
                    null
                );

            case ExternalSourceOwnershipService::OWNED_PARTIAL:
                return $this->evaluateOwnedPartial(
                    $importRoot,
                    $albumKey,
                    $candidateParts,
                    $stableSeconds,
                    $ownership
                );

            case ExternalSourceOwnershipService::UNAVAILABLE:
                return $this->result(
                    'UNAVAILABLE',
                    isset($ownership['album_id'])
                        && $ownership['album_id'] !== null
                            ? (int)$ownership['album_id']
                            : null,
                    'ownership-unavailable:'
                        . (string)($ownership['reason'] ?? 'filesystem-unavailable'),
                    $ownershipState,
                    $ownership,
                    null,
                    $this->identityNotRun(),
                    null
                );

            case ExternalSourceOwnershipService::CONFLICT:
            case ExternalSourceOwnershipService::INVALID:
                return $this->result(
                    'AMBIGUOUS',
                    null,
                    'ownership-' . strtolower($ownershipState)
                        . ':' . (string)($ownership['reason'] ?? ''),
                    $ownershipState,
                    $ownership,
                    null,
                    $this->identityNotRun(),
                    $this->reviewResult(
                        'ownership-' . strtolower($ownershipState)
                    )
                );

            case ExternalSourceOwnershipService::UNOWNED:
                return $this->evaluateUnowned(
                    $importRoot,
                    $albumKey,
                    $candidateParts,
                    $stableSeconds,
                    $ownership
                );

            default:
                return $this->result(
                    'AMBIGUOUS',
                    null,
                    'ownership-contract-invalid',
                    $ownershipState,
                    $ownership,
                    null,
                    $this->identityNotRun(),
                    $this->reviewResult('ownership-contract-invalid')
                );
        }
    }

    /**
     * @param array<string,mixed> $ownership
     * @return array<string,mixed>
     */
    private function evaluateOwnedPartial(
        string $importRoot,
        string $albumKey,
        array $candidateParts,
        int $stableSeconds,
        array $ownership
    ): array {
        $ownershipState = ExternalSourceOwnershipService::OWNED_PARTIAL;
        $albumId = (int)($ownership['album_id'] ?? 0);

        if ($albumId <= 0) {
            return $this->result(
                'AMBIGUOUS',
                null,
                'ownership-partial-without-album-id',
                $ownershipState,
                $ownership,
                null,
                $this->identityNotRun(),
                $this->reviewResult('ownership-partial-without-album-id')
            );
        }

        $coverage = $this->coverage->inspectCandidateCoverage(
            $albumId,
            $albumKey
        );

        $managedRows = (int)($coverage['managed_rows'] ?? 0);
        $managedMissing = (int)($coverage['managed_missing_tracks'] ?? 0);
        $albumTrackCount = (int)($coverage['track_count'] ?? 0);
        $candidateAudioCount = (int)($ownership['audio_files'] ?? 0);

        if ($managedRows > 0 && $managedMissing > 0) {
            return $this->result(
                'AMBIGUOUS',
                $albumId,
                'managed-audio-missing',
                $ownershipState,
                $ownership,
                null,
                $this->identityNotRun(),
                $this->reviewResult('managed-audio-missing')
            );
        }

        if (
            $managedRows > 0
            && $albumTrackCount > 0
            && $candidateAudioCount === $albumTrackCount
            && !empty($coverage['complete'])
        ) {
            return $this->result(
                'SAME',
                $albumId,
                'mixed-album-complete',
                $ownershipState,
                $ownership,
                null,
                $this->identityNotRun(),
                null
            );
        }

        $relocation = $this->relocation->verifyCandidateRelocation(
            $importRoot,
            $albumKey,
            $albumId
        );

        if (!empty($relocation['verified'])) {
            return $this->result(
                'MOVED',
                $albumId,
                'verified-relocation-by-hash',
                $ownershipState,
                $ownership,
                null,
                $this->identityNotRun(),
                null
            );
        }

        $candidate = $this->readiness->analyzeCandidate(
            $importRoot,
            $albumKey,
            $candidateParts,
            $stableSeconds,
            true
        );

        $canonicalReason = 'external-source-owned-partial:'
            . (string)($ownership['reason'] ?? '')
            . ';relocation-unverified:'
            . (string)($relocation['reason'] ?? 'unknown');

        $gateResult = $this->writeGateFromOwnership(
            $importRoot,
            $candidate,
            $albumId,
            $ownership
        );

        return $this->result(
            'CHANGED',
            $albumId,
            $canonicalReason,
            $ownershipState,
            $ownership,
            $candidate,
            $this->identityNotRun(),
            $gateResult
        );
    }

    /**
     * @param array<string,mixed> $ownership
     * @return array<string,mixed>
     */
    private function evaluateUnowned(
        string $importRoot,
        string $albumKey,
        array $candidateParts,
        int $stableSeconds,
        array $ownership
    ): array {
        /*
         * A path-UNOWNED candidate may actually be a complete physical move.
         * Content ownership is stronger than album Identity, so give the
         * canonical relocation service one fail-closed chance to prove it
         * before Readiness/Identity are consulted.
         */
        $preIdentityRelocation =
            $this->relocation->resolveCandidateRelocationByFingerprint(
                $importRoot,
                $albumKey
            );

        if (!empty($preIdentityRelocation['ambiguous'])) {
            return $this->result(
                'AMBIGUOUS',
                null,
                'relocation-fingerprint-ambiguous',
                ExternalSourceOwnershipService::UNOWNED,
                $ownership,
                null,
                $this->identityNotRun(),
                $this->reviewResult('relocation-fingerprint-ambiguous')
            );
        }

        if (!empty($preIdentityRelocation['verified'])) {
            $relocatedAlbumId = (int)(
                $preIdentityRelocation['album_id'] ?? 0
            );

            if ($relocatedAlbumId <= 0) {
                return $this->result(
                    'AMBIGUOUS',
                    null,
                    'relocation-fingerprint-without-album-id',
                    ExternalSourceOwnershipService::UNOWNED,
                    $ownership,
                    null,
                    $this->identityNotRun(),
                    $this->reviewResult(
                        'relocation-fingerprint-without-album-id'
                    )
                );
            }

            return $this->result(
                'MOVED',
                $relocatedAlbumId,
                'verified-relocation-by-hash',
                ExternalSourceOwnershipService::UNOWNED,
                $ownership,
                null,
                $this->identityNotRun(),
                null
            );
        }

        /*
         * One authoritative candidate observation.
         *
         * `evidence_status` describes intrinsic evidence/completeness.
         * `status` additionally includes temporal stability. Identity consumes
         * evidence_status; temporal status authorizes writes only.
         */
        $candidate = $this->readiness->analyzeCandidate(
            $importRoot,
            $albumKey,
            $candidateParts,
            $stableSeconds,
            true
        );

        $identity = $this->identity->resolveCandidate(
            $candidate,
            $importRoot
        );

        $identityDecision = strtoupper(trim((string)(
            $identity['decision'] ?? ''
        )));

        if ($identityDecision === AlbumIdentityResolver::NEW_RELEASE) {
            $gateResult = $this->writeGateFromIdentity(
                $importRoot,
                $candidate,
                $identity
            );

            return $this->result(
                'NEW',
                null,
                'identity-new-release',
                ExternalSourceOwnershipService::UNOWNED,
                $ownership,
                $candidate,
                $identity,
                $gateResult
            );
        }

        if (
            $identityDecision === AlbumIdentityResolver::AMBIGUOUS
            || $identityDecision === AlbumIdentityResolver::CONFLICT
        ) {
            return $this->result(
                'AMBIGUOUS',
                null,
                'identity-' . strtolower($identityDecision),
                ExternalSourceOwnershipService::UNOWNED,
                $ownership,
                $candidate,
                $identity,
                $this->reviewResult(
                    'identity-' . strtolower($identityDecision),
                    $candidate,
                    $identity
                )
            );
        }

        if ($identityDecision !== AlbumIdentityResolver::EXACT_EXISTING) {
            return $this->result(
                'AMBIGUOUS',
                null,
                'identity-unresolved',
                ExternalSourceOwnershipService::UNOWNED,
                $ownership,
                $candidate,
                $identity,
                $this->reviewResult(
                    'identity-unresolved',
                    $candidate,
                    $identity
                )
            );
        }

        $albumId = (int)($identity['album_id'] ?? 0);
        if ($albumId <= 0) {
            return $this->result(
                'AMBIGUOUS',
                null,
                'identity-existing-without-album-id',
                ExternalSourceOwnershipService::UNOWNED,
                $ownership,
                $candidate,
                $identity,
                $this->reviewResult(
                    'identity-existing-without-album-id',
                    $candidate,
                    $identity
                )
            );
        }

        $stats = $this->albumAudioStats($albumId);
        $trackCount = (int)$stats['track_count'];
        $managedAudio = (int)$stats['managed_audio'];
        $externalAudio = (int)$stats['external_audio'];
        $candidateTrackCount = (int)(
            $identity['candidate_track_count']
            ?? $candidate['observed_tracks']
            ?? 0
        );

        if (
            $trackCount > 0
            && $candidateTrackCount === $trackCount
            && $managedAudio > 0
        ) {
            $coverage = $this->coverage->inspectCandidateCoverage(
                $albumId,
                $albumKey
            );

            if ((int)($coverage['managed_missing_tracks'] ?? 0) > 0) {
                return $this->result(
                    'AMBIGUOUS',
                    $albumId,
                    'managed-audio-missing',
                    ExternalSourceOwnershipService::UNOWNED,
                    $ownership,
                    $candidate,
                    $identity,
                    $this->reviewResult(
                        'managed-audio-missing',
                        $candidate,
                        $identity
                    )
                );
            }

            if (!empty($coverage['complete'])) {
                return $this->result(
                    'SAME',
                    $albumId,
                    $externalAudio > 0
                        ? 'mixed-album-complete'
                        : 'managed-album-complete',
                    ExternalSourceOwnershipService::UNOWNED,
                    $ownership,
                    $candidate,
                    $identity,
                    null
                );
            }
        }

        if ($externalAudio <= 0) {
            $gateResult = $this->writeGateFromIdentity(
                $importRoot,
                $candidate,
                $identity
            );

            return $this->result(
                'CHANGED',
                $albumId,
                'existing-album-new-source',
                ExternalSourceOwnershipService::UNOWNED,
                $ownership,
                $candidate,
                $identity,
                $gateResult
            );
        }

        $relocation = $this->relocation->verifyCandidateRelocation(
            $importRoot,
            $albumKey,
            $albumId
        );

        if (!empty($relocation['verified'])) {
            return $this->result(
                'MOVED',
                $albumId,
                'verified-relocation-by-hash',
                ExternalSourceOwnershipService::UNOWNED,
                $ownership,
                $candidate,
                $identity,
                null
            );
        }

        $relocationReason = (string)($relocation['reason'] ?? 'unknown');
        $canonicalReason = $relocationReason === 'old-source-still-live'
            ? 'existing-album-source-differs'
            : 'relocation-unverified:' . $relocationReason;

        $gateResult = $this->writeGateFromIdentity(
            $importRoot,
            $candidate,
            $identity
        );

        return $this->result(
            'CHANGED',
            $albumId,
            $canonicalReason,
            ExternalSourceOwnershipService::UNOWNED,
            $ownership,
            $candidate,
            $identity,
            $gateResult
        );
    }

    /**
     * Temporal status authorizes writes. Identity has already been resolved and
     * must never be executed by the gate again.
     *
     * @return array<string,mixed>
     */
    private function writeGateFromIdentity(
        string $importRoot,
        array $candidate,
        array $identity
    ): array {
        $status = strtoupper(trim((string)($candidate['status'] ?? '')));

        if ($status !== 'READY') {
            return $this->nonReadyGateResult($candidate);
        }

        return $this->gate->fromResolvedIdentity(
            $importRoot,
            $candidate,
            $identity
        );
    }

    /** @return array<string,mixed> */
    private function writeGateFromOwnership(
        string $importRoot,
        array $candidate,
        int $albumId,
        array $ownership
    ): array {
        $status = strtoupper(trim((string)($candidate['status'] ?? '')));

        if ($status !== 'READY') {
            return $this->nonReadyGateResult($candidate);
        }

        return $this->gate->fromResolvedOwnership(
            $importRoot,
            $candidate,
            $albumId,
            $ownership
        );
    }

    /** @return array<string,mixed> */
    private function nonReadyGateResult(array $candidate): array
    {
        $status = strtoupper(trim((string)($candidate['status'] ?? '')));

        if ($status === 'PENDING') {
            $action = 'NONE';
            $reason = 'readiness-pending';
        } elseif ($status === 'INCOMPLETE') {
            $action = 'NONE';
            $reason = 'readiness-incomplete';
        } elseif ($status === 'AMBIGUOUS') {
            $action = 'REVIEW';
            $reason = 'readiness-ambiguous';
        } else {
            $action = 'REVIEW';
            $reason = 'unknown-readiness-status';
        }

        return [
            'action' => $action,
            'reason' => $reason,
            'readiness_status' => $status,
            'identity' => $this->identityNotRun(),
            'plan' => null,
        ];
    }

    /**
     * @return array{track_count:int,managed_audio:int,external_audio:int}
     */
    private function albumAudioStats(int $albumId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                (SELECT COUNT(*)
                 FROM tracks
                 WHERE album_id = :tracks_album_id) AS track_count,
                SUM(CASE WHEN storage_type = 'managed' THEN 1 ELSE 0 END) AS managed_audio,
                SUM(CASE WHEN storage_type = 'external' THEN 1 ELSE 0 END) AS external_audio
            FROM audio_files
            WHERE album_id = :audio_album_id
        ");

        $stmt->execute([
            ':tracks_album_id' => $albumId,
            ':audio_album_id' => $albumId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = is_array($row) ? $row : [];

        return [
            'track_count' => (int)($row['track_count'] ?? 0),
            'managed_audio' => (int)($row['managed_audio'] ?? 0),
            'external_audio' => (int)($row['external_audio'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function result(
        string $classification,
        ?int $albumId,
        string $reason,
        string $ownershipState,
        array $ownership,
        ?array $candidate,
        array $identity,
        ?array $gateResult
    ): array {
        return [
            'classification' => strtoupper($classification),
            'album_id' => $albumId,
            'reason' => $reason,
            'ownership_state' => strtoupper($ownershipState),
            'ownership' => $ownership,
            'identity_decision' => strtoupper((string)(
                $identity['decision'] ?? 'NOT_RUN'
            )),
            'candidate' => $candidate,
            'identity' => $identity,
            'gate_result' => $gateResult,
        ];
    }

    /** @return array<string,mixed> */
    private function identityNotRun(): array
    {
        return [
            'decision' => 'NOT_RUN',
            'album_id' => null,
            'match_source' => null,
            'reasons' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function reviewResult(
        string $reason,
        ?array $candidate = null,
        ?array $identity = null
    ): array {
        return [
            'action' => 'REVIEW',
            'reason' => $reason,
            'readiness_status' => $candidate !== null
                ? strtoupper(trim((string)($candidate['status'] ?? '')))
                : '',
            'identity' => $identity ?? $this->identityNotRun(),
            'plan' => null,
        ];
    }
}

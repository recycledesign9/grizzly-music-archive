<?php

/**
 * AlbumScanGate
 *
 * Canonical read-only orchestration gate:
 *
 *   Readiness -> Identity -> Action -> AlbumImportPlan
 *
 * This service NEVER writes to the database or filesystem and NEVER executes
 * an import. It exists so the worker does not duplicate the decision table
 * already validated in shadow mode.
 *
 * PHP 7.4 compatible.
 */
final class AlbumScanGate
{
    private PDO $db;
    private string $basePath;

    /** @var callable */
    private $identityResolver;

    public function __construct(
        PDO $db,
        ?string $basePath = null,
        ?callable $identityResolver = null
    ) {
        $this->db = $db;
        $this->basePath = $basePath !== null && trim($basePath) !== ''
            ? rtrim(str_replace('\\', '/', $basePath), '/')
            : (defined('BASE_PATH')
                ? rtrim(str_replace('\\', '/', (string)BASE_PATH), '/')
                : dirname(__DIR__, 2));

        $this->requireDependencies();

        if ($identityResolver !== null) {
            $this->identityResolver = $identityResolver;
            return;
        }

        $resolver = new AlbumIdentityResolver($this->db, $this->basePath);

        $this->identityResolver = static function (
            array $candidate,
            string $importRoot
        ) use ($resolver): array {
            return $resolver->resolveCandidate($candidate, $importRoot);
        };
    }

    /**
     * Evaluate exactly one readiness candidate.
     *
     * Returned action is one of:
     * NONE, REVIEW, CREATE, RECONCILE.
     *
     * Identity is deliberately NOT_RUN for non-READY candidates.
     *
     * @return array<string,mixed>
     */
    public function evaluate(string $importRoot, array $candidate): array
    {
        $status = strtoupper(trim((string)($candidate['status'] ?? '')));

        if ($status === 'PENDING') {
            return $this->nonExecutable(
                'NONE',
                'readiness-pending',
                $candidate
            );
        }

        if ($status === 'INCOMPLETE') {
            return $this->nonExecutable(
                'NONE',
                'readiness-incomplete',
                $candidate
            );
        }

        if ($status === 'AMBIGUOUS') {
            return $this->nonExecutable(
                'REVIEW',
                'readiness-ambiguous',
                $candidate
            );
        }

        if ($status !== 'READY') {
            return $this->nonExecutable(
                'REVIEW',
                'unknown-readiness-status',
                $candidate
            );
        }

        $identity = call_user_func(
            $this->identityResolver,
            $candidate,
            $importRoot
        );

        if (!is_array($identity)) {
            throw new RuntimeException(
                'Identity resolver returned an invalid result.'
            );
        }

        return $this->fromResolvedIdentity(
            $importRoot,
            $candidate,
            $identity
        );
    }

    /**
     * Pure second half of the gate. Public so the exact decision table can be
     * regression-tested without DB fixtures or filesystem access.
     *
     * @return array<string,mixed>
     */
    public function fromResolvedIdentity(
        string $importRoot,
        array $candidate,
        array $identity
    ): array {
        $status = strtoupper(trim((string)($candidate['status'] ?? '')));

        if ($status !== 'READY') {
            // The orchestration invariant is absolute: non-READY never reaches
            // identity execution or an executable plan.
            if ($status === 'PENDING') {
                return $this->nonExecutable(
                    'NONE',
                    'readiness-pending',
                    $candidate
                );
            }

            if ($status === 'INCOMPLETE') {
                return $this->nonExecutable(
                    'NONE',
                    'readiness-incomplete',
                    $candidate
                );
            }

            if ($status === 'AMBIGUOUS') {
                return $this->nonExecutable(
                    'REVIEW',
                    'readiness-ambiguous',
                    $candidate
                );
            }

            return $this->nonExecutable(
                'REVIEW',
                'unknown-readiness-status',
                $candidate
            );
        }

        $decision = strtoupper(trim((string)($identity['decision'] ?? '')));

        if ($decision === AlbumIdentityResolver::EXACT_EXISTING) {
            $plan = AlbumImportPlan::fromResolvedDecision(
                'RECONCILE',
                $importRoot,
                $candidate,
                $identity
            );

            return [
                'action' => 'RECONCILE',
                'reason' => 'identity-exact-existing',
                'readiness_status' => 'READY',
                'identity' => $identity,
                'plan' => $plan,
            ];
        }

        if ($decision === AlbumIdentityResolver::NEW_RELEASE) {
            $plan = AlbumImportPlan::fromResolvedDecision(
                'CREATE',
                $importRoot,
                $candidate,
                $identity
            );

            return [
                'action' => 'CREATE',
                'reason' => 'identity-new-release',
                'readiness_status' => 'READY',
                'identity' => $identity,
                'plan' => $plan,
            ];
        }

        if ($decision === AlbumIdentityResolver::AMBIGUOUS) {
            return [
                'action' => 'REVIEW',
                'reason' => 'identity-ambiguous',
                'readiness_status' => 'READY',
                'identity' => $identity,
                'plan' => null,
            ];
        }

        if ($decision === AlbumIdentityResolver::CONFLICT) {
            return [
                'action' => 'REVIEW',
                'reason' => 'identity-conflict',
                'readiness_status' => 'READY',
                'identity' => $identity,
                'plan' => null,
            ];
        }

        return [
            'action' => 'REVIEW',
            'reason' => 'unknown-identity-decision',
            'readiness_status' => 'READY',
            'identity' => $identity,
            'plan' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function nonExecutable(
        string $action,
        string $reason,
        array $candidate
    ): array {
        return [
            'action' => $action,
            'reason' => $reason,
            'readiness_status' => strtoupper(
                trim((string)($candidate['status'] ?? ''))
            ),
            'identity' => [
                'decision' => 'NOT_RUN',
                'album_id' => null,
                'match_source' => null,
                'reasons' => [],
            ],
            'plan' => null,
        ];
    }

    private function requireDependencies(): void
    {
        $serviceDir = __DIR__;

        if (!class_exists('AlbumIdentityResolver')) {
            require_once $serviceDir . '/AlbumIdentityResolver.php';
        }

        if (!class_exists('AlbumImportPlan')) {
            require_once $serviceDir . '/AlbumImportPlan.php';
        }
    }
}

<?php

/**
 * AlbumImportExecutor v2.2
 *
 * Trust boundary + write-capable orchestration for one AlbumImportPlan.
 *
 * Safety rules:
 * - preflight revalidates the exact candidate and the bound DB target;
 * - MediaImportService receives a binding, not permission to choose an album;
 * - candidate fingerprint/evidence is checked again immediately before BEGIN;
 * - candidate fingerprint/evidence is checked again before COMMIT;
 * - DB changes are atomic per album;
 * - RECONCILE locks the bound album row inside the write transaction;
 * - CREATE uses a connection-scoped advisory lock keyed by canonical release identity;
 * - CREATE reruns AlbumIdentityResolver only as a veto while holding that lock;
 * - filesystem side effects are compensated by AlbumImportFilesystemJournal;
 * - once MediaImportService reports committed, later failures NEVER trigger filesystem rollback.
 */
final class AlbumImportExecutor
{
    private PDO $db;
    private string $basePath;

    public function __construct(PDO $db, ?string $basePath = null)
    {
        $this->db = $db;
        $this->basePath = $basePath !== null && trim($basePath) !== ''
            ? rtrim(str_replace('\\', '/', $basePath), '/')
            : (defined('BASE_PATH')
                ? rtrim(str_replace('\\', '/', (string)BASE_PATH), '/')
                : dirname(__DIR__, 2));

        $this->requireDependencies();
    }

    /**
     * Full preflight before an execution attempt.
     *
     * In addition to the immutable media snapshot, this verifies the DB-side
     * action:
     * - CREATE must still have no local target;
     * - RECONCILE target must still exist and cannot drift to another album.
     *
     * NO writes are performed.
     *
     * @return array<string,mixed>
     */
    public function preflight(AlbumImportPlan $plan): array
    {
        $check = $this->revalidateCandidate($plan);
        $current = $check['candidate'];

        $action = $check['action'];
        $targetAlbumId = $plan->targetAlbumId();
        $currentLocalAlbumId = !empty($current['local_album_id'])
            ? (int)$current['local_album_id']
            : null;

        $target = null;

        if ($action === 'CREATE') {
            if ($targetAlbumId !== null) {
                throw new RuntimeException(
                    'CREATE unexpectedly carries target_album_id.'
                );
            }

            if ($currentLocalAlbumId !== null && $currentLocalAlbumId > 0) {
                throw new RuntimeException(
                    'CREATE plan is stale: candidate now maps to local album_id='
                    . $currentLocalAlbumId
                );
            }
        }

        if ($action === 'RECONCILE') {
            if ($targetAlbumId === null || $targetAlbumId <= 0) {
                throw new RuntimeException(
                    'RECONCILE requires a positive target_album_id.'
                );
            }

            $target = $this->loadTargetAlbum($targetAlbumId);
            if ($target === null) {
                throw new RuntimeException(
                    'RECONCILE target album no longer exists: ' . $targetAlbumId
                );
            }

            if (
                $currentLocalAlbumId !== null
                && $currentLocalAlbumId > 0
                && $currentLocalAlbumId !== $targetAlbumId
            ) {
                throw new RuntimeException(
                    'RECONCILE target changed: plan=' . $targetAlbumId
                    . ' current_local=' . $currentLocalAlbumId
                );
            }
        }

        return [
            'ok' => true,
            'mode' => 'preflight-read-only',
            'action' => $action,
            'target_album_id' => $targetAlbumId,
            'candidate_path' => $check['candidate_path'],
            'candidate_signature' => $check['candidate_signature'],
            'evidence_status' => $check['evidence_status'],
            'observed_tracks' => (int)($current['observed_tracks'] ?? 0),
            'expected_tracks' => isset($current['expected_tracks'])
                && $current['expected_tracks'] !== null
                ? (int)$current['expected_tracks']
                : null,
            'current_local_album_id' => $currentLocalAlbumId,
            'target_album' => $target,
            'plan_snapshot_hash' => $plan->snapshotHash(),
        ];
    }

    /**
     * Revalidate only immutable media/evidence.
     *
     * This method is safe both before BEGIN and immediately before COMMIT.
     * It deliberately does NOT re-run DB album identity. During CREATE the
     * transaction itself has already inserted the new album by pre-commit time,
     * so a DB identity check there would incorrectly invalidate our own write.
     *
     * @return array<string,mixed>
     */
    public function revalidateCandidate(
        AlbumImportPlan $plan,
        bool $verifyEffectiveIdentity = true
    ): array {
        $planData = $plan->toArray();

        $action = strtoupper(trim((string)($planData['action'] ?? '')));
        if (!in_array($action, ['CREATE', 'RECONCILE'], true)) {
            throw new RuntimeException(
                'Executor received non-executable action: ' . $action
            );
        }

        $root = $this->realDirectory($plan->importRoot(), 'import root');
        $candidatePath = $this->realDirectory(
            $plan->candidatePath(),
            'candidate'
        );

        if (!$this->pathWithin($candidatePath, $root)) {
            throw new RuntimeException(
                'Candidate is outside import root: ' . $candidatePath
            );
        }

        $parts = $this->validateParts(
            $plan->parts(),
            $root,
            $candidatePath
        );

        $plannedSignature = trim((string)(
            $planData['readiness']['candidate_signature'] ?? ''
        ));

        if ($plannedSignature === '') {
            throw new RuntimeException(
                'Import plan has no candidate_signature.'
            );
        }

        $current = $this->analyzeCurrentCandidate(
            $root,
            $candidatePath,
            $parts
        );

        $currentSignature = trim((string)(
            $current['candidate_signature'] ?? ''
        ));

        if ($currentSignature === '') {
            throw new RuntimeException(
                'Current readiness analysis did not produce candidate_signature.'
            );
        }

        if (!hash_equals($plannedSignature, $currentSignature)) {
            throw new RuntimeException(
                'Candidate signature changed after decision; execution refused.'
            );
        }

        $evidenceStatus = strtoupper(trim((string)(
            $current['evidence_status'] ?? ''
        )));

        if ($evidenceStatus !== 'READY') {
            throw new RuntimeException(
                'Candidate is no longer intrinsically READY; evidence_status='
                . ($evidenceStatus !== '' ? $evidenceStatus : '<empty>')
            );
        }

        if (!empty($current['temporary_files'])) {
            throw new RuntimeException(
                'Candidate now contains in-progress files; execution refused.'
            );
        }

        if (!empty($current['unreadable_files'])) {
            throw new RuntimeException(
                'Candidate now contains unreadable audio files; execution refused.'
            );
        }

        if (!empty($current['metadata_conflicts'])) {
            throw new RuntimeException(
                'Candidate now has metadata conflicts; execution refused.'
            );
        }

        if ($verifyEffectiveIdentity) {
            $this->assertCandidateIdentitySnapshot(
                $plan->candidateSnapshot(),
                $current
            );
        } else {
            /*
             * before-commit runs after the importer has already written the
             * bound audio/source_path rows inside the current transaction.
             * Those writes can legitimately change Readiness' DB-derived
             * effective identity (for example making source_path the strongest
             * local evidence) even though the media files themselves did not
             * change. Compare the immutable observed media identity here,
             * never the DB-derived effective identity.
             */
            $this->assertCandidateObservedSnapshot(
                $plan->candidateSnapshot(),
                $current
            );
        }

        return [
            'ok' => true,
            'mode' => 'candidate-revalidation-read-only',
            'action' => $action,
            'candidate_path' => $candidatePath,
            'candidate_signature' => $currentSignature,
            'evidence_status' => $evidenceStatus,
            'candidate' => $current,
        ];
    }

    /**
     * Execute one already-authorized plan.
     *
     * The write-capable MediaImportService path is deterministic:
     * - CREATE never runs album matching;
     * - RECONCILE receives the exact target_album_id.
     *
     * @return array<string,mixed>
     */
    public function execute(AlbumImportPlan $plan): array
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException(
                'AlbumImportExecutor requires no active caller transaction.'
            );
        }

        $preflight = $this->preflight($plan);
        $this->requireExecutionDependencies();

        // All application models used by MediaImportService obtain the PDO from
        // Database::getInstance(). Refuse execution if that singleton is not the
        // same PDO that was validated by this executor.
        if (
            class_exists('Database')
            && Database::getInstance() !== $this->db
        ) {
            throw new RuntimeException(
                'Executor PDO differs from application Database singleton.'
            );
        }

        $journal = new AlbumImportFilesystemJournal(
            $this->filesystemJournalRoots()
        );
        $service = new MediaImportService();
        $createLockNames = [];

        // False until executeBoundAlbum() returns the explicit "committed"
        // outcome. From that point the DB transaction is already durable and
        // filesystem compensation must never remove files referenced by it.
        $databaseCommitted = false;

        try {
            $entry = $service->executeBoundAlbum(
                $plan,
                $journal,
                function (string $phase) use (
                    $plan,
                    &$createLockNames
                ): array {
                    if ($phase === 'before-write') {
                        // MediaImportService has already opened the DB
                        // transaction. Concurrency guards therefore protect
                        // the exact transaction that will perform the write.
                        return $this->beforeWriteConcurrencyGuard(
                            $plan,
                            $createLockNames
                        );
                    }

                    if ($phase === 'before-commit') {
                        // Media-only check: catches files that changed while
                        // the transaction was being applied. DB locks acquired
                        // at before-write are still held here.
                        //
                        // Do not compare DB-derived effective identity here:
                        // this transaction may already have inserted source_path
                        // associations, legitimately changing Readiness'
                        // strongest local evidence before COMMIT.
                        return $this->revalidateCandidate($plan, false);
                    }

                    throw new RuntimeException(
                        'Unknown bound revalidation phase: ' . $phase
                    );
                }
            );

            $outcome = strtolower(trim((string)(
                $entry['execution_outcome'] ?? 'committed'
            )));

            if (in_array($outcome, ['deferred', 'review'], true)) {
                // MediaImportService has already rolled back the DB transaction.
                // Compensate every filesystem side effect from the attempt too.
                $filesystem = $journal->rollback();

                return [
                    'ok' => false,
                    'mode' => 'bound-atomic-no-commit',
                    'status' => $outcome,
                    'action' => $plan->action(),
                    'target_album_id' => $plan->targetAlbumId(),
                    'preflight' => $preflight,
                    'result' => $entry,
                    'filesystem' => $filesystem,
                    'plan_snapshot_hash' => $plan->snapshotHash(),
                ];
            }

            if ($outcome !== 'committed') {
                throw new RuntimeException(
                    'Unknown bound execution outcome: ' . $outcome
                );
            }

            // executeBoundAlbum() returns "committed" only after the DB COMMIT.
            // Crossing this boundary is irreversible: even if filesystem
            // finalization unexpectedly throws, rollback() must not remove
            // newly-created cover/audio files now referenced by committed rows.
            $databaseCommitted = true;

            $filesystem = $journal->commit();

            return [
                'ok' => true,
                'mode' => 'bound-atomic-write',
                'status' => 'committed',
                'action' => $plan->action(),
                'target_album_id' => $plan->targetAlbumId(),
                'preflight' => $preflight,
                'result' => $entry,
                'filesystem' => $filesystem,
                'plan_snapshot_hash' => $plan->snapshotHash(),
            ];
        } catch (Throwable $e) {
            // MediaImportService owns the bound DB transaction and rolls it
            // back before propagating. This is a final guard only.
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if (!$databaseCommitted) {
                $journal->rollback();
            }

            throw $e;
        } finally {
            if (!empty($createLockNames)) {
                $this->releaseCreateAdvisoryLocks($createLockNames);
            }
        }
    }

    /**
     * Final DB-side concurrency guard executed inside the write transaction.
     *
     * CREATE:
     * - acquire a connection-scoped advisory lock for the canonical release;
     * - full preflight;
     * - rerun identity strictly as a veto (must still be NEW_RELEASE).
     *
     * RECONCILE:
     * - lock the immutable target album row with SELECT ... FOR UPDATE;
     * - full preflight while the target row lock is held.
     *
     * @return array<string,mixed>
     */
    private function beforeWriteConcurrencyGuard(
        AlbumImportPlan $plan,
        array &$createLockNames
    ): array {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException(
                'Concurrency guard requires an active DB transaction.'
            );
        }

        $action = strtoupper(trim($plan->action()));

        if ($action === 'CREATE') {
            if (empty($createLockNames)) {
                $createLockNames = $this->acquireCreateAdvisoryLocks($plan);
            }

            $check = $this->preflight($plan);
            $identity = $this->assertCreateStillNewRelease($plan);

            $check['create_advisory_locks'] = $createLockNames;
            $check['identity_after_lock'] = (string)($identity['decision'] ?? '');

            return $check;
        }

        if ($action === 'RECONCILE') {
            $targetAlbumId = $plan->targetAlbumId();
            if ($targetAlbumId === null || $targetAlbumId <= 0) {
                throw new RuntimeException(
                    'RECONCILE concurrency guard requires target_album_id.'
                );
            }

            $lockedTarget = $this->lockTargetAlbumForUpdate($targetAlbumId);
            $check = $this->preflight($plan);
            $check['target_album'] = $lockedTarget;
            $check['target_row_locked'] = true;

            return $check;
        }

        throw new RuntimeException(
            'Concurrency guard received unsupported action: ' . $action
        );
    }

    /**
     * CREATE is allowed to proceed only if identity is STILL NEW_RELEASE after
     * the canonical advisory lock has been acquired. A different result vetoes
     * the stale plan; it never retargets CREATE into RECONCILE.
     *
     * @return array<string,mixed>
     */
    private function assertCreateStillNewRelease(AlbumImportPlan $plan): array
    {
        $current = $this->revalidateCandidate($plan);
        $candidate = $current['candidate'];

        $resolver = new AlbumIdentityResolver($this->db, $this->basePath);
        $identity = $resolver->resolveCandidate(
            $candidate,
            $plan->importRoot()
        );

        $decision = strtoupper(trim((string)($identity['decision'] ?? '')));

        if ($decision !== AlbumIdentityResolver::NEW_RELEASE) {
            $albumId = isset($identity['album_id']) && $identity['album_id'] !== null
                ? (int)$identity['album_id']
                : null;

            throw new RuntimeException(
                'CREATE plan lost NEW_RELEASE identity under lock; decision='
                . ($decision !== '' ? $decision : '<empty>')
                . ($albumId !== null ? ' album_id=' . $albumId : '')
            );
        }

        return $identity;
    }

    /**
     * Acquire all canonical CREATE locks in deterministic order.
     *
     * Every CREATE takes the coarse artist+title lock. If a release MBID is
     * known it also takes an MBID lock. This intentionally serializes same-title
     * releases (even with different/unknown years) and also catches aliases that
     * converge on the same exact release MBID. Safety is preferred over maximum
     * CREATE parallelism.
     *
     * @return array<int,string>
     */
    private function acquireCreateAdvisoryLocks(AlbumImportPlan $plan): array
    {
        $names = $this->createAdvisoryLockNames($plan);
        $acquired = [];

        try {
            foreach ($names as $name) {
                $stmt = $this->db->prepare('SELECT GET_LOCK(:name, 0)');
                $stmt->execute([':name' => $name]);
                $result = $stmt->fetchColumn();

                if ((string)$result !== '1') {
                    throw new RuntimeException(
                        'Concurrent CREATE lock unavailable: ' . $name
                    );
                }

                $acquired[] = $name;
            }
        } catch (Throwable $e) {
            $this->releaseCreateAdvisoryLocks($acquired);
            throw $e;
        }

        return $acquired;
    }

    /**
     * @param array<int,string> $names
     */
    private function releaseCreateAdvisoryLocks(array $names): void
    {
        foreach (array_reverse(array_values($names)) as $name) {
            try {
                $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
                $stmt->execute([':name' => (string)$name]);
            } catch (Throwable $e) {
                // Best effort only. Connection-scoped locks are also released
                // when the PDO connection closes; never mask import outcome.
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function createAdvisoryLockNames(AlbumImportPlan $plan): array
    {
        $snapshot = $plan->candidateSnapshot();
        $artist = $this->canonicalLockText((string)($snapshot['artist'] ?? ''));
        $title = $this->canonicalLockText((string)($snapshot['title'] ?? ''));

        if ($artist === '' || $title === '') {
            throw new RuntimeException(
                'CREATE advisory lock requires canonical artist and title.'
            );
        }

        $names = [
            'grizzly:create:at:'
            . substr(hash('sha256', $artist . '|' . $title), 0, 40),
        ];

        $releaseMbid = strtolower(trim((string)($snapshot['release_mbid'] ?? '')));
        if ($releaseMbid !== '') {
            $names[] = 'grizzly:create:mbid:'
                . substr(hash('sha256', $releaseMbid), 0, 38);
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        foreach ($names as $name) {
            if (strlen($name) > 64) {
                throw new RuntimeException(
                    'CREATE advisory lock name exceeds 64 characters.'
                );
            }
        }

        return $names;
    }

    private function canonicalLockText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }

        $value = str_replace(
            ["\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9C", "\xE2\x80\x9D", '_'],
            ["'", "'", '"', '"', ' '],
            $value
        );

        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        if (!is_string($normalized)) {
            $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $value);
        }

        $normalized = is_string($normalized) ? $normalized : $value;
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        return trim(is_string($normalized) ? $normalized : $value);
    }

    /**
     * Lock the exact RECONCILE target in the same transaction that will write.
     *
     * @return array<string,mixed>
     */
    private function lockTargetAlbumForUpdate(int $albumId): array
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException(
                'Target row lock requires an active DB transaction.'
            );
        }

        $stmt = $this->db->prepare(
            "SELECT id, artist_id, title, year, mbid, mb_release_group "
            . "FROM albums WHERE id = :id LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':id' => $albumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new RuntimeException(
                'RECONCILE target disappeared before row lock: ' . $albumId
            );
        }

        return $row;
    }

    private function requireDependencies(): void
    {
        $serviceDir = __DIR__;

        if (!class_exists('AlbumImportPlan')) {
            require_once $serviceDir . '/AlbumImportPlan.php';
        }

        if (!class_exists('AlbumReadinessStateRepository')) {
            require_once $serviceDir . '/AlbumReadinessStateRepository.php';
        }

        if (!class_exists('AlbumReadinessService')) {
            require_once $serviceDir . '/AlbumReadinessService.php';
        }
    }

    private function requireExecutionDependencies(): void
    {
        $serviceDir = __DIR__;

        if (!class_exists('AlbumImportFilesystemJournal')) {
            require_once $serviceDir . '/AlbumImportFilesystemJournal.php';
        }

        if (!class_exists('MediaPathResolver')) {
            require_once $serviceDir . '/MediaPathResolver.php';
        }

        if (!class_exists('MediaImportService')) {
            require_once $serviceDir . '/MediaImportService.php';
        }

        if (!class_exists('AlbumIdentityResolver')) {
            require_once $serviceDir . '/AlbumIdentityResolver.php';
        }
    }

    /**
     * Filesystem roots the bound importer is allowed to mutate.
     *
     * Covers always live under COVERS_PATH. Managed audio may live outside the
     * project entirely, so the active runtime path from MediaPathResolver must
     * be authorized independently. The journal canonicalizes both roots and
     * rejects filesystem-root configurations.
     *
     * @return string[]
     */
    private function filesystemJournalRoots(): array
    {
        if (!defined('COVERS_PATH')) {
            throw new RuntimeException(
                'COVERS_PATH is required for filesystem journal protection.'
            );
        }

        if (!class_exists('MediaPathResolver')) {
            throw new RuntimeException(
                'MediaPathResolver is required for filesystem journal protection.'
            );
        }

        $coverRoot = trim(str_replace('\\', '/', (string)COVERS_PATH));
        $audioRoot = trim(str_replace('\\', '/', MediaPathResolver::getAudioDir()));

        if ($coverRoot === '') {
            throw new RuntimeException('COVERS_PATH resolved to an empty path.');
        }

        if ($audioRoot === '') {
            throw new RuntimeException('Managed audio path resolved to an empty path.');
        }

        return array_values(array_unique([$coverRoot, $audioRoot]));
    }

    /**
     * Re-run Readiness only to recompute current fingerprint/evidence.
     *
     * A volatile repository is attached so v4.1 exposes candidate_signature.
     * It is NEVER saved.
     *
     * @return array<string,mixed>
     */
    private function analyzeCurrentCandidate(
        string $root,
        string $candidatePath,
        array $parts
    ): array {
        $statePath = rtrim(sys_get_temp_dir(), '/')
            . '/grizzly-import-executor-preflight-'
            . getmypid()
            . '-'
            . bin2hex(random_bytes(8))
            . '.json';

        @unlink($statePath);

        try {
            $repository = new AlbumReadinessStateRepository($statePath);
            $readiness = new AlbumReadinessService(
                $this->db,
                $this->basePath
            );
            $readiness->setStateRepository($repository);

            // stableSeconds=0 is intentional. A fresh volatile repository can
            // never mark first observation stable, but evidence_status and the
            // persistent candidate signature are already fully computed.
            return $readiness->analyzeCandidate(
                $root,
                $candidatePath,
                $parts,
                0,
                true
            );
        } finally {
            @unlink($statePath);
            @unlink($statePath . '.lock');
        }
    }

    private function validateParts(
        array $parts,
        string $root,
        string $candidatePath
    ): array {
        if (empty($parts)) {
            throw new RuntimeException(
                'Import plan has no candidate parts.'
            );
        }

        $validated = [];

        foreach (array_values($parts) as $idx => $part) {
            if (!is_array($part)) {
                throw new RuntimeException(
                    'Invalid candidate part #' . $idx
                );
            }

            $dir = $this->realDirectory(
                (string)($part['dir'] ?? ''),
                'candidate part #' . $idx
            );

            if (!$this->pathWithin($dir, $root)) {
                throw new RuntimeException(
                    'Candidate part is outside import root: ' . $dir
                );
            }

            if (!$this->pathWithin($dir, $candidatePath)) {
                throw new RuntimeException(
                    'Candidate part is outside candidate directory: ' . $dir
                );
            }

            $validated[] = [
                'dir' => $dir,
                'disc' => max(1, (int)($part['disc'] ?? 1)),
            ];
        }

        usort($validated, static function (array $a, array $b): int {
            $discCmp = ((int)$a['disc']) <=> ((int)$b['disc']);
            if ($discCmp !== 0) {
                return $discCmp;
            }
            return strcmp((string)$a['dir'], (string)$b['dir']);
        });

        return $validated;
    }

    /**
     * Compare only identity observed from the candidate media itself.
     *
     * Used immediately before COMMIT, after the bound import may already have
     * changed DB-side evidence inside the same transaction. Effective identity
     * is intentionally NOT compared here because it can legitimately change
     * as a consequence of our own uncommitted source_path/audio associations.
     *
     * @param array<string,mixed> $planned
     * @param array<string,mixed> $current
     */
    private function assertCandidateObservedSnapshot(
        array $planned,
        array $current
    ): void {
        foreach (['artist', 'title'] as $field) {
            $a = trim((string)($planned[$field] ?? ''));
            $b = trim((string)($current[$field] ?? ''));

            if ($a !== $b) {
                throw new RuntimeException(
                    'Candidate observed ' . $field . ' changed after decision.'
                );
            }
        }

        $plannedYear = array_key_exists('observed_year', $planned)
            && $planned['observed_year'] !== null
                ? (int)$planned['observed_year']
                : (
                    isset($planned['year']) && $planned['year'] !== null
                        ? (int)$planned['year']
                        : null
                );

        $currentYear = array_key_exists('observed_year', $current)
            && $current['observed_year'] !== null
                ? (int)$current['observed_year']
                : (
                    isset($current['year']) && $current['year'] !== null
                        ? (int)$current['year']
                        : null
                );

        if ($plannedYear !== $currentYear) {
            throw new RuntimeException(
                'Candidate observed year changed after decision.'
            );
        }

        $identityFields = [
            ['observed_release_mbid', 'release_mbid'],
            ['observed_release_group', 'release_group'],
        ];

        foreach ($identityFields as $fields) {
            [$observedField, $fallbackField] = $fields;

            $a = array_key_exists($observedField, $planned)
                ? strtolower(trim((string)($planned[$observedField] ?? '')))
                : strtolower(trim((string)($planned[$fallbackField] ?? '')));

            $b = array_key_exists($observedField, $current)
                ? strtolower(trim((string)($current[$observedField] ?? '')))
                : strtolower(trim((string)($current[$fallbackField] ?? '')));

            if ($a !== $b) {
                throw new RuntimeException(
                    'Candidate observed ' . $fallbackField
                    . ' changed after decision.'
                );
            }
        }

        $plannedObserved = max(
            0,
            (int)($planned['observed_tracks'] ?? 0)
        );
        $currentObserved = max(
            0,
            (int)($current['observed_tracks'] ?? 0)
        );

        if ($plannedObserved !== $currentObserved) {
            throw new RuntimeException(
                'Candidate observed track count changed after decision.'
            );
        }
    }

    private function assertCandidateIdentitySnapshot(
        array $planned,
        array $current
    ): void {
        foreach (['artist', 'title'] as $field) {
            $a = trim((string)($planned[$field] ?? ''));
            $b = trim((string)($current[$field] ?? ''));

            if ($a !== $b) {
                throw new RuntimeException(
                    'Candidate ' . $field . ' changed after decision.'
                );
            }
        }

        $plannedYear = isset($planned['year'])
            && $planned['year'] !== null
                ? (int)$planned['year']
                : null;
        $currentYear = isset($current['year'])
            && $current['year'] !== null
                ? (int)$current['year']
                : null;

        if ($plannedYear !== $currentYear) {
            throw new RuntimeException(
                'Candidate year changed after decision.'
            );
        }

        foreach (['release_mbid', 'release_group'] as $field) {
            $a = strtolower(trim((string)($planned[$field] ?? '')));
            $b = strtolower(trim((string)($current[$field] ?? '')));

            if ($a !== $b) {
                throw new RuntimeException(
                    'Candidate ' . $field . ' changed after decision.'
                );
            }
        }

        $plannedObserved = max(
            0,
            (int)($planned['observed_tracks'] ?? 0)
        );
        $currentObserved = max(
            0,
            (int)($current['observed_tracks'] ?? 0)
        );

        if ($plannedObserved !== $currentObserved) {
            throw new RuntimeException(
                'Candidate observed track count changed after decision.'
            );
        }
    }

    private function loadTargetAlbum(int $albumId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, artist_id, title, year, mbid, mb_release_group "
            . "FROM albums WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $albumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function realDirectory(string $path, string $label): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '') {
            throw new RuntimeException(
                $label . ' path is empty.'
            );
        }

        $real = realpath($path);
        if (
            $real === false
            || !is_dir($real)
            || !is_readable($real)
        ) {
            throw new RuntimeException(
                $label . ' is missing or unreadable: ' . $path
            );
        }

        return rtrim(str_replace('\\', '/', $real), '/');
    }

    private function pathWithin(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $path === $root
            || strpos($path . '/', $root . '/') === 0;
    }
}

<?php

/**
 * AlbumReadinessService
 *
 * Read-only readiness engine for the media scanner.
 * PHP 7.4 compatible.
 *
 * IMPORTANT:
 * - does not INSERT/UPDATE/DELETE anything;
 * - GRIZZLY_SCANNER_EVIDENCE_V1: may read cached MusicBrainz scanner
 *   evidence; network lookup is allowed only for an already stable candidate
 *   when internal completeness evidence is still insufficient;
 * - intentionally ignores media_scan_excluded so shadow mode can observe the
 *   whole watched root and show what a future downloader-agnostic gate would do;
 * - does not replace MediaImportService yet.
 *
 * Shadow decisions:
 *   READY       complete evidence exists and the candidate looks stable;
 *   PENDING     still changing, temp/in-progress files exist, or completeness
 *               cannot yet be proven;
 *   INCOMPLETE  stable candidate with positive evidence that tracks/discs are
 *               missing;
 *   AMBIGUOUS   conflicting metadata/completeness evidence; never auto-import.
 */
class AlbumReadinessService
{
    private $stateRepository = null;

    // GRIZZLY_SCANNER_EVIDENCE_V1
    /** @var mixed|null */
    private $metadataService = null;

    /** @var mixed|null */
    private $trackMatcher = null;

    /** @var mixed|null */
    private $titleMatcher = null;

    /** @var bool */
    private $externalEvidenceNetworkAllowed = true;

    public function setStateRepository(AlbumReadinessStateRepository $repository): void
    {
        $this->stateRepository = $repository;
    }

    // Executor/preflight can force cache-only behaviour explicitly.
    public function setExternalEvidenceNetworkAllowed(bool $allowed): void
    {
        $this->externalEvidenceNetworkAllowed = $allowed;
    }


    private const AUDIO_EXT = ['mp3', 'flac'];

    /** @var PDO */
    private $db;

    /** @var mixed|null */
    private $id3;

    /** @var string */
    private $basePath;

    public function __construct(PDO $db, ?string $basePath = null)
    {
        $this->db = $db;
        $this->basePath = $basePath !== null && trim($basePath) !== ''
            ? rtrim($basePath, '/')
            : (defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : dirname(__DIR__, 2));
        $this->id3 = $this->loadGetID3();
    }

    /**
     * Analyze the entire root without honoring media_scan_excluded.
     *
     * @return array<string,mixed>
     */
    public function analyzeRoot(string $importDir, int $stableSeconds = 30): array
    {
        $stableSeconds = max(0, $stableSeconds);
        $real = realpath($importDir);

        $report = [
            'mode'            => 'shadow-read-only',
            'import_dir'      => $importDir,
            'runtime_root'    => null,
            'stable_seconds'  => $stableSeconds,
            'getid3'          => $this->id3 !== null,
            'candidates'      => [],
            'totals'          => [
                'candidates' => 0,
                'ready'      => 0,
                'pending'    => 0,
                'incomplete' => 0,
                'ambiguous'  => 0,
            ],
            'error'           => null,
        ];

        if ($real === false || !is_dir($real)) {
            $report['error'] = 'Cartella non trovata: ' . $importDir;
            return $report;
        }
        if (!is_readable($real)) {
            $report['error'] = 'Cartella non leggibile: ' . $real;
            return $report;
        }

        $root = rtrim(str_replace('\\', '/', $real), '/');
        $report['runtime_root'] = $root;

        foreach ($this->discoverCandidates($root) as $albumKey => $parts) {
            $row = $this->analyzeCandidate($root, (string)$albumKey, $parts, $stableSeconds);
            $report['candidates'][] = $row;

            $statusKey = strtolower((string)$row['status']);
            if (isset($report['totals'][$statusKey])) {
                $report['totals'][$statusKey]++;
            }
        }

        $report['totals']['candidates'] = count($report['candidates']);
        return $report;
    }

    /**
     * @return array<string,array<int,array{dir:string,disc:int}>>
     */
    public function discoverCandidates(string $importDir): array
    {
        $real = realpath($importDir);
        if ($real === false || !is_dir($real) || !is_readable($real)) {
            return [];
        }

        $root = rtrim(str_replace('\\', '/', $real), '/');
        $candidates = [];

        foreach ($this->allDirectories($root) as $dir) {
            if (!empty($this->listAudioFiles($dir))) {
                $candidates[] = $dir;
            }
        }

        $groups = [];
        foreach ($candidates as $dir) {
            $disc = $this->discNumberFromName(basename($dir));

            if ($disc !== null && $dir !== $root) {
                $key = dirname($dir);
            } else {
                $key = $dir;
                $disc = $disc === null ? 1 : $disc;
            }

            $key = rtrim(str_replace('\\', '/', $key), '/');
            if ($key === '' || strpos($key . '/', $root . '/') !== 0) {
                $key = $dir;
                $disc = 1;
            }

            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }

            $groups[$key][] = [
                'dir'  => $dir,
                'disc' => $disc === null ? 1 : (int)$disc,
            ];
        }

        foreach ($groups as &$parts) {
            usort($parts, static function (array $a, array $b): int {
                $discCmp = ((int)$a['disc']) <=> ((int)$b['disc']);
                if ($discCmp !== 0) {
                    return $discCmp;
                }
                return strcmp((string)$a['dir'], (string)$b['dir']);
            });
        }
        unset($parts);

        ksort($groups, SORT_STRING);
        return $groups;
    }

    /**
     * @param array<int,array{dir:string,disc:int}> $parts
     * @return array<string,mixed>
     */
    public function analyzeCandidate(
        string $importRoot,
        string $albumKey,
        array $parts,
        int $stableSeconds = 30
    ): array {
        $stableSeconds = max(0, $stableSeconds);
        $audio = [];
        $unreadable = [];

        foreach ($parts as $part) {
            $dir = isset($part['dir']) ? (string)$part['dir'] : '';
            $disc = isset($part['disc']) ? max(1, (int)$part['disc']) : 1;

            foreach ($this->listAudioFiles($dir) as $path) {
                $key = realpath($path);
                $key = $key !== false ? $key : $path;
                $audio[$key] = [
                    'path' => $path,
                    'disc' => $disc,
                ];

                if (!is_readable($path)) {
                    $unreadable[] = $path;
                }
            }
        }

        $audio = array_values($audio);
        usort($audio, static function (array $a, array $b): int {
            $discCmp = ((int)$a['disc']) <=> ((int)$b['disc']);
            if ($discCmp !== 0) {
                return $discCmp;
            }
            return strcmp((string)$a['path'], (string)$b['path']);
        });

        $tempFiles = $this->findInProgressFiles($albumKey);
        $latestMtime = $this->latestRelevantMtime($audio, $tempFiles);

        $stableFor = 0;
        $stable = false;
        $stabilityBasis = 'mtime-age-shadow-proxy';
        $candidateSignature = null;
        $signatureChanged = null;
        $signatureScanCount = 0;
        $signatureFirstSeenAt = null;
        $signatureLastSeenAt = null;
        $signatureLastChangedAt = null;

        $metadataRows = [];
        foreach ($audio as $row) {
            $metadataRows[] = $this->analyzeAudio((string)$row['path'], (int)$row['disc']);
        }

        if ($this->stateRepository !== null) {
            $signatureInfo = $this->buildPersistentSignature(
                $importRoot,
                $albumKey,
                $audio,
                $metadataRows,
                $tempFiles
            );

            $observation = $this->stateRepository->observe(
                (string)$signatureInfo['key'],
                (string)$signatureInfo['root'],
                (string)$signatureInfo['path'],
                (string)$signatureInfo['signature'],
                $stableSeconds
            );

            $candidateSignature = (string)$observation['signature'];
            $signatureChanged = (bool)$observation['changed'];
            $signatureScanCount = (int)$observation['scan_count'];
            $signatureFirstSeenAt = (int)$observation['first_seen_at'];
            $signatureLastSeenAt = (int)$observation['last_seen_at'];
            $signatureLastChangedAt = (int)$observation['last_changed_at'];
            $stableFor = (int)$observation['stable_for'];

            $stable = empty($unreadable)
                && empty($tempFiles)
                && !empty($observation['stable']);

            $stabilityBasis = 'persistent-signature-v1';
        } else {
            $stableFor = $latestMtime > 0 ? max(0, time() - $latestMtime) : 0;

            $stable = empty($unreadable)
                && empty($tempFiles)
                && $latestMtime > 0
                && $stableFor >= $stableSeconds;
        }

        $identity = $this->candidateIdentity($albumKey, $metadataRows);
        $tagEvidence = $this->tagCompletenessEvidence($metadataRows);
        $localEvidence = $this->localCompletenessEvidence(
            $importRoot,
            $albumKey,
            $parts,
            $audio,
            $identity,
            $metadataRows
        );

        $reasons = [];
        $status = 'PENDING';
        $observedTracks = count($audio);

        $tagExpected = !empty($tagEvidence['expected_tracks'])
            ? (int)$tagEvidence['expected_tracks']
            : null;

        $localExpected = !empty($localEvidence['expected_tracks'])
            ? (int)$localEvidence['expected_tracks']
            : null;

        $expectedTracks = null;
        $expectedSource = null;

        // Candidate identity returned downstream. Strong external evidence may
        // fill missing MusicBrainz IDs, but never overwrites IDs read from tags.
        $effectiveReleaseMbid = strtolower(trim((string)($identity['release_mbid'] ?? '')));
        $effectiveReleaseGroup = strtolower(trim((string)($identity['release_group'] ?? '')));

        $externalEvidence = [
            'status' => 'not-needed',
            'source' => null,
            'cache' => null,
            'expected_tracks' => null,
            'release_mbid' => '',
            'release_group' => '',
            'track_match' => null,
            'year' => null,
            'year_mismatch' => false,
            'candidate_year' => $identity['year'],
            'candidate_year_source' => $identity['year_source'] ?? null,
        ];

        $observedYear = $identity['year'];
        $observedYearSource = $identity['year_source'] ?? null;
        $effectiveYear = $observedYear;
        $effectiveYearSource = $observedYearSource;

        $conflicts = array_values(array_unique(array_merge(
            isset($identity['conflicts']) && is_array($identity['conflicts']) ? $identity['conflicts'] : [],
            isset($tagEvidence['conflicts']) && is_array($tagEvidence['conflicts']) ? $tagEvidence['conflicts'] : []
        )));

        if ($tagExpected !== null && $tagExpected > 0) {
            // TRACKTOTAL/TOTALTRACKS coerente sui file e' una prova diretta
            // della completezza attesa del candidato corrente.
            $expectedTracks = $tagExpected;
            $expectedSource = 'tags';

            if ($localExpected !== null && $localExpected > 0) {
                if ($localExpected === $tagExpected) {
                    $expectedSource = (string)$localEvidence['source'] . '+tags';
                } elseif ($localExpected > $tagExpected) {
                    // Il DB locale conosce piu' tracce di quante ne dichiarino
                    // i file: possibile edition/identity mismatch. Non indovinare.
                    $conflicts[] = 'local-track-count-exceeds-tag-total:'
                        . $localExpected . '/' . $tagExpected;
                }
                // localExpected < tagExpected NON e' un conflitto:
                // il DB locale potrebbe essere stato creato da una vecchia
                // scansione incompleta (caso The Catastrophist 10/11).
            }
        } elseif ($localExpected !== null && $localExpected > 0) {
            $localTracklistExact = !empty($localEvidence['tracklist_exact']);

            if ($localExpected > $observedTracks) {
                // Il DB locale puo' provare che mancano file se conosce PIU'
                // tracce di quelle osservate.
                $expectedTracks = $localExpected;
                $expectedSource = (string)$localEvidence['source'];
            } elseif ($localExpected === $observedTracks && $localTracklistExact) {
                // Per un album manuale/curato (needs_review=0), il confronto
                // esatto dell'intera tracklist candidata con quella locale e'
                // una prova di completezza. Il solo conteggio continua a NON
                // bastare, cosi' un vecchio album scanner incompleto non puo'
                // certificare un altro candidato incompleto.
                $expectedTracks = $localExpected;
                $expectedSource = (string)$localEvidence['source'] . '+tracklist';
            }
        }


        // GRIZZLY_SCANNER_EVIDENCE_V1
        //
        // Internal evidence always wins. MusicBrainz is consulted only when
        // neither tags nor trusted local evidence can prove completeness.
        //
        // A cache read is allowed on every pass. Network is allowed only when
        // the persistent candidate is already stable. This means:
        // - active downloads never trigger external traffic;
        // - the worker resolves an unknown complete album once;
        // - Executor/preflight can revalidate from cache without network.
        if (
            $expectedTracks === null
            && empty($conflicts)
            && empty($tempFiles)
            && empty($unreadable)
        ) {
            $externalEvidence = $this->externalCompletenessEvidence(
                $identity,
                $audio,
                $metadataRows,
                $stable
            );

            if (!empty($externalEvidence['matched'])) {
                $externalExpected = (int)($externalEvidence['expected_tracks'] ?? 0);

                if ($externalExpected > 0) {
                    $expectedTracks = $externalExpected;
                    $expectedSource = 'musicbrainz-release+tracklist';

                    if ($effectiveReleaseMbid === '') {
                        $effectiveReleaseMbid = strtolower(trim((string)(
                            $externalEvidence['release_mbid'] ?? ''
                        )));
                    }

                    if ($effectiveReleaseGroup === '') {
                        $effectiveReleaseGroup = strtolower(trim((string)(
                            $externalEvidence['release_group'] ?? ''
                        )));
                    }

                    $externalYear = isset($externalEvidence['year'])
                        && $externalEvidence['year'] !== null
                        ? (int)$externalEvidence['year']
                        : null;

                    if (
                        $externalYear !== null
                        && $externalYear > 0
                        && $externalYear !== (int)$effectiveYear
                        && $effectiveYearSource !== 'original-tag'
                        && $this->isUuid($effectiveReleaseMbid)
                    ) {
                        // Full one-to-one MusicBrainz tracklist evidence is
                        // stronger than a generic YEAR/DATE tag (or folder year).
                        // Canonicalize the album year, but preserve the observed
                        // source year separately in the returned candidate.
                        $effectiveYear = $externalYear;
                        $effectiveYearSource = 'musicbrainz-release+tracklist';
                    }

                    // Re-run local evidence with the enriched/canonical identity.
                    // This lets an existing album be reconciled by exact release
                    // MBID or canonical year even when the source files carried a
                    // reissue/generic YEAR value.
                    $effectiveIdentity = $identity;
                    $effectiveIdentity['year'] = $effectiveYear;
                    $effectiveIdentity['year_source'] = $effectiveYearSource;
                    $effectiveIdentity['release_mbid'] = $effectiveReleaseMbid;
                    $effectiveIdentity['release_group'] = $effectiveReleaseGroup;

                    $refreshedLocalEvidence = $this->localCompletenessEvidence(
                        $importRoot,
                        $albumKey,
                        $parts,
                        $audio,
                        $effectiveIdentity,
                        $metadataRows
                    );

                    if (!empty($refreshedLocalEvidence['album_id'])) {
                        $localEvidence = $refreshedLocalEvidence;
                    }
                }
            }
        }

        $conflicts = array_values(array_unique($conflicts));

        $missingTracks = isset($tagEvidence['missing_tracks'])
            && is_array($tagEvidence['missing_tracks'])
            ? $tagEvidence['missing_tracks']
            : [];

        // Prima classifichiamo SOLO l'evidenza di completezza.
        // La stabilita' persistente viene applicata come gate separato dopo.
        if (!empty($conflicts)) {
            $status = 'AMBIGUOUS';
            $reasons = $conflicts;
        } elseif (!empty($tempFiles)) {
            $status = 'PENDING';
            $reasons[] = 'in-progress-files-present';
        } elseif (!empty($unreadable)) {
            $status = 'PENDING';
            $reasons[] = 'unreadable-audio-files';
        } elseif (!empty($tagEvidence['missing_discs'])) {
            $status = 'INCOMPLETE';
            $reasons[] = 'missing-disc(s):' . implode(',', $tagEvidence['missing_discs']);
        } elseif (!empty($missingTracks)) {
            $status = 'INCOMPLETE';

            if ($expectedTracks !== null && $observedTracks < $expectedTracks) {
                $reasons[] = 'tracks-missing:' . $observedTracks . '/' . $expectedTracks;
            }

            $reasons[] = 'missing-track-position(s):' . implode(',', $missingTracks);
        } elseif ($expectedTracks === null) {
            $status = 'PENDING';
            $reasons[] = 'expected-track-count-unknown';
        } elseif ($observedTracks < $expectedTracks) {
            $status = 'INCOMPLETE';
            $reasons[] = 'tracks-missing:' . $observedTracks . '/' . $expectedTracks;
        } elseif ($observedTracks > $expectedTracks) {
            $status = 'AMBIGUOUS';
            $reasons[] = 'observed-tracks-exceed-expected:' . $observedTracks . '/' . $expectedTracks;
        } else {
            $status = 'READY';
            $reasons[] = 'complete-evidence-confirmed';
        }

        $evidenceStatus = $status;
        $evidenceReasons = $reasons;

        // Gate di stabilita':
        // - con repository persistente, qualunque candidato non stabile resta PENDING;
        // - senza repository, preserviamo il comportamento legacy shadow v3.
        if ($this->stateRepository !== null) {
            if (!$stable) {
                $status = 'PENDING';
                $reasons = ['not-stable-yet'];
            }
        } elseif (!$stable && ($status === 'READY' || $status === 'INCOMPLETE')) {
            $status = 'PENDING';

            if ($evidenceStatus === 'READY') {
                $reasons = ['not-stable-yet'];
            }
        }

        // Shadow v3: readiness e azione sono due dimensioni separate.
        // Questo blocco NON esegue alcuna azione; espone solo una decisione advisory.
        $action = 'NONE';
        $actionReason = 'candidate-not-actionable';
        $actionConfidence = null;

        if (!empty($tempFiles)) {
            $action = 'NONE';
            $actionReason = 'in-progress-files-present';
        } elseif (!empty($unreadable)) {
            $action = 'NONE';
            $actionReason = 'unreadable-audio-files';
        } elseif (!$stable) {
            $action = 'NONE';
            $actionReason = 'not-stable-yet';
        } elseif (!empty($conflicts)) {
            $action = 'REVIEW';
            $actionReason = 'metadata-or-identity-conflict';
        } elseif (!empty($localEvidence['album_id'])) {
            $action = 'RECONCILE';
            $actionReason = 'existing-local-album';

            $localSource = (string)($localEvidence['source'] ?? '');
            if (in_array($localSource, ['local-source-path', 'local-release-mbid'], true)) {
                $actionConfidence = 'strong';
            } elseif ($localSource === 'local-artist-title-year') {
                // Advisory only: il futuro AlbumIdentityResolver dovra'
                // confermare questo match prima di qualunque scrittura.
                $actionConfidence = 'candidate';
            } else {
                $actionConfidence = 'candidate';
            }
        } elseif ($status === 'READY') {
            $action = 'CREATE';
            $actionReason = 'new-candidate-ready';
            $actionConfidence = 'strong';
        } elseif ($status === 'AMBIGUOUS') {
            $action = 'REVIEW';
            $actionReason = 'ambiguous-new-candidate';
        } else {
            $action = 'NONE';
            $actionReason = 'new-candidate-not-ready';
        }

        return [
            'path'               => $albumKey,
            'status'             => $status,
            'reason'             => implode('; ', $reasons),
            'reasons'            => $reasons,
            'artist'             => (string)$identity['artist'],
            'title'              => (string)$identity['title'],
            'year'               => $effectiveYear,
            'year_source'        => $effectiveYearSource,
            'observed_year'      => $observedYear,
            'observed_year_source' => $observedYearSource,
            'release_mbid'       => $effectiveReleaseMbid,
            'release_group'      => $effectiveReleaseGroup,
            'observed_tracks'    => $observedTracks,
            'expected_tracks'    => $expectedTracks,
            'expected_source'    => $expectedSource,
            'local_album_id'     => $localEvidence['album_id'],
            'local_match_source' => $localEvidence['source'],
            'stable'             => $stable,
            'stable_for'         => $stableFor,
            'stable_seconds'     => $stableSeconds,
            'stability_basis'    => $stabilityBasis,
            'latest_mtime'       => $latestMtime > 0 ? $latestMtime : null,
            'candidate_signature'=> $candidateSignature,
            'signature_changed'  => $signatureChanged,
            'signature_scan_count' => $signatureScanCount,
            'first_seen_at'      => $signatureFirstSeenAt,
            'last_seen_at'       => $signatureLastSeenAt,
            'last_changed_at'    => $signatureLastChangedAt,
            'evidence_status'    => $evidenceStatus,
            'evidence_reasons'   => $evidenceReasons,
            'temporary_files'    => $tempFiles,
            'unreadable_files'   => $unreadable,
            'missing_discs'      => $tagEvidence['missing_discs'],
            'missing_tracks'     => $missingTracks,
            'metadata_conflicts' => $conflicts,
            'external_evidence'  => $externalEvidence,
            'action'             => $action,
            'action_reason'      => $actionReason,
            'action_confidence'  => $actionConfidence,
            'parts'              => $parts,
        ];
    }

    /** @return array<int,string> */
    private function allDirectories(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $out = [$root];
        $stack = [$root];

        while (!empty($stack)) {
            $dir = array_pop($stack);
            $entries = @scandir($dir);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || ($entry !== '' && $entry[0] === '.')) {
                    continue;
                }

                $path = $dir . '/' . $entry;
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    $path = rtrim(str_replace('\\', '/', $path), '/');
                    $out[] = $path;
                    $stack[] = $path;
                }
            }
        }

        sort($out, SORT_STRING);
        return $out;
    }

    /** @return array<int,string> */
    private function listAudioFiles(string $dir): array
    {
        $out = [];
        $entries = @scandir($dir);
        if ($entries === false) {
            return $out;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || ($entry !== '' && $entry[0] === '.')) {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (!is_file($path) || is_link($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($ext, self::AUDIO_EXT, true)) {
                $out[] = str_replace('\\', '/', $path);
            }
        }

        sort($out, SORT_STRING);
        return $out;
    }

    private function discNumberFromName(string $name): ?int
    {
        if (preg_match('/^(cd|disc|disco|disk)\s*[-_. ]?\s*(\d{1,2})$/i', trim($name), $m)) {
            return (int)$m[2];
        }
        return null;
    }

    /** @return array<int,string> */
    private function findInProgressFiles(string $albumKey): array
    {
        $hits = [];
        if (!is_dir($albumKey) || !is_readable($albumKey)) {
            return $hits;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($albumKey, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }

                $name = $file->getFilename();
                if ($this->isInProgressFilename($name)) {
                    $hits[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        } catch (Throwable $e) {
            // A transient filesystem problem must never turn into READY.
            $hits[] = $albumKey . '/[filesystem-scan-error]';
        }

        sort($hits, SORT_STRING);
        return $hits;
    }

    private function isInProgressFilename(string $name): bool
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return false;
        }

        return (bool)preg_match(
            '/(?:\.part|\.partial|\.tmp|\.crdownload|\.aria2|\.download|\.opdownload|\.filepart|\.!qb)$/i',
            $name
        );
    }

    /**
     * @param array<int,array{path:string,disc:int}> $audio
     * @param array<int,string> $tempFiles
     */
    private function latestRelevantMtime(array $audio, array $tempFiles): int
    {
        $latest = 0;
        foreach ($audio as $row) {
            $mtime = @filemtime((string)$row['path']);
            if ($mtime !== false) {
                $latest = max($latest, (int)$mtime);
            }
        }
        foreach ($tempFiles as $path) {
            if (strpos($path, '[filesystem-scan-error]') !== false) {
                continue;
            }
            $mtime = @filemtime($path);
            if ($mtime !== false) {
                $latest = max($latest, (int)$mtime);
            }
        }
        return $latest;
    }

    /** @return array<string,mixed> */
    private function analyzeAudio(string $path, int $structuralDisc): array
    {
        $out = [
            'artist'         => '',
            'albumartist'    => '',
            'album'          => '',
            'title'          => '',
            'year'           => null,
            'year_source'    => null,
            'track'          => null,
            'track_total'    => null,
            'disc'           => $structuralDisc > 0 ? $structuralDisc : 1,
            'disc_total'     => null,
            'release_mbid'   => '',
            'release_group'  => '',
        ];

        if ($this->id3 === null) {
            return $out;
        }

        try {
            $info = @$this->id3->analyze($path);
        } catch (Throwable $e) {
            return $out;
        }
        if (!is_array($info)) {
            return $out;
        }

        if (class_exists('getid3_lib')) {
            getid3_lib::CopyTagsToComments($info);
        }

        $comments = isset($info['comments']) && is_array($info['comments'])
            ? $info['comments']
            : [];

        $out['artist'] = $this->firstComment($comments, ['artist']);
        $out['albumartist'] = $this->firstComment(
            $comments,
            ['albumartist', 'album_artist', 'band', 'ensemble']
        );
        $out['album'] = $this->firstComment($comments, ['album']);
        $out['title'] = $this->firstComment($comments, ['title']);

        $out['year'] = $this->firstYearFromComments(
            $comments,
            ['original_year', 'originalyear', 'originaldate', 'original_release_time'],
            ['ORIGINALYEAR', 'ORIGINALDATE', 'ORIGINAL YEAR', 'ORIGINAL DATE']
        );
        if ($out['year'] !== null) {
            // An explicit original-release date is strong identity evidence.
            $out['year_source'] = 'original-tag';
        } else {
            $out['year'] = $this->firstYearFromComments(
                $comments,
                ['year', 'date', 'recording_time', 'creation_date'],
                []
            );
            if ($out['year'] !== null) {
                // Generic YEAR/DATE may describe a reissue/rip/tagging edition.
                // Keep its provenance so stronger verified evidence can
                // canonicalize the album year without losing the observation.
                $out['year_source'] = 'tag';
            }
        }

        $trackRaw = $this->firstComment($comments, ['track_number', 'tracknumber', 'track']);
        $trackPair = $this->numberAndTotal($trackRaw);
        $out['track'] = $trackPair['number'];
        $out['track_total'] = $trackPair['total'];
        if ($out['track_total'] === null) {
            $out['track_total'] = $this->firstPositiveInt(
                $comments,
                ['track_total', 'tracktotal', 'totaltracks', 'total_tracks', 'tracks_total'],
                ['TRACKTOTAL', 'TOTALTRACKS', 'TOTAL TRACKS']
            );
        }

        $discRaw = $this->firstComment(
            $comments,
            ['part_of_a_set', 'discnumber', 'disc_number', 'disc']
        );
        $discPair = $this->numberAndTotal($discRaw);
        if ($discPair['number'] !== null) {
            $out['disc'] = $discPair['number'];
        }
        $out['disc_total'] = $discPair['total'];
        if ($out['disc_total'] === null) {
            $out['disc_total'] = $this->firstPositiveInt(
                $comments,
                ['disc_total', 'disctotal', 'totaldiscs', 'total_discs', 'discs_total'],
                ['DISCTOTAL', 'TOTALDISCS', 'TOTAL DISCS']
            );
        }

        $out['release_mbid'] = strtolower($this->firstCommentOrText(
            $comments,
            ['musicbrainz_albumid', 'musicbrainz_releaseid', 'musicbrainz_release_id'],
            ['MUSICBRAINZ_ALBUMID', 'MUSICBRAINZ_RELEASEID', 'MUSICBRAINZ_RELEASE_ID']
        ));
        $out['release_group'] = strtolower($this->firstCommentOrText(
            $comments,
            ['musicbrainz_releasegroupid', 'musicbrainz_release_group_id', 'musicbrainz_releasegroup_id'],
            ['MUSICBRAINZ_RELEASEGROUPID', 'MUSICBRAINZ_RELEASE_GROUP_ID']
        ));

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function candidateIdentity(string $albumKey, array $rows): array
    {
        $albumArtists = [];
        $trackArtists = [];
        $albums = [];
        $years = [];
        $yearSources = [];
        $releaseMbids = [];
        $releaseGroups = [];

        foreach ($rows as $row) {
            $this->collectIdentityValue($albumArtists, (string)($row['albumartist'] ?? ''));
            $this->collectIdentityValue($trackArtists, (string)($row['artist'] ?? ''));
            $this->collectAlbumTitleValue($albums, (string)($row['album'] ?? ''));

            if (!empty($row['year'])) {
                $years[(int)$row['year']] = (int)$row['year'];
                $yearSource = trim((string)($row['year_source'] ?? ''));
                if ($yearSource !== '') {
                    $yearSources[$yearSource] = true;
                }
            }
            $this->collectIdentityValue($releaseMbids, (string)($row['release_mbid'] ?? ''));
            $this->collectIdentityValue($releaseGroups, (string)($row['release_group'] ?? ''));
        }

        $conflicts = [];
        if (count($albumArtists) > 1) {
            $conflicts[] = 'multiple-album-artists';
        }
        if (count($albums) > 1) {
            $conflicts[] = 'multiple-album-titles';
        }
        if (count($years) > 1) {
            $conflicts[] = 'multiple-album-years';
        }
        if (count($releaseMbids) > 1) {
            $conflicts[] = 'multiple-release-mbids';
        }
        if (count($releaseGroups) > 1) {
            $conflicts[] = 'multiple-release-groups';
        }

        $artist = '';
        $albumArtist = count($albumArtists) === 1 ? (string)reset($albumArtists) : '';

        if ($albumArtist !== '') {
            $albumArtistKey = $this->normalizeIdentity($albumArtist);
            $isVariousArtists = in_array(
                $albumArtistKey,
                ['variousartists', 'variousartist', 'va'],
                true
            );

            if ($isVariousArtists && count($trackArtists) === 1) {
                // Caso tipico di tag errato: ALBUMARTIST=Various Artists
                // ma tutte le tracce appartengono allo stesso artista reale.
                $artist = (string)reset($trackArtists);
            } else {
                $artist = $albumArtist;
            }
        }

        if ($artist === '' && count($trackArtists) === 1) {
            $artist = (string)reset($trackArtists);
        } elseif ($artist === '' && count($trackArtists) > 1) {
            $conflicts[] = 'multiple-track-artists-without-albumartist';
        }

        $title = count($albums) === 1 ? (string)reset($albums) : '';
        $year = count($years) === 1 ? (int)reset($years) : null;
        $yearSource = null;
        if ($year !== null) {
            // If at least one file explicitly carries ORIGINALYEAR/ORIGINALDATE,
            // keep that stronger provenance for the candidate as a whole.
            $yearSource = isset($yearSources['original-tag']) ? 'original-tag' : 'tag';
        }

        $folder = $this->parseFolderName(basename($albumKey));
        if ($artist === '' && $folder['artist'] !== '') {
            $artist = $folder['artist'];
        }
        if ($title === '' && $folder['title'] !== '') {
            $title = $folder['title'];
        }
        if ($year === null && $folder['year'] !== null) {
            $year = $folder['year'];
            $yearSource = 'folder';
        }

        return [
            'artist'        => $artist,
            'title'         => $title,
            'year'          => $year,
            'year_source'   => $yearSource,
            'release_mbid'  => count($releaseMbids) === 1 ? (string)reset($releaseMbids) : '',
            'release_group' => count($releaseGroups) === 1 ? (string)reset($releaseGroups) : '',
            'conflicts'     => array_values(array_unique($conflicts)),
        ];
    }

    /** @param array<string,string> $bucket */
    private function collectIdentityValue(array &$bucket, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        $key = $this->normalizeIdentity($value);
        if ($key !== '' && !isset($bucket[$key])) {
            $bucket[$key] = $value;
        }
    }

    /** @param array<string,string> $bucket */
    private function collectAlbumTitleValue(array &$bucket, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        foreach ($bucket as $existing) {
            if ($this->titleMatcher()->equivalent(
                $value,
                (string)$existing
            )) {
                return;
            }
        }

        $key = $this->normalizeIdentity($value);
        if ($key !== '' && !isset($bucket[$key])) {
            $bucket[$key] = $value;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function tagCompletenessEvidence(array $rows): array
    {
        // GRIZZLY_TNT_GLOBAL_TRACK_TOTAL_FIX_V1
        //
        // TRACKTOTAL is not universally "tracks on this disc".
        // Real-world tags also use an album-global TRACKTOTAL repeated on each
        // disc/side while TRACKNUMBER continues globally (e.g. D1=1..6,
        // D2=7..12, TRACKTOTAL=12 on every file).
        //
        // Old behaviour summed 12 + 12 => 24 and manufactured missing tracks.
        // We distinguish:
        //   - global numbering: track positions do not repeat across discs and
        //     all discs expose the same total => expected is that single total;
        //   - per-disc numbering: positions repeat/reset across discs or totals
        //     differ => expected is the sum of per-disc totals.
        $discTotals = [];
        $discTotalValues = [];
        $observedDiscs = [];
        $trackPositions = [];
        $conflicts = [];

        foreach ($rows as $row) {
            $disc = !empty($row['disc']) ? max(1, (int)$row['disc']) : 1;
            $observedDiscs[$disc] = true;

            if (!empty($row['track'])) {
                $track = (int)$row['track'];
                if ($track > 0) {
                    if (!isset($trackPositions[$disc])) {
                        $trackPositions[$disc] = [];
                    }
                    $trackPositions[$disc][$track] = true;
                }
            }

            if (!empty($row['track_total'])) {
                $total = (int)$row['track_total'];
                if ($total > 0) {
                    if (!isset($discTotals[$disc])) {
                        $discTotals[$disc] = [];
                    }
                    $discTotals[$disc][$total] = true;
                }
            }

            if (!empty($row['disc_total'])) {
                $discTotal = (int)$row['disc_total'];
                if ($discTotal > 0) {
                    $discTotalValues[$discTotal] = true;
                }
            }
        }

        foreach ($discTotals as $disc => $totals) {
            if (count($totals) > 1) {
                $conflicts[] = 'track-total-conflict-disc-' . $disc;
            }
        }

        if (count($discTotalValues) > 1) {
            $conflicts[] = 'disc-total-conflict';
        }

        $knownDiscTotal = count($discTotalValues) === 1
            ? (int)array_key_first($discTotalValues)
            : null;

        $missingDiscs = [];
        if ($knownDiscTotal !== null) {
            for ($disc = 1; $disc <= $knownDiscTotal; $disc++) {
                if (!isset($observedDiscs[$disc])) {
                    $missingDiscs[] = $disc;
                }
            }
        }

        $expected = null;
        $missingTracks = [];

        if (empty($conflicts) && empty($missingDiscs) && !empty($discTotals)) {
            $perDiscTotals = [];
            $allObservedDiscsHaveOneTotal = true;

            foreach (array_keys($observedDiscs) as $disc) {
                if (!isset($discTotals[$disc]) || count($discTotals[$disc]) !== 1) {
                    $allObservedDiscsHaveOneTotal = false;
                    break;
                }

                $perDiscTotals[$disc] = (int)array_key_first($discTotals[$disc]);
            }

            $globalNumbering = false;
            $globalTotal = null;
            $globalPositions = [];

            if ($allObservedDiscsHaveOneTotal && count($observedDiscs) > 1) {
                $uniqueTotals = [];
                foreach ($perDiscTotals as $total) {
                    if ($total > 0) {
                        $uniqueTotals[$total] = true;
                    }
                }

                if (count($uniqueTotals) === 1) {
                    $candidateGlobalTotal = (int)array_key_first($uniqueTotals);
                    $duplicateAcrossDiscs = false;
                    $positionsWithinTotal = true;

                    foreach ($trackPositions as $disc => $positions) {
                        foreach (array_keys($positions) as $track) {
                            $track = (int)$track;

                            if ($track <= 0 || $track > $candidateGlobalTotal) {
                                $positionsWithinTotal = false;
                                break 2;
                            }

                            if (isset($globalPositions[$track])) {
                                $duplicateAcrossDiscs = true;
                            }

                            $globalPositions[$track] = true;
                        }
                    }

                    if (
                        !$duplicateAcrossDiscs
                        && $positionsWithinTotal
                        && !empty($globalPositions)
                    ) {
                        $globalNumbering = true;
                        $globalTotal = $candidateGlobalTotal;
                    }
                }
            }

            if ($globalNumbering && $globalTotal !== null && $globalTotal > 0) {
                $expected = $globalTotal;

                for ($track = 1; $track <= $globalTotal; $track++) {
                    if (!isset($globalPositions[$track])) {
                        $missingTracks[] = 'T' . $track;
                    }
                }
            } elseif ($allObservedDiscsHaveOneTotal) {
                $sum = 0;

                foreach ($perDiscTotals as $total) {
                    $sum += $total;
                }

                if ($sum > 0) {
                    $expected = $sum;
                }

                $isMultiDisc = count($observedDiscs) > 1
                    || ($knownDiscTotal !== null && $knownDiscTotal > 1);

                foreach ($perDiscTotals as $disc => $total) {
                    if ($total <= 0 || empty($trackPositions[$disc])) {
                        continue;
                    }

                    for ($track = 1; $track <= $total; $track++) {
                        if (!isset($trackPositions[$disc][$track])) {
                            $missingTracks[] = $isMultiDisc
                                ? ('D' . $disc . ':T' . $track)
                                : (string)$track;
                        }
                    }
                }
            }
        }

        return [
            'expected_tracks' => $expected,
            'missing_discs'   => $missingDiscs,
            'missing_tracks'  => $missingTracks,
            'conflicts'       => $conflicts,
        ];
    }


    // ==========================================================
    // GRIZZLY_SCANNER_EVIDENCE_V1
    // ==========================================================

    /** @return array<string,mixed> */
    private function externalCompletenessEvidence(
        array $identity,
        array $audio,
        array $metadataRows,
        bool $stable
    ): array {
        $result = [
            'status' => 'unavailable',
            'source' => null,
            'cache' => null,
            'matched' => false,
            'expected_tracks' => null,
            'release_mbid' => '',
            'artist_mbid' => '',
            'release_group' => '',
            'year' => null,
            'track_match' => null,
            'year_mismatch' => false,
            'candidate_year' => null,
            'candidate_year_source' => null,
        ];

        $artist = trim((string)($identity['artist'] ?? ''));
        $title = trim((string)($identity['title'] ?? ''));

        if ($artist === '' || $title === '') {
            $result['status'] = 'identity-insufficient';
            return $result;
        }

        $candidateTracks = $this->candidateTrackTitles($audio, $metadataRows);
        if (empty($candidateTracks)) {
            $result['status'] = 'candidate-tracklist-unavailable';
            return $result;
        }

        $year = isset($identity['year']) && $identity['year'] !== null
            ? max(0, (int)$identity['year'])
            : 0;
        $yearSource = trim((string)($identity['year_source'] ?? ''));
        $result['candidate_year'] = $year > 0 ? $year : null;
        $result['candidate_year_source'] = $yearSource !== '' ? $yearSource : null;

        $tagReleaseMbid = strtolower(trim((string)($identity['release_mbid'] ?? '')));

        try {
            $evidence = $this->metadataService()->resolveScannerEvidence(
                $artist,
                $title,
                $year,
                $tagReleaseMbid,
                $this->externalEvidenceNetworkAllowed && $stable
            );
        } catch (Throwable $e) {
            $result['status'] = 'lookup-error';
            return $result;
        }

        $result['status'] = (string)($evidence['status'] ?? 'unavailable');
        $result['source'] = (string)($evidence['source'] ?? '');
        $result['cache'] = (string)($evidence['cache'] ?? '');
        $result['release_mbid'] = strtolower(trim((string)($evidence['release_mbid'] ?? '')));
        $result['artist_mbid'] = strtolower(trim((string)($evidence['artist_mbid'] ?? '')));
        $result['release_group'] = strtolower(trim((string)($evidence['release_group'] ?? '')));
        $result['year'] = isset($evidence['year']) && $evidence['year'] !== null
            ? (int)$evidence['year']
            : null;
        $result['expected_tracks'] = isset($evidence['expected_tracks'])
            && $evidence['expected_tracks'] !== null
            ? max(0, (int)$evidence['expected_tracks'])
            : null;

        if ($result['status'] !== 'ok') {
            return $result;
        }

        $yearMismatch = $year > 0
            && $result['year'] !== null
            && $result['year'] > 0
            && $result['year'] !== $year;
        $result['year_mismatch'] = $yearMismatch;

        // ORIGINALYEAR/ORIGINALDATE is explicit original-release evidence and
        // remains a hard veto when it contradicts a query-derived release.
        // A generic YEAR/DATE (or folder year) is weaker: do not accept the
        // external release yet, but allow the full one-to-one tracklist check
        // below to decide whether MusicBrainz can canonicalize the album year.
        if (
            $tagReleaseMbid === ''
            && $yearMismatch
            && $yearSource === 'original-tag'
        ) {
            $result['status'] = 'known-year-mismatch';
            return $result;
        }

        $externalTracks = [];
        foreach ((array)($evidence['tracks'] ?? []) as $track) {
            if (!is_array($track)) {
                continue;
            }

            $trackTitle = trim((string)($track['title'] ?? ''));
            if ($trackTitle !== '') {
                $externalTracks[] = $trackTitle;
            }
        }

        if (empty($externalTracks)) {
            $result['status'] = 'external-tracklist-empty';
            return $result;
        }

        // READY from external evidence requires a FULL one-to-one TrackMatcher
        // comparison. Same track count alone is never enough.
        $match = $this->trackMatcher()->compare(
            $candidateTracks,
            $externalTracks
        );

        $result['track_match'] = $match;

        if (empty($match['equivalent'])) {
            $result['status'] = 'external-tracklist-not-equivalent';
            return $result;
        }

        if (count($candidateTracks) !== count($externalTracks)) {
            $result['status'] = 'external-track-count-mismatch';
            return $result;
        }

        $result['matched'] = true;
        $result['expected_tracks'] = count($externalTracks);
        $result['status'] = 'ok';

        return $result;
    }

    /** @return array<int,string> */
    private function candidateTrackTitles(array $audio, array $metadataRows): array
    {
        $titles = [];

        foreach ($audio as $index => $row) {
            $metadata = isset($metadataRows[$index]) && is_array($metadataRows[$index])
                ? $metadataRows[$index]
                : [];

            $title = trim((string)($metadata['title'] ?? ''));

            if ($title === '') {
                $path = (string)($row['path'] ?? '');
                $title = pathinfo($path, PATHINFO_FILENAME);
                $title = (string)preg_replace(
                    '/^\s*\d+\s*[-._ ]+\s*/u',
                    '',
                    $title
                );
                $title = trim($title);
            }

            if ($title === '') {
                return [];
            }

            $titles[] = $title;
        }

        return $titles;
    }

    private function metadataService()
    {
        if ($this->metadataService !== null) {
            return $this->metadataService;
        }

        if (!class_exists('AlbumMetadataService')) {
            $file = __DIR__ . '/AlbumMetadataService.php';
            if (!is_file($file)) {
                throw new RuntimeException('AlbumMetadataService.php not found.');
            }
            require_once $file;
        }

        $this->metadataService = new AlbumMetadataService();
        return $this->metadataService;
    }

    private function trackMatcher()
    {
        if ($this->trackMatcher !== null) {
            return $this->trackMatcher;
        }

        if (!class_exists('TrackMatcher')) {
            $file = __DIR__ . '/TrackMatcher.php';
            if (!is_file($file)) {
                throw new RuntimeException('TrackMatcher.php not found.');
            }
            require_once $file;
        }

        $this->trackMatcher = new TrackMatcher();
        return $this->trackMatcher;
    }

    private function titleMatcher()
    {
        if ($this->titleMatcher !== null) {
            return $this->titleMatcher;
        }

        if (!class_exists('AlbumTitleMatcher')) {
            $file = __DIR__ . '/AlbumTitleMatcher.php';
            if (!is_file($file)) {
                throw new RuntimeException('AlbumTitleMatcher.php not found.');
            }
            require_once $file;
        }

        $this->titleMatcher = new AlbumTitleMatcher();
        return $this->titleMatcher;
    }

    /**
     * @param array<int,array{dir:string,disc:int}> $parts
     * @param array<int,array{path:string,disc:int}> $audio
     * @param array<string,mixed> $identity
     * @param array<int,array<string,mixed>> $metadataRows
     * @return array<string,mixed>
     */
    private function localCompletenessEvidence(
        string $importRoot,
        string $albumKey,
        array $parts,
        array $audio,
        array $identity,
        array $metadataRows
    ): array {
        $bySourcePath = $this->findUniqueAlbumIdBySourcePaths($importRoot, $audio);
        if ($bySourcePath !== null) {
            $evidence = $this->localAlbumEvidence(
                $bySourcePath,
                'local-source-path',
                $audio,
                $metadataRows
            );
            if ($evidence !== null) {
                return $evidence;
            }
        }

        $releaseMbid = trim((string)($identity['release_mbid'] ?? ''));
        if ($this->isUuid($releaseMbid)) {
            $stmt = $this->db->prepare(
                "SELECT id FROM albums WHERE LOWER(mbid) = LOWER(:mbid) ORDER BY id ASC LIMIT 2"
            );
            $stmt->execute([':mbid' => $releaseMbid]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if (count($ids) === 1) {
                $evidence = $this->localAlbumEvidence(
                    $ids[0],
                    'local-release-mbid',
                    $audio,
                    $metadataRows
                );
                if ($evidence !== null) {
                    return $evidence;
                }
            }
        }

        $artist = trim((string)($identity['artist'] ?? ''));
        $title = trim((string)($identity['title'] ?? ''));
        $year = isset($identity['year']) && $identity['year'] !== null
            ? (int)$identity['year']
            : null;

        if ($artist !== '' && $title !== '') {
            $stmt = $this->db->prepare(
                "SELECT a.id, a.title, a.year, ar.name AS artist_name\n"
                . "FROM albums a\n"
                . "JOIN artists ar ON ar.id = a.artist_id\n"
                . "WHERE ar.name = :artist\n"
                . "ORDER BY a.id ASC"
            );
            $stmt->execute([':artist' => $artist]);

            $matches = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!$this->titleMatcher()->equivalent(
                    $title,
                    (string)$row['title']
                )) {
                    continue;
                }

                $dbYear = !empty($row['year']) ? (int)$row['year'] : null;
                if ($year !== null && $dbYear !== null && $year !== $dbYear) {
                    continue;
                }
                $matches[] = (int)$row['id'];
            }

            if (count($matches) === 1) {
                $evidence = $this->localAlbumEvidence(
                    $matches[0],
                    'local-artist-title-year',
                    $audio,
                    $metadataRows
                );
                if ($evidence !== null) {
                    return $evidence;
                }
            }
        }

        return [
            'album_id'        => null,
            'expected_tracks' => null,
            'source'          => null,
        ];
    }

    /**
     * @param array<int,array{path:string,disc:int}> $audio
     * @param array<int,array<string,mixed>> $metadataRows
     * @return array<string,mixed>|null
     */
    private function localAlbumEvidence(
        int $albumId,
        string $source,
        array $audio,
        array $metadataRows
    ): ?array {
        if ($albumId <= 0) {
            return null;
        }

        $count = $this->trackCountForAlbum($albumId);
        if ($count <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT needs_review FROM albums WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $albumId]);
        $needsReview = $stmt->fetchColumn();

        $tracklistExact = $needsReview !== false
            && (int)$needsReview === 0
            && $this->candidateTracklistMatchesAlbum(
                $audio,
                $metadataRows,
                $albumId
            );

        return [
            'album_id'        => $albumId,
            'expected_tracks' => $count,
            'source'          => $source,
            'tracklist_exact' => $tracklistExact,
        ];
    }

    /**
     * Confronto esatto a multinsieme tra la tracklist candidata e quella
     * locale. Usato solo come prova di completezza per album manuali/curati
     * (needs_review=0).
     *
     * @param array<int,array{path:string,disc:int}> $audio
     * @param array<int,array<string,mixed>> $metadataRows
     */
    private function candidateTracklistMatchesAlbum(
        array $audio,
        array $metadataRows,
        int $albumId
    ): bool {
        if ($albumId <= 0 || empty($audio)) {
            return false;
        }

        $candidate = $this->candidateTrackTitles($audio, $metadataRows);
        if (empty($candidate)) {
            return false;
        }

        $stmt = $this->db->prepare(
            "SELECT title FROM tracks WHERE album_id = :album_id ORDER BY position ASC, id ASC"
        );
        $stmt->execute([':album_id' => $albumId]);
        $localTitles = array_values(array_map(
            'strval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        ));

        if (empty($localTitles)) {
            return false;
        }

        $match = $this->trackMatcher()->compare(
            $candidate,
            $localTitles
        );

        return !empty($match['equivalent']);
    }

    /**
     * @param array<int,array{path:string,disc:int}> $audio
     */
    private function findUniqueAlbumIdBySourcePaths(string $importRoot, array $audio): ?int
    {
        if (empty($audio)) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT DISTINCT album_id FROM audio_files\n"
            . "WHERE album_id IS NOT NULL\n"
            . "  AND source_path IS NOT NULL\n"
            . "  AND (source_path = :source_path OR source_path = :legacy_rel)\n"
            . "LIMIT 2"
        );

        $albumIds = [];
        foreach ($audio as $row) {
            $path = (string)$row['path'];
            $stmt->execute([
                ':source_path' => $this->sourcePathForDb($path),
                ':legacy_rel'  => $this->relPath($importRoot, $path),
            ]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $albumId) {
                $albumId = (int)$albumId;
                if ($albumId > 0) {
                    $albumIds[$albumId] = true;
                }
            }
            if (count($albumIds) > 1) {
                return null;
            }
        }

        return count($albumIds) === 1 ? (int)array_key_first($albumIds) : null;
    }

    private function trackCountForAlbum(int $albumId): int
    {
        if ($albumId <= 0) {
            return 0;
        }
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM tracks WHERE album_id = :album_id");
        $stmt->execute([':album_id' => $albumId]);
        return (int)$stmt->fetchColumn();
    }

    private function buildPersistentSignature(
        string $importRoot,
        string $albumKey,
        array $audio,
        array $metadataRows,
        array $tempFiles
    ): array {
        $root = rtrim(str_replace('\\', '/', $importRoot), '/');
        $relativeCandidate = $this->relPath($importRoot, $albumKey);
        $files = [];

        foreach ($audio as $index => $row) {
            $path = (string)$row['path'];
            $metadata = isset($metadataRows[$index]) && is_array($metadataRows[$index])
                ? $metadataRows[$index]
                : [];

            $size = @filesize($path);
            $mtime = @filemtime($path);
            $track = !empty($metadata['track']) ? (int)$metadata['track'] : null;

            $files[] = [
                'kind' => 'audio',
                'path' => $this->relPath($importRoot, $path),
                'size' => $size === false ? null : (int)$size,
                'mtime' => $mtime === false ? null : (int)$mtime,
                'disc' => isset($row['disc']) ? max(1, (int)$row['disc']) : 1,
                'track' => $track !== null && $track > 0 ? $track : null,
                'readable' => is_readable($path),
            ];
        }

        foreach ($tempFiles as $path) {
            $path = (string)$path;
            $size = @filesize($path);
            $mtime = @filemtime($path);

            $files[] = [
                'kind' => 'temporary',
                'path' => $this->relPath($importRoot, $path),
                'size' => $size === false ? null : (int)$size,
                'mtime' => $mtime === false ? null : (int)$mtime,
                'disc' => null,
                'track' => null,
                'readable' => is_readable($path),
            ];
        }

        usort($files, static function (array $a, array $b): int {
            $kindCmp = strcmp((string)$a['kind'], (string)$b['kind']);
            if ($kindCmp !== 0) {
                return $kindCmp;
            }

            $pathCmp = strcmp((string)$a['path'], (string)$b['path']);
            if ($pathCmp !== 0) {
                return $pathCmp;
            }

            $discCmp = ((int)($a['disc'] ?? 0)) <=> ((int)($b['disc'] ?? 0));
            if ($discCmp !== 0) {
                return $discCmp;
            }

            return ((int)($a['track'] ?? 0)) <=> ((int)($b['track'] ?? 0));
        });

        $payload = [
            'version' => 1,
            'candidate' => $relativeCandidate,
            'audio_count' => count($audio),
            'temporary_count' => count($tempFiles),
            'files' => $files,
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            $json = serialize($payload);
        }

        return [
            'key' => hash('sha256', $root . "\\n" . $relativeCandidate),
            'root' => $root,
            'path' => $relativeCandidate,
            'signature' => hash('sha256', $json),
        ];
    }

    private function sourcePathForDb(string $runtimePath): string
    {
        $real = realpath($runtimePath);
        $path = $real !== false ? $real : $runtimePath;
        $path = str_replace('\\', '/', $path);

        $prefix = trim((string)getenv('MEDIA_SCAN_HOST_PREFIX'));
        if ($prefix === '') {
            return $path;
        }

        $prefix = rtrim(str_replace('\\', '/', $prefix), '/');
        if ($path === $prefix) {
            return '/';
        }
        if (strpos($path, $prefix . '/') === 0) {
            $hostPath = substr($path, strlen($prefix));
            return $hostPath === '' ? '/' : $hostPath;
        }
        return $path;
    }

    private function relPath(string $importDir, string $abs): string
    {
        $importDir = rtrim(str_replace('\\', '/', $importDir), '/') . '/';
        $abs = str_replace('\\', '/', $abs);
        if (strpos($abs, $importDir) === 0) {
            return substr($abs, strlen($importDir));
        }
        return basename($abs);
    }

    private function normalizeIdentity(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
        return (string)preg_replace('/[^\pL\pN]+/u', '', $value);
    }

    /** @return array{artist:string,title:string,year:?int} */
    private function parseFolderName(string $name): array
    {
        $out = ['artist' => '', 'title' => '', 'year' => null];
        $s = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', trim($name));

        if (preg_match('/[\(\[\{]?\b(19|20)\d{2}\b[\)\]\}]?/', $s, $m)) {
            $out['year'] = (int)preg_replace('/\D/', '', $m[0]);
            $s = trim(str_replace($m[0], ' ', $s));
        }

        $s = trim((string)preg_replace('/\s+/u', ' ', $s));
        $parts = preg_split('/\s+-\s+/u', $s, 2);
        if (is_array($parts) && count($parts) === 2
            && trim((string)$parts[0]) !== '' && trim((string)$parts[1]) !== '') {
            $out['artist'] = $this->cleanFolderPiece((string)$parts[0]);
            $out['title'] = $this->cleanFolderPiece((string)$parts[1]);
        } else {
            $out['title'] = $this->cleanFolderPiece($s);
        }

        return $out;
    }

    private function cleanFolderPiece(string $value): string
    {
        $value = (string)preg_replace('/[\[\{\(][^\]\}\)]*[\]\}\)]/u', ' ', $value);
        $value = (string)preg_replace('/^[.\-_\s]+/u', '', $value);
        $value = (string)preg_replace('/[.\-_\s]+$/u', '', $value);
        $value = (string)preg_replace('/\s+/u', ' ', $value);
        return trim($value);
    }

    private function firstComment(array $comments, array $keys): string
    {
        foreach ($keys as $key) {
            if (!isset($comments[$key])) {
                continue;
            }
            $value = $comments[$key];
            if (is_array($value)) {
                $value = reset($value);
            }
            if (is_array($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function firstCommentOrText(array $comments, array $keys, array $textKeys): string
    {
        $value = $this->firstComment($comments, $keys);
        if ($value !== '') {
            return $value;
        }
        return $this->firstTextComment($comments, $textKeys);
    }

    private function firstTextComment(array $comments, array $wantedKeys): string
    {
        if (empty($wantedKeys) || !isset($comments['text']) || !is_array($comments['text'])) {
            return '';
        }

        $wanted = [];
        foreach ($wantedKeys as $key) {
            $wanted[strtoupper(trim((string)$key))] = true;
        }

        foreach ($comments['text'] as $desc => $value) {
            if (!is_string($desc) || !isset($wanted[strtoupper(trim($desc))])) {
                continue;
            }
            if (is_array($value)) {
                $value = reset($value);
            }
            if (is_array($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function firstYearFromComments(array $comments, array $keys, array $textKeys): ?int
    {
        foreach ($keys as $key) {
            if (!isset($comments[$key])) {
                continue;
            }
            $values = is_array($comments[$key]) ? $comments[$key] : [$comments[$key]];
            foreach ($values as $value) {
                if (is_array($value)) {
                    continue;
                }
                $year = $this->parseYear(trim((string)$value));
                if ($year !== null) {
                    return $year;
                }
            }
        }

        $text = $this->firstTextComment($comments, $textKeys);
        return $text !== '' ? $this->parseYear($text) : null;
    }

    private function parseYear(string $raw): ?int
    {
        if ($raw !== '' && preg_match('/(19|20)\d{2}/', $raw, $m)) {
            return (int)$m[0];
        }
        return null;
    }

    /** @return array{number:?int,total:?int} */
    private function numberAndTotal(string $raw): array
    {
        $raw = trim($raw);
        $number = null;
        $total = null;

        if ($raw !== '' && preg_match('/^\s*(\d{1,4})/', $raw, $m)) {
            $number = (int)$m[1];
        }
        if ($raw !== '' && preg_match('/(?:\/|\bof\b)\s*(\d{1,4})\s*$/i', $raw, $m)) {
            $total = (int)$m[1];
        }

        return [
            'number' => $number !== null && $number > 0 ? $number : null,
            'total'  => $total !== null && $total > 0 ? $total : null,
        ];
    }

    private function firstPositiveInt(array $comments, array $keys, array $textKeys): ?int
    {
        $raw = $this->firstComment($comments, $keys);
        if ($raw === '') {
            $raw = $this->firstTextComment($comments, $textKeys);
        }
        if ($raw !== '' && preg_match('/\d{1,4}/', $raw, $m)) {
            $value = (int)$m[0];
            return $value > 0 ? $value : null;
        }
        return null;
    }

    private function isUuid(string $value): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            trim($value)
        );
    }

    private function loadGetID3()
    {
        if (class_exists('getID3')) {
            return new getID3();
        }

        $candidates = [
            $this->basePath . '/app/lib/getid3/getid3/getid3.php',
            $this->basePath . '/app/lib/getID3/getid3/getid3.php',
            $this->basePath . '/lib/getid3/getid3/getid3.php',
            $this->basePath . '/vendor/james-heinrich/getid3/getid3/getid3.php',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                require_once $path;
                if (class_exists('getID3')) {
                    return new getID3();
                }
            }
        }

        return null;
    }
}

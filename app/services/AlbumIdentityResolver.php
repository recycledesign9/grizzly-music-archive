<?php

/**
 * Grizzly Album Identity Resolver - shadow/read-only component.
 *
 * Decision values:
 *   EXACT_EXISTING
 *   NEW_RELEASE
 *   AMBIGUOUS
 *   CONFLICT
 *
 * This class performs SELECT-only database access.
 */
class AlbumIdentityResolver
{
    public const EXACT_EXISTING = 'EXACT_EXISTING';
    public const NEW_RELEASE = 'NEW_RELEASE';
    public const AMBIGUOUS = 'AMBIGUOUS';
    public const CONFLICT = 'CONFLICT';

    /** @var PDO */
    private $db;

    /** @var string */
    private $projectRoot;

    /** @var getID3|null */
    private $getId3 = null;

    /** @var TrackMatcher */
    private $trackMatcher;

    /** @var AlbumTitleMatcher */
    private $titleMatcher;

    public function __construct(PDO $db, string $projectRoot)
    {
        $this->db = $db;
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->bootstrapTrackMatcher();
        $this->bootstrapTitleMatcher();
        $this->bootstrapGetId3();
    }

    /**
     * Resolve one candidate produced by AlbumReadinessService.
     *
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    public function resolveCandidate(array $candidate, string $importRoot): array
    {
        $artist = trim((string)($candidate['artist'] ?? ''));
        $title = trim((string)($candidate['title'] ?? ''));
        $year = isset($candidate['year']) && $candidate['year'] !== null
            ? (int)$candidate['year']
            : null;
        $releaseMbid = strtolower(trim((string)($candidate['release_mbid'] ?? '')));
        $releaseGroup = strtolower(trim((string)($candidate['release_group'] ?? '')));
        $evidenceStatus = strtoupper(trim((string)($candidate['evidence_status'] ?? $candidate['status'] ?? '')));

        $audioFiles = $this->collectCandidateAudioFiles($candidate);
        $candidateTracks = $this->candidateTrackTitles($audioFiles);

        $base = [
            'candidate_path' => (string)($candidate['path'] ?? ''),
            'artist' => $artist,
            'title' => $title,
            'year' => $year,
            'release_mbid' => $releaseMbid,
            'release_group' => $releaseGroup,
            'readiness_status' => strtoupper((string)($candidate['status'] ?? '')),
            'evidence_status' => $evidenceStatus,
            'candidate_track_count' => count($candidateTracks),
            'candidate_tracks' => $candidateTracks,
        ];

        /*
         * 1. SOURCE PATH
         * Strongest local evidence. If different source files already point to
         * different albums we refuse to guess.
         */
        $sourceAlbumIds = $this->albumIdsBySourcePaths($importRoot, $audioFiles);

        if (count($sourceAlbumIds) > 1) {
            return $base + [
                'decision' => self::CONFLICT,
                'confidence' => 'strong',
                'album_id' => null,
                'match_source' => 'source-path',
                'candidate_album_ids' => $sourceAlbumIds,
                'reasons' => ['source-paths-point-to-multiple-albums'],
            ];
        }

        if (count($sourceAlbumIds) === 1) {
            $album = $this->loadAlbum($sourceAlbumIds[0]);
            if ($album === null) {
                return $base + [
                    'decision' => self::CONFLICT,
                    'confidence' => 'strong',
                    'album_id' => null,
                    'match_source' => 'source-path',
                    'candidate_album_ids' => $sourceAlbumIds,
                    'reasons' => ['source-path-album-not-found'],
                ];
            }

            $metadataWarnings = $this->strongIdentityVetoes(
                $artist,
                $title,
                $year,
                $releaseMbid,
                $album
            );

            $reasons = ['exact-source-path'];
            if (!empty($metadataWarnings)) {
                $reasons[] = 'source-path-metadata-warning';
            }

            return $base + [
                'decision' => self::EXACT_EXISTING,
                'confidence' => 'strong',
                'album_id' => (int)$album['id'],
                'match_source' => 'source-path',
                'candidate_album_ids' => [(int)$album['id']],
                'reasons' => $reasons,
                'metadata_warnings' => $metadataWarnings,
                'matched_album' => $this->publicAlbum($album),
            ];
        }

        /*
         * 2. EXACT RELEASE MBID
         * Release MBID identifies a release; release-group MBID does not.
         */
        if ($this->isUuid($releaseMbid)) {
            $albums = $this->albumsByReleaseMbid($releaseMbid);

            if (count($albums) > 1) {
                return $base + [
                    'decision' => self::AMBIGUOUS,
                    'confidence' => 'strong',
                    'album_id' => null,
                    'match_source' => 'release-mbid',
                    'candidate_album_ids' => $this->albumIds($albums),
                    'reasons' => ['duplicate-local-release-mbid'],
                ];
            }

            if (count($albums) === 1) {
                $album = $albums[0];

                $metadataWarnings = $this->strongIdentityVetoes(
                    $artist,
                    $title,
                    $year,
                    $releaseMbid,
                    $album
                );

                // Exact release MBID is stronger than a year discrepancy.
                // A wrong/stale year must be surfaced as metadata warning, not
                // turn the same MusicBrainz release into a different album.
                $hardVetoes = array_values(array_filter(
                    $metadataWarnings,
                    static function (string $reason): bool {
                        return $reason !== 'known-year-mismatch';
                    }
                ));

                if ($hardVetoes === ['title-mismatch']
                    && count($candidateTracks) >= 2
                    && $this->tracklistsStrictlyEqual($candidateTracks, (int)$album['id'])) {
                    // Exact MBID + an independent FULL exact tracklist overrides
                    // a locally curated display title; retain the discrepancy.
                    return $base + [
                        'decision' => self::EXACT_EXISTING,
                        'confidence' => 'strong',
                        'album_id' => (int)$album['id'],
                        'match_source' => 'release-mbid',
                        'candidate_album_ids' => [(int)$album['id']],
                        'reasons' => ['exact-release-mbid', 'full-tracklist-confirmed'],
                        'metadata_warnings' => $metadataWarnings,
                        'matched_album' => $this->publicAlbum($album),
                    ];
                }

                if (!empty($hardVetoes)) {
                    return $base + [
                        'decision' => self::CONFLICT,
                        'confidence' => 'strong',
                        'album_id' => (int)$album['id'],
                        'match_source' => 'release-mbid',
                        'candidate_album_ids' => [(int)$album['id']],
                        'reasons' => $hardVetoes,
                        'metadata_warnings' => $metadataWarnings,
                        'matched_album' => $this->publicAlbum($album),
                    ];
                }

                return $base + [
                    'decision' => self::EXACT_EXISTING,
                    'confidence' => 'strong',
                    'album_id' => (int)$album['id'],
                    'match_source' => 'release-mbid',
                    'candidate_album_ids' => [(int)$album['id']],
                    'reasons' => ['exact-release-mbid'],
                    'metadata_warnings' => $metadataWarnings,
                    'matched_album' => $this->publicAlbum($album),
                ];
            }
        }

        /*
         * Weak identity stages need an exact candidate-vs-local tracklist match.
         *
         * If readiness already proved the candidate complete, the candidate
         * tracklist may be used normally.
         *
         * If readiness is still PENDING/INCOMPLETE, weak matching is allowed
         * only against a manually curated local album (needs_review=0) and only
         * when the candidate track titles exactly equal the complete local
         * tracklist currently stored for that album. This supports the
         * "manual album + audio later" workflow without allowing a partial
         * scanner-created album to certify another partial candidate.
         */
        $hasCandidateTracklist = !empty($candidateTracks);

        /*
         * 3. RELEASE GROUP + FULL TRACKLIST
         * release-group alone is never sufficient. Once the complete
         * tracklist confirms the same logical album, a year discrepancy is a
         * metadata warning, not an identity veto.
         */
        if ($this->isUuid($releaseGroup) && $hasCandidateTracklist) {
            $groupAlbums = $this->albumsByReleaseGroup($releaseGroup);
            $groupMetadataMatches = [];
            $groupTracklistMatches = [];

            foreach ($groupAlbums as $album) {
                if (!$this->weakAlbumMetadataCompatible(
                    $artist,
                    $title,
                    $year,
                    $releaseMbid,
                    $releaseGroup,
                    $album,
                    false
                )) {
                    continue;
                }

                $groupMetadataMatches[] = $album;

                if ($this->tracklistsEqual($candidateTracks, (int)$album['id'])) {
                    $groupTracklistMatches[] = $album;
                }
            }

            if (count($groupTracklistMatches) > 1) {
                return $base + [
                    'decision' => self::AMBIGUOUS,
                    'confidence' => 'candidate',
                    'album_id' => null,
                    'match_source' => 'release-group+year+tracklist',
                    'candidate_album_ids' => $this->albumIds($groupTracklistMatches),
                    'reasons' => ['multiple-release-group-tracklist-matches'],
                ];
            }

            if (count($groupTracklistMatches) === 1) {
                $album = $groupTracklistMatches[0];

                if ($this->tracklistEvidenceAllowed($evidenceStatus, $album)) {
                    return $base + [
                        'decision' => self::EXACT_EXISTING,
                        'confidence' => 'candidate',
                        'album_id' => (int)$album['id'],
                        // Keep the historical match_source value for report/API
                        // compatibility even though year is no longer a veto.
                        'match_source' => 'release-group+year+tracklist',
                        'candidate_album_ids' => [(int)$album['id']],
                        'reasons' => ['release-group-tracklist-match'],
                        'metadata_warnings' => $this->yearMetadataWarnings($year, $album),
                        'matched_album' => $this->publicAlbum($album),
                    ];
                }

                return $base + [
                    'decision' => self::AMBIGUOUS,
                    'confidence' => 'candidate',
                    'album_id' => null,
                    'match_source' => 'release-group+year+tracklist',
                    'candidate_album_ids' => [(int)$album['id']],
                    'reasons' => [
                        'plausible-local-identity-match-not-proven',
                        'candidate-readiness-not-ready-and-local-needs-review',
                    ],
                    'plausible_album' => $this->publicAlbum($album),
                ];
            }

            if (!empty($groupMetadataMatches)) {
                return $base + [
                    'decision' => self::AMBIGUOUS,
                    'confidence' => 'candidate',
                    'album_id' => null,
                    'match_source' => 'release-group+year',
                    'candidate_album_ids' => $this->albumIds($groupMetadataMatches),
                    'reasons' => [
                        'plausible-local-identity-match-not-proven',
                        'release-group-metadata-match-tracklist-not-confirmed',
                    ],
                ];
            }
        }

        /*
         * 4. ARTIST + TITLE + FULL TRACKLIST
         * The complete tracklist is the structural proof. Year differences do
         * not create a second logical album. A known different release-group,
         * however, remains a strong proof that MusicBrainz considers them
         * different logical albums.
         */
        if ($artist !== '' && $title !== '') {
            // Discover by artist FIRST. A manually curated title can differ from
            // the file tags; do not let title equivalence hide an exact tracklist.
            // This is only candidate discovery, never an identity verdict.
            $artistAlbums = $this->albumsByArtistAndTitle($artist, $title, false);
            $fallbackAlbums = [];
            foreach ($artistAlbums as $album) {
                if ($this->titleMatcher->equivalent($title, (string)$album['title'])) {
                    $fallbackAlbums[] = $album;
                }
            }

            $crossTitleDecision = $this->resolveCrossTitleTracklistMatches(
                $artistAlbums,
                $title,
                $year,
                $releaseGroup,
                $candidateTracks,
                $evidenceStatus,
                $base
            );
            if ($crossTitleDecision !== null) {
                return $crossTitleDecision;
            }

            $fallbackMetadataMatches = [];
            $fallbackTracklistMatches = [];

            foreach ($fallbackAlbums as $album) {
                if (!$this->weakAlbumMetadataCompatible(
                    $artist,
                    $title,
                    $year,
                    $releaseMbid,
                    $releaseGroup,
                    $album,
                    true
                )) {
                    continue;
                }

                $fallbackMetadataMatches[] = $album;

                if (
                    $hasCandidateTracklist
                    && $this->tracklistsEqual($candidateTracks, (int)$album['id'])
                ) {
                    $fallbackTracklistMatches[] = $album;
                }
            }

            if (count($fallbackTracklistMatches) > 1) {
                return $base + [
                    'decision' => self::AMBIGUOUS,
                    'confidence' => 'candidate',
                    'album_id' => null,
                    'match_source' => 'artist-title-year+tracklist',
                    'candidate_album_ids' => $this->albumIds($fallbackTracklistMatches),
                    'reasons' => ['multiple-artist-title-tracklist-matches'],
                ];
            }

            if (count($fallbackTracklistMatches) === 1) {
                $album = $fallbackTracklistMatches[0];

                if ($this->tracklistEvidenceAllowed($evidenceStatus, $album)) {
                    return $base + [
                        'decision' => self::EXACT_EXISTING,
                        'confidence' => 'candidate',
                        // Keep the historical match_source value for report/API
                        // compatibility even though year is no longer a veto.
                        'album_id' => (int)$album['id'],
                        'match_source' => 'artist-title-year+tracklist',
                        'candidate_album_ids' => [(int)$album['id']],
                        'reasons' => ['artist-title-tracklist-match'],
                        'metadata_warnings' => $this->yearMetadataWarnings($year, $album),
                        'matched_album' => $this->publicAlbum($album),
                    ];
                }

                return $base + [
                    'decision' => self::AMBIGUOUS,
                    'confidence' => 'candidate',
                    'album_id' => null,
                    'match_source' => 'artist-title-year+tracklist',
                    'candidate_album_ids' => [(int)$album['id']],
                    'reasons' => [
                        'plausible-local-identity-match-not-proven',
                        'candidate-readiness-not-ready-and-local-needs-review',
                    ],
                    'plausible_album' => $this->publicAlbum($album),
                ];
            }

            if (!empty($fallbackMetadataMatches)) {
                return $base + [
                    'decision' => self::AMBIGUOUS,
                    'confidence' => 'candidate',
                    'album_id' => null,
                    'match_source' => 'artist-title-year',
                    'candidate_album_ids' => $this->albumIds($fallbackMetadataMatches),
                    'reasons' => [
                        'plausible-local-identity-match-not-proven',
                        $hasCandidateTracklist
                            ? 'metadata-match-tracklist-not-confirmed'
                            : 'candidate-tracklist-unavailable',
                    ],
                ];
            }
        }

        return $base + [
            'decision' => self::NEW_RELEASE,
            'confidence' => 'none',
            'album_id' => null,
            'match_source' => null,
            'candidate_album_ids' => [],
            'reasons' => ['no-safe-local-identity-match'],
        ];
    }

    /**
     * @param array<string,mixed> $candidate
     * @return array<int,string>
     */
    private function collectCandidateAudioFiles(array $candidate): array
    {
        $parts = isset($candidate['parts']) && is_array($candidate['parts'])
            ? $candidate['parts']
            : [];

        $files = [];

        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }

            $dir = (string)($part['dir'] ?? '');
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }

            foreach ($this->listAudioFilesForIdentity($dir) as $path) {
                $real = realpath($path);
                $key = $real !== false ? $real : $path;
                $files[$key] = $path;
            }
        }

        $files = array_values($files);
        usort($files, 'strnatcasecmp');

        return $files;
    }

    /**
     * @return array<int,string>
     */
    private function listAudioFilesForIdentity(string $dir): array
    {
        $out = [];
        $stack = [$dir];

        while (!empty($stack)) {
            $current = array_pop($stack);
            $entries = @scandir($current);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $path = rtrim($current, '/\\') . DIRECTORY_SEPARATOR . $name;

                if (is_dir($path) && !is_link($path)) {
                    $stack[] = $path;
                    continue;
                }

                if (!is_file($path)) {
                    continue;
                }

                $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
                if ($ext !== 'mp3' && $ext !== 'flac') {
                    continue;
                }

                $out[] = $path;
            }
        }

        usort($out, 'strnatcasecmp');
        return $out;
    }

    /**
     * @param array<int,string> $audioFiles
     * @return array<int,string>
     */
    private function candidateTrackTitles(array $audioFiles): array
    {
        $tracks = [];

        foreach ($audioFiles as $index => $path) {
            $title = '';
            $trackNo = null;
            $discNo = 1;

            if ($this->getId3 !== null && is_readable($path)) {
                try {
                    $info = $this->getId3->analyze($path);

                    if (class_exists('getid3_lib')) {
                        getid3_lib::CopyTagsToComments($info);
                    }

                    $comments = isset($info['comments']) && is_array($info['comments'])
                        ? $info['comments']
                        : [];

                    $title = $this->firstComment($comments, ['title']);
                    $trackRaw = $this->firstComment(
                        $comments,
                        ['track_number', 'tracknumber', 'track']
                    );
                    $discRaw = $this->firstComment(
                        $comments,
                        ['part_of_a_set', 'discnumber', 'disc_number', 'disc']
                    );

                    $trackNo = $this->leadingPositiveInt($trackRaw);
                    $parsedDisc = $this->leadingPositiveInt($discRaw);
                    if ($parsedDisc !== null) {
                        $discNo = $parsedDisc;
                    }
                } catch (Throwable $e) {
                    // Shadow resolver: fall back to the filename.
                }
            }

            if ($title === '') {
                $title = pathinfo($path, PATHINFO_FILENAME);
                $title = preg_replace('/^\s*\d+\s*[-._ ]+\s*/u', '', $title);
                $title = trim((string)$title);
            }

            $tracks[] = [
                'title' => $title,
                'normalized' => $this->normalizeIdentity($title),
                'disc' => $discNo,
                'track' => $trackNo,
                'index' => $index,
            ];
        }

        usort($tracks, static function (array $a, array $b): int {
            $discCmp = ((int)$a['disc']) <=> ((int)$b['disc']);
            if ($discCmp !== 0) {
                return $discCmp;
            }

            $aTrack = $a['track'] === null ? PHP_INT_MAX : (int)$a['track'];
            $bTrack = $b['track'] === null ? PHP_INT_MAX : (int)$b['track'];
            $trackCmp = $aTrack <=> $bTrack;
            if ($trackCmp !== 0) {
                return $trackCmp;
            }

            return ((int)$a['index']) <=> ((int)$b['index']);
        });

        return array_values(array_map(static function (array $row): string {
            return (string)$row['title'];
        }, $tracks));
    }

    /**
     * @param array<int,string> $audioFiles
     * @return array<int,int>
     */
    private function albumIdsBySourcePaths(string $importRoot, array $audioFiles): array
    {
        if (empty($audioFiles)) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT DISTINCT album_id
             FROM audio_files
             WHERE album_id IS NOT NULL
               AND source_path = :source_path
             ORDER BY album_id ASC
             LIMIT 3"
        );

        $ids = [];

        foreach ($audioFiles as $path) {
            foreach ($this->sourcePathVariants($importRoot, $path) as $variant) {
                $stmt->execute([':source_path' => $variant]);

                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $albumId) {
                    $albumId = (int)$albumId;
                    if ($albumId > 0) {
                        $ids[$albumId] = true;
                    }
                }

                if (count($ids) > 1) {
                    break 2;
                }
            }
        }

        $out = array_map('intval', array_keys($ids));
        sort($out, SORT_NUMERIC);
        return $out;
    }

    /**
     * @return array<int,string>
     */
    private function sourcePathVariants(string $importRoot, string $path): array
    {
        $variants = [];

        $sourcePath = $this->sourcePathForDb($path);
        if ($sourcePath !== '') {
            $variants[$sourcePath] = true;
        }

        $legacyRel = $this->relPath($importRoot, $path);
        if ($legacyRel !== '') {
            $variants[$legacyRel] = true;
        }

        return array_values(array_keys($variants));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadAlbum(int $albumId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT
                a.id,
                a.artist_id,
                a.title,
                a.year,
                a.mbid,
                a.mb_release_group,
                a.needs_review,
                ar.name AS artist_name,
                ar.mb_artist_id
             FROM albums a
             JOIN artists ar ON ar.id = a.artist_id
             WHERE a.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $albumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function albumsByReleaseMbid(string $mbid): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                a.id,
                a.artist_id,
                a.title,
                a.year,
                a.mbid,
                a.mb_release_group,
                a.needs_review,
                ar.name AS artist_name,
                ar.mb_artist_id
             FROM albums a
             JOIN artists ar ON ar.id = a.artist_id
             WHERE LOWER(a.mbid) = LOWER(:mbid)
             ORDER BY a.id ASC"
        );
        $stmt->execute([':mbid' => $mbid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function albumsByReleaseGroup(string $releaseGroup): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                a.id,
                a.artist_id,
                a.title,
                a.year,
                a.mbid,
                a.mb_release_group,
                a.needs_review,
                ar.name AS artist_name,
                ar.mb_artist_id
             FROM albums a
             JOIN artists ar ON ar.id = a.artist_id
             WHERE LOWER(a.mb_release_group) = LOWER(:release_group)
             ORDER BY a.id ASC"
        );
        $stmt->execute([':release_group' => $releaseGroup]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function albumsByArtistAndTitle(string $artist, string $title, bool $requireTitle = true): array
    {
        $wantedArtist = $this->normalizeIdentity($artist);

        $stmt = $this->db->query(
            "SELECT
                a.id,
                a.artist_id,
                a.title,
                a.year,
                a.mbid,
                a.mb_release_group,
                a.needs_review,
                ar.name AS artist_name,
                ar.mb_artist_id
             FROM albums a
             JOIN artists ar ON ar.id = a.artist_id
             ORDER BY a.id ASC"
        );

        $matches = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->normalizeIdentity((string)$row['artist_name']) !== $wantedArtist) {
                continue;
            }

            if ($requireTitle && !$this->titleMatcher->equivalent(
                $title,
                (string)$row['title']
            )) {
                continue;
            }

            $matches[] = $row;
        }

        return $matches;
    }

    /**
     * Cross-title safeguard. Compare complete tracklists for EVERY same-artist
     * local album before returning NEW_RELEASE or allowing a misleading exact-
     * title candidate to hide an existing curated album.
     *
     * No artist/title substring match is ever sufficient for reconciliation.
     * Same-year, exact whole-tracklist and a cataloguing-only title difference
     * may auto-reconcile; any other plausible collision must go to REVIEW.
     * Explicit edition names are kept distinct unless the existing stronger
     * MBID/release-group stages have already independently proven identity.
     *
     * @param array<int,array<string,mixed>> $artistAlbums
     * @param array<int,string> $candidateTracks
     * @param array<string,mixed> $base
     * @return array<string,mixed>|null
     */
    private function resolveCrossTitleTracklistMatches(
        array $artistAlbums,
        string $title,
        ?int $year,
        string $releaseGroup,
        array $candidateTracks,
        string $evidenceStatus,
        array $base
    ): ?array {
        $matches = [];
        $sameTitleTrackMatches = [];
        $catalogAliasCandidates = [];
        foreach ($artistAlbums as $album) {
            $localTitle = (string)$album['title'];
            $sameTitle = $this->titleMatcher->equivalent($title, $localTitle);
            $relationship = $sameTitle ? 'same-title'
                : $this->titleMatcher->relationship(
                    $title,
                    $localTitle,
                    (string)$base['artist'],
                    $year
                );
            // Explicit editions are never auto-reconciled here, but a
            // complete-tracklist collision must still be surfaced as REVIEW
            // rather than falling through to NEW_RELEASE/CREATE.

            $dbYear = !empty($album['year']) ? (int)$album['year'] : null;
            if ($relationship === 'catalog-alias'
                && ($year === null || $dbYear === null || $year === $dbYear)) {
                $catalogAliasCandidates[] = $album;
            }
            // A short/unknown list can never prove equality; still preserve
            // catalogue-title collisions as REVIEW instead of unsafe CREATE.
            if (count($candidateTracks) < 2) {
                continue;
            }
            if ($relationship === 'unrelated'
                && $year !== null && $dbYear !== null && $year !== $dbYear) {
                continue;
            }

            $albumId = (int)$album['id'];
            $localTracks = $this->localTrackTitles($albumId);
            $comparison = $this->trackMatcher->compare($candidateTracks, $localTracks);
            if (empty($comparison['equivalent'])) {
                continue;
            }

            if ($sameTitle) {
                if ($this->weakAlbumMetadataCompatible(
                    (string)$base['artist'], $title, $year, '', $releaseGroup, $album, true
                )) {
                    $sameTitleTrackMatches[] = $album;
                }
                continue; // The original fallback owns this candidate.
            }

            $dbGroup = strtolower(trim((string)($album['mb_release_group'] ?? '')));
            $groupConflict = $this->isUuid($releaseGroup)
                && $this->isUuid($dbGroup)
                && $releaseGroup !== $dbGroup;
            $exactTracklist = (int)($comparison['exact_count'] ?? -1)
                === count($candidateTracks)
                && (int)($comparison['total'] ?? -1) === count($candidateTracks);

            $matches[] = [
                'album' => $album,
                'relationship' => $relationship,
                'exact_tracks' => $exactTracklist,
                'group_conflict' => $groupConflict,
                'year_match' => $year !== null && $dbYear !== null && $year === $dbYear,
            ];
        }

        if (empty($matches)) {
            if (!empty($catalogAliasCandidates)) {
                return $base + [
                    'decision' => self::AMBIGUOUS,
                    'confidence' => 'candidate',
                    'album_id' => null,
                    'match_source' => 'artist+catalog-title-collision',
                    'candidate_album_ids' => $this->albumIds($catalogAliasCandidates),
                    'reasons' => ['catalog-title-alias-tracklist-not-proven'],
                ];
            }
            return null;
        }

        $matchedAlbums = array_merge(array_column($matches, 'album'), $sameTitleTrackMatches);
        // If a title-exact album ALSO matches the full tracklist, no cross-
        // title match is safe to choose automatically.
        if (count($matches) === 1 && empty($sameTitleTrackMatches)) {
            $match = $matches[0];
            $album = $match['album'];
            if ($match['relationship'] === 'catalog-alias'
                && $match['year_match']
                && $match['exact_tracks']
                && !$match['group_conflict']
                && $this->tracklistEvidenceAllowed($evidenceStatus, $album)) {
                return $base + [
                    'decision' => self::EXACT_EXISTING,
                    'confidence' => 'candidate',
                    'album_id' => (int)$album['id'],
                    'match_source' => 'artist+year+full-tracklist',
                    'candidate_album_ids' => [(int)$album['id']],
                    'reasons' => ['catalog-title-alias-full-tracklist-match'],
                    'matched_album' => $this->publicAlbum($album),
                ];
            }
        }

        $reviewReasons = ['cross-title-complete-tracklist-collision-needs-review'];
        foreach ($matches as $match) {
            if ($match['relationship'] === 'distinct-edition'
                && !empty($match['exact_tracks'])) {
                $reviewReasons = ['edition-title-complete-tracklist-collision'];
                break;
            }
        }

        return $base + [
            'decision' => self::AMBIGUOUS,
            'confidence' => 'candidate',
            'album_id' => null,
            'match_source' => 'artist+full-tracklist-review',
            'candidate_album_ids' => $this->albumIds($matchedAlbums),
            'reasons' => $reviewReasons,
        ];
    }

    /** @param array<int,string> $candidateTracks */
    private function tracklistsStrictlyEqual(array $candidateTracks, int $albumId): bool
    {
        $comparison = $this->trackMatcher->compare($candidateTracks, $this->localTrackTitles($albumId));
        return !empty($comparison['equivalent'])
            && (int)($comparison['exact_count'] ?? -1) === count($candidateTracks)
            && (int)($comparison['total'] ?? -1) === count($candidateTracks);
    }

    /**
     * Strong-source vetoes. An exact local source-path or exact release MBID is
     * never silently reconciled when its identity metadata contradicts the
     * candidate.
     *
     * @param array<string,mixed> $album
     * @return array<int,string>
     */
    private function strongIdentityVetoes(
        string $artist,
        string $title,
        ?int $year,
        string $releaseMbid,
        array $album
    ): array {
        $vetoes = [];

        $dbArtist = trim((string)($album['artist_name'] ?? ''));
        $dbTitle = trim((string)($album['title'] ?? ''));
        $dbYear = !empty($album['year']) ? (int)$album['year'] : null;
        $dbMbid = strtolower(trim((string)($album['mbid'] ?? '')));

        if ($releaseMbid !== '' && $dbMbid !== '' && $releaseMbid !== $dbMbid) {
            $vetoes[] = 'release-mbid-mismatch';
        }

        if ($year !== null && $dbYear !== null && $year !== $dbYear) {
            $vetoes[] = 'known-year-mismatch';
        }

        if (
            $artist !== ''
            && $dbArtist !== ''
            && $this->normalizeIdentity($artist) !== $this->normalizeIdentity($dbArtist)
        ) {
            $vetoes[] = 'artist-mismatch';
        }

        if (
            $title !== ''
            && $dbTitle !== ''
            && !$this->titleMatcher->equivalent($title, $dbTitle)
        ) {
            $vetoes[] = 'title-mismatch';
        }

        return array_values(array_unique($vetoes));
    }

    /**
     * @param array<string,mixed> $album
     */
    private function weakAlbumMetadataCompatible(
        string $artist,
        string $title,
        ?int $year,
        string $releaseMbid,
        string $releaseGroup,
        array $album,
        bool $requireTitle
    ): bool {
        $dbArtist = trim((string)($album['artist_name'] ?? ''));
        $dbTitle = trim((string)($album['title'] ?? ''));
        $dbYear = !empty($album['year']) ? (int)$album['year'] : null;
        $dbMbid = strtolower(trim((string)($album['mbid'] ?? '')));
        $dbGroup = strtolower(trim((string)($album['mb_release_group'] ?? '')));

        if (
            $artist !== ''
            && $dbArtist !== ''
            && $this->normalizeIdentity($artist) !== $this->normalizeIdentity($dbArtist)
        ) {
            return false;
        }

        if (
            $requireTitle
            && $title !== ''
            && $dbTitle !== ''
            && !$this->titleMatcher->equivalent($title, $dbTitle)
        ) {
            return false;
        }

        // Year is descriptive metadata, not a logical-album identity key.
        // It is evaluated only as a warning after a complete tracklist proves
        // the match. This prevents a stale/manual year from defeating stronger
        // evidence such as same release-group + full tracklist or
        // artist/title + full tracklist.

        // A different release MBID is a different edition/release, not proof of
        // a different logical album. Do not veto on release MBID here.

        // GRIZZLY_INVALID_RELEASE_GROUP_TAG_FIX_V1
        // In the fallback path, only two valid MusicBrainz release-group UUIDs
        // can prove that the candidate and the local row are different logical
        // albums. Numeric/vendor values (for example "3831") are not valid
        // MusicBrainz release-group identities and must not veto the complete
        // artist/title/tracklist fallback.
        if (
            $requireTitle
            && $this->isUuid($releaseGroup)
            && $this->isUuid($dbGroup)
            && $releaseGroup !== $dbGroup
        ) {
            return false;
        }

        return true;
    }

    /**
     * Year is metadata only once stronger structural identity has been proven.
     *
     * @param array<string,mixed> $album
     * @return array<int,string>
     */
    private function yearMetadataWarnings(?int $year, array $album): array
    {
        $dbYear = !empty($album['year']) ? (int)$album['year'] : null;

        if ($year !== null && $dbYear !== null && $year !== $dbYear) {
            return ['known-year-mismatch'];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $album
     */
    private function tracklistEvidenceAllowed(string $evidenceStatus, array $album): bool
    {
        if ($evidenceStatus === 'READY') {
            return true;
        }

        return (int)($album['needs_review'] ?? 1) === 0;
    }

    /**
     * Conservative complete-tracklist comparison delegated to TrackMatcher.
     * The matcher preserves multiset semantics for multidisc compatibility,
     * requires the same track count and allows only a small minority of
     * controlled title variants.
     *
     * @param array<int,string> $candidateTracks
     */
    private function tracklistsEqual(array $candidateTracks, int $albumId): bool
    {
        $localTracks = $this->localTrackTitles($albumId);
        $comparison = $this->trackMatcher->compare($candidateTracks, $localTracks);

        return !empty($comparison['equivalent']);
    }

    /**
     * @return array<int,string>
     */
    private function localTrackTitles(int $albumId): array
    {
        $stmt = $this->db->prepare(
            "SELECT title
             FROM tracks
             WHERE album_id = :album_id
             ORDER BY position ASC, id ASC"
        );
        $stmt->execute([':album_id' => $albumId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @param array<int,array<string,mixed>> $albums
     * @return array<int,int>
     */
    private function albumIds(array $albums): array
    {
        $ids = [];
        foreach ($albums as $album) {
            $id = (int)($album['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        sort($ids, SORT_NUMERIC);
        return array_values(array_unique($ids));
    }

    /**
     * @param array<string,mixed> $album
     * @return array<string,mixed>
     */
    private function publicAlbum(array $album): array
    {
        return [
            'id' => (int)($album['id'] ?? 0),
            'artist' => (string)($album['artist_name'] ?? ''),
            'title' => (string)($album['title'] ?? ''),
            'year' => !empty($album['year']) ? (int)$album['year'] : null,
            'mbid' => (string)($album['mbid'] ?? ''),
            'mb_release_group' => (string)($album['mb_release_group'] ?? ''),
            'needs_review' => (int)($album['needs_review'] ?? 0),
            'track_count' => count($this->localTrackTitles((int)($album['id'] ?? 0))),
        ];
    }

    /**
     * @param array<string,mixed> $comments
     * @param array<int,string> $keys
     */
    private function firstComment(array $comments, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $comments)) {
                continue;
            }

            $value = $comments[$key];

            if (is_array($value)) {
                foreach ($value as $item) {
                    $item = trim((string)$item);
                    if ($item !== '') {
                        return $item;
                    }
                }
                continue;
            }

            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function leadingPositiveInt(string $value): ?int
    {
        if (preg_match('/^\s*(\d+)/', $value, $m) !== 1) {
            return null;
        }

        $n = (int)$m[1];
        return $n > 0 ? $n : null;
    }

    private function normalizeIdentity(string $value): string
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

        $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
        return trim((string)$value);
    }

    private function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#/+#', '/', $path);
        return rtrim((string)$path, '/');
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

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            trim($value)
        ) === 1;
    }

    private function bootstrapTrackMatcher(): void
    {
        if (!class_exists('TrackMatcher')) {
            $path = $this->projectRoot . '/app/services/TrackMatcher.php';
            if (is_file($path)) {
                require_once $path;
            }
        }

        if (!class_exists('TrackMatcher')) {
            throw new RuntimeException('TrackMatcher service not found.');
        }

        $this->trackMatcher = new TrackMatcher();
    }

    private function bootstrapTitleMatcher(): void
    {
        if (!class_exists('AlbumTitleMatcher')) {
            $path = $this->projectRoot . '/app/services/AlbumTitleMatcher.php';
            if (is_file($path)) {
                require_once $path;
            }
        }

        if (!class_exists('AlbumTitleMatcher')) {
            throw new RuntimeException('AlbumTitleMatcher service not found.');
        }

        $this->titleMatcher = new AlbumTitleMatcher();
    }

    private function bootstrapGetId3(): void
    {
        if (!class_exists('getID3')) {
            $path = $this->projectRoot . '/app/lib/getid3/getid3/getid3.php';
            if (is_file($path)) {
                require_once $path;
            }
        }

        if (class_exists('getID3')) {
            $this->getId3 = new getID3();
        }
    }
}

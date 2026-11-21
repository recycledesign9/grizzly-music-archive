<?php

require_once __DIR__ . '/MediaPathResolver.php';

/**
 * AlbumAudioCoverageService
 *
 * Verifica read-only della copertura audio di un album.
 *
 * Per gli audio managed una riga DB non costituisce da sola prova di
 * riproducibilità: il file deve essere risolvibile realmente tramite
 * MediaPathResolver::resolveAudioFile().
 *
 * Il servizio non modifica database o filesystem.
 */
class AlbumAudioCoverageService
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Verifica se tutte le tracce dell'album sono coperte da almeno un
     * file managed fisicamente riproducibile.
     *
     * Le righe duplicate sulla stessa track_id sono ammesse ma non
     * aumentano la copertura semantica.
     *
     * Una riga managed viene considerata valida per la copertura solo se:
     *
     * - appartiene all'album richiesto;
     * - track_id appartiene realmente a una track dello stesso album;
     * - MediaPathResolver riesce a risolverla;
     * - il file fisico esiste ed e' leggibile.
     *
     * @return array<string,mixed>
     */
    public function inspectManagedCoverage(int $albumId): array
    {
        $result = [
            'complete' => false,
            'album_id' => $albumId > 0 ? $albumId : null,
            'track_count' => 0,
            'managed_rows' => 0,
            'playable_managed_rows' => 0,
            'covered_tracks' => 0,
            'uncovered_tracks' => 0,
            'reason' => 'unverified',
        ];

        if ($albumId <= 0) {
            $result['reason'] = 'invalid-album-id';
            return $result;
        }

        /*
         * L'insieme delle track dell'album e' l'autorita' per la copertura.
         *
         * Non ci limitiamo a COUNT(*) perché dobbiamo anche verificare che
         * ogni audio_files.track_id appartenga davvero allo stesso album.
         */
        $tracksStmt = $this->db->prepare("
            SELECT id
            FROM tracks
            WHERE album_id = :album_id
            ORDER BY id ASC
        ");

        $tracksStmt->execute([
            ':album_id' => $albumId,
        ]);

        $trackIds = array_map(
            'intval',
            $tracksStmt->fetchAll(PDO::FETCH_COLUMN)
        );

        $trackSet = [];

        foreach ($trackIds as $trackId) {
            if ($trackId > 0) {
                $trackSet[$trackId] = true;
            }
        }

        $trackCount = count($trackSet);

        $result['track_count'] = $trackCount;
        $result['uncovered_tracks'] = $trackCount;

        if ($trackCount === 0) {
            $result['reason'] = 'album-has-no-tracks';
            return $result;
        }

        /*
         * Leggiamo tutte le righe managed dell'album.
         *
         * source_path viene incluso per passare a MediaPathResolver la
         * stessa struttura usata dal resto dell'applicazione, anche se per
         * storage_type=managed il resolver utilizza filename.
         */
        $audioStmt = $this->db->prepare("
            SELECT
                id,
                album_id,
                track_id,
                filename,
                source_path,
                storage_type
            FROM audio_files
            WHERE album_id = :album_id
              AND storage_type = 'managed'
            ORDER BY id ASC
        ");

        $audioStmt->execute([
            ':album_id' => $albumId,
        ]);

        $rows = $audioStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($rows)) {
            $rows = [];
        }

        $result['managed_rows'] = count($rows);

        if (empty($rows)) {
            $result['reason'] = 'no-managed-audio';
            return $result;
        }

        $covered = [];
        $playableRows = 0;

        foreach ($rows as $row) {
            $trackId = (int)($row['track_id'] ?? 0);

            /*
             * Una riga senza track_id, o collegata a una track di un altro
             * album, non puo' certificare la copertura di questo album.
             */
            if (
                $trackId <= 0
                || !isset($trackSet[$trackId])
            ) {
                continue;
            }

            $resolved = MediaPathResolver::resolveAudioFile($row);

            if ($resolved === null) {
                continue;
            }

            /*
             * resolveAudioFile() verifica gia' is_file()+is_readable().
             * Manteniamo comunque il risultato come prova esplicita:
             * questa riga managed e' realmente riproducibile.
             */
            $playableRows++;
            $covered[$trackId] = true;
        }

        $coveredTracks = count($covered);

        $result['playable_managed_rows'] = $playableRows;
        $result['covered_tracks'] = $coveredTracks;
        $result['uncovered_tracks'] = max(
            0,
            $trackCount - $coveredTracks
        );

        if ($coveredTracks !== $trackCount) {
            $result['reason'] = 'managed-track-coverage-incomplete';
            return $result;
        }

        $result['complete'] = true;
        $result['reason'] = 'all-tracks-covered-by-playable-managed-audio';

        return $result;
    }

    /**
     * Verifica la copertura effettiva di un album quando coesistono audio
     * managed ed external.
     *
     * Regole:
     * - managed riproducibile ha priorita' assoluta sulla stessa track;
     * - se esiste almeno una riga managed per una track ma nessun relativo
     *   file e' riproducibile, la track e' marcata managed_missing e NON viene
     *   soddisfatta automaticamente da un external;
     * - in assenza di managed, una track puo' essere coperta da un external
     *   solo se il file e' leggibile, appartiene al candidato corrente e il
     *   contenuto coincide con filesize/mtime o con il SHA-1 persistito;
     * - righe duplicate non aumentano la copertura semantica.
     *
     * @return array<string,mixed>
     */
    public function inspectCandidateCoverage(
        int $albumId,
        string $candidatePath
    ): array {
        $result = [
            'complete' => false,
            'album_id' => $albumId > 0 ? $albumId : null,
            'track_count' => 0,
            'covered_tracks' => 0,
            'managed_covered_tracks' => 0,
            'external_covered_tracks' => 0,
            'managed_missing_tracks' => 0,
            'uncovered_tracks' => 0,
            'managed_rows' => 0,
            'external_rows' => 0,
            'playable_managed_rows' => 0,
            'current_external_rows' => 0,
            'reason' => 'unverified',
        ];

        if ($albumId <= 0) {
            $result['reason'] = 'invalid-album-id';
            return $result;
        }

        $candidateReal = realpath($candidatePath);
        if (
            $candidateReal === false
            || !is_dir($candidateReal)
            || !is_readable($candidateReal)
        ) {
            $result['reason'] = 'candidate-path-unavailable';
            return $result;
        }
        $candidateReal = rtrim(str_replace('\\', '/', $candidateReal), '/');

        $tracksStmt = $this->db->prepare("\n            SELECT id\n            FROM tracks\n            WHERE album_id = :album_id\n            ORDER BY id ASC\n        ");
        $tracksStmt->execute([':album_id' => $albumId]);

        $trackIds = array_map('intval', $tracksStmt->fetchAll(PDO::FETCH_COLUMN));
        $trackSet = [];
        foreach ($trackIds as $trackId) {
            if ($trackId > 0) {
                $trackSet[$trackId] = true;
            }
        }

        $trackCount = count($trackSet);
        $result['track_count'] = $trackCount;
        $result['uncovered_tracks'] = $trackCount;

        if ($trackCount === 0) {
            $result['reason'] = 'album-has-no-tracks';
            return $result;
        }

        $audioStmt = $this->db->prepare("\n            SELECT\n                id, album_id, track_id, filename, filesize, source_hash,\n                source_path, source_mtime, storage_type\n            FROM audio_files\n            WHERE album_id = :album_id\n              AND track_id IS NOT NULL\n            ORDER BY id ASC\n        ");
        $audioStmt->execute([':album_id' => $albumId]);
        $rows = $audioStmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = is_array($rows) ? $rows : [];

        $managedByTrack = [];
        $externalByTrack = [];

        foreach ($rows as $row) {
            $trackId = (int)($row['track_id'] ?? 0);
            if ($trackId <= 0 || !isset($trackSet[$trackId])) {
                continue;
            }

            if ((string)($row['storage_type'] ?? 'managed') === 'external') {
                $result['external_rows']++;
                $externalByTrack[$trackId][] = $row;
            } else {
                $result['managed_rows']++;
                $managedByTrack[$trackId][] = $row;
            }
        }

        $covered = [];

        foreach (array_keys($trackSet) as $trackId) {
            $managedRows = $managedByTrack[$trackId] ?? [];

            if (!empty($managedRows)) {
                $managedPlayable = false;

                foreach ($managedRows as $row) {
                    if (MediaPathResolver::resolveAudioFile($row) !== null) {
                        $result['playable_managed_rows']++;
                        $managedPlayable = true;
                    }
                }

                if ($managedPlayable) {
                    $covered[$trackId] = true;
                    $result['managed_covered_tracks']++;
                    continue;
                }

                // Una riga managed esprime una scelta manuale. Se il relativo
                // file e' sparito non la sostituiamo implicitamente con external.
                $result['managed_missing_tracks']++;
                continue;
            }

            foreach (($externalByTrack[$trackId] ?? []) as $row) {
                $resolved = MediaPathResolver::resolveAudioFile($row);
                if ($resolved === null) {
                    continue;
                }

                $resolvedReal = realpath($resolved);
                if ($resolvedReal === false) {
                    continue;
                }
                $resolvedReal = str_replace('\\', '/', $resolvedReal);

                if (!$this->within($resolvedReal, $candidateReal)) {
                    continue;
                }

                $sizeNow = @filesize($resolvedReal);
                if ($sizeNow === false) {
                    continue;
                }
                $sizeNow = (int)$sizeNow;

                $dbSize = (int)($row['filesize'] ?? 0);
                if ($dbSize > 0 && $sizeNow !== $dbSize) {
                    continue;
                }

                $mtimeNow = @filemtime($resolvedReal);
                $dbMtime = isset($row['source_mtime'])
                    && $row['source_mtime'] !== null
                        ? (int)$row['source_mtime']
                        : null;

                $fastSame = $dbSize > 0
                    && $dbMtime !== null
                    && $mtimeNow !== false
                    && $sizeNow === $dbSize
                    && (int)$mtimeNow === $dbMtime;

                if (!$fastSame) {
                    $dbHash = strtolower(trim((string)($row['source_hash'] ?? '')));
                    if ($dbHash === '') {
                        continue;
                    }

                    $hashNow = @sha1_file($resolvedReal);
                    if (
                        $hashNow === false
                        || !hash_equals($dbHash, strtolower((string)$hashNow))
                    ) {
                        continue;
                    }
                }

                $result['current_external_rows']++;
                $covered[$trackId] = true;
                $result['external_covered_tracks']++;
                break;
            }
        }

        $coveredTracks = count($covered);
        $result['covered_tracks'] = $coveredTracks;
        $result['uncovered_tracks'] = max(0, $trackCount - $coveredTracks);

        if ($result['managed_missing_tracks'] > 0) {
            $result['reason'] = 'managed-audio-missing';
            return $result;
        }

        if ($coveredTracks !== $trackCount) {
            $result['reason'] = 'album-track-coverage-incomplete';
            return $result;
        }

        $result['complete'] = true;
        $result['reason'] = 'all-tracks-covered-by-managed-or-current-external-audio';

        return $result;
    }

    private function within(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $path === $root || strpos($path . '/', $root . '/') === 0;
    }

}

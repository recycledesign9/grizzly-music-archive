<?php
class Track
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getByAlbum(int $albumId): array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, af.filename AS audio_filename, af.id AS audio_file_id
            FROM tracks t
            LEFT JOIN audio_files af ON af.track_id = t.id
            WHERE t.album_id = :album_id
            ORDER BY t.position ASC
        ");
        $stmt->execute([':album_id' => $albumId]);
        return $stmt->fetchAll();
    }

    public function saveTracklist(int $albumId, array $tracks): void
    {
        $this->db->beginTransaction();

        try {
            // Legge anche posizione e titolo: servono a preservare gli ID locali
            // quando una tracklist recuperata da API viene reinserita nel form
            // senza gli ID della tabella tracks.
            $stmt = $this->db->prepare("
                SELECT id, position, title
                FROM tracks
                WHERE album_id = :album_id
                ORDER BY position ASC, id ASC
            ");
            $stmt->execute([':album_id' => $albumId]);
            $existingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $existingIds = [];
            $byPosition  = [];
            $byTitle     = [];

            foreach ($existingRows as $row) {
                $eid = (int)$row['id'];
                $existingIds[] = $eid;

                $pos = (int)$row['position'];
                if ($pos > 0) {
                    $byPosition[$pos] = $eid;
                }

                $key = $this->normalizeTrackTitle((string)$row['title']);
                if ($key !== '') {
                    if (!isset($byTitle[$key])) {
                        $byTitle[$key] = [];
                    }
                    $byTitle[$key][] = $eid;
                }
            }

            $incomingCount = 0;
            $allIncomingIdsEmpty = true;
            foreach ($tracks as $track) {
                if (trim((string)($track['title'] ?? '')) !== '') {
                    $incomingCount++;
                }
                if (!empty($track['id'])) {
                    $allIncomingIdsEmpty = false;
                }
            }

            $keptIds = [];
            $usedIds = [];

            $update = $this->db->prepare("
                UPDATE tracks
                SET position = :position,
                    title = :title,
                    duration_sec = :duration_sec
                WHERE id = :id AND album_id = :album_id
            ");

            $insert = $this->db->prepare("
                INSERT INTO tracks (album_id, position, title, duration_sec)
                VALUES (:album_id, :position, :title, :duration_sec)
            ");

            foreach ($tracks as $i => $track) {
                $title = trim((string)($track['title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                $position = $i + 1;
                $duration = isset($track['duration']) && $track['duration'] !== ''
                    ? (int)$track['duration']
                    : null;

                $requestedId = !empty($track['id']) ? (int)$track['id'] : 0;
                $resolvedId = 0;

                // 1) ID locale esplicito proveniente dal form corrente.
                if ($requestedId > 0
                    && in_array($requestedId, $existingIds, true)
                    && empty($usedIds[$requestedId])) {
                    $resolvedId = $requestedId;
                }

                // 2) Se l'ID manca (tipico dopo recupero API), prova un titolo
                // locale univoco. Non fidarsi mai di eventuali ID esterni.
                if ($resolvedId === 0) {
                    $titleKey = $this->normalizeTrackTitle($title);
                    if ($titleKey !== '' && isset($byTitle[$titleKey])) {
                        $available = array_values(array_filter($byTitle[$titleKey], function ($id) use ($usedIds) {
                            return empty($usedIds[(int)$id]);
                        }));
                        if (count($available) === 1) {
                            $resolvedId = (int)$available[0];
                        }
                    }
                }

                // 3) Caso specifico del refresh completo da API: tutti gli ID
                // sono vuoti e il numero di tracce non cambia. Conserviamo gli
                // ID per posizione, evitando di sganciare gli audio gia' associati.
                if ($resolvedId === 0
                    && $allIncomingIdsEmpty
                    && $incomingCount === count($existingRows)
                    && isset($byPosition[$position])
                    && empty($usedIds[(int)$byPosition[$position]])) {
                    $resolvedId = (int)$byPosition[$position];
                }

                if ($resolvedId > 0) {
                    $update->execute([
                        ':id'           => $resolvedId,
                        ':album_id'     => $albumId,
                        ':position'     => $position,
                        ':title'        => $title,
                        ':duration_sec' => $duration,
                    ]);
                    $keptIds[] = $resolvedId;
                    $usedIds[$resolvedId] = true;
                } else {
                    $insert->execute([
                        ':album_id'     => $albumId,
                        ':position'     => $position,
                        ':title'        => $title,
                        ':duration_sec' => $duration,
                    ]);
                    $newId = (int)$this->db->lastInsertId();
                    $keptIds[] = $newId;
                    $usedIds[$newId] = true;
                }
            }

            // Elimina SOLO le tracce realmente tolte. Prima sgancia gli audio,
            // ma il percorso esterno/fisico non viene mai cancellato qui.
            $idsToDelete = array_diff($existingIds, $keptIds);

            if (!empty($idsToDelete)) {
                $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));

                $sqlDetach = "UPDATE audio_files SET track_id = NULL WHERE track_id IN ($placeholders)";
                $stmtDetach = $this->db->prepare($sqlDetach);
                $stmtDetach->execute(array_values($idsToDelete));

                $sqlDelete = "DELETE FROM tracks WHERE id IN ($placeholders) AND album_id = ?";
                $params = array_merge(array_values($idsToDelete), [$albumId]);
                $stmtDelete = $this->db->prepare($sqlDelete);
                $stmtDelete->execute($params);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function normalizeTrackTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        $title = function_exists('mb_strtolower')
            ? mb_strtolower($title, 'UTF-8')
            : strtolower($title);
        $title = str_replace(["\xE2\x80\x93", "\xE2\x80\x94", '_'], ' ', $title);
        $title = preg_replace('/[^\pL\pN]+/u', ' ', $title);
        $title = preg_replace('/\s+/u', ' ', (string)$title);

        return trim((string)$title);
    }

    // Formatta secondi in m:ss
    public static function formatDuration(?int $seconds): string
    {
        if (!$seconds) return '';
        return floor($seconds / 60) . ':' . str_pad($seconds % 60, 2, '0', STR_PAD_LEFT);
    }
}

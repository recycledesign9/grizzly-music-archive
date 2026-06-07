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
            // 1) prendi ID tracce esistenti
            $stmt = $this->db->prepare("SELECT id FROM tracks WHERE album_id = :album_id");
            $stmt->execute([':album_id' => $albumId]);
            $existingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $keptIds = [];

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
                if ($title === '') continue;

                $trackId = !empty($track['id']) ? (int)$track['id'] : 0;
                $duration = isset($track['duration']) && $track['duration'] !== ''
                    ? (int)$track['duration']
                    : null;

                // UPDATE se esiste
                if (!empty($track['id']) && in_array((int)$track['id'], $existingIds, true)) {
                    $update->execute([
                        ':id' => $trackId,
                        ':album_id' => $albumId,
                        ':position' => $i + 1,
                        ':title' => $title,
                        ':duration_sec' => $duration,
                    ]);
                    $keptIds[] = (int)$track['id'];

                    // INSERT se nuova
                } else {
                    $insert->execute([
                        ':album_id' => $albumId,
                        ':position' => $i + 1,
                        ':title' => $title,
                        ':duration_sec' => $duration,
                    ]);
                    $keptIds[] = (int)$this->db->lastInsertId();
                }
            }

            // 2) elimina SOLO le tracce tolte dal form
            $idsToDelete = array_diff($existingIds, $keptIds);

            if (!empty($idsToDelete)) {
                $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));

                // sgancia audio (NON li cancella)
                $sqlDetach = "UPDATE audio_files SET track_id = NULL WHERE track_id IN ($placeholders)";
                $stmtDetach = $this->db->prepare($sqlDetach);
                $stmtDetach->execute(array_values($idsToDelete));

                // elimina tracce
                $sqlDelete = "DELETE FROM tracks WHERE id IN ($placeholders) AND album_id = ?";
                $params = array_merge(array_values($idsToDelete), [$albumId]);
                $stmtDelete = $this->db->prepare($sqlDelete);
                $stmtDelete->execute($params);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // Formatta secondi in m:ss
    public static function formatDuration(?int $seconds): string
    {
        if (!$seconds) return '';
        return floor($seconds / 60) . ':' . str_pad($seconds % 60, 2, '0', STR_PAD_LEFT);
    }
}

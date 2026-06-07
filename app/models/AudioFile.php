<?php

class AudioFile {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // ----------------------------------------------------------
    // Recupera tutti i file audio di un album
    // ----------------------------------------------------------
    public function getByAlbum(int $albumId): array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM audio_files
            WHERE album_id = :album_id
        ");
        $stmt->execute([':album_id' => $albumId]);
        return $stmt->fetchAll();
    }

    // ----------------------------------------------------------
    // Salva file audio
    // ----------------------------------------------------------
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO audio_files (album_id, track_id, filename, original_name, filesize)
            VALUES (:album_id, :track_id, :filename, :original_name, :filesize)
        ");

        $stmt->execute([
            ':album_id'     => $data['album_id'],
            ':track_id'     => $data['track_id'],
            ':filename'     => $data['filename'],
            ':original_name'=> $data['original_name'],
            ':filesize'     => $data['filesize'] ?? null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    // ----------------------------------------------------------
    // Cancella file audio
    // ----------------------------------------------------------
    public function delete(int $id): void {
        $stmt = $this->db->prepare("DELETE FROM audio_files WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }
}
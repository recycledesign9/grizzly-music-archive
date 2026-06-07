<?php
class Artist {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll(): array {
        return $this->db->query("SELECT * FROM artists ORDER BY name")->fetchAll();
    }

    public function getById(int $id) {
        $stmt = $this->db->prepare("SELECT * FROM artists WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function findOrCreate(string $name): int {
        $name = trim($name);
        $stmt = $this->db->prepare("SELECT id FROM artists WHERE name = :name LIMIT 1");
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch();
        if ($row) return (int)$row['id'];

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8','ASCII//TRANSLIT', $name)));
        $stmt = $this->db->prepare("INSERT INTO artists (name, slug) VALUES (:name, :slug)");
        $stmt->execute([':name' => $name, ':slug' => $slug]);
        return (int)$this->db->lastInsertId();
    }

    public function getAlbums(int $artistId): array {
        $stmt = $this->db->prepare("
            SELECT a.*, f.name AS format_name, g.name AS genre_name,
                   COUNT(t.id) AS track_count
            FROM albums a
            LEFT JOIN formats f ON a.format_id = f.id
            LEFT JOIN genres  g ON a.genre_id  = g.id
            LEFT JOIN tracks  t ON a.id = t.album_id
            WHERE a.artist_id = :id
            GROUP BY a.id
            ORDER BY a.year ASC, a.title ASC
        ");
        $stmt->execute([':id' => $artistId]);
        return $stmt->fetchAll();
    }

    public function getTopArtists(int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT ar.id, ar.name, ar.slug, COUNT(a.id) AS album_count
            FROM artists ar
            LEFT JOIN albums a ON ar.id = a.artist_id
            GROUP BY ar.id
            ORDER BY album_count DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
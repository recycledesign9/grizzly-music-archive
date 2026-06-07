<?php

class SearchController {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function dispatch(string $action, ?int $id): void {
        $this->index();
    }

    public function index(): void {
        $q       = isset($_GET['q']) ? trim($_GET['q']) : null;
        $albums  = [];
        $artists = [];

        if (strlen($q) >= 2) {
            $like = '%' . $q . '%';

            $stmt = $this->db->prepare("
                SELECT a.*, ar.name AS artist_name, f.name AS format_name,
                       ar.id AS artist_id
                FROM albums a
                JOIN artists ar ON ar.id = a.artist_id
                JOIN formats f  ON f.id  = a.format_id
                WHERE a.title LIKE ? OR ar.name LIKE ?
                ORDER BY ar.name ASC, a.year ASC
            ");
            $stmt->execute([$like, $like]);
            $albums = $stmt->fetchAll();

            $stmt = $this->db->prepare("
                SELECT ar.*, COUNT(a.id) AS album_count
                FROM artists ar
                LEFT JOIN albums a ON a.artist_id = ar.id
                WHERE ar.name LIKE ?
                GROUP BY ar.id
                ORDER BY ar.name ASC
            ");
            $stmt->execute([$like]);
            $artists = $stmt->fetchAll();
        }

        require BASE_PATH . '/views/search/results.php';
    }
}
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
                       ar.id AS artist_id,
                       (
                         SELECT GROUP_CONCAT(CONCAT(f2.id, ':::', f2.name) ORDER BY f2.id SEPARATOR '|||')
                         FROM album_formats af2
                         JOIN formats f2 ON af2.format_id = f2.id
                         WHERE af2.album_id = a.id
                       ) AS formats_raw
                FROM albums a
                JOIN artists ar     ON ar.id = a.artist_id
                LEFT JOIN formats f ON f.id  = a.format_id
                WHERE a.title LIKE ? OR ar.name LIKE ?
                ORDER BY ar.name ASC, a.year ASC
            ");
            $stmt->execute([$like, $like]);
            $albums = $stmt->fetchAll();

            // Tutti i formati della scheda (tabella ponte); la colonna
            // legacy format_name resta come fallback per robustezza
            foreach ($albums as &$row) {
                $formats = [];
                if (!empty($row['formats_raw'])) {
                    foreach (explode('|||', $row['formats_raw']) as $chunk) {
                        $parts = explode(':::', $chunk, 2);
                        if (count($parts) === 2 && $parts[0] !== '') {
                            $formats[] = ['id' => (int)$parts[0], 'name' => $parts[1]];
                        }
                    }
                }
                $row['formats'] = $formats;
                unset($row['formats_raw']);
            }
            unset($row);

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
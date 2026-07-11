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
        $q = trim($_GET['q'] ?? '');

        // Filtri avanzati (stessi dell'archivio)
        $filters = [
            'format_id' => (int)($_GET['format_id'] ?? 0),
            'genre_id'  => (int)($_GET['genre_id']  ?? 0),
            'label_id'  => (int)($_GET['label_id']  ?? 0),
            'year'      => (int)($_GET['year']      ?? 0),
        ];
        $hasFilters = (bool)array_filter($filters);

        // Lookup per i select del form di ricerca
        $albumModel = new Album();
        $formats = $albumModel->getFormats();
        $genres  = $albumModel->getGenres();
        $labels  = $albumModel->getLabels();

        $albums  = [];
        $artists = [];

        // La ricerca parte con almeno 2 caratteri OPPURE con soli filtri
        $didSearch = (strlen($q) >= 2) || $hasFilters;

        if ($didSearch) {
            $where  = [];
            $params = [];

            if (strlen($q) >= 2) {
                $like = '%' . $q . '%';
                $where[]  = '(a.title LIKE ? OR ar.name LIKE ?)';
                $params[] = $like;
                $params[] = $like;
            }
            if ($filters['format_id']) {
                // Filtro sulla tabella ponte: l'album ha quel formato tra i suoi
                $where[]  = 'EXISTS (SELECT 1 FROM album_formats afx
                                     WHERE afx.album_id = a.id AND afx.format_id = ?)';
                $params[] = $filters['format_id'];
            }
            if ($filters['genre_id']) { $where[] = 'a.genre_id = ?'; $params[] = $filters['genre_id']; }
            if ($filters['label_id']) { $where[] = 'a.label_id = ?'; $params[] = $filters['label_id']; }
            if ($filters['year'])     { $where[] = 'a.year = ?';     $params[] = $filters['year']; }

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
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ar.name ASC, a.year ASC
            ");
            $stmt->execute($params);
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

            // La ricerca artisti resta legata al testo: con soli
            // filtri (es. "tutti i vinili del 1994") ha senso solo
            // l'elenco album. ar.* include image_local/image_url,
            // usati dalla vista per l'avatar accanto al nome.
            if (strlen($q) >= 2) {
                $stmt = $this->db->prepare("
                    SELECT ar.*, COUNT(a.id) AS album_count
                    FROM artists ar
                    LEFT JOIN albums a ON a.artist_id = ar.id
                    WHERE ar.name LIKE ?
                    GROUP BY ar.id
                    ORDER BY ar.name ASC
                ");
                $stmt->execute(['%' . $q . '%']);
                $artists = $stmt->fetchAll();
            }
        }

        require BASE_PATH . '/views/search/results.php';
    }
}
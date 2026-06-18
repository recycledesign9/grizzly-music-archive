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

    // ----------------------------------------------------------
    // STATISTICHE per la hero della pagina artista
    // (totale album, conteggio per formato, range anni, generi)
    // ----------------------------------------------------------
    public function getAlbumStats(int $artistId): array {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                              AS total,
                SUM(CASE WHEN f.name = 'Vinile'        THEN 1 ELSE 0 END) AS vinili,
                SUM(CASE WHEN f.name = 'CD'            THEN 1 ELSE 0 END) AS cd,
                SUM(CASE WHEN f.name = 'Musicassetta' THEN 1 ELSE 0 END) AS cassette,
                SUM(CASE WHEN f.name = 'Digital'      THEN 1 ELSE 0 END) AS digital,
                MIN(NULLIF(a.year, 0))                                AS year_min,
                MAX(NULLIF(a.year, 0))                                AS year_max
            FROM albums a
            LEFT JOIN formats f ON a.format_id = f.id
            WHERE a.artist_id = :id
        ");
        $stmt->execute([':id' => $artistId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Generi distinti presenti per l'artista
        $g = $this->db->prepare("
            SELECT DISTINCT g.name
            FROM albums a
            JOIN genres g ON a.genre_id = g.id
            WHERE a.artist_id = :id
            ORDER BY g.name
        ");
        $g->execute([':id' => $artistId]);
        $row['genres'] = $g->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return $row;
    }

    // ----------------------------------------------------------
    // Salva i metadati recuperati dalle API esterne.
    // Aggiorna solo le colonne passate (whitelist), niente DROP.
    // ----------------------------------------------------------
    public function updateMeta(int $id, array $data): void {
        $allowed = [
            'mb_artist_id', 'bio', 'bio_source', 'bio_lang', 'bio_url',
            'image_url', 'image_local', 'image_source',
            'country', 'active_from', 'active_to',
        ];

        $set    = [];
        $params = [':id' => $id];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $set[]            = "`$col` = :$col";
                $params[":$col"]  = ($data[$col] === '' ? null : $data[$col]);
            }
        }

        if (empty($set)) {
            return;
        }

        // Marca sempre il momento del fetch della bio
        $set[] = "`bio_fetched_at` = NOW()";

        $sql = "UPDATE artists SET " . implode(', ', $set) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    // ----------------------------------------------------------
    // DISCOGRAFIA UFFICIALE (cache)
    // ----------------------------------------------------------

    // Legge la discografia ufficiale salvata in cache, ordinata per anno.
    public function getDiscography(int $artistId): array {
        $stmt = $this->db->prepare("
            SELECT title, year, mb_release_group_id
            FROM artist_discography
            WHERE artist_id = :id
            ORDER BY (year IS NULL), year ASC, title ASC
        ");
        $stmt->execute([':id' => $artistId]);
        return $stmt->fetchAll();
    }

    // Salva (sostituisce) la discografia ufficiale dell'artista e
    // marca disco_fetched_at. Idempotente: ripulisce prima di inserire.
    public function saveDiscography(int $artistId, array $items): void {
        $this->db->beginTransaction();
        try {
            $del = $this->db->prepare("DELETE FROM artist_discography WHERE artist_id = :id");
            $del->execute([':id' => $artistId]);

            if (!empty($items)) {
                $ins = $this->db->prepare("
                    INSERT INTO artist_discography
                        (artist_id, mb_release_group_id, title, year)
                    VALUES (:aid, :rg, :title, :year)
                ");
                foreach ($items as $it) {
                    $ins->execute([
                        ':aid'   => $artistId,
                        ':rg'    => ($it['mb_release_group_id'] ?? '') ?: null,
                        ':title' => $it['title'] ?? '',
                        ':year'  => $it['year'] ?? null,
                    ]);
                }
            }

            $upd = $this->db->prepare("UPDATE artists SET disco_fetched_at = NOW() WHERE id = :id");
            $upd->execute([':id' => $artistId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

}
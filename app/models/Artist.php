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

    // Lookup in SOLA LETTURA: cerca l'artista per nome senza mai
    // crearlo. Usato dal controllo duplicati in AlbumController::save()
    // per non lasciare artisti orfani se l'inserimento viene bloccato.
    public function findByName(string $name): ?int {
        $name = trim($name);
        if ($name === '') return null;

        $stmt = $this->db->prepare("SELECT id FROM artists WHERE name = :name LIMIT 1");
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch();

        return $row ? (int)$row['id'] : null;
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

    // Album dell'artista (scheda unica): una riga per album con
    //   formats       → array [id, name] dei formati posseduti
    //   editions      → compat con le viste dell'ex raggruppamento
    //   edition_count → numero formati
    // Colonne invariate rispetto a prima (a.* + format_name legacy,
    // genre_name, track_count): la disambiguazione MusicBrainz che
    // legge solo 'title' resta compatibile.
    public function getAlbums(int $artistId): array {
        $stmt = $this->db->prepare("
            SELECT a.*, f.name AS format_name, g.name AS genre_name,
                   (
                     SELECT COUNT(*)
                     FROM tracks t
                     WHERE t.album_id = a.id
                   ) AS track_count,
                   (
                     SELECT GROUP_CONCAT(CONCAT(f2.id, ':::', f2.name) ORDER BY f2.id SEPARATOR '|||')
                     FROM album_formats af2
                     JOIN formats f2 ON af2.format_id = f2.id
                     WHERE af2.album_id = a.id
                   ) AS formats_raw
            FROM albums a
            LEFT JOIN formats f ON a.format_id = f.id
            LEFT JOIN genres  g ON a.genre_id  = g.id
            WHERE a.artist_id = :id
            ORDER BY a.year ASC, a.title ASC
        ");
        $stmt->execute([':id' => $artistId]);

        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['formats'] = $this->parseFormats($row['formats_raw'] ?? null);
            unset($row['formats_raw']);

            // Compatibilità con le viste dell'ex raggruppamento:
            // i "link edizione" puntano tutti alla stessa scheda.
            $editions = [];
            foreach ($row['formats'] as $f) {
                $editions[] = ['id' => (int)$row['id'], 'format_name' => $f['name']];
            }
            $row['editions']      = $editions;
            $row['edition_count'] = count($editions);
        }
        unset($row);

        return $rows;
    }

    // Trasforma "id:::Nome|||id:::Nome" (GROUP_CONCAT) in
    // array di formati [['id' => int, 'name' => string], ...]
    private function parseFormats(?string $raw): array {
        $out = [];
        if (!$raw) return $out;

        foreach (explode('|||', $raw) as $chunk) {
            $parts = explode(':::', $chunk, 2);
            if (count($parts) === 2 && $parts[0] !== '') {
                $out[] = [
                    'id'   => (int)$parts[0],
                    'name' => $parts[1],
                ];
            }
        }
        return $out;
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
        // total/anni dalle schede; conteggi per formato dalla tabella
        // ponte: un album vinile+CD conta 1 vinile E 1 CD.
        $stmt = $this->db->prepare("
            SELECT
                (SELECT COUNT(*) FROM albums WHERE artist_id = :id_total)              AS total,
                SUM(CASE WHEN f.name = 'Vinile'        THEN 1 ELSE 0 END) AS vinili,
                SUM(CASE WHEN f.name = 'CD'            THEN 1 ELSE 0 END) AS cd,
                SUM(CASE WHEN f.name IN ('Musicassetta', 'Tape') THEN 1 ELSE 0 END) AS cassette,
                SUM(CASE WHEN f.name = 'Digital'      THEN 1 ELSE 0 END) AS digital,
                (SELECT MIN(NULLIF(year, 0)) FROM albums WHERE artist_id = :id_ymin)   AS year_min,
                (SELECT MAX(NULLIF(year, 0)) FROM albums WHERE artist_id = :id_ymax)   AS year_max
            FROM album_formats af
            JOIN albums a       ON af.album_id  = a.id
            LEFT JOIN formats f ON af.format_id = f.id
            WHERE a.artist_id = :id
        ");
        $stmt->execute([
            ':id'       => $artistId,
            ':id_total' => $artistId,
            ':id_ymin'  => $artistId,
            ':id_ymax'  => $artistId,
        ]);
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
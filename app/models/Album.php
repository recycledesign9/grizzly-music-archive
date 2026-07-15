<?php
class Album
{
  private PDO $db;

  public function __construct()
  {
    $this->db = Database::getInstance();
  }

  // ----------------------------------------------------------
  // READ — lista completa con JOIN
  // ----------------------------------------------------------
  public function getAll(
    array $filters = [],
    string $order = 'a.title',
    string $dir = 'ASC',
    ?int $limit = null,
    int $offset = 0
  ): array {
    $allowed_order = ['a.title', 'a.year', 'ar.name', 'f.name', 'a.created_at', 'a.id'];
    $allowed_dir   = ['ASC', 'DESC'];

    $order = in_array($order, $allowed_order, true) ? $order : 'a.title';
    $dir   = in_array($dir, $allowed_dir, true) ? $dir : 'ASC';

    $offset = max(0, $offset);

    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['artist_id'])) {
      $where[] = 'a.artist_id = :artist_id';
      $params[':artist_id'] = (int)$filters['artist_id'];
    }
    if (!empty($filters['format_id'])) {
      // Filtro sulla tabella ponte: l'album ha quel formato tra i suoi
      $where[] = 'EXISTS (SELECT 1 FROM album_formats afx
                          WHERE afx.album_id = a.id AND afx.format_id = :format_id)';
      $params[':format_id'] = (int)$filters['format_id'];
    }
    if (!empty($filters['genre_id'])) {
      $where[] = 'a.genre_id = :genre_id';
      $params[':genre_id'] = (int)$filters['genre_id'];
    }
    if (!empty($filters['year'])) {
      $where[] = 'a.year = :year';
      $params[':year'] = (int)$filters['year'];
    }
    if (!empty($filters['label_id'])) {
      $where[] = 'a.label_id = :label_id';
      $params[':label_id'] = (int)$filters['label_id'];
    }
    if (!empty($filters['q'])) {
      $where[] = '(a.title LIKE :q_title OR ar.name LIKE :q_artist)';
      $params[':q_title']  = '%' . $filters['q'] . '%';
      $params[':q_artist'] = '%' . $filters['q'] . '%';
    }

    $sql = "
  SELECT a.*, 
         ar.name AS artist_name, 
         f.name AS format_name,
         g.name AS genre_name, 
         l.name AS label_name,
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
         ) AS formats_raw,
         (
           SELECT COUNT(DISTINCT af3.track_id)
           FROM audio_files af3
           JOIN tracks t3 ON t3.id = af3.track_id
           WHERE t3.album_id = a.id
         ) AS tracks_with_audio_count
  FROM albums a
  LEFT JOIN artists ar ON a.artist_id = ar.id
  LEFT JOIN formats  f ON a.format_id = f.id
  LEFT JOIN genres   g ON a.genre_id = g.id
  LEFT JOIN labels   l ON a.label_id = l.id
  WHERE " . implode(' AND ', $where) . "
  ORDER BY $order $dir, a.id ASC
";

    if ($limit !== null) {
      $limit  = (int)$limit;
      $offset = (int)$offset;

      $sql .= " LIMIT $limit OFFSET $offset";
    }

    $stmt = $this->db->prepare($sql);

    foreach ($params as $key => $value) {
      $stmt->bindValue($key, $value);
    }

    $stmt->execute();

    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
      $row['formats'] = $this->parseFormats($row['formats_raw'] ?? null);
      unset($row['formats_raw']);
    }
    unset($row);

    return $rows;
  }

  //metodo countAll

  public function countAll(array $filters = []): int
  {
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['artist_id'])) {
      $where[] = 'a.artist_id = :artist_id';
      $params[':artist_id'] = (int)$filters['artist_id'];
    }
    if (!empty($filters['format_id'])) {
      // Filtro sulla tabella ponte, come in getAll
      $where[] = 'EXISTS (SELECT 1 FROM album_formats afx
                          WHERE afx.album_id = a.id AND afx.format_id = :format_id)';
      $params[':format_id'] = (int)$filters['format_id'];
    }
    if (!empty($filters['genre_id'])) {
      $where[] = 'a.genre_id = :genre_id';
      $params[':genre_id'] = (int)$filters['genre_id'];
    }
    if (!empty($filters['year'])) {
      $where[] = 'a.year = :year';
      $params[':year'] = (int)$filters['year'];
    }
    if (!empty($filters['label_id'])) {
      $where[] = 'a.label_id = :label_id';
      $params[':label_id'] = (int)$filters['label_id'];
    }
    if (!empty($filters['q'])) {
      // Due parametri distinti per evitare HY093:
      // PDO con execute() non gestisce lo stesso named param usato più volte
      $where[] = '(a.title LIKE :q_title OR ar.name LIKE :q_artist)';
      $params[':q_title']  = '%' . $filters['q'] . '%';
      $params[':q_artist'] = '%' . $filters['q'] . '%';
    }

    $sql = "
          SELECT COUNT(DISTINCT a.id)
          FROM albums a
          LEFT JOIN artists ar ON a.artist_id = ar.id
          LEFT JOIN formats  f ON a.format_id = f.id
          LEFT JOIN genres   g ON a.genre_id = g.id
          LEFT JOIN labels   l ON a.label_id = l.id
          WHERE " . implode(' AND ', $where);

    $stmt = $this->db->prepare($sql);

    foreach ($params as $key => $value) {
      $stmt->bindValue($key, $value);
    }

    $stmt->execute();

    return (int)$stmt->fetchColumn();
  }

  // ----------------------------------------------------------
  // FORMATI MULTIPLI (scheda unica per album)
  //
  // La fonte di verità dei formati è la tabella ponte
  // `album_formats`; la colonna legacy `albums.format_id` resta
  // sincronizzata come "formato principale" finché
  // SearchController ed export/import non saranno migrati
  // (strategia expand-contract, fasi 2-3).
  // ----------------------------------------------------------

  // Trasforma "id:::Nome|||id:::Nome" (GROUP_CONCAT) in
  // array di formati [['id' => int, 'name' => string], ...]
  private function parseFormats(?string $raw): array
  {
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

  // Sincronizza i formati dell'album sulla tabella ponte e
  // riallinea la colonna legacy `format_id` (formato principale
  // = id formato più basso tra i selezionati). In transazione.
  public function syncFormats(int $albumId, array $formatIds): void
  {
    $formatIds = array_values(array_unique(array_map('intval', $formatIds)));
    $formatIds = array_filter($formatIds, function ($v) {
      return $v > 0;
    });

    if (empty($formatIds)) {
      return; // mai lasciare un album senza formati
    }

    $ownTransaction = !$this->db->inTransaction();
    if ($ownTransaction) {
      $this->db->beginTransaction();
    }

    try {
      $del = $this->db->prepare("DELETE FROM album_formats WHERE album_id = :id");
      $del->execute([':id' => $albumId]);

      $ins = $this->db->prepare("
          INSERT IGNORE INTO album_formats (album_id, format_id)
          VALUES (:album_id, :format_id)
      ");
      foreach ($formatIds as $fid) {
        $ins->execute([':album_id' => $albumId, ':format_id' => $fid]);
      }

      // Colonna legacy: formato principale
      $upd = $this->db->prepare("UPDATE albums SET format_id = :fid WHERE id = :id");
      $upd->execute([':fid' => min($formatIds), ':id' => $albumId]);

      if ($ownTransaction) {
        $this->db->commit();
      }
    } catch (Throwable $e) {
      if ($ownTransaction && $this->db->inTransaction()) {
        $this->db->rollBack();
      }
      throw $e;
    }
  }

  // ----------------------------------------------------------
  // Controllo duplicati — stesso artista + titolo (il formato
  // non conta più: i formati sono un attributo della scheda).
  // Confronto case-insensitive, spazi ai bordi ignorati.
  // $excludeId esclude il record corrente in modifica.
  // Ritorna la riga esistente (id, title, slug) oppure null.
  // ----------------------------------------------------------
  public function findDuplicate(int $artistId, string $title, ?int $excludeId = null): ?array
  {
    $sql = "
        SELECT id, title, slug
        FROM albums
        WHERE artist_id = :artist_id
          AND LOWER(TRIM(title)) = LOWER(TRIM(:title))
    ";

    $params = [
      ':artist_id' => $artistId,
      ':title'     => $title,
    ];

    if ($excludeId !== null) {
      $sql .= " AND id <> :exclude_id";
      $params[':exclude_id'] = $excludeId;
    }

    $sql .= " LIMIT 1";

    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
  }

  // ----------------------------------------------------------
  // COMPATIBILITÀ — wrapper per i consumatori dell\'ex
  // "raggruppamento per edizione" (dashboard.php) non ancora
  // aggiornati. Con la scheda unica non c\'è più nulla da
  // raggruppare: restituiscono getAll/countAll, mappando
  // \'editions\' sui formati della scheda (i link puntano
  // tutti alla stessa scheda).
  // ----------------------------------------------------------
  public function getAllGrouped(
    array $filters = [],
    string $order = 'a.title',
    string $dir = 'ASC',
    ?int $limit = null,
    int $offset = 0
  ): array {
    if ($order === 'last_added') {
      $order = 'a.created_at';
    }

    $rows = $this->getAll($filters, $order, $dir, $limit, $offset);

    foreach ($rows as &$row) {
      $editions = [];
      foreach ($row['formats'] as $f) {
        $editions[] = [
          'id'          => (int)$row['id'],
          'format_name' => $f['name'],
        ];
      }
      $row['editions']      = $editions;
      $row['edition_count'] = count($editions);
    }
    unset($row);

    return $rows;
  }

  public function countAllGrouped(array $filters = []): int
  {
    return $this->countAll($filters);
  }

  // ----------------------------------------------------------
  // READ — singolo album con tutti i dettagli
  // ----------------------------------------------------------
  public function getById(int $id): ?array
  {
    $sql = "
        SELECT a.*, ar.name AS artist_name, ar.slug AS artist_slug,
               f.name AS format_name, g.name AS genre_name,
               l.name AS label_name,
               (
                 SELECT GROUP_CONCAT(CONCAT(f2.id, ':::', f2.name) ORDER BY f2.id SEPARATOR '|||')
                 FROM album_formats af2
                 JOIN formats f2 ON af2.format_id = f2.id
                 WHERE af2.album_id = a.id
               ) AS formats_raw
        FROM albums a
        LEFT JOIN artists ar ON a.artist_id = ar.id
        LEFT JOIN formats  f  ON a.format_id  = f.id
        LEFT JOIN genres   g  ON a.genre_id   = g.id
        LEFT JOIN labels   l  ON a.label_id   = l.id
        WHERE a.id = :id
        LIMIT 1
    ";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([':id' => $id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      $row['formats'] = $this->parseFormats($row['formats_raw'] ?? null);
      unset($row['formats_raw']);
    }

    return $row ?: null;
  }

  // ----------------------------------------------------------
  // READ — slug
  // ----------------------------------------------------------
  public function getBySlug(string $slug)
  {
    $stmt = $this->db->prepare("
            SELECT a.*, ar.name AS artist_name, ar.slug AS artist_slug,
                   f.name AS format_name, g.name AS genre_name,
                   l.name AS label_name,
                   (
                     SELECT GROUP_CONCAT(CONCAT(f2.id, ':::', f2.name) ORDER BY f2.id SEPARATOR '|||')
                     FROM album_formats af2
                     JOIN formats f2 ON af2.format_id = f2.id
                     WHERE af2.album_id = a.id
                   ) AS formats_raw
            FROM albums a
            LEFT JOIN artists ar ON a.artist_id = ar.id
            LEFT JOIN formats  f  ON a.format_id  = f.id
            LEFT JOIN genres   g  ON a.genre_id   = g.id
            LEFT JOIN labels   l  ON a.label_id   = l.id
            WHERE a.slug = :slug LIMIT 1
        ");
    $stmt->execute([':slug' => $slug]);
    $album = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($album) {
      $album['formats'] = $this->parseFormats($album['formats_raw'] ?? null);
      unset($album['formats_raw']);
    }

    return $album ?: null;
  }

  // ----------------------------------------------------------
  // CREATE
  // ----------------------------------------------------------
  public function create(array $data): int
  {
    $sql = "
            INSERT INTO albums
                (artist_id, genre_id, label_id, format_id, title, slug,
                 year, `condition`, copies, notes, cover_url, cover_local, mbid)
            VALUES
                (:artist_id, :genre_id, :label_id, :format_id, :title, :slug,
                 :year, :condition, :copies, :notes, :cover_url, :cover_local, :mbid)
        ";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([
      ':artist_id'   => $data['artist_id'],
      ':genre_id'    => $data['genre_id']    ?: null,
      ':label_id'    => $data['label_id']    ?: null,
      ':format_id'   => $data['format_id'],
      ':title'       => $data['title'],
      ':slug'        => $this->generateSlug($data['title'], $data['artist_id']),
      ':year'        => $data['year']        ?: null,
      ':condition'   => $data['condition']   ?? 'Very Good',
      ':copies'      => $data['copies']      ?? 1,
      ':notes'       => $data['notes']       ?: null,
      ':cover_url'   => $data['cover_url']   ?: null,
      ':cover_local' => $data['cover_local'] ?: null,
      ':mbid'        => $data['mbid']        ?: null,
    ]);
    return (int)$this->db->lastInsertId();
  }

  // ----------------------------------------------------------
  // UPDATE
  // ----------------------------------------------------------
  public function update(int $id, array $data): bool
  {
    $sql = "
            UPDATE albums SET
                artist_id   = :artist_id,
                genre_id    = :genre_id,
                label_id    = :label_id,
                format_id   = :format_id,
                title       = :title,
                year        = :year,
                `condition` = :condition,
                copies      = :copies,
                notes       = :notes,
                cover_url   = :cover_url,
                cover_local = :cover_local,
                mbid        = :mbid
            WHERE id = :id
        ";
    $stmt = $this->db->prepare($sql);
    return $stmt->execute([
      ':artist_id'   => $data['artist_id'],
      ':genre_id'    => $data['genre_id']    ?: null,
      ':label_id'    => $data['label_id']    ?: null,
      ':format_id'   => $data['format_id'],
      ':title'       => $data['title'],
      ':year'        => $data['year']        ?: null,
      ':condition'   => $data['condition']   ?? 'Very Good',
      ':copies'      => $data['copies']      ?? 1,
      ':notes'       => $data['notes']       ?: null,
      ':cover_url'   => $data['cover_url']   ?: null,
      ':cover_local' => $data['cover_local'] ?: null,
      ':mbid'        => $data['mbid']        ?: null,
      ':id'          => $id,
    ]);
  }

  // ----------------------------------------------------------
  // DELETE
  // ----------------------------------------------------------
  public function delete(int $id): bool
  {
    // Le tracce e audio_files sono CASCADE, si eliminano da soli
    $stmt = $this->db->prepare("DELETE FROM albums WHERE id = :id");
    return $stmt->execute([':id' => $id]);
  }

  // ----------------------------------------------------------
  // Statistiche dashboard
  // ----------------------------------------------------------
  public function getStats(): array
  {
    // total = schede/titoli; i conteggi per formato vengono dalla
    // tabella ponte: un album vinile+CD conta 1 vinile E 1 CD.
    $sql = "
            SELECT
                (SELECT COUNT(*) FROM albums)                        AS total,
                SUM(f.name = 'Vinile')                               AS vinili,
                SUM(f.name = 'CD')                                   AS cd,
                SUM(f.name IN ('Musicassetta', 'Tape'))              AS cassette,
                SUM(f.name = 'Digital')                              AS digital,
                (SELECT COUNT(DISTINCT artist_id) FROM albums)       AS artisti,
                (SELECT COUNT(DISTINCT genre_id)  FROM albums)       AS generi
            FROM album_formats af
            LEFT JOIN formats f ON af.format_id = f.id
        ";
    return $this->db->query($sql)->fetch();
  }

  // ----------------------------------------------------------
  // Lookup tables per i <select>
  // ----------------------------------------------------------
  public function getFormats(): array
  {
    return $this->db->query("SELECT * FROM formats ORDER BY name")->fetchAll();
  }
  public function getGenres(): array
  {
    return $this->db->query("SELECT * FROM genres ORDER BY name")->fetchAll();
  }
  public function getLabels(): array
  {
    return $this->db->query("SELECT * FROM labels ORDER BY name")->fetchAll();
  }

  // ----------------------------------------------------------
  // Slug univoco
  // ----------------------------------------------------------
  public function generateSlug(string $title, int $artistId): string
  {
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $title)));
    $slug = $base . '-' . $artistId;
    $i    = 1;
    while ($this->slugExists($slug)) {
      $slug = $base . '-' . $artistId . '-' . $i++;
    }
    return $slug;
  }

  private function slugExists(string $slug): bool
  {
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM albums WHERE slug = :slug");
    $stmt->execute([':slug' => $slug]);
    return (bool)$stmt->fetchColumn();
  }
}

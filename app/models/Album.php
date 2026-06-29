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
      $where[] = 'a.format_id = :format_id';
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
         ) AS track_count
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

    return $stmt->fetchAll();
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
      $where[] = 'a.format_id = :format_id';
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
  // READ — singolo album con tutti i dettagli
  // ----------------------------------------------------------
  public function getById(int $id): ?array
  {
    $sql = "
        SELECT a.*, ar.name AS artist_name, ar.slug AS artist_slug,
               f.name AS format_name, g.name AS genre_name,
               l.name AS label_name
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
                   l.name AS label_name
            FROM albums a
            LEFT JOIN artists ar ON a.artist_id = ar.id
            LEFT JOIN formats  f  ON a.format_id  = f.id
            LEFT JOIN genres   g  ON a.genre_id   = g.id
            LEFT JOIN labels   l  ON a.label_id   = l.id
            WHERE a.slug = :slug LIMIT 1
        ");
    $stmt->execute([':slug' => $slug]);
    $album = $stmt->fetch(PDO::FETCH_ASSOC);
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
    $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(f.name = 'Vinile')       AS vinili,
                SUM(f.name = 'CD')           AS cd,
                SUM(f.name = 'Musicassetta') AS cassette,
                SUM(f.name = 'Digital')      AS digital,
                COUNT(DISTINCT a.artist_id)  AS artisti,
                COUNT(DISTINCT a.genre_id)   AS generi
            FROM albums a
            LEFT JOIN formats f ON a.format_id = f.id
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

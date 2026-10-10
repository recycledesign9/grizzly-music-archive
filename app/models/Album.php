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
         ) AS tracks_with_audio_count,
         EXISTS (
           SELECT 1
           FROM audio_files af_ext
           WHERE af_ext.album_id = a.id
             AND af_ext.storage_type = 'external'
         ) AS has_external_audio
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

  // Sincronizza i formati selezionati MANUALMENTE dall'utente.
  //
  // `album_formats` conserva due provenienze indipendenti:
  //   is_manual  = formato dichiarato nel form
  //   is_scanner = formato dedotto dalla posizione della cartella audio
  //
  // Il salvataggio manuale NON deve quindi cancellare il formato automatico:
  // aggiorna soltanto il flag manuale e rimuove le righe che non hanno più
  // nessuna provenienza.
  public function syncFormats(int $albumId, array $formatIds): void
  {
    $formatIds = array_values(array_unique(array_map('intval', $formatIds)));
    $formatIds = array_values(array_filter($formatIds, function ($v) {
      return $v > 0;
    }));

    if (empty($formatIds)) {
      return; // il form non consente album senza formati
    }

    $ownTransaction = !$this->db->inTransaction();
    if ($ownTransaction) {
      $this->db->beginTransaction();
    }

    try {
      $prevStmt = $this->db->prepare("
          SELECT format_id, is_manual, is_scanner
          FROM album_formats
          WHERE album_id = :album_id
          ORDER BY format_id
      ");
      $prevStmt->execute([':album_id' => $albumId]);
      $previousRows = $prevStmt->fetchAll(PDO::FETCH_ASSOC);

      $previousIds = array_map('intval', array_column($previousRows, 'format_id'));
      sort($previousIds);
      $submittedIds = $formatIds;
      sort($submittedIds);

      // Se l'insieme dei checkbox non è cambiato, il form potrebbe essere
      // stato salvato solo per titolo/note/cover: NON trasformiamo quindi
      // silenziosamente un formato scanner-only in manuale.
      $formatsChangedByUser = ($previousIds !== $submittedIds);

      if ($formatsChangedByUser) {
        // L'utente ha davvero modificato i "Formati posseduti": da questo
        // momento la selezione manuale diventa autorevole. I formati scanner
        // precedenti vengono sganciati; quelli ancora selezionati saranno
        // reinseriti/marcati come manuali qui sotto.
        $clear = $this->db->prepare("
            UPDATE album_formats
            SET is_manual = 0,
                is_scanner = 0
            WHERE album_id = :album_id
        ");
        $clear->execute([':album_id' => $albumId]);

        $ins = $this->db->prepare("
            INSERT INTO album_formats (album_id, format_id, is_manual, is_scanner)
            VALUES (:album_id, :format_id, 1, 0)
            ON DUPLICATE KEY UPDATE is_manual = 1, is_scanner = 0
        ");

        foreach ($submittedIds as $fid) {
          $ins->execute([
            ':album_id'  => $albumId,
            ':format_id' => $fid,
          ]);
        }

        // Le righe non più né manuali né scanner possono sparire.
        $cleanup = $this->db->prepare("
            DELETE FROM album_formats
            WHERE album_id = :album_id
              AND is_manual = 0
              AND is_scanner = 0
        ");
        $cleanup->execute([':album_id' => $albumId]);
      }

      $this->refreshPrimaryFormat($albumId);

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

  /**
   * Sincronizza il SOLO formato dedotto dallo scanner.
   *
   * Se la cartella viene spostata da Digital a CD/Vinile/Musicassetta,
   * il vecchio flag scanner viene tolto e il nuovo viene impostato.
   * Un formato selezionato manualmente resta presente anche quando non è più
   * il formato dedotto dalla cartella.
   */
  public function syncScannerFormat(int $albumId, int $formatId): void
  {
    if ($albumId <= 0 || $formatId <= 0) {
      return;
    }

    $ownTransaction = !$this->db->inTransaction();
    if ($ownTransaction) {
      $this->db->beginTransaction();
    }

    try {
      // Un formato dichiarato manualmente è autorevole. Non tentiamo neppure
      // l'adozione legacy: i vecchi record non distinguibili con certezza da una
      // scelta dell'utente devono essere trattati in modo conservativo.
      $manual = $this->db->prepare("
          SELECT COUNT(*)
          FROM album_formats
          WHERE album_id = :album_id
            AND is_manual = 1
      ");
      $manual->execute([':album_id' => $albumId]);
      if ((int)$manual->fetchColumn() > 0) {
        if ($ownTransaction) {
          $this->db->commit();
        }
        return;
      }

      // Compatibilità con gli album scannerizzati PRIMA dell'introduzione
      // dei flag di provenienza. Se un album creato dallo scanner ha un solo
      // formato e nessun flag scanner, quella riga è con ragionevole certezza
      // il formato automatico originario: la "adottiamo" come scanner-only.
      $probe = $this->db->prepare("
          SELECT
            a.needs_review,
            (SELECT COUNT(*)
               FROM audio_files au
              WHERE au.album_id = a.id
                AND au.source_path IS NOT NULL) AS scanner_audio,
            (SELECT COUNT(*)
               FROM album_formats af0
              WHERE af0.album_id = a.id) AS format_count,
            (SELECT COUNT(*)
               FROM album_formats af1
              WHERE af1.album_id = a.id
                AND af1.is_scanner = 1) AS scanner_count
          FROM albums a
          WHERE a.id = :album_id
          LIMIT 1
      ");
      $probe->execute([':album_id' => $albumId]);
      $legacy = $probe->fetch(PDO::FETCH_ASSOC);

      if ($legacy
          && (int)$legacy['needs_review'] === 1
          && (int)$legacy['scanner_audio'] > 0
          && (int)$legacy['format_count'] === 1
          && (int)$legacy['scanner_count'] === 0) {
        $adopt = $this->db->prepare("
            UPDATE album_formats
            SET is_manual = 0,
                is_scanner = 1
            WHERE album_id = :album_id
        ");
        $adopt->execute([':album_id' => $albumId]);
      }

      // Il formato scanner deve essere uno solo per album.
      $clear = $this->db->prepare("
          UPDATE album_formats
          SET is_scanner = 0
          WHERE album_id = :album_id
      ");
      $clear->execute([':album_id' => $albumId]);

      // Se il formato esiste già perché aggiunto manualmente, diventa "both":
      // is_manual resta 1 e aggiungiamo soltanto is_scanner = 1.
      $ins = $this->db->prepare("
          INSERT INTO album_formats (album_id, format_id, is_manual, is_scanner)
          VALUES (:album_id, :format_id, 0, 1)
          ON DUPLICATE KEY UPDATE is_scanner = 1
      ");
      $ins->execute([
        ':album_id'  => $albumId,
        ':format_id' => $formatId,
      ]);

      $cleanup = $this->db->prepare("
          DELETE FROM album_formats
          WHERE album_id = :album_id
            AND is_manual = 0
            AND is_scanner = 0
      ");
      $cleanup->execute([':album_id' => $albumId]);

      $this->refreshPrimaryFormat($albumId);

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

  /**
   * Mantiene allineata la colonna legacy albums.format_id al formato con id
   * più basso tra quelli realmente presenti nella tabella ponte.
   */
  private function refreshPrimaryFormat(int $albumId): void
  {
    $stmt = $this->db->prepare("
        SELECT MIN(format_id)
        FROM album_formats
        WHERE album_id = :album_id
    ");
    $stmt->execute([':album_id' => $albumId]);
    $primary = (int)$stmt->fetchColumn();

    if ($primary > 0) {
      $upd = $this->db->prepare("
          UPDATE albums
          SET format_id = :format_id
          WHERE id = :album_id
      ");
      $upd->execute([
        ':format_id' => $primary,
        ':album_id'  => $albumId,
      ]);
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
                 year, `condition`, copies, notes, cover_url, cover_local, mbid, mb_release_group)
            VALUES
                (:artist_id, :genre_id, :label_id, :format_id, :title, :slug,
                 :year, :condition, :copies, :notes, :cover_url, :cover_local, :mbid, :mb_release_group)
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
      ':mb_release_group' => !empty($data['mb_release_group']) ? $data['mb_release_group'] : null,
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
                mbid        = :mbid,
                mb_release_group = :mb_release_group
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
      ':mb_release_group' => !empty($data['mb_release_group']) ? $data['mb_release_group'] : null,
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



  /**
   * Coppie artista/titolo già presenti in archivio.
   * Servono a non mostrare come "esterno" un disco già posseduto.
   *
   * @return array<int,array{artist:string,title:string}>
   */
  public function getOwnedAlbumPairs(): array
  {
    $stmt = $this->db->query("
      SELECT ar.name AS artist, a.title
      FROM albums a
      JOIN artists ar ON ar.id = a.artist_id
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  // ----------------------------------------------------------
  // RACCOMANDAZIONI ALBUM
  //
  // Cerca SOLO album già presenti nell'archivio Grizzly.
  // Ranking:
  //   1. affinità Last.fm
  //   2. stesso genere
  //   3. vicinanza temporale
  //
  // Al massimo un album per artista.
  // Se i match Last.fm locali non bastano, completa con album
  // dello stesso genere.
  // ----------------------------------------------------------
  public function getRecommendations(
    array $similarArtists,
    int $excludeAlbumId,
    int $excludeArtistId,
    ?int $genreId,
    ?int $year,
    int $limit = 4,
    array $albumTags = []
  ): array {
    $limit = max(1, min(8, $limit));

    // Tag dell'album corrente (Last.fm, già normalizzati minuscoli) come
    // set per confronto O(1): danno il bonus "coerenza col disco" che
    // rende i consigli album-aware, non solo artist-aware.
    $tagSet = [];
    foreach ($albumTags as $t) {
      $t = trim((string) $t);
      if ($t !== '') {
        $tagSet[mb_strtolower($t, 'UTF-8')] = true;
      }
    }

    $similarity = [];
    $artistScores = [];
    $queryNames = [];

    foreach ($similarArtists as $item) {
      $name = trim((string)($item['name'] ?? ''));
      if ($name === '') continue;

      $norm = $this->normalizeArtistForRecommendation($name);
      if ($norm === '') continue;

      $match = max(0.0, min(1.0, (float)($item['match'] ?? 0)));
      $albumScore = isset($item['_album_score'])
        ? max(0.0, (float)$item['_album_score'])
        : ($match * 1000.0);

      if (!isset($artistScores[$norm]) || $albumScore > $artistScores[$norm]) {
        $similarity[$norm] = $match;
        $artistScores[$norm] = $albumScore;
        $queryNames[$norm] = $name;
      }
    }

    $candidates = [];

    if (!empty($queryNames)) {
      $placeholders = [];
      $params = [
        ':exclude_album'  => $excludeAlbumId,
        ':exclude_artist' => $excludeArtistId,
      ];

      $i = 0;
      foreach (array_values($queryNames) as $name) {
        $ph = ':artist_' . $i++;
        $placeholders[] = $ph;
        $params[$ph] = $name;
      }

      $sql = "
        SELECT
          a.id,
          a.title,
          a.year,
          a.cover_local,
          a.cover_url,
          a.genre_id,
          a.created_at,
          ar.id AS artist_id,
          ar.name AS artist_name,
          g.name AS genre_name
        FROM albums a
        JOIN artists ar ON ar.id = a.artist_id
        LEFT JOIN genres g ON g.id = a.genre_id
        WHERE a.id <> :exclude_album
          AND a.artist_id <> :exclude_artist
          AND ar.name IN (" . implode(',', $placeholders) . ")
      ";

      $stmt = $this->db->prepare($sql);

      foreach ($params as $key => $value) {
        $stmt->bindValue(
          $key,
          $value,
          is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
        );
      }

      $stmt->execute();

      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $norm = $this->normalizeArtistForRecommendation((string)$row['artist_name']);
        $match = $similarity[$norm] ?? 0.0;

        if ($match <= 0.0 && !isset($artistScores[$norm])) {
          continue;
        }

        // Gli artisti emersi solo da track.getSimilar hanno match=0 ma
        // mantengono il loro _album_score specifico del disco.
        $score = $artistScores[$norm] ?? ($match * 1000.0);

        if ($genreId && (int)$row['genre_id'] === $genreId) {
          $score += 80.0;
        }

        // Bonus ALBUM-AWARE: il genere del candidato compare tra i tag
        // dell'album corrente? (es. album corrente taggato "noise rock",
        // candidato di genere "Noise Rock" → +90). È questo che fa variare
        // i consigli tra Il Vile e Che cosa vedi pur partendo dagli stessi
        // artisti simili. Confronto sul NOME genere normalizzato.
        if (!empty($tagSet) && !empty($row['genre_name'])) {
          $gname = mb_strtolower(trim((string)$row['genre_name']), 'UTF-8');
          if ($gname !== '' && isset($tagSet[$gname])) {
            $score += 90.0;
          }
        }

        if ($year && !empty($row['year'])) {
          $diff = abs((int)$row['year'] - $year);

          if ($diff <= 1)       $score += 60.0;
          elseif ($diff <= 3)  $score += 45.0;
          elseif ($diff <= 5)  $score += 30.0;
          elseif ($diff <= 10) $score += 15.0;
        }

        $row['_score'] = $score;
        $row['_basis'] = 'lastfm';
        $candidates[] = $row;
      }
    }

    usort($candidates, function (array $a, array $b): int {
      if ($a['_score'] === $b['_score']) {
        return strcmp((string)$a['title'], (string)$b['title']);
      }
      return ($a['_score'] < $b['_score']) ? 1 : -1;
    });

    $selected = [];
    $usedArtists = [];

    foreach ($candidates as $row) {
      $artistId = (int)$row['artist_id'];

      if (isset($usedArtists[$artistId])) continue;

      $usedArtists[$artistId] = true;
      $selected[] = $row;

      if (count($selected) >= $limit) break;
    }

    // Fallback locale per completare la lista.
    if (count($selected) < $limit && $genreId) {
      $stmt = $this->db->prepare("
        SELECT
          a.id,
          a.title,
          a.year,
          a.cover_local,
          a.cover_url,
          a.genre_id,
          a.created_at,
          ar.id AS artist_id,
          ar.name AS artist_name
        FROM albums a
        JOIN artists ar ON ar.id = a.artist_id
        WHERE a.genre_id = :genre_id
          AND a.id <> :exclude_album
          AND a.artist_id <> :exclude_artist
        ORDER BY a.created_at DESC, a.id DESC
      ");

      $stmt->execute([
        ':genre_id'       => $genreId,
        ':exclude_album'  => $excludeAlbumId,
        ':exclude_artist' => $excludeArtistId,
      ]);

      $fallback = [];

      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $artistId = (int)$row['artist_id'];

        if (isset($usedArtists[$artistId])) continue;

        $score = 0.0;

        if ($year && !empty($row['year'])) {
          $diff = abs((int)$row['year'] - $year);
          $score = max(0.0, 100.0 - min(100, $diff * 5));
        }

        $row['_score'] = $score;
        $row['_basis'] = 'genre';
        $fallback[] = $row;
      }

      usort($fallback, function (array $a, array $b): int {
        if ($a['_score'] === $b['_score']) {
          return strcmp((string)$a['title'], (string)$b['title']);
        }
        return ($a['_score'] < $b['_score']) ? 1 : -1;
      });

      foreach ($fallback as $row) {
        $artistId = (int)$row['artist_id'];

        if (isset($usedArtists[$artistId])) continue;

        $usedArtists[$artistId] = true;
        $selected[] = $row;

        if (count($selected) >= $limit) break;
      }
    }

    foreach ($selected as &$row) {
      unset($row['_score']);
    }
    unset($row);

    return $selected;
  }

  private function normalizeArtistForRecommendation(string $name): string
  {
    $name = trim($name);

    if (function_exists('mb_strtolower')) {
      $name = mb_strtolower($name, 'UTF-8');
    } else {
      $name = strtolower($name);
    }

    $name = preg_replace('/\s+/u', ' ', $name);

    return trim((string)$name);
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

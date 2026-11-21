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
      $this->applyTextFilter($where, $params, (string)$filters['q']);
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

  // ----------------------------------------------------------
  // Filtro testuale dell'Archivio (titolo o artista), condiviso da
  // getAll() e countAll() perché elenco e conteggio restino allineati.
  //
  // Oltre al confronto diretto, il testo viene confrontato anche con
  // titolo e artista privati di punti, barre, trattini e apostrofi:
  // "rem" trova "R.E.M.", "acdc" trova "AC/DC". Gli spazi restano,
  // così il confronto non scavalca il confine tra due parole.
  //
  // Parametri tutti distinti per evitare HY093: PDO con prepare nativi
  // non gestisce lo stesso named param usato più volte.
  // ----------------------------------------------------------
  private function applyTextFilter(array &$where, array &$params, string $q): void
  {
    $conditions = ['a.title LIKE :q_title', 'ar.name LIKE :q_artist'];
    $params[':q_title']  = '%' . $q . '%';
    $params[':q_artist'] = '%' . $q . '%';

    $compact = str_replace(['.', '/', '-', "'", '’'], '', $q);
    $compact = trim((string)preg_replace('/\s+/u', ' ', $compact));

    if ($compact !== '') {
      $conditions[] = $this->compactSql('a.title') . ' LIKE :q_title_compact';
      $conditions[] = $this->compactSql('ar.name') . ' LIKE :q_artist_compact';
      $params[':q_title_compact']  = '%' . $compact . '%';
      $params[':q_artist_compact'] = '%' . $compact . '%';
    }

    $where[] = '(' . implode(' OR ', $conditions) . ')';
  }

  private function compactSql(string $column): string
  {
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($column, '.', ''), '/', ''), '-', ''), '''', ''), '’', '')";
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
      $this->applyTextFilter($where, $params, (string)$filters['q']);
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
  // Cerca SOLO album già presenti nell'archivio Grizzly, al massimo uno
  // per artista, e riempie i posti a livelli successivi. Un livello entra
  // solo se quelli precedenti non bastano:
  //
  //   1. artisti simili (ListenBrainz), i primi SIMILAR_PRIMARY;
  //   2. stesso genere Grizzly del disco;
  //   3. album il cui genere Grizzly compare tra i generi MusicBrainz
  //      del disco;
  //   4. artisti simili oltre i primi SIMILAR_PRIMARY.
  //
  // Nei livelli dei simili l'ordine combina somiglianza, genere e anno;
  // nei livelli per genere conta la vicinanza d'anno.
  //
  // Misura sull'archivio del 19/11/2026: con i livelli 1-3 il 95% dei
  // dischi riceve 4 consigli, il livello 4 ne completa altri 5. I livelli
  // scartati (generi MusicBrainz dell'artista, simili di secondo grado)
  // portavano consigli fuori tema o troppe chiamate.
  //
  // $similarArtists: righe di AlbumRecommendationService::getSimilarArtists()
  //   [mbid, name, match 0..1, rank], già ordinate.
  // $mbGenres: generi MusicBrainz del release-group del disco.
  // ----------------------------------------------------------
  private const SIMILAR_PRIMARY = 30;

  public function getRecommendations(
    array $similarArtists,
    int $excludeAlbumId,
    int $excludeArtistId,
    ?int $genreId,
    ?int $year,
    int $limit = 4,
    array $mbGenres = []
  ): array {
    $limit = max(1, min(8, $limit));

    $genreSet = [];
    foreach ($mbGenres as $g) {
      $g = $this->normalizeArtistForRecommendation((string)$g);
      if ($g !== '') {
        $genreSet[$g] = true;
      }
    }

    // Album dei simili presenti in archivio: per MBID e, per gli artisti
    // in archivio senza MBID, per nome.
    $byMbid = [];
    $byName = [];
    foreach ($similarArtists as $item) {
      $mbid = strtolower(trim((string)($item['mbid'] ?? '')));
      $name = $this->normalizeArtistForRecommendation((string)($item['name'] ?? ''));
      $info = [
        'match' => max(0.0, min(1.0, (float)($item['match'] ?? 0))),
        'rank'  => (int)($item['rank'] ?? 0),
      ];
      if ($mbid !== '' && !isset($byMbid[$mbid])) {
        $byMbid[$mbid] = $info;
      }
      if ($name !== '' && !isset($byName[$name])) {
        $byName[$name] = $info;
      }
    }

    $primary   = [];
    $secondary = [];

    if (!empty($byMbid) || !empty($byName)) {
      $params = [
        ':exclude_album'  => $excludeAlbumId,
        ':exclude_artist' => $excludeArtistId,
      ];
      $or = [];

      if (!empty($byMbid)) {
        $ph = [];
        $i  = 0;
        foreach (array_keys($byMbid) as $m) {
          $k = ':m' . $i++;
          $ph[] = $k;
          $params[$k] = $m;
        }
        $or[] = 'ar.mb_artist_id IN (' . implode(',', $ph) . ')';
      }
      if (!empty($byName)) {
        $ph = [];
        $i  = 0;
        foreach (array_keys($byName) as $n) {
          $k = ':n' . $i++;
          $ph[] = $k;
          $params[$k] = $n;
        }
        $or[] = "((ar.mb_artist_id IS NULL OR ar.mb_artist_id = '') AND LOWER(TRIM(ar.name)) IN (" . implode(',', $ph) . '))';
      }

      $stmt = $this->db->prepare($this->recommendationSelect() . "
        WHERE a.id <> :exclude_album
          AND a.artist_id <> :exclude_artist
          AND (" . implode(' OR ', $or) . ")
      ");
      foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
      }
      $stmt->execute();

      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mbid = strtolower(trim((string)($row['artist_mbid'] ?? '')));
        $info = ($mbid !== '' && isset($byMbid[$mbid]))
          ? $byMbid[$mbid]
          : ($byName[$this->normalizeArtistForRecommendation((string)$row['artist_name'])] ?? null);
        if ($info === null) {
          continue;
        }

        $score = $info['match'] * 1000.0;
        if ($genreId && (int)$row['genre_id'] === $genreId) {
          $score += 80.0;
        }
        if (!empty($genreSet) && isset($genreSet[$this->normalizeArtistForRecommendation((string)$row['genre_name'])])) {
          $score += 90.0;
        }
        $score += $this->yearBonus($year, $row['year'] ?? null);

        $row['_score'] = $score;
        if ($info['rank'] < self::SIMILAR_PRIMARY) {
          $row['_basis'] = 'similar';
          $primary[] = $row;
        } else {
          $row['_basis'] = 'similar_more';
          $secondary[] = $row;
        }
      }
    }

    $selected    = [];
    $usedArtists = [];

    // 1. simili principali
    $this->fillRecommendations($selected, $usedArtists, $primary, $limit);

    // 2. stesso genere Grizzly
    if (count($selected) < $limit && $genreId) {
      $stmt = $this->db->prepare($this->recommendationSelect() . "
        WHERE a.genre_id = :genre_id
          AND a.id <> :exclude_album
          AND a.artist_id <> :exclude_artist
      ");
      $stmt->execute([
        ':genre_id'       => $genreId,
        ':exclude_album'  => $excludeAlbumId,
        ':exclude_artist' => $excludeArtistId,
      ]);
      $rows = [];
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['_score'] = $this->yearProximity($year, $row['year'] ?? null);
        $row['_basis'] = 'genre';
        $rows[] = $row;
      }
      $this->fillRecommendations($selected, $usedArtists, $rows, $limit);
    }

    // 3. generi MusicBrainz del disco
    if (count($selected) < $limit && !empty($genreSet)) {
      $params = [
        ':exclude_album'  => $excludeAlbumId,
        ':exclude_artist' => $excludeArtistId,
      ];
      $ph = [];
      $i  = 0;
      foreach (array_keys($genreSet) as $g) {
        $k = ':g' . $i++;
        $ph[] = $k;
        $params[$k] = $g;
      }
      $stmt = $this->db->prepare($this->recommendationSelect() . "
        WHERE LOWER(TRIM(g.name)) IN (" . implode(',', $ph) . ")
          AND a.id <> :exclude_album
          AND a.artist_id <> :exclude_artist
      ");
      $stmt->execute($params);
      $rows = [];
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['_score'] = $this->yearProximity($year, $row['year'] ?? null);
        $row['_basis'] = 'mb_genre';
        $rows[] = $row;
      }
      $this->fillRecommendations($selected, $usedArtists, $rows, $limit);
    }

    // 4. simili oltre i primi
    if (count($selected) < $limit) {
      $this->fillRecommendations($selected, $usedArtists, $secondary, $limit);
    }

    foreach ($selected as &$row) {
      unset($row['_score'], $row['artist_mbid']);
    }
    unset($row);

    return $selected;
  }

  /** SELECT comune ai livelli dei consigli. */
  private function recommendationSelect(): string
  {
    return "
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
        ar.mb_artist_id AS artist_mbid,
        g.name AS genre_name
      FROM albums a
      JOIN artists ar ON ar.id = a.artist_id
      LEFT JOIN genres g ON g.id = a.genre_id
    ";
  }

  /**
   * Aggiunge a $selected i candidati di un livello, dal punteggio più alto,
   * un solo album per artista e fino a $limit elementi in totale.
   */
  private function fillRecommendations(array &$selected, array &$usedArtists, array $rows, int $limit): void
  {
    usort($rows, function (array $a, array $b): int {
      if ($a['_score'] == $b['_score']) {
        return strcmp((string)$a['title'], (string)$b['title']);
      }
      return ($a['_score'] < $b['_score']) ? 1 : -1;
    });

    foreach ($rows as $row) {
      if (count($selected) >= $limit) {
        return;
      }
      $artistId = (int)$row['artist_id'];
      if (isset($usedArtists[$artistId])) {
        continue;
      }
      $usedArtists[$artistId] = true;
      $selected[] = $row;
    }
  }

  /**
   * Punteggio dei livelli per genere: stesso calcolo del vecchio ripiego
   * (100 meno 5 punti per anno di distanza).
   */
  private function yearProximity(?int $year, $candidateYear): float
  {
    if (!$year || empty($candidateYear)) {
      return 0.0;
    }
    $diff = abs((int)$candidateYear - $year);
    return max(0.0, 100.0 - min(100, $diff * 5));
  }

  /** Bonus di vicinanza d'anno per i simili (0-60). */
  private function yearBonus(?int $year, $candidateYear): float
  {
    if (!$year || empty($candidateYear)) {
      return 0.0;
    }
    $diff = abs((int)$candidateYear - $year);
    if ($diff <= 1) return 60.0;
    if ($diff <= 3) return 45.0;
    if ($diff <= 5) return 30.0;
    if ($diff <= 10) return 15.0;
    return 0.0;
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

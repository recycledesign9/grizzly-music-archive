<?php

require_once BASE_PATH . '/app/models/AudioFile.php';

class AlbumController
{
  private Album  $albumModel;
  private Artist $artistModel;
  private Track  $trackModel;
  private AudioFile $audioModel;

  // Contatore dei fallimenti HTTP (connessione/5xx) durante il recupero
  // della descrizione Wikipedia: se > 0 l'esito negativo NON viene messo
  // in cache, perché è un problema transitorio di rete, non un "non trovato".
  private int $wikiHttpFailures = 0;

  // Contatore delle richieste HTTP andate a buon fine: un esito negativo
  // viene cachato (TTL breve) SOLO se almeno una richiesta è riuscita,
  // cioè se la rete funzionava e il "non trovato" è credibile.
  private int $wikiHttpSuccesses = 0;

  // Scadenza assoluta (microtime) per l'intero recupero descrizione:
  // superata questa, nessuna nuova richiesta HTTP viene avviata e la
  // risposta torna subito al client. Evita attese infinite lato UI.
  private float $wikiDeadline = 0.0;

  private function wikiTimeLeft(): float
  {
    if ($this->wikiDeadline <= 0.0) return 999.0; // budget non attivo
    return $this->wikiDeadline - microtime(true);
  }

  public function __construct()
  {
    $this->albumModel  = new Album();
    $this->artistModel = new Artist();
    $this->trackModel  = new Track();
    $this->audioModel = new AudioFile();
  }

  public function dispatch(string $action, ?int $id): void
  {
    switch ($action) {

      case 'list':
        $this->list();
        break;

      case 'detail':
        $this->detail($id);
        break;

      case 'create':
        $this->create();
        break;

      case 'edit':
        $this->edit($id);
        break;

      case 'delete':
        $this->delete($id);
        break;

      case 'save':
        $this->save($id);
        break;

      case 'fetch-meta':
        $this->fetchMeta();
        break;
      case 'api_cover':
        $this->apiCover();
        break;

      case 'api-description':
        $this->apiDescription();
        break;

      default:
        $this->list();
        break;
    }
  }

  // ----------------------------------------------------------
  // GET /albums/list
  // ----------------------------------------------------------
  private function list(): void
  {
    $filters = [
      'q'         => trim($_GET['q'] ?? ''),
      'format_id' => (int)($_GET['format_id'] ?? 0),
      'genre_id'  => (int)($_GET['genre_id'] ?? 0),
      'year'      => (int)($_GET['year'] ?? 0),
      'label_id'  => (int)($_GET['label_id'] ?? 0),
    ];

    $allowedOrder = ['a.title', 'ar.name', 'a.year'];
    $order = in_array($_GET['order'] ?? '', $allowedOrder, true)
      ? $_GET['order']
      : 'a.title';

    $dir = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

    $allowedPerPage = [10, 20, 50, 100];

    $perPage = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT);
    if (!$perPage || !in_array($perPage, $allowedPerPage, true)) {
      $perPage = 20;
    }

    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
    if (!$page || $page < 1) {
      $page = 1;
    }

    $totalRecords = $this->albumModel->countAll($filters);
    $totalPages   = max(1, (int)ceil($totalRecords / $perPage));

    if ($page > $totalPages) {
      $page = $totalPages;
    }

    $offset = max(0, ($page - 1) * $perPage);

    $albums  = $this->albumModel->getAll($filters, $order, $dir, $perPage, $offset);
    $formats = $this->albumModel->getFormats();
    $genres  = $this->albumModel->getGenres();
    $labels  = $this->albumModel->getLabels();

    $pagination = [
      'page'        => $page,
      'per_page'    => $perPage,
      'total'       => $totalRecords,
      'total_pages' => $totalPages,
      'from'        => $totalRecords > 0 ? $offset + 1 : 0,
      'to'          => min($offset + $perPage, $totalRecords),
      'allowed_per_page' => $allowedPerPage,
    ];

    require BASE_PATH . '/views/albums/list.php';
  }

  // ----------------------------------------------------------
  // GET /albums/detail/{id}
  // ----------------------------------------------------------
  private function detail(?int $id): void
  {
    if (!$id) {
      $this->redirect('albums/list');
      return;
    }
    $album  = $this->albumModel->getById($id);
    if (!$album) {
      $this->notFound();
      return;
    }
    $tracks     = $this->trackModel->getByAlbum($id);
    $audioFiles = $this->audioModel->getByAlbum($id);

    // Playlist esistenti — usate dal modal "Aggiungi a playlist" nella view
    $db = Database::getInstance();
    $stmtPl = $db->query("SELECT id, name FROM playlists ORDER BY name ASC");
    $userPlaylists = $stmtPl->fetchAll(PDO::FETCH_ASSOC);

    require BASE_PATH . '/views/albums/detail.php';
  }

  // ----------------------------------------------------------
  // GET /albums/create
  // ----------------------------------------------------------
  private function create(): void
  {
    $artists = $this->artistModel->getAll();
    $formats = $this->albumModel->getFormats();
    $genres  = $this->albumModel->getGenres();
    $labels  = $this->albumModel->getLabels();
    $album   = [];
    $tracks  = [];

    $errors = $_SESSION['form_errors'] ?? [];
    $old = $_SESSION['form_old'] ?? [];

    if (empty($errors)) {
      $old = [];
    }

    unset($_SESSION['form_errors'], $_SESSION['form_old']);

    require BASE_PATH . '/views/albums/form.php';
  }

  // ----------------------------------------------------------
  // GET /albums/edit/{id}
  // ----------------------------------------------------------
  private function edit(?int $id): void
  {
    if (!$id) {
      $this->redirect('albums/list');
      return;
    }

    $album = $this->albumModel->getById($id);

    if (!$album) {
      $this->notFound();
      return;
    }

    $tracks  = $this->trackModel->getByAlbum($id);
    $artists = $this->artistModel->getAll();
    $formats = $this->albumModel->getFormats();
    $genres  = $this->albumModel->getGenres();
    $labels  = $this->albumModel->getLabels();

    $errors = $_SESSION['form_errors'] ?? [];
    $old    = !empty($errors) ? ($_SESSION['form_old'] ?? []) : [];

    unset($_SESSION['form_errors'], $_SESSION['form_old']);

    require BASE_PATH . '/views/albums/form.php';
  }
  // ----------------------------------------------------------
  // POST /albums/save — gestisce sia create che update
  // ----------------------------------------------------------
  private function save(?int $id): void
  {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      $this->redirect('albums/list');
      return;
    }

    // CSRF check
    if (
      empty($_POST['csrf_token']) ||
      empty($_SESSION['csrf_token']) ||
      !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
      die('Token CSRF non valido.');
    }

    // Validazione
    $errors = $this->validate($_POST);
    if (!empty($errors)) {
      // Se chiamata AJAX, risponde con JSON
      if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['errors' => $errors]);
        exit;
      }
      $_SESSION['form_errors'] = $errors;
      $_SESSION['form_old']    = $_POST;
      $route = $id ? "albums/edit/$id" : 'albums/create';
      $this->redirect($route);
      return;
    }

    // Gestione artista — stringa libera o id esistente
    $artistId = !empty($_POST['artist_id'])
      ? (int)$_POST['artist_id']
      : $this->artistModel->findOrCreate($_POST['artist_name'] ?? '');

    // Gestione label (crea se nuova)
    $labelId = $this->resolveLabel($_POST);

    // Gestione genre (crea se nuovo)
    $genreId = $this->resolveGenre($_POST);

    // Upload cover
    $coverLocal = null;
    if (!empty($_FILES['cover_file']['tmp_name'])) {
      $coverLocal = $this->uploadCover($_FILES['cover_file']);
    }
    $existing = $id ? $this->albumModel->getById($id) : null;

    $coverLocalNew      = trim($_POST['cover_local_new'] ?? '');
    $coverLocalExisting = trim($_POST['cover_local_existing'] ?? '');

    $coverLocalFinal =
      $coverLocal
      ?: ($coverLocalNew !== '' ? $coverLocalNew : null)
      ?: ($coverLocalExisting !== '' ? $coverLocalExisting : null)
      ?: ($existing['cover_local'] ?? null);

    $yearInput = $_POST['year'] ?? '';

    if ($yearInput === '' && $id) {
      $existing = $existing ?? $this->albumModel->getById($id);
      $yearFinal = $existing['year'] ?? null;
    } else {
      $yearFinal = (int)$yearInput ?: null;
    }

    $data = [
      'artist_id'   => $artistId,
      'genre_id'    => $genreId,
      'label_id'    => $labelId,
      'format_id'   => (int)$_POST['format_id'],
      'title'       => trim($_POST['title']),
      'year' => $yearFinal,
      'condition'   => $_POST['condition']   ?? 'Very Good',
      'copies'      => max(1, (int)($_POST['copies'] ?? 1)),
      'notes'       => trim($_POST['notes']  ?? ''),
      'cover_url' => trim($_POST['cover_url'] ?? '') ?: null,
      // Priorità: 1) file caricato manualmente, 2) scaricata da API, 3) esistente
      'cover_local' => $coverLocalFinal,
      'mbid'        => trim($_POST['mbid'] ?? ''),
    ];

    if ($id) {
      $this->albumModel->update($id, $data);
      $albumId = $id;
    } else {
      $albumId = $this->albumModel->create($data);
    }

    // Invalida la cache delle descrizioni Wikipedia: se artista/titolo
    // sono cambiati (o se prima il disco non aveva ancora una scheda),
    // forza una nuova ricerca alla prossima apertura della pagina invece
    // di mostrare una descrizione vecchia/sbagliata o "non trovata".
    $newArtist = $this->artistModel->getById($artistId)['name'] ?? '';
    $newTitle  = $data['title'];
    $this->invalidateWikiCache($newArtist, $newTitle, (string)($data['mbid'] ?? ''));

    if ($existing) {
      $oldArtist = $this->artistModel->getById($existing['artist_id'])['name'] ?? '';
      $oldTitle  = $existing['title'] ?? '';
      $oldMbid   = (string)($existing['mbid'] ?? '');
      if ($oldArtist !== $newArtist || $oldTitle !== $newTitle || $oldMbid !== ($data['mbid'] ?? '')) {
        $this->invalidateWikiCache($oldArtist, $oldTitle, $oldMbid);
      }
    }

    // Salva tracklist
    $trackIds       = $_POST['track_id']       ?? [];
    $trackTitles    = $_POST['track_title']    ?? [];
    $trackDurations = $_POST['track_duration'] ?? [];

    $tracks = [];

    foreach ($trackTitles as $k => $title) {
      $tracks[] = [
        'id'       => !empty($trackIds[$k]) ? (int)$trackIds[$k] : null,
        'title'    => trim($title),
        'duration' => !empty($trackDurations[$k]) ? (int)$trackDurations[$k] : null,
      ];
    }

    // SALVA SEMPRE (anche in edit)
    $this->trackModel->saveTracklist($albumId, $tracks);

    // Se chiamata AJAX (form intercettato da SPA), risponde con JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
      header('Content-Type: application/json');
      echo json_encode(['redirect' => BASE_URL . '/index.php?route=albums/detail/' . $albumId]);
      exit;
    }

    $this->redirect("albums/detail/$albumId");
  }

  // ----------------------------------------------------------
  // POST /albums/delete/{id}
  // ----------------------------------------------------------
  private function delete(?int $id): void
  {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
      $this->redirect('albums/list');
      return;
    }

    if (
      empty($_POST['csrf_token']) ||
      empty($_SESSION['csrf_token']) ||
      !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
      die('Token CSRF non valido.');
    }

    $album = $this->albumModel->getById($id);
    if ($album) {
      $db = Database::getInstance();

      // Rimuove file audio fisici associati all'album
      $audioStmt = $db->prepare("SELECT filename FROM audio_files WHERE album_id = ?");
      $audioStmt->execute([$id]);
      $audioFilenames = $audioStmt->fetchAll(PDO::FETCH_COLUMN);
      foreach ($audioFilenames as $filename) {
        $audioPath = MediaPathResolver::getAudioAbsPath($filename);
        if (file_exists($audioPath)) {
          unlink($audioPath);
        }
      }

      // Rimuove cover locale
      if ($album['cover_local']) {
        $coverPath = COVERS_PATH . '/' . basename($album['cover_local']);
        if (file_exists($coverPath)) {
          unlink($coverPath);
        }
      }

      // Elimina album dal DB (audio_files, tracks, playlist_tracks
      // vengono rimossi automaticamente dalle FK ON DELETE CASCADE)
      $this->albumModel->delete($id);
    }

    // Se chiamata AJAX risponde con JSON invece di fare redirect
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
      header('Content-Type: application/json');
      echo json_encode(['ok' => true]);
      exit;
    }

    $this->redirect('albums/list');
  }

  // ----------------------------------------------------------
  // Helpers
  // ----------------------------------------------------------
  private function validate(array $post): array
  {
    $errors = [];
    if (empty(trim($post['title'] ?? ''))) {
      $errors['title'] = 'Il titolo è obbligatorio.';
    }
    if (empty($post['format_id'])) {
      $errors['format_id'] = 'Seleziona un formato.';
    }
    if (empty($post['artist_id']) && empty(trim($post['artist_name'] ?? ''))) {
      $errors['artist'] = 'Inserisci il nome artista.';
    }
    if (!empty($post['year']) && ($post['year'] < 1900 || $post['year'] > (int)date('Y') + 1)) {
      $errors['year'] = 'Anno non valido.';
    }
    return $errors;
  }

  private function resolveLabel(array $post): ?int
  {
    if (!empty($post['label_id'])) return (int)$post['label_id'];
    if (!empty(trim($post['label_new'] ?? ''))) {
      $name = trim($post['label_new']);
      $db   = Database::getInstance();
      $stmt = $db->prepare("INSERT IGNORE INTO labels (name) VALUES (:name)");
      $stmt->execute([':name' => $name]);
      $id   = $db->lastInsertId();
      if (!$id) {
        $s = $db->prepare("SELECT id FROM labels WHERE name = :name");
        $s->execute([':name' => $name]);
        $id = $s->fetchColumn();
      }
      return (int)$id ?: null;
    }
    return null;
  }

  private function resolveGenre(array $post): ?int
  {
    if (!empty($post['genre_id'])) return (int)$post['genre_id'];
    if (!empty(trim($post['genre_new'] ?? ''))) {
      $name = trim($post['genre_new']);
      $db   = Database::getInstance();
      $stmt = $db->prepare("INSERT IGNORE INTO genres (name) VALUES (:name)");
      $stmt->execute([':name' => $name]);
      $id   = $db->lastInsertId();
      if (!$id) {
        $s = $db->prepare("SELECT id FROM genres WHERE name = :name");
        $s->execute([':name' => $name]);
        $id = $s->fetchColumn();
      }
      return (int)$id ?: null;
    }
    return null;
  }

  private function uploadCover(array $file): ?string
  {
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($file['type'], $allowed)) return null;
    if ($file['size'] > MAX_COVER_SIZE)     return null;
    $ext  = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = bin2hex(random_bytes(8)) . '.' . strtolower($ext);
    $dest = COVERS_PATH . '/' . $name;
    if (!is_dir(COVERS_PATH)) mkdir(COVERS_PATH, 0755, true);
    if (move_uploaded_file($file['tmp_name'], $dest)) {
      return 'covers/' . $name;
    }
    return null;
  }

  private function redirect(string $route): void
  {
    header('Location: ' . BASE_URL . '/index.php?route=' . $route);
    exit;
  }

  private function notFound(): void
  {
    http_response_code(404);
    $errorContext = 'disco';
    require BASE_PATH . '/views/errors/404.php';
    exit;
  }

  private function fetchMeta(): void
  {
    ini_set('display_errors', 0);
    error_reporting(0);

    // Pulisce eventuale output sporco
    while (ob_get_level()) ob_end_clean();
    ob_start();

    header('Content-Type: application/json');

    try {
      if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
      ) {
        echo json_encode(['error' => 'Token non valido']);
        exit;
      }

      $artist = trim($_POST['artist'] ?? '');
      $title  = trim($_POST['title']  ?? '');
      $year   = (int)($_POST['year']  ?? 0);

      if (!$artist || !$title) {
        echo json_encode(['error' => 'Parametri mancanti']);
        exit;
      }

      require_once BASE_PATH . '/app/services/AlbumMetadataService.php';
      $service = new AlbumMetadataService();
      $data    = $service->search($artist, $title, $year);

      // Cover
      if (empty($data['cover_local']) && !empty($data['cover'])) {
        $local = $service->downloadCover($data['cover']);
        if ($local) {
          $data['cover_local'] = $local;
        }
      }

      // Preview
      if (!empty($data['cover_local'])) {
        $data['cover_preview'] = BASE_URL . '/public/uploads/' . $data['cover_local'];
      } else {
        $data['cover_preview'] = $data['cover'] ?? '';
      }

      echo json_encode($data);
      exit;
    } catch (Throwable $e) {
      echo json_encode([
        'error' => $e->getMessage()
      ]);
      exit;
    }
  }

  // ----------------------------------------------------------
  // GET /albums/api-description?artist=...&album=...&lang=it|en[&mbid=...]
  //
  // STRATEGIA A DUE LIVELLI:
  //  1. Se l'album ha un MBID → percorso DETERMINISTICO:
  //     MusicBrainz release → release-group → link Wikidata →
  //     sitelink Wikipedia nella lingua richiesta → estratto per
  //     titolo esatto. Nessuna ricerca fulltext, nessuna euristica,
  //     nessuna omonimia possibile: la pagina è quella collegata
  //     ufficialmente al disco nel database MusicBrainz.
  //  2. Senza MBID (o se la catena non produce nulla) → fallback
  //     alla vecchia ricerca euristica su Wikipedia.
  // ----------------------------------------------------------
  private function apiDescription(): void
  {
    header('Content-Type: application/json; charset=utf-8');

    // FONDAMENTALE: rilascia SUBITO il lock di sessione. Questo endpoint
    // fa I/O esterno lento (Wikipedia, MusicBrainz): senza questa riga
    // terrebbe bloccata OGNI altra richiesta dell'app (navigazione, AJAX,
    // player) finché non ha finito. Convenzione di progetto per tutti gli
    // endpoint con I/O esterno.
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }

    try {
      $artist = trim($_GET['artist'] ?? '');
      $album  = trim($_GET['album']  ?? '');
      $mbid   = trim($_GET['mbid']   ?? '');
      $lang   = in_array($_GET['lang'] ?? 'it', ['it', 'en'], true)
                  ? ($_GET['lang'] ?? 'it')
                  : 'it';

      // MBID valido = UUID; qualunque altra cosa viene ignorata
      if ($mbid !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $mbid)) {
        $mbid = '';
      }

      if (!$artist || !$album) {
        echo json_encode(['error' => 'Parametri mancanti']);
        exit;
      }

      // La chiave cache privilegia l'MBID: è stabile anche se l'utente
      // corregge maiuscole/accenti nel titolo o nel nome artista.
      $cacheKey = $mbid !== ''
        ? 'mbid:' . strtolower($mbid) . '|' . $lang
        : strtolower($artist) . '|' . strtolower($album) . '|' . $lang;

      $debug    = isset($_GET['debug']) && $_GET['debug'] === '1';
      // force=1: bypassa SOLO la lettura della cache (forza la ricerca)
      // ma scrive comunque il risultato nuovo in cache — usato dal pulsante
      // "Rinnova" sulla scheda disco, per aggiornare un singolo album senza
      // svuotare tutta la cache.
      $force    = isset($_GET['force']) && $_GET['force'] === '1';

      if (!$debug && !$force) {
        $cached = $this->getWikiCache($cacheKey);
        if ($cached !== null) {
          echo json_encode($cached);
          exit;
        }
      }

      $result = null;
      $this->wikiHttpFailures  = 0;
      $this->wikiHttpSuccesses = 0;

      // BUDGET TOTALE: 6 secondi per l'intero recupero, qualunque cosa
      // accada. Superato il budget, nessuna nuova richiesta parte e la
      // risposta torna al client: il box mostra l'esito invece di girare
      // all'infinito.
      $this->wikiDeadline = microtime(true) + 6.0;

      // ---- LIVELLO 1: titoli esatti in UNA richiesta batch (il più
      //      veloce e affidabile: le pagine album seguono la convenzione
      //      "Titolo (Artista album)"). Prima la lingua richiesta, poi
      //      cross-lingua sull'altra: tutto lato server, un solo giro.
      $result = $this->fetchWikipediaBatchedTitles($artist, $album, $lang, $debug);

      if (empty($result['description']) && $this->wikiTimeLeft() > 0.8) {
        $otherLang = ($lang === 'it') ? 'en' : 'it';
        $cross = $this->fetchWikipediaBatchedTitles($artist, $album, $otherLang, $debug);
        if ($debug) {
          $cross['debug'] = array_merge($result['debug'] ?? [], $cross['debug'] ?? []);
        }
        if (!empty($cross['description'])) {
          $result = $cross;
        } elseif ($debug) {
          $result['debug'] = $cross['debug'];
        }
      }

      // ---- LIVELLO 2: percorso MBID → Wikidata (già cross-lingua).
      //      Tocca MusicBrainz (lento e rate-limitato): solo se il
      //      livello 1 non ha risolto. I sitelink risolti vengono cachati
      //      per MBID, quindi questa salita avviene UNA volta per disco.
      if (empty($result['description']) && $mbid !== '' && $this->wikiTimeLeft() > 1.0) {
        $lvl2 = $this->fetchWikipediaViaMbid($mbid, $lang, $debug);
        if ($debug) {
          $lvl2['debug'] = array_merge($result['debug'] ?? [], $lvl2['debug'] ?? []);
        }
        if (!empty($lvl2['description'])) {
          $result = $lvl2;
        } elseif ($debug) {
          $result['debug'] = $lvl2['debug'];
        }
      }

      // ---- LIVELLO 3: euristica fulltext (ultima spiaggia), con
      //      controlli di budget interni ad ogni passo.
      if (empty($result['description']) && $this->wikiTimeLeft() > 1.0) {
        $lvl3 = $this->fetchWikipediaDescription($artist, $album, $lang, $debug);
        if ($debug) {
          $lvl3['debug'] = array_merge($result['debug'] ?? [], $lvl3['debug'] ?? []);
        }
        $result = $lvl3;
      }

      if (empty($result)) {
        $result = ['description' => null, 'lang' => $lang];
      }

      if (!$debug) {
        if (!empty($result['description'])) {
          // Esito positivo: cache lunga
          $this->setWikiCache($cacheKey, $result, 60 * 60 * 24 * 30);
        } elseif ($this->wikiHttpSuccesses > 0) {
          // "Non trovato" credibile: almeno una richiesta HTTP è riuscita,
          // quindi la rete funzionava. Cache breve: se la pagina viene
          // creata in seguito, la ricerca riparte dopo pochi giorni.
          $this->setWikiCache($cacheKey, $result, 60 * 60 * 24 * 3);
        } else {
          // BLACKOUT TOTALE (nessuna richiesta riuscita): non cachare,
          // era un problema di rete, non un "non trovato".
          $result['transient'] = true;
          $result['http_failures'] = $this->wikiHttpFailures;
        }
      }

      echo json_encode($result);
    } catch (Throwable $e) {
      echo json_encode(['error' => $e->getMessage()]);
    }

    exit;
  }

  // ----------------------------------------------------------
  // Cache su file per le descrizioni Wikipedia.
  // Evita di rifare ogni volta la ricerca lenta (più round-trip
  // HTTP sequenziali) quando l'utente apre/ricarica la stessa
  // scheda album.
  // ----------------------------------------------------------
  private function wikiCacheDir(): string
  {
    $dir = BASE_PATH . '/cache/wiki';
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    return $dir;
  }

  private function wikiCacheFile(string $key): string
  {
    return $this->wikiCacheDir() . '/' . sha1($key) . '.json';
  }

  private function getWikiCache(string $key): ?array
  {
    $file = $this->wikiCacheFile($key);
    if (!is_file($file)) {
      return null;
    }

    $raw  = @file_get_contents($file);
    $data = $raw ? json_decode($raw, true) : null;

    if (!is_array($data) || !isset($data['_expires'], $data['_payload'])) {
      return null;
    }

    if (time() > $data['_expires']) {
      return null; // scaduta
    }

    return $data['_payload'];
  }

  private function setWikiCache(string $key, array $payload, int $ttlSeconds): void
  {
    $file = $this->wikiCacheFile($key);
    $data = [
      '_expires' => time() + $ttlSeconds,
      '_payload' => $payload,
    ];
    @file_put_contents($file, json_encode($data), LOCK_EX);
  }

  // Cancella le cache IT ed EN per una coppia artista/album (e, se
  // fornito, per l'MBID), es. dopo il salvataggio del disco.
  private function invalidateWikiCache(string $artist, string $album, string $mbid = ''): void
  {
    $keys = [];
    foreach (['it', 'en'] as $lang) {
      if ($artist !== '' && $album !== '') {
        $keys[] = strtolower($artist) . '|' . strtolower($album) . '|' . $lang;
      }
      if ($mbid !== '') {
        $keys[] = 'mbid:' . strtolower($mbid) . '|' . $lang;
      }
    }
    foreach ($keys as $key) {
      $file = $this->wikiCacheFile($key);
      if (is_file($file)) {
        @unlink($file);
      }
    }
  }

  // ----------------------------------------------------------
  // Esegue una GET verso Wikipedia usando cURL invece di
  // file_get_contents(): su stack PHP/OpenSSL datati (come MAMP
  // con PHP 7.4 + OpenSSL 1.0.2o) file_get_contents fallisce in
  // modo intermittente l'handshake TLS verso siti moderni, mentre
  // cURL gestisce meglio le versioni TLS e i certificati. Ritorna
  // [body, httpCode, error] — error è popolato solo se la
  // richiesta fallisce a livello di connessione (non per HTTP 4xx/5xx,
  // che vengono comunque restituiti come body+code).
  // ----------------------------------------------------------
  private function httpGetWiki(string $url, string $userAgent, int $timeoutSeconds = 5): array
  {
    // BUDGET: se il tempo totale è esaurito, non partire nemmeno.
    // Non conta come fallimento di rete: è un limite nostro.
    $left = $this->wikiTimeLeft();
    if ($left < 0.5) {
      return [null, 0, 'budget esaurito'];
    }
    // Il timeout della singola richiesta non può superare il budget residuo
    $timeoutSeconds = max(1, min($timeoutSeconds, (int)ceil($left)));

    if (!function_exists('curl_init')) {
      // Fallback estremo se cURL non è disponibile (raro)
      $ctx = stream_context_create([
        'http' => [
          'header'        => "User-Agent: {$userAgent}\r\nAccept: application/json",
          'timeout'       => $timeoutSeconds,
          'ignore_errors' => true,
        ],
      ]);
      $body = @file_get_contents($url, false, $ctx);
      if ($body === false) {
        $this->wikiHttpFailures++;
        return [null, 0, 'file_get_contents failed (no curl available)'];
      }
      $this->wikiHttpSuccesses++;
      return [$body, 200, ''];
    }

    // Primo tentativo: verifica SSL completa (comportamento corretto).
    [$body, $code, $error, $errno] = $this->curlGet($url, $userAgent, $timeoutSeconds, true);

    // MAMP (PHP 7.4 + OpenSSL/cURL datati) spesso NON ha un CA bundle
    // configurato (curl.cainfo vuoto in php.ini): in quel caso OGNI
    // richiesta HTTPS fallisce con errno 60 (peer certificate) o 77
    // (CA bundle non leggibile), e la sezione descrizioni resta muta
    // per TUTTI gli album pur con la rete perfettamente funzionante.
    // In tal caso — e SOLO in tal caso — ritenta senza verifica peer:
    // accettabile per letture pubbliche da Wikipedia/MusicBrainz in
    // ambiente di sviluppo locale.
    $sslErrnos = [35, 51, 58, 60, 77, 83]; // famiglie di errori SSL/CA di cURL
    if ($body === null && in_array($errno, $sslErrnos, true)) {
      [$body, $code, $error2, ] = $this->curlGet($url, $userAgent, $timeoutSeconds, false);
      if ($body !== null) {
        // Riuscito senza verifica: segnala nel log errori (una volta per
        // richiesta) così il problema del CA bundle resta visibile.
        error_log('[wiki-desc] SSL verify fallita (' . $error . ') — retry senza verifica riuscito per ' . $url);
        $error = '';
      } else {
        $error = $error . ' | retry no-verify: ' . $error2;
      }
    }

    // RATE LIMIT / servizio momentaneamente saturo (tipico di MusicBrainz,
    // che ammette ~1 richiesta/secondo per IP e risponde 503 alle raffiche):
    // attende un secondo abbondante e ritenta UNA volta — ma solo se il
    // budget residuo lo consente.
    if ($body !== null && in_array($code, [429, 503], true) && $this->wikiTimeLeft() > 2.0) {
      usleep(1200000); // 1,2 s
      [$body2, $code2, , ] = $this->curlGet($url, $userAgent, $timeoutSeconds, true);
      if ($body2 !== null && $code2 < 500 && $code2 !== 429) {
        $body = $body2;
        $code = $code2;
      } else {
        error_log('[wiki-desc] HTTP ' . $code . ' persistente (rate limit?) per ' . $url);
      }
    }

    if ($body === null) {
      $this->wikiHttpFailures++;
      error_log('[wiki-desc] richiesta fallita (' . ($error ?: 'errore sconosciuto') . ') per ' . $url);
      return [null, $code, $error ?: 'curl_exec returned false'];
    }

    if ($code >= 500 || $code === 429) {
      $this->wikiHttpFailures++;
    } else {
      // Risposta arrivata (2xx-4xx): la rete funziona.
      $this->wikiHttpSuccesses++;
    }

    return [$body, $code, ''];
  }

  // Esegue la singola GET cURL. Ritorna [body|null, httpCode, error, errno].
  private function curlGet(string $url, string $userAgent, int $timeoutSeconds, bool $verifySsl): array
  {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => ["User-Agent: {$userAgent}", 'Accept: application/json'],
      CURLOPT_TIMEOUT        => $timeoutSeconds,
      CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
      CURLOPT_SSL_VERIFYPEER => $verifySsl,
      CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS      => 3,
      CURLOPT_ENCODING       => '', // accetta gzip: risposte Wikipedia molto più rapide
    ]);

    $body  = curl_exec($ch);
    $error = curl_error($ch);
    $errno = (int) curl_errno($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$body === false ? null : $body, $code, $error, $errno];
  }

  // ----------------------------------------------------------
  // PERCORSO DETERMINISTICO — dall'MBID alla pagina Wikipedia
  //
  // Catena: release MBID → release-group → relazione "wikidata"
  // → entità Wikidata → sitelink itwiki/enwiki → estratto per
  // TITOLO ESATTO. Nessuna ricerca fulltext, nessuna blacklist:
  // il collegamento è curato a mano dalla community MusicBrainz/
  // Wikidata, quindi la pagina è per definizione quella giusta.
  //
  // Esempio reale: Slip (Quicksand) → release-group
  // 24f9fe37-… → Wikidata Q7540796 → enwiki "Slip (album)".
  // ----------------------------------------------------------
  private function fetchWikipediaViaMbid(string $mbid, string $lang, bool $debug = false): array
  {
    $ua       = defined('APP_USER_AGENT') ? APP_USER_AGENT : 'GrizzlyMusicArchive/1.0';
    $debugLog = [];
    $fail     = function (string $step, array $extra = []) use (&$debugLog, $lang, $debug) {
      if ($debug) $debugLog[] = array_merge(['step' => 'mbid-path', 'fail_at' => $step], $extra);
      $out = ['description' => null, 'lang' => $lang];
      if ($debug) $out['debug'] = $debugLog;
      return $out;
    };

    $otherLang = ($lang === 'it') ? 'en' : 'it';

    // CACHE SITELINK: la catena MusicBrainz→Wikidata è costosa (2-3
    // richieste, con MB rate-limitato a ~1/s). I titoli pagina risolti
    // per questo MBID vengono cachati: le richieste successive (inclusa
    // l'altra lingua) saltano MusicBrainz del tutto.
    $slKey  = 'sitelinks:' . strtolower($mbid);
    $titles = $this->getWikiCache($slKey); // ['it' => titolo|'', 'en' => titolo|'']

    if (!is_array($titles)) {
      $titles = $this->resolveMbidSitelinks($mbid, $ua, $debugLog, $debug);
      if ($titles === null) {
        return $fail('mb-chain'); // errore HTTP nella catena: non cachare
      }
      // Catena completata (anche se senza sitelink): cache 30 giorni
      $this->setWikiCache($slKey, $titles, 60 * 60 * 24 * 30);
    } elseif ($debug) {
      $debugLog[] = ['step' => 'sitelinks-cache-hit', 'titles' => $titles];
    }

    $pageTitle = $titles[$lang] ?? '';
    $pageLang  = $lang;
    if ($pageTitle === '' && !empty($titles[$otherLang])) {
      // Cross-lingua: meglio una descrizione in inglese subito che nessuna
      $pageTitle = $titles[$otherLang];
      $pageLang  = $otherLang;
    }

    if ($pageTitle === '') return $fail('no-sitelink');

    // --- Estratto per titolo esatto (con redirect) ---
    $code = 0;
    $err  = '';
    $extract = $this->wikiExtractByTitle($pageTitle, $pageLang, $ua, $code, $err);
    if ($debug) {
      $debugLog[] = ['step' => 'wiki-extract', 'http_code' => $code, 'curl_error' => $err,
                     'extract_length' => strlen($extract)];
    }
    if ($extract === '') return $fail('empty-extract');

    // Nessun filtro euristico qui: la pagina è certificata dalla catena
    // MusicBrainz→Wikidata, quindi l'estratto è per definizione corretto.
    $text = preg_replace("/\n{3,}/", "\n\n", $extract);
    $text = trim($text);

    $out = [
      'description' => $text,
      'lang'        => $pageLang,
      'page_title'  => $pageTitle,
      'wiki_url'    => 'https://' . $pageLang . '.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $pageTitle)),
      'source'      => 'mbid',
    ];
    if ($debug) $out['debug'] = $debugLog;
    return $out;
  }

  // Recupera l'estratto introduttivo di una pagina Wikipedia dato il
  // TITOLO ESATTO (segue i redirect). Ritorna '' se la pagina non esiste
  // o l'estratto è vuoto. $httpCode/$httpErr riportano l'esito HTTP.
  private function wikiExtractByTitle(string $pageTitle, string $lang, string $ua, ?int &$httpCode = null, ?string &$httpErr = null): string
  {
    $url = 'https://' . $lang . '.wikipedia.org/w/api.php'
         . '?action=query&prop=extracts&exintro=1&explaintext=1&redirects=1'
         . '&titles=' . urlencode($pageTitle)
         . '&format=json&utf8=1';
    [$raw, $httpCode, $httpErr] = $this->httpGetWiki($url, $ua);
    $data  = $raw ? json_decode($raw, true) : null;
    $pages = $data['query']['pages'] ?? [];
    $page  = reset($pages);
    return trim($page['extract'] ?? '');
  }

  // Risolve la catena release MBID → release-group → Wikidata → sitelink
  // e ritorna ['it' => titolo|'', 'en' => titolo|''].
  // Ritorna NULL se un errore HTTP ha impedito di completare la catena
  // (in tal caso il risultato NON va cachato).
  private function resolveMbidSitelinks(string $mbid, string $ua, array &$debugLog, bool $debug): ?array
  {
    // --- 1. release → release-group ---
    $url = 'https://musicbrainz.org/ws/2/release/' . rawurlencode($mbid)
         . '?inc=release-groups&fmt=json';
    [$raw, $code, $err] = $this->httpGetWiki($url, $ua);
    $data = $raw ? json_decode($raw, true) : null;
    if ($debug) {
      $debugLog[] = ['step' => 'mb-release', 'http_code' => $code, 'curl_error' => $err,
                     'rg_found' => !empty($data['release-group']['id'])];
    }
    if ($raw === null || $code >= 500 || $code === 429) return null; // errore rete
    $rgId = $data['release-group']['id'] ?? '';
    if ($rgId === '') {
      // Risposta valida ma release inesistente (es. MBID obsoleto): esito
      // definitivo, cachabile come "nessun sitelink".
      return ['it' => '', 'en' => ''];
    }

    // --- 2. release-group → relazioni URL (wikidata / wikipedia) ---
    $url = 'https://musicbrainz.org/ws/2/release-group/' . rawurlencode($rgId)
         . '?inc=url-rels&fmt=json';
    [$raw, $code, $err] = $this->httpGetWiki($url, $ua);
    $data = $raw ? json_decode($raw, true) : null;
    if ($raw === null || $code >= 500 || $code === 429) return null;

    $qid        = '';  // entità Wikidata (es. Q7540796)
    $titles     = ['it' => '', 'en' => ''];
    foreach (($data['relations'] ?? []) as $rel) {
      $type   = $rel['type'] ?? '';
      $relUrl = $rel['url']['resource'] ?? '';
      if ($type === 'wikidata' && preg_match('~wikidata\.org/wiki/(Q\d+)~', $relUrl, $m)) {
        $qid = $m[1];
      }
      // Link wikipedia diretti (relazione legacy)
      if ($type === 'wikipedia' && preg_match('~https?://(it|en)\.wikipedia\.org/wiki/(.+)$~', $relUrl, $m)) {
        $titles[$m[1]] = urldecode(str_replace('_', ' ', $m[2]));
      }
    }
    if ($debug) {
      $debugLog[] = ['step' => 'mb-rg-rels', 'http_code' => $code, 'curl_error' => $err,
                     'wikidata' => $qid];
    }

    // --- 3. Wikidata → sitelink it+en in una chiamata ---
    if ($qid !== '' && ($titles['it'] === '' || $titles['en'] === '')) {
      $url = 'https://www.wikidata.org/w/api.php?action=wbgetentities'
           . '&ids=' . rawurlencode($qid)
           . '&props=sitelinks&sitefilter=' . rawurlencode('itwiki|enwiki')
           . '&format=json';
      [$raw, $code, $err] = $this->httpGetWiki($url, $ua);
      $data = $raw ? json_decode($raw, true) : null;
      if ($raw === null || $code >= 500 || $code === 429) return null;

      $sitelinks = $data['entities'][$qid]['sitelinks'] ?? [];
      if ($titles['it'] === '' && !empty($sitelinks['itwiki']['title'])) {
        $titles['it'] = $sitelinks['itwiki']['title'];
      }
      if ($titles['en'] === '' && !empty($sitelinks['enwiki']['title'])) {
        $titles['en'] = $sitelinks['enwiki']['title'];
      }
      if ($debug) {
        $debugLog[] = ['step' => 'wikidata-sitelink', 'http_code' => $code,
                       'curl_error' => $err, 'titles' => $titles];
      }
    }

    return $titles;
  }

  // ----------------------------------------------------------
  // LIVELLO 1 — Titoli esatti in UNA richiesta batch
  //
  // Le pagine Wikipedia degli album seguono una convenzione di naming
  // stabile: "Titolo (Artista album)" quando serve disambiguare,
  // "Titolo (album)" o semplicemente "Titolo" altrimenti.
  // Es.: "Songs of Praise (Shame album)", "Slip (album)".
  // Tutti i candidati vengono richiesti in UNA SOLA chiamata API
  // (titles=A|B|C con redirect): una richiesta, ~300 ms, e la stragrande
  // maggioranza dei dischi è risolta. Ogni estratto viene validato
  // (artista citato, termine da disco, non bio/singolo/disambigua) e
  // vince il candidato con priorità più alta.
  // ----------------------------------------------------------
  private function fetchWikipediaBatchedTitles(string $artist, string $album, string $lang, bool $debug = false): array
  {
    $ua       = defined('APP_USER_AGENT') ? APP_USER_AGENT : 'GrizzlyMusicArchive/1.0';
    $debugLog = [];

    // Varianti di capitalizzazione dell'artista: i titoli MediaWiki sono
    // case-sensitive (tranne la prima lettera), e band come "shame"
    // stilizzate in minuscolo possono essere archiviate in entrambi i modi.
    $artistVariants = array_values(array_unique([
      $artist,
      ucwords(strtolower($artist)),
    ]));

    $candidates = [];
    foreach ($artistVariants as $a) {
      $candidates[] = $album . ' (' . $a . ' album)';
    }
    $candidates[] = $album . ' (album)';
    foreach ($artistVariants as $a) {
      $candidates[] = $album . ' (' . $a . ')';
    }
    $candidates[] = $album;
    // Il separatore | non può comparire nei titoli MediaWiki: per sicurezza
    // scarta candidati che lo contengano (titolo album anomalo).
    $candidates = array_values(array_unique(array_filter($candidates, function ($t) {
      return strpos($t, '|') === false;
    })));

    $url = 'https://' . $lang . '.wikipedia.org/w/api.php'
         . '?action=query&prop=extracts&exintro=1&explaintext=1&exlimit=20&redirects=1'
         . '&titles=' . urlencode(implode('|', $candidates))
         . '&format=json&utf8=1';

    [$raw, $code, $err] = $this->httpGetWiki($url, $ua);
    $data = $raw ? json_decode($raw, true) : null;

    if ($debug) {
      $debugLog[] = ['step' => 'batched-titles', 'lang' => $lang,
                     'candidates' => $candidates,
                     'http_code' => $code, 'curl_error' => $err,
                     'pages_returned' => count($data['query']['pages'] ?? [])];
    }

    $out = ['description' => null, 'lang' => $lang];
    if (empty($data['query']['pages'])) {
      if ($debug) $out['debug'] = $debugLog;
      return $out;
    }

    // Mappa ogni candidato al titolo FINALE della pagina, seguendo le
    // catene di normalizzazione e redirect riportate dall'API.
    $normalized = [];
    foreach (($data['query']['normalized'] ?? []) as $n) {
      $normalized[$n['from']] = $n['to'];
    }
    $redirects = [];
    foreach (($data['query']['redirects'] ?? []) as $r) {
      $redirects[$r['from']] = $r['to'];
    }

    $finalTitleOf = function (string $t) use ($normalized, $redirects): string {
      if (isset($normalized[$t])) $t = $normalized[$t];
      $hops = 0;
      while (isset($redirects[$t]) && $hops < 5) {
        $t = $redirects[$t];
        $hops++;
      }
      return $t;
    };

    // Estratti per titolo finale (le pagine mancanti non hanno extract)
    $extractByTitle = [];
    foreach ($data['query']['pages'] as $page) {
      $t = $page['title'] ?? '';
      $e = trim($page['extract'] ?? '');
      if ($t !== '' && $e !== '') {
        $extractByTitle[$t] = $e;
      }
    }

    // Valuta i candidati IN ORDINE DI PRIORITÀ: il primo valido vince.
    foreach ($candidates as $cand) {
      $pageTitle = $finalTitleOf($cand);
      $extract   = $extractByTitle[$pageTitle] ?? '';
      if ($extract === '') continue;

      if (!$this->isValidAlbumExtract($extract, $artist)) {
        if ($debug) $debugLog[] = ['step' => 'batched-validate', 'title' => $pageTitle, 'valid' => false];
        continue;
      }

      $text = preg_replace("/\n{3,}/", "\n\n", $extract);
      $text = trim($text);

      $out = [
        'description' => $text,
        'lang'        => $lang,
        'page_title'  => $pageTitle,
        'wiki_url'    => 'https://' . $lang . '.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $pageTitle)),
        'source'      => 'exact-title',
      ];
      if ($debug) $out['debug'] = $debugLog;
      return $out;
    }

    if ($debug) $out['debug'] = $debugLog;
    return $out;
  }

  // Valida un estratto come "pagina di un album di questo artista":
  // niente disambigue, niente bio/singoli (blacklist condivisa), deve
  // contenere un termine da disco e citare l'artista.
  private function isValidAlbumExtract(string $extract, string $artist): bool
  {
    $extractLower = strtolower($extract);

    if (stripos($extract, 'may refer to') !== false
        || stripos($extract, 'può riferirsi') !== false) {
      return false;
    }

    $intro = substr($extractLower, 0, 250);
    foreach ($this->wikiRejectMarkers() as $marker) {
      if (strpos($intro, $marker) !== false) {
        return false;
      }
    }

    if (strpos($extractLower, 'album') === false
        && strpos($extractLower, 'disco') === false
        && strpos($extractLower, 'ep ') === false
        && !preg_match('/\blp\b/', $extractLower)) {
      return false;
    }

    if (stripos($extract, $artist) === false) {
      return false;
    }

    return true;
  }

  // Frasi che identificano pagine DA SCARTARE (bio artista, singolo,
  // brano, canzone): condivise tra livello 2 e livello 3.
  private function wikiRejectMarkers(): array
  {
    return [
      // Biografia artista
      'è un gruppo musicale', 'è una band', 'è un cantante', 'è una cantante',
      'è un complesso', 'sono un gruppo', 'è un musicista', 'è un duo',
      'is a band', 'is an american', 'is a british', 'is an english',
      'are an american', 'are a british', 'is a singer', 'is a musician',
      'is a rock band', 'is a musical group', 'formatasi', 'formatosi',
      'formed in', 'is a singer-songwriter',
      // Singolo / brano / canzone
      'è un singolo', 'è una canzone', 'è un brano', 'è il singolo',
      'is a single', 'is a song', 'is the lead single', 'is the debut single',
      'is the second single', 'is the third single',
    ];
  }

  // ----------------------------------------------------------
  // Cerca su Wikipedia la pagina dell'album e ne estrae l'intro
  // Strategia: prova "Artista Album (album)" → "Artista Album"
  // ----------------------------------------------------------
  private function fetchWikipediaDescription(string $artist, string $album, string $lang, bool $debug = false): array
  {
    $ua  = defined('APP_USER_AGENT') ? APP_USER_AGENT : 'GrizzlyMusicArchive/1.0';

    $debugLog = [];

    // Candidati da provare in ordine — i primi forzano la pagina "album"
    $candidates = [
      $album . ' (' . strtolower($artist) . ' album)',
      $album . ' (album)',
      $album . ' (' . $artist . ')',
      $artist . ' ' . $album,
      $album,
    ];

    $base = 'https://' . $lang . '.wikipedia.org/w/api.php';

    // Frasi che indicano una pagina DA SCARTARE:
    // biografia artista, oppure singolo / brano / canzone
    $rejectMarkers = $this->wikiRejectMarkers();

    // Una pagina è considerata un ALBUM se NON è stata scartata dalla
    // blacklist sopra E contiene la parola "album"/"disco" nel testo.
    // (la verifica avviene più sotto, dopo aver letto l'estratto)

    foreach ($candidates as $query) {
      // Budget esaurito: fermati subito, la risposta deve tornare al client
      if ($this->wikiTimeLeft() < 0.8) break;
      // 1. Cerca il titolo esatto della pagina
      $searchUrl = $base
        . '?action=query&list=search&srsearch=' . urlencode($query)
        . '&srlimit=5&format=json&utf8=1';

      $raw  = null;
      $data = null;
      $httpCode = 0;
      $curlError = '';
      [$raw, $httpCode, $curlError] = $this->httpGetWiki($searchUrl, $ua);
      $data = $raw ? json_decode($raw, true) : null;

      if ($debug) {
        $debugLog[] = [
          'step'        => 'search',
          'query'       => $query,
          'http_code'   => $httpCode,
          'curl_error'  => $curlError,
          'raw_length'  => $raw === null ? false : strlen($raw),
          'json_valid'  => $data !== null,
          'hits_found'  => count($data['query']['search'] ?? []),
        ];
      }

      $hits = $data['query']['search'] ?? [];
      if (empty($hits)) continue;

      $albumLower  = strtolower($album);
      $artistLower = strtolower($artist);

      // Normalizza un titolo Wikipedia rimuovendo i suffissi tra parentesi
      // es. "Korn (album)" -> "korn", "Issues (Korn) -> "issues"
      $normalizeTitle = function (string $t): string {
        $t = preg_replace('/\s*\([^)]*\)\s*/', ' ', $t); // togli (…)
        $t = strtolower(trim($t));
        $t = preg_replace('/\s+/', ' ', $t);
        return $t;
      };
      $albumNorm = $normalizeTitle($album);

      // Prova ogni risultato finché non ne troviamo uno che è davvero un album
      foreach ($hits as $hit) {
        if ($this->wikiTimeLeft() < 0.8) break 2;
        $pageTitle = $hit['title'] ?? '';
        if ($pageTitle === '') continue;

        $titleLower = strtolower($pageTitle);

        // Salta se il titolo è ESATTAMENTE il nome dell'artista (= pagina bio)
        if ($titleLower === $artistLower) continue;

        // CONTROLLO DECISIVO: il titolo della pagina, ripulito dai suffissi
        // tra parentesi, deve corrispondere all'album cercato.
        // Questo evita di pescare un ALTRO album dello stesso artista
        // (es. cercando "Korn" non deve restituire "Issues").
        $pageTitleNorm = $normalizeTitle($pageTitle);
        if ($pageTitleNorm !== $albumNorm) {
          continue;
        }

        // 2. Recupera l'estratto introduttivo della pagina
        $extractUrl = $base
          . '?action=query&prop=extracts&exintro=1&explaintext=1&redirects=1'
          . '&titles=' . urlencode($pageTitle)
          . '&format=json&utf8=1';

        $raw2  = null;
        [$raw2, , ] = $this->httpGetWiki($extractUrl, $ua);
        $data2 = $raw2 ? json_decode($raw2, true) : null;

        $pages = $data2['query']['pages'] ?? [];
        $page  = reset($pages);

        $extract = trim($page['extract'] ?? '');
        if ($extract === '') continue;

        $extractLower = strtolower($extract);

        // Scarta disambiguazioni
        if (stripos($extract, 'may refer to') !== false
            || stripos($extract, 'può riferirsi') !== false) {
          continue;
        }

        // La definizione sta sempre nella prima frase: analizziamo
        // solo l'inizio dell'intro per decidere se scartare.
        $intro = substr($extractLower, 0, 250);

        // Scarta se è una biografia / singolo / brano / canzone.
        // (blacklist forte: queste frasi compaiono SEMPRE nella prima riga)
        $reject = false;
        foreach ($rejectMarkers as $marker) {
          if (strpos($intro, $marker) !== false) {
            $reject = true;
            break;
          }
        }
        if ($reject) continue;

        // A questo punto NON è una bio né un singolo/brano.
        // Accettiamo la pagina se nell'intero estratto compare una parola
        // che indica un disco: "album"/"disco"/"ep " oppure "LP" (usato
        // spesso da Wikipedia IT per dischi più datati, es. "è il settimo
        // LP della band"). "lp" va cercato come parola intera per evitare
        // falsi positivi (es. "help", "alps").
        // Se nessuna di queste è presente, proviamo il candidato successivo.
        if (strpos($extractLower, 'album') === false
            && strpos($extractLower, 'disco') === false
            && strpos($extractLower, 'ep ') === false
            && !preg_match('/\blp\b/', $extractLower)) {
          continue;
        }

        // CONTROLLO ANTI-OMONIMIA: quando il titolo dell'album coincide
        // con un termine generico (es. "Heavy Metal" = nome di un genere
        // musicale), la pagina sbagliata può comunque superare i controlli
        // sopra (nessuna bio/singolo, contiene "album" da qualche parte).
        // Una vera pagina-album cita SEMPRE il nome dell'artista
        // nell'introduzione ("è il primo album in studio di X" /
        // "is the debut album by X"): se l'artista non compare affatto
        // nell'estratto, scartiamo e proviamo il prossimo candidato.
        if (stripos($extract, $artist) === false) {
          continue;
        }

        // OK: pagina valida
        $text = preg_replace("/\n{3,}/", "\n\n", $extract);
        $text = trim($text);

        $out = [
          'description' => $text,
          'lang'        => $lang,
          'page_title'  => $pageTitle,
          'wiki_url'    => 'https://' . $lang . '.wikipedia.org/wiki/' . urlencode(str_replace(' ', '_', $pageTitle)),
        ];
        if ($debug) $out['debug'] = $debugLog;
        return $out;
      }
    }

    $out = ['description' => null, 'lang' => $lang];
    if ($debug) $out['debug'] = $debugLog;
    return $out;
  }

  private function apiCover(): void
  {
    header('Content-Type: application/json');

    try {
      $artist = $_GET['artist'] ?? '';
      $title  = $_GET['title'] ?? '';

      if (!$artist || !$title) {
        echo json_encode(['error' => 'Parametri mancanti']);
        exit;
      }

      require_once BASE_PATH . '/app/services/AlbumMetadataService.php';
      $service = new AlbumMetadataService();

      $data = $service->search($artist, $title, $year);

      echo json_encode([
        'cover_url' => $data['cover'] ?? '',
        'mbid'      => $data['mbid'] ?? ''
      ]);
    } catch (Throwable $e) {
      echo json_encode(['error' => $e->getMessage()]);
    }

    exit;
  }
}
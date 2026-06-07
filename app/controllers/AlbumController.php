<?php

require_once BASE_PATH . '/app/models/AudioFile.php';

class AlbumController
{
  private Album  $albumModel;
  private Artist $artistModel;
  private Track  $trackModel;
  private AudioFile $audioModel;

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
    echo '<h1>404 — Disco non trovato</h1>';
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
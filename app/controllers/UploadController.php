<?php

class UploadController
{

  private PDO $db;

  public function __construct()
  {
    $this->db = Database::getInstance();
  }

  public function dispatch(string $action, ?int $id): void
  {
    switch ($action) {
      case 'audio':
        $this->audio($id);
        break;
      case 'bulk-audio':
        $this->bulkAudio($id);
        break;
      case 'delete-audio':
        $this->deleteAudio($id);
        break;
      default:
        header('Location: ' . BASE_URL . '?route=albums');
        exit;
    }
  }

  /* ----------------------------------------------------------------
     Upload singolo MP3 (flusso originale invariato)
  ---------------------------------------------------------------- */
  public function audio(?int $albumIdFromRoute): void
  {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      header('Location: ' . BASE_URL . '?route=albums');
      exit;
    }

    // Controlla se PHP ha scartato il body per dimensione eccessiva
    if (
      empty($_POST) &&
      empty($_FILES) &&
      isset($_SERVER['CONTENT_LENGTH']) &&
      (int)$_SERVER['CONTENT_LENGTH'] > 0
    ) {
      $maxBytes = ini_get('post_max_size');
      $_SESSION['flash_error'] = 'File troppo grande. Il limite del server è ' . $maxBytes . '.';
      $albumId = $albumIdFromRoute ?? 0;
      header('Location: ' . BASE_URL . '/index.php?route=albums/detail/' . $albumId);
      exit;
    }

    $albumId = $albumIdFromRoute ?? (int)($_POST['album_id'] ?? 0);
    $trackId = !empty($_POST['track_id']) ? (int)$_POST['track_id'] : null;

    // CSRF check
    if (
      empty($_POST['csrf_token']) ||
      empty($_SESSION['csrf_token']) ||
      !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
      $_SESSION['flash_error'] = 'Token CSRF non valido.';
      header('Location: ' . BASE_URL . '/index.php?route=albums/detail/' . $albumId);
      exit;
    }

    if (!$albumId || empty($_FILES['audio_file']['name'])) {
      $_SESSION['flash_error'] = 'Parametri mancanti o file non selezionato.';
      header('Location: ' . BASE_URL . '/index.php?route=albums/detail/' . $albumId);
      exit;
    }

    if (empty($_POST['track_id'])) {
      $_SESSION['flash_error'] = 'Seleziona una traccia a cui associare il file audio.';
      header('Location: ' . BASE_URL . '/index.php?route=albums/detail/' . $albumId);
      exit;
    }

    $file = $_FILES['audio_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
      $_SESSION['flash_error'] = 'Errore upload: codice ' . $file['error'];
      header('Location: ' . BASE_URL . '/index.php?route=albums/detail/' . $albumId);
      exit;
    }

    if ($file['size'] > MAX_AUDIO_SIZE) {
      $_SESSION['flash_error'] = 'File troppo grande. Massimo ' . (MAX_AUDIO_SIZE / 1024 / 1024) . ' MB.';
      header('Location: ' . BASE_URL . '/index.php?route=albums/detail/' . $albumId);
      exit;
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($file['tmp_name']);
    $allowed     = ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg', 'audio/flac', 'audio/x-flac', 'application/octet-stream'];
    $ext         = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['mp3', 'flac'];
    if (!in_array($mimeReal, $allowed) && !in_array($ext, $allowedExts)) {
      $_SESSION['flash_error'] = 'Formato non valido. Carica solo file MP3 o FLAC.';
      header('Location: ' . BASE_URL . '/index.php?route=albums/detail/' . $albumId);
      exit;
    }

    $audioDir = MediaPathResolver::getAudioDir();
    if (!is_dir($audioDir)) {
      mkdir($audioDir, 0755, true);
    }

    $filename = bin2hex(random_bytes(10)) . '.' . $ext;
    $dest     = $audioDir . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
      $stmt = $this->db->prepare("
          INSERT INTO audio_files (album_id, track_id, filename, original_name, filesize)
          VALUES (?, ?, ?, ?, ?)
      ");
      $stmt->execute([$albumId, $trackId, $filename, $file['name'], $file['size']]);
      $_SESSION['flash_success'] = 'File audio caricato con successo.';
    } else {
      $_SESSION['flash_error'] = 'Errore nel salvataggio del file. Controlla i permessi della cartella uploads/audio/.';
    }

    header('Location: ' . BASE_URL . '/index.php?route=albums/detail/' . $albumId);
    exit;
  }

  /* ----------------------------------------------------------------
     Upload massivo MP3 — risponde sempre in JSON
     Ogni richiesta carica UN solo file (chiamate sequenziali dal JS).
     Endpoint: POST /upload/bulk-audio/{albumId}
     Body (multipart):
       - csrf_token  string
       - album_id    int
       - track_id    int
       - audio_file  file
  ---------------------------------------------------------------- */
  public function bulkAudio(?int $albumIdFromRoute): void
  {
    // Questa action risponde sempre in JSON
    header('Content-Type: application/json; charset=utf-8');

    // Verifica metodo
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      echo json_encode(['success' => false, 'message' => 'Metodo non consentito.']);
      exit;
    }

    // Verifica header AJAX (impostato dal fetch nel JS)
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
      echo json_encode(['success' => false, 'message' => 'Richiesta non autorizzata.']);
      exit;
    }

    // Gestione body troppo grande (PHP svuota $_POST e $_FILES)
    if (
      empty($_POST) &&
      empty($_FILES) &&
      isset($_SERVER['CONTENT_LENGTH']) &&
      (int)$_SERVER['CONTENT_LENGTH'] > 0
    ) {
      $maxBytes = ini_get('post_max_size');
      echo json_encode(['success' => false, 'message' => 'File troppo grande. Limite server: ' . $maxBytes]);
      exit;
    }

    $albumId = $albumIdFromRoute ?? (int)($_POST['album_id'] ?? 0);
    $trackId = !empty($_POST['track_id']) ? (int)$_POST['track_id'] : null;

    // CSRF check
    if (
      empty($_POST['csrf_token']) ||
      empty($_SESSION['csrf_token']) ||
      !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
      echo json_encode(['success' => false, 'message' => 'Token CSRF non valido.']);
      exit;
    }

    if (!$albumId) {
      echo json_encode(['success' => false, 'message' => 'Album non valido.']);
      exit;
    }

    if (!$trackId) {
      echo json_encode(['success' => false, 'message' => 'Traccia non specificata.']);
      exit;
    }

    // Verifica che la traccia appartenga all'album (sicurezza)
    $checkStmt = $this->db->prepare("SELECT id FROM tracks WHERE id = ? AND album_id = ?");
    $checkStmt->execute([$trackId, $albumId]);
    if (!$checkStmt->fetch()) {
      echo json_encode(['success' => false, 'message' => 'La traccia non appartiene a questo album.']);
      exit;
    }

    if (empty($_FILES['audio_file']['name'])) {
      echo json_encode(['success' => false, 'message' => 'Nessun file ricevuto.']);
      exit;
    }

    $file = $_FILES['audio_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
      $errMessages = [
        UPLOAD_ERR_INI_SIZE   => 'File troppo grande (limite php.ini).',
        UPLOAD_ERR_FORM_SIZE  => 'File troppo grande (limite form).',
        UPLOAD_ERR_PARTIAL    => 'Upload parziale, riprova.',
        UPLOAD_ERR_NO_FILE    => 'Nessun file inviato.',
        UPLOAD_ERR_NO_TMP_DIR => 'Directory temporanea mancante.',
        UPLOAD_ERR_CANT_WRITE => 'Impossibile scrivere il file.',
        UPLOAD_ERR_EXTENSION  => 'Upload bloccato da estensione PHP.',
      ];
      $msg = $errMessages[$file['error']] ?? 'Errore upload sconosciuto (codice ' . $file['error'] . ').';
      echo json_encode(['success' => false, 'message' => $msg]);
      exit;
    }

    if ($file['size'] > MAX_AUDIO_SIZE) {
      echo json_encode([
        'success' => false,
        'message' => 'File troppo grande. Massimo ' . (MAX_AUDIO_SIZE / 1024 / 1024) . ' MB.',
      ]);
      exit;
    }

    // Valida MIME reale
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($file['tmp_name']);
    $allowed     = ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg', 'audio/flac', 'audio/x-flac', 'application/octet-stream'];
    $ext         = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['mp3', 'flac'];
    if (!in_array($mimeReal, $allowed) && !in_array($ext, $allowedExts)) {
      echo json_encode(['success' => false, 'message' => 'Formato non valido: ' . $mimeReal]);
      exit;
    }

    $audioDir = MediaPathResolver::getAudioDir();
    if (!is_dir($audioDir)) {
      mkdir($audioDir, 0755, true);
    }

    // Genera nome file univoco
    $filename = bin2hex(random_bytes(10)) . '.' . $ext;
    $dest     = $audioDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
      echo json_encode(['success' => false, 'message' => 'Errore nel salvataggio del file. Controlla i permessi di uploads/audio/.']);
      exit;
    }

    // Se esiste già un audio per questa traccia, elimina il vecchio file fisico
    // (sostituzione, non accumulo)
    $oldStmt = $this->db->prepare("SELECT id, filename FROM audio_files WHERE track_id = ?");
    $oldStmt->execute([$trackId]);
    $oldFile = $oldStmt->fetch();
    if ($oldFile) {
      $oldPath = MediaPathResolver::getAudioAbsPath($oldFile['filename']);
      if (file_exists($oldPath)) {
        unlink($oldPath);
      }
      $this->db->prepare("DELETE FROM audio_files WHERE id = ?")->execute([$oldFile['id']]);
    }

    // Inserisce record nel database
    $stmt = $this->db->prepare("
        INSERT INTO audio_files (album_id, track_id, filename, original_name, filesize)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$albumId, $trackId, $filename, $file['name'], $file['size']]);

    echo json_encode([
      'success'       => true,
      'message'       => 'File caricato correttamente.',
      'track_id'      => $trackId,
      'filename'      => $filename,
      'original_name' => $file['name'],
    ]);
    exit;
  }

  /* ----------------------------------------------------------------
     Elimina singolo file audio.
     Risponde JSON se AJAX (X-Requested-With), redirect se tradizionale.
  ---------------------------------------------------------------- */
  public function deleteAudio(?int $id): void
  {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
      && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

    if (!$id) {
      if ($isAjax) {
        $this->jsonResponse(false, 'ID non valido.');
      }
      header('Location: ' . BASE_URL . '?route=albums');
      exit;
    }

    // CSRF check
    $csrfPost    = $_POST['csrf_token'] ?? '';
    $csrfSession = $_SESSION['csrf_token'] ?? '';
    if (!$csrfPost || !$csrfSession || !hash_equals($csrfSession, $csrfPost)) {
      if ($isAjax) {
        $this->jsonResponse(false, 'Token CSRF non valido.');
      }
      header('Location: ' . BASE_URL . '?route=albums');
      exit;
    }

    $stmt = $this->db->prepare("SELECT * FROM audio_files WHERE id = ?");
    $stmt->execute([$id]);
    $file = $stmt->fetch();

    if (!$file) {
      if ($isAjax) {
        $this->jsonResponse(false, 'File non trovato.');
      }
      header('Location: ' . BASE_URL . '?route=albums');
      exit;
    }

    // Elimina file fisico
    $path = MediaPathResolver::getAudioAbsPath($file['filename']);
    if (file_exists($path)) {
      unlink($path);
    }
    $this->db->prepare("DELETE FROM audio_files WHERE id = ?")->execute([$id]);

    if ($isAjax) {
      $this->jsonResponse(true, 'File eliminato.');
    }

    $_SESSION['flash_success'] = 'File audio eliminato.';
    header('Location: ' . BASE_URL . '/index.php?route=albums/detail/' . $file['album_id']);
    exit;
  }

  /* ----------------------------------------------------------------
     Helper JSON
  ---------------------------------------------------------------- */
  private function jsonResponse(bool $success, string $message = ''): void
  {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
  }
}

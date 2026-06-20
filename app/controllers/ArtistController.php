<?php

class ArtistController
{

  private Artist $artistModel;

  public function __construct()
  {
    $this->artistModel = new Artist();
  }

  public function dispatch(string $action, ?int $id): void
  {
    switch ($action) {
      case 'profile':
        $this->profile($id);
        break;
      case 'fetch-meta':
        $this->fetchMeta($id);
        break;
      case 'fetch-discography':
        $this->fetchDiscography($id);
        break;
      default:
        $this->index();
        break;
    }
  }

  // ----------------------------------------------------------
  // LISTA ARTISTI
  // ----------------------------------------------------------
  public function index(): void
  {
    $artists = $this->artistModel->getTopArtists(1000); // oppure getAll + count

    require BASE_PATH . '/views/artists/list.php'; // ⚠️ cambiata view
  }

  // ----------------------------------------------------------
  // DETTAGLIO ARTISTA
  // ----------------------------------------------------------
  public function profile(?int $id): void
  {
    if (!$id) {
      header('Location: ' . BASE_URL . '/index.php?route=artists');
      exit;
    }

    $artist = $this->artistModel->getById($id);

    if (!$artist) {
      http_response_code(404);
      $errorContext = 'artista';
      require BASE_PATH . '/views/errors/404.php';
      exit;
    }

    // Model: album + statistiche per la hero
    $albums = $this->artistModel->getAlbums($id);
    $stats  = $this->artistModel->getAlbumStats($id);

    require BASE_PATH . '/views/artists/profile.php';
  }

  // ----------------------------------------------------------
  // ENDPOINT AJAX: recupera bio + immagine da fonti esterne.
  // Chiamato dalla view solo se la bio non è ancora stata cercata.
  // GET /index.php?route=artists/fetch-meta/{id}
  // Risposta JSON: { ok, bio, bio_source, bio_lang, bio_url,
  //                  image, country, active_from, active_to }
  // ----------------------------------------------------------
  private function fetchMeta(?int $id): void
  {
    // Output JSON pulito
    while (ob_get_level()) {
      ob_end_clean();
    }
    ini_set('display_errors', '0');
    header('Content-Type: application/json; charset=utf-8');

    try {
      if (!$id) {
        echo json_encode(['error' => 'ID artista mancante']);
        return;
      }

      $artist = $this->artistModel->getById($id);
      if (!$artist) {
        echo json_encode(['error' => 'Artista non trovato']);
        return;
      }

      // Se la bio è GIÀ stata recuperata in passato, non rifacciamo
      // le chiamate esterne: serviamo quanto già salvato in DB.
      if (!empty($artist['bio_fetched_at'])) {
        echo json_encode($this->metaPayload($artist));
        return;
      }

      require_once BASE_PATH . '/app/services/ArtistMetadataService.php';
      $service = new ArtistMetadataService();

      $meta = $service->fetchByName($artist['name']);

      // Download immagine in locale (best-effort)
      if (!empty($meta['image_url'])) {
        $local = $service->downloadImage($meta['image_url']);
        if ($local) {
          $meta['image_local'] = $local;
        }
      }

      // Persistenza: salviamo SEMPRE (anche con bio vuota, così
      // bio_fetched_at viene valorizzato e non ritentiamo a ogni visita)
      $this->artistModel->updateMeta($id, $meta);

      // Ricarica per servire i path definitivi
      $fresh = $this->artistModel->getById($id);
      echo json_encode($this->metaPayload($fresh));
    } catch (Throwable $e) {
      echo json_encode(['error' => DEBUG ? $e->getMessage() : 'Errore nel recupero dati']);
    }
    exit;
  }

  /**
   * Normalizza la risposta JSON per il frontend, scegliendo
   * l'immagine locale se presente, altrimenti quella remota.
   */
  private function metaPayload(array $artist): array
  {
    $image = '';
    if (!empty($artist['image_local'])) {
      $image = BASE_URL . '/public/uploads/' . $artist['image_local'];
    } elseif (!empty($artist['image_url'])) {
      $image = $artist['image_url'];
    }

    return [
      'ok'          => true,
      'bio'         => $artist['bio']         ?? '',
      'bio_source'  => $artist['bio_source']  ?? '',
      'bio_lang'    => $artist['bio_lang']    ?? '',
      'bio_url'     => $artist['bio_url']     ?? '',
      'image'       => $image,
      'country'     => $artist['country']     ?? '',
      'active_from' => $artist['active_from'] ?? null,
      'active_to'   => $artist['active_to']   ?? null,
    ];
  }

  // ----------------------------------------------------------
  // ENDPOINT AJAX: discografia ufficiale (studio album) da MusicBrainz.
  // GET /index.php?route=artists/fetch-discography/{id}
  // Risposta JSON: { ok, items:[ {title, year}, ... ] }
  // ----------------------------------------------------------
  private function fetchDiscography(?int $id): void
  {
    while (ob_get_level()) {
      ob_end_clean();
    }
    ini_set('display_errors', '0');
    header('Content-Type: application/json; charset=utf-8');

    try {
      if (!$id) {
        echo json_encode(['error' => 'ID artista mancante']);
        return;
      }

      $artist = $this->artistModel->getById($id);
      if (!$artist) {
        echo json_encode(['error' => 'Artista non trovato']);
        return;
      }

      // Gia' in cache? servi dal DB senza richiamare MusicBrainz.
      if (!empty($artist['disco_fetched_at'])) {
        echo json_encode([
          'ok'    => true,
          'items' => $this->artistModel->getDiscography($id),
        ]);
        return;
      }

      // Serve l'MBID artista (ottenuto col fetch della bio).
      $mbid = $artist['mb_artist_id'] ?? '';
      if ($mbid === '') {
        echo json_encode(['ok' => true, 'items' => []]);
        return;
      }

      require_once BASE_PATH . '/app/services/ArtistMetadataService.php';
      $service = new ArtistMetadataService();

      $items = $service->fetchDiscography($mbid);
      $this->artistModel->saveDiscography($id, $items);

      echo json_encode([
        'ok'    => true,
        'items' => $this->artistModel->getDiscography($id),
      ]);
    } catch (Throwable $e) {
      echo json_encode(['error' => DEBUG ? $e->getMessage() : 'Errore nel recupero discografia']);
    }
    exit;
  }
}

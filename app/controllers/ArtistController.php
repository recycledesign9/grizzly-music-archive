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
      case 'disco-cover':
        $this->discoCover();
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
    // FONDAMENTALE: rilascia SUBITO il lock di sessione. Questo endpoint
    // fa I/O esterno lento (MusicBrainz, Last.fm, download immagine):
    // senza questa riga terrebbe bloccata OGNI altra richiesta dell'app
    // (navigazione, AJAX, player) finché non ha finito.
    // Convenzione di progetto per tutti gli endpoint con I/O esterno.
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }

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

      require_once BASE_PATH . '/app/services/ArtistMetadataService.php';

      // Rifetch solo se: mai tentato, oppure la logica di fetch è
      // cambiata (version bump), oppure l'ultimo tentativo è fallito ed
      // è passato il cooldown — vedi Artist::needsBioRefetch(). In tutti
      // gli altri casi (incluso "cercato ma non trovato") serviamo la cache.
      if (!$this->artistModel->needsBioRefetch($artist, ArtistMetadataService::BIO_LOGIC_VERSION)) {
        echo json_encode($this->metaPayload($artist));
        return;
      }

      $service = new ArtistMetadataService();

      // Titoli degli album locali dell'artista: usati dal service per
      // disambiguare artisti omonimi su MusicBrainz (es. "Beck").
      $localTitles = array_column($this->artistModel->getAlbums($id), 'title');

      $meta = $service->fetchByName($artist['name'], $localTitles);

      // Indica se la ricerca MusicBrainz è andata a buon fine (vedi
      // ArtistMetadataService::fetchByName). Se è fallita per un errore
      // di rete/timeout marchiamo 'error': verrà ritentata da sola dopo
      // un cooldown, invece di restare vuota per sempre.
      $fetchOk = (bool) ($meta['fetch_ok'] ?? true);
      unset($meta['fetch_ok']);

      // Download immagine in locale (best-effort)
      if (!empty($meta['image_url'])) {
        $local = $service->downloadImage($meta['image_url']);
        if ($local) {
          $meta['image_local'] = $local;
        }
      }

      $status = $fetchOk ? 'ok' : 'error';
      $this->artistModel->updateMeta($id, $meta, $status, ArtistMetadataService::BIO_LOGIC_VERSION);

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
    // Rilascio immediato del lock di sessione — vedi nota in fetchMeta().
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }

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

      require_once BASE_PATH . '/app/services/ArtistMetadataService.php';

      // Gia' in cache E ancora valida (stessa versione della logica di
      // fetch, nessun errore da ritentare)? servi dal DB senza richiamare
      // MusicBrainz. Il version bump fa sì che le discografie già in
      // cache con l'algoritmo vecchio (troncato) si aggiornino da sole
      // alla prossima visita, senza bisogno di toccare il DB a mano.
      if (!$this->artistModel->needsDiscographyRefetch($artist, ArtistMetadataService::DISCOGRAPHY_LOGIC_VERSION)) {
        echo json_encode([
          'ok'    => true,
          'items' => $this->mapDiscographyCovers($this->artistModel->getDiscography($id)),
        ]);
        return;
      }

      // Serve l'MBID artista (ottenuto col fetch della bio). Non è un
      // errore: semplicemente la bio non è ancora stata recuperata, non
      // marchiamo nulla così si potrà ritentare subito dopo il fetch-meta.
      $mbid = $artist['mb_artist_id'] ?? '';
      if ($mbid === '') {
        echo json_encode(['ok' => true, 'items' => []]);
        return;
      }

      $service = new ArtistMetadataService();
      $result  = $service->fetchDiscography($mbid);

      $status = ($result['ok'] ?? true) ? 'ok' : 'error';
      $this->artistModel->saveDiscography(
        $id,
        $result['items'] ?? [],
        $status,
        ArtistMetadataService::DISCOGRAPHY_LOGIC_VERSION
      );

      echo json_encode([
        'ok'    => true,
        'items' => $this->mapDiscographyCovers($this->artistModel->getDiscography($id)),
      ]);
    } catch (Throwable $e) {
      echo json_encode(['error' => DEBUG ? $e->getMessage() : 'Errore nel recupero discografia']);
    }
    exit;
  }

  // ----------------------------------------------------------
  // ENDPOINT: cover della discografia (proxy lazy con cache su disco).
  // GET /index.php?route=artists/disco-cover&rg={release-group MBID}
  // File già su disco → redirect al file statico. Assente → lo scarica
  // da Cover Art Archive, lo salva, poi redirect. CAA giù o cover
  // inesistente → 404 secco: l'onerror dell'<img> nella view mostra il
  // placeholder e NIENTE viene salvato (un errore transitorio non deve
  // avvelenare la cache — si ritenterà alla prossima visita).
  // L'MBID viaggia come query param perché il router castà a int il
  // terzo segmento della route.
  // ----------------------------------------------------------
  private function discoCover(): void
  {
    // Rilascio immediato del lock di sessione — convenzione di progetto
    // per ogni endpoint con I/O esterno lento (vedi nota in fetchMeta).
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }

    $rg = strtolower(trim($_GET['rg'] ?? ''));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $rg)) {
      http_response_code(404);
      exit;
    }

    $file = UPLOAD_PATH . '/disco/' . $rg . '.jpg';

    if (!is_file($file)) {
      require_once BASE_PATH . '/app/services/ArtistMetadataService.php';
      $service = new ArtistMetadataService();
      $service->downloadDiscographyCover($rg);
    }

    if (is_file($file)) {
      header('Location: ' . BASE_URL . '/public/uploads/disco/' . $rg . '.jpg', true, 302);
      exit;
    }

    http_response_code(404);
    exit;
  }

  /**
   * Aggiunge a ogni voce di discografia l'URL cover migliore:
   * file locale già scaricato → URL statico (zero passaggi PHP),
   * altrimenti l'endpoint proxy che la scaricherà al primo accesso.
   */
  private function mapDiscographyCovers(array $items): array
  {
    foreach ($items as &$it) {
      $rg = strtolower((string) ($it['mb_release_group_id'] ?? ''));
      if ($rg === '') {
        $it['cover'] = '';
        continue;
      }
      if (is_file(UPLOAD_PATH . '/disco/' . $rg . '.jpg')) {
        $it['cover'] = BASE_URL . '/public/uploads/disco/' . $rg . '.jpg';
      } else {
        $it['cover'] = BASE_URL . '/index.php?route=artists/disco-cover&rg=' . $rg;
      }
    }
    unset($it);
    return $items;
  }
}
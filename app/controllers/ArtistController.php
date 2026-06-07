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
      echo 'Artista non trovato.';
      return;
    }

    // 👉 USI IL MODEL (non SQL nel controller)
    $albums = $this->artistModel->getAlbums($id);

    require BASE_PATH . '/views/artists/profile.php';
  }
}

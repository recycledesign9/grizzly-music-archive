<?php

class PlaylistController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ----------------------------------------------------------
    // Router — converte action con trattini in nomi di metodo
    // Es. "add-tracks" → addTracks(), "api-tracks" → apiTracks()
    // ----------------------------------------------------------
    public function dispatch(string $action, ?int $id): void
    {
        // Converti kebab-case in camelCase
        $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $action))));

        switch ($method) {

            case 'index':
                $this->index();
                break;

            case 'detail':
                $this->detail($id);
                break;

            case 'store':
                $this->store();
                break;

            case 'rename':
                $this->rename();
                break;

            case 'addTracks':
                $this->addTracks();
                break;

            case 'removeTrack':
                $this->removeTrack();
                break;

            case 'removeTracksBulk':
                $this->removeTracksBulk();
                break;

            case 'reorder':
                $this->reorder();
                break;

            case 'delete':
                $this->delete();
                break;

            case 'apiTracks':
                $this->apiTracks($id);
                break;

            default:
                $this->index();
                break;
        }
    }

    // ----------------------------------------------------------
    // GET /playlists
    // Elenco playlist con conteggio tracce totali e riproducibili
    // ----------------------------------------------------------
    private function index(): void
    {
        $stmt = $this->db->query("
            SELECT
                p.id,
                p.name,
                p.created_at,
                COUNT(pt.id)                                        AS total_tracks,
                SUM(CASE WHEN af.id IS NOT NULL THEN 1 ELSE 0 END) AS playable_tracks
            FROM playlists p
            LEFT JOIN playlist_tracks pt ON pt.playlist_id = p.id
            LEFT JOIN audio_files af     ON af.track_id    = pt.track_id
            GROUP BY p.id, p.name, p.created_at
            ORDER BY p.created_at DESC
        ");
        $playlists = $stmt->fetchAll();

        $pageTitle = 'Playlist';
        require BASE_PATH . '/views/playlists/list.php';
    }

    // ----------------------------------------------------------
    // GET /playlists/detail/{id}
    // ----------------------------------------------------------
    private function detail(?int $id): void
    {
        if (!$id) {
            $this->redirect('playlists');
            return;
        }

        $stmt = $this->db->prepare("SELECT * FROM playlists WHERE id = ?");
        $stmt->execute([$id]);
        $playlist = $stmt->fetch();

        if (!$playlist) {
            $this->notFound();
            return;
        }

        $tracks = $this->getPlaylistTracks($id);

        require BASE_PATH . '/views/playlists/detail.php';
    }

    // ----------------------------------------------------------
    // POST /playlists/store
    // Crea nuova playlist. Risponde JSON se AJAX.
    // ----------------------------------------------------------
    private function store(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('playlists');
            return;
        }

        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            $this->jsonError('Nome playlist obbligatorio.');
        }

        $stmt = $this->db->prepare("INSERT INTO playlists (name) VALUES (?)");
        $stmt->execute([$name]);
        $id = (int)$this->db->lastInsertId();

        if ($this->isAjax()) {
            $this->json(['success' => true, 'id' => $id, 'name' => $name]);
        }

        $this->redirect("playlists/detail/{$id}");
    }

    // ----------------------------------------------------------
    // POST /playlists/rename
    // ----------------------------------------------------------
    private function rename(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('Metodo non consentito.');
        }

        $id   = (int)($_POST['id']   ?? 0);
        $name = trim($_POST['name'] ?? '');

        if (!$id || $name === '') {
            $this->jsonError('Dati mancanti.');
        }

        $stmt = $this->db->prepare("UPDATE playlists SET name = ? WHERE id = ?");
        $stmt->execute([$name, $id]);

        $this->json(['success' => true]);
    }

    // ----------------------------------------------------------
    // POST /playlists/add-tracks
    // Aggiunge tracce singole o un intero album a una playlist.
    // Crea la playlist al volo se playlist_id = 'new'.
    //
    // POST body:
    //   playlist_id   int|'new'
    //   playlist_name string   (obbligatorio se playlist_id = 'new')
    //   album_id      int      (aggiunge tutte le tracce dell'album)
    //   track_ids[]   int[]    (aggiunge tracce specifiche)
    // ----------------------------------------------------------
    private function addTracks(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('Metodo non consentito.');
        }

        $playlistId = $_POST['playlist_id'] ?? 'new';

        // Crea playlist se richiesto
        $isNewPlaylist   = false;
        $newPlaylistName = '';
        if ($playlistId === 'new') {
            $name = trim($_POST['playlist_name'] ?? '');
            if ($name === '') {
                $this->jsonError('Nome playlist obbligatorio.');
            }
            $stmt = $this->db->prepare("INSERT INTO playlists (name) VALUES (?)");
            $stmt->execute([$name]);
            $playlistId      = (int)$this->db->lastInsertId();
            $isNewPlaylist   = true;
            $newPlaylistName = $name;
        } else {
            $playlistId = (int)$playlistId;
            if (!$playlistId) {
                $this->jsonError('Playlist non valida.');
            }
        }

        // Raccoglie i track_id da aggiungere
        $trackIds = [];

        if (!empty($_POST['album_id'])) {
            // Album intero: prende tutte le tracce in ordine di posizione
            $albumId = (int)$_POST['album_id'];
            $stmt    = $this->db->prepare(
                "SELECT id FROM tracks WHERE album_id = ? ORDER BY position ASC"
            );
            $stmt->execute([$albumId]);
            $trackIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        } elseif (!empty($_POST['track_ids'])) {
            $trackIds = array_map('intval', (array)$_POST['track_ids']);
            $trackIds = array_filter($trackIds); // rimuove zeri
        }

        if (empty($trackIds)) {
            $this->jsonError('Nessuna traccia selezionata.');
        }

        // Posizione di partenza: appende dopo l'ultima esistente
        $stmt = $this->db->prepare(
            "SELECT COALESCE(MAX(position), 0) FROM playlist_tracks WHERE playlist_id = ?"
        );
        $stmt->execute([$playlistId]);
        $maxPos = (int)$stmt->fetchColumn();

        $ins = $this->db->prepare(
            "INSERT IGNORE INTO playlist_tracks (playlist_id, track_id, position)
             VALUES (?, ?, ?)"
        );

        foreach (array_values($trackIds) as $i => $tid) {
            $ins->execute([$playlistId, $tid, $maxPos + $i + 1]);
        }

        $response = ['success' => true, 'playlist_id' => $playlistId];
        // Se nuova playlist: invia nome al frontend per aggiornare i dropdown senza reload
        if ($isNewPlaylist) {
            $response['playlist_name'] = $newPlaylistName;
        }
        $this->json($response);
    }

    // ----------------------------------------------------------
    // POST /playlists/remove-track
    // ----------------------------------------------------------
    private function removeTrack(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('Metodo non consentito.');
        }

        $playlistId = (int)($_POST['playlist_id'] ?? 0);
        $trackId    = (int)($_POST['track_id']    ?? 0);

        if (!$playlistId || !$trackId) {
            $this->jsonError('Dati mancanti.');
        }

        $stmt = $this->db->prepare(
            "DELETE FROM playlist_tracks WHERE playlist_id = ? AND track_id = ?"
        );
        $stmt->execute([$playlistId, $trackId]);

        $this->recompactPositions($playlistId);

        $this->json(['success' => true]);
    }

    // ----------------------------------------------------------
    // POST /playlists/remove-tracks-bulk
    // Rimuove più tracce in un colpo solo.
    // POST body:
    //   playlist_id  int
    //   track_ids[]  int[]
    // ----------------------------------------------------------
    private function removeTracksBulk(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('Metodo non consentito.');
        }

        $playlistId = (int)($_POST['playlist_id'] ?? 0);
        $trackIds   = array_map('intval', (array)($_POST['track_ids'] ?? []));
        $trackIds   = array_filter($trackIds);

        if (!$playlistId || empty($trackIds)) {
            $this->jsonError('Dati mancanti.');
        }

        // Costruisce placeholders sicuri per la IN clause
        $placeholders = implode(',', array_fill(0, count($trackIds), '?'));
        $params       = array_merge([$playlistId], array_values($trackIds));

        $stmt = $this->db->prepare(
            "DELETE FROM playlist_tracks
             WHERE playlist_id = ? AND track_id IN ({$placeholders})"
        );
        $stmt->execute($params);

        $this->recompactPositions($playlistId);

        $this->json(['success' => true, 'removed' => count($trackIds)]);
    }

    // ----------------------------------------------------------
    // POST /playlists/reorder
    // Riceve l'array dei track_id nell'ordine desiderato e
    // riscrive le posizioni.
    //
    // POST body:
    //   playlist_id  int
    //   order[]      int[]   track_id nell'ordine nuovo
    // ----------------------------------------------------------
    private function reorder(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('Metodo non consentito.');
        }

        $playlistId = (int)($_POST['playlist_id'] ?? 0);
        $order      = array_map('intval', (array)($_POST['order'] ?? []));

        if (!$playlistId || empty($order)) {
            $this->jsonError('Dati mancanti.');
        }

        $upd = $this->db->prepare(
            "UPDATE playlist_tracks
             SET position = ?
             WHERE playlist_id = ? AND track_id = ?"
        );

        foreach ($order as $pos => $tid) {
            $upd->execute([$pos + 1, $playlistId, $tid]);
        }

        $this->json(['success' => true]);
    }

    // ----------------------------------------------------------
    // POST /playlists/delete
    // ----------------------------------------------------------
    private function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('Metodo non consentito.');
        }

        $id = (int)($_POST['id'] ?? 0);

        if (!$id) {
            $this->jsonError('ID mancante.');
        }

        // La FK con ON DELETE CASCADE elimina anche i playlist_tracks
        $stmt = $this->db->prepare("DELETE FROM playlists WHERE id = ?");
        $stmt->execute([$id]);

        if ($this->isAjax()) {
            $this->json(['success' => true]);
        }

        $this->redirect('playlists');
    }

    // ----------------------------------------------------------
    // GET /playlists/api-tracks/{id}
    // Risponde sempre in JSON — usato da PlaylistPlayer.load()
    // ----------------------------------------------------------
    private function apiTracks(?int $id): void
    {
        header('Content-Type: application/json');

        if (!$id) {
            echo json_encode(['error' => 'ID mancante.']);
            exit;
        }

        // Verifica che la playlist esista
        $stmt = $this->db->prepare("SELECT name FROM playlists WHERE id = ?");
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();

        if ($name === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Playlist non trovata.']);
            exit;
        }

        $rows = $this->getPlaylistTracks($id);

        // Costruisce la struttura che PlaylistPlayer passa a Player.load()
        $tracks = [];
        foreach ($rows as $t) {
            $tracks[] = [
                'id'       => (int)$t['track_id'],
                'title'    => $t['title'],
                'position' => (int)$t['position'],
                // src = null per le tracce senza file audio → Player le salta
                'src'      => $t['audio_filename']
                                ? BASE_URL . '/public/uploads/audio/' . $t['audio_filename']
                                : null,
                'cover'    => $t['cover_local']
                                ? BASE_URL . '/public/uploads/' . $t['cover_local']
                                : ($t['cover_url'] ?: BASE_URL . '/public/img/placeholder.png'),
                'artist'   => $t['artist_name'],
                'album_id' => (int)$t['album_id'],
                'youtube_id' => $t['youtube_id'] ?: null,
            ];
        }

        echo json_encode([
            'name'   => $name,
            'tracks' => $tracks,
        ]);
        exit;
    }

    // ----------------------------------------------------------
    // Helpers privati
    // ----------------------------------------------------------

    /**
     * Recupera le tracce di una playlist con tutti i dati
     * necessari al player e alla view (cover, artista, album, audio).
     */
    private function getPlaylistTracks(int $playlistId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                pt.position,
                t.id          AS track_id,
                t.title,
                t.duration_sec,
                t.youtube_id,
                al.id         AS album_id,
                al.title      AS album_title,
                al.cover_url,
                al.cover_local,
                ar.id         AS artist_id,
                ar.name       AS artist_name,
                af.filename   AS audio_filename
            FROM playlist_tracks pt
            JOIN tracks  t  ON t.id  = pt.track_id
            JOIN albums  al ON al.id = t.album_id
            JOIN artists ar ON ar.id = al.artist_id
            LEFT JOIN audio_files af ON af.track_id = t.id
            WHERE pt.playlist_id = ?
            ORDER BY pt.position ASC
        ");
        $stmt->execute([$playlistId]);
        return $stmt->fetchAll();
    }

    /**
     * Ricompatta le posizioni dopo una rimozione, senza buchi.
     * Es. 1, 2, 4, 5 → 1, 2, 3, 4
     */
    private function recompactPositions(int $playlistId): void
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM playlist_tracks
             WHERE playlist_id = ?
             ORDER BY position ASC"
        );
        $stmt->execute([$playlistId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $upd = $this->db->prepare(
            "UPDATE playlist_tracks SET position = ? WHERE id = ?"
        );
        foreach ($rows as $i => $rowId) {
            $upd->execute([$i + 1, $rowId]);
        }
    }

    // ----------------------------------------------------------
    // Utility
    // ----------------------------------------------------------

    private function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    /** Risponde con JSON e termina. */
    private function json(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /** Risponde con JSON di errore e termina. */
    private function jsonError(string $message, int $code = 400): void
    {
        http_response_code($code);
        $this->json(['success' => false, 'error' => $message]);
    }

    private function redirect(string $route): void
    {
        header('Location: ' . BASE_URL . '/index.php?route=' . $route);
        exit;
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo '<h1>404 — Playlist non trovata</h1>';
        exit;
    }
}
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

            case 'download':
                $this->download($id);
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
        // playable_sec: stessa regola della pagina di dettaglio, cioè
        // somma delle durate delle sole tracce con file audio.
        $stmt = $this->db->query("
            SELECT
                p.id,
                p.name,
                p.created_at,
                COUNT(pt.id)                                        AS total_tracks,
                SUM(CASE WHEN af.id IS NOT NULL THEN 1 ELSE 0 END) AS playable_tracks,
                SUM(CASE WHEN af.id IS NOT NULL THEN COALESCE(t.duration_sec, 0) ELSE 0 END) AS playable_sec
            FROM playlists p
            LEFT JOIN playlist_tracks pt ON pt.playlist_id = p.id
            LEFT JOIN tracks t           ON t.id          = pt.track_id
            LEFT JOIN audio_files af     ON af.track_id    = pt.track_id
            GROUP BY p.id, p.name, p.created_at
            ORDER BY p.created_at DESC
        ");
        $playlists = $stmt->fetchAll();

        // Copertine per il mosaico: primi 4 album distinti di ogni
        // playlist, nell'ordine in cui compaiono le tracce.
        $coverRows = $this->db->query("
            SELECT
                pt.playlist_id,
                al.id          AS album_id,
                al.cover_local,
                al.cover_url,
                MIN(pt.position) AS first_pos
            FROM playlist_tracks pt
            JOIN tracks t  ON t.id  = pt.track_id
            JOIN albums al ON al.id = t.album_id
            GROUP BY pt.playlist_id, al.id, al.cover_local, al.cover_url
            ORDER BY pt.playlist_id, first_pos
        ")->fetchAll();

        $playlistCovers = [];
        foreach ($coverRows as $c) {
            $pid = (int)$c['playlist_id'];
            if (isset($playlistCovers[$pid]) && count($playlistCovers[$pid]) >= 4) {
                continue;
            }
            $src = $this->coverSrc($c['cover_local'], $c['cover_url']);
            if ($src !== null) {
                $playlistCovers[$pid][] = $src;
            }
        }

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

        // Gli URL audio devono SEMPRE passare per l'endpoint di streaming
        // (MediaController via MediaPathResolver): in produzione i file
        // audio vivono su un path configurabile FUORI dalla webroot, quindi
        // il vecchio percorso statico public/uploads/audio/ non li trova.
        // Le cover invece restano statiche per progetto (sempre in webroot).
        if (!class_exists('MediaPathResolver')) {
            require_once BASE_PATH . '/app/services/MediaPathResolver.php';
        }

        // Costruisce la struttura che PlaylistPlayer passa a Player.load()
        $tracks = [];
        foreach ($rows as $t) {
            $tracks[] = [
                'id'       => (int)$t['track_id'],
                'title'    => $t['title'],
                'position' => (int)$t['position'],
                // src = null per le tracce senza file audio → Player le salta
                'src'      => $t['audio_filename']
                    ? MediaPathResolver::getStreamUrl($t['audio_filename'])
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
    // GET /playlists/download/{id}
    // Scarica in un unico ZIP i file audio della playlist, nell'ordine
    // della playlist, dentro una cartella con il nome della playlist e
    // insieme a un file .m3u8 con la stessa sequenza.
    //
    // Lo ZIP è generato in streaming da ZipStreamWriter: nessun file
    // temporaneo, Content-Length esatto, primi byte inviati subito
    // (niente timeout del reverse proxy sulle playlist grandi).
    // Le tracce senza file audio, o con file non più raggiungibile,
    // vengono saltate.
    // ----------------------------------------------------------
    private function download(?int $id): void
    {
        if (!$id) {
            $this->redirect('playlists');
        }

        $stmt = $this->db->prepare("SELECT name FROM playlists WHERE id = ?");
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();

        if ($name === false) {
            $this->notFound();
        }

        $files = $this->getPlaylistDownloadFiles($id);

        if (empty($files)) {
            $this->plainError(404, 'Nessun file audio disponibile per questa playlist.');
        }

        // Il download può durare minuti: la sessione non deve bloccare
        // le altre richieste (player, navigazione SPA).
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $folder = ZipStreamWriter::safeName((string)$name);
        if ($folder === '') {
            $folder = 'Playlist ' . $id;
        }

        // Numerazione a larghezza fissa (01, 02 … oppure 001 oltre la 99a
        // posizione) perché l'ordine alfabetico coincida con la playlist.
        $maxPosition = 0;
        foreach ($files as $f) {
            $maxPosition = max($maxPosition, $f['position']);
        }
        $width = max(2, strlen((string)$maxPosition));

        $zip = new ZipStreamWriter();
        $zip->addDirectory($folder . '/');

        $m3u   = "#EXTM3U\n";
        $added = 0;

        foreach ($files as $f) {
            $title  = trim((string)$f['title']) !== '' ? (string)$f['title'] : 'Traccia ' . $f['position'];
            $artist = (string)$f['artist_name'];

            $entry = sprintf('%0' . $width . 'd', $f['position'])
                . ' - '
                . ZipStreamWriter::safeName($artist . ' - ' . $title, 160)
                . '.' . $f['ext'];

            if (!$zip->addFile($folder . '/' . $entry, $f['path'])) {
                continue;
            }
            $added++;

            $duration = $f['duration_sec'] > 0 ? $f['duration_sec'] : -1;
            $m3u .= '#EXTINF:' . $duration . ',' . $this->m3uText($artist . ' - ' . $title) . "\n"
                . $entry . "\n";
        }

        if ($added === 0) {
            $this->plainError(404, 'Nessun file audio disponibile per questa playlist.');
        }

        $zip->addString($folder . '/' . $folder . '.m3u8', $m3u);

        try {
            $zip->send($folder . '.zip');
        } catch (RuntimeException $e) {
            // Eccezioni possibili solo prima dell'invio delle intestazioni
            error_log('[playlist-zip] ' . $e->getMessage());
            if (!headers_sent()) {
                $this->plainError(500, $e->getMessage());
            }
        }

        exit;
    }

    // ----------------------------------------------------------
    // Helpers privati
    // ----------------------------------------------------------

    /**
     * File audio da inserire nello ZIP, uno per traccia, nell'ordine
     * della playlist.
     *
     * Se una traccia ha più file audio, vince il FLAC; a parità di
     * formato il record più recente (stesso criterio dello streaming).
     * Il percorso è risolto con MediaPathResolver::resolveAudioFile(),
     * cioè con le stesse regole dello streaming per i file managed ed
     * external; un file non raggiungibile passa al candidato successivo.
     *
     * @return array<int, array{position: int, title: string, artist_name: string, duration_sec: int, path: string, ext: string}>
     */
    private function getPlaylistDownloadFiles(int $playlistId): array
    {
        $sql = "
            SELECT
                pt.position,
                t.id          AS track_id,
                t.title,
                t.duration_sec,
                ar.name       AS artist_name,
                af.id         AS audio_id,
                af.filename,
                %s
            FROM playlist_tracks pt
            JOIN tracks      t  ON t.id  = pt.track_id
            JOIN albums      al ON al.id = t.album_id
            JOIN artists     ar ON ar.id = al.artist_id
            JOIN audio_files af ON af.track_id = t.id
            WHERE pt.playlist_id = ?
            ORDER BY pt.position ASC, af.id DESC
        ";

        try {
            $stmt = $this->db->prepare(sprintf($sql, 'af.storage_type, af.source_path'));
            $stmt->execute([$playlistId]);
        } catch (PDOException $e) {
            // DB precedente alle colonne storage_type/source_path:
            // tutti i file sono managed, come nello streaming.
            $stmt = $this->db->prepare(sprintf($sql, "'managed' AS storage_type, NULL AS source_path"));
            $stmt->execute([$playlistId]);
        }

        $byTrack = [];
        foreach ($stmt->fetchAll() as $row) {
            $tid = (int)$row['track_id'];
            if (!isset($byTrack[$tid])) {
                $byTrack[$tid] = [];
            }
            $byTrack[$tid][] = $row;
        }

        $files = [];

        foreach ($byTrack as $candidates) {
            usort($candidates, function (array $a, array $b): int {
                $rankA = strtolower(pathinfo((string)$a['filename'], PATHINFO_EXTENSION)) === 'flac' ? 0 : 1;
                $rankB = strtolower(pathinfo((string)$b['filename'], PATHINFO_EXTENSION)) === 'flac' ? 0 : 1;
                if ($rankA !== $rankB) {
                    return $rankA - $rankB;
                }
                return (int)$b['audio_id'] - (int)$a['audio_id'];
            });

            foreach ($candidates as $c) {
                $path = MediaPathResolver::resolveAudioFile($c);
                if ($path === null) {
                    continue;
                }

                $files[] = [
                    'position'     => (int)$c['position'],
                    'title'        => (string)$c['title'],
                    'artist_name'  => (string)$c['artist_name'],
                    'duration_sec' => (int)($c['duration_sec'] ?? 0),
                    'path'         => $path,
                    'ext'          => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                ];
                break;
            }
        }

        return $files;
    }

    /** Testo su una sola riga per le voci #EXTINF del file .m3u8 */
    private function m3uText(string $value): string
    {
        return trim(preg_replace('/[\r\n]+/', ' ', $value) ?? $value);
    }

    /**
     * URL della copertina di un album: locale > remota > nessuna.
     * Stessa priorità usata nella view di dettaglio.
     */
    private function coverSrc(?string $local, ?string $url): ?string
    {
        if (!empty($local)) {
            return BASE_URL . '/public/uploads/' . $local;
        }
        if (!empty($url)) {
            return strpos($url, 'http') === 0 ? $url : BASE_URL . '/public/uploads/' . $url;
        }
        return null;
    }

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

    /** Risponde con un messaggio di testo semplice e termina. */
    private function plainError(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }

    private function redirect(string $route): void
    {
        header('Location: ' . BASE_URL . '/index.php?route=' . $route);
        exit;
    }

    private function notFound(): void
    {
        http_response_code(404);
        $errorContext = 'playlist';
        require BASE_PATH . '/views/errors/404.php';
        exit;
    }
}
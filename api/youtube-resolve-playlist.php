<?php
/**
 * api/youtube-resolve-playlist.php
 *
 * Endpoint AJAX — risolve in batch i video YouTube delle tracce di una
 * playlist che non hanno ancora uno youtube_id.
 *
 * Lavora A CHUNK: ogni chiamata risolve al massimo `limit` tracce e
 * riferisce quante ne restano. Il frontend richiama in loop finché
 * remaining = 0 (o finché la quota API si esaurisce). Motivi:
 *   - search.list costa 100 unità su 10.000/giorno → il chunking evita
 *     richieste HTTP lunghe e permette una progress bar reale;
 *   - un errore di quota interrompe pulito senza perdere il lavoro fatto
 *     (ogni traccia risolta è già persistita).
 *
 * Cache negativa: le tracce senza risultato vengono marcate
 * youtube_status='not_found' e NON vengono ritentate per 30 giorni,
 * per non bruciare quota sugli stessi fallimenti.
 *
 * Sicurezza: richiede il token CSRF di sessione (stesso pattern degli
 * altri endpoint POST del progetto). La sessione viene chiusa subito
 * dopo la lettura del token per non trattenere il lock durante le
 * chiamate lente alla YouTube API.
 *
 * POST params:
 *   csrf_token  (string) — token CSRF di sessione
 *   playlist_id (int)    — id della playlist
 *   limit       (int)    — tracce da risolvere in questa chiamata (default 8, max 15)
 *
 * Response JSON:
 *   {
 *     "success": true,
 *     "resolved": 5,            // trovate e salvate in questa chiamata
 *     "failed": 1,              // senza risultato (marcate not_found)
 *     "remaining": 12,          // tracce ancora da risolvere
 *     "quota_exceeded": false,  // true se la quota API si è esaurita
 *     "youtube_ids": ["...",…]  // TUTTI gli id della playlist, in ordine
 *   }
 *   { "success": false, "error": "..." }
 */

declare(strict_types=1);

// — Bootstrap —————————————————————————————————————————————
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/services/YouTubeSearchService.php';

while (ob_get_level()) {
    ob_end_clean();
}
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

// — Validazioni preliminari ———————————————————————————————
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito.']);
    exit;
}

// — CSRF check ————————————————————————————————————————————
// La sessione serve solo per leggere il token: viene chiusa
// IMMEDIATAMENTE dopo (regola del progetto: nessun endpoint con I/O
// lento deve trattenere il lock di sessione).
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$csrfSession = $_SESSION['csrf_token'] ?? '';
session_write_close();

$csrfPost = $_POST['csrf_token'] ?? '';
if (
    !is_string($csrfPost)
    || $csrfPost === ''
    || $csrfSession === ''
    || !hash_equals($csrfSession, $csrfPost)
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF non valido.']);
    exit;
}

if (!defined('YOUTUBE_API_KEY') || YOUTUBE_API_KEY === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'YouTube API key non configurata.']);
    exit;
}

$playlistId = filter_input(INPUT_POST, 'playlist_id', FILTER_VALIDATE_INT);
$limit      = filter_input(INPUT_POST, 'limit', FILTER_VALIDATE_INT);

if (!$playlistId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'playlist_id mancante o non valido.']);
    exit;
}

// Chunk fra 1 e 15, default 8
if (!$limit || $limit < 1)  { $limit = 8;  }
if ($limit > 15)            { $limit = 15; }

$db = Database::getInstance();

// — La playlist esiste? ———————————————————————————————————
$stmt = $db->prepare('SELECT id FROM playlists WHERE id = ? LIMIT 1');
$stmt->execute([$playlistId]);
if ($stmt->fetchColumn() === false) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Playlist non trovata.']);
    exit;
}

/*
 * Condizione "traccia da risolvere":
 *   - youtube_id assente
 *   - E non marcata not_found di recente (cache negativa, retry dopo 30 giorni)
 * NB: la stessa condizione è riusata più volte, ma con placeholder
 * POSIZIONALI (?) perché PDO non consente di riutilizzare parametri nominati.
 */
$missingWhere = "
    (t.youtube_id IS NULL OR t.youtube_id = '')
    AND (
        t.youtube_status IS NULL
        OR t.youtube_status <> 'not_found'
        OR t.youtube_checked_at IS NULL
        OR t.youtube_checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    )
";

// — Seleziona il prossimo chunk di tracce da risolvere ————
$stmt = $db->prepare("
    SELECT
        t.id,
        t.title,
        ar.name AS artist_name
    FROM playlist_tracks pt
    JOIN tracks  t  ON t.id  = pt.track_id
    JOIN albums  al ON al.id = t.album_id
    JOIN artists ar ON ar.id = al.artist_id
    WHERE pt.playlist_id = ?
      AND {$missingWhere}
    ORDER BY pt.position ASC
    LIMIT {$limit}
");
$stmt->execute([$playlistId]);
$toResolve = $stmt->fetchAll(PDO::FETCH_ASSOC);

// — Risolvi il chunk ———————————————————————————————————————
$service       = new YouTubeSearchService();
$resolved      = 0;
$failed        = 0;
$quotaExceeded = false;

$updFound = $db->prepare(
    "UPDATE tracks
     SET youtube_id = ?, youtube_status = 'auto', youtube_checked_at = NOW()
     WHERE id = ?"
);
$updNotFound = $db->prepare(
    "UPDATE tracks
     SET youtube_status = 'not_found', youtube_checked_at = NOW()
     WHERE id = ?"
);

foreach ($toResolve as $i => $track) {
    $result = $service->resolve($track['artist_name'], $track['title']);

    if ($result['error'] === YouTubeSearchService::ERROR_QUOTA) {
        // Quota esaurita: fermati subito, il lavoro già fatto è persistito.
        $quotaExceeded = true;
        break;
    }

    if ($result['video_id']) {
        $updFound->execute([$result['video_id'], (int)$track['id']]);
        $resolved++;
    } elseif ($result['error'] === null) {
        // Nessun video pertinente → cache negativa
        $updNotFound->execute([(int)$track['id']]);
        $failed++;
    } else {
        // Errore API transitorio: non marcare, verrà ritentata.
        $failed++;
    }

    // Piccola pausa di cortesia fra le chiamate (tranne dopo l'ultima)
    if ($i < count($toResolve) - 1) {
        usleep(200000); // 0,2 s
    }
}

// — Ricalcola quante tracce restano da risolvere ——————————
$stmt = $db->prepare("
    SELECT COUNT(*)
    FROM playlist_tracks pt
    JOIN tracks t ON t.id = pt.track_id
    WHERE pt.playlist_id = ?
      AND {$missingWhere}
");
$stmt->execute([$playlistId]);
$remaining = (int)$stmt->fetchColumn();

// — Elenco completo degli youtube_id della playlist, in ordine ————
$stmt = $db->prepare("
    SELECT t.youtube_id
    FROM playlist_tracks pt
    JOIN tracks t ON t.id = pt.track_id
    WHERE pt.playlist_id = ?
      AND t.youtube_id IS NOT NULL
      AND t.youtube_id <> ''
    ORDER BY pt.position ASC
");
$stmt->execute([$playlistId]);
$ytIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
$ytIds = array_values(array_unique($ytIds));

echo json_encode([
    'success'        => true,
    'resolved'       => $resolved,
    'failed'         => $failed,
    'remaining'      => $remaining,
    'quota_exceeded' => $quotaExceeded,
    'youtube_ids'    => $ytIds,
]);
exit;

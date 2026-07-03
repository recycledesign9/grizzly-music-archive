<?php
/**
 * api/youtube-track.php
 *
 * Endpoint AJAX — cerca un video YouTube per una traccia.
 * Usa la cache su tracks.youtube_id per risparmiare quota API.
 *
 * La logica di ricerca/scoring vive in YouTubeSearchService
 * (app/services/YouTubeSearchService.php), condivisa con il
 * batch resolver api/youtube-resolve-playlist.php.
 *
 * GET params:
 *   track_id  (int)    — id della traccia nella tabella tracks
 *   artist    (string) — nome artista/gruppo
 *   title     (string) — titolo della traccia
 *   force     (int)    — se 1, ignora la cache e ri-cerca sempre
 *
 * Response JSON (identica alla versione precedente):
 *   { "video_id": "...", "cached": true/false }
 *   { "error": "..." }
 */

declare(strict_types=1);

// — Bootstrap —————————————————————————————————————————————
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/services/YouTubeSearchService.php';

// Silenzia output sporco e setta header JSON
while (ob_get_level()) {
    ob_end_clean();
}
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

// — Valida API Key ————————————————————————————————————————
if (!defined('YOUTUBE_API_KEY') || YOUTUBE_API_KEY === '') {
    http_response_code(503);
    echo json_encode(['error' => 'YouTube API key non configurata.']);
    exit;
}

// — Input & sanitizzazione ————————————————————————————————
$trackId = filter_input(INPUT_GET, 'track_id', FILTER_VALIDATE_INT);
$artist  = trim(strip_tags($_GET['artist'] ?? ''));
$title   = trim(strip_tags($_GET['title']  ?? ''));
$force   = (int)($_GET['force'] ?? 0);

if (!$artist || !$title) {
    http_response_code(400);
    echo json_encode(['error' => 'Parametri artist e title obbligatori.']);
    exit;
}

// — Leggi dalla cache DB ——————————————————————————————————
$db = Database::getInstance();

if ($trackId && !$force) {
    $stmt = $db->prepare('SELECT youtube_id FROM tracks WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $trackId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['youtube_id'])) {
        echo json_encode([
            'video_id' => $row['youtube_id'],
            'cached'   => true,
        ]);
        exit;
    }
}

// — Chiama YouTube Data API v3 (servizio condiviso) ———————
$service = new YouTubeSearchService();
$result  = $service->resolve($artist, $title);
$videoId = $result['video_id'];

if (!$videoId) {
    // Cache negativa: memorizza il fallimento SOLO se non è un errore
    // API/quota (in quel caso vale la pena ritentare più avanti).
    if ($trackId && $result['error'] === null) {
        $upd = $db->prepare(
            'UPDATE tracks
             SET youtube_status = :st, youtube_checked_at = NOW()
             WHERE id = :id'
        );
        $upd->execute([':st' => 'not_found', ':id' => $trackId]);
    }
    echo json_encode(['error' => 'Nessun video trovato per questa traccia.']);
    exit;
}

// — Salva in cache DB (solo se abbiamo un track_id valido) ——
if ($trackId) {
    $upd = $db->prepare(
        'UPDATE tracks
         SET youtube_id = :vid, youtube_status = :st, youtube_checked_at = NOW()
         WHERE id = :id'
    );
    $upd->execute([':vid' => $videoId, ':st' => 'auto', ':id' => $trackId]);
}

echo json_encode([
    'video_id' => $videoId,
    'cached'   => false,
]);
exit;
<?php
/**
 * api/youtube-track.php
 *
 * Endpoint AJAX — cerca un video YouTube per una traccia.
 * Usa la cache su tracks.youtube_id per risparmiare quota API.
 *
 * GET params:
 *   track_id  (int)    — id della traccia nella tabella tracks
 *   artist    (string) — nome artista/gruppo
 *   title     (string) — titolo della traccia
 *   force     (int)    — se 1, ignora la cache e ri-cerca sempre
 *
 * Response JSON:
 *   { "video_id": "...", "cached": true/false }
 *   { "error": "..." }
 */

declare(strict_types=1);

// — Bootstrap —————————————————————————————————————————————
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

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

// — Chiama YouTube Data API v3 ————————————————————————————
$videoId = searchYouTube($artist, $title);

if (!$videoId) {
    echo json_encode(['error' => 'Nessun video trovato per questa traccia.']);
    exit;
}

// — Salva in cache DB (solo se abbiamo un track_id valido) ——
if ($trackId) {
    $upd = $db->prepare('UPDATE tracks SET youtube_id = :vid WHERE id = :id');
    $upd->execute([':vid' => $videoId, ':id' => $trackId]);
}

echo json_encode([
    'video_id' => $videoId,
    'cached'   => false,
]);
exit;

// ============================================================
// Funzione: chiama YouTube Search API e ritorna il videoId
// ============================================================
function searchYouTube(string $artist, string $title): ?string
{
    /*
     * Strategia di query:
     *   Prima prova: query precisa "Artista - Titolo" → solitamente il video ufficiale
     *   Seconda prova (fallback): query larga senza virgolette
     *
     * Parametri chiave:
     *   type=video          — solo video (non playlist o canali)
     *   videoCategoryId=10  — categoria Music
     *   maxResults=5        — leggi i primi 5 e scegli il migliore
     *   part=snippet        — ci basta lo snippet per il titolo/canale
     *   safeSearch=none     — serve per non filtrare musica esplicita
     */

    $queries = [
        $artist . ' - ' . $title,  // 1° tentativo: preciso
        $artist . ' ' . $title,    // 2° tentativo: largo
    ];

    foreach ($queries as $q) {
        $url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
            'part'            => 'snippet',
            'q'               => $q,
            'type'            => 'video',
            'videoCategoryId' => '10',
            'maxResults'      => 5,
            'safeSearch'      => 'none',
            'key'             => YOUTUBE_API_KEY,
        ]);

        $ctx = stream_context_create([
            'http' => [
                'timeout'       => 8,
                'ignore_errors' => true,
                'header'        => 'Accept: application/json',
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $json = @file_get_contents($url, false, $ctx);

        if (!$json) {
            continue;
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            continue;
        }

        // Errore API YouTube (es. quota esaurita, chiave invalida)
        if (!empty($data['error'])) {
            error_log('[YouTube API] ' . ($data['error']['message'] ?? 'Errore sconosciuto'));
            return null;
        }

        $items = $data['items'] ?? [];

        if (empty($items)) {
            continue; // prova con la query larga
        }

        // — Scegli il video migliore ——————————————————————
        $videoId = pickBestVideo($items, $artist, $title);

        if ($videoId) {
            return $videoId;
        }
    }

    return null;
}

// ============================================================
// Funzione: seleziona il videoId più pertinente dall'elenco
// ============================================================
function pickBestVideo(array $items, string $artist, string $title): ?string
{
    /*
     * Logica di scoring (semplice ma efficace):
     *   +3 se il titolo del video contiene il titolo della traccia
     *   +2 se il titolo del video contiene il nome artista
     *   +2 se il canale contiene il nome artista (canale ufficiale)
     *   -2 se il titolo contiene "live", "cover", "remix", "karaoke"
     *   Prende il video con punteggio più alto
     */

    $artistLow = strtolower($artist);
    $titleLow  = strtolower($title);

    $avoid = ['live', 'cover', 'remix', 'karaoke', 'instrumental', 'tribute', 'reaction'];

    $best      = null;
    $bestScore = -99;

    foreach ($items as $item) {
        if (empty($item['id']['videoId'])) {
            continue;
        }

        $snippet      = $item['snippet'] ?? [];
        $videoTitle   = strtolower($snippet['title']       ?? '');
        $channelTitle = strtolower($snippet['channelTitle'] ?? '');

        $score = 0;

        if (strpos($videoTitle, $titleLow)  !== false) $score += 3;
        if (strpos($videoTitle, $artistLow) !== false) $score += 2;
        if (strpos($channelTitle, $artistLow) !== false) $score += 2;

        foreach ($avoid as $word) {
            if (strpos($videoTitle, $word) !== false) {
                $score -= 2;
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best      = $item['id']['videoId'];
        }
    }

    return $best;
}

<?php

/**
 * YouTubeSearchService
 * ------------------------------------------------------------
 * Cerca su YouTube il video migliore per una traccia (artista + titolo).
 * Logica estratta da api/youtube-track.php — comportamento IDENTICO
 * (stesse query, stesso scoring), ora riusabile da più endpoint:
 *
 *   - api/youtube-track.php            (risoluzione singola, lazy dal player)
 *   - api/youtube-resolve-playlist.php (risoluzione batch di una playlist)
 *
 * Il risultato distingue tre casi:
 *   - video trovato            → ['video_id' => 'xyz', 'error' => null]
 *   - nessun video pertinente  → ['video_id' => null,  'error' => null]
 *   - errore API (quota, rete) → ['video_id' => null,  'error' => 'quota'|'api']
 *
 * La distinzione serve al batch resolver: 'quota' interrompe il ciclo,
 * "nessun video" viene salvato come cache negativa (youtube_status='not_found').
 *
 * Compatibile PHP 7.4 (no match(), no str_contains, no union types).
 */
class YouTubeSearchService
{
    /** Errore: quota API esaurita o chiave rifiutata */
    const ERROR_QUOTA = 'quota';

    /** Errore: problema generico API / rete / JSON malformato */
    const ERROR_API = 'api';

    /**
     * Cerca il video YouTube migliore per artista + titolo.
     *
     * @param  string $artist Nome artista/gruppo
     * @param  string $title  Titolo della traccia
     * @return array  ['video_id' => ?string, 'error' => ?string]
     */
    public function resolve(string $artist, string $title): array
    {
        /*
         * Strategia di query (identica al comportamento storico):
         *   1° tentativo: "Artista - Titolo" → solitamente il video ufficiale
         *   2° tentativo: query larga senza trattino
         */
        $queries = [
            $artist . ' - ' . $title,
            $artist . ' ' . $title,
        ];

        $lastError = null;

        foreach ($queries as $q) {
            $result = $this->searchOnce($q, $artist, $title);

            // Errore hard (quota/API): inutile ritentare con la query larga
            if ($result['error'] === self::ERROR_QUOTA) {
                return ['video_id' => null, 'error' => self::ERROR_QUOTA];
            }
            if ($result['error'] === self::ERROR_API) {
                $lastError = self::ERROR_API;
                continue; // la query larga potrebbe comunque riuscire
            }

            if ($result['video_id']) {
                return ['video_id' => $result['video_id'], 'error' => null];
            }
            // Nessun risultato pertinente → prova la query successiva
        }

        return ['video_id' => null, 'error' => $lastError];
    }

    // ------------------------------------------------------------
    // Singola chiamata a search.list + scelta del candidato migliore
    // ------------------------------------------------------------
    private function searchOnce(string $q, string $artist, string $title): array
    {
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
            return ['video_id' => null, 'error' => self::ERROR_API];
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['video_id' => null, 'error' => self::ERROR_API];
        }

        // Errore API YouTube (quota esaurita, chiave invalida, ecc.)
        if (!empty($data['error'])) {
            $message = $data['error']['message'] ?? 'Errore sconosciuto';
            error_log('[YouTube API] ' . $message);

            if ($this->isQuotaError($data['error'])) {
                return ['video_id' => null, 'error' => self::ERROR_QUOTA];
            }
            return ['video_id' => null, 'error' => self::ERROR_API];
        }

        $items = $data['items'] ?? [];

        if (empty($items)) {
            return ['video_id' => null, 'error' => null];
        }

        return [
            'video_id' => $this->pickBestVideo($items, $artist, $title),
            'error'    => null,
        ];
    }

    // ------------------------------------------------------------
    // Riconosce gli errori di quota/autorizzazione dalla risposta API
    // ------------------------------------------------------------
    private function isQuotaError(array $error): bool
    {
        // reason tipici: quotaExceeded, dailyLimitExceeded, rateLimitExceeded,
        // forbidden (chiave disabilitata). HTTP code 403.
        $reasons = [];
        foreach (($error['errors'] ?? []) as $e) {
            if (!empty($e['reason'])) {
                $reasons[] = strtolower($e['reason']);
            }
        }

        foreach ($reasons as $reason) {
            if (strpos($reason, 'quota') !== false ||
                strpos($reason, 'limit') !== false ||
                $reason === 'forbidden') {
                return true;
            }
        }

        return (int)($error['code'] ?? 0) === 403;
    }

    // ------------------------------------------------------------
    // Seleziona il videoId più pertinente dall'elenco
    // (scoring identico alla versione storica di youtube-track.php)
    // ------------------------------------------------------------
    private function pickBestVideo(array $items, string $artist, string $title): ?string
    {
        /*
         * Logica di scoring (semplice ma efficace):
         *   +3 se il titolo del video contiene il titolo della traccia
         *   +2 se il titolo del video contiene il nome artista
         *   +2 se il canale contiene il nome artista (canale ufficiale)
         *   -2 se il titolo contiene "live", "cover", "remix", "karaoke"...
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
            $videoTitle   = strtolower($snippet['title']        ?? '');
            $channelTitle = strtolower($snippet['channelTitle'] ?? '');

            $score = 0;

            if (strpos($videoTitle, $titleLow)    !== false) $score += 3;
            if (strpos($videoTitle, $artistLow)   !== false) $score += 2;
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
}
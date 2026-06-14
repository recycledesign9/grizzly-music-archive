<?php

class AlbumMetadataService
{
    private const MAX_TRACKS = 40;

    public function search(string $artist, string $album, int $year = 0): array
    {
        $artistRaw = $artist;
        $albumRaw  = $album;

        $artist = $this->normalize($artist);
        $album  = $this->normalize($album);

        if ($artist === '' || $album === '') {
            return $this->emptyResult($album);
        }

        $result = $this->emptyResult($album);

        // ================= MUSICBRAINZ =================
        $mb = $this->searchMusicBrainzRelease($artist, $album, $year);

        if (!empty($mb)) {
            $result['title'] = $mb['title'] ?? $album;
            $result['year']  = !empty($mb['date']) ? substr($mb['date'], 0, 4) : '';
            $result['mbid']  = $mb['id'] ?? '';

            // Genere da MusicBrainz release-group
            if (!empty($mb['release-group']['genres'][0]['name'])) {
                $result['genre'] = ucfirst($mb['release-group']['genres'][0]['name']);
            } elseif (!empty($mb['genres'][0]['name'])) {
                $result['genre'] = ucfirst($mb['genres'][0]['name']);
            }

            // Etichetta da MusicBrainz
            if (!empty($mb['label-info'][0]['label']['name'])) {
                $result['label'] = $mb['label-info'][0]['label']['name'];
            }

            if (!empty($mb['id'])) {
                $tracks = $this->getTracksFromMusicBrainzRelease($mb['id']);
                $result['tracks'] = $this->cleanTracks($tracks);

                // Tenta cover dalla release specifica
                $cover = $this->getCoverFromCAA($mb['id']);

                // Fallback: cerca cover sul release-group (più affidabile per album storici)
                if (empty($cover) && !empty($mb['release-group']['id'])) {
                    $cover = $this->getCoverFromReleaseGroup($mb['release-group']['id']);
                }

                if (!empty($cover)) {
                    $result['cover'] = $cover;
                }
            }
        }
        $isMbValid = $this->isValidMb($mb ?? [], $result['tracks']);

        // ================= DISCOGS (UNICA CHIAMATA) =================

        $discogs = null;

        // chiama Discogs SOLO se serve qualcosa
        if (
            !$isMbValid
            || empty($result['tracks']) || count($result['tracks']) < 5
            || empty($result['genre'])
            || empty($result['label'])
            || empty($result['cover'])
        ) {
            $discogs = $this->searchDiscogs($artist, $album, $year);
        }

        // usa il risultato UNA SOLA VOLTA
        if (!empty($discogs)) {

            if (!empty($discogs['tracks'])) {

                // Se Discogs ha PIÙ tracce → usa Discogs
                if (
                    !$isMbValid
                    || count($discogs['tracks']) > count($result['tracks'])
                ) {
                    $result['tracks'] = $this->cleanTracks($discogs['tracks']);
                }
            }

            if (empty($result['year']) && !empty($discogs['year'])) {
                $result['year'] = $discogs['year'];
            }

            if (empty($result['cover']) && !empty($discogs['cover'])) {
                $result['cover'] = $discogs['cover'];
            }

            if (empty($result['genre']) && !empty($discogs['genre'])) {
                $result['genre'] = $discogs['genre'];
            }

            if (empty($result['label']) && !empty($discogs['label'])) {
                $result['label'] = $discogs['label'];
            }
        }

        // ================= LASTFM (SOLO COME ULTIMO FALLBACK) =================
        if (empty($result['tracks'])) {
            $lfm = $this->getLastFmAlbumInfo($artistRaw, $albumRaw);

            if (!empty($lfm['tracks'])) {
                $result['tracks'] = $this->cleanTracks($lfm['tracks']);
            }
        }

        $result['debug_source'] = !$isMbValid ? 'discogs' : 'musicbrainz';

        return $result;
    }

    private function isValidMb(array $mb, array $tracks): bool
    {
        if (empty($mb)) return false;
        if (count($tracks) < 3) return false; // era 5, troppo restrittivo

        $title = strtolower($mb['title'] ?? '');

        $bad = [
            'deluxe', 'remaster', 'remastered',
            'expanded', 'anniversary', 'bonus'
        ];

        foreach ($bad as $w) {
            if (strpos($title, $w) !== false) {
                return false;
            }
        }

        return true;
    }

    private function emptyResult(string $album): array
    {
        return [
            'title'       => $album,
            'year'        => '',
            'mbid'        => '',
            'cover'       => '',
            'cover_local' => '',
            'genre'       => '',
            'label'       => '',
            'tracks'      => [],
        ];
    }

    private function normalize(string $str): string
    {
        $str = trim($str);
        $str = preg_replace('/\(.+?\)/', '', $str);
        $str = preg_replace('/\s*-\s*(remaster(ed)?|deluxe|edition|version).*$/i', '', $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return trim($str);
    }

    // ================= HTTP =================

    private function httpGetJson(string $url, array $headers = []): array
    {
        $ua = defined('APP_USER_AGENT') ? APP_USER_AGENT : 'MusicArchive/1.0';

        $context = stream_context_create([
            'http' => [
                'header' => implode("\r\n", array_merge([
                    'User-Agent: ' . $ua,
                    'Accept: application/json'
                ], $headers)),
                'timeout' => 10,
                'ignore_errors' => true
            ]
        ]);

        $json = @file_get_contents($url, false, $context);

        if ($json === false || $json === null) {
            return [];
        }

        if (isset($http_response_header)) {
            $isJson = false;
            foreach ($http_response_header as $h) {
                if (stripos($h, 'application/json') !== false) {
                    $isJson = true;
                    break;
                }
            }
            if (!$isJson) {
                return [];
            }
        }

        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    // ================= MUSICBRAINZ =================

    private function searchMusicBrainzRelease(string $artist, string $album, int $year = 0): array
    {
        // 1 QUERY PRECISA — include anno se disponibile
        $query = 'release:"' . $album . '" AND artist:"' . $artist . '"';
        if ($year > 0) {
            $query .= ' AND date:' . $year;
        }

        $url = 'https://musicbrainz.org/ws/2/release/?query='
            . rawurlencode($query)
            . '&fmt=json&limit=10&inc=release-groups+labels+genres+media';

        $data = $this->httpGetJson($url);

        if (!empty($data['releases'])) {
            $best = $this->pickBestRelease($data['releases'], $album, $year);
            if (!empty($best)) return $best;
        }

        // 2 FALLBACK LARGO — senza anno per non perdere risultati
        $url = 'https://musicbrainz.org/ws/2/release/?query='
            . rawurlencode($artist . ' ' . $album)
            . '&fmt=json&limit=10&inc=release-groups+labels+genres+media';

        $data = $this->httpGetJson($url);

        if (!empty($data['releases'])) {
            $best = $this->pickBestRelease($data['releases'], $album, $year);
            if (!empty($best)) return $best;
        }

        return [];
    }

    private function pickBestRelease(array $releases, string $album, int $year = 0): array
    {
        $album = strtolower($album);

        // Parole nel titolo che identificano edizioni da evitare
        $avoidTitle = [
            'deluxe', 'bonus', 'remaster', 'remastered', 'reissue',
            'expanded', 'anniversary', 'special', 'collector',
            'box set', 'live', 'bootleg', 'promo', 'limited',
            'edition', 'version', '2cd', '3cd', 'super edition'
        ];

        // Tipi di packaging che indicano edizioni speciali
        $avoidPackaging = ['box', 'box set', 'tin', 'deluxe'];

        // Se non è stato fornito un anno, trova l'anno più vecchio tra le release
        // valide (senza parole da evitare) — quella è quasi certamente l'originale
        $oldestYear = null;
        if ($year === 0) {
            foreach ($releases as $rel) {
                if (empty($rel['date'])) continue;
                $titleLow = strtolower($rel['title'] ?? '');
                $isSpecial = false;
                foreach ($avoidTitle as $w) {
                    if (strpos($titleLow, $w) !== false) { $isSpecial = true; break; }
                }
                if ($isSpecial) continue;
                $y = (int)substr($rel['date'], 0, 4);
                if ($y > 1900 && ($oldestYear === null || $y < $oldestYear)) {
                    $oldestYear = $y;
                }
            }
        }

        $best      = null;
        $bestScore = -9999;

        foreach ($releases as $rel) {
            if (empty($rel['title'])) continue;

            $title = strtolower($rel['title']);

            // Base: punteggio MusicBrainz nativo (0-100)
            $score = (float)($rel['score'] ?? 50);

            // Penalizza parole speciali nel titolo
            foreach ($avoidTitle as $word) {
                if (strpos($title, $word) !== false) {
                    $score -= 35;
                    break;
                }
            }

            // Penalizza packaging speciale
            $packaging = strtolower($rel['packaging'] ?? '');
            foreach ($avoidPackaging as $word) {
                if (strpos($packaging, $word) !== false) {
                    $score -= 20;
                    break;
                }
            }

            // Penalizza release con più di 1 disco
            $mediaCount = (int)($rel['media-count'] ?? 1);
            if ($mediaCount > 1) {
                $score -= (25 * ($mediaCount - 1));
            }

            // Premia release ufficiali
            if (!empty($rel['status']) && strtolower($rel['status']) === 'official') {
                $score += 10;
            }

            // Premia release con paese di origine
            if (!empty($rel['country'])) {
                $score += 5;
            }

            $relYear = !empty($rel['date']) ? (int)substr($rel['date'], 0, 4) : 0;

            if ($year > 0) {
                // Anno fornito dall'utente: usalo come filtro dominante
                if ($relYear > 0) {
                    $diff = abs($relYear - $year);
                    if ($diff === 0)     $score += 50;
                    elseif ($diff <= 1) $score += 20;
                    elseif ($diff <= 3) $score += 5;
                    else                $score -= 30;
                } else {
                    $score -= 10;
                }
            } elseif ($oldestYear !== null && $relYear > 0) {
                // Nessun anno fornito: premia la release più vicina all'anno più antico
                $diff = abs($relYear - $oldestYear);
                if ($diff === 0)     $score += 35; // è la più vecchia = originale
                elseif ($diff <= 2) $score += 15;
                elseif ($diff <= 5) $score += 0;
                else                $score -= 20; // ristampa tardiva
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $rel;
            }
        }

        return $best ?? [];
    }

    // FIX 2: getTracksFromMusicBrainzRelease ora prende SOLO
    // il primo medium (Disc 1 / disco principale).
    // In MusicBrainz i media sono sempre ordinati: il primo è l'album originale,
    // i successivi sono bonus disc, disc 2, DVD, ecc.
    // NON usare "il medium con più tracce" perché nelle edizioni Collector's
    // il disco bonus può avere più tracce del disco principale.
    private function getTracksFromMusicBrainzRelease(string $mbid): array
    {
        $url  = 'https://musicbrainz.org/ws/2/release/' . $mbid . '?inc=recordings+media&fmt=json';
        $data = $this->httpGetJson($url);

        if (empty($data['media'])) return [];

        $tracks = [];

        // PRENDE TUTTI I DISCHI (CD1, CD2, VINILE A/B/C/D ecc.)
        foreach ($data['media'] as $medium) {

            if (empty($medium['tracks'])) continue;

            foreach ($medium['tracks'] as $t) {
                if (empty($t['title'])) continue;

                $tracks[] = [
                    'position' => count($tracks) + 1,
                    'title'    => $t['title'],
                    'duration' => !empty($t['length']) ? (int)($t['length'] / 1000) : 0
                ];
            }
        }

        return $tracks;
    }

    // ================= DISCOGS =================

    private function searchDiscogs(string $artist, string $album, int $year = 0): array
    {
        if (!defined('DISCOGS_TOKEN') || DISCOGS_TOKEN === '') return [];

        $url = 'https://api.discogs.com/database/search?type=release'
            . '&artist='        . urlencode($artist)
            . '&release_title=' . urlencode($album)
            . ($year > 0 ? '&year=' . $year : '')
            . '&per_page=5';

        $headers = ['Authorization: Discogs token=' . DISCOGS_TOKEN];

        $data = $this->httpGetJson($url, $headers);

        if (empty($data['results'])) return [];

        $first = $this->pickBestDiscogsResult($data['results'], $album, $year);

        if (!$first) return [];

        $rel = $this->httpGetJson($first['resource_url'], $headers);

        $tracks = [];
        foreach ($rel['tracklist'] ?? [] as $i => $t) {
            if (!empty($t['title'])) {
                $tracks[] = [
                    'position' => $i + 1,
                    'title'    => $t['title'],
                    'duration' => $this->discogsDurationToSeconds($t['duration'] ?? '')
                ];
            }
        }

        $genre = '';
        if (!empty($rel['styles'][0])) {
            $genre = $rel['styles'][0];
        } elseif (!empty($rel['genres'][0])) {
            $genre = $rel['genres'][0];
        } elseif (!empty($first['style'][0])) {
            $genre = $first['style'][0];
        } elseif (!empty($first['genre'][0])) {
            $genre = $first['genre'][0];
        }

        $label = '';
        if (!empty($rel['labels'][0]['name'])) {
            $label = $rel['labels'][0]['name'];
        }

        return [
            'year'   => $first['year'] ?? '',
            'cover'  => !empty($first['cover_image'])
                ? str_replace('R-90-', 'R-600-', $first['cover_image'])
                : '',
            'genre'  => $genre,
            'label'  => $label,
            'tracks' => $tracks,
        ];
    }

    private function pickBestDiscogsResult(array $results, string $album, int $year = 0): ?array
    {
        $album = strtolower($album);

        $avoid = [
            'deluxe', 'remaster', 'remastered', 'reissue', 'expanded',
            'anniversary', 'special', 'collector', 'box set', 'live',
            'bootleg', 'promo', 'edition', 'version'
        ];

        $best      = null;
        $bestScore = -9999;

        foreach ($results as $row) {
            $title = strtolower($row['title'] ?? '');
            if ($title === '') continue;

            similar_text($album, $title, $score);

            // Penalizza versioni non ufficiali
            foreach ($avoid as $word) {
                if (strpos($title, $word) !== false) {
                    $score -= 30;
                    break;
                }
            }

            // Bonus anno
            if ($year > 0 && !empty($row['year'])) {
                $diff = abs((int)$row['year'] - $year);
                if ($diff === 0)     $score += 30;
                elseif ($diff <= 1) $score += 15;
                elseif ($diff <= 3) $score += 5;
                else                $score -= 10;
            }

            // Bonus master_id (release principale)
            if (!empty($row['master_id'])) $score += 15;

            // Bonus presenza anno
            if (!empty($row['year'])) $score += 5;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $row;
            }
        }

        return $best;
    }

    private function discogsDurationToSeconds(string $d): int
    {
        if (preg_match('/(\d+):(\d+)/', $d, $m)) {
            return $m[1] * 60 + $m[2];
        }
        return 0;
    }

    // ================= LASTFM =================

    private function getLastFmAlbumInfo(string $artist, string $album): array
    {
        if (!defined('LASTFM_API_KEY') || LASTFM_API_KEY === '') return [];

        $url = 'https://ws.audioscrobbler.com/2.0/?method=album.getinfo'
            . '&api_key=' . LASTFM_API_KEY
            . '&artist=' . urlencode($artist)
            . '&album=' . urlencode($album)
            . '&format=json';

        $data = $this->httpGetJson($url);

        $tracks = [];
        $raw    = $data['album']['tracks']['track'] ?? [];

        if (isset($raw['name'])) $raw = [$raw];

        foreach ($raw as $i => $t) {
            if (!empty($t['name'])) {
                $tracks[] = [
                    'position' => $i + 1,
                    'title'    => $t['name'],
                    'duration' => (int)($t['duration'] ?? 0)
                ];
            }
        }

        return ['tracks' => $tracks];
    }

    // ================= COVER =================

    private function getCoverFromCAA(string $mbid): string
    {
        $url  = 'https://coverartarchive.org/release/' . $mbid;
        $data = $this->httpGetJson($url);

        if (!empty($data['images'])) {
            foreach ($data['images'] as $img) {
                if (!empty($img['front'])) {
                    // Preferisci thumbnail 500px: stabile, nessun redirect
                    if (!empty($img['thumbnails']['500'])) {
                        return $img['thumbnails']['500'];
                    }
                    if (!empty($img['thumbnails']['large'])) {
                        return $img['thumbnails']['large'];
                    }
                    if (!empty($img['image'])) {
                        return $img['image'];
                    }
                }
            }
        }

        return '';
    }

    private function getCoverFromReleaseGroup(string $releaseGroupMbid): string
    {
        if (empty($releaseGroupMbid)) return '';

        $url  = 'https://coverartarchive.org/release-group/' . $releaseGroupMbid;
        $data = $this->httpGetJson($url);

        if (!empty($data['images'])) {
            foreach ($data['images'] as $img) {
                if (!empty($img['front'])) {
                    if (!empty($img['thumbnails']['500'])) {
                        return $img['thumbnails']['500'];
                    }
                    if (!empty($img['thumbnails']['large'])) {
                        return $img['thumbnails']['large'];
                    }
                    if (!empty($img['image'])) {
                        return $img['image'];
                    }
                }
            }
        }

        return '';
    }

    // FIX 3: cleanTracks ora deduplica per titolo,
    // evitando che la stessa traccia compaia più volte
    // quando le sorgenti si sovrappongono
    private function cleanTracks(array $tracks): array
    {
        $clean      = [];
        $seenTitles = [];

        $skipWords = [
            'take',
            'rehearsal',
            'jam',
            'dialogue',
            'studio',
            'mix',
            'session'
        ];

        foreach ($tracks as $t) {
            $title      = trim($t['title'] ?? '');
            $titleLower = strtolower($title);

            if ($title === '') continue;

            // Salta tracce con parole da evitare
            foreach ($skipWords as $word) {
                if (strpos($titleLower, $word) !== false) {
                    continue 2;
                }
            }

            // Deduplicazione: normalizza e confronta
            $normalized = preg_replace('/\s+/', ' ', $titleLower);
            if (in_array($normalized, $seenTitles, true)) {
                continue;
            }

            $seenTitles[] = $normalized;
            $clean[]      = $t;

            if (count($clean) >= self::MAX_TRACKS) break;
        }

        return array_values($clean);
    }

    // ----------------------------------------------------------
    // Scarica cover da URL remoto e salva in uploads/covers/
    // ----------------------------------------------------------
    public function downloadCover(string $url): ?string
    {
        if (!defined('COVERS_PATH') || empty($url)) return null;
        if (!is_dir(COVERS_PATH)) mkdir(COVERS_PATH, 0755, true);

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => 20,
                'follow_location' => true,
                'max_redirects'   => 10,
                'header'          => "User-Agent: MusicArchive/1.0\r\nAccept: image/*",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $imageData = @file_get_contents($url, false, $ctx);
        if (!$imageData || strlen($imageData) < 500) return null;

        $sig    = substr($imageData, 0, 4);
        $isJpeg = substr($sig, 0, 2) === "\xFF\xD8";
        $isPng  = $sig === "\x89PNG";
        $isWebp = substr($imageData, 8, 4) === 'WEBP';
        if (!$isJpeg && !$isPng && !$isWebp) return null;

        $ext      = $isPng ? 'png' : ($isWebp ? 'webp' : 'jpg');
        $filename = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest     = COVERS_PATH . '/' . $filename;

        if (file_put_contents($dest, $imageData) !== false) {
            return 'covers/' . $filename;
        }
        return null;
    }
}
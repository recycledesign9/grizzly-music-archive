<?php

/**
 * ArtistMetadataService
 * ------------------------------------------------------------
 * Recupera bio + immagine artista da fonti esterne, privilegiando
 * l'ITALIANO. Pipeline:
 *
 *   1) MusicBrainz  -> match affidabile (score>=85) + relation Wikidata
 *   2) Wikidata     -> titolo pagina Wikipedia IT + nazionalita + immagine P18
 *   3) Wikipedia IT -> bio italiana COMPLETA (intro) + immagine
 *   4) [fallback]   -> Wikipedia EN
 *   5) [fallback]   -> Last.fm artist.getInfo (bio inglese)
 *
 * IMMAGINE — catena a piu livelli per coprire anche i collage:
 *   a) Wikipedia pageimages (foto singole)
 *   b) Wikipedia REST summary thumbnail (pesca dove (a) fallisce)
 *   c) Wikidata P18 (file Wikimedia Commons)
 *
 * Compatibile PHP 7.4 (no match(), no union types).
 */
class ArtistMetadataService
{
    /** Pausa fra le chiamate a MusicBrainz: rate limit 1 req/sec */
    private const MB_THROTTLE_US = 1100000;

    /** Soglia minima di confidenza sul match MusicBrainz */
    private const MB_MIN_SCORE = 85;

    /** Lunghezza massima bio salvata (caratteri) per non esagerare */
    private const BIO_MAX_CHARS = 2200;

    public function fetchByName(string $name): array
    {
        $result = $this->emptyResult();
        $name   = trim($name);
        if ($name === '') {
            return $result;
        }

        // ---------- 1) MUSICBRAINZ : match + metadati ----------
        $mb = $this->searchMusicBrainzArtist($name);

        $wikidataId = '';
        if (!empty($mb)) {
            $result['mb_artist_id'] = $mb['id'] ?? '';
            $result['country']      = $mb['area']['name'] ?? ($mb['country'] ?? '');

            if (!empty($mb['life-span']['begin'])) {
                $result['active_from'] = (int) substr($mb['life-span']['begin'], 0, 4) ?: null;
            }
            if (!empty($mb['life-span']['end'])) {
                $result['active_to'] = (int) substr($mb['life-span']['end'], 0, 4) ?: null;
            }

            $wikidataId = $this->extractWikidataId($mb['relations'] ?? []);
        }

        // ---------- 2+3) WIKIPEDIA IT (intro completa + immagine) ----------
        $wiki = $this->fetchWikipedia($wikidataId, $name, 'it');

        // ---------- 4) FALLBACK WIKIPEDIA EN ----------
        if (empty($wiki['extract'])) {
            $wiki = $this->fetchWikipedia($wikidataId, $name, 'en');
        }

        if (!empty($wiki['extract'])) {
            $result['bio']        = $this->trimBio($wiki['extract']);
            $result['bio_source'] = 'wikipedia';
            $result['bio_lang']   = $wiki['lang'];
            $result['bio_url']    = $wiki['url'];
            if (!empty($wiki['image'])) {
                $result['image_url']    = $wiki['image'];
                $result['image_source'] = 'wikimedia';
            }
            // memorizza il titolo/lingua pagina per i fallback immagine
            $wikiTitle = $wiki['title'] ?? '';
            $wikiLang  = $wiki['lang']  ?? 'it';
        } else {
            $wikiTitle = '';
            $wikiLang  = 'it';
        }

        // ---------- IMMAGINE: fallback in catena se ancora manca ----------
        if ($result['image_url'] === '') {
            // b) REST summary (pesca i casi dove pageimages e' vuoto, es. collage)
            if ($wikiTitle !== '') {
                $img = $this->wikipediaSummaryImage($wikiTitle, $wikiLang);
                if ($img !== '') {
                    $result['image_url']    = $img;
                    $result['image_source'] = 'wikimedia';
                }
            }
        }
        if ($result['image_url'] === '' && $wikidataId !== '') {
            // c) Wikidata P18
            $p18 = $this->wikidataImage($wikidataId);
            if ($p18 !== '') {
                $result['image_url']    = $p18;
                $result['image_source'] = 'wikidata';
            }
        }

        // ---------- NAZIONALITA: fallback da Wikidata se manca ----------
        if ($result['country'] === '' && $wikidataId !== '') {
            $country = $this->wikidataCountry($wikidataId);
            if ($country !== '') {
                $result['country'] = $country;
            }
        }

        // ---------- 5) FALLBACK LAST.FM ----------
        if ($result['bio'] === '') {
            $lf = $this->fetchLastFmBio($name, $result['mb_artist_id']);
            if (!empty($lf['bio'])) {
                $result['bio']        = $this->trimBio($lf['bio']);
                $result['bio_source'] = 'lastfm';
                $result['bio_lang']   = 'en';
                $result['bio_url']    = $lf['url'] ?? '';
            }
        }

        return $result;
    }

    // ============================================================
    // MUSICBRAINZ
    // ============================================================

    private function searchMusicBrainzArtist(string $name): array
    {
        $url = 'https://musicbrainz.org/ws/2/artist/'
            . '?query=' . urlencode('artist:"' . $name . '"')
            . '&fmt=json&limit=5';

        $data = $this->httpGetJson($url);
        usleep(self::MB_THROTTLE_US);

        if (empty($data['artists'])) {
            return [];
        }

        $best = null;
        foreach ($data['artists'] as $a) {
            $score = (int) ($a['score'] ?? 0);
            if ($score >= self::MB_MIN_SCORE) {
                $best = $a;
                break;
            }
        }
        if ($best === null) {
            return [];
        }

        if (!empty($best['id'])) {
            $lookupUrl = 'https://musicbrainz.org/ws/2/artist/' . $best['id']
                . '?inc=url-rels&fmt=json';
            $full = $this->httpGetJson($lookupUrl);
            usleep(self::MB_THROTTLE_US);
            if (!empty($full['id'])) {
                $full['score'] = $best['score'] ?? 0;
                return $full;
            }
        }

        return $best;
    }

    private function extractWikidataId(array $relations): string
    {
        foreach ($relations as $rel) {
            $type = $rel['type'] ?? '';
            $res  = $rel['url']['resource'] ?? '';
            if ($type === 'wikidata' && $res !== '') {
                if (preg_match('~/(Q\d+)~', $res, $m)) {
                    return $m[1];
                }
            }
        }
        return '';
    }

    // ============================================================
    // WIKIDATA -> WIKIPEDIA
    // ============================================================

    /**
     * Recupera l'INTRODUZIONE COMPLETA + immagine da Wikipedia.
     * Restituisce anche 'title' (titolo pagina risolto) per i fallback img.
     *
     * @return array{extract:string,image:string,url:string,lang:string,title:string}
     */
    private function fetchWikipedia(string $wikidataId, string $name, string $lang): array
    {
        $empty = ['extract' => '', 'image' => '', 'url' => '', 'lang' => $lang, 'title' => ''];

        // Titolo pagina via Wikidata (matching certo), altrimenti nome
        $title = '';
        if ($wikidataId !== '') {
            $title = $this->wikidataSitelinkTitle($wikidataId, $lang);
        }
        if ($title === '') {
            $title = $name;
        }

        // action API: intro completa in testo semplice + immagine pagina
        $apiUrl = 'https://' . $lang . '.wikipedia.org/w/api.php'
            . '?action=query&format=json&redirects=1'
            . '&prop=extracts|pageimages|info'
            . '&inprop=url'
            . '&exintro=1&explaintext=1'
            . '&piprop=original|thumbnail&pithumbsize=600'
            . '&titles=' . rawurlencode($title);

        $data  = $this->httpGetJson($apiUrl);
        $pages = $data['query']['pages'] ?? [];

        if (empty($pages)) {
            return $empty;
        }

        $page = reset($pages);

        if (isset($page['missing'])) {
            return $empty;
        }

        $extract = trim($page['extract'] ?? '');
        if ($extract === '') {
            return $empty;
        }

        if (stripos($extract, 'puo riferirsi a') !== false
            || stripos($extract, "pu\xC3\xB2 riferirsi a") !== false
            || stripos($extract, 'may refer to') !== false) {
            return $empty;
        }

        $image = $page['original']['source']
              ?? ($page['thumbnail']['source'] ?? '');

        // titolo reale dopo eventuali redirect
        $resolvedTitle = $page['title'] ?? $title;

        $url = $page['fullurl']
            ?? ('https://' . $lang . '.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $resolvedTitle)));

        return [
            'extract' => $extract,
            'image'   => $image,
            'url'     => $url,
            'lang'    => $lang,
            'title'   => $resolvedTitle,
        ];
    }

    /**
     * Fallback immagine via REST summary: spesso restituisce la thumbnail
     * dell'infobox anche quando pageimages la lascia vuota (es. collage).
     */
    private function wikipediaSummaryImage(string $title, string $lang): string
    {
        $url = 'https://' . $lang . '.wikipedia.org/api/rest_v1/page/summary/'
            . rawurlencode(str_replace(' ', '_', $title));

        $data = $this->httpGetJson($url);

        return $data['thumbnail']['source']
            ?? ($data['originalimage']['source'] ?? '');
    }

    private function wikidataSitelinkTitle(string $wikidataId, string $lang): string
    {
        $url = 'https://www.wikidata.org/wiki/Special:EntityData/'
            . rawurlencode($wikidataId) . '.json';

        $data = $this->httpGetJson($url);

        $sitelinks = $data['entities'][$wikidataId]['sitelinks'] ?? [];
        $key       = $lang . 'wiki';

        if (!empty($sitelinks[$key]['title'])) {
            return $sitelinks[$key]['title'];
        }
        return '';
    }

    /**
     * Immagine da Wikidata P18 -> URL diretto del file su Wikimedia Commons.
     */
    private function wikidataImage(string $wikidataId): string
    {
        $url = 'https://www.wikidata.org/wiki/Special:EntityData/'
            . rawurlencode($wikidataId) . '.json';

        $data   = $this->httpGetJson($url);
        $claims = $data['entities'][$wikidataId]['claims'] ?? [];

        if (empty($claims['P18'][0]['mainsnak']['datavalue']['value'])) {
            return '';
        }

        $filename = $claims['P18'][0]['mainsnak']['datavalue']['value'];

        return 'https://commons.wikimedia.org/wiki/Special:FilePath/'
            . rawurlencode($filename) . '?width=600';
    }

    /**
     * Nazionalita da Wikidata: prova P495 (country of origin) poi P17.
     */
    private function wikidataCountry(string $wikidataId): string
    {
        $url = 'https://www.wikidata.org/wiki/Special:EntityData/'
            . rawurlencode($wikidataId) . '.json';

        $data   = $this->httpGetJson($url);
        $claims = $data['entities'][$wikidataId]['claims'] ?? [];

        $countryQid = '';
        foreach (['P495', 'P17'] as $prop) {
            if (!empty($claims[$prop][0]['mainsnak']['datavalue']['value']['id'])) {
                $countryQid = $claims[$prop][0]['mainsnak']['datavalue']['value']['id'];
                break;
            }
        }
        if ($countryQid === '') {
            return '';
        }

        $cUrl = 'https://www.wikidata.org/wiki/Special:EntityData/'
            . rawurlencode($countryQid) . '.json';
        $cData   = $this->httpGetJson($cUrl);
        $labels  = $cData['entities'][$countryQid]['labels'] ?? [];

        if (!empty($labels['it']['value'])) {
            return $labels['it']['value'];
        }
        if (!empty($labels['en']['value'])) {
            return $labels['en']['value'];
        }
        return '';
    }

    // ============================================================
    // LAST.FM (fallback)
    // ============================================================

    private function fetchLastFmBio(string $name, string $mbid = ''): array
    {
        if (!defined('LASTFM_API_KEY') || LASTFM_API_KEY === '') {
            return ['bio' => '', 'url' => ''];
        }

        $url = 'https://ws.audioscrobbler.com/2.0/?method=artist.getinfo'
            . '&api_key=' . LASTFM_API_KEY
            . '&format=json&lang=en';

        if ($mbid !== '') {
            $url .= '&mbid=' . urlencode($mbid);
        } else {
            $url .= '&artist=' . urlencode($name);
        }

        $data = $this->httpGetJson($url);

        $raw = $data['artist']['bio']['content'] ?? '';
        if ($raw === '') {
            return ['bio' => '', 'url' => ''];
        }

        $bio = strip_tags($raw);
        $bio = preg_replace('/\s*Read more on Last\.fm.*$/is', '', $bio);
        $bio = trim($bio);

        return [
            'bio' => $bio,
            'url' => $data['artist']['url'] ?? '',
        ];
    }

    // ============================================================
    // IMMAGINE — download locale opzionale
    // ============================================================

    public function downloadImage(string $imageUrl): ?string
    {
        if ($imageUrl === '') {
            return null;
        }

        $dir = UPLOAD_PATH . '/artists';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $bytes = $this->httpGetBinary($imageUrl);
        if ($bytes === '' || strlen($bytes) < 512) {
            return null;
        }

        $ext = strtolower(pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $ext = $this->guessExtension($bytes);
        }

        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $name;

        if (@file_put_contents($dest, $bytes) === false) {
            return null;
        }

        return 'artists/' . $name;
    }

    // ============================================================
    // DISCOGRAFIA UFFICIALE (MusicBrainz release-groups)
    // ============================================================

    /**
     * Recupera l'elenco degli ALBUM IN STUDIO UFFICIALI dell'artista.
     *
     * Strategia: interroga le RELEASE con status=official e type=album,
     * poi le raggruppa per release-group (così le varie edizioni ufficiali
     * dello stesso album contano una volta sola). In questo modo i bootleg
     * e le voci non ufficiali (che non hanno alcuna release "official")
     * vengono automaticamente esclusi.
     *
     * Filtra inoltre per primary-type "Album" senza secondary-type, così
     * restano solo gli studio album (niente live, compilation, remix...).
     *
     * @return array[] ['title' => string, 'year' => ?int,
     *                  'mb_release_group_id' => string] ordinato per anno.
     */
    public function fetchDiscography(string $mbArtistId): array
    {
        $mbArtistId = trim($mbArtistId);
        if ($mbArtistId === '') {
            return [];
        }

        // Raccoglie per release-group: tiene il primo anno (più vecchio).
        $byGroup = []; // rg_id => ['title'=>..., 'year'=>..., 'rg'=>...]
        $seen    = []; // titoli normalizzati (deduplica finale)

        // Paginazione release ufficiali di tipo album.
        $limit  = 100;
        $offset = 0;
        $guard  = 0;

        do {
            $url = 'https://musicbrainz.org/ws/2/release'
                . '?artist=' . rawurlencode($mbArtistId)
                . '&type=album&status=official'
                . '&inc=release-groups'
                . '&fmt=json&limit=' . $limit . '&offset=' . $offset;

            $data = $this->httpGetJson($url);
            usleep(self::MB_THROTTLE_US);

            $releases = $data['releases'] ?? [];
            $total    = (int) ($data['release-count'] ?? count($releases));

            foreach ($releases as $rel) {
                $rg = $rel['release-group'] ?? null;
                if (!$rg) {
                    continue;
                }

                // Solo studio album: primary "Album", nessun secondary-type
                $primary   = $rg['primary-type'] ?? '';
                $secondary = $rg['secondary-types'] ?? [];
                if (strcasecmp($primary, 'Album') !== 0 || !empty($secondary)) {
                    continue;
                }

                $rgId  = $rg['id'] ?? '';
                $title = trim($rg['title'] ?? ($rel['title'] ?? ''));
                if ($rgId === '' || $title === '') {
                    continue;
                }

                // Anno: preferisci la first-release-date del release-group
                $year = null;
                $fr   = $rg['first-release-date'] ?? ($rel['date'] ?? '');
                if ($fr !== '' && preg_match('/^(\d{4})/', $fr, $m)) {
                    $year = (int) $m[1];
                }

                if (!isset($byGroup[$rgId])) {
                    $byGroup[$rgId] = ['title' => $title, 'year' => $year, 'rg' => $rgId];
                } elseif ($year !== null) {
                    // mantieni l'anno più vecchio se ne arriva uno valido
                    $cur = $byGroup[$rgId]['year'];
                    if ($cur === null || $year < $cur) {
                        $byGroup[$rgId]['year'] = $year;
                    }
                }
            }

            $offset += $limit;
            $guard++;
        } while ($offset < $total && $guard < 6);

        // Deduplica per titolo e costruisci l'output
        $out = [];
        foreach ($byGroup as $g) {
            $key = preg_replace('/\s+/', ' ', mb_strtolower($g['title']));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'title'               => $g['title'],
                'year'                => $g['year'],
                'mb_release_group_id' => $g['rg'],
            ];
        }

        // Ordina per anno (senza anno in fondo)
        usort($out, function ($a, $b) {
            $ya = $a['year'] ?? 99999;
            $yb = $b['year'] ?? 99999;
            if ($ya === $yb) {
                return strcasecmp($a['title'], $b['title']);
            }
            return $ya - $yb;
        });

        return $out;
    }

    private function guessExtension(string $bytes): string
    {
        $head = substr($bytes, 0, 12);
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0)          return 'jpg';
        if (strncmp($head, "\x89PNG", 4) === 0)               return 'png';
        if (strncmp($head, "GIF8", 4) === 0)                  return 'gif';
        if (substr($head, 0, 4) === 'RIFF'
            && substr($head, 8, 4) === 'WEBP')                return 'webp';
        return 'jpg';
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function emptyResult(): array
    {
        return [
            'mb_artist_id' => '',
            'bio'          => '',
            'bio_source'   => '',
            'bio_lang'     => '',
            'bio_url'      => '',
            'image_url'    => '',
            'image_source' => '',
            'country'      => '',
            'active_from'  => null,
            'active_to'    => null,
        ];
    }

    private function trimBio(string $bio): string
    {
        $bio = trim(preg_replace('/\n{3,}/', "\n\n", $bio));
        if (mb_strlen($bio) <= self::BIO_MAX_CHARS) {
            return $bio;
        }
        $cut = mb_substr($bio, 0, self::BIO_MAX_CHARS);
        $lastDot = mb_strrpos($cut, '. ');
        if ($lastDot !== false && $lastDot > 400) {
            $cut = mb_substr($cut, 0, $lastDot + 1);
        }
        return trim($cut);
    }

    private function httpGetJson(string $url): array
    {
        $raw = $this->httpGetBinary($url, 'application/json');
        if ($raw === '') {
            return [];
        }
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    /**
     * GET generico che SEGUE i redirect (necessario per Special:FilePath)
     * e invia uno User-Agent (richiesto da MusicBrainz/Wikimedia).
     */
    private function httpGetBinary(string $url, string $accept = '*/*'): string
    {
        $ua = defined('APP_USER_AGENT') ? APP_USER_AGENT : 'GrizzlyMusicArchive/1.0';

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => "User-Agent: {$ua}\r\nAccept: {$accept}\r\n",
                'timeout'         => 15,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'ignore_errors'   => true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        return ($raw === false) ? '' : $raw;
    }
}
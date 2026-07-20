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

        // Tracklist MB "valida" per numero di tracce ma senza NESSUNA
        // durata: capita quando la release scelta non ha il campo
        // "length" valorizzato sui singoli track (es. Urban Hymns / The
        // Verve). isValidMb() guarda solo il conteggio tracce, quindi
        // senza questo controllo Discogs non veniva mai interpellato se
        // genere/etichetta/cover erano già presenti, lasciando le durate
        // a 0 anche quando Discogs le avrebbe.
        $mbDurationsMissing = $this->tracksHaveNoDuration($result['tracks']);

        // ================= DISCOGS (UNICA CHIAMATA) =================

        $discogs = null;

        // chiama Discogs SOLO se serve qualcosa
        if (
            !$isMbValid
            || $mbDurationsMissing
            || empty($result['tracks']) || count($result['tracks']) < 5
            || empty($result['genre'])
            || empty($result['label'])
            || empty($result['cover'])
        ) {
            $discogs = $this->searchDiscogs($artist, $album, $year);
        }

        // usa il risultato UNA SOLA VOLTA
        if (!empty($discogs)) {

            // FIX: Discogs può solo MIGLIORARE o PAREGGIARE la tracklist,
            // mai ridurla. Prima, quando $isMbValid era false (es. release
            // MusicBrainz con "deluxe"/"anniversary" nel titolo), Discogs
            // sovrascriveva SEMPRE a prescindere dal numero di tracce
            // trovate — bastava un match Discogs sbagliato/parziale (singolo,
            // sampler, edizione incompleta) per buttare via una tracklist
            // MusicBrainz già corretta e completa.
            //
            // FIX 2 (2026-07): la sola regola "più tracce vince" era un
            // boomerang — se Discogs pescava una Deluxe/Collector's
            // Edition (33 righe), sostituiva la tracklist MB CORRETTA
            // dell'edizione standard (12 tracce). Ora una tracklist MB
            // valida non viene MAI sostituita: Discogs può rimpiazzarla
            // solo se quella MB è assente o invalida. Le durate mancanti
            // restano gestite dal blocco dedicato più sotto.
            if (
                !empty($discogs['tracks'])
                && (
                    empty($result['tracks'])
                    || (
                        !$isMbValid
                        && count($discogs['tracks']) >= count($result['tracks'])
                    )
                )
            ) {
                $result['tracks'] = $this->cleanTracks($discogs['tracks']);
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

            // FIX DURATE MANCANTI: se il branch sopra NON ha sostituito
            // la tracklist (es. Discogs ha meno tracce di MB) e le durate
            // sono ancora tutte a 0, riempiamo SOLO le durate per
            // posizione, senza toccare titoli/posizioni già assegnati da
            // MusicBrainz — stessa filosofia del FIX precedente: Discogs
            // può solo MIGLIORARE, mai sostituire dati già corretti.
            if (
                $mbDurationsMissing
                && $this->tracksHaveNoDuration($result['tracks'])
                && !empty($discogs['tracks'])
            ) {
                foreach ($result['tracks'] as $i => &$track) {
                    if (!empty($discogs['tracks'][$i]['duration'])) {
                        $track['duration'] = $discogs['tracks'][$i]['duration'];
                    }
                }
                unset($track);
            }
        }

        // ================= LASTFM (FALLBACK: TRACKLIST VUOTA O DURATE MANCANTI) =================
        // Ramo originale invariato: tracklist assente → Last.fm la fornisce.
        // Nuovo ramo (elseif): tracklist già presente da MB/Discogs ma con
        // TUTTE le durate a 0 (es. Urban Hymns — né la release MusicBrainz
        // né quella Discogs scelta hanno il campo durata compilato per
        // singolo track). Last.fm viene interpellato SOLO per le durate,
        // riempite per posizione senza toccare titoli/posizioni già
        // assegnati — stessa filosofia del fix Discogs qui sopra.
        if (empty($result['tracks']) || $this->tracksHaveNoDuration($result['tracks'])) {
            $lfm = $this->getLastFmAlbumInfo($artistRaw, $albumRaw);

            if (empty($result['tracks'])) {
                if (!empty($lfm['tracks'])) {
                    $result['tracks'] = $this->cleanTracks($lfm['tracks']);
                }
            } elseif (!empty($lfm['tracks'])) {
                foreach ($result['tracks'] as $i => &$track) {
                    if (!empty($lfm['tracks'][$i]['duration'])) {
                        $track['duration'] = $lfm['tracks'][$i]['duration'];
                    }
                }
                unset($track);
            }
        }

        $result['debug_source'] = !$isMbValid ? 'discogs' : 'musicbrainz';

        return $result;
    }

    // Semplificata: la validazione "è la release giusta?" (tipo release,
    // artista accreditato) avviene ORA a monte in pickBestRelease() —
    // qui serve solo capire se ci fidiamo della tracklist MusicBrainz
    // già trovata. Il vecchio controllo sulle parole "deluxe/anniversary"
    // era il vero innesco del bug: bastava un titolo con quella parola
    // per far scartare in blocco una tracklist completa e corretta,
    // rimpiazzata poi ciecamente da Discogs (vedi fix in search()).
    private function isValidMb(array $mb, array $tracks): bool
    {
        if (empty($mb)) return false;
        if (count($tracks) < 3) return false;

        return true;
    }

    // Vero SOLO se la tracklist esiste ma NESSUNA traccia ha una durata
    // maggiore di 0. Serve a distinguere il caso "tracklist valida ma
    // senza durate" da "tracklist valida e completa", cosa che
    // isValidMb() non fa (guarda solo il numero di tracce).
    private function tracksHaveNoDuration(array $tracks): bool
    {
        if (empty($tracks)) return false;

        foreach ($tracks as $t) {
            if (!empty($t['duration'])) return false;
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
            $best = $this->pickBestRelease($data['releases'], $album, $year, $artist);
            if (!empty($best)) return $best;
        }

        // 2 FALLBACK — stessi campi (artist/release) ma senza frase esatta
        // e senza vincolo di anno: a volte il titolo ufficiale ha piccole
        // differenze di punteggiatura. Resta comunque scoped sui campi
        // artista/titolo — NON è più una ricerca a testo libero: era
        // proprio questo il varco da cui passavano compilation "Various
        // Artists" e omonimi, perché non c'era alcun vincolo sull'artista.
        $query2 = 'release:(' . $album . ') AND artist:(' . $artist . ')';

        $url = 'https://musicbrainz.org/ws/2/release/?query='
            . rawurlencode($query2)
            . '&fmt=json&limit=15&inc=release-groups+labels+genres+media';

        $data = $this->httpGetJson($url);

        if (!empty($data['releases'])) {
            $best = $this->pickBestRelease($data['releases'], $album, $year, $artist);
            if (!empty($best)) return $best;
        }

        // 3 ULTIMA SPIAGGIA — ricerca libera, usata solo se le due
        // precedenti non hanno trovato nulla. pickBestRelease() applica
        // comunque i controlli hard su tipo release e artista accreditato,
        // quindi anche qui non possono passare compilation o omonimi.
        $url = 'https://musicbrainz.org/ws/2/release/?query='
            . rawurlencode($artist . ' ' . $album)
            . '&fmt=json&limit=15&inc=release-groups+labels+genres+media';

        $data = $this->httpGetJson($url);

        if (!empty($data['releases'])) {
            $best = $this->pickBestRelease($data['releases'], $album, $year, $artist);
            if (!empty($best)) return $best;
        }

        return [];
    }

    private function pickBestRelease(array $releases, string $album, int $year = 0, string $artist = ''): array
    {
        $album = strtolower($album);

        // Parole nel titolo che identificano edizioni da evitare.
        // Usate SOLO per penalizzare il punteggio tra candidati già validi
        // — non per scartarli: una Deluxe Edition ha comunque la tracklist
        // giusta (di solito l'originale + bonus track in coda).
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

            // ============ SCARTI HARD — non omonimi, non tipi sbagliati ============
            //
            // Prima questi controlli non esistevano: la scelta si basava
            // solo sul punteggio di rilevanza di MusicBrainz, che NON
            // garantisce che l'artista accreditato o il tipo di release
            // siano quelli giusti (una compilation "Various Artists" con
            // dentro una traccia dallo stesso titolo poteva tranquillamente
            // "vincere" se il punteggio nativo era alto).

            // Artista accreditato: scarta compilation "Various Artists" e omonimi.
            if ($artist !== '' && !$this->artistCreditMatches($rel, $artist)) {
                continue;
            }

            // Tipo release: scarta Single, Broadcast e release-group con
            // secondary types (Compilation, Live, Soundtrack, Remix...).
            // Gli EP sono AMMESSI: per un collezionista sono dischi a tutti
            // gli effetti (es. "Jar of Flies" è un EP) — il vecchio scarto
            // duro li rendeva introvabili e faceva vincere ristampe spurie
            // con metadati scarni (anno sbagliato, niente cover).
            $rg             = $rel['release-group'] ?? [];
            $primaryType    = strtolower($rg['primary-type'] ?? '');
            $secondaryTypes = array_map('strtolower', $rg['secondary-types'] ?? []);

            if ($primaryType !== '' && $primaryType !== 'album' && $primaryType !== 'ep') {
                continue;
            }
            if (!empty($secondaryTypes)) {
                continue;
            }

            // ============ PUNTEGGIO (solo tra i candidati sopravvissuti) ============

            $title = strtolower($rel['title']);

            // Base: punteggio MusicBrainz nativo (0-100)
            $score = (float)($rel['score'] ?? 50);

            // Leggera preferenza Album > EP: a parità di titolo (EP eponimo
            // di un album) vince l'album; non basta a far vincere un album
            // sbagliato su un EP col titolo esatto cercato.
            if ($primaryType === 'album') {
                $score += 8;
            }

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

    // ----------------------------------------------------------
    // Verifica che l'artista accreditato sulla release MusicBrainz
    // corrisponda (con tolleranza) all'artista cercato. È il controllo
    // che manca per bloccare il caso più insidioso: query "larghe" che
    // agganciano compilation "Various Artists" o un omonimo che ha
    // inciso un disco con lo stesso titolo dell'album cercato.
    // ----------------------------------------------------------
    private function artistCreditMatches(array $rel, string $artist): bool
    {
        $credit = $rel['artist-credit'] ?? [];
        if (empty($credit)) {
            // Nessun dato per verificare: non blocchiamo, per non perdere
            // match legittimi quando MusicBrainz non restituisce il campo.
            return true;
        }

        $phrase = '';
        foreach ($credit as $c) {
            $phrase .= ($c['name'] ?? '') . ($c['joinphrase'] ?? '');
        }
        $phrase = strtolower(trim($phrase));
        $needle = strtolower(trim($artist));

        if ($phrase === '' || $needle === '') {
            return true;
        }

        // "Various Artists" è la bandiera rossa più comune per le compilation
        if (strpos($phrase, 'various artist') !== false) {
            return false;
        }

        similar_text($phrase, $needle, $percent);

        return $percent >= 55.0;
    }

    // Legge le tracce da TUTTI i media della release, non solo dal primo.
    //
    // FIX: la versione precedente prendeva solo $data['media'][0],
    // partendo dal presupposto che un disco con più lati (A/B/C/D)
    // fosse sempre UN solo medium — vero per un vinile singolo, ma
    // FALSO per un doppio (o multiplo) LP/CD originale: MusicBrainz
    // rappresenta ogni disco fisico come un medium separato, quindi
    // "Daydream Nation" (Sonic Youth, doppio LP) risultava con solo
    // 6 tracce invece di 12 — il secondo disco veniva scartato.
    //
    // Le release deluxe/bonus/anniversary sono già escluse a monte
    // (pickBestRelease/isValidMb scartano i titoli con questi termini),
    // quindi sommare tutti i media della release scelta non reintroduce
    // il problema che la regola originale voleva evitare: se una release
    // arriva fin qui, i suoi media sono dischi legittimi dell'album, non
    // bonus disc di un'edizione speciale.
    private function getTracksFromMusicBrainzRelease(string $mbid): array
    {
        $url  = 'https://musicbrainz.org/ws/2/release/' . $mbid . '?inc=recordings+media&fmt=json';
        $data = $this->httpGetJson($url);

        if (empty($data['media'])) return [];

        $tracks = [];
        foreach ($data['media'] as $medium) {
            if (empty($medium['tracks'])) continue;

            foreach ($medium['tracks'] as $t) {
                if (empty($t['title'])) continue;

                // FIX DURATA: 'length' qui è la durata specifica DI QUESTA
                // release — un campo che gli editor MusicBrainz valorizzano
                // raramente. La durata quasi sempre presente è invece
                // 'recording.length' (la registrazione, indipendente dalla
                // release). Prima leggevamo solo 'length', quindi la durata
                // risultava 0 per la stragrande maggioranza dei dischi
                // (Urban Hymns, Morning Glory, ecc.), anche quando
                // MusicBrainz aveva perfettamente la durata totale.
                $length = $t['length'] ?? ($t['recording']['length'] ?? null);

                $tracks[] = [
                    'position' => count($tracks) + 1,
                    'title'    => $t['title'],
                    'duration' => !empty($length) ? (int)($length / 1000) : 0
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
            . '&per_page=15';

        $headers = ['Authorization: Discogs token=' . DISCOGS_TOKEN];

        $data = $this->httpGetJson($url, $headers);

        if (empty($data['results'])) return [];

        $first = $this->pickBestDiscogsResult($data['results'], $album, $year);

        if (!$first) return [];

        $rel = $this->httpGetJson($first['resource_url'], $headers);

        $tracks = [];
        foreach ($rel['tracklist'] ?? [] as $t) {
            // Discogs nelle edizioni multi-disco include righe di
            // intestazione (type_ "heading"/"index", es. "CD 1", "CD 2"):
            // non sono tracce e non vanno importate. Le posizioni si
            // rinumerano DOPO il filtro, altrimenti restano i buchi.
            $rowType = strtolower($t['type_'] ?? 'track');
            if ($rowType !== 'track') {
                continue;
            }
            if (!empty($t['title'])) {
                $tracks[] = [
                    'position' => count($tracks) + 1,
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

        // Formati che indicano quasi sempre un disco diverso dall'album
        // completo (singolo, sampler promozionale...): scarto hard.
        // Gli EP NON sono più scartati (stesso fix del filtro MusicBrainz:
        // "Jar of Flies" è un EP) — solo lievemente penalizzati più sotto,
        // così un EP eponimo non scavalca l'album omonimo a parità di titolo.
        $avoidFormat = ['single', 'sampler', 'promo', 'flexi-disc', 'maxi-single'];

        $best      = null;
        $bestScore = -9999;

        foreach ($results as $row) {
            $title = strtolower($row['title'] ?? '');
            if ($title === '') continue;

            $formats = array_map('strtolower', $row['format'] ?? []);
            $isBadFormat = false;
            foreach ($avoidFormat as $f) {
                if (in_array($f, $formats, true)) { $isBadFormat = true; break; }
            }
            if ($isBadFormat) continue;

            similar_text($album, $title, $score);

            // Lieve penalità EP: a parità di titolo vince l'album,
            // ma se l'EP è l'unico match (o il titolo cercato È un EP)
            // resta selezionabile.
            if (in_array('ep', $formats, true)) {
                $score -= 8;
            }

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

            // Bonus master_id (release principale) — alzato: è quasi
            // sempre l'edizione di riferimento tra le tante ristampe
            if (!empty($row['master_id'])) $score += 20;

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

            // Salta tracce SOLO se le parole-marcatore compaiono dentro
            // un'annotazione tra parentesi/quadre o dopo un trattino
            // finale, es. "Song (Alternate Take)", "Song (Remix)",
            // "Song - Studio Jam". MAI sull'intero titolo: altrimenti
            // un titolo come "Love Takes Miles" verrebbe scartato solo
            // perché contiene "take" dentro "takes".
            $annotation = '';
            if (preg_match('/[\(\[]([^)\]]*)[\)\]]/', $titleLower, $m)) {
                $annotation .= ' ' . $m[1];
            }
            if (preg_match('/\s-\s(.+)$/', $titleLower, $m2)) {
                $annotation .= ' ' . $m2[1];
            }

            if ($annotation !== '') {
                foreach ($skipWords as $word) {
                    if (strpos($annotation, $word) !== false) {
                        continue 2;
                    }
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
    //
    // FIX: la CDN immagini di Discogs (img.discogs.com/api-img.discogs.com)
    // risponde 403 Forbidden a chi manda uno User-Agent generico/senza
    // contatto — comportamento documentato e ricorrente (forum Discogs),
    // non un problema di rete intermittente. Prima qui veniva mandato
    // "MusicArchive/1.0" hardcoded, diverso (e "peggiore") di
    // APP_USER_AGENT già usato con successo da httpGetJson() per le
    // chiamate JSON. Ora è lo stesso ovunque.
    // ----------------------------------------------------------
    public function downloadCover(string $url): ?string
    {
        if (!defined('COVERS_PATH') || empty($url)) return null;
        if (!is_dir(COVERS_PATH)) mkdir(COVERS_PATH, 0755, true);

        $ua = defined('APP_USER_AGENT') ? APP_USER_AGENT : 'MusicArchive/1.0';

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => 20,
                'follow_location' => true,
                'max_redirects'   => 10,
                'header'          => "User-Agent: {$ua}\r\nAccept: image/*",
                // Senza questo, su risposta non-2xx (es. 403) lo stream
                // wrapper fallisce e basta, senza dare accesso allo status
                // reale: impossibile capire perché. Con ignore_errors
                // possiamo leggere $http_response_header anche sugli errori.
                'ignore_errors'   => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $imageData  = @file_get_contents($url, false, $ctx);
        $statusCode = $this->extractHttpStatus($http_response_header ?? []);

        if ($imageData === false) {
            $this->logCoverFailure($url, $statusCode, 'connection_failed');
            return null;
        }

        if ($statusCode !== null && $statusCode >= 400) {
            $this->logCoverFailure($url, $statusCode, 'http_error');
            return null;
        }

        if (strlen($imageData) < 500) {
            $this->logCoverFailure($url, $statusCode, 'too_small');
            return null;
        }

        $sig    = substr($imageData, 0, 4);
        $isJpeg = substr($sig, 0, 2) === "\xFF\xD8";
        $isPng  = $sig === "\x89PNG";
        $isWebp = substr($imageData, 8, 4) === 'WEBP';
        if (!$isJpeg && !$isPng && !$isWebp) {
            $this->logCoverFailure($url, $statusCode, 'invalid_signature');
            return null;
        }

        $ext      = $isPng ? 'png' : ($isWebp ? 'webp' : 'jpg');
        $filename = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest     = COVERS_PATH . '/' . $filename;

        if (file_put_contents($dest, $imageData) !== false) {
            // Normalizza alla fonte (max 1200px, q85) — best-effort
            ImageOptimizer::optimize($dest);
            return 'covers/' . $filename;
        }

        $this->logCoverFailure($url, $statusCode, 'write_failed');
        return null;
    }

    // Estrae l'ultimo status code HTTP da $http_response_header (l'ultimo,
    // non il primo: con i redirect ce n'è uno per hop, l'ultimo è quello
    // finale dopo aver seguito la catena).
    private function extractHttpStatus(array $headers): ?int
    {
        $status = null;
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int)$m[1];
            }
        }
        return $status;
    }

    // Log leggero su file dei fallimenti di download cover: prima non
    // esisteva nessuna traccia, quindi un 403 spariva nel nulla e non si
    // poteva mai sapere se era un caso isolato o un pattern ricorrente.
    private function logCoverFailure(string $url, ?int $statusCode, string $reason): void
    {
        if (!defined('BASE_PATH')) return;

        $dir = BASE_PATH . '/cache';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $line = sprintf(
            "[%s] reason=%s status=%s url=%s\n",
            date('Y-m-d H:i:s'),
            $reason,
            $statusCode ?? 'n/a',
            $url
        );

        @file_put_contents($dir . '/cover_download_errors.log', $line, FILE_APPEND | LOCK_EX);
    }
}

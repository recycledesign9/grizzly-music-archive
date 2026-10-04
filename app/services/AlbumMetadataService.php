<?php

require_once __DIR__ . '/ExternalApiConfig.php';

class AlbumMetadataService
{
    private const MAX_TRACKS = 40;

    // EDIZIONI (ottobre 2026). Versione della logica che costruisce le
    // varianti di tracklist di un release-group: cambiandola, le cache
    // in cache/album-editions/ scritte con la logica precedente vengono
    // ignorate e ricostruite alla prima ricerca.
    // v2: titoli confrontati con tolleranza, varianti quasi identiche
    // unite, frammenti (singoli, estratti) esclusi dall'elenco.
    private const EDITIONS_LOGIC_VERSION = 2;

    // Durata della cache delle edizioni: il catalogo di un album cambia
    // di rado, ma nuove ristampe vengono aggiunte a MusicBrainz nel tempo.
    private const EDITIONS_CACHE_TTL = 2592000; // 30 giorni

    // Browse MusicBrainz con inc=recordings. Il limite richiesto è 100
    // release per pagina, ma MusicBrainz ne restituisce meno: si ferma
    // intorno alle 500 tracce complessive per pagina (verificato su
    // Slipknot, ottobre 2026: release-count 39, 29 release lette nella
    // prima pagina, 486 tracce). La paginazione avanza quindi per numero
    // di release effettivamente ricevute, non per multipli di 100.
    // 8 pagine coprono circa 4000 tracce, cioè 250-300 release di un
    // album di 13-15 brani. Oltre questa soglia l'elenco viene marcato
    // come parziale; la release scelta dalla ricerca viene comunque
    // aggiunta. La prima ricerca su un album con molte stampe può
    // richiedere 15-25 secondi; le successive leggono la cache.
    private const EDITIONS_PAGE_SIZE = 100;
    private const EDITIONS_MAX_PAGES = 8;

    // Numero massimo di titoli elencati come differenza tra due varianti.
    private const EDITIONS_DIFF_MAX = 6;

    // Numero minimo di stampe perché una variante che TOGLIE tracce
    // dell'originale faccia comparire l'elenco delle edizioni. Una
    // variante con una sola release è spesso un inserimento incompleto
    // su MusicBrainz, non una vera edizione alternativa.
    private const EDITIONS_EXCEPTION_MIN_RELEASES = 2;

    // Una variante con meno di questa frazione delle tracce dell'originale
    // è un frammento collegato all'album (singolo, estratto digitale da
    // 1-2 brani) e non un'edizione: viene esclusa dall'elenco.
    private const EDITIONS_FRAGMENT_RATIO = 0.5;

    // Tolleranza nel confronto tra titoli: lunghezza minima del titolo
    // più corto perché valga il contenimento ("A Little Help From My
    // Friends" dentro "With a Little Help From My Friends", "Lucy in
    // the Sky With Diamonds" dentro la versione "(Dolby Atmos)"), e
    // quota massima di caratteri diversi per i refusi di trascrizione.
    private const TITLE_MATCH_MIN_CONTAINED = 5;
    private const TITLE_MATCH_MAX_DISTANCE  = 0.15;

    // MusicBrainz consente circa una richiesta al secondo per client.
    // Manteniamo anche il paese principale dell'artista per scegliere
    // l'edizione territoriale più coerente (GB per Radiohead/Coldplay,
    // US per Interpol) ed escludere le edizioni giapponesi.
    private float $lastMusicBrainzRequestAt = 0.0;
    private string $preferredArtistCountry = '';

    // ----------------------------------------------------------
    // $currentMbid (facoltativo): MBID della release già salvata sulla
    // scheda in modifica. Se appartiene a una delle varianti trovate,
    // quella variante resta selezionata e l'MBID non cambia: un
    // aggiornamento dalle fonti non deve mai spostare in silenzio la
    // scheda su un'edizione diversa da quella scelta.
    // ----------------------------------------------------------
    public function search(string $artist, string $album, int $year = 0, string $currentMbid = ''): array
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

            // Genere da MusicBrainz release-group. MusicBrainz non
            // garantisce l'ordine dell'array genres: topGenreName()
            // sceglie il genere con più voti (campo count), così due
            // ricerche sullo stesso album danno lo stesso genere.
            $topGenre = $this->topGenreName($mb['release-group']['genres'] ?? []);
            if ($topGenre === '') {
                $topGenre = $this->topGenreName($mb['genres'] ?? []);
            }
            if ($topGenre !== '') {
                $result['genre'] = ucfirst($topGenre);
            }

            // Etichetta della stessa release scelta per MBID, cover e
            // tracklist. La release viene selezionata privilegiando il
            // paese dell'artista ed escludendo in modo rigido il Giappone.
            if (!empty($mb['label-info'][0]['label']['name'])) {
                $result['label'] = $mb['label-info'][0]['label']['name'];
            }

            // Campi diagnostici: il frontend li ignora, ma permettono di
            // verificare subito quale mercato è stato selezionato.
            $result['release_country'] = $mb['country'] ?? '';
            $result['artist_country']  = $this->preferredArtistCountry;

            // ================= EDIZIONI DEL RELEASE-GROUP =================
            // La ricerca qui sopra serve solo a identificare l'ALBUM
            // (release-group). L'edizione si sceglie tra TUTTE le release
            // ufficiali del gruppo, raggruppate per tracklist: prima la
            // scelta avveniva tra le 10-15 release restituite dalla
            // ricerca testuale e dipendeva dall'anno inviato dal form,
            // che a sua volta veniva riscritto dalla ricerca precedente
            // (Slipknot: anno 2000 -> digipak a 19 tracce, riconfermata
            // a ogni aggiornamento).
            $rgId     = (string)($mb['release-group']['id'] ?? '');
            $editions = $rgId !== '' ? $this->getReleaseGroupEditions($rgId, $mb) : [];

            if (!empty($editions['groups'])) {
                $groups      = $editions['groups'];
                $defaultIdx  = (int)$editions['default_index'];
                $selectedIdx = $defaultIdx;
                $selectedMbid = '';

                $currentMbid = strtolower(trim($currentMbid));
                if ($this->isUuid($currentMbid)) {
                    foreach ($groups as $gi => $g) {
                        if (in_array($currentMbid, $g['release_ids'], true)) {
                            $selectedIdx  = $gi;
                            $selectedMbid = $currentMbid;
                            break;
                        }
                    }
                }

                $sel = $groups[$selectedIdx];
                if ($selectedMbid === '') {
                    $selectedMbid = $sel['mbid'];
                }

                $result['mbid']   = $selectedMbid;
                $result['tracks'] = $sel['tracks'];
                if ($sel['label'] !== '') {
                    $result['label'] = $sel['label'];
                }
                $result['release_country'] = $sel['country'];

                // Anno = prima pubblicazione dell'album, indipendente
                // dall'edizione posseduta (salvata tramite MBID).
                if (!empty($editions['first_year'])) {
                    $result['year'] = (string)$editions['first_year'];
                }

                // L'elenco delle edizioni compare SOLO nei casi limite:
                //  - esiste una variante diffusa che toglie o sostituisce
                //    tracce dell'originale (Slipknot: ristampa senza
                //    Purity e Frail Limb Nursery, con Me Inside);
                //  - la scheda è già collegata a un'edizione diversa
                //    dall'originale, così la scelta resta visibile.
                // Deluxe, bonus track, ristampe con le stesse tracce:
                // la prima ricerca applica l'originale e basta.
                $result['release_group'] = $rgId;
                if ($selectedIdx !== $defaultIdx || $this->hasConflictingEdition($groups, $defaultIdx)) {
                    $result['editions']         = $this->publicEditions($groups, $defaultIdx, $selectedIdx, $selectedMbid);
                    $result['editions_partial'] = !empty($editions['partial']);
                }

                $cover = $this->getCoverFromCAA($selectedMbid);
                if (empty($cover)) {
                    $cover = $this->getCoverFromReleaseGroup($rgId);
                }
                if (!empty($cover)) {
                    $result['cover'] = $cover;
                }
            } elseif (!empty($mb['id'])) {
                // Percorso precedente, invariato: browse non disponibile
                // (rete, rate limit) o release-group senza tracklist.
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

        // Le varianti descrivono tracklist MusicBrainz: se la tracklist
        // finale arriva da Discogs o Last.fm non sono più confrontabili
        // con quella applicata e non vengono proposte.
        if (!$isMbValid) {
            unset($result['editions'], $result['editions_partial'], $result['release_group']);
        }

        return $result;
    }

    // ----------------------------------------------------------
    // Dati di una singola edizione, scelta dall'utente nell'elenco
    // delle varianti del form. $releaseGroupId e $releaseMbid arrivano
    // dalla risposta di search(); le varianti vengono lette dalla cache
    // (o ricostruite se scaduta). Restituisce [] se l'MBID non
    // appartiene al release-group indicato.
    // ----------------------------------------------------------
    public function fetchEdition(string $releaseGroupId, string $releaseMbid): array
    {
        $releaseGroupId = strtolower(trim($releaseGroupId));
        $releaseMbid    = strtolower(trim($releaseMbid));

        if (!$this->isUuid($releaseGroupId) || !$this->isUuid($releaseMbid)) {
            return [];
        }

        $editions = $this->getReleaseGroupEditions($releaseGroupId, []);
        if (empty($editions['groups'])) {
            return [];
        }

        $groups = $editions['groups'];
        foreach ($groups as $gi => $g) {
            if (!in_array($releaseMbid, $g['release_ids'], true)) {
                continue;
            }

            $public = $this->publicEditions($groups, (int)$editions['default_index'], $gi, $releaseMbid);

            $cover = $this->getCoverFromCAA($releaseMbid);
            if (empty($cover)) {
                $cover = $this->getCoverFromReleaseGroup($releaseGroupId);
            }

            return [
                'mbid'          => $releaseMbid,
                'release_group' => $releaseGroupId,
                'label'         => $g['label'],
                'tracks'        => $g['tracks'],
                'cover'         => $cover,
                'cover_local'   => '',
                'edition'       => $public[$gi],
            ];
        }

        return [];
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

    // $timeout: 10 s per le chiamate normali; il browse delle edizioni
    // (fino a 100 release con tracce) usa un valore più alto.
    private function httpGetJson(string $url, array $headers = [], int $timeout = 10): array
    {
        $ua = defined('APP_USER_AGENT') ? APP_USER_AGENT : 'MusicArchive/1.0';
        $isMusicBrainz = stripos($url, 'https://musicbrainz.org/') === 0;

        $attempts = $isMusicBrainz ? 2 : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($isMusicBrainz) {
                $this->throttleMusicBrainz();
            }

            $context = stream_context_create([
                'http' => [
                    'header' => implode("\r\n", array_merge([
                        'User-Agent: ' . $ua,
                        'Accept: application/json'
                    ], $headers)),
                    'timeout' => $timeout,
                    'ignore_errors' => true
                ]
            ]);

            $json = @file_get_contents($url, false, $context);
            $responseHeaders = $http_response_header ?? [];
            $statusCode = $this->extractHttpStatus($responseHeaders);

            // MusicBrainz usa 429/503 quando il client supera il rate limit
            // o il servizio è temporaneamente occupato. Un solo retry,
            // sempre rispettando l'intervallo minimo tra le richieste.
            if ($isMusicBrainz && in_array($statusCode, [429, 503], true) && $attempt < $attempts) {
                continue;
            }

            if ($json === false || $json === null) {
                return [];
            }

            if ($statusCode !== null && $statusCode >= 400) {
                return [];
            }

            $isJson = false;
            foreach ($responseHeaders as $h) {
                if (stripos($h, 'application/json') !== false) {
                    $isJson = true;
                    break;
                }
            }
            if (!$isJson) {
                return [];
            }

            $decoded = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function throttleMusicBrainz(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastMusicBrainzRequestAt;
        $minimumInterval = 1.10;

        if ($this->lastMusicBrainzRequestAt > 0.0 && $elapsed < $minimumInterval) {
            usleep((int)(($minimumInterval - $elapsed) * 1000000));
        }

        $this->lastMusicBrainzRequestAt = microtime(true);
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
            $this->resolvePreferredArtistCountry($data['releases']);
            $best = $this->pickBestRelease($data['releases'], $album, $year, $artist, $this->preferredArtistCountry);
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
            $this->resolvePreferredArtistCountry($data['releases']);
            $best = $this->pickBestRelease($data['releases'], $album, $year, $artist, $this->preferredArtistCountry);
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
            $this->resolvePreferredArtistCountry($data['releases']);
            $best = $this->pickBestRelease($data['releases'], $album, $year, $artist, $this->preferredArtistCountry);
            if (!empty($best)) return $best;
        }

        return [];
    }

    private function pickBestRelease(
        array $releases,
        string $album,
        int $year = 0,
        string $artist = '',
        string $preferredCountry = ''
    ): array
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

        // FIX (In Rainbows): esiste almeno un candidato a DISCO SINGOLO
        // che supera gli scarti hard (artista, tipo release, non-giapponese)?
        // Se sì, le release multi-disco vanno ESCLUSE, non solo penalizzate:
        // la penalità -25/disco veniva sovrastata dal bonus territoriale +80,
        // facendo vincere la discbox 2-CD (In Rainbows + Disk 2 = 18 tracce,
        // etichetta _Xurbia_Xendless, cover del Disk 2). I doppi album veri
        // (Daydream Nation) NON hanno un candidato a disco singolo, quindi per
        // loro questa regola non scatta e continuano a funzionare.
        $singleDiscExists = false;
        foreach ($releases as $rel) {
            if (empty($rel['title'])) continue;
            if ($this->isJapaneseMusicBrainzRelease($rel)) continue;
            if ($artist !== '' && !$this->artistCreditMatches($rel, $artist)) continue;

            $statusLow = strtolower(trim($rel['status'] ?? ''));
            if ($statusLow !== '' && $statusLow !== 'official') continue;

            $rg  = $rel['release-group'] ?? [];
            $pt  = strtolower($rg['primary-type'] ?? '');
            $st  = array_map('strtolower', $rg['secondary-types'] ?? []);
            if ($pt !== '' && $pt !== 'album' && $pt !== 'ep') continue;
            if (!empty($st)) continue;

            $mc = (int)($rel['media-count'] ?? count($rel['media'] ?? []) ?: 1);
            if ($mc <= 1) { $singleDiscExists = true; break; }
        }

        $best      = null;
        $bestScore = -9999;

        foreach ($releases as $rel) {
            if (empty($rel['title'])) continue;

            // ESCLUSIONE RIGIDA DELLE EDIZIONI GIAPPONESI. MusicBrainz
            // può attribuire loro uno score elevato perché sono molto ben
            // documentate, ma per Grizzly non devono mai essere usate per
            // cover, MBID o tracklist automatica.
            if ($this->isJapaneseMusicBrainzRelease($rel)) {
                continue;
            }

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

            // SCARTO NON-OFFICIAL: la ricerca In Rainbows include una
            // release "Promotion" a 18 tracce (US 2008) che non deve mai
            // vincere. Scartiamo promo/bootleg/withdrawn/pseudo-release in
            // modo rigido. Le release SENZA campo status esplicito NON
            // vengono toccate: alcune release legittime non lo espongono e
            // scartarle reintrodurrebbe buchi.
            $statusLow = strtolower(trim($rel['status'] ?? ''));
            if ($statusLow !== '' && $statusLow !== 'official') {
                continue;
            }

            // SCARTO MULTI-DISCO: se esiste un'edizione a disco singolo
            // valida, questa multi-disco è quasi certamente una discbox /
            // edizione speciale (bonus disc). La saltiamo del tutto invece
            // di penalizzarla, perché la penalità -25/disco non basta a
            // battere il bonus territoriale. Se NON esistono candidati a
            // disco singolo (doppio album originale), la escludiamo NON.
            $mediaCountHard = (int)($rel['media-count'] ?? count($rel['media'] ?? []) ?: 1);
            if ($singleDiscExists && $mediaCountHard > 1) {
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

            // Mercato territoriale: il paese principale dell'artista
            // è il criterio dominante tra release altrimenti equivalenti.
            // GB per Radiohead/Coldplay, US per Interpol. Le edizioni
            // Europe/Worldwide restano fallback validi.
            $releaseCountry = strtoupper(trim($rel['country'] ?? ''));
            $preferredCountry = strtoupper(trim($preferredCountry));

            if ($preferredCountry !== '' && $releaseCountry !== '') {
                if ($releaseCountry === $preferredCountry) {
                    $score += 80;
                } elseif (in_array($releaseCountry, ['XE', 'XW'], true)) {
                    $score += 12;
                } else {
                    $score -= 12;
                }
            } elseif ($releaseCountry !== '') {
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

    // Ricava una sola volta il paese principale dell'artista usando
    // l'MBID presente nell'artist-credit dei risultati di ricerca.
    private function resolvePreferredArtistCountry(array $releases): void
    {
        if ($this->preferredArtistCountry !== '') {
            return;
        }

        $artistMbid = '';
        foreach ($releases as $rel) {
            foreach (($rel['artist-credit'] ?? []) as $credit) {
                $candidate = trim($credit['artist']['id'] ?? '');
                if ($candidate !== '') {
                    $artistMbid = $candidate;
                    break 2;
                }
            }
        }

        if ($artistMbid === '') {
            return;
        }

        $url = 'https://musicbrainz.org/ws/2/artist/'
            . rawurlencode($artistMbid)
            . '?fmt=json';

        $data = $this->httpGetJson($url);

        $country = strtoupper(trim($data['country'] ?? ''));
        if ($country === '' && !empty($data['area']['iso-3166-1-codes'][0])) {
            $country = strtoupper(trim($data['area']['iso-3166-1-codes'][0]));
        }

        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $this->preferredArtistCountry = $country;
        }
    }

    private function isJapaneseMusicBrainzRelease(array $release): bool
    {
        $country  = strtoupper(trim($release['country'] ?? ''));
        $language = strtolower(trim($release['text-representation']['language'] ?? ''));
        $script   = strtolower(trim($release['text-representation']['script'] ?? ''));

        return $country === 'JP'
            || $language === 'jpn'
            || $script === 'jpan';
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

        return $this->tracksFromMedia($data['media']);
    }

    // Tracce di tutti i media di una release MusicBrainz (lookup o browse).
    private function tracksFromMedia(array $media): array
    {
        $tracks = [];
        foreach ($media as $medium) {
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

                // Millisecondi arrotondati al secondo: il troncamento
                // mostrava 0:35 per una traccia di 35,6 s (742617000027
                // di Slipknot, 0:36 su copertina e Wikipedia).
                $tracks[] = [
                    'position' => count($tracks) + 1,
                    'title'    => $t['title'],
                    'duration' => !empty($length) ? (int)round($length / 1000) : 0
                ];
            }
        }

        return $tracks;
    }

    // ================= EDIZIONI (VARIANTI DI TRACKLIST) =================
    //
    // Un album (release-group) ha spesso decine di release: stampe per
    // paese, ristampe, formati diversi. Quasi tutte hanno la stessa
    // tracklist; alcune no (Slipknot: prima stampa 1999 con Purity,
    // ristampa senza Purity e con Me Inside, digipak 2000 con bonus).
    // Le release vengono quindi raggruppate per "firma" della tracklist,
    // cioè la sequenza dei titoli normalizzati. Le durate non entrano
    // nella firma: differenze di qualche secondo tra stampe non indicano
    // una tracklist diversa.
    //
    // Restituisce:
    //   groups        varianti ordinate per prima pubblicazione
    //   default_index variante proposta come originale
    //   first_year    anno di prima pubblicazione dell'album
    //   partial       true se il gruppo ha più release di quelle lette
    // oppure [] se MusicBrainz non risponde o non espone le tracce:
    // in quel caso search() usa il percorso precedente.
    // ----------------------------------------------------------
    private function getReleaseGroupEditions(string $rgId, array $searchRelease): array
    {
        $rgId = strtolower(trim($rgId));
        if (!$this->isUuid($rgId)) {
            return [];
        }

        $cached = $this->readEditionsCache($rgId);
        if ($cached !== null) {
            return $cached;
        }

        $releases = [];
        $complete = true;  // nessun errore di rete durante il browse
        $partial  = false; // release oltre il limite di pagine
        $offset   = 0;

        for ($page = 0; $page < self::EDITIONS_MAX_PAGES; $page++) {
            $url = 'https://musicbrainz.org/ws/2/release?release-group=' . $rgId
                . '&inc=media+labels+recordings&fmt=json'
                . '&limit=' . self::EDITIONS_PAGE_SIZE
                . '&offset=' . $offset;

            $data = $this->httpGetJson($url, [], 25);

            if (!isset($data['releases']) || !is_array($data['releases'])) {
                $complete = false;
                break;
            }

            foreach ($data['releases'] as $rel) {
                $releases[] = $rel;
            }

            $pageCount = count($data['releases']);
            $offset   += $pageCount;
            $total     = (int)($data['release-count'] ?? 0);

            if ($pageCount === 0 || $offset >= $total) {
                break;
            }
            if ($page === self::EDITIONS_MAX_PAGES - 1) {
                $partial = true;
            }
        }

        if (empty($releases)) {
            return [];
        }

        // La release identificata dalla ricerca deve far parte del
        // confronto anche quando il browse è stato troncato.
        $searchId = strtolower((string)($searchRelease['id'] ?? ''));
        if ($this->isUuid($searchId)) {
            $present = false;
            foreach ($releases as $rel) {
                if (strtolower((string)($rel['id'] ?? '')) === $searchId) {
                    $present = true;
                    break;
                }
            }
            if (!$present) {
                $lookup = $this->httpGetJson(
                    'https://musicbrainz.org/ws/2/release/' . $searchId . '?inc=media+labels+recordings&fmt=json'
                );
                if (!empty($lookup['id'])) {
                    $releases[] = $lookup;
                }
            }
        }

        $built = $this->buildEditionGroups($releases);
        if (empty($built['groups'])) {
            return [];
        }

        $built['partial'] = $partial || !$complete;

        // Cache solo se il browse si è concluso senza errori di rete:
        // un elenco monco per un 503 non deve restare in cache 30 giorni.
        if ($complete) {
            $this->writeEditionsCache($rgId, $built);
        }

        return $built;
    }

    private function buildEditionGroups(array $releases): array
    {
        $preferred = strtoupper(trim($this->preferredArtistCountry));

        $byKey      = [];
        $seenIds    = [];
        $durationOf = []; // titolo normalizzato -> durata, per riempire i buchi

        foreach ($releases as $rel) {
            $id = strtolower((string)($rel['id'] ?? ''));
            if (!$this->isUuid($id) || isset($seenIds[$id])) {
                continue;
            }
            $seenIds[$id] = true;

            // Stesse regole della ricerca: solo release ufficiali (quelle
            // senza status restano) e nessuna edizione giapponese.
            $status = strtolower(trim($rel['status'] ?? ''));
            if ($status !== '' && $status !== 'official') {
                continue;
            }
            if ($this->isJapaneseMusicBrainzRelease($rel)) {
                continue;
            }

            $media = $rel['media'] ?? [];
            if (empty($media)) {
                continue;
            }

            $tracks = $this->cleanTracks($this->tracksFromMedia($media));
            if (empty($tracks)) {
                continue;
            }

            $titleKeys = [];
            foreach ($tracks as $t) {
                $k = $this->titleKey($t['title']);
                $titleKeys[] = $k;
                if (!empty($t['duration']) && !isset($durationOf[$k])) {
                    $durationOf[$k] = (int)$t['duration'];
                }
            }

            $formats = [];
            foreach ($media as $m) {
                $f = trim((string)($m['format'] ?? ''));
                if ($f !== '' && !in_array($f, $formats, true)) {
                    $formats[] = $f;
                }
            }

            $date    = trim((string)($rel['date'] ?? ''));
            $country = strtoupper(trim((string)($rel['country'] ?? '')));

            $record = [
                'id'          => $id,
                'date'        => $date,
                'date_sort'   => $this->sortableDate($date),
                'year'        => $date !== '' ? (int)substr($date, 0, 4) : 0,
                'country'     => $country,
                'country_rank'=> $this->countryRank($country, $preferred),
                'media_count' => count($media),
                'formats'     => $formats,
                'packaging'   => trim((string)($rel['packaging'] ?? '')),
                'label'       => (string)($rel['label-info'][0]['label']['name'] ?? ''),
                'has_cover'   => !empty($rel['cover-art-archive']['front']),
                'tracks'      => $tracks,
                'title_keys'  => $titleKeys,
            ];

            $sig = implode('|', $titleKeys);
            if (!isset($byKey[$sig])) {
                $byKey[$sig] = [];
            }
            $byKey[$sig][] = $record;
        }

        if (empty($byKey)) {
            return [];
        }

        $groups = [];
        foreach ($byKey as $sig => $records) {
            // Release rappresentativa della variante (il suo MBID viene
            // salvato se l'utente sceglie questa variante): prima per
            // anno, a parità di anno quella del paese dell'artista, poi
            // quella con cover su Cover Art Archive, poi la data precisa.
            usort($records, function ($a, $b) {
                $ya = $a['year'] ?: 9999;
                $yb = $b['year'] ?: 9999;
                if ($ya !== $yb) return $ya - $yb;
                if ($a['country_rank'] !== $b['country_rank']) return $a['country_rank'] - $b['country_rank'];
                if ($a['has_cover'] !== $b['has_cover']) return $a['has_cover'] ? -1 : 1;
                $cmp = strcmp($a['date_sort'], $b['date_sort']);
                if ($cmp !== 0) return $cmp;
                return strcmp($a['id'], $b['id']);
            });
            $rep = $records[0];

            $firstSort = '9999-99-99';
            $firstDate = '';
            $bestRank  = 9;
            $ids       = [];
            foreach ($records as $r) {
                $ids[] = $r['id'];
                if ($r['date_sort'] < $firstSort) {
                    $firstSort = $r['date_sort'];
                    $firstDate = $r['date'];
                }
                if ($r['country_rank'] < $bestRank) {
                    $bestRank = $r['country_rank'];
                }
            }

            $groups[] = [
                'signature'     => $sig,
                'mbid'          => $rep['id'],
                'release_ids'   => $ids,
                'release_count' => count($records),
                'first_date'    => $firstDate,
                'first_sort'    => $firstSort,
                // Data mostrata: quella della release rappresentativa,
                // così data e paese descrivono la stessa stampa.
                'rep_date'      => $rep['date'] !== '' ? $rep['date'] : $firstDate,
                'year'          => $firstDate !== '' ? (int)substr($firstDate, 0, 4) : 0,
                'country'       => $rep['country'],
                'best_rank'     => $bestRank,
                'formats'       => $rep['formats'],
                'packaging'     => $rep['packaging'],
                'label'         => $rep['label'],
                'multi_disc'    => $rep['media_count'] > 1,
                'has_cover'     => $rep['has_cover'],
                'tracks'        => $rep['tracks'],
                'title_keys'    => $rep['title_keys'],
            ];
        }

        $groups = $this->mergeNearIdenticalGroups($groups);

        // Durate mancanti: la stessa registrazione compare in più
        // varianti, quindi una durata nota altrove vale anche qui.
        foreach ($groups as &$g) {
            foreach ($g['tracks'] as $ti => &$t) {
                if (empty($t['duration'])) {
                    $k = $g['title_keys'][$ti] ?? '';
                    if ($k !== '' && isset($durationOf[$k])) {
                        $t['duration'] = $durationOf[$k];
                    }
                }
            }
            unset($t);
        }
        unset($g);

        // Ordine di presentazione: per prima pubblicazione.
        usort($groups, function ($a, $b) {
            $cmp = strcmp($a['first_sort'], $b['first_sort']);
            if ($cmp !== 0) return $cmp;
            return $b['release_count'] - $a['release_count'];
        });

        // Variante proposta come originale. Se esiste una variante a
        // disco singolo, quelle multi-disco (bonus disc, anniversari)
        // non vengono proposte per prime, come in pickBestRelease().
        $singleExists = false;
        foreach ($groups as $g) {
            if (!$g['multi_disc']) {
                $singleExists = true;
                break;
            }
        }

        $defaultIdx = -1;
        foreach ($groups as $gi => $g) {
            if ($singleExists && $g['multi_disc']) {
                continue;
            }
            if ($defaultIdx === -1) {
                $defaultIdx = $gi;
                continue;
            }
            $d  = $groups[$defaultIdx];
            $ya = $g['year'] ?: 9999;
            $yb = $d['year'] ?: 9999;
            if ($ya !== $yb) {
                if ($ya < $yb) $defaultIdx = $gi;
                continue;
            }
            if ($g['best_rank'] !== $d['best_rank']) {
                if ($g['best_rank'] < $d['best_rank']) $defaultIdx = $gi;
                continue;
            }
            $cmp = strcmp($g['first_sort'], $d['first_sort']);
            if ($cmp !== 0) {
                if ($cmp < 0) $defaultIdx = $gi;
                continue;
            }
            if ($g['release_count'] > $d['release_count']) {
                $defaultIdx = $gi;
            }
        }
        if ($defaultIdx === -1) {
            $defaultIdx = 0;
        }

        // Frammenti esclusi: varianti con meno della metà delle tracce
        // dell'originale (Sgt. Pepper: release digitali da 1-2 brani
        // collegate all'album). L'originale resta sempre.
        $minTracks = (int)ceil(count($groups[$defaultIdx]['tracks']) * self::EDITIONS_FRAGMENT_RATIO);
        $kept      = [];
        $newDefault = 0;
        foreach ($groups as $gi => $g) {
            if ($gi !== $defaultIdx && count($g['tracks']) < $minTracks) {
                continue;
            }
            if ($gi === $defaultIdx) {
                $newDefault = count($kept);
            }
            $kept[] = $g;
        }
        $groups     = $kept;
        $defaultIdx = $newDefault;

        $firstYear = 0;
        foreach ($groups as $g) {
            if ($g['year'] > 0 && ($firstYear === 0 || $g['year'] < $firstYear)) {
                $firstYear = $g['year'];
            }
        }

        return [
            'groups'        => $groups,
            'default_index' => $defaultIdx,
            'first_year'    => $firstYear,
            'partial'       => false,
        ];
    }

    // Vero se almeno una variante a disco singolo, presente in un numero
    // minimo di stampe, NON contiene tutte le tracce della variante
    // originale. Le varianti che aggiungono soltanto (deluxe, bonus
    // track) non contano: chi le possiede ha comunque l'album originale.
    private function hasConflictingEdition(array $groups, int $defaultIdx): bool
    {
        if (!isset($groups[$defaultIdx])) {
            return false;
        }
        $defaultKeys = $groups[$defaultIdx]['title_keys'];

        foreach ($groups as $gi => $g) {
            if ($gi === $defaultIdx || $g['multi_disc']) {
                continue;
            }
            if ($g['release_count'] < self::EDITIONS_EXCEPTION_MIN_RELEASES) {
                continue;
            }
            list($missing) = $this->diffTitleKeys($defaultKeys, $g['title_keys']);
            if (!empty($missing)) {
                return true;
            }
        }

        return false;
    }

    // Due titoli normalizzati indicano lo stesso brano se coincidono, se
    // uno contiene l'altro (versioni annotate, articolo mancante) o se
    // differiscono per pochi caratteri (refusi di trascrizione).
    private function titleKeysMatch(string $a, string $b): bool
    {
        if ($a === $b) return true;
        if ($a === '' || $b === '') return false;

        $la = strlen($a);
        $lb = strlen($b);
        $short = $la <= $lb ? $a : $b;
        $long  = $la <= $lb ? $b : $a;

        if (strlen($short) >= self::TITLE_MATCH_MIN_CONTAINED && strpos($long, $short) !== false) {
            return true;
        }

        // levenshtein() lavora su byte e fino a 255 caratteri
        if ($la <= 255 && $lb <= 255) {
            // Sotto i 7 caratteri la soglia è 0: "hole" e "home" restano
            // brani diversi.
            $max = (int)floor(max($la, $lb) * self::TITLE_MATCH_MAX_DISTANCE);
            return $max > 0 && levenshtein($a, $b) <= $max;
        }

        return false;
    }

    // Confronto uno a uno tra due tracklist: ogni brano di una può
    // corrispondere a un solo brano dell'altra. Prima gli abbinamenti
    // esatti, poi quelli tolleranti sui brani rimasti. Così "Spit It Out
    // (Hyper version)" risulta aggiunta anche se "Spit It Out" è già
    // presente, e "Scissors" si abbina a "Scissors / Eeyore".
    // Restituisce [indici non abbinati di $baseKeys, di $otherKeys].
    private function diffTitleKeys(array $baseKeys, array $otherKeys): array
    {
        $baseFree  = [];
        $otherFree = [];
        foreach ($baseKeys as $i => $k) {
            if ($k !== '') $baseFree[$i] = $k;
        }
        foreach ($otherKeys as $j => $k) {
            if ($k !== '') $otherFree[$j] = $k;
        }

        foreach ($baseFree as $i => $k) {
            foreach ($otherFree as $j => $o) {
                if ($k === $o) {
                    unset($baseFree[$i], $otherFree[$j]);
                    break;
                }
            }
        }
        foreach ($baseFree as $i => $k) {
            foreach ($otherFree as $j => $o) {
                if ($this->titleKeysMatch($k, $o)) {
                    unset($baseFree[$i], $otherFree[$j]);
                    break;
                }
            }
        }

        return [array_keys($baseFree), array_keys($otherFree)];
    }

    // Unisce le varianti che hanno lo stesso numero di tracce e titoli
    // corrispondenti posizione per posizione: sono la stessa tracklist
    // scritta in modo diverso (Sgt. Pepper US 1967 con "A Little Help
    // From My Friends"). Resta la variante con più stampe; data di prima
    // pubblicazione, paese migliore e MBID delle stampe vengono riuniti.
    private function mergeNearIdenticalGroups(array $groups): array
    {
        $n = count($groups);
        $absorbed = [];

        for ($i = 0; $i < $n; $i++) {
            if (isset($absorbed[$i])) continue;

            for ($j = $i + 1; $j < $n; $j++) {
                if (isset($absorbed[$j])) continue;

                $a = $groups[$i];
                $b = $groups[$j];
                if (count($a['title_keys']) !== count($b['title_keys'])) continue;

                $same = true;
                foreach ($a['title_keys'] as $ti => $k) {
                    if (!$this->titleKeysMatch($k, $b['title_keys'][$ti])) {
                        $same = false;
                        break;
                    }
                }
                if (!$same) continue;

                // Base: più stampe, a parità quella pubblicata prima
                $baseIsA = $a['release_count'] > $b['release_count']
                    || ($a['release_count'] === $b['release_count'] && strcmp($a['first_sort'], $b['first_sort']) <= 0);
                $base  = $baseIsA ? $a : $b;
                $other = $baseIsA ? $b : $a;

                $base['release_ids']   = array_values(array_unique(array_merge($base['release_ids'], $other['release_ids'])));
                $base['release_count'] = count($base['release_ids']);
                if (strcmp($other['first_sort'], $base['first_sort']) < 0) {
                    $base['first_sort'] = $other['first_sort'];
                    $base['first_date'] = $other['first_date'];
                    $base['year']       = $other['year'];
                }
                $base['best_rank'] = min($base['best_rank'], $other['best_rank']);

                $groups[$i]    = $base;
                $absorbed[$j]  = true;
            }
        }

        $out = [];
        foreach ($groups as $gi => $g) {
            if (!isset($absorbed[$gi])) {
                $out[] = $g;
            }
        }
        return $out;
    }

    // Descrizione delle varianti per il frontend: niente tracce complete
    // (arrivano con fetchEdition), solo i dati per riconoscerle e le
    // differenze rispetto alla variante originale.
    private function publicEditions(array $groups, int $defaultIdx, int $selectedIdx, string $selectedMbid): array
    {
        $default = $groups[$defaultIdx] ?? $groups[0];

        $out = [];
        foreach ($groups as $gi => $g) {
            $added   = [];
            $removed = [];

            if ($gi !== $defaultIdx) {
                list($missing, $extra) = $this->diffTitleKeys($default['title_keys'], $g['title_keys']);
                foreach ($missing as $ti) {
                    $removed[] = $default['tracks'][$ti]['title'];
                }
                foreach ($extra as $ti) {
                    $added[] = $g['tracks'][$ti]['title'];
                }
            }

            $out[] = [
                'key'           => substr(md5($g['signature']), 0, 12),
                'mbid'          => $gi === $selectedIdx ? $selectedMbid : $g['mbid'],
                'date'          => $g['rep_date'],
                'year'          => $g['year'],
                'country'       => $g['country'],
                'formats'       => implode(' + ', $g['formats']),
                'packaging'     => $g['packaging'],
                'label'         => $g['label'],
                'track_count'   => count($g['tracks']),
                'release_count' => $g['release_count'],
                'multi_disc'    => $g['multi_disc'],
                'has_cover'     => $g['has_cover'],
                'is_default'    => $gi === $defaultIdx,
                'selected'      => $gi === $selectedIdx,
                'added'         => array_slice($added, 0, self::EDITIONS_DIFF_MAX),
                'added_more'    => max(0, count($added) - self::EDITIONS_DIFF_MAX),
                'removed'       => array_slice($removed, 0, self::EDITIONS_DIFF_MAX),
                'removed_more'  => max(0, count($removed) - self::EDITIONS_DIFF_MAX),
            ];
        }

        return $out;
    }

    // Titolo ridotto a lettere e cifre minuscole: "Wait and Bleed",
    // "Wait And Bleed" e "Wait & Bleed" non devono generare varianti.
    // Anche le annotazioni di rimasterizzazione ("Eyeless (2009
    // Remaster)", "Eyeless - Remastered") vengono ignorate: indicano lo
    // stesso brano, non una tracklist diversa.
    private function titleKey(string $title): string
    {
        $title = preg_replace('/\s*[\(\[][^\)\]]*remaster[^\)\]]*[\)\]]/i', '', $title) ?? $title;
        $title = preg_replace('/\s+-\s+[^-]*remaster.*$/i', '', $title) ?? $title;
        $t = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
        $t = str_replace('&', 'and', $t);
        $k = preg_replace('/[^\p{L}\p{N}]+/u', '', $t);
        return ($k === null || $k === '') ? trim($t) : $k;
    }

    // Data confrontabile come stringa. Le date parziali ("1999",
    // "1999-11") finiscono DOPO le date complete dello stesso periodo:
    // una ristampa datata solo "1999" non deve risultare anteriore
    // alla prima stampa del 29 giugno 1999.
    private function sortableDate(string $date): string
    {
        if (preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?/', $date, $m)) {
            $mm = isset($m[2]) && $m[2] !== '' ? $m[2] : '99';
            $dd = isset($m[3]) && $m[3] !== '' ? $m[3] : '99';
            return $m[1] . '-' . $mm . '-' . $dd;
        }
        return '9999-99-99';
    }

    private function countryRank(string $country, string $preferred): int
    {
        if ($country === '') return 3;
        if ($preferred !== '' && $country === $preferred) return 0;
        if (in_array($country, ['XE', 'XW'], true)) return 1;
        return 2;
    }

    private function isUuid(string $value): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($value));
    }

    // Genere con più voti; a parità di voti resta l'ordine originale.
    private function topGenreName(array $genres): string
    {
        $best      = '';
        $bestCount = -1;
        foreach ($genres as $g) {
            $name = trim((string)($g['name'] ?? ''));
            if ($name === '') continue;
            $count = (int)($g['count'] ?? 0);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best      = $name;
            }
        }
        return $best;
    }

    private function editionsCacheFile(string $rgId): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        return $base . '/cache/album-editions/' . $rgId . '.json';
    }

    private function readEditionsCache(string $rgId): ?array
    {
        $file = $this->editionsCacheFile($rgId);
        if (!is_file($file)) {
            return null;
        }
        if ((time() - (int)@filemtime($file)) > self::EDITIONS_CACHE_TTL) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)
            || (int)($data['version'] ?? 0) !== self::EDITIONS_LOGIC_VERSION
            || empty($data['editions']['groups'])
        ) {
            return null;
        }

        return $data['editions'];
    }

    private function writeEditionsCache(string $rgId, array $editions): void
    {
        $file = $this->editionsCacheFile($rgId);
        $dir  = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $payload = json_encode([
            'version'  => self::EDITIONS_LOGIC_VERSION,
            'rg'       => $rgId,
            'editions' => $editions,
        ], JSON_UNESCAPED_UNICODE);

        if ($payload !== false) {
            @file_put_contents($file, $payload, LOCK_EX);
        }
    }

    // ================= DISCOGS =================

    private function searchDiscogs(string $artist, string $album, int $year = 0): array
    {
        if (ExternalApiConfig::getDiscogsToken() === '') return [];

        $url = 'https://api.discogs.com/database/search?type=release'
            . '&artist='        . urlencode($artist)
            . '&release_title=' . urlencode($album)
            . ($year > 0 ? '&year=' . $year : '')
            . '&per_page=15';

        $headers = ['Authorization: Discogs token=' . ExternalApiConfig::getDiscogsToken()];

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

            // Stessa regola applicata a MusicBrainz: nessuna edizione
            // giapponese deve entrare come fallback da Discogs.
            $discogsCountry = strtolower(trim($row['country'] ?? ''));
            if (in_array($discogsCountry, ['japan', 'jp'], true)) {
                continue;
            }

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

            // Discogs usa nomi estesi per il paese. Applichiamo una
            // preferenza leggera coerente con il paese MusicBrainz.
            if ($this->discogsCountryMatchesPreferred($row['country'] ?? '')) {
                $score += 35;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $row;
            }
        }

        return $best;
    }

    private function discogsCountryMatchesPreferred(string $country): bool
    {
        if ($this->preferredArtistCountry === '') {
            return false;
        }

        $map = [
            'GB' => ['uk', 'united kingdom', 'great britain', 'england'],
            'US' => ['us', 'usa', 'united states'],
            'CA' => ['canada'],
            'AU' => ['australia'],
            'DE' => ['germany'],
            'FR' => ['france'],
            'IT' => ['italy'],
            'ES' => ['spain'],
        ];

        $country = strtolower(trim($country));
        return in_array($country, $map[$this->preferredArtistCountry] ?? [], true);
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
        if (ExternalApiConfig::getLastFmKey() === '') return [];

        $url = 'https://ws.audioscrobbler.com/2.0/?method=album.getinfo'
            . '&api_key=' . ExternalApiConfig::getLastFmKey()
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
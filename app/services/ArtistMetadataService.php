<?php

/**
 * ArtistMetadataService
 * ------------------------------------------------------------
 * Recupera bio + immagine artista da fonti esterne, privilegiando
 * l'ITALIANO. Pipeline:
 *
 *   1) MusicBrainz  -> match affidabile (score>=85) + relation Wikidata
 *                      (con disambiguazione omonimi: tra i candidati vince
 *                      chi ha a catalogo almeno un album dell'archivio locale)
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

    /**
     * Versioni della logica di fetch. Da incrementare OGNI VOLTA che si
     * modifica in modo sostanziale l'algoritmo di recupero — non la
     * frequenza di chiamata, ma cosa/come viene cercato o filtrato.
     * ArtistController confronta questi valori con quelli salvati sul
     * singolo artista (Artist::needsBioRefetch/needsDiscographyRefetch):
     * se la versione salvata è più vecchia, il fetch riparte da solo alla
     * prossima visita, senza bisogno di toccare il DB a mano.
     *
     * BIO e DISCOGRAFIA hanno versioni indipendenti perché evolvono per
     * conto proprio (oggi cambia la discografia, la bio no: non ha senso
     * ri-martellare bio/immagini di tutti gli artisti per un fix che le
     * riguarda solo di striscio).
     */
    public const BIO_LOGIC_VERSION = 1;

    /**
     * v2: fix del 2026-07 — la discografia veniva letta da /release
     * (una entry per ogni edizione fisica ufficiale) invece che da
     * /release-group (una entry per album), il che troncava la
     * discografia di artisti con molte ristampe prima di arrivare agli
     * album più recenti (bug riscontrato su Pearl Jam, fermo al 2009).
     *
     * v3: fix del 2026-07 — il passaggio a /release-group in v2 aveva
     * eliminato per distrazione il controllo status=official che il
     * vecchio codice su /release faceva gratuitamente. Risultato: un
     * release-group con primary-type Album e nessun secondary-type ma
     * SENZA alcuna release ufficiale dietro (bootleg, ristampe grigie
     * di registrazioni radio/broadcast) passava comunque il filtro.
     * Casi confermati: "Tilburg 1993" (Alice in Chains, release con
     * status=Bootleg), "Radio Transmissions 1995-2000" (Pearl Jam,
     * raccolta di broadcast ripubblicata da un'etichetta di bootleg).
     *
     * v4: fix del 2026-07 — l'implementazione di v3 aggiungeva
     * '&inc=releases' alla STESSA query browse-per-artista paginata:
     * questa combinazione specifica è tornata sempre con lista di
     * release annidate vuota per OGNI release-group (non solo i
     * bootleg), quindi la verifica "ha una release ufficiale" falliva
     * sempre e la discografia risultava vuota per tutti — bug peggiore
     * di quello che doveva risolvere, perché scriveva quel vuoto in
     * cache con stato 'ok'. v4 verifica ogni album candidato con una
     * lookup dedicata (/release-group/{id}?inc=releases&status=official),
     * più lenta ma su un comportamento documentato in modo inequivocabile.
     *
     * v5: fix del 2026-07 — v4 era corretta ma troppo lenta: una
     * chiamata HTTP throttled per OGNI candidato (15-30 album = 15-30
     * secondi abbondanti, superando anche il minuto). v5 sostituisce le
     * N lookup singole con una scansione unica a blocchi da 100 release
     * (stessa query del vecchio codice pre-v2, usata pero' solo per
     * CONFERMARE i candidati già noti, non più come fonte primaria di
     * titoli/anni): tipicamente 1-3 chiamate invece di N, con lo stesso
     * identico risultato — vedi confirmOfficialReleaseGroups().
     */
    public const DISCOGRAPHY_LOGIC_VERSION = 5;

    /** Lunghezza massima bio salvata (caratteri) per non esagerare */
    private const BIO_MAX_CHARS = 2200;

    /**
     * @param string $name              Nome artista da cercare
     * @param array  $localAlbumTitles  Titoli degli album di questo artista
     *                                  presenti nell'archivio locale. Usati per
     *                                  disambiguare artisti omonimi su MusicBrainz
     *                                  (es. "Beck" musicista vs Rufus Beck attore).
     *                                  Con array vuoto il comportamento è identico
     *                                  a prima (nessuna verifica).
     */
    public function fetchByName(string $name, array $localAlbumTitles = []): array
    {
        $result = $this->emptyResult();
        $name   = trim($name);
        if ($name === '') {
            return $result;
        }

        // ---------- 1) MUSICBRAINZ : match + metadati ----------
        $mb = $this->searchMusicBrainzArtist($name, $localAlbumTitles);

        // Estrae il flag di esito (vedi searchMusicBrainzArtist): indica
        // se la RICERCA MusicBrainz è andata a buon fine, indipendentemente
        // dal fatto che abbia trovato un match. Usato da ArtistController
        // per decidere se la cache va marcata 'ok' o 'error' (da ritentare).
        $fetchOk = true;
        if (array_key_exists('_fetch_ok', $mb)) {
            $fetchOk = (bool) $mb['_fetch_ok'];
            unset($mb['_fetch_ok']);
        }

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

        // ---------- IMMAGINE, fonte prioritaria: DEEZER ----------
        // Foto quadrate e uniformi, API pubblica senza chiave.
        // Il match sul nome è di uguaglianza esatta normalizzata
        // per non pescare omonimi (lezione "Packaging"); in caso
        // di mancato match si scende sulla catena Wikipedia/Wikidata.
        $dz = $this->deezerArtistImage($name);
        if ($dz !== '') {
            $result['image_url']    = $dz;
            $result['image_source'] = 'deezer';
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
            if ($result['image_url'] === '' && !empty($wiki['image'])) {
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

        $result['fetch_ok'] = $fetchOk;

        return $result;
    }

    // ============================================================
    // MUSICBRAINZ
    // ============================================================

    private function searchMusicBrainzArtist(string $name, array $localAlbumTitles = []): array
    {
        $url = 'https://musicbrainz.org/ws/2/artist/'
            . '?query=' . urlencode('artist:"' . $name . '"')
            . '&fmt=json&limit=5';

        $resp = $this->httpGetJsonWithStatus($url);
        usleep(self::MB_THROTTLE_US);

        // Segnala se la RICERCA (non il lookup successivo) è andata a
        // buon fine: è la chiamata portante, da cui dipende la decisione
        // di fetchByName() se marcare la cache come confermata o da
        // ritentare. Propagata via chiave interna, rimossa da fetchByName().
        $fetchOk = $resp['ok'];
        $data    = $resp['data'];

        if (empty($data['artists'])) {
            return ['_fetch_ok' => $fetchOk];
        }

        // Raccoglie TUTTI i candidati sopra soglia (non solo il primo):
        // per nomi ambigui ("Beck", "Bush", "Genesis"...) MusicBrainz può
        // restituire più artisti omonimi con score alto e l'ordinamento
        // del motore di ricerca non garantisce che il primo sia quello giusto.
        $candidates = [];
        foreach ($data['artists'] as $a) {
            $score = (int) ($a['score'] ?? 0);
            if ($score >= self::MB_MIN_SCORE) {
                $candidates[] = $a;
            }
        }
        if (empty($candidates)) {
            return ['_fetch_ok' => $fetchOk];
        }

        // Disambiguazione: se c'è più di un candidato e conosciamo gli album
        // locali dell'artista, vince il primo candidato che ha a catalogo
        // (release-group MusicBrainz) almeno uno di quegli album.
        $best = null;
        if (count($candidates) > 1 && !empty($localAlbumTitles)) {
            $normLocal = [];
            foreach ($localAlbumTitles as $t) {
                $n = $this->normalizeTitleForMatch((string) $t);
                if ($n !== '') {
                    $normLocal[] = $n;
                }
            }
            if (!empty($normLocal)) {
                foreach ($candidates as $a) {
                    if (empty($a['id'])) {
                        continue;
                    }
                    if ($this->artistOwnsLocalAlbum($a['id'], $normLocal)) {
                        $best = $a;
                        break;
                    }
                }
            }
        }

        // Fallback: comportamento storico (primo candidato sopra soglia).
        // Copre candidato unico, archivio senza album verificabili su MB,
        // o nessun match nella verifica.
        if ($best === null) {
            $best = $candidates[0];
        }

        if (!empty($best['id'])) {
            $lookupUrl = 'https://musicbrainz.org/ws/2/artist/' . $best['id']
                . '?inc=url-rels&fmt=json';
            $full = $this->httpGetJson($lookupUrl);
            usleep(self::MB_THROTTLE_US);
            if (!empty($full['id'])) {
                $full['score']     = $best['score'] ?? 0;
                $full['_fetch_ok'] = true; // la ricerca primaria è comunque riuscita
                return $full;
            }
        }

        $best['_fetch_ok'] = true;
        return $best;
    }

    /**
     * Verifica se l'artista MusicBrainz possiede almeno uno degli album
     * locali (confronto su titoli normalizzati dei release-group).
     * Costa una richiesta MB (throttled) per candidato verificato.
     *
     * @param string   $mbid       MBID artista candidato
     * @param string[] $normLocal  Titoli locali già normalizzati
     */
    private function artistOwnsLocalAlbum(string $mbid, array $normLocal): bool
    {
        $url = 'https://musicbrainz.org/ws/2/release-group'
            . '?artist=' . urlencode($mbid)
            . '&type=album&limit=100&fmt=json';

        $data = $this->httpGetJson($url);
        usleep(self::MB_THROTTLE_US);

        foreach ($data['release-groups'] ?? [] as $rg) {
            $t = $this->normalizeTitleForMatch((string) ($rg['title'] ?? ''));
            if ($t !== '' && in_array($t, $normLocal, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalizza un titolo album per il confronto: minuscolo, senza
     * annotazioni tra parentesi (deluxe, remaster...), senza punteggiatura,
     * spazi compattati. Stessa filosofia del matching Wikipedia degli album.
     */
    private function normalizeTitleForMatch(string $t): string
    {
        $t = mb_strtolower(trim($t));
        $t = preg_replace('/\s*[\(\[][^\)\]]*[\)\]]\s*/u', ' ', $t);
        $t = preg_replace('/[^\p{L}\p{N}\s]/u', '', $t);
        $t = preg_replace('/\s+/u', ' ', $t);
        return trim($t);
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

        // Titolo pagina via Wikidata (matching certo), altrimenti nome.
        // $fromSitelink distingue i due casi: le pagine raggiunte via
        // sitelink Wikidata sono garantite essere DELL'ARTISTA; quelle
        // trovate per semplice nome vanno validate (vedi sotto).
        $title        = '';
        $fromSitelink = false;
        if ($wikidataId !== '') {
            $title        = $this->wikidataSitelinkTitle($wikidataId, $lang);
            $fromSitelink = ($title !== '');
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

        // GUARDIA ANTI-OMONIMI da sostantivo comune (es. artista
        // "Packaging" -> articolo sugli imballaggi, "Television" ->
        // articolo sulla televisione). Si applica SOLO alle pagine
        // trovate per semplice nome: l'estratto deve descrivere un
        // soggetto musicale, altrimenti viene scartato — meglio
        // nessuna bio che una sbagliata. Le pagine raggiunte via
        // sitelink Wikidata sono match certi e passano senza esame.
        if (!$fromSitelink && !$this->looksLikeMusicBio($extract)) {
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
     * Euristica: l'estratto Wikipedia descrive un soggetto musicale?
     * Wikipedia dichiara "chi/cosa è" il soggetto nelle prime frasi
     * dell'intro, quindi il controllo avviene su una finestra iniziale.
     * Usata SOLO per le pagine trovate per nome (fallback), mai per
     * quelle raggiunte via sitelink Wikidata (match certo).
     * Nota: ' band' ha lo spazio davanti per non matchare "husband",
     * "contraband" ecc.; 'cantautor'/'compositor' coprono le varianti
     * maschili/femminili.
     */
    private function looksLikeMusicBio(string $extract): bool
    {
        $window = mb_strtolower(mb_substr($extract, 0, 800));

        $keywords = [
            // italiano
            'gruppo musicale', 'duo musicale', 'trio musicale',
            'progetto musicale', 'cantante', 'cantautor', 'musicista',
            ' band', 'rapper', 'compositor', 'chitarrista', 'batterista',
            'bassista', 'tastierista', 'polistrumentista', 'violinista',
            'pianista', 'disc jockey', 'produttore discografico',
            'etichetta discografica', 'discografia', 'direttore d\'orchestra',
            // inglese
            'musical group', 'music group', 'singer', 'musician',
            'songwriter', 'record producer', 'music project', 'composer',
            'music duo', 'discography', 'guitarist', 'drummer',
        ];

        foreach ($keywords as $kw) {
            if (mb_strpos($window, $kw) !== false) {
                return true;
            }
        }

        return false;
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
    // ============================================================
    // DEEZER (fallback immagine artista, nessuna API key richiesta)
    // ============================================================

    /**
     * Cerca l'artista su Deezer e restituisce l'URL della foto in
     * alta risoluzione, oppure '' se non trovato.
     *
     * Protezioni:
     * - match di UGUAGLIANZA ESATTA sul nome normalizzato (minuscole,
     *   spazi compattati): la ricerca Deezer è fuzzy e senza questo
     *   controllo un artista di nicchia pescherebbe l'omonimo famoso;
     * - scarto dell'immagine placeholder di default di Deezer,
     *   riconoscibile dall'md5 vuoto nel percorso ('/artist//').
     */
    private function deezerArtistImage(string $name): string
    {
        $url  = 'https://api.deezer.com/search/artist?q=' . rawurlencode($name);
        $data = $this->httpGetJson($url);

        if (empty($data['data']) || !is_array($data['data'])) {
            return '';
        }

        $wanted = $this->normalizeArtistName($name);

        // Esamina solo i primi risultati: se il match esatto non è
        // in cima, quasi certamente l'artista non è quello giusto.
        $candidates = array_slice($data['data'], 0, 5);

        foreach ($candidates as $a) {
            if (empty($a['name'])) {
                continue;
            }
            if ($this->normalizeArtistName($a['name']) !== $wanted) {
                continue;
            }

            $img = $a['picture_xl'] ?? ($a['picture_big'] ?? '');
            if ($img === '') {
                continue;
            }
            // Placeholder Deezer, caso 1: percorso con hash vuoto (/artist//...)
            if (strpos($img, '/artist//') !== false) {
                continue;
            }
            // Placeholder Deezer, caso 2: hash = md5('') = d41d8cd98f00b204e9800998ecf8427e.
            // Deezer lo usa come "nessuna foto" per artisti omonimi minori che a
            // volte precedono nei risultati l'artista vero (es. un "David Bowie"
            // con 441 fan senza foto, prima del vero David Bowie con 2,4M fan).
            // Senza questo controllo il codice accetta il placeholder come se
            // fosse una foto reale e lo scarica in locale.
            if (strpos($img, '/d41d8cd98f00b204e9800998ecf8427e/') !== false) {
                continue;
            }

            return $img;
        }

        return '';
    }

    /**
     * Normalizzazione nome artista per confronto: minuscole, trim,
     * spazi multipli compattati.
     */
    private function normalizeArtistName(string $n): string
    {
        $n = mb_strtolower(trim($n));
        return preg_replace('/\s+/', ' ', $n);
    }

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

        // Normalizza alla fonte (max 1200px, q85) — best-effort
        ImageOptimizer::optimize($dest);

        return 'artists/' . $name;
    }

    // ============================================================
    // DISCOGRAFIA UFFICIALE (MusicBrainz release-groups)
    // ============================================================

    /**
     * Recupera l'elenco degli ALBUM IN STUDIO UFFICIALI dell'artista.
     *
     * Strategia: interroga direttamente i RELEASE-GROUP dell'artista
     * (endpoint /release-group, non /release), filtrando per primary-type
     * "Album" senza secondary-type (niente live, compilation, remix...).
     *
     * NOTA IMPORTANTE (fix): la versione precedente interrogava /release
     * (una entry per OGNI edizione/pressaggio fisico ufficiale — ogni
     * paese, ogni formato, ogni ristampa) e poi raggruppava per
     * release-group lato PHP. Per artisti con un catalogo di ristampe
     * ampio (es. Pearl Jam ha centinaia di release ufficiali distinte
     * contando tutte le edizioni nazionali dei primi album) questo
     * esauriva il guard di paginazione (max 6 pagine × 100 = 600 release)
     * prima ancora di raggiungere le release degli album più recenti,
     * perché MusicBrainz non restituisce le release in ordine
     * cronologico di uscita. Risultato: la discografia si fermava a
     * metà, dando l'illusione (falsa) che MusicBrainz non conoscesse
     * gli album successivi. Interrogando /release-group si ottiene
     * invece UNA riga per album (indipendentemente da quante edizioni
     * fisiche esistano), quindi per la stragrande maggioranza degli
     * artisti basta una sola pagina.
     *
     * @return array[] ['title' => string, 'year' => ?int,
     *                  'mb_release_group_id' => string] ordinato per anno.
     */
    public function fetchDiscography(string $mbArtistId): array
    {
        $mbArtistId = trim($mbArtistId);
        if ($mbArtistId === '') {
            return ['ok' => true, 'items' => []];
        }

        // STEP 1 — candidati (titolo/anno) da /release-group: una riga per
        // album indipendentemente da quante edizioni fisiche esistano
        // (fix v2, niente troncamento su cataloghi con molte ristampe).
        $candidates = []; // titolo normalizzato => ['rgId','title','year']
        $fetchOk    = true;

        $limit  = 100;
        $offset = 0;
        $guard  = 0;

        do {
            $url = 'https://musicbrainz.org/ws/2/release-group'
                . '?artist=' . rawurlencode($mbArtistId)
                . '&type=album'
                . '&fmt=json&limit=' . $limit . '&offset=' . $offset;

            $resp = $this->httpGetJsonWithStatus($url);
            usleep(self::MB_THROTTLE_US);

            if (!$resp['ok']) {
                $fetchOk = false;
                break;
            }

            $data   = $resp['data'];
            $groups = $data['release-groups'] ?? [];
            $total  = (int) ($data['release-group-count'] ?? count($groups));

            foreach ($groups as $rg) {
                $primary   = $rg['primary-type'] ?? '';
                $secondary = $rg['secondary-types'] ?? [];
                if (strcasecmp($primary, 'Album') !== 0 || !empty($secondary)) {
                    continue;
                }

                $rgId  = $rg['id'] ?? '';
                $title = trim($rg['title'] ?? '');
                if ($rgId === '' || $title === '') {
                    continue;
                }

                $year = null;
                $fr   = $rg['first-release-date'] ?? '';
                if ($fr !== '' && preg_match('/^(\d{4})/', $fr, $m)) {
                    $year = (int) $m[1];
                }

                // Dedup per titolo QUI, prima della verifica ufficiale. A
                // parità di titolo, tiene il candidato con l'anno
                // valorizzato (di solito quello con dati più completi,
                // es. "Blindness" vs "blindness").
                $key = preg_replace('/\s+/', ' ', mb_strtolower($title));
                if (!isset($candidates[$key]) || ($candidates[$key]['year'] === null && $year !== null)) {
                    $candidates[$key] = ['rgId' => $rgId, 'title' => $title, 'year' => $year];
                }
            }

            $offset += $limit;
            $guard++;
        } while ($offset < $total && $guard < 6);

        // STEP 2 — quali candidati hanno ALMENO UNA release ufficiale
        // dietro? Una scansione unica a blocchi da 100 (non una chiamata
        // per candidato: con 15-30 album significava 15-30 chiamate
        // sequenziali da oltre un secondo l'una, quindi anche un minuto
        // pieno) — vedi confirmOfficialReleaseGroups().
        $out = [];
        if ($fetchOk && !empty($candidates)) {
            $confirmedIds = $this->confirmOfficialReleaseGroups(
                $mbArtistId,
                array_column($candidates, 'rgId')
            );
            foreach ($candidates as $cand) {
                if (in_array($cand['rgId'], $confirmedIds, true)) {
                    $out[] = [
                        'title'               => $cand['title'],
                        'year'                => $cand['year'],
                        'mb_release_group_id' => $cand['rgId'],
                    ];
                }
            }
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

        return ['ok' => $fetchOk, 'items' => $out];
    }

    /**
     * Conferma quali dei $candidateRgIds hanno almeno una release con
     * status=Official, scansionando le release ufficiali di tipo album
     * dell'artista A BLOCCHI DA 100 (query identica a quella del vecchio
     * codice pre-v2: /release?artist=...&type=album&status=official&
     * inc=release-groups) — UNA chiamata ogni 100 release, non una
     * chiamata per candidato: per un artista con 15-30 album questo
     * vuol dire tipicamente 1-3 chiamate invece di 15-30.
     *
     * Si ferma appena TUTTI i candidati sono confermati (early exit) o
     * quando ha scandito tutte le release ufficiali dell'artista
     * (offset >= total): a quel punto chi resta non trovato NON ha
     * nessuna release ufficiale, quindi va escluso con certezza — è
     * così che "Tilburg 1993"/"Radio Transmissions 1995-2000" vengono
     * esclusi in modo definitivo, non per un timeout.
     *
     * Solo se il catalogo è così enorme da far scattare il tetto di
     * sicurezza ($guardMax pagine) PRIMA di finire la scansione, o se la
     * rete fallisce a metà, i candidati ancora incerti a quel punto
     * vengono inclusi per prudenza (fail-open sull'ambiguità residua,
     * mai sui bootleg già confermati assenti).
     *
     * @param string[] $candidateRgIds
     * @return string[] i release-group id confermati ufficiali
     */
    private function confirmOfficialReleaseGroups(string $mbArtistId, array $candidateRgIds): array
    {
        $remaining = array_flip($candidateRgIds); // rgId => indice originale
        $confirmed = [];

        $limit    = 100;
        $offset   = 0;
        $total    = 0;
        $guard    = 0;
        $guardMax = 30; // fino a 3000 release scandite prima del fail-open residuo
        $sawFailure = false;

        do {
            $url = 'https://musicbrainz.org/ws/2/release'
                . '?artist=' . rawurlencode($mbArtistId)
                . '&type=album&status=official'
                . '&inc=release-groups'
                . '&fmt=json&limit=' . $limit . '&offset=' . $offset;

            $resp = $this->httpGetJsonWithStatus($url);
            usleep(self::MB_THROTTLE_US);

            if (!$resp['ok']) {
                $sawFailure = true;
                break;
            }

            $data     = $resp['data'];
            $releases = $data['releases'] ?? [];
            $total    = (int) ($data['release-count'] ?? count($releases));

            foreach ($releases as $rel) {
                $rgId = $rel['release-group']['id'] ?? '';
                if ($rgId !== '' && isset($remaining[$rgId])) {
                    $confirmed[] = $rgId;
                    unset($remaining[$rgId]);
                }
            }

            $offset += $limit;
            $guard++;
        } while (!empty($remaining) && $offset < $total && $guard < $guardMax);

        // Scansione completa (tutte le release ufficiali dell'artista
        // viste, nessun errore di rete nel mezzo): chi resta in
        // $remaining è escluso con certezza, non ha nessuna release
        // ufficiale. Altrimenti (tetto di sicurezza o rete fallita a
        // metà) l'esito è incerto, non un bootleg confermato: includiamo
        // per prudenza.
        $exhausted = !$sawFailure && ($offset >= $total);
        if (!$exhausted) {
            $confirmed = array_merge($confirmed, array_keys($remaining));
        }

        return $confirmed;
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
            'fetch_ok'     => true,
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
     * Come httpGetJson() ma segnala anche se la chiamata è andata
     * DAVVERO a buon fine (bytes ricevuti + JSON valido), a differenza
     * di httpGetJson() che restituisce [] sia per "nessun risultato" sia
     * per "richiesta fallita" — ambiguità accettabile per le chiamate di
     * fallback (Wikipedia/Wikidata/Deezer/Last.fm, dove un fallimento è
     * un esito normale e già previsto della catena), ma NON per i due
     * punti che decidono se la cache va marcata come confermata:
     * la ricerca artista su MusicBrainz e la discografia.
     *
     * @return array{ok:bool,data:array}
     */
    private function httpGetJsonWithStatus(string $url): array
    {
        $raw = $this->httpGetBinary($url, 'application/json');
        if ($raw === '') {
            return ['ok' => false, 'data' => []];
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['ok' => false, 'data' => []];
        }
        return ['ok' => true, 'data' => $json];
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
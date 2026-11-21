<?php

require_once __DIR__ . '/ExternalApiConfig.php';

/**
 * AlbumRecommendationService
 * ------------------------------------------------------------
 * Fonti dei consigli della scheda disco e della dashboard, tutte senza
 * credenziali obbligatorie:
 *
 *   1) identità MusicBrainz dell'artista (MBID). Se manca in archivio si
 *      ricava dall'artista accreditato sul release-group del disco oppure
 *      con la ricerca e la prova d'identità di ArtistMetadataService;
 *   2) artisti simili da ListenBrainz Labs (similar-artists), corretti per
 *      popolarità: indice = score / sqrt(ascoltatori), soglia MIN_INDEX.
 *      Senza correzione la lista premia gli artisti più ascoltati in
 *      assoluto (Radiohead, Beatles, Pink Floyd compaiono quasi ovunque);
 *   3) generi MusicBrainz del release-group del disco (solo il campo
 *      "genres", non i tag liberi);
 *   4) facoltativo, con token ListenBrainz: album più ascoltati degli
 *      artisti simili che non sono in archivio (sezione di scoperta).
 *
 * Gli errori delle fonti (rete, 429, 5xx, 401) non vengono mai salvati in
 * cache: il chiamante riceve ok=false e non conserva il risultato
 * complessivo, così la richiesta successiva riprova.
 *
 * Tempo: la scheda interrompe la richiesta dopo 15 secondi. Il servizio
 * lavora entro un budget (startBudget) e, finito il tempo, salta le
 * chiamate rimaste segnando il risultato come incompleto.
 *
 * Cache file: cache/recommendations/{sha1}.json
 *
 * Compatibile PHP 7.4.
 */
class AlbumRecommendationService
{
    private const CACHE_TTL_OK    = 604800;  // 7 giorni
    private const CACHE_TTL_LONG  = 2592000; // 30 giorni
    private const CACHE_TTL_EMPTY = 43200;   // 12 ore

    /**
     * Algoritmo ListenBrainz Labs per gli artisti simili. Sovrascrivibile
     * definendo LISTENBRAINZ_SIMILAR_ALGORITHM in config.php/.env, nel caso
     * ListenBrainz ritiri questo dataset.
     */
    private const LB_SIMILAR_ALGORITHM =
        'session_based_days_7500_session_300_contribution_5_threshold_10_limit_100_filter_True_skip_30';

    /** Righe della risposta ListenBrainz considerate (servono anche oltre le prime 30). */
    private const LB_SIMILAR_ROWS = 60;

    /**
     * Soglia sull'indice corretto per popolarità. Misurata sull'archivio il
     * 18/11/2026: il rumore sta intorno a 0,02, la riga valida più bassa a 0,10.
     */
    private const MIN_INDEX = 0.08;

    /** Liste di simili considerate per riconoscere gli artisti "hub". */
    private const SIMILAR_PRIMARY = 30;

    /** Liste in cache necessarie per applicare il filtro sugli hub. */
    private const HUB_MIN_LISTS = 30;

    /**
     * Quota massima di liste dell'archivio in cui un artista può comparire
     * per essere proposto nella scoperta (vedi rankExternalCandidates).
     */
    private const HUB_MAX_SHARE = 0.10;

    /** Rate limit MusicBrainz: 1 richiesta al secondo. */
    private const MB_THROTTLE_US = 1100000;

    /** Istante (microtime) entro cui chiudere il lavoro; 0 = nessun limite. */
    private $deadline = 0.0;

    /** Vero se almeno una chiamata è stata saltata per mancanza di tempo. */
    private $budgetExceeded = false;

    /** Ultimo accesso a MusicBrainz nel processo (per il throttle). */
    private static $mbLastAt = 0.0;

    // ============================================================
    // BUDGET DI TEMPO
    // ============================================================

    /** Imposta il tempo massimo (secondi) per le chiamate esterne. */
    public function startBudget(float $seconds): void
    {
        $this->deadline       = microtime(true) + max(1.0, $seconds);
        $this->budgetExceeded = false;
    }

    public function budgetExceeded(): bool
    {
        return $this->budgetExceeded;
    }

    /** Secondi rimasti; null se non è stato impostato un budget. */
    private function remaining(): ?float
    {
        if ($this->deadline <= 0) {
            return null;
        }
        return $this->deadline - microtime(true);
    }

    /** Vero se c'è tempo per una chiamata che può durare $needed secondi. */
    private function hasTime(float $needed): bool
    {
        $left = $this->remaining();
        if ($left === null || $left >= $needed) {
            return true;
        }
        $this->budgetExceeded = true;
        return false;
    }

    // ============================================================
    // 1) IDENTITÀ MUSICBRAINZ DELL'ARTISTA
    // ============================================================

    /**
     * MBID da usare come sorgente dei simili per il disco.
     *
     * Ordine:
     *   a) MBID già salvato sull'artista;
     *   b) artisti accreditati sul release-group del disco (identità certa:
     *      è il disco stesso a indicarli);
     *   c) ricerca per nome con la prova d'identità della pagina artista
     *      (il candidato deve possedere uno dei dischi locali);
     *   d) nomi composti ("A & B", "A feat. B"): la stessa prova su ogni
     *      parte.
     *
     * save_mbid è valorizzato solo quando l'identità è UNA e certa e può
     * essere salvata sull'artista (b con un solo accreditato, oppure c).
     * Con più accreditati o con le parti di un nome composto gli MBID
     * servono solo come sorgente dei simili: la riga in archivio
     * rappresenta la coppia, non i singoli.
     *
     * ok=false se una fonte ha risposto con errore: l'identità potrebbe
     * esistere, quindi il risultato complessivo non va messo in cache.
     *
     * @param array    $artist      riga artists (mb_artist_id, name)
     * @param string[] $localTitles titoli dei dischi dell'artista in archivio
     * @param array    $rgInfo      risultato di getReleaseGroupInfo() del disco
     * @return array{ok:bool,mbids:string[],save_mbid:string,source:string}
     */
    public function resolveArtistIdentity(array $artist, array $localTitles, array $rgInfo): array
    {
        $out = ['ok' => true, 'mbids' => [], 'save_mbid' => '', 'source' => ''];

        $known = $this->normalizeMbid((string)($artist['mb_artist_id'] ?? ''));
        if ($known !== '') {
            $out['mbids']  = [$known];
            $out['source'] = 'archive';
            return $out;
        }

        // b) accreditati sul release-group
        $credited = $rgInfo['artists'] ?? [];
        if (!empty($credited)) {
            $out['mbids']  = array_values(array_unique($credited));
            $out['source'] = 'release-group';
            if (count($out['mbids']) === 1) {
                $out['save_mbid'] = $out['mbids'][0];
            }
            return $out;
        }
        if (empty($rgInfo['ok'])) {
            $out['ok'] = false;
        }

        $name = trim((string)($artist['name'] ?? ''));
        if ($name === '') {
            return $out;
        }

        require_once __DIR__ . '/ArtistMetadataService.php';
        $meta = new ArtistMetadataService();

        // c) ricerca per nome con prova d'identità (ricerca + verifiche:
        //    almeno 3-4 richieste MusicBrainz)
        if (!$this->hasTime(5.0)) {
            $out['ok'] = false;
            return $out;
        }
        $res = $this->resolveByName($meta, $name, $localTitles);
        if (!$res['ok']) {
            $out['ok'] = false;
        }
        if ($res['mbid'] !== '') {
            $out['mbids']     = [$res['mbid']];
            $out['save_mbid'] = $res['mbid'];
            $out['source']    = 'search';
            return $out;
        }

        // d) nome composto
        $parts = preg_split('/\s+(?:&|and|feat\.?|ft\.?|featuring|with|vs\.?|x)\s+/iu', $name);
        if (is_array($parts) && count($parts) > 1) {
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (!$this->hasTime(5.0)) {
                    $out['ok'] = false;
                    break;
                }
                $r = $this->resolveByName($meta, $part, $localTitles);
                if (!$r['ok']) {
                    $out['ok'] = false;
                }
                if ($r['mbid'] !== '') {
                    $out['mbids'][] = $r['mbid'];
                }
            }
            $out['mbids'] = array_values(array_unique($out['mbids']));
            if (!empty($out['mbids'])) {
                $out['source'] = 'name-parts';
            }
        }

        return $out;
    }

    /**
     * Ricerca per nome con prova d'identità, con memoria degli esiti
     * definitivi:
     *   - MBID trovato: in cache 30 giorni. Per un artista singolo il
     *     controller lo salva anche sull'artista; per le parti di un nome
     *     composto ("A & B") la cache è l'unica memoria, e senza di essa
     *     ogni visita ripeterebbe due ricerche esaurendo il tempo;
     *   - nessun candidato confermato: in cache 7 giorni, così la scheda di
     *     un artista che MusicBrainz non conosce non ripete la ricerca.
     * Gli errori non vanno in cache.
     *
     * @return array{ok:bool,mbid:string}
     */
    private function resolveByName(ArtistMetadataService $meta, string $name, array $localTitles): array
    {
        $titles = [];
        foreach ($localTitles as $t) {
            $titles[] = $this->normalizeArtistName((string)$t);
        }
        sort($titles);
        $cacheKey = 'mb-id-name:v1:' . $this->normalizeArtistName($name) . '|' . sha1(implode('|', $titles));

        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return ['ok' => true, 'mbid' => $this->normalizeMbid((string)($cached['mbid'] ?? ''))];
        }

        $res = $meta->resolveArtistMbid($name, $localTitles);
        if ($res['ok']) {
            $this->writeCache(
                $cacheKey,
                ['mbid' => $res['mbid']],
                $res['mbid'] !== '' ? self::CACHE_TTL_LONG : self::CACHE_TTL_OK
            );
        }

        return $res;
    }

    // ============================================================
    // 2) ARTISTI SIMILI — ListenBrainz
    // ============================================================

    /**
     * Artisti simili per uno o più MBID sorgente, corretti per popolarità.
     *
     * Ogni riga: mbid, name, index (score / sqrt(ascoltatori)), match
     * (index normalizzato sul massimo, 0..1), rank (0 = più simile).
     * Le righe sono già filtrate con MIN_INDEX e ordinate; con più MBID
     * sorgente vale l'indice più alto per ciascun artista.
     *
     * @param string[] $sourceMbids
     * @return array{ok:bool,rows:array<int,array{mbid:string,name:string,index:float,match:float,rank:int}>}
     */
    public function getSimilarArtists(array $sourceMbids): array
    {
        $ok     = true;
        $merged = [];
        $skip   = [];

        foreach ($sourceMbids as $m) {
            $m = $this->normalizeMbid((string)$m);
            if ($m !== '') {
                $skip[$m] = true;
            }
        }

        foreach (array_keys($skip) as $mbid) {
            $res = $this->similarForMbid($mbid);
            if (!$res['ok']) {
                $ok = false;
                continue;
            }
            foreach ($res['rows'] as $row) {
                if (isset($skip[$row['mbid']])) {
                    continue;
                }
                if (!isset($merged[$row['mbid']]) || $row['index'] > $merged[$row['mbid']]['index']) {
                    $merged[$row['mbid']] = $row;
                }
            }
        }

        $rows = array_values($merged);
        usort($rows, function (array $a, array $b): int {
            return $b['index'] <=> $a['index'];
        });

        $max = !empty($rows) ? (float)$rows[0]['index'] : 0.0;
        foreach ($rows as $i => &$r) {
            $r['match'] = $max > 0 ? round($r['index'] / $max, 4) : 0.0;
            $r['rank']  = $i;
        }
        unset($r);

        return ['ok' => $ok, 'rows' => $rows];
    }

    /**
     * Simili di un singolo MBID: similar-artists (ListenBrainz Labs) più
     * popolarità (API ListenBrainz). In cache solo gli esiti validi.
     *
     * @return array{ok:bool,rows:array}
     */
    private function similarForMbid(string $mbid): array
    {
        $cacheKey = 'lb-sim:v1:' . $mbid;
        $cached   = $this->readCache($cacheKey);
        if ($cached !== null) {
            return ['ok' => true, 'rows' => $cached];
        }

        if (!$this->hasTime(4.0)) {
            return ['ok' => false, 'rows' => []];
        }

        $algorithm = (defined('LISTENBRAINZ_SIMILAR_ALGORITHM') && trim((string)LISTENBRAINZ_SIMILAR_ALGORITHM) !== '')
            ? trim((string)LISTENBRAINZ_SIMILAR_ALGORITHM)
            : self::LB_SIMILAR_ALGORITHM;

        $resp = $this->httpJson(
            'https://labs.api.listenbrainz.org/similar-artists/json?artist_mbids='
                . rawurlencode($mbid) . '&algorithm=' . rawurlencode($algorithm)
        );
        if (!$resp['ok']) {
            return ['ok' => false, 'rows' => []];
        }

        // La risposta può essere una lista piatta di righe oppure una
        // lista di blocchi {data: [...]}: si accettano entrambe le forme.
        $raw = [];
        foreach ((array)$resp['data'] as $x) {
            if (is_array($x) && isset($x['data']) && is_array($x['data'])) {
                foreach ($x['data'] as $y) {
                    $raw[] = $y;
                }
            } elseif (is_array($x) && isset($x['artist_mbid'])) {
                $raw[] = $x;
            }
        }

        if (empty($raw)) {
            // Artista sconosciuto a ListenBrainz: esito valido, cache breve.
            $this->writeCache($cacheKey, [], self::CACHE_TTL_EMPTY);
            return ['ok' => true, 'rows' => []];
        }

        $raw = array_slice($raw, 0, self::LB_SIMILAR_ROWS);

        $ids = [];
        foreach ($raw as $r) {
            $id = $this->normalizeMbid((string)($r['artist_mbid'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        if (!$this->hasTime(3.0)) {
            return ['ok' => false, 'rows' => []];
        }
        $pop = $this->httpJson(
            'https://api.listenbrainz.org/1/popularity/artist',
            ['artist_mbids' => array_values(array_unique($ids))]
        );
        if (!$pop['ok']) {
            // Senza popolarità la correzione non si può applicare.
            return ['ok' => false, 'rows' => []];
        }

        $users = [];
        foreach ((array)$pop['data'] as $p) {
            if (is_array($p) && isset($p['artist_mbid'])) {
                $users[$this->normalizeMbid((string)$p['artist_mbid'])] = (int)($p['total_user_count'] ?? 0);
            }
        }

        $rows = [];
        foreach ($raw as $r) {
            $id = $this->normalizeMbid((string)($r['artist_mbid'] ?? ''));
            $u  = $users[$id] ?? 0;
            if ($id === '' || $u <= 0) {
                continue;
            }
            $idx = (float)($r['score'] ?? 0) / sqrt($u);
            if ($idx < self::MIN_INDEX) {
                continue;
            }
            $rows[] = [
                'mbid'  => $id,
                'name'  => trim((string)($r['name'] ?? '')),
                'index' => round($idx, 5),
            ];
        }

        usort($rows, function (array $a, array $b): int {
            return $b['index'] <=> $a['index'];
        });

        $this->writeCache($cacheKey, $rows, !empty($rows) ? self::CACHE_TTL_OK : self::CACHE_TTL_EMPTY);

        return ['ok' => true, 'rows' => $rows];
    }

    // ============================================================
    // 3) RELEASE-GROUP DEL DISCO — generi e artisti accreditati
    // ============================================================

    /**
     * Generi MusicBrainz (campo "genres", conteggio > 0) e MBID degli
     * artisti accreditati sul release-group. Una sola richiesta.
     *
     * @return array{ok:bool,genres:string[],artists:string[]}
     */
    public function getReleaseGroupInfo(string $releaseGroup): array
    {
        $rg = $this->normalizeMbid($releaseGroup);
        if ($rg === '') {
            return ['ok' => true, 'genres' => [], 'artists' => []];
        }

        $cacheKey = 'mb-rg:v1:' . $rg;
        $cached   = $this->readCache($cacheKey);
        if ($cached !== null) {
            return [
                'ok'      => true,
                'genres'  => (array)($cached['genres'] ?? []),
                'artists' => (array)($cached['artists'] ?? []),
            ];
        }

        if (!$this->hasTime(3.0)) {
            return ['ok' => false, 'genres' => [], 'artists' => []];
        }

        $resp = $this->musicBrainzJson(
            'https://musicbrainz.org/ws/2/release-group/' . $rg . '?inc=genres+artist-credits&fmt=json'
        );
        if (!$resp['ok']) {
            // Un 404 (release-group rimosso o unito) è un esito definitivo.
            if ((int)$resp['status'] === 404) {
                $this->writeCache($cacheKey, ['genres' => [], 'artists' => []], self::CACHE_TTL_LONG);
                return ['ok' => true, 'genres' => [], 'artists' => []];
            }
            return ['ok' => false, 'genres' => [], 'artists' => []];
        }

        $genres = [];
        foreach ((array)($resp['data']['genres'] ?? []) as $g) {
            $n = trim((string)($g['name'] ?? ''));
            if ($n !== '' && (int)($g['count'] ?? 0) > 0) {
                $genres[] = $n;
            }
        }

        $artists = [];
        foreach ((array)($resp['data']['artist-credit'] ?? []) as $ac) {
            $id = $this->normalizeMbid((string)($ac['artist']['id'] ?? ''));
            if ($id !== '') {
                $artists[] = $id;
            }
        }

        $payload = ['genres' => $genres, 'artists' => array_values(array_unique($artists))];
        $this->writeCache($cacheKey, $payload, self::CACHE_TTL_LONG);

        return ['ok' => true] + $payload;
    }

    // ============================================================
    // 4) SCOPERTA — album fuori archivio (token ListenBrainz)
    // ============================================================

    /**
     * Un album rappresentativo per alcuni artisti simili che NON sono in
     * archivio: il release-group più ascoltato di tipo Album che non sia
     * evidentemente un live o una raccolta. La conferma "album in studio"
     * su MusicBrainz arriva poi da filterExternalAlbumCandidates().
     *
     * Richiede il token ListenBrainz (l'endpoint top-release-groups risponde
     * 401 senza). Senza token restituisce ok=true e nessun suggerimento: è
     * una funzione facoltativa.
     *
     * @param array    $similar    righe di getSimilarArtists()
     * @param string[] $skipMbids  MBID da saltare (artisti in archivio, sorgente)
     * @return array{ok:bool,rows:array<int,array{name:string,album:string,cover:string,match:float}>}
     */
    public function getExternalSuggestions(array $similar, array $skipMbids, int $limit = 5, int $maxLookups = 8): array
    {
        $token = $this->listenBrainzToken();
        if ($token === '' || empty($similar)) {
            return ['ok' => true, 'rows' => []];
        }

        $skip = [];
        foreach ($skipMbids as $m) {
            $m = $this->normalizeMbid((string)$m);
            if ($m !== '') {
                $skip[$m] = true;
            }
        }

        $candidates = $this->rankExternalCandidates($similar, $skip);

        $ok      = true;
        $out     = [];
        $lookups = 0;

        foreach ($candidates as $row) {
            if (count($out) >= $limit || $lookups >= $maxLookups) {
                break;
            }
            $mbid = (string)($row['mbid'] ?? '');
            $lookups++;

            $top = $this->topStudioAlbum($mbid, (string)($row['name'] ?? ''), $token);
            if (!$top['ok']) {
                $ok = false;
                continue;
            }
            if ($top['album'] === '') {
                continue;
            }

            $out[] = [
                'name'  => $top['artist'] !== '' ? $top['artist'] : (string)($row['name'] ?? ''),
                'album' => $top['album'],
                'cover' => $top['cover'],
                'match' => (float)($row['match'] ?? 0),
                'via'   => (string)($row['name'] ?? ''),
            ];
        }

        return ['ok' => $ok, 'rows' => $out];
    }

    /**
     * Ordine dei candidati per la scoperta (artisti simili fuori archivio).
     *
     * Alcuni artisti risultano simili a buona parte della collezione:
     * nelle sessioni di ascolto compaiono insieme a quasi tutto. Misura del
     * 21/11/2026 su 194 liste di simili dell'archivio: blink-182 compariva
     * fra i primi suggerimenti esterni di System of a Down, Pearl Jam,
     * Coldplay e Stone Temple Pilots, perché presente in 21 liste e con un
     * conteggio di ascoltatori ListenBrainz anomalo (44 mila, contro i 300
     * mila di artisti di pari notorietà), che gonfia l'indice corretto per
     * popolarità.
     *
     * Per questo, quando le liste in cache sono abbastanza (HUB_MIN_LISTS):
     *   - un candidato presente in più del HUB_MAX_SHARE delle liste
     *     dell'archivio non viene proposto (non è specifico del disco);
     *   - gli altri sono ordinati per indice × log((N+1)/(df+1)), dove df è
     *     il numero di liste in cui compaiono: a parità di somiglianza
     *     vince l'artista più specifico.
     * Con poche liste (installazione nuova) resta l'ordine per indice.
     *
     * Vale solo per la scoperta: i consigli in collezione non cambiano.
     *
     * @param array<string,bool> $skip MBID in archivio e MBID sorgente
     */
    private function rankExternalCandidates(array $similar, array $skip): array
    {
        $rows = [];
        foreach ($similar as $row) {
            $mbid = (string)($row['mbid'] ?? '');
            if ($mbid !== '' && !isset($skip[$mbid])) {
                $rows[] = $row;
            }
        }

        $hub = $this->hubIndex(array_keys($skip));
        $n   = $hub['n'];
        if ($n < self::HUB_MIN_LISTS) {
            return $rows;
        }

        $ranked = [];
        foreach ($rows as $row) {
            $df = (int)($hub['df'][$row['mbid']] ?? 0);
            if ($df / $n > self::HUB_MAX_SHARE) {
                continue;
            }
            $row['_ext_score'] = (float)($row['index'] ?? 0) * log(($n + 1) / ($df + 1));
            $ranked[] = $row;
        }

        usort($ranked, function (array $a, array $b): int {
            return $b['_ext_score'] <=> $a['_ext_score'];
        });

        return $ranked;
    }

    /**
     * In quante liste di simili dell'archivio compare ogni artista (primi
     * SIMILAR_PRIMARY di ciascuna lista). Legge solo le liste già in cache,
     * senza chiamate esterne; il risultato resta in cache un giorno.
     *
     * @param string[] $archiveMbids
     * @return array{n:int,df:array<string,int>}
     */
    private function hubIndex(array $archiveMbids): array
    {
        $ids = [];
        foreach ($archiveMbids as $m) {
            $m = $this->normalizeMbid((string)$m);
            if ($m !== '') {
                $ids[$m] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        $cacheKey = 'lb-df:v1:' . sha1(implode(',', $ids));
        $cached   = $this->readCache($cacheKey);
        if ($cached !== null) {
            return ['n' => (int)($cached['n'] ?? 0), 'df' => (array)($cached['df'] ?? [])];
        }

        $n  = 0;
        $df = [];
        foreach ($ids as $m) {
            $rows = $this->readCache('lb-sim:v1:' . $m);
            if (empty($rows)) {
                continue;
            }
            $n++;
            foreach (array_slice($rows, 0, self::SIMILAR_PRIMARY) as $r) {
                $id = (string)($r['mbid'] ?? '');
                if ($id !== '') {
                    $df[$id] = ($df[$id] ?? 0) + 1;
                }
            }
        }

        $this->writeCache($cacheKey, ['n' => $n, 'df' => $df], 86400);

        return ['n' => $n, 'df' => $df];
    }

    /**
     * Release-group più ascoltato di tipo Album per un artista.
     *
     * ListenBrainz elenca anche i release-group in cui l'artista compare
     * solo come ospite: compilation di etichetta, DJ mix firmati da altri,
     * "Various Artists". Si accettano soltanto quelli accreditati
     * all'artista richiesto (MBID nel credito, oppure, se la risposta non
     * riporta gli MBID, lo stesso nome).
     *
     * @return array{ok:bool,artist:string,album:string,cover:string}
     */
    private function topStudioAlbum(string $artistMbid, string $artistName, string $token): array
    {
        $none     = ['ok' => true, 'artist' => '', 'album' => '', 'cover' => ''];
        $cacheKey = 'lb-top:v2:' . $artistMbid;
        $cached   = $this->readCache($cacheKey);
        if ($cached !== null) {
            return ['ok' => true] + $cached + $none;
        }

        if (!$this->hasTime(3.0)) {
            return ['ok' => false] + $none;
        }

        $resp = $this->httpJson(
            'https://api.listenbrainz.org/1/popularity/top-release-groups-for-artist/' . rawurlencode($artistMbid),
            null,
            ['Authorization: Token ' . $token]
        );
        if (!$resp['ok']) {
            return ['ok' => false] + $none;
        }

        $pick = ['artist' => '', 'album' => '', 'cover' => ''];
        foreach ((array)$resp['data'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rg   = (array)($item['release_group'] ?? []);
            $name = trim((string)($rg['name'] ?? ''));
            // Il tipo indicato è solo quello primario: i live risultano
            // "Album". Il filtro sul titolo toglie i casi evidenti, la
            // conferma MusicBrainz quelli restanti.
            if ($name === '' || (string)($rg['type'] ?? '') !== 'Album') {
                continue;
            }
            if ($this->isObviouslyNonStudioAlbumTitle($name)) {
                continue;
            }
            if (!$this->creditedTo($item, $artistMbid, $artistName)) {
                continue;
            }

            $cover    = '';
            $caaId    = (string)($rg['caa_id'] ?? '');
            $caaRelId = $this->normalizeMbid((string)($rg['caa_release_mbid'] ?? ''));
            if ($caaId !== '' && $caaRelId !== '') {
                $cover = 'https://coverartarchive.org/release/' . $caaRelId . '/' . rawurlencode($caaId) . '-250.jpg';
            }

            $artistName = '';
            if (isset($item['artist']) && is_array($item['artist'])) {
                $artistName = (string)($item['artist']['artist_credit_name'] ?? ($item['artist']['name'] ?? ''));
            } elseif (isset($item['artist_name'])) {
                $artistName = (string)$item['artist_name'];
            }

            $pick = [
                'artist' => trim($artistName),
                'album'  => $name,
                'cover'  => $cover,
            ];
            break;
        }

        $this->writeCache($cacheKey, $pick, $pick['album'] !== '' ? self::CACHE_TTL_OK : self::CACHE_TTL_EMPTY);

        return ['ok' => true] + $pick;
    }

    /**
     * Vero se il release-group della risposta ListenBrainz è accreditato
     * all'artista richiesto. Legge gli MBID del credito nelle forme note
     * (artist.artist_credit_mbids, artist.artists[].artist_mbid,
     * artist_mbids); se la risposta non ne riporta, confronta il nome.
     */
    private function creditedTo(array $item, string $artistMbid, string $artistName): bool
    {
        $artist = isset($item['artist']) && is_array($item['artist']) ? $item['artist'] : [];

        $mbids = [];
        foreach ((array)($artist['artist_credit_mbids'] ?? []) as $m) {
            $mbids[] = $this->normalizeMbid((string)$m);
        }
        foreach ((array)($artist['artists'] ?? []) as $a) {
            if (is_array($a)) {
                $mbids[] = $this->normalizeMbid((string)($a['artist_mbid'] ?? ''));
            }
        }
        foreach ((array)($item['artist_mbids'] ?? []) as $m) {
            $mbids[] = $this->normalizeMbid((string)$m);
        }
        $mbids = array_values(array_filter($mbids));

        if (!empty($mbids)) {
            return in_array($artistMbid, $mbids, true);
        }

        $credit = (string)($artist['artist_credit_name'] ?? ($artist['name'] ?? ($item['artist_name'] ?? '')));
        return $credit !== ''
            && $this->normalizeArtistName($credit) === $this->normalizeArtistName($artistName);
    }

    /** Token ListenBrainz configurato (Impostazioni o config), oppure ''. */
    private function listenBrainzToken(): string
    {
        if (ExternalApiConfig::isSupported('listenbrainz')) {
            return ExternalApiConfig::get('listenbrainz');
        }
        return (defined('LISTENBRAINZ_TOKEN') && is_string(LISTENBRAINZ_TOKEN)) ? trim(LISTENBRAINZ_TOKEN) : '';
    }

    // ============================================================
    // ALBUM IN STUDIO — conferma MusicBrainz (invariata nella logica)
    // ============================================================

    /**
     * Prima barriera contro release chiaramente non-studio.
     * La conferma definitiva viene comunque fatta via MusicBrainz.
     */
    private function isObviouslyNonStudioAlbumTitle(string $title): bool
    {
        $t = $this->normalizeArtistName($title);

        if ($t === '') {
            return true;
        }

        $patterns = [
            '/\bthe best of\b/u',
            '/\bbest of\b/u',
            '/\bvery best of\b/u',
            '/\bgreatest hits?\b/u',
            '/\bessential(?:s)?\b/u',
            '/\banthology\b/u',
            '/\bretrospective\b/u',
            '/\bthe collection\b/u',
            '/\bcomplete collection\b/u',
            '/\bsingles collection\b/u',
            '/\bthe singles\b/u',
            '/\bb[\s\-.]?sides?\b/u',
            '/\brarities\b/u',
            '/\bouttakes?\b/u',

            '/^live\b/u',
            '/\blive at\b/u',
            '/\blive in\b/u',
            '/\blive from\b/u',
            '/\blive on\b/u',
            '/\(\s*live\b/u',
            '/\[\s*live\b/u',
            '/\bunplugged\b/u',
            '/\bin concert\b/u',
            '/\bdal vivo\b/u',

            '/\bremix(?:es)?\b/u',
            '/\bremixed\b/u',
            '/\bsoundtrack\b/u',
            '/\boriginal motion picture soundtrack\b/u',
            '/\btribute to\b/u',
            '/\bkaraoke\b/u',
            '/\bmixtape\b/u',

            // Demo / bootleg / prove di studio: non sono l'album ufficiale.
            // Gli stessi marcatori usati con successo dal filtro discografia
            // (es. "Gish Rough Mix", "MCIS Rough Mix" dei Pumpkins).
            '/\bdemos?\b/u',
            '/\bbootlegs?\b/u',
            '/\brough mix(?:es)?\b/u',
            '/\brehearsals?\b/u',
            '/\bsessions?\b/u',

            '/\bbox set\b/u',
            '/\bboxed set\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $t)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rimuove solo suffissi editoriali che non cambiano l'identità
     * dell'album (Deluxe, Remastered, Anniversary...) per facilitare
     * il match con la release-group MusicBrainz.
     */
    private function normalizeStudioAlbumLookupTitle(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            return '';
        }

        $title = preg_replace(
            '/\s*[\(\[]\s*(?:deluxe(?: edition)?|expanded(?: edition)?|special edition|anniversary edition|legacy edition|remaster(?:ed)?(?: edition)?|bonus track(?:s)?(?: edition)?)\s*[\)\]]\s*$/iu',
            '',
            $title
        );

        $title = preg_replace(
            '/\s*[-–—]\s*(?:\d{4}\s+)?remaster(?:ed)?\s*$/iu',
            '',
            (string)$title
        );

        return trim((string)$title);
    }

    /**
     * Conferma via MusicBrainz che i candidati siano album in studio.
     *
     * Regola stretta:
     *   primary-type = Album
     *   secondary-types = []
     *
     * Quindi vengono esclusi Compilation, Live, Remix, Soundtrack,
     * Mixtape, DJ-mix e altri secondary type.
     *
     * I candidati non presenti in cache vengono verificati con UNA sola
     * query OR verso release-group. Se MusicBrainz non conferma, il
     * candidato viene scartato: meglio meno suggerimenti ma puliti.
     *
     * @return array<int,array>
     */
    private function keepConfirmedStudioAlbums(array $candidates): array
    {
        if (empty($candidates)) {
            return [];
        }

        $accepted = [];
        $toSchedule = []; // candidati da confermare al prossimo giro (async)

        foreach ($candidates as $index => $row) {
            $artist = trim((string)($row['name'] ?? ''));
            $title  = trim((string)($row['album'] ?? ''));

            if ($artist === '' || $title === '') {
                continue;
            }

            // Filtro-titolo (zero chiamate): scarta i non-studio evidenti.
            if ($this->isObviouslyNonStudioAlbumTitle($title)) {
                continue;
            }

            $lookupTitle = $this->normalizeStudioAlbumLookupTitle($title);

            if ($lookupTitle === '') {
                continue;
            }

            $artistNorm = $this->normalizeArtistName($artist);
            $titleNorm  = $this->normalizeArtistName($lookupTitle);

            $cacheKey = 'studio-rg:' . $artistNorm . '|' . $titleNorm;

            $cached = $this->readCache($cacheKey);

            if ($cached !== null && array_key_exists('studio', $cached)) {
                // Conferma MB già in cache: rispettala.
                if (!empty($cached['studio'])) {
                    $accepted[$index] = $row;
                }
                continue;
            }

            // NON in cache → NON blocchiamo con MusicBrainz adesso.
            // Ci fidiamo del filtro-titolo (già superato) e ACCETTIAMO il
            // candidato subito: primo caricamento veloce. Segniamo che va
            // confermato, così la verifica MB parte fuori dal percorso
            // critico (vedi confirmScheduledStudioAlbums), e dal giro dopo
            // la cache 'studio' filtrerà gli eventuali falsi positivi.
            $accepted[$index] = $row;

            $toSchedule[] = [
                'artist'    => $artist,
                'title'     => $lookupTitle,
                'cache_key' => $cacheKey,
            ];
        }

        // Scrive i marker "da confermare" (best-effort, nessuna rete).
        if (!empty($toSchedule)) {
            $this->scheduleStudioConfirmations($toSchedule);
        }

        ksort($accepted);

        return array_values($accepted);
    }

    /**
     * Accoda su file i candidati la cui natura studio/non-studio non è
     * ancora nota, così la conferma MusicBrainz può avvenire FUORI dal
     * percorso critico (non blocca il primo caricamento). Il marker è
     * leggero: solo artista+titolo+chiave cache di destinazione.
     */
    private function scheduleStudioConfirmations(array $items): void
    {
        $file = $this->cacheDir() . '/_studio_confirm_queue.json';

        $queue = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $dec = $raw ? json_decode($raw, true) : null;
            if (is_array($dec)) {
                $queue = $dec;
            }
        }

        // Dedup per cache_key, tetto a 200 voci per non far crescere il file.
        $seen = [];
        foreach ($queue as $q) {
            if (!empty($q['cache_key'])) {
                $seen[$q['cache_key']] = true;
            }
        }
        foreach ($items as $it) {
            if (empty($it['cache_key']) || isset($seen[$it['cache_key']])) {
                continue;
            }
            $seen[$it['cache_key']] = true;
            $queue[] = $it;
        }
        if (count($queue) > 200) {
            $queue = array_slice($queue, -200);
        }

        @file_put_contents(
            $file,
            json_encode($queue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * Elabora la coda di conferme studio-album (MusicBrainz), a piccoli
     * lotti e rispettando il rate-limit (~1 req/s). NON viene chiamata nel
     * percorso di risposta: va invocata separatamente (endpoint dedicato
     * chiamato dal JS dopo il render, oppure cron). Popola la cache
     * 'studio-rg:*' che al giro successivo filtrerà i falsi positivi.
     *
     * @return int quante conferme sono state elaborate
     */
    public function confirmScheduledStudioAlbums(int $maxItems = 10): int
    {
        $file = $this->cacheDir() . '/_studio_confirm_queue.json';
        if (!is_file($file)) {
            return 0;
        }

        $raw = @file_get_contents($file);
        $queue = $raw ? json_decode($raw, true) : null;
        if (!is_array($queue) || empty($queue)) {
            return 0;
        }

        $maxItems = max(1, min(25, $maxItems));
        $batch = array_splice($queue, 0, $maxItems);

        // Salva subito la coda accorciata (così due richieste concorrenti
        // non rielaborano lo stesso lotto).
        @file_put_contents(
            $file,
            json_encode($queue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        $done = 0;
        foreach ($batch as $item) {
            $artist    = trim((string)($item['artist'] ?? ''));
            $title     = trim((string)($item['title'] ?? ''));
            $cacheKey  = (string)($item['cache_key'] ?? '');
            if ($artist === '' || $title === '' || $cacheKey === '') {
                continue;
            }

            // Salta se nel frattempo qualcuno l'ha già confermato.
            $existing = $this->readCache($cacheKey);
            if ($existing !== null && array_key_exists('studio', $existing)) {
                continue;
            }

            $isStudio = $this->musicBrainzIsStudioAlbum($artist, $title);

            // MusicBrainz non ha risposto: nessun verdetto in cache, la voce
            // torna in coda e verrà riprovata. Prima un errore veniva salvato
            // come "non è un album in studio" per 7 giorni.
            if ($isStudio === null) {
                $this->scheduleStudioConfirmations([$item]);
                continue;
            }

            $this->writeCache($cacheKey, ['studio' => $isStudio], self::CACHE_TTL_OK);
            $done++;
            // Il rate limit di MusicBrainz (1 richiesta al secondo) è
            // applicato da musicBrainzJson().
        }

        return $done;
    }

    /**
     * Interroga MusicBrainz per stabilire se (artista, titolo) è uno studio
     * album puro (primary-type Album, nessun secondary-type). Una sola
     * chiamata. true = confermato studio; false = non trovato o non studio;
     * null = MusicBrainz non ha risposto (nessun verdetto).
     */
    private function musicBrainzIsStudioAlbum(string $artist, string $title): ?bool
    {
        $artistEsc = str_replace(['\\', '"'], ['\\\\', '\\"'], $artist);
        $titleEsc  = str_replace(['\\', '"'], ['\\\\', '\\"'], $title);
        $query = '(releasegroup:"' . $titleEsc . '" AND artist:"' . $artistEsc . '")';

        $url = 'https://musicbrainz.org/ws/2/release-group/'
            . '?query=' . rawurlencode($query)
            . '&fmt=json&limit=25';

        $resp = $this->httpGetJson($url);
        if (!$resp['ok'] || !empty($resp['data']['error'])) {
            return null;
        }

        $titleNorm  = $this->normalizeArtistName($this->normalizeStudioAlbumLookupTitle($title));
        $artistNorm = $this->normalizeArtistName($artist);

        foreach ((array)($resp['data']['release-groups'] ?? []) as $rg) {
            if (trim((string)($rg['primary-type'] ?? '')) !== 'Album') {
                continue;
            }
            $secondary = $rg['secondary-types'] ?? [];
            if (is_array($secondary) && !empty($secondary)) {
                continue; // live, compilation, soundtrack…
            }

            $rgTitle = $this->normalizeArtistName(
                $this->normalizeStudioAlbumLookupTitle((string)($rg['title'] ?? ''))
            );
            if ($rgTitle === '' || $rgTitle !== $titleNorm) {
                continue;
            }

            foreach (($rg['artist-credit'] ?? []) as $credit) {
                if (!is_array($credit)) {
                    continue;
                }
                $creditName = trim((string)(
                    $credit['name'] ?? ($credit['artist']['name'] ?? '')
                ));
                if ($creditName !== ''
                    && $this->normalizeArtistName($creditName) === $artistNorm) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Filtro finale dei suggerimenti esterni.
     * Accetta soltanto album in studio confermati da MusicBrainz, poi
     * rimuove dischi posseduti, artisti già mostrati e duplicati.
     */
    public function filterExternalAlbumCandidates(
        array $candidates,
        array $ownedAlbums,
        int $limit = 5,
        array $excludeArtistNames = []
    ): array {
        $candidates = $this->keepConfirmedStudioAlbums($candidates);

        if (empty($candidates)) {
            return [];
        }

        $owned = [];

        foreach ($ownedAlbums as $row) {
            $artist = $this->normalizeArtistName((string)($row['artist'] ?? ''));
            $title  = $this->normalizeArtistName((string)($row['title'] ?? ''));

            if ($artist !== '' && $title !== '') {
                $owned[$artist . '|' . $title] = true;
            }
        }

        $skipArtists = [];

        foreach ($excludeArtistNames as $name) {
            $norm = $this->normalizeArtistName((string)$name);

            if ($norm !== '') {
                $skipArtists[$norm] = true;
            }
        }

        $out = [];
        $usedArtists = [];

        foreach ($candidates as $row) {
            $artist = trim((string)($row['name'] ?? ''));
            $title  = trim((string)($row['album'] ?? ''));

            if ($artist === '' || $title === '') {
                continue;
            }

            $artistNorm = $this->normalizeArtistName($artist);
            $titleNorm  = $this->normalizeArtistName($title);

            if ($artistNorm === '' || $titleNorm === '') {
                continue;
            }

            if (isset($skipArtists[$artistNorm]) || isset($usedArtists[$artistNorm])) {
                continue;
            }

            if (isset($owned[$artistNorm . '|' . $titleNorm])) {
                continue;
            }

            $usedArtists[$artistNorm] = true;
            $out[] = $row;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }


    // ============================================================
    // COVER, CACHE, UTILITÀ
    // ============================================================

    /**
     * Cerca su Deezer la cover di un album con match ESATTO (normalizzato)
     * su artista e titolo. Ritorna l'URL della cover grande o '' se assente.
     * Best-effort: qualunque problema di rete → ''.
     */
    private function deezerAlbumCover(string $artistName, string $albumTitle): string
    {
        $wantArtist = $this->normalizeArtistName($artistName);
        $wantTitle  = $this->normalizeArtistName($albumTitle);
        if ($wantArtist === '' || $wantTitle === '') {
            return '';
        }

        $q = 'artist:"' . $artistName . '" album:"' . $albumTitle . '"';
        $resp = $this->httpGetJson('https://api.deezer.com/search/album?q=' . rawurlencode($q));

        if (!$resp['ok'] || !empty($resp['data']['error'])) {
            return '';
        }

        foreach (array_slice($resp['data']['data'] ?? [], 0, 10) as $al) {
            $aName = (string) ($al['artist']['name'] ?? '');
            $title = (string) ($al['title'] ?? '');
            if ($aName === '' || $title === '') {
                continue;
            }
            if ($this->normalizeArtistName($aName) !== $wantArtist) {
                continue;
            }
            if ($this->normalizeArtistName($title) !== $wantTitle) {
                continue;
            }
            $img = $al['cover_xl'] ?? ($al['cover_big'] ?? '');
            if ($img !== '') {
                return (string) $img;
            }
        }

        return '';
    }

    private function cacheDir(): string
    {
        $dir = BASE_PATH . '/cache/recommendations';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function cacheFile(string $key): string
    {
        return $this->cacheDir() . '/' . sha1($key) . '.json';
    }

    private function readCache(string $key): ?array
    {
        $file = $this->cacheFile($key);

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        $data = $raw ? json_decode($raw, true) : null;

        if (!is_array($data) || !isset($data['_expires'], $data['_payload'])) {
            return null;
        }

        if (time() > (int)$data['_expires']) {
            @unlink($file);
            return null;
        }

        return is_array($data['_payload']) ? $data['_payload'] : null;
    }

    private function writeCache(string $key, array $payload, int $ttlSeconds): void
    {
        $file = $this->cacheFile($key);

        $data = [
            '_expires' => time() + $ttlSeconds,
            '_payload' => $payload,
        ];

        @file_put_contents(
            $file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function normalizeArtistName(string $name): string
    {
        $name = trim($name);

        if (function_exists('mb_strtolower')) {
            $name = mb_strtolower($name, 'UTF-8');
        } else {
            $name = strtolower($name);
        }

        $name = preg_replace('/\s+/u', ' ', $name);

        return trim((string)$name);
    }


    private function normalizeMbid(string $mbid): string
    {
        $mbid = strtolower(trim($mbid));
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $mbid) ? $mbid : '';
    }

    /**
     * GET verso MusicBrainz con throttle di 1 richiesta al secondo nel
     * processo e un nuovo tentativo sui 503 (MusicBrainz li restituisce
     * spesso sotto carico).
     *
     * @return array{ok:bool,data:array,status:int}
     */
    private function musicBrainzJson(string $url): array
    {
        $resp = ['ok' => false, 'data' => [], 'status' => 0];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $elapsedUs = (int)((microtime(true) - self::$mbLastAt) * 1000000);
            if ($elapsedUs < self::MB_THROTTLE_US) {
                usleep(self::MB_THROTTLE_US - $elapsedUs);
            }
            self::$mbLastAt = microtime(true);

            $resp = $this->httpJson($url);
            $transient = !$resp['ok'] && ($resp['status'] === 0 || $resp['status'] === 429 || $resp['status'] >= 500);
            if (!$transient || $attempt === 2 || !$this->hasTime(3.0)) {
                break;
            }
        }

        return $resp;
    }

    /**
     * GET (oppure POST JSON se $postJson non è null) con risposta JSON.
     *
     * ok=false per errori di rete, status diverso da 2xx, corpo non JSON o
     * oggetto {"error": ...}. status riporta il codice HTTP (0 = nessuna
     * risposta). Il timeout totale non supera il budget residuo.
     *
     * @param string[] $headers intestazioni aggiuntive
     * @return array{ok:bool,data:array,status:int}
     */
    private function httpJson(string $url, ?array $postJson = null, array $headers = []): array
    {
        $ua = defined('APP_USER_AGENT')
            ? APP_USER_AGENT
            : 'GrizzlyMusicArchive/1.0 ( https://github.com/recycledesign9/grizzly-music-archive )';

        $timeout = 8.0;
        $left    = $this->remaining();
        if ($left !== null) {
            $timeout = max(1.0, min($timeout, $left));
        }

        $headers[] = 'Accept: application/json';
        $body      = null;
        if ($postJson !== null) {
            $body      = json_encode($postJson);
            $headers[] = 'Content-Type: application/json';
        }

        $raw    = false;
        $status = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => (int)ceil($timeout),
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_HTTPHEADER     => $headers,
            ];
            if ($body !== null) {
                $opts[CURLOPT_POST]       = true;
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($ch, $opts);
            $raw    = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (curl_error($ch) !== '') {
                $raw = false;
            }
            curl_close($ch);
        } else {
            $headers[] = 'User-Agent: ' . $ua;
            $http = [
                'method'        => $body !== null ? 'POST' : 'GET',
                'header'        => implode("\r\n", $headers) . "\r\n",
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ];
            if ($body !== null) {
                $http['content'] = $body;
            }
            $raw = @file_get_contents($url, false, stream_context_create(['http' => $http]));
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $h) {
                    if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
                        $status = (int)$m[1];
                    }
                }
            }
        }

        if ($raw === false || $status < 200 || $status >= 300) {
            return ['ok' => false, 'data' => [], 'status' => $status];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded) || isset($decoded['error'])) {
            return ['ok' => false, 'data' => [], 'status' => $status];
        }

        return ['ok' => true, 'data' => $decoded, 'status' => $status];
    }

    /**
     * Compatibilità con i metodi conservati (conferma studio, cover Deezer),
     * che usano la forma {ok, data}.
     *
     * @return array{ok:bool,data:array,status:int}
     */
    private function httpGetJson(string $url): array
    {
        if (strpos($url, 'https://musicbrainz.org/') === 0) {
            return $this->musicBrainzJson($url);
        }
        return $this->httpJson($url);
    }
}

<?php

/**
 * AlbumRecommendationService
 * ------------------------------------------------------------
 * Recupera artisti simili da Last.fm per costruire raccomandazioni
 * musicali nella scheda album.
 *
 * Cache file:
 *   cache/recommendations/{sha1}.json
 *
 * Compatibile PHP 7.4.
 */
class AlbumRecommendationService
{
    private const LASTFM_LIMIT = 30;
    private const CACHE_TTL_OK = 604800;   // 7 giorni
    private const CACHE_TTL_EMPTY = 43200; // 12 ore

    /**
     * @return array<int,array{name:string,match:float}>
     */
    public function getSimilarArtists(string $artistName): array
    {
        $artistName = trim($artistName);

        if ($artistName === '' || !defined('LASTFM_API_KEY') || LASTFM_API_KEY === '') {
            return [];
        }

        $cacheKey = $this->normalizeArtistName($artistName);
        $cached = $this->readCache($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $url = 'https://ws.audioscrobbler.com/2.0/'
            . '?method=artist.getsimilar'
            . '&artist=' . rawurlencode($artistName)
            . '&api_key=' . rawurlencode(LASTFM_API_KEY)
            . '&format=json'
            . '&autocorrect=1'
            . '&limit=' . self::LASTFM_LIMIT;

        $resp = $this->httpGetJson($url);

        // Rete/API KO: non cachare.
        if (!$resp['ok']) {
            return [];
        }

        $data = $resp['data'];

        // Last.fm può restituire un errore dentro JSON valido.
        if (!empty($data['error'])) {
            return [];
        }

        $out = [];
        $seen = [];
        $currentNorm = $this->normalizeArtistName($artistName);

        foreach (($data['similarartists']['artist'] ?? []) as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $norm = $this->normalizeArtistName($name);

            if ($norm === '' || $norm === $currentNorm || isset($seen[$norm])) {
                continue;
            }

            $match = (float)($row['match'] ?? 0);
            if ($match <= 0) {
                continue;
            }

            $seen[$norm] = true;
            $out[] = [
                'name'  => $name,
                'match' => $match,
            ];
        }

        $this->writeCache(
            $cacheKey,
            $out,
            !empty($out) ? self::CACHE_TTL_OK : self::CACHE_TTL_EMPTY
        );

        return $out;
    }

    /**
     * Tag dell'ALBUM specifico da Last.fm (album.getInfo), normalizzati e
     * minuscoli: es. ["noise rock","alternative rock","italian"]. A
     * differenza di artist.getSimilar (che ragiona sull'artista), questi
     * descrivono il DISCO — sono il segnale che rende i consigli
     * "album-aware": Il Vile e Che cosa vedi dei Marlene Kuntz hanno tag
     * diversi e quindi possono riordinare i candidati in modo diverso.
     *
     * Una sola chiamata per album corrente (non per candidato). Cache
     * propria con chiave "tags:<artista>|<album>".
     *
     * @return array<int,string>
     */
    public function getAlbumTags(string $artistName, string $albumTitle): array
    {
        $artistName = trim($artistName);
        $albumTitle = trim($albumTitle);

        if ($artistName === '' || $albumTitle === ''
            || !defined('LASTFM_API_KEY') || LASTFM_API_KEY === '') {
            return [];
        }

        $cacheKey = 'tags:' . $this->normalizeArtistName($artistName)
            . '|' . $this->normalizeArtistName($albumTitle);

        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url = 'https://ws.audioscrobbler.com/2.0/'
            . '?method=album.getinfo'
            . '&artist=' . rawurlencode($artistName)
            . '&album=' . rawurlencode($albumTitle)
            . '&api_key=' . rawurlencode(LASTFM_API_KEY)
            . '&format=json&autocorrect=1';

        $resp = $this->httpGetJson($url);
        if (!$resp['ok'] || !empty($resp['data']['error'])) {
            // Rete/errore: NON cachare (potrebbe essere transitorio), così
            // si ritenta alla prossima apertura.
            return [];
        }

        $out = [];
        $seen = [];
        $rawTags = $resp['data']['album']['tags']['tag'] ?? [];

        // Last.fm ritorna 'tag' come lista di oggetti, ma con un solo tag
        // può restituire un singolo oggetto: normalizziamo a lista.
        if (isset($rawTags['name'])) {
            $rawTags = [$rawTags];
        }

        if (is_array($rawTags)) {
            foreach ($rawTags as $t) {
                $tag = $this->normalizeArtistName((string) ($t['name'] ?? ''));
                if ($tag !== '' && !isset($seen[$tag])) {
                    $seen[$tag] = true;
                    $out[] = $tag;
                }
            }
        }

        // Esito valido (anche lista vuota di tag): cache lunga.
        $this->writeCache($cacheKey, $out, self::CACHE_TTL_OK);

        return $out;
    }



    /**
     * Segnale specifico del DISCO ottenuto dalle sue tracce.
     * Campiona prima, centro e ultima traccia e usa track.getSimilar.
     * hits = numero di TRACCE SORGENTE differenti che suggeriscono l'artista.
     *
     * @param array<int,string> $trackTitles
     * @return array<string,array{name:string,score:float,hits:int}>
     */
    public function getTrackBasedArtistScores(
        string $artistName,
        array $trackTitles,
        int $sampleSize = 3
    ): array {
        $artistName = trim($artistName);
        if ($artistName === '' || empty($trackTitles)
            || !defined('LASTFM_API_KEY') || LASTFM_API_KEY === '') {
            return [];
        }
        $sampledTracks = $this->sampleSourceTracks($trackTitles, $sampleSize);
        if (empty($sampledTracks)) return [];

        $scores = [];
        $sourceNorm = $this->normalizeArtistName($artistName);

        foreach ($sampledTracks as $trackTitle) {
            $rows = $this->getSimilarTracks($artistName, $trackTitle, 25);
            $bestForThisSource = [];

            foreach ($rows as $rank => $row) {
                $candidate = trim((string)($row['artist'] ?? ''));
                $norm = $this->normalizeArtistName($candidate);
                if ($candidate === '' || $norm === '' || $norm === $sourceNorm) continue;

                $rankScore = max(15.0, 250.0 - ($rank * 9.0));
                if (!isset($bestForThisSource[$norm]) || $rankScore > $bestForThisSource[$norm]['score']) {
                    $bestForThisSource[$norm] = ['name'=>$candidate,'score'=>$rankScore];
                }
            }

            foreach ($bestForThisSource as $norm => $row) {
                if (!isset($scores[$norm])) {
                    $scores[$norm] = ['name'=>$row['name'],'score'=>0.0,'hits'=>0];
                }
                $scores[$norm]['score'] += (float)$row['score'];
                $scores[$norm]['hits']++;
            }
        }

        foreach ($scores as &$row) {
            if ($row['hits'] > 1) $row['score'] += 160.0 * ($row['hits'] - 1);
        }
        unset($row);
        return $scores;
    }

    /** @return array<int,string> */
    private function sampleSourceTracks(array $trackTitles, int $sampleSize = 3): array
    {
        $tracks=[];
        foreach ($trackTitles as $title) {
            $title=trim((string)$title);
            if ($title!=='') $tracks[]=$title;
        }
        if (empty($tracks)) return [];
        $sampleSize=max(1,min(3,$sampleSize));
        $indexes=[0];
        if (count($tracks)>=3) $indexes[]=(int)floor((count($tracks)-1)/2);
        if (count($tracks)>=2) $indexes[]=count($tracks)-1;
        $indexes=array_slice(array_values(array_unique($indexes)),0,$sampleSize);
        $out=[];
        foreach ($indexes as $idx) if (isset($tracks[$idx])) $out[]=$tracks[$idx];
        return $out;
    }

    /**
     * @return array<int,array{artist:string,track:string,match:float}>
     */
    private function getSimilarTracks(
        string $artistName,
        string $trackTitle,
        int $limit = 25
    ): array {
        $limit = max(5, min(40, $limit));

        $cacheKey = 'track-similar:'
            . $this->normalizeArtistName($artistName)
            . '|'
            . $this->normalizeArtistName($trackTitle)
            . ':'
            . $limit;

        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url = 'https://ws.audioscrobbler.com/2.0/'
            . '?method=track.getsimilar'
            . '&artist=' . rawurlencode($artistName)
            . '&track=' . rawurlencode($trackTitle)
            . '&api_key=' . rawurlencode(LASTFM_API_KEY)
            . '&format=json'
            . '&autocorrect=1'
            . '&limit=' . $limit;

        $resp = $this->httpGetJson($url);

        if (!$resp['ok'] || !empty($resp['data']['error'])) {
            return [];
        }

        $rows = $resp['data']['similartracks']['track'] ?? [];

        if (isset($rows['name'])) {
            $rows = [$rows];
        }

        $out = [];

        foreach ((array)$rows as $row) {
            $artist = trim((string)($row['artist']['name'] ?? ''));
            $track  = trim((string)($row['name'] ?? ''));

            if ($artist === '' || $track === '') {
                continue;
            }

            $out[] = [
                'artist' => $artist,
                'track'  => $track,
                'match'  => (float)($row['match'] ?? 0),
            ];
        }

        $this->writeCache(
            $cacheKey,
            $out,
            !empty($out) ? self::CACHE_TTL_OK : self::CACHE_TTL_EMPTY
        );

        return $out;
    }

    /**
     * Costruisce un ranking di artisti specifico del disco.
     *
     * artist.getSimilar resta la base generale; track.getSimilar può
     * riordinare i candidati e introdurre artisti che emergono dalle
     * tracce di questo album ma non dai primi risultati artist-level.
     *
     * @param array<int,array{name:string,match:float}> $similar
     * @param array<string,array{name:string,score:float,hits:int}> $trackArtistScores
     * @return array<int,array{name:string,match:float,_album_score:float,_track_hits:int}>
     */
    public function rankSimilarArtistsForAlbum(
        array $similar,
        array $trackArtistScores
    ): array {
        $pool = [];

        foreach ($similar as $item) {
            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $norm = $this->normalizeArtistName($name);
            if ($norm === '') {
                continue;
            }

            $pool[$norm] = [
                'name'  => $name,
                'match' => max(0.0, min(1.0, (float)($item['match'] ?? 0))),
            ];
        }

        // Ammette anche artisti emersi SOLO dalle tracce dell'album.
        foreach ($trackArtistScores as $norm => $row) {
            $name = trim((string)($row['name'] ?? ''));

            if ($name !== '' && !isset($pool[$norm])) {
                $pool[$norm] = [
                    'name'  => $name,
                    'match' => 0.0,
                ];
            }
        }

        $ranked = [];

        foreach ($pool as $norm => $item) {
            // L'artista generale pesa, ma non monopolizza più il risultato.
            $score = ((float)$item['match']) * 600.0;
            $trackHits = 0;

            if (isset($trackArtistScores[$norm])) {
                $score += min(
                    850.0,
                    (float)($trackArtistScores[$norm]['score'] ?? 0)
                );
                $trackHits = (int)($trackArtistScores[$norm]['hits'] ?? 0);
            }

            $item['_album_score'] = $score;
            $item['_track_hits'] = $trackHits;
            $ranked[] = $item;
        }

        usort($ranked, function (array $a, array $b): int {
            $sa = (float)($a['_album_score'] ?? 0);
            $sb = (float)($b['_album_score'] ?? 0);

            if ($sa === $sb) {
                return strcmp((string)$a['name'], (string)$b['name']);
            }

            return ($sa < $sb) ? 1 : -1;
        });

        return $ranked;
    }


    /**
     * Candidati ALBUM realmente specifici del disco:
     * track.getSimilar -> track.getInfo -> album reale del brano simile.
     * tag.getTopAlbums non viene usato qui.
     *
     * @param array<int,string> $trackTitles
     * @return array<int,array{name:string,album:string,cover:string,score:float,hits:int,track:string}>
     */
    public function getTrackBasedAlbumCandidates(
        string $sourceArtist,
        array $trackTitles,
        array $rankedSimilarArtists,
        int $limit = 30
    ): array {
        $sourceArtist=trim($sourceArtist);
        if ($sourceArtist==='' || empty($trackTitles)) return [];
        $sampledTracks=$this->sampleSourceTracks($trackTitles,3);
        if (empty($sampledTracks)) return [];

        $artistBonus=[];
        foreach ($rankedSimilarArtists as $rank=>$item) {
            $name=trim((string)($item['name']??''));
            $norm=$this->normalizeArtistName($name);
            if ($norm!=='') $artistBonus[$norm]=max(0.0,220.0-($rank*7.0));
        }

        $sourceNorm=$this->normalizeArtistName($sourceArtist);
        $trackPool=[];
        foreach ($sampledTracks as $sourceTrack) {
            $rows=$this->getSimilarTracks($sourceArtist,$sourceTrack,25);
            $bestForThisSource=[];
            foreach ($rows as $rank=>$row) {
                $artist=trim((string)($row['artist']??''));
                $track=trim((string)($row['track']??''));
                if ($artist==='' || $track==='') continue;
                $artistNorm=$this->normalizeArtistName($artist);
                if ($artistNorm==='' || $artistNorm===$sourceNorm) continue;
                $key=$artistNorm.'|'.$this->normalizeArtistName($track);
                $score=max(20.0,360.0-($rank*12.0));
                if (!isset($bestForThisSource[$key]) || $score>$bestForThisSource[$key]['score']) {
                    $bestForThisSource[$key]=['artist'=>$artist,'track'=>$track,'score'=>$score];
                }
            }
            foreach ($bestForThisSource as $key=>$row) {
                if (!isset($trackPool[$key])) $trackPool[$key]=['artist'=>$row['artist'],'track'=>$row['track'],'score'=>0.0,'hits'=>0];
                $trackPool[$key]['score']+=(float)$row['score'];
                $trackPool[$key]['hits']++;
            }
        }

        foreach ($trackPool as &$row) {
            $artistNorm=$this->normalizeArtistName((string)$row['artist']);
            $row['score'] += $artistBonus[$artistNorm] ?? 0.0;
            if ($row['hits']>1) $row['score'] += 180.0*($row['hits']-1);
        }
        unset($row);
        $trackCandidates=array_values($trackPool);
        usort($trackCandidates,function(array $a,array $b):int{
            $sa=(float)($a['score']??0); $sb=(float)($b['score']??0);
            if ($sa===$sb) return strcmp((string)$a['artist'].'|'.(string)$a['track'],(string)$b['artist'].'|'.(string)$b['track']);
            return ($sa<$sb)?1:-1;
        });

        $toResolve=[]; $perArtist=[];
        foreach ($trackCandidates as $row) {
            $artistNorm=$this->normalizeArtistName((string)$row['artist']);
            if (($perArtist[$artistNorm]??0)>=2) continue;
            $perArtist[$artistNorm]=($perArtist[$artistNorm]??0)+1;
            $toResolve[]=$row;
            if (count($toResolve)>=12) break;
        }

        $resolved=$this->resolveTrackAlbums($toResolve);
        $albums=[];
        foreach ($toResolve as $row) {
            $trackKey=$this->trackInfoKey((string)$row['artist'],(string)$row['track']);
            $info=$resolved[$trackKey]??null;
            if (!is_array($info) || empty($info['album'])) continue;
            $artist=trim((string)$row['artist']);
            $albumTitle=trim((string)$info['album']);
            if ($artist==='' || $albumTitle==='') continue;
            $albumKey=$this->normalizeArtistName($artist).'|'.$this->normalizeArtistName($albumTitle);
            if (!isset($albums[$albumKey])) {
                $albums[$albumKey]=['name'=>$artist,'album'=>$albumTitle,'cover'=>(string)($info['cover']??''),'score'=>0.0,'hits'=>0,'track'=>(string)$row['track']];
            }
            $albums[$albumKey]['score']+=(float)$row['score'];
            $albums[$albumKey]['hits']++;
            if ($albums[$albumKey]['cover']==='' && !empty($info['cover'])) $albums[$albumKey]['cover']=(string)$info['cover'];
        }
        foreach ($albums as &$row) if ($row['hits']>1) $row['score']+=160.0*($row['hits']-1);
        unset($row);
        $out=array_values($albums);
        usort($out,function(array $a,array $b):int{
            $sa=(float)($a['score']??0); $sb=(float)($b['score']??0);
            if ($sa===$sb) return strcmp((string)$a['name'].'|'.(string)$a['album'],(string)$b['name'].'|'.(string)$b['album']);
            return ($sa<$sb)?1:-1;
        });
        return array_slice($out,0,max(1,min(60,$limit)));
    }

    private function trackInfoKey(string $artistName,string $trackTitle):string
    {
        return 'track-info:'.$this->normalizeArtistName($artistName).'|'.$this->normalizeArtistName($trackTitle);
    }

    /** @return array<string,array{album:string,cover:string}> */
    private function resolveTrackAlbums(array $candidates):array
    {
        $resolved=[]; $pending=[];
        foreach ($candidates as $row) {
            $artist=trim((string)($row['artist']??'')); $track=trim((string)($row['track']??''));
            if ($artist==='' || $track==='') continue;
            $key=$this->trackInfoKey($artist,$track);
            $cached=$this->readCache($key);
            if ($cached!==null) {
                $resolved[$key]=['album'=>(string)($cached['album']??''),'cover'=>(string)($cached['cover']??'')];
            } else $pending[$key]=['artist'=>$artist,'track'=>$track];
        }
        if (empty($pending)) return $resolved;

        if (function_exists('curl_multi_init') && function_exists('curl_init')) {
            $mh=curl_multi_init(); $handles=[];
            $ua=defined('APP_USER_AGENT')?APP_USER_AGENT:'GrizzlyMusicArchive/1.0';
            foreach ($pending as $key=>$row) {
                $url='https://ws.audioscrobbler.com/2.0/?method=track.getinfo'
                    .'&artist='.rawurlencode($row['artist']).'&track='.rawurlencode($row['track'])
                    .'&api_key='.rawurlencode(LASTFM_API_KEY).'&format=json&autocorrect=1';
                $ch=curl_init($url);
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_USERAGENT=>$ua,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
                curl_multi_add_handle($mh,$ch); $handles[$key]=$ch;
            }
            $active=null;
            do {
                $status=curl_multi_exec($mh,$active);
                if ($active) { $selected=curl_multi_select($mh,1.0); if ($selected===-1) usleep(10000); }
            } while ($active && $status===CURLM_OK);

            foreach ($handles as $key=>$ch) {
                $body=curl_multi_getcontent($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch);
                $info=['album'=>'','cover'=>''];
                if ($body!==false && $err==='' && $code>=200 && $code<300) {
                    $data=json_decode($body,true);
                    if (is_array($data) && empty($data['error'])) $info=$this->extractAlbumFromTrackInfo($data);
                }
                // Fallback Deezer: se abbiamo l'album ma non la cover, cerchiamola.
                if ($info['album']!=='' && $info['cover']==='') {
                    $dz=$this->deezerAlbumCover($pending[$key]['artist'],$info['album']);
                    if ($dz!=='') $info['cover']=$dz;
                }
                $resolved[$key]=$info;
                $this->writeCache($key,$info,$info['album']!==''?self::CACHE_TTL_OK:self::CACHE_TTL_EMPTY);
                curl_multi_remove_handle($mh,$ch); curl_close($ch);
            }
            curl_multi_close($mh); return $resolved;
        }

        foreach ($pending as $key=>$row) {
            $url='https://ws.audioscrobbler.com/2.0/?method=track.getinfo'
                .'&artist='.rawurlencode($row['artist']).'&track='.rawurlencode($row['track'])
                .'&api_key='.rawurlencode(LASTFM_API_KEY).'&format=json&autocorrect=1';
            $resp=$this->httpGetJson($url); $info=['album'=>'','cover'=>''];
            if ($resp['ok'] && empty($resp['data']['error'])) $info=$this->extractAlbumFromTrackInfo($resp['data']);
            // Fallback Deezer: album noto ma cover mancante.
            if ($info['album']!=='' && $info['cover']==='') {
                $dz=$this->deezerAlbumCover($row['artist'],$info['album']);
                if ($dz!=='') $info['cover']=$dz;
            }
            $resolved[$key]=$info;
            $this->writeCache($key,$info,$info['album']!==''?self::CACHE_TTL_OK:self::CACHE_TTL_EMPTY);
        }
        return $resolved;
    }

    /** @return array{album:string,cover:string} */
    private function extractAlbumFromTrackInfo(array $data):array
    {
        $albumTitle=trim((string)($data['track']['album']['title']??'')); $cover='';
        $images=$data['track']['album']['image']??[];
        if (is_array($images)) {
            for ($i=count($images)-1;$i>=0;$i--) {
                $url=trim((string)($images[$i]['#text']??''));
                if ($url!=='' && !$this->isLastFmPlaceholderCover($url)) { $cover=$url; break; }
            }
        }
        return ['album'=>$albumTitle,'cover'=>$cover];
    }

    /**
     * Candidati ALBUM ottenuti direttamente dai tag del disco corrente.
     *
     * Usa tag.getTopAlbums sui primi due tag e li riordina con il ranking
     * artistico già reso track-aware. Qui il candidato nasce come album:
     * non è più sempre "l'album più popolare dell'artista".
     *
     * @return array<int,array{name:string,album:string,cover:string,score:float}>
     */
    public function getAlbumSpecificCandidates(
        array $albumTags,
        array $rankedSimilarArtists,
        string $sourceArtist,
        int $limit = 70
    ): array {
        $tags = $this->selectUsefulAlbumTagsForAlbums($albumTags, 2);

        if (empty($tags)) {
            return [];
        }

        $sourceNorm = $this->normalizeArtistName($sourceArtist);

        $artistBonus = [];
        foreach ($rankedSimilarArtists as $rank => $item) {
            $name = trim((string)($item['name'] ?? ''));
            $norm = $this->normalizeArtistName($name);

            if ($norm !== '') {
                $artistBonus[$norm] = max(0.0, 500.0 - ($rank * 14.0));
            }
        }

        $merged = [];

        foreach ($tags as $tagIndex => $tag) {
            $tagWeight = $tagIndex === 0 ? 1.0 : 0.78;
            $rows = $this->getTopAlbumsForTag($tag, 50);

            foreach ($rows as $rank => $row) {
                $artist = trim((string)($row['name'] ?? ''));
                $album  = trim((string)($row['album'] ?? ''));

                if ($artist === '' || $album === '') {
                    continue;
                }

                $artistNorm = $this->normalizeArtistName($artist);

                // Mai suggerire un altro disco dello stesso artista sorgente:
                // "Potrebbero piacerti" deve aprire davvero la scoperta.
                if ($artistNorm === '' || $artistNorm === $sourceNorm) {
                    continue;
                }

                $key = $artistNorm . '|' . $this->normalizeArtistName($album);

                $score = (900.0 / (1.0 + ($rank * 0.12))) * $tagWeight;
                $score += $artistBonus[$artistNorm] ?? 0.0;

                if (!isset($merged[$key])) {
                    $merged[$key] = [
                        'name'  => $artist,
                        'album' => $album,
                        'cover' => (string)($row['cover'] ?? ''),
                        'score' => 0.0,
                        'hits'  => 0,
                    ];
                }

                $merged[$key]['score'] += $score;
                $merged[$key]['hits']++;

                if ($merged[$key]['cover'] === '' && !empty($row['cover'])) {
                    $merged[$key]['cover'] = (string)$row['cover'];
                }
            }
        }

        foreach ($merged as &$row) {
            if ($row['hits'] > 1) {
                $row['score'] += 150.0;
            }
        }
        unset($row);

        $out = array_values($merged);

        usort($out, function (array $a, array $b): int {
            $sa = (float)($a['score'] ?? 0);
            $sb = (float)($b['score'] ?? 0);

            if ($sa === $sb) {
                return strcmp(
                    (string)$a['name'] . '|' . (string)$a['album'],
                    (string)$b['name'] . '|' . (string)$b['album']
                );
            }

            return ($sa < $sb) ? 1 : -1;
        });

        return array_slice($out, 0, max(1, min(120, $limit)));
    }

    /**
     * Tag realmente utili per tag.getTopAlbums.
     */
    private function selectUsefulAlbumTagsForAlbums(array $tags, int $limit = 2): array
    {
        $blocked = [
            'albums i own', 'album i own', 'owned',
            'seen live', 'favorites', 'favourites',
            'favorite', 'favourite', 'spotify',
            'lastfm', 'last.fm', 'italian'
        ];

        $out = [];
        $seen = [];

        foreach ($tags as $tag) {
            $tag = $this->normalizeArtistName((string)$tag);

            if ($tag === '' || isset($seen[$tag]) || in_array($tag, $blocked, true)) {
                continue;
            }

            if (preg_match('/^(?:19|20)\d{2}$/', $tag)
                || preg_match('/^\d{2}s$/', $tag)
                || preg_match('/^(?:19|20)\d0s$/', $tag)) {
                continue;
            }

            $seen[$tag] = true;
            $out[] = $tag;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<int,array{name:string,album:string,cover:string}>
     */
    private function getTopAlbumsForTag(string $tag, int $limit = 50): array
    {
        $tag = $this->normalizeArtistName($tag);
        $limit = max(10, min(100, $limit));

        if ($tag === '' || !defined('LASTFM_API_KEY') || LASTFM_API_KEY === '') {
            return [];
        }

        $cacheKey = 'tag-albums:' . $tag . ':' . $limit;
        $cached = $this->readCache($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $url = 'https://ws.audioscrobbler.com/2.0/'
            . '?method=tag.gettopalbums'
            . '&tag=' . rawurlencode($tag)
            . '&api_key=' . rawurlencode(LASTFM_API_KEY)
            . '&format=json'
            . '&limit=' . $limit;

        $resp = $this->httpGetJson($url);

        if (!$resp['ok'] || !empty($resp['data']['error'])) {
            return [];
        }

        $rows = $resp['data']['albums']['album'] ?? [];

        if (isset($rows['name'])) {
            $rows = [$rows];
        }

        $out = [];

        foreach ((array)$rows as $row) {
            $album = trim((string)($row['name'] ?? ''));
            $artist = trim((string)($row['artist']['name'] ?? ''));

            if ($album === '' || $artist === '') {
                continue;
            }

            $cover = '';
            $images = $row['image'] ?? [];

            if (is_array($images)) {
                for ($i = count($images) - 1; $i >= 0; $i--) {
                    $url = trim((string)($images[$i]['#text'] ?? ''));

                    if ($url !== '' && !$this->isLastFmPlaceholderCover($url)) {
                        $cover = $url;
                        break;
                    }
                }
            }

            $out[] = [
                'name'  => $artist,
                'album' => $album,
                'cover' => $cover,
            ];
        }

        $this->writeCache(
            $cacheKey,
            $out,
            !empty($out) ? self::CACHE_TTL_OK : self::CACHE_TTL_EMPTY
        );

        return $out;
    }


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
            $this->writeCache($cacheKey, ['studio' => $isStudio], self::CACHE_TTL_OK);
            $done++;

            // Rate-limit MusicBrainz: ~1 richiesta/secondo per IP.
            usleep(1100000);
        }

        return $done;
    }

    /**
     * Interroga MusicBrainz per stabilire se (artista, titolo) è uno studio
     * album puro (primary-type Album, nessun secondary-type). Una sola
     * chiamata. true = confermato studio; false = non trovato o non studio.
     */
    private function musicBrainzIsStudioAlbum(string $artist, string $title): bool
    {
        $artistEsc = str_replace(['\\', '"'], ['\\\\', '\\"'], $artist);
        $titleEsc  = str_replace(['\\', '"'], ['\\\\', '\\"'], $title);
        $query = '(releasegroup:"' . $titleEsc . '" AND artist:"' . $artistEsc . '")';

        $url = 'https://musicbrainz.org/ws/2/release-group/'
            . '?query=' . rawurlencode($query)
            . '&fmt=json&limit=25';

        $resp = $this->httpGetJson($url);
        if (!$resp['ok'] || !empty($resp['data']['error'])) {
            return false;
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

    /**
     * Suggerimenti di SCOPERTA: artisti simili che NON sono in archivio.
     * Per ognuno recupera un album rappresentativo (il piu' ascoltato su
     * Last.fm) con la sua cover. Usato per riempire il blocco "consigliati"
     * quando l'incrocio con la collezione locale non basta — tipico di una
     * collezione di nicchia con poche sovrapposizioni interne.
     *
     * $similar        lista gia' ottenuta da getSimilarArtists() (per non
     *                 richiamare Last.fm una seconda volta).
     * $excludeNames   nomi (grezzi) da saltare: gli artisti gia' mostrati
     *                 dalla sezione "in archivio". Confronto normalizzato.
     * $sourceArtist   artista dell'album corrente: entra nella chiave cache
     *                 così album di artisti diversi non la condividono.
     * $sourceAlbum    titolo dell'album corrente: rende la cache specifica
     *                 per disco (Il Vile ≠ Catartica).
     * $limit          quanti suggerimenti esterni al massimo (ognuno costa
     *                 una chiamata Last.fm getTopAlbums, quindi si tiene basso).
     *
     * @param array<int,array{name:string,match:float}> $similar
     * @param array<int,string> $excludeNames
     * @return array<int,array{name:string,album:string,cover:string,match:float}>
     */
    public function getExternalSuggestions(
        array $similar,
        array $excludeNames,
        string $sourceArtist = '',
        string $sourceAlbum = '',
        int $limit = 5
    ): array {
        if (empty($similar) || !defined('LASTFM_API_KEY') || LASTFM_API_KEY === '') {
            return [];
        }

        $limit = max(1, min(8, $limit));

        // Insieme dei nomi da escludere (normalizzati) per confronto O(1).
        $skip = [];
        foreach ($excludeNames as $n) {
            $norm = $this->normalizeArtistName((string) $n);
            if ($norm !== '') {
                $skip[$norm] = true;
            }
        }

        // Chiave cache basata su ARTISTA + ALBUM sorgente (oltre a esclusi
        // e limite): così album diversi non condividono mai la cache, e
        // nemmeno sorgenti diverse con condizioni casualmente simili. Se
        // artista/album non sono passati si ripiega sul primo simile, ma è
        // un fallback: il chiamante dovrebbe sempre passarli.
        $keySource = $this->normalizeArtistName($sourceArtist) !== ''
            ? $this->normalizeArtistName($sourceArtist) . '|' . $this->normalizeArtistName($sourceAlbum)
            : $this->normalizeArtistName((string) ($similar[0]['name'] ?? ''));

        $cacheKey = 'ext:v2:' . $keySource
            . ':' . substr(sha1(implode('|', array_keys($skip))), 0, 8)
            . ':' . $limit;

        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $out = [];
        foreach ($similar as $item) {
            if (count($out) >= $limit) {
                break;
            }
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (isset($skip[$this->normalizeArtistName($name)])) {
                continue; // gia' in archivio: mostrato dall'altra sezione
            }

            $top = $this->lastFmTopAlbum($name);

            if (trim((string)$top['album']) === '') {
                continue;
            }

            $out[] = [
                'name'  => $name,
                'album' => $top['album'],
                'cover' => $top['cover'],
                'match' => (float) ($item['match'] ?? 0),
            ];
        }

        // Cache anche il risultato vuoto (TTL breve) per non ritentare N
        // getTopAlbums a ogni pageview quando non c'e' nulla da mostrare.
        $this->writeCache(
            $cacheKey,
            $out,
            !empty($out) ? self::CACHE_TTL_OK : self::CACHE_TTL_EMPTY
        );

        return $out;
    }

    /**
     * Album piu' ascoltato di un artista su Last.fm + cover. La cover
     * Last.fm e' spesso una placeholder (stella grigia): quando manca o
     * e' quella nota si tenta Deezer (match esatto artista+titolo).
     *
     * @return array{album:string,cover:string}
     */
    private function lastFmTopAlbum(string $artistName): array
    {
        $out = ['album' => '', 'cover' => ''];

        if (!defined('LASTFM_API_KEY') || LASTFM_API_KEY === '') {
            return $out;
        }

        $url = 'https://ws.audioscrobbler.com/2.0/'
            . '?method=artist.gettopalbums'
            . '&artist=' . rawurlencode($artistName)
            . '&api_key=' . rawurlencode(LASTFM_API_KEY)
            . '&format=json&autocorrect=1&limit=1';

        $resp = $this->httpGetJson($url);
        if (!$resp['ok'] || !empty($resp['data']['error'])) {
            return $out;
        }

        $album = $resp['data']['topalbums']['album'][0] ?? null;
        if (!is_array($album)) {
            return $out;
        }

        $out['album'] = trim((string) ($album['name'] ?? ''));

        // Cover Last.fm: array di taglie, l'ultima e' la piu' grande.
        $images = $album['image'] ?? [];
        if (is_array($images)) {
            for ($i = count($images) - 1; $i >= 0; $i--) {
                $u = trim((string) ($images[$i]['#text'] ?? ''));
                if ($u !== '' && !$this->isLastFmPlaceholderCover($u)) {
                    $out['cover'] = $u;
                    break;
                }
            }
        }

        // Fallback Deezer se Last.fm non ha dato una cover utile.
        if ($out['cover'] === '' && $out['album'] !== '') {
            $dz = $this->deezerAlbumCover($artistName, $out['album']);
            if ($dz !== '') {
                $out['cover'] = $dz;
            }
        }

        return $out;
    }

    /**
     * Vero se l'URL e' la nota cover placeholder di Last.fm (stella grigia
     * "no image"), riconoscibile dall'md5 ricorrente nel path.
     */
    private function isLastFmPlaceholderCover(string $url): bool
    {
        return strpos($url, '2a96cbd8b46e442fc41c2b86b821562f') !== false;
    }

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

    /**
     * @return array{ok:bool,data:array}
     */
    private function httpGetJson(string $url): array
    {
        $ua = defined('APP_USER_AGENT')
            ? APP_USER_AGENT
            : 'GrizzlyMusicArchive/1.0';

        // cURL preferito: più robusto sul tuo stack MAMP/PHP 7.4.
        if (function_exists('curl_init')) {
            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_USERAGENT => $ua,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);

            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);

            curl_close($ch);

            if ($body === false || $err !== '' || $code < 200 || $code >= 300) {
                return ['ok' => false, 'data' => []];
            }

            $decoded = json_decode($body, true);

            if (!is_array($decoded)) {
                return ['ok' => false, 'data' => []];
            }

            return ['ok' => true, 'data' => $decoded];
        }

        // Fallback se cURL non è disponibile.
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: {$ua}\r\nAccept: application/json\r\n",
                'timeout' => 6,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return ['ok' => false, 'data' => []];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded)
            ? ['ok' => true, 'data' => $decoded]
            : ['ok' => false, 'data' => []];
    }
}

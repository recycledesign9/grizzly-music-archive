<?php

/**
 * InstantMixService
 *
 * Genera un mix al volo a partire da criteri semplici (generi, intervallo
 * di anni, numero di tracce, ordine). Il mix non viene salvato: è una lista
 * di tracce che il player riceve nello stesso formato delle playlist.
 *
 * Regole di selezione:
 * - entrano solo tracce con un file audio associato (audio_files.track_id)
 *   e il cui file risulta raggiungibile al momento della generazione;
 * - le tracce sotto MIN_DURATION secondi sono escluse (intro, intermezzi);
 *   le tracce senza durata restano ammesse;
 * - al massimo MAX_LONG_TRACKS tracce oltre LONG_TRACK_SEC per mix;
 * - varietà con un round-robin per artista: a ogni giro al massimo una
 *   traccia per artista, ogni volta da un album diverso;
 * - se le tracce non bastano si allentano i limiti di varietà, mai i filtri.
 *
 * La casualità è guidata da un seed esadecimale: stessi criteri, stesso
 * seed e stesso archivio producono lo stesso mix.
 *
 * Compatibile con PHP 7.4 e MySQL 5.7 (nessuna window function).
 *
 * Posizionare in: app/services/InstantMixService.php
 */
class InstantMixService
{
    const MIN_DURATION_SEC = 45;
    const LONG_TRACK_SEC   = 720;
    const MAX_LONG_TRACKS  = 1;
    const DEFAULT_TRACKS   = 20;
    const MAX_TRACKS       = 100;
    const MAX_GENRES       = 12;
    const YEAR_MIN         = 1900;

    /** Numeri di tracce proposti nell'interfaccia */
    const TRACK_PRESETS = [20, 30, 50, 100];

    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ----------------------------------------------------------
    // Criteri
    // ----------------------------------------------------------

    /**
     * Normalizza i criteri ricevuti dalla request.
     *
     * Parametri accettati:
     *   g      id generi, "1,5,9" oppure array
     *   from   anno iniziale
     *   to     anno finale
     *   n      numero tracce
     *   order  random | chrono
     *   seed   esadecimale 4-12 caratteri (se assente viene creato)
     */
    public function normalizeCriteria(array $input, bool $withSeed = true): array
    {
        $rawGenres = $input['g'] ?? [];
        if (!is_array($rawGenres)) {
            $rawGenres = explode(',', (string)$rawGenres);
        }
        $genres = [];
        foreach ($rawGenres as $g) {
            $g = (int)$g;
            if ($g > 0 && !in_array($g, $genres, true)) {
                $genres[] = $g;
            }
            if (count($genres) >= self::MAX_GENRES) {
                break;
            }
        }
        sort($genres);

        $maxYear = (int)date('Y') + 1;
        $from = $this->yearOrNull($input['from'] ?? null, $maxYear);
        $to   = $this->yearOrNull($input['to'] ?? null, $maxYear);
        if ($from !== null && $to !== null && $from > $to) {
            $tmp  = $from;
            $from = $to;
            $to   = $tmp;
        }

        $n = (int)($input['n'] ?? self::DEFAULT_TRACKS);
        if ($n <= 0) {
            $n = self::DEFAULT_TRACKS;
        }
        $n = min($n, self::MAX_TRACKS);

        $order = (string)($input['order'] ?? 'random');
        if ($order !== 'chrono') {
            $order = 'random';
        }

        $criteria = [
            'g'     => $genres,
            'from'  => $from,
            'to'    => $to,
            'n'     => $n,
            'order' => $order,
        ];

        if ($withSeed) {
            $seed = strtolower(trim((string)($input['seed'] ?? '')));
            if (!preg_match('/^[0-9a-f]{4,12}$/', $seed)) {
                $seed = bin2hex(random_bytes(3));
            }
            $criteria['seed'] = $seed;
        }

        return $criteria;
    }

    private function yearOrNull($value, int $maxYear): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $y = (int)$value;
        if ($y < self::YEAR_MIN || $y > $maxYear) {
            return null;
        }
        return $y;
    }

    /**
     * Nome leggibile del mix, usato come titolo e come nome proposto
     * per il salvataggio in playlist.
     */
    public function label(array $criteria): string
    {
        $parts = [];

        if (!empty($criteria['g'])) {
            $names = $this->genreNames($criteria['g']);
            if (!empty($names)) {
                $parts[] = implode(', ', $names);
            }
        }

        $years = $this->yearsLabel($criteria['from'] ?? null, $criteria['to'] ?? null);
        if ($years !== '') {
            $parts[] = $years;
        }

        if (empty($parts)) {
            return 'Tutto l\'archivio';
        }

        return implode(' · ', $parts);
    }

    private function yearsLabel(?int $from, ?int $to): string
    {
        if ($from === null && $to === null) {
            return '';
        }
        if ($from !== null && $to !== null) {
            if ($from === $to) {
                return (string)$from;
            }
            // Decennio esatto: "anni '90", "anni 2000"
            if ($from % 10 === 0 && $to === $from + 9) {
                return $from >= 2000 ? 'anni ' . $from : "anni '" . substr((string)$from, 2);
            }
            return $from . '–' . $to;
        }
        if ($from !== null) {
            return 'dal ' . $from;
        }
        return 'fino al ' . $to;
    }

    // ----------------------------------------------------------
    // Dati per i filtri
    // ----------------------------------------------------------

    /**
     * Generi con almeno una traccia ascoltabile, con il relativo conteggio.
     */
    public function availableGenres(): array
    {
        $sql = "
            SELECT g.id, g.name, COUNT(DISTINCT t.id) AS tracks
            FROM genres g
            JOIN albums a       ON a.genre_id  = g.id
            JOIN tracks t       ON t.album_id  = a.id
            JOIN audio_files af ON af.track_id = t.id
            WHERE (t.duration_sec IS NULL OR t.duration_sec >= :min_dur)
            GROUP BY g.id, g.name
            ORDER BY g.name ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':min_dur' => self::MIN_DURATION_SEC]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id'     => (int)$row['id'],
                'name'   => (string)$row['name'],
                'tracks' => (int)$row['tracks'],
            ];
        }
        return $out;
    }

    /**
     * Decenni con almeno una traccia ascoltabile (per i chip rapidi).
     */
    public function availableDecades(): array
    {
        $sql = "
            SELECT FLOOR(a.year / 10) * 10 AS decade, COUNT(DISTINCT t.id) AS tracks
            FROM albums a
            JOIN tracks t       ON t.album_id  = a.id
            JOIN audio_files af ON af.track_id = t.id
            WHERE a.year IS NOT NULL
              AND (t.duration_sec IS NULL OR t.duration_sec >= :min_dur)
            GROUP BY decade
            ORDER BY decade ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':min_dur' => self::MIN_DURATION_SEC]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decade = (int)$row['decade'];
            if ($decade < self::YEAR_MIN) {
                continue;
            }
            $out[] = [
                'decade' => $decade,
                'label'  => $decade >= 2000 ? (string)$decade : "'" . substr((string)$decade, 2),
                'tracks' => (int)$row['tracks'],
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------
    // Conteggi
    // ----------------------------------------------------------

    /**
     * playable: tracce che possono entrare nel mix (audio associato,
     *           durata ammessa). Non verifica l'esistenza dei file.
     * catalog:  tracce a catalogo che rispettano genere e anni, con o
     *           senza audio.
     */
    public function count(array $criteria): array
    {
        $params = [];
        $where  = $this->filterWhere($criteria, $params);

        $sqlPlayable = "
            SELECT COUNT(*)
            FROM tracks t
            JOIN albums a ON a.id = t.album_id
            JOIN (
                SELECT DISTINCT track_id FROM audio_files WHERE track_id IS NOT NULL
            ) af ON af.track_id = t.id
            WHERE {$where}
              AND (t.duration_sec IS NULL OR t.duration_sec >= :min_dur)
        ";
        $stmt = $this->db->prepare($sqlPlayable);
        $stmt->execute($params + [':min_dur' => self::MIN_DURATION_SEC]);
        $playable = (int)$stmt->fetchColumn();

        $sqlCatalog = "
            SELECT COUNT(*)
            FROM tracks t
            JOIN albums a ON a.id = t.album_id
            WHERE {$where}
        ";
        $stmt = $this->db->prepare($sqlCatalog);
        $stmt->execute($params);
        $catalog = (int)$stmt->fetchColumn();

        return [
            'playable' => $playable,
            'catalog'  => $catalog,
        ];
    }

    // ----------------------------------------------------------
    // Generazione
    // ----------------------------------------------------------

    public function generate(array $criteria): array
    {
        $n = (int)$criteria['n'];

        $candidates = $this->fetchCandidates($criteria);
        $counts     = $this->count($criteria);

        mt_srand((int)hexdec(substr((string)$criteria['seed'], 0, 7)));

        $selection = $this->selectWithVariety($candidates, $n);
        $picked    = $selection['picked'];

        if ($criteria['order'] === 'chrono') {
            usort($picked, function (array $a, array $b): int {
                $ya = $a['year'] === null ? 9999 : (int)$a['year'];
                $yb = $b['year'] === null ? 9999 : (int)$b['year'];
                if ($ya !== $yb) {
                    return $ya <=> $yb;
                }
                if ($a['album_id'] !== $b['album_id']) {
                    return $a['album_id'] <=> $b['album_id'];
                }
                return $a['position'] <=> $b['position'];
            });
        } else {
            $this->shuffle($picked);
            $picked = $this->spaceArtists($picked, 3);
        }

        $tracks = $this->buildTracks($picked);

        $artists  = [];
        $albums   = [];
        $duration = 0;
        foreach ($tracks as $t) {
            $artists[$t['artist_id']] = true;
            $albums[$t['albumId']]    = true;
            $duration += (int)($t['duration'] ?? 0);
        }

        $stats = [
            'requested'    => $n,
            'selected'     => count($tracks),
            'artists'      => count($artists),
            'albums'       => count($albums),
            'duration_sec' => $duration,
            'playable'     => $counts['playable'],
            'catalog'      => $counts['catalog'],
            'relaxed'      => $selection['relaxed'],
            'missing'      => $selection['missing'],
        ];

        return [
            'criteria'     => $criteria,
            'label'        => $this->label($criteria),
            'tracks'       => $tracks,
            'stats'        => $stats,
            'alternatives' => count($tracks) < $n ? $this->alternatives($criteria, $counts['playable']) : [],
        ];
    }

    /**
     * Candidati con un solo file audio per traccia (il più vecchio):
     * un eventuale doppione in audio_files non duplica la traccia.
     */
    private function fetchCandidates(array $criteria): array
    {
        $params = [];
        $where  = $this->filterWhere($criteria, $params);

        $sql = "
            SELECT
                t.id           AS track_id,
                t.album_id,
                t.position,
                t.duration_sec,
                a.artist_id,
                a.year,
                af.filename,
                af.storage_type,
                af.source_path
            FROM tracks t
            JOIN albums a ON a.id = t.album_id
            JOIN (
                SELECT track_id, MIN(id) AS af_id
                FROM audio_files
                WHERE track_id IS NOT NULL
                GROUP BY track_id
            ) pick ON pick.track_id = t.id
            JOIN audio_files af ON af.id = pick.af_id
            WHERE {$where}
              AND (t.duration_sec IS NULL OR t.duration_sec >= :min_dur)
            ORDER BY t.id ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params + [':min_dur' => self::MIN_DURATION_SEC]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'track_id'     => (int)$row['track_id'],
                'album_id'     => (int)$row['album_id'],
                'artist_id'    => (int)$row['artist_id'],
                'position'     => (int)$row['position'],
                'year'         => $row['year'] !== null ? (int)$row['year'] : null,
                'duration'     => $row['duration_sec'] !== null ? (int)$row['duration_sec'] : null,
                'filename'     => (string)$row['filename'],
                'storage_type' => (string)$row['storage_type'],
                'source_path'  => $row['source_path'],
            ];
        }
        return $out;
    }

    /**
     * Condizione WHERE condivisa da conteggi e candidati.
     * Ogni valore ha un parametro nominato distinto (emulate prepares off).
     */
    private function filterWhere(array $criteria, array &$params): string
    {
        $conds = ['1 = 1'];

        if (!empty($criteria['g'])) {
            $names = [];
            foreach (array_values($criteria['g']) as $i => $gid) {
                $key = ':genre_' . $i;
                $names[] = $key;
                $params[$key] = (int)$gid;
            }
            $conds[] = 'a.genre_id IN (' . implode(', ', $names) . ')';
        }

        if (($criteria['from'] ?? null) !== null) {
            $conds[] = 'a.year >= :year_from';
            $params[':year_from'] = (int)$criteria['from'];
        }
        if (($criteria['to'] ?? null) !== null) {
            $conds[] = 'a.year <= :year_to';
            $params[':year_to'] = (int)$criteria['to'];
        }

        return implode(' AND ', $conds);
    }

    /**
     * Round-robin per artista con allentamento progressivo.
     *
     * Livello 0: 1 traccia per album, max(2, ceil(n/10)) per artista.
     * Livello 1: 2 tracce per album, limite artista raddoppiato.
     * Livello 2: nessun limite di varietà.
     *
     * Il file di ogni traccia scelta viene verificato subito; se manca,
     * la traccia viene scartata e il giro prosegue con il candidato dopo.
     */
    private function selectWithVariety(array $candidates, int $n): array
    {
        // artista → album → tracce, tutto in ordine casuale
        $byArtist = [];
        foreach ($candidates as $c) {
            $byArtist[$c['artist_id']][$c['album_id']][] = $c;
        }

        $artistIds = array_keys($byArtist);
        $this->shuffle($artistIds);

        $pool = [];
        foreach ($artistIds as $aid) {
            $albumIds = array_keys($byArtist[$aid]);
            $this->shuffle($albumIds);
            $albums = [];
            foreach ($albumIds as $alb) {
                $tracks = $byArtist[$aid][$alb];
                $this->shuffle($tracks);
                $albums[] = ['id' => $alb, 'tracks' => $tracks, 'used' => 0];
            }
            $pool[] = ['id' => $aid, 'albums' => $albums, 'next' => 0, 'used' => 0];
        }

        $picked    = [];
        $longUsed  = 0;
        $missing   = 0;
        $relaxed   = 0;
        $basePerArtist = max(2, (int)ceil($n / 10));

        $levels = [
            [1, $basePerArtist],
            [2, $basePerArtist * 2],
            [PHP_INT_MAX, PHP_INT_MAX],
        ];

        foreach ($levels as $levelIndex => $limits) {
            if (count($picked) >= $n) {
                break;
            }
            $perAlbum  = $limits[0];
            $perArtist = $limits[1];
            $addedAtLevel = 0;

            while (count($picked) < $n) {
                $addedThisRound = 0;

                foreach ($pool as &$artist) {
                    if (count($picked) >= $n) {
                        break;
                    }
                    if ($artist['used'] >= $perArtist) {
                        continue;
                    }

                    $track = $this->takeFromArtist($artist, $perAlbum, $longUsed, $missing);
                    if ($track === null) {
                        continue;
                    }

                    $picked[] = $track;
                    $artist['used']++;
                    $addedThisRound++;
                    if ($track['duration'] !== null && $track['duration'] > self::LONG_TRACK_SEC) {
                        $longUsed++;
                    }
                }
                unset($artist);

                if ($addedThisRound === 0) {
                    break;
                }
                $addedAtLevel += $addedThisRound;
            }

            if ($levelIndex > 0 && $addedAtLevel > 0) {
                $relaxed = $levelIndex;
            }
        }

        return [
            'picked'  => $picked,
            'relaxed' => $relaxed,
            'missing' => $missing,
        ];
    }

    /**
     * Prende la prossima traccia valida da un artista, ruotando sugli
     * album. Restituisce null se l'artista non ha più nulla da offrire
     * con i limiti correnti.
     */
    private function takeFromArtist(array &$artist, int $perAlbum, int $longUsed, int &$missing): ?array
    {
        $albumCount = count($artist['albums']);

        for ($step = 0; $step < $albumCount; $step++) {
            $idx = ($artist['next'] + $step) % $albumCount;
            $album = &$artist['albums'][$idx];

            if ($album['used'] >= $perAlbum || empty($album['tracks'])) {
                unset($album);
                continue;
            }

            foreach ($album['tracks'] as $k => $t) {
                $isLong = $t['duration'] !== null && $t['duration'] > self::LONG_TRACK_SEC;
                if ($isLong && $longUsed >= self::MAX_LONG_TRACKS) {
                    continue;
                }

                // Tolta dal pool in ogni caso: scelta oppure non raggiungibile
                unset($album['tracks'][$k]);

                if (MediaPathResolver::resolveAudioFile($t) === null) {
                    $missing++;
                    continue;
                }

                $album['used']++;
                $artist['next'] = ($idx + 1) % $albumCount;
                unset($album);
                return $t;
            }

            unset($album);
        }

        return null;
    }

    /**
     * Distanzia i brani dello stesso artista: ogni traccia viene scelta tra
     * le rimanenti evitando gli artisti delle ultime ($gap - 1) posizioni.
     * Se non è possibile (pochi artisti) prende la prima disponibile.
     */
    private function spaceArtists(array $tracks, int $gap): array
    {
        $out    = [];
        $recent = [];

        while (!empty($tracks)) {
            $chosenKey = null;
            foreach ($tracks as $k => $t) {
                if (!in_array($t['artist_id'], $recent, true)) {
                    $chosenKey = $k;
                    break;
                }
            }
            if ($chosenKey === null) {
                $chosenKey = array_key_first($tracks);
            }

            $t = $tracks[$chosenKey];
            unset($tracks[$chosenKey]);
            $out[] = $t;

            $recent[] = $t['artist_id'];
            if (count($recent) > $gap - 1) {
                array_shift($recent);
            }
        }

        return $out;
    }

    /**
     * Fisher-Yates con mt_rand: deterministico dato il seed.
     */
    private function shuffle(array &$items): void
    {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            if ($j !== $i) {
                $tmp = $items[$i];
                $items[$i] = $items[$j];
                $items[$j] = $tmp;
            }
        }
    }

    /**
     * Arricchisce le tracce scelte con titolo, artista, album e cover,
     * nel formato che Player.load() accetta (come playlists/api-tracks).
     */
    private function buildTracks(array $picked): array
    {
        if (empty($picked)) {
            return [];
        }

        $names  = [];
        $params = [];
        foreach (array_values($picked) as $i => $p) {
            $key = ':t_' . $i;
            $names[] = $key;
            $params[$key] = $p['track_id'];
        }

        $sql = "
            SELECT
                t.id, t.title, t.position, t.duration_sec, t.youtube_id,
                a.id AS album_id, a.title AS album_title, a.year,
                a.cover_local, a.cover_url,
                ar.id AS artist_id, ar.name AS artist_name
            FROM tracks t
            JOIN albums a   ON a.id  = t.album_id
            JOIN artists ar ON ar.id = a.artist_id
            WHERE t.id IN (" . implode(', ', $names) . ")
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $info = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $info[(int)$row['id']] = $row;
        }

        $out = [];
        $pos = 0;
        foreach ($picked as $p) {
            $row = $info[$p['track_id']] ?? null;
            if ($row === null) {
                continue;
            }
            $pos++;
            $out[] = [
                'id'         => (int)$row['id'],
                'title'      => (string)$row['title'],
                // Posizione nel mix, come nelle playlist (il player la
                // mostra davanti al titolo)
                'position'   => $pos,
                'src'        => MediaPathResolver::getStreamUrl($p['filename']),
                'cover'      => $this->coverSrc($row['cover_local'], $row['cover_url']),
                'artist'     => (string)$row['artist_name'],
                'artist_id'  => (int)$row['artist_id'],
                'albumId'    => (int)$row['album_id'],
                'album_id'   => (int)$row['album_id'],
                'album'      => (string)$row['album_title'],
                'year'       => $row['year'] !== null ? (int)$row['year'] : null,
                'duration'   => $row['duration_sec'] !== null ? (int)$row['duration_sec'] : null,
                'youtube_id' => $row['youtube_id'] ?: null,
            ];
        }

        return $out;
    }

    /**
     * Alternative concrete quando il mix resta sotto il numero richiesto.
     * Ogni proposta è applicabile con un clic e riporta quante tracce
     * ascoltabili darebbe. Si propone solo ciò che amplia il risultato.
     */
    private function alternatives(array $criteria, int $currentPlayable): array
    {
        $base = [
            'g'     => $criteria['g'],
            'from'  => $criteria['from'],
            'to'    => $criteria['to'],
            'n'     => $criteria['n'],
            'order' => $criteria['order'],
        ];

        $proposals = [];
        $hasYears  = $criteria['from'] !== null || $criteria['to'] !== null;

        if ($hasYears) {
            $wide = $base;
            $maxYear = (int)date('Y') + 1;
            if ($wide['from'] !== null) {
                $wide['from'] = max(self::YEAR_MIN, $wide['from'] - 5);
            }
            if ($wide['to'] !== null) {
                $wide['to'] = min($maxYear, $wide['to'] + 5);
            }
            $proposals[] = $wide;
        }

        if (!empty($criteria['g']) && $hasYears) {
            $noGenre = $base;
            $noGenre['g'] = [];
            $proposals[] = $noGenre;

            $noYears = $base;
            $noYears['from'] = null;
            $noYears['to'] = null;
            $proposals[] = $noYears;
        }

        $out = [];
        foreach ($proposals as $p) {
            $c = $this->count($p);
            if ($c['playable'] <= $currentPlayable) {
                continue;
            }
            $out[] = [
                'label'    => $this->label($p),
                'playable' => $c['playable'],
                'criteria' => $p,
            ];
        }

        return $out;
    }

    // ----------------------------------------------------------
    // Utility
    // ----------------------------------------------------------

    private function genreNames(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $names  = [];
        $params = [];
        foreach (array_values($ids) as $i => $gid) {
            $key = ':gn_' . $i;
            $names[] = $key;
            $params[$key] = (int)$gid;
        }
        $stmt = $this->db->prepare(
            'SELECT name FROM genres WHERE id IN (' . implode(', ', $names) . ') ORDER BY name ASC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Stessa priorità di PlaylistController::coverSrc(), con placeholder. */
    private function coverSrc(?string $local, ?string $url): string
    {
        if (!empty($local)) {
            return BASE_URL . '/public/uploads/' . $local;
        }
        if (!empty($url)) {
            return strpos($url, 'http') === 0 ? $url : BASE_URL . '/public/uploads/' . $url;
        }
        return BASE_URL . '/public/img/placeholder.png';
    }
}


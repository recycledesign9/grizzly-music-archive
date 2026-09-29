<?php

/**
 * DashboardService
 *
 * Dati aggregati per la Panoramica. Tutte le informazioni derivano dal
 * database locale: nessuna chiamata a servizi esterni.
 * Le query sono aggregazioni su tabelle già indicizzate (album, tracce,
 * generi, etichette, playlist) e restano leggere anche con archivi ampi.
 *
 * Compatibile PHP 7.4 e MySQL 5.7.
 */
class DashboardService
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Durata complessiva delle tracce catalogate, in secondi.
     * Le tracce senza durata nota non contribuiscono.
     */
    public function totalDurationSeconds(): int
    {
        $row = $this->db->query("SELECT COALESCE(SUM(duration_sec), 0) AS s FROM tracks")->fetch(PDO::FETCH_ASSOC);
        return (int)($row['s'] ?? 0);
    }

    /**
     * Album aggiunti in ciascuno degli ultimi $months mesi, dal più
     * vecchio al mese corrente. I mesi senza arrivi valgono 0.
     *
     * @return array<int, array{ym:string, label:string, count:int}>
     */
    public function monthlyArrivals(int $months = 12): array
    {
        $months = max(1, $months);
        $start  = new DateTime('first day of this month 00:00:00');
        $start->modify('-' . ($months - 1) . ' months');

        $stmt = $this->db->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
            FROM albums
            WHERE created_at >= :from
            GROUP BY ym
        ");
        $stmt->execute([':from' => $start->format('Y-m-d H:i:s')]);
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $counts[$r['ym']] = (int)$r['c'];
        }

        $names = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
        $out   = [];
        $cur   = clone $start;
        for ($i = 0; $i < $months; $i++) {
            $ym    = $cur->format('Y-m');
            $out[] = [
                'ym'    => $ym,
                'label' => $names[(int)$cur->format('n') - 1] . ' ' . $cur->format('Y'),
                'count' => $counts[$ym] ?? 0,
            ];
            $cur->modify('+1 month');
        }
        return $out;
    }

    /**
     * Generi ordinati per numero di dischi.
     *
     * @return array<int, array{id:int, name:string, count:int}>
     */
    public function topGenres(int $limit = 16): array
    {
        $stmt = $this->db->prepare("
            SELECT g.id, g.name, COUNT(*) AS c
            FROM albums a
            JOIN genres g ON g.id = a.genre_id
            GROUP BY g.id, g.name
            ORDER BY c DESC, g.name ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(function (array $r): array {
            return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'count' => (int)$r['c']];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Numero totale di generi con almeno un disco. */
    public function genreCount(): int
    {
        $row = $this->db->query("SELECT COUNT(DISTINCT genre_id) AS c FROM albums WHERE genre_id IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    }

    /**
     * Distribuzione per decennio di uscita (solo album con anno noto).
     *
     * @return array<int, array{decade:int, count:int}>
     */
    public function decades(): array
    {
        $rows = $this->db->query("
            SELECT FLOOR(year / 10) * 10 AS decade, COUNT(*) AS c
            FROM albums
            WHERE year IS NOT NULL AND year > 0
            GROUP BY decade
            ORDER BY decade ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function (array $r): array {
            return ['decade' => (int)$r['decade'], 'count' => (int)$r['c']];
        }, $rows);
    }

    /**
     * Etichette ordinate per numero di dischi.
     *
     * @return array<int, array{id:int, name:string, count:int}>
     */
    public function topLabels(int $limit = 5): array
    {
        $stmt = $this->db->prepare("
            SELECT l.id, l.name, COUNT(*) AS c
            FROM albums a
            JOIN labels l ON l.id = a.label_id
            GROUP BY l.id, l.name
            ORDER BY c DESC, l.name ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(function (array $r): array {
            return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'count' => (int)$r['c']];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Playlist con conteggi e fino a quattro copertine per il mosaico.
     * Ordine: ultime modificate, poi ultime create.
     * I conteggi usano DISTINCT: una traccia con più file audio conta una
     * volta sola.
     *
     * @return array<int, array{id:int, name:string, total_tracks:int, playable_tracks:int, covers:string[]}>
     */
    public function playlists(int $limit = 12): array
    {
        $stmt = $this->db->prepare("
            SELECT
                p.id,
                p.name,
                COUNT(DISTINCT pt.id) AS total_tracks,
                COUNT(DISTINCT CASE WHEN af.id IS NOT NULL THEN pt.id END) AS playable_tracks
            FROM playlists p
            LEFT JOIN playlist_tracks pt ON pt.playlist_id = p.id
            LEFT JOIN audio_files af     ON af.track_id    = pt.track_id
            GROUP BY p.id, p.name, p.updated_at, p.created_at
            ORDER BY p.updated_at DESC, p.created_at DESC, p.id DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return [];
        }

        $ids = array_map(function (array $r): int { return (int)$r['id']; }, $rows);
        $in  = implode(',', array_fill(0, count($ids), '?'));

        // Copertine: un album per volta, nell'ordine in cui compare per la
        // prima volta nella playlist; solo album con una copertina.
        $cstmt = $this->db->prepare("
            SELECT pt.playlist_id, al.id AS album_id, al.cover_local, al.cover_url,
                   MIN(pt.position) AS first_pos
            FROM playlist_tracks pt
            JOIN tracks t  ON t.id  = pt.track_id
            JOIN albums al ON al.id = t.album_id
            WHERE pt.playlist_id IN ($in)
              AND (al.cover_local IS NOT NULL OR al.cover_url IS NOT NULL)
            GROUP BY pt.playlist_id, al.id, al.cover_local, al.cover_url
            ORDER BY pt.playlist_id, first_pos
        ");
        $cstmt->execute($ids);

        $covers = [];
        foreach ($cstmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $pid = (int)$c['playlist_id'];
            if (isset($covers[$pid]) && count($covers[$pid]) >= 4) {
                continue;
            }
            $src = self::coverUrl($c['cover_local'] ?? null, $c['cover_url'] ?? null);
            if ($src !== '') {
                $covers[$pid][] = $src;
            }
        }

        return array_map(function (array $r) use ($covers): array {
            $id = (int)$r['id'];
            return [
                'id'              => $id,
                'name'            => (string)$r['name'],
                'total_tracks'    => (int)$r['total_tracks'],
                'playable_tracks' => (int)$r['playable_tracks'],
                'covers'          => $covers[$id] ?? [],
            ];
        }, $rows);
    }

    /**
     * Disco del giorno: una scelta stabile per tutta la giornata e diversa
     * il giorno dopo. Il seme è la data, quindi ricaricare la pagina non
     * cambia il disco. Si escludono i dischi arrivati negli ultimi 60
     * giorni (sono già nei Nuovi arrivi) e si privilegiano quelli con una
     * copertina; se non restano candidati si usa l'intero archivio.
     *
     * @return array<string, mixed>|null
     */
    public function albumOfTheDay(?string $date = null): ?array
    {
        $seed = $date ?? date('Y-m-d');
        $sql  = "
            SELECT a.id, a.title, a.year, a.cover_local, a.cover_url,
                   a.`condition` AS cond, a.copies, a.notes, a.created_at,
                   ar.id AS artist_id, ar.name AS artist_name,
                   (SELECT COUNT(*) FROM tracks t WHERE t.album_id = a.id) AS track_count,
                   (SELECT GROUP_CONCAT(f.name ORDER BY f.id SEPARATOR '|')
                      FROM album_formats af
                      JOIN formats f ON f.id = af.format_id
                     WHERE af.album_id = a.id) AS formats_raw
            FROM albums a
            JOIN artists ar ON ar.id = a.artist_id
            %s
            ORDER BY (a.cover_local IS NULL AND a.cover_url IS NULL) ASC,
                     CRC32(CONCAT(a.id, '|', :seed)) ASC
            LIMIT 1
        ";

        $stmt = $this->db->prepare(sprintf($sql, 'WHERE a.created_at < (NOW() - INTERVAL 60 DAY)'));
        $stmt->execute([':seed' => $seed]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $stmt = $this->db->prepare(sprintf($sql, ''));
            $stmt->execute([':seed' => $seed]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$row) {
            return null;
        }

        $row['formats'] = $row['formats_raw'] !== null && $row['formats_raw'] !== ''
            ? explode('|', (string)$row['formats_raw'])
            : [];
        unset($row['formats_raw']);
        return $row;
    }

    /**
     * Dischi che quest'anno compiono un anniversario tondo dall'uscita
     * (10, 20, 25, 30, 40, 50, 60, 70 anni). Prima gli anniversari più
     * lunghi, poi in ordine di titolo.
     *
     * @return array<int, array{id:int, title:string, artist_name:string, year:int, age:int}>
     */
    public function anniversaries(int $limit = 6, ?int $currentYear = null): array
    {
        $y     = $currentYear ?? (int)date('Y');
        $ages  = [70, 60, 50, 40, 30, 25, 20, 10];
        $years = array_map(function (int $a) use ($y): int { return $y - $a; }, $ages);
        $in    = implode(',', array_fill(0, count($years), '?'));

        $stmt = $this->db->prepare("
            SELECT a.id, a.title, a.year, ar.name AS artist_name
            FROM albums a
            JOIN artists ar ON ar.id = a.artist_id
            WHERE a.year IN ($in)
            ORDER BY a.year ASC, a.title ASC
        ");
        $stmt->execute($years);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'          => (int)$r['id'],
                'title'       => (string)$r['title'],
                'artist_name' => (string)$r['artist_name'],
                'year'        => (int)$r['year'],
                'age'         => $y - (int)$r['year'],
            ];
            if (count($out) >= max(1, $limit)) {
                break;
            }
        }
        return $out;
    }

    /**
     * URL copertina con la precedenza usata in tutta l'app:
     * file locale, poi URL remoto, altrimenti stringa vuota.
     * Il valore NON è escapato: va passato a htmlspecialchars in output.
     */
    public static function coverUrl(?string $local, ?string $remote): string
    {
        if (!empty($local)) {
            return BASE_URL . '/public/uploads/' . $local;
        }
        if (!empty($remote)) {
            return $remote;
        }
        return '';
    }
}

<?php
class Artist {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll(): array {
        return $this->db->query("SELECT * FROM artists ORDER BY name")->fetchAll();
    }

    public function getById(int $id) {
        $stmt = $this->db->prepare("SELECT * FROM artists WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    // Lookup in SOLA LETTURA: cerca l'artista per nome senza mai
    // crearlo. Usato dal controllo duplicati in AlbumController::save()
    // per non lasciare artisti orfani se l'inserimento viene bloccato.
    public function findByName(string $name): ?int {
        $name = trim($name);
        if ($name === '') return null;

        $stmt = $this->db->prepare("SELECT id FROM artists WHERE name = :name LIMIT 1");
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch();

        return $row ? (int)$row['id'] : null;
    }

    public function findOrCreate(string $name): int {
        $name = trim($name);
        $stmt = $this->db->prepare("SELECT id FROM artists WHERE name = :name LIMIT 1");
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch();
        if ($row) return (int)$row['id'];

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8','ASCII//TRANSLIT', $name)));
        $stmt = $this->db->prepare("INSERT INTO artists (name, slug) VALUES (:name, :slug)");
        $stmt->execute([':name' => $name, ':slug' => $slug]);
        return (int)$this->db->lastInsertId();
    }

    // Album dell'artista (scheda unica): una riga per album con
    //   formats       → array [id, name] dei formati posseduti
    //   editions      → compat con le viste dell'ex raggruppamento
    //   edition_count → numero formati
    // Colonne invariate rispetto a prima (a.* + format_name legacy,
    // genre_name, track_count): la disambiguazione MusicBrainz che
    // legge solo 'title' resta compatibile.
    public function getAlbums(int $artistId): array {
        $stmt = $this->db->prepare("
            SELECT a.*, f.name AS format_name, g.name AS genre_name,
                   (
                     SELECT COUNT(*)
                     FROM tracks t
                     WHERE t.album_id = a.id
                   ) AS track_count,
                   (
                     SELECT GROUP_CONCAT(CONCAT(f2.id, ':::', f2.name) ORDER BY f2.id SEPARATOR '|||')
                     FROM album_formats af2
                     JOIN formats f2 ON af2.format_id = f2.id
                     WHERE af2.album_id = a.id
                   ) AS formats_raw
            FROM albums a
            LEFT JOIN formats f ON a.format_id = f.id
            LEFT JOIN genres  g ON a.genre_id  = g.id
            WHERE a.artist_id = :id
            ORDER BY a.year ASC, a.title ASC
        ");
        $stmt->execute([':id' => $artistId]);

        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['formats'] = $this->parseFormats($row['formats_raw'] ?? null);
            unset($row['formats_raw']);

            // Compatibilità con le viste dell'ex raggruppamento:
            // i "link edizione" puntano tutti alla stessa scheda.
            $editions = [];
            foreach ($row['formats'] as $f) {
                $editions[] = ['id' => (int)$row['id'], 'format_name' => $f['name']];
            }
            $row['editions']      = $editions;
            $row['edition_count'] = count($editions);
        }
        unset($row);

        return $rows;
    }

    // Trasforma "id:::Nome|||id:::Nome" (GROUP_CONCAT) in
    // array di formati [['id' => int, 'name' => string], ...]
    private function parseFormats(?string $raw): array {
        $out = [];
        if (!$raw) return $out;

        foreach (explode('|||', $raw) as $chunk) {
            $parts = explode(':::', $chunk, 2);
            if (count($parts) === 2 && $parts[0] !== '') {
                $out[] = [
                    'id'   => (int)$parts[0],
                    'name' => $parts[1],
                ];
            }
        }
        return $out;
    }

    public function getTopArtists(int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT ar.id, ar.name, ar.slug, COUNT(a.id) AS album_count
            FROM artists ar
            LEFT JOIN albums a ON ar.id = a.artist_id
            GROUP BY ar.id
            ORDER BY album_count DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ----------------------------------------------------------
    // STATISTICHE per la hero della pagina artista
    // (totale album, conteggio per formato, range anni, generi)
    // ----------------------------------------------------------
    public function getAlbumStats(int $artistId): array {
        // total/anni dalle schede; conteggi per formato dalla tabella
        // ponte: un album vinile+CD conta 1 vinile E 1 CD.
        $stmt = $this->db->prepare("
            SELECT
                (SELECT COUNT(*) FROM albums WHERE artist_id = :id_total)              AS total,
                SUM(CASE WHEN f.name = 'Vinile'        THEN 1 ELSE 0 END) AS vinili,
                SUM(CASE WHEN f.name = 'CD'            THEN 1 ELSE 0 END) AS cd,
                SUM(CASE WHEN f.name IN ('Musicassetta', 'Tape') THEN 1 ELSE 0 END) AS cassette,
                SUM(CASE WHEN f.name = 'Digital'      THEN 1 ELSE 0 END) AS digital,
                (SELECT MIN(NULLIF(year, 0)) FROM albums WHERE artist_id = :id_ymin)   AS year_min,
                (SELECT MAX(NULLIF(year, 0)) FROM albums WHERE artist_id = :id_ymax)   AS year_max
            FROM album_formats af
            JOIN albums a       ON af.album_id  = a.id
            LEFT JOIN formats f ON af.format_id = f.id
            WHERE a.artist_id = :id
        ");
        $stmt->execute([
            ':id'       => $artistId,
            ':id_total' => $artistId,
            ':id_ymin'  => $artistId,
            ':id_ymax'  => $artistId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Generi distinti presenti per l'artista
        $g = $this->db->prepare("
            SELECT DISTINCT g.name
            FROM albums a
            JOIN genres g ON a.genre_id = g.id
            WHERE a.artist_id = :id
            ORDER BY g.name
        ");
        $g->execute([':id' => $artistId]);
        $row['genres'] = $g->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return $row;
    }

    // ----------------------------------------------------------
    // Salva i metadati recuperati dalle API esterne.
    // Aggiorna solo le colonne passate (whitelist), niente DROP.
    // ----------------------------------------------------------
    // $status: 'ok' se la ricerca MusicBrainz è andata a buon fine
    // (anche con bio/immagine non trovate: è un "non trovato" confermato),
    // 'error' se la chiamata a MusicBrainz è proprio fallita (rete/timeout):
    // in quel caso needsBioRefetch() la ritenterà da sola dopo un cooldown,
    // invece di restare vuota per sempre. $version va confrontato con
    // ArtistMetadataService::BIO_LOGIC_VERSION.
    public function updateMeta(int $id, array $data, string $status = 'ok', int $version = 0): void {
        $allowed = [
            'mb_artist_id', 'bio', 'bio_source', 'bio_lang', 'bio_url',
            'image_url', 'image_local', 'image_source',
            'country', 'active_from', 'active_to',
        ];

        // Leggiamo i valori attuali per non sovrascrivere un dato buono già
        // presente con un vuoto: un fetch fallito o senza risultati non deve
        // MAI cancellare quello che c'era prima (stesso principio già in uso
        // in AlbumMetadataService per le tracklist: si può solo migliorare o
        // pareggiare, mai peggiorare). Riguarda soprattutto gli artisti del
        // seed demo, che arrivano con una bio scritta a mano ma senza
        // bio_fetched_at: il primo fetch live, se fallisce, non deve
        // spazzarla via.
        $current = $this->getById($id) ?: [];

        $set    = [];
        $params = [':id' => $id];

        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }

            $newValue = ($data[$col] === '' ? null : $data[$col]);
            $hasCurrentValue = array_key_exists($col, $current)
                && $current[$col] !== null
                && $current[$col] !== '';

            // Nuovo valore vuoto ma ne esiste già uno buono: non tocchiamo
            // la colonna, teniamo quello che c'era.
            if ($newValue === null && $hasCurrentValue) {
                continue;
            }

            $set[]           = "`$col` = :$col";
            $params[":$col"] = $newValue;
        }

        // Marca SEMPRE tentativo + esito + versione, anche se nessun campo
        // dati è cambiato (es. tentativo fallito senza nulla da salvare):
        // serve al retry-cooldown e al version bump. Se non lo facessimo,
        // un fallimento non lascerebbe traccia e verrebbe ritentato ad ogni
        // singola visita della pagina, invece che dopo un cooldown.
        $set[] = "`bio_fetched_at` = NOW()";
        $set[] = "`bio_status` = :bio_status";
        $set[] = "`bio_fetch_version` = :bio_fetch_version";
        $params[':bio_status']        = $status;
        $params[':bio_fetch_version'] = $version;

        $sql = "UPDATE artists SET " . implode(', ', $set) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    // ----------------------------------------------------------
    // CACHE — decide se un fetch (bio o discografia) va ripetuto.
    // ----------------------------------------------------------

    /**
     * @param ?string $fetchedAt      Timestamp DB dell'ultimo tentativo (o null)
     * @param ?string $status         'ok' | 'error' | null
     * @param ?int    $storedVersion  Versione della logica al momento del fetch
     * @param int     $currentVersion Versione attuale della logica (costante nel service)
     * @param int     $errorCooldownMinutes  Minuti di attesa prima di ritentare un errore
     */
    private function needsRefetch(
        ?string $fetchedAt,
        ?string $status,
        ?int $storedVersion,
        int $currentVersion,
        int $errorCooldownMinutes = 180,
        int $okTtlDays = 0
    ): bool {
        // Mai tentato prima.
        if ($fetchedAt === null) {
            return true;
        }
        // La logica di fetch è cambiata da quando è stato salvato questo
        // artista: forza un ri-fetch, così un fix (come quello del
        // 2026-07 sulla discografia) si applica da solo alla prossima
        // visita, senza bisogno di toccare il DB a mano.
        if ((int) $storedVersion < $currentVersion) {
            return true;
        }
        // Ultimo tentativo fallito (non "non trovato": proprio fallito):
        // ritenta automaticamente, ma non ad ogni richiesta — altrimenti
        // un MusicBrainz giù per un'ora martellerebbe l'API a ogni pageview.
        if ($status === 'error') {
            $elapsedMinutes = (time() - strtotime($fetchedAt)) / 60;
            return $elapsedMinutes >= $errorCooldownMinutes;
        }
        // Stato 'ok': anche con dati vuoti è un "non trovato" confermato.
        // Con $okTtlDays > 0, però, la conferma SCADE: dopo N giorni si
        // rivalida da sola (usato dalla discografia — un artista attivo
        // può pubblicare nuovi dischi, la cache non deve congelarla per
        // sempre). Con 0 il comportamento resta quello storico: mai più.
        if ($okTtlDays > 0) {
            $elapsedDays = (time() - strtotime($fetchedAt)) / 86400;
            if ($elapsedDays >= $okTtlDays) {
                return true;
            }
        }
        return false;
    }

    public function needsBioRefetch(array $artist, int $currentVersion): bool {
        return $this->needsRefetch(
            $artist['bio_fetched_at']    ?? null,
            $artist['bio_status']        ?? null,
            $artist['bio_fetch_version'] ?? 0,
            $currentVersion
        );
    }

    public function needsDiscographyRefetch(array $artist, int $currentVersion): bool {
        // TTL 30 giorni sull'esito 'ok': la discografia di un artista
        // attivo si rivalida da sola alla prima visita dopo la scadenza.
        // La bio (sopra) resta senza TTL: non invecchia allo stesso modo.
        return $this->needsRefetch(
            $artist['disco_fetched_at']    ?? null,
            $artist['disco_status']        ?? null,
            $artist['disco_fetch_version'] ?? 0,
            $currentVersion,
            180,
            30
        );
    }

    // ----------------------------------------------------------
    // DISCOGRAFIA UFFICIALE (cache)
    // ----------------------------------------------------------

    // Legge la discografia ufficiale salvata in cache, ordinata per anno.
    public function getDiscography(int $artistId): array {
        $stmt = $this->db->prepare("
            SELECT title, year, mb_release_group_id
            FROM artist_discography
            WHERE artist_id = :id
            ORDER BY (year IS NULL), year ASC, title ASC
        ");
        $stmt->execute([':id' => $artistId]);
        return $stmt->fetchAll();
    }

    // Vero se l'artista ha almeno una riga di discografia in cache.
    // Usato dalla guardia anti-svuotamento in saveDiscography().
    public function hasDiscography(int $artistId): bool {
        $stmt = $this->db->prepare("
            SELECT 1 FROM artist_discography WHERE artist_id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $artistId]);
        return (bool) $stmt->fetchColumn();
    }

    // Salva (sostituisce) la discografia ufficiale dell'artista e marca
    // tentativo/stato/versione. Idempotente: ripulisce prima di inserire.
    //
    // $status: 'ok' se la chiamata a MusicBrainz è andata a buon fine
    // (anche a zero risultati: è confermato), 'error' se la richiesta è
    // proprio fallita — in quel caso NON tocchiamo le righe già salvate
    // in precedenza (meglio tenere l'ultima discografia buona che
    // cancellarla per un errore di rete), ma aggiorniamo comunque
    // tentativo/stato/versione così needsDiscographyRefetch() ritenta da
    // sola dopo un cooldown. $version va confrontato con
    // ArtistMetadataService::DISCOGRAPHY_LOGIC_VERSION.
    public function saveDiscography(int $artistId, array $items, string $status = 'ok', int $version = 0): void {
        $this->db->beginTransaction();
        try {
            if ($status === 'ok') {
                // GUARDIA ANTI-SVUOTAMENTO: con la rivalidazione periodica
                // (TTL 30 giorni) un refetch "riuscito" ma degenere — zero
                // risultati per un'anomalia lato MusicBrainz o un filtro
                // troppo aggressivo — non deve cancellare una discografia
                // buona già in cache. Stesso principio di updateMeta():
                // migliorare o pareggiare, mai peggiorare. Timestamp,
                // stato e versione si aggiornano comunque (sotto), così
                // il tentativo resta tracciato e non si martella l'API.
                $keepExisting = empty($items) && $this->hasDiscography($artistId);

                if (!$keepExisting) {
                    $del = $this->db->prepare("DELETE FROM artist_discography WHERE artist_id = :id");
                    $del->execute([':id' => $artistId]);

                    if (!empty($items)) {
                        $ins = $this->db->prepare("
                            INSERT INTO artist_discography
                                (artist_id, mb_release_group_id, title, year)
                            VALUES (:aid, :rg, :title, :year)
                        ");
                        foreach ($items as $it) {
                            $ins->execute([
                                ':aid'   => $artistId,
                                ':rg'    => ($it['mb_release_group_id'] ?? '') ?: null,
                                ':title' => $it['title'] ?? '',
                                ':year'  => $it['year'] ?? null,
                            ]);
                        }
                    }
                }
            }

            $upd = $this->db->prepare("
                UPDATE artists
                SET disco_fetched_at = NOW(), disco_status = :status, disco_fetch_version = :version
                WHERE id = :id
            ");
            $upd->execute([
                ':id'      => $artistId,
                ':status'  => $status,
                ':version' => $version,
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

}
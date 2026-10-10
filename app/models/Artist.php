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


    // Lookup in sola lettura per MBID MusicBrainz artista.
    public function findByMbid(string $mbid): ?int {
        $mbid = $this->normalizeArtistMbid($mbid);
        if ($mbid === '') return null;

        $stmt = $this->db->prepare("SELECT id FROM artists WHERE mb_artist_id = :mbid LIMIT 1");
        $stmt->execute([':mbid' => $mbid]);
        $row = $stmt->fetch();

        return $row ? (int)$row['id'] : null;
    }

    // Lookup in sola lettura per ID artista Deezer persistito.
    public function findByDeezerId(string $deezerArtistId): ?int {
        $deezerArtistId = $this->normalizeDeezerArtistId($deezerArtistId);
        if ($deezerArtistId === '') return null;

        $stmt = $this->db->prepare("
            SELECT id
            FROM artists
            WHERE deezer_artist_id = :deezer_id
            LIMIT 1
        ");
        $stmt->execute([':deezer_id' => $deezerArtistId]);
        $row = $stmt->fetch();

        return $row ? (int)$row['id'] : null;
    }

    // Risolve un artista senza scrivere nulla:
    //  1) MBID MusicBrainz, se disponibile;
    //  2) nome, ma solo se non contraddice un MBID già salvato.
    //
    // Serve al controllo duplicati degli album: alias diversi dello stesso
    // artista ("Beatles" / "The Beatles") devono convergere sullo stesso id
    // quando MusicBrainz fornisce la stessa identità.
    public function findByIdentity(string $name, string $mbArtistId = ''): ?int {
        $name = trim($name);
        $mbid = $this->normalizeArtistMbid($mbArtistId);

        if ($mbid !== '') {
            $byMbid = $this->findByMbid($mbid);
            if ($byMbid !== null) {
                return $byMbid;
            }

            if ($name === '') {
                return null;
            }

            $stmt = $this->db->prepare("
                SELECT id, mb_artist_id
                FROM artists
                WHERE name = :name
                LIMIT 1
            ");
            $stmt->execute([':name' => $name]);
            $row = $stmt->fetch();

            if (!$row) {
                return null;
            }

            $storedMbid = $this->normalizeArtistMbid((string)($row['mb_artist_id'] ?? ''));

            // Nome già presente ma senza MBID: è un candidato valido e verrà
            // arricchito SOLO dopo che il salvataggio ha superato i controlli.
            if ($storedMbid === '') {
                return (int)$row['id'];
            }

            // Stesso nome e stesso MBID: stessa identità.
            if (hash_equals($storedMbid, $mbid)) {
                return (int)$row['id'];
            }

            // Stesso nome ma MBID differente: possibile omonimo. Non fondere.
            return null;
        }

        return $this->findByName($name);
    }

    // Collega un MBID a un artista esistente solo se la colonna è vuota e
    // l'MBID non appartiene già a un altro record. Mai sovrascrivere
    // un'identità MusicBrainz già salvata.
    public function attachMbidIfEmpty(int $artistId, string $mbArtistId): void {
        $mbid = $this->normalizeArtistMbid($mbArtistId);
        if ($artistId <= 0 || $mbid === '') return;

        $owner = $this->findByMbid($mbid);
        if ($owner !== null && $owner !== $artistId) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE artists
            SET mb_artist_id = :mbid
            WHERE id = :id
              AND (mb_artist_id IS NULL OR mb_artist_id = '')
        ");
        $stmt->execute([
            ':mbid' => $mbid,
            ':id'   => $artistId,
        ]);
    }

    // Sostituzione ESPLICITA di un mapping Deezer gia' persistito.
    // Da usare solo in procedure di riparazione verificate: il flusso
    // automatico usa sempre attachDeezerIdIfEmpty() e non cambia identita'.
    public function setDeezerIdVerified(int $artistId, string $deezerArtistId): bool {
        $deezerArtistId = $this->normalizeDeezerArtistId($deezerArtistId);
        if ($artistId <= 0 || $deezerArtistId === '') return false;

        $owner = $this->findByDeezerId($deezerArtistId);
        if ($owner !== null && $owner !== $artistId) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE artists
            SET deezer_artist_id = :deezer_id
            WHERE id = :id
        ");
        $stmt->execute([
            ':deezer_id' => $deezerArtistId,
            ':id'        => $artistId,
        ]);

        return true;
    }

    // Collega l'ID Deezer a un artista solo se la colonna e' vuota.
    // Un mapping gia' persistito non viene mai cambiato automaticamente.
    // La UNIQUE KEY sul DB impedisce inoltre che lo stesso Deezer ID venga
    // associato a due artisti locali differenti.
    public function attachDeezerIdIfEmpty(int $artistId, string $deezerArtistId): bool {
        $deezerArtistId = $this->normalizeDeezerArtistId($deezerArtistId);
        if ($artistId <= 0 || $deezerArtistId === '') return false;

        $current = $this->getById($artistId);
        if (!$current) return false;

        $stored = $this->normalizeDeezerArtistId(
            (string)($current['deezer_artist_id'] ?? '')
        );

        // Mapping gia' persistito: e' valido solo se coincide.
        if ($stored !== '') {
            return hash_equals($stored, $deezerArtistId);
        }

        $owner = $this->findByDeezerId($deezerArtistId);
        if ($owner !== null && $owner !== $artistId) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE artists
            SET deezer_artist_id = :deezer_id
            WHERE id = :id
              AND (deezer_artist_id IS NULL OR deezer_artist_id = '')
        ");
        $stmt->execute([
            ':deezer_id' => $deezerArtistId,
            ':id'        => $artistId,
        ]);

        $fresh = $this->getById($artistId);
        return $fresh
            && hash_equals(
                $deezerArtistId,
                $this->normalizeDeezerArtistId(
                    (string)($fresh['deezer_artist_id'] ?? '')
                )
            );
    }

    // Aggiorna la GRAFIA del nome di un artista esistente (maiuscole,
    // accenti, spazi). Chiamato solo da AlbumController::save() in
    // modifica, quando il nome digitato risolve già a questo artista
    // secondo la collation del DB: non è un cambio di identità, quindi
    // MBID, bio, immagine e discografia restano validi e non si toccano.
    // Il confronto in PHP è binario: se la stringa è identica non si
    // scrive nulla.
    public function updateNameSpelling(int $artistId, string $name): void {
        $name = trim($name);
        if ($name === '') return;

        $stmt = $this->db->prepare("SELECT name FROM artists WHERE id = :id");
        $stmt->execute([':id' => $artistId]);
        $current = $stmt->fetchColumn();

        if ($current === false || $current === $name) return;

        $stmt = $this->db->prepare("UPDATE artists SET name = :name WHERE id = :id");
        $stmt->execute([':name' => $name, ':id' => $artistId]);
    }

    public function findOrCreate(string $name, string $mbArtistId = ''): int {
        $name = trim($name);
        $mbid = $this->normalizeArtistMbid($mbArtistId);

        $existingId = $this->findByIdentity($name, $mbid);
        if ($existingId !== null) {
            if ($mbid !== '') {
                $this->attachMbidIfEmpty($existingId, $mbid);
            }
            return $existingId;
        }

        $slugBase = strtolower((string)preg_replace(
            '/[^a-z0-9]+/i',
            '-',
            (string)iconv('UTF-8', 'ASCII//TRANSLIT', $name)
        ));
        $slugBase = trim($slugBase, '-');
        if ($slugBase === '') {
            $slugBase = 'artist';
        }

        $slug = $this->uniqueArtistSlug($slugBase, $mbid);

        $stmt = $this->db->prepare("
            INSERT INTO artists (name, slug, mb_artist_id)
            VALUES (:name, :slug, :mbid)
        ");
        $stmt->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':mbid' => $mbid !== '' ? $mbid : null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    private function normalizeArtistMbid(string $mbid): string {
        $mbid = strtolower(trim($mbid));

        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $mbid
        ) ? $mbid : '';
    }

    private function normalizeDeezerArtistId(string $deezerArtistId): string {
        $deezerArtistId = trim($deezerArtistId);
        return preg_match('/^\d+$/', $deezerArtistId) ? $deezerArtistId : '';
    }

    // Lo slug resta quello storico quando è libero. Il suffisso viene usato
    // solo nel raro caso di due artisti omonimi con MBID differenti.
    private function uniqueArtistSlug(string $base, string $mbid = ''): string {
        $candidate = $base;
        $suffix = $mbid !== ''
            ? substr(str_replace('-', '', $mbid), 0, 8)
            : 'artist';

        $n = 1;
        while (true) {
            $stmt = $this->db->prepare("SELECT 1 FROM artists WHERE slug = :slug LIMIT 1");
            $stmt->execute([':slug' => $candidate]);

            if (!$stmt->fetchColumn()) {
                return $candidate;
            }

            $candidate = $base . '-' . $suffix . ($n > 1 ? '-' . $n : '');
            $n++;
        }
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
                     SELECT COUNT(DISTINCT af3.track_id)
                     FROM audio_files af3
                     JOIN tracks t3 ON t3.id = af3.track_id
                     WHERE t3.album_id = a.id
                   ) AS tracks_with_audio_count,
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
    // METADATI ARTISTA — BIO e IMMAGINE hanno cache indipendenti.
    // ----------------------------------------------------------

    /**
     * Salva SOLO identita' MusicBrainz + bio + metadati anagrafici.
     * Non tocca mai image_* / deezer_artist_id.
     */
    public function updateBioMeta(
        int $id,
        array $data,
        string $status = 'ok',
        int $version = 0
    ): void {
        if (!empty($data['mb_artist_id'])) {
            $this->attachMbidIfEmpty($id, (string)$data['mb_artist_id']);
        }

        $allowed = [
            'bio', 'bio_source', 'bio_lang', 'bio_url',
            'country', 'active_from', 'active_to',
        ];

        $current = $this->getById($id) ?: [];
        $set     = [];
        $params  = [':id' => $id];

        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }

            $newValue = ($data[$col] === '' ? null : $data[$col]);
            $hasCurrentValue = array_key_exists($col, $current)
                && $current[$col] !== null
                && $current[$col] !== '';

            // Un fetch vuoto/fallito non cancella mai un dato gia' buono.
            if ($newValue === null && $hasCurrentValue) {
                continue;
            }

            $set[]            = "`$col` = :$col";
            $params[":$col"] = $newValue;
        }

        $set[] = "`bio_fetched_at` = NOW()";
        $set[] = "`bio_status` = :bio_status";
        $set[] = "`bio_fetch_version` = :bio_fetch_version";
        $params[':bio_status']        = $status;
        $params[':bio_fetch_version'] = $version;

        $sql = "UPDATE artists SET " . implode(', ', $set) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Salva SOLO identita' Deezer + immagine e relativa cache.
     *
     * $allowReplace=false e' la guardia anti-regressione fondamentale:
     * una foto gia' presente (locale o remota) non viene sostituita da un
     * normale refetch automatico. La sostituzione e' ammessa solo da una
     * procedura esplicita di riparazione verificata.
     */
    public function updateImageMeta(
        int $id,
        array $data,
        string $status = 'ok',
        int $version = 0,
        bool $allowReplace = false
    ): void {
        $ownsTransaction = !$this->db->inTransaction();

        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            if (!empty($data['deezer_artist_id'])) {
                $identitySaved = $allowReplace
                    ? $this->setDeezerIdVerified($id, (string)$data['deezer_artist_id'])
                    : $this->attachDeezerIdIfEmpty($id, (string)$data['deezer_artist_id']);

                // Se il Deezer ID appartiene gia' a un altro artista locale o
                // contraddice un mapping persistito, non salviamo neppure la foto.
                if (!$identitySaved) {
                    throw new RuntimeException(
                        'Deezer artist identity conflict for artist #' . $id
                    );
                }
            }

            $current = $this->getById($id) ?: [];

            $hasCurrentImage =
                !empty($current['image_local'])
                || !empty($current['image_url']);

            $set    = [];
            $params = [':id' => $id];

            if ($allowReplace || !$hasCurrentImage) {
                foreach (['image_url', 'image_local', 'image_source'] as $col) {
                    if (!array_key_exists($col, $data)) {
                        continue;
                    }

                    $newValue = ($data[$col] === '' ? null : $data[$col]);

                    if (!$allowReplace) {
                        $hasCurrentValue = array_key_exists($col, $current)
                            && $current[$col] !== null
                            && $current[$col] !== '';

                        if ($newValue === null && $hasCurrentValue) {
                            continue;
                        }
                    }

                    $set[]            = "`$col` = :$col";
                    $params[":$col"] = $newValue;
                }
            }

            $set[] = "`image_fetched_at` = NOW()";
            $set[] = "`image_status` = :image_status";
            $set[] = "`image_fetch_version` = :image_fetch_version";
            $params[':image_status']        = $status;
            $params[':image_fetch_version'] = $version;

            $sql = "UPDATE artists SET " . implode(', ', $set) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Compatibilita' con eventuali chiamanti storici.
     * Nuovo codice: usare updateBioMeta() e updateImageMeta() separatamente.
     */
    public function updateMeta(
        int $id,
        array $data,
        string $status = 'ok',
        int $version = 0
    ): void {
        $this->updateBioMeta($id, $data, $status, $version);

        // Compatibilita' soltanto: non marchiare una cache immagine come
        // "tentata" se il chiamante storico stava aggiornando solo la bio.
        $hasImagePayload =
            array_key_exists('deezer_artist_id', $data)
            || array_key_exists('image_url', $data)
            || array_key_exists('image_local', $data)
            || array_key_exists('image_source', $data);

        if ($hasImagePayload) {
            $this->updateImageMeta($id, $data, $status, 1, false);
        }
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
        // Risultato PARZIALE: abbiamo già salvato e mostrato gli album
        // raccolti, ma la scansione si era interrotta (una pagina caduta).
        // Ritenta per RICOMPLETARE dopo un cooldown breve — molto più corto
        // dell'errore, perché qui NON stiamo martellando su un fallimento:
        // stiamo solo cercando di aggiungere i dischi mancanti. La guardia
        // anti-regressione in saveDiscography assicura che un nuovo parziale
        // più corto non peggiori quello che già mostriamo.
        if ($status === 'partial') {
            $elapsedMinutes = (time() - strtotime($fetchedAt)) / 60;
            return $elapsedMinutes >= 60;
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

    public function needsImageRefetch(array $artist, int $currentVersion): bool {
        // Immagine gia' valida: MAI sostituirla automaticamente per un
        // semplice version bump. Le correzioni di immagini esistenti passano
        // da una riparazione esplicita (updateImageMeta(..., true)).
        if (!empty($artist['image_local']) || !empty($artist['image_url'])) {
            return false;
        }

        // Se non esiste alcuna immagine, il miss confermato viene rivalidato
        // dopo 7 giorni; gli errori transitori restano sul cooldown standard.
        return $this->needsRefetch(
            $artist['image_fetched_at']    ?? null,
            $artist['image_status']        ?? null,
            $artist['image_fetch_version'] ?? 0,
            $currentVersion,
            180,
            7
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

    // Riga di discografia (titolo + nome artista) a partire dal
    // release-group MBID: usata dal proxy cover (disco-cover) per dare
    // al fallback Deezer i dati di ricerca senza farli viaggiare in
    // query string. Null se l'MBID non è in cache.
    public function getDiscographyEntryByRg(string $rgMbid): ?array {
        $rgMbid = trim($rgMbid);
        if ($rgMbid === '') return null;

        $stmt = $this->db->prepare("
            SELECT d.title, ar.name AS artist_name
            FROM artist_discography d
            JOIN artists ar ON d.artist_id = ar.id
            WHERE d.mb_release_group_id = :rg
            LIMIT 1
        ");
        $stmt->execute([':rg' => $rgMbid]);
        $row = $stmt->fetch();
        return $row ?: null;
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
            // Sia 'ok' sia 'partial' scrivono le righe. La differenza è nel
            // cooldown (vedi needsRefetch): 'ok' = definitivo (TTL lungo),
            // 'partial' = incompleto (retry breve per ricompletarsi).
            if ($status === 'ok' || $status === 'partial') {
                // GUARDIA ANTI-SVUOTAMENTO / ANTI-REGRESSIONE:
                //  - refetch 'ok' ma degenere (zero risultati per anomalia
                //    MusicBrainz o filtro troppo aggressivo) non deve
                //    cancellare una discografia buona già in cache;
                //  - un risultato PARZIALE più CORTO non deve sostituire una
                //    discografia esistente più LUNGA (es. avevo 25 album
                //    completi, un parziale ne porta 16: tengo i 25). Un
                //    parziale che invece AMPLIA (16 → 18) aggiorna.
                //
                // FIX 2026-09 (discografia v15): la seconda condizione non
                // controllava né lo stato né la versione, quindi scattava
                // anche su esiti 'ok' completi. Quando una correzione della
                // logica TOGLIE album (bootleg esclusi: Rolling Stones 41 → 28,
                // Coldplay 11 → 10) il risultato corretto veniva scartato,
                // le righe vecchie restavano e l'UPDATE sotto marcava
                // comunque l'artista come aggiornato alla nuova versione,
                // bloccandolo sul dato errato. Ora la protezione del
                // parziale più corto vale solo se:
                //  - l'esito è 'partial' (un 'ok' è una scansione completa
                //    e sostituisce sempre);
                //  - le righe in cache sono state prodotte dalla STESSA
                //    versione di logica (righe di una logica superata non
                //    sono una base affidabile da proteggere).
                // La prima condizione (vuoto vs qualcosa) resta invariata.
                $existingCount = $this->discographyCount($artistId);
                $newCount      = count($items);

                $sameVersion = $this->storedDiscographyVersion($artistId) === $version;

                $keepExisting =
                    (empty($items) && $existingCount > 0)           // vuoto vs qualcosa
                    || ($status === 'partial'                        // parziale più corto,
                        && $sameVersion                              // stessa logica
                        && $newCount > 0
                        && $newCount < $existingCount);

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

    // Versione della logica con cui è stata salvata la discografia in
    // cache (artists.disco_fetch_version). Letta PRIMA dell'UPDATE in
    // saveDiscography, quindi riflette le righe attualmente presenti.
    private function storedDiscographyVersion(int $artistId): int {
        $stmt = $this->db->prepare("
            SELECT disco_fetch_version FROM artists WHERE id = :id
        ");
        $stmt->execute([':id' => $artistId]);
        return (int) $stmt->fetchColumn();
    }

    // Conta le righe di discografia in cache per un artista. Usato dalla
    // guardia anti-regressione in saveDiscography (un parziale più corto
    // non deve sostituire una discografia più lunga).
    public function discographyCount(int $artistId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM artist_discography WHERE artist_id = :id
        ");
        $stmt->execute([':id' => $artistId]);
        return (int) $stmt->fetchColumn();
    }

}

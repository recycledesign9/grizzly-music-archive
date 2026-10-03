<?php

/**
 * MediaImportService
 *
 * Scansione stile Navidrome. Data una cartella di import, individua gli album,
 * legge i metadati dai tag (getID3, opzionale) con fallback sul nome cartella e
 * sul nome file, risolve il formato dalla cartella antenata, e scrive artista,
 * album, tracce e file audio riusando i modelli esistenti (Artist, Album,
 * Track) e la stessa convenzione di storage dell'upload manuale.
 *
 * Regola di raggruppamento: una cartella che contiene DIRETTAMENTE file audio
 * e' un album, a qualunque profondita' nell'albero. Le sottocartelle CD1/CD2/
 * Disc 2 confluiscono nell'album del genitore (multi-disco).
 *
 * Formato: si risale dalla cartella dell'album verso la root di import; la
 * prima cartella antenata il cui nome corrisponde a un formato della tabella
 * `formats` (con una mappa di alias) vince. Se nessun antenato e' un formato
 * riconosciuto, si applica 'Digital'.
 *
 * NON tocca il flusso di upload esistente: aggiunge solo un percorso di
 * inserimento. Gli album creati qui sono marcati needs_review = 1; quelli
 * inseriti a mano restano 0. review_note indica perche' un album e' incerto.
 *
 * Idempotenza: source_path identifica la posizione corrente del file visto nella
 * watched folder; i nuovi import vengono indicizzati sul posto
 * (storage_type=external), senza copia audio. source_mtime + filesize permettono
 * lo skip veloce. source_hash = SHA-1 resta un fingerprint diagnostico, NON una
 * chiave globale di unicita'. Se una traccia external si sposta e il vecchio path
 * scompare, il record viene riallineato sul nuovo path mantenendo lo stesso token.
 *
 * Dipendenze runtime gia' caricate dal bootstrap dell'app: Database,
 * MediaPathResolver, Artist, Album, Track e le costanti di config
 * (BASE_PATH, COVERS_PATH).
 *
 * getID3 e' opzionale: se non installata, lo scanner funziona in modalita'
 * degradata (metadati dedotti solo da nome cartella e nome file) e lo segnala.
 *
 * Posizionare in: app/services/MediaImportService.php
 */
class MediaImportService
{
    /** Estensioni audio riconosciute (case-insensitive). */
    private const AUDIO_EXT = ['mp3', 'flac'];

    /** Estensioni immagine per le cover di cartella (case-insensitive). */
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var PDO */
    private $db;

    /** Istanza getID3 oppure null se la libreria non e' installata. */
    private $id3 = null;

    /** @var array<string,int>|null cache: nome-formato-normalizzato => id */
    private $formatMap = null;

    /** @var array<int,string> cache: id formato => nome reale (per il report) */
    private $formatNames = [];

    /** @var array<string,?int> cache: nome-genere-minuscolo => id|null */
    private $genreCache = [];

    /** @var array<string,?int> cache: nome-etichetta-minuscolo => id|null */
    private $labelCache = [];

    public function __construct()
    {
        $this->db  = Database::getInstance();
        $this->id3 = $this->loadGetID3();
    }

    // ==========================================================
    // API PUBBLICA
    // ==========================================================

    /**
     * Scansiona la cartella di import.
     *
     * @param string $importDir Cartella radice da cui scansionare.
     * @param bool   $dryRun    Se true non scrive nulla: rileva e riferisce soltanto.
     * @param int    $maxAlbums Limite di album per esecuzione (0 = nessun limite).
     * @return array Report strutturato (vedi struttura in coda al metodo).
     */
    public function scan(string $importDir, bool $dryRun = false, int $maxAlbums = 0, array $onlyAlbumKeys = []): array
    {
        @set_time_limit(0);

        $report = [
            'import_dir' => $importDir,
            'dry_run'    => $dryRun,
            'getid3'     => $this->id3 !== null,
            'albums'     => [],
            'totals'     => [
                'albums_found'    => 0,
                'albums_created'  => 0,
                'albums_existing' => 0,
                'audio_imported'  => 0,
                'audio_skipped'   => 0,
                'audio_deferred'  => 0,
                'errors'          => 0,
            ],
            'error' => null,
        ];

        $real = realpath($importDir);
        if ($real === false || !is_dir($real)) {
            $report['error'] = 'Cartella di import non trovata: ' . $importDir;
            return $report;
        }
        if (!is_readable($real)) {
            $report['error'] = 'Cartella di import non leggibile: ' . $real;
            return $report;
        }
        $importDir = rtrim(str_replace('\\', '/', $real), '/');

        $groups = $this->discoverAlbums($importDir);

        // Cartelle eliminate volontariamente dall'archivio: restano fisicamente
        // nella watched folder, ma non devono essere ricreate dallo scanner.
        $ignoredHashes = $this->ignoredAlbumPathHashes();
        if (!empty($ignoredHashes)) {
            $groups = array_filter($groups, function ($parts, $albumKey) use ($ignoredHashes) {
                $dbPath = $this->sourcePathForDb((string)$albumKey);
                return !isset($ignoredHashes[sha1($dbPath)]);
            }, ARRAY_FILTER_USE_BOTH);
        }

        // Il worker automatico puo' chiedere di elaborare solo album gia'
        // considerati stabili. Le chiamate esistenti non passano questo filtro
        // e continuano a scansionare l'intero albero come prima.
        if (!empty($onlyAlbumKeys)) {
            $wanted = [];
            foreach ($onlyAlbumKeys as $key) {
                $key = rtrim(str_replace('\\', '/', (string)$key), '/');
                if ($key !== '') {
                    $wanted[$key] = true;
                }
            }
            $groups = array_filter($groups, function ($parts, $key) use ($wanted) {
                return isset($wanted[rtrim(str_replace('\\', '/', (string)$key), '/')]);
            }, ARRAY_FILTER_USE_BOTH);
        }

        $report['totals']['albums_found'] = count($groups);

        $done = 0;
        foreach ($groups as $albumKey => $parts) {
            if ($maxAlbums > 0 && $done >= $maxAlbums) {
                break;
            }
            $done++;

            try {
                $entry = $this->processAlbum($importDir, (string)$albumKey, $parts, $dryRun);
            } catch (Throwable $e) {
                $entry = [
                    'artist'         => '',
                    'title'          => basename((string)$albumKey),
                    'year'           => null,
                    'format'         => '',
                    'status'         => 'error',
                    'tracks'         => 0,
                    'audio_imported' => 0,
                    'audio_skipped'  => 0,
                    'audio_deferred' => 0,
                    'deferred_sources' => [],
                    'cover'          => false,
                    'review_note'    => '',
                    'errors'         => ['Eccezione: ' . $e->getMessage()],
                ];
            }

            $report['albums'][] = $entry;

            if ($entry['status'] === 'created') {
                $report['totals']['albums_created']++;
            }
            if ($entry['status'] === 'exists') {
                $report['totals']['albums_existing']++;
            }
            $report['totals']['audio_imported'] += $entry['audio_imported'];
            $report['totals']['audio_skipped']  += $entry['audio_skipped'];
            $report['totals']['audio_deferred'] += $entry['audio_deferred'];
            $report['totals']['errors']         += count($entry['errors']);
        }

        return $report;
    }

    /**
     * Restituisce le directory-album individuate dallo stesso motore usato da
     * scan(). Serve al worker automatico per applicare la stability window
     * senza duplicare la logica di raggruppamento.
     *
     * @return array<int,string>
     */
    public function discoverAlbumKeys(string $importDir): array
    {
        $real = realpath($importDir);
        if ($real === false || !is_dir($real) || !is_readable($real)) {
            return [];
        }

        $root = rtrim(str_replace('\\', '/', $real), '/');
        return array_keys($this->discoverAlbums($root));
    }

    // ==========================================================
    // SCOPERTA E RAGGRUPPAMENTO
    // ==========================================================

    /**
     * Individua gli album come mappa: albumKey => lista di [dir, disc].
     * albumKey e' la cartella dell'album (per i multi-disco, il genitore).
     */
    private function discoverAlbums(string $importDir): array
    {
        $candidates = [];
        foreach ($this->allDirectories($importDir) as $dir) {
            if (count($this->listByExt($dir, self::AUDIO_EXT)) > 0) {
                $candidates[] = $dir;
            }
        }

        $groups = [];
        foreach ($candidates as $dir) {
            $disc = $this->discNumberFromName(basename($dir));

            if ($disc !== null && $dir !== $importDir) {
                $key = dirname($dir); // sottocartella disco: confluisce nel genitore
            } else {
                $key  = $dir;
                $disc = ($disc === null) ? 1 : $disc;
            }

            // Non risalire mai oltre la root di import.
            if ($key === '' || strpos($key . '/', $importDir . '/') !== 0) {
                $key  = $dir;
                $disc = 1;
            }

            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = ['dir' => $dir, 'disc' => ($disc === null ? 1 : $disc)];
        }

        ksort($groups);
        return $groups;
    }

    /**
     * Tutte le directory sotto $root (inclusa $root), senza symlink/nascoste.
     */
    private function allDirectories(string $root): array
    {
        $out   = [$root];
        $stack = [$root];

        while (!empty($stack)) {
            $dir     = array_pop($stack);
            $entries = @scandir($dir);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                if ($e !== '' && $e[0] === '.') {
                    continue; // salta nascosti
                }
                $path = $dir . '/' . $e;
                if (is_link($path)) {
                    continue; // niente symlink, evita loop
                }
                if (is_dir($path)) {
                    $out[]   = $path;
                    $stack[] = $path;
                }
            }
        }
        return $out;
    }

    /**
     * File in $dir con una delle estensioni date (case-insensitive), ordinati.
     */
    private function listByExt(string $dir, array $exts): array
    {
        $out     = [];
        $entries = @scandir($dir);
        if ($entries === false) {
            return $out;
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            if ($e !== '' && $e[0] === '.') {
                continue;
            }
            $path = $dir . '/' . $e;
            if (!is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
            if (in_array($ext, $exts, true)) {
                $out[] = $path;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Numero di disco da un nome cartella tipo "CD2", "Disc 1", "Disco 3".
     * Null se la cartella non e' una cartella-disco.
     */
    private function discNumberFromName(string $name): ?int
    {
        if (preg_match('/^(cd|disc|disco|disk)\s*[-_. ]?\s*(\d{1,2})$/i', trim($name), $m)) {
            return (int)$m[2];
        }
        return null;
    }

    // ==========================================================
    // ELABORAZIONE DI UN ALBUM
    // ==========================================================

    private function processAlbum(string $importDir, string $albumKey, array $parts, bool $dryRun): array
    {
        // --- 1) Analizza tutte le tracce del gruppo (tutti i dischi) ---
        $files = [];
        foreach ($parts as $p) {
            foreach ($this->listByExt($p['dir'], self::AUDIO_EXT) as $abs) {
                $files[] = ['abs' => $abs, 'disc' => $p['disc']];
            }
        }

        $tracks         = [];
        $tagArtist      = '';
        $tagAlbumArtist = '';
        $tagAlbum       = '';
        $tagGenre       = '';
        $tagLabel       = '';
        $tagYear        = null;
        $embeddedCover  = null;

        foreach ($files as $f) {
            $meta = $this->analyze($f['abs']);

            $disc = $f['disc'];
            if ($meta['disc'] !== null && $meta['disc'] > 0 && $disc === 1) {
                $disc = $meta['disc'];
            }

            $trackNum = $meta['track'];
            if ($trackNum === null) {
                $trackNum = $this->trackNumberFromFilename(basename($f['abs']));
            }

            $title = $meta['title'] !== '' ? $meta['title'] : $this->titleFromFilename(basename($f['abs']));

            $tracks[] = [
                'abs'      => $f['abs'],
                'disc'     => $disc ? $disc : 1,
                'num'      => $trackNum === null ? 0 : $trackNum,
                'title'    => $title,
                'duration' => $meta['duration'],
                'ext'      => strtolower(pathinfo($f['abs'], PATHINFO_EXTENSION)),
            ];

            if ($tagAlbumArtist === '' && $meta['albumartist'] !== '') {
                $tagAlbumArtist = $meta['albumartist'];
            }
            if ($tagArtist === '' && $meta['artist'] !== '') {
                $tagArtist = $meta['artist'];
            }
            if ($tagAlbum === '' && $meta['album'] !== '') {
                $tagAlbum = $meta['album'];
            }
            if ($tagGenre === '' && $meta['genre'] !== '') {
                $tagGenre = $meta['genre'];
            }
            if ($tagLabel === '' && $meta['label'] !== '') {
                $tagLabel = $meta['label'];
            }
            if ($tagYear === null && $meta['year'] !== null) {
                $tagYear = $meta['year'];
            }
            if ($embeddedCover === null && $meta['picture'] !== null) {
                $embeddedCover = $meta['picture'];
            }
        }

        usort($tracks, function ($a, $b) {
            if ($a['disc'] !== $b['disc']) {
                return $a['disc'] <=> $b['disc'];
            }
            if ($a['num'] !== $b['num']) {
                return $a['num'] <=> $b['num'];
            }
            return strcmp($a['abs'], $b['abs']);
        });

        // --- 2) Campi album con fallback e motivi di revisione ---
        $folder  = $this->parseFolderName(basename($albumKey));
        $reasons = [];

        $artist = $tagAlbumArtist !== '' ? $tagAlbumArtist : $tagArtist;
        if ($artist === '') {
            $artist = $folder['artist'];
            if ($artist !== '') {
                $reasons[] = 'artista dedotto dal nome cartella';
            } else {
                $artist    = 'Sconosciuto';
                $reasons[] = 'artista non determinato';
            }
        }

        $title = $tagAlbum !== '' ? $tagAlbum : $folder['title'];
        if ($tagAlbum === '') {
            $reasons[] = 'titolo dedotto dal nome cartella';
        }
        if ($title === '') {
            $title = basename($albumKey);
        }

        $year = $tagYear !== null ? $tagYear : $folder['year'];
        if ($year === null) {
            $reasons[] = 'anno assente';
        }

        if (!$this->id3) {
            $reasons[] = 'tag non letti (getID3 assente)';
        }

        // Se questi stessi file external sono gia' indicizzati, il source_path
        // e' un'identita' piu' forte dei tag embedded. Questo protegge le
        // correzioni manuali dell'utente (es. albumartist errato "Various
        // Artists" corretto in "Why") dalle scansioni successive.
        $sourceLinkedAlbum = $this->findExistingAlbumBySourcePaths($importDir, $tracks);
        if ($sourceLinkedAlbum !== null) {
            if (!empty($sourceLinkedAlbum['artist_name'])) {
                $artist = (string)$sourceLinkedAlbum['artist_name'];
            }
            if (!empty($sourceLinkedAlbum['title'])) {
                $title = (string)$sourceLinkedAlbum['title'];
            }
            if ($year === null && !empty($sourceLinkedAlbum['year'])) {
                $year = (int)$sourceLinkedAlbum['year'];
            }
        }

        // --- 3) Formato dalla cartella antenata (default Digital) ---
        $formatId   = $this->resolveFormatId($albumKey, $importDir);
        $formatName = $this->formatNameById($formatId);

        // --- 4) Cover + completamento metadati ---
        // Priorita' assoluta ai tag/file locali. Le API vengono interpellate UNA
        // sola volta per album soltanto se manca almeno uno tra genere, etichetta
        // o cover. In questo modo lo scanner resta rapido quando i file sono ben
        // taggati e usa lo stesso AlbumMetadataService del form manuale come
        // fallback quando i tag sono incompleti.
        $coverSource      = $this->pickCover($parts, $embeddedCover);
        $externalMeta     = [];
        $externalCoverLocal = null;
        $externalCoverUrl   = null;
        $externalMbid       = null;

        if ($tagGenre === '' || $tagLabel === '' || $coverSource['type'] === 'none') {
            $externalMeta = $this->fetchExternalMetadata(
                $artist,
                $title,
                $year,
                $coverSource['type'] === 'none',
                !$dryRun
            );

            if ($tagGenre === '' && !empty($externalMeta['genre'])) {
                $tagGenre = trim((string)$externalMeta['genre']);
            }
            if ($tagLabel === '' && !empty($externalMeta['label'])) {
                $tagLabel = trim((string)$externalMeta['label']);
            }
            if ($year === null && !empty($externalMeta['year'])) {
                $apiYear = (int)$externalMeta['year'];
                if ($apiYear >= 1900 && $apiYear <= ((int)date('Y') + 1)) {
                    $year = $apiYear;
                }
            }

            if (!empty($externalMeta['mbid'])) {
                $externalMbid = trim((string)$externalMeta['mbid']);
            }

            if ($coverSource['type'] === 'none') {
                if (!empty($externalMeta['cover_local'])) {
                    $externalCoverLocal = trim((string)$externalMeta['cover_local']);
                } elseif (!empty($externalMeta['cover'])) {
                    $externalCoverUrl = trim((string)$externalMeta['cover']);
                }
            }
        }

        if ($coverSource['type'] === 'none' && $externalCoverLocal === null && $externalCoverUrl === null) {
            $reasons[] = 'cover assente';
        }
        if ($tagGenre === '') {
            $reasons[] = 'genere assente';
        }
        if ($tagLabel === '') {
            $reasons[] = 'etichetta assente';
        }

        $reviewNote = $this->clip(implode('; ', $reasons), 255);

        $entry = [
            'artist'         => $artist,
            'title'          => $title,
            'year'           => $year,
            'format'         => $formatName,
            'status'         => 'planned',
            'tracks'         => count($tracks),
            'audio_imported' => 0,
            'audio_skipped'  => 0,
            'audio_deferred' => 0,
            'deferred_sources' => [],
            'cover'          => $coverSource['type'] !== 'none' || $externalCoverLocal !== null || $externalCoverUrl !== null,
            'review_note'    => $reviewNote,
            'errors'         => [],
        ];

        $artistModel = new Artist();
        $albumModel  = new Album();
        $trackModel  = new Track();

        // --- 5a) DRY-RUN: nessuna scrittura, solo diagnosi ---
        if ($dryRun) {
            $existing = null;
            if ($sourceLinkedAlbum !== null && !empty($sourceLinkedAlbum['id'])) {
                $existing = $albumModel->getById((int)$sourceLinkedAlbum['id']);
            }
            if (!$existing) {
                $artistId = $artistModel->findByName($artist);
                $existing = $artistId ? $albumModel->findDuplicate($artistId, $title) : null;
            }
            $entry['status'] = $existing ? 'exists' : 'planned';

            foreach ($tracks as $t) {
                if ($this->audioState($importDir, $t['abs']) === 'present') {
                    $entry['audio_skipped']++;
                } else {
                    $entry['audio_imported']++; // verrebbe importato
                }
            }
            return $entry;
        }

        // --- 5b) IMPORT REALE ---
        $existing = null;
        $artistId = 0;

        // Prima prova il legame gia' noto tramite source_path. In questo modo
        // una correzione manuale di artista/titolo non crea una seconda scheda
        // quando i tag embedded continuano a contenere valori sbagliati.
        if ($sourceLinkedAlbum !== null && !empty($sourceLinkedAlbum['id'])) {
            $existing = $albumModel->getById((int)$sourceLinkedAlbum['id']);
            if ($existing) {
                $artistId = (int)($existing['artist_id'] ?? 0);
            }
        }

        if (!$existing) {
            $artistId = $artistModel->findOrCreate($artist);
            $existing = $albumModel->findDuplicate($artistId, $title);
        }

        if ($existing) {
            // Album gia' in archivio: NON si duplica la scheda e NON si
            // sovrascrive una tracklist curata manualmente. Gli audio dello
            // scanner vengono associati alle tracce esistenti quando il match e'
            // affidabile; un audio manuale gia' presente sulla traccia ha priorita'.
            $albumId         = (int)$existing['id'];
            $entry['status'] = 'exists';

            $albumModel->syncScannerFormat($albumId, $formatId);
            $this->maybeFillAlbumMetadataIfMissing($albumId, $tagGenre, $tagLabel, $year, $externalMbid);
            $this->maybeSetCoverIfMissing($albumId, $coverSource, $externalCoverLocal, $externalCoverUrl);

            $existingTracks = $this->uniqueTrackRows($trackModel->getByAlbum($albumId));

            // Se l'album esiste ma non ha ancora una tracklist, la crea dai tag
            // letti dallo scanner.
            if (empty($existingTracks) && !empty($tracks)) {
                $tl = [];
                foreach ($tracks as $t) {
                    $tl[] = ['title' => $t['title'], 'duration' => $t['duration']];
                }
                $trackModel->saveTracklist($albumId, $tl);
                $existingTracks = $this->uniqueTrackRows($trackModel->getByAlbum($albumId));
            }

            $trackMap = $this->matchExistingTracks($tracks, $existingTracks);

            // Riparazione incrementale di una tracklist creata dallo scanner ma
            // rimasta incompleta perche' la watched folder era stata importata
            // durante una pausa del trasferimento.
            //
            // NON tocchiamo una tracklist manuale: il completamento e' consentito
            // solo quando:
            //   - ora lo scanner vede piu' tracce di quelle presenti nel DB;
            //   - TUTTE le tracce gia' presenti trovano un match affidabile;
            //   - l'album e' marcato come scanner/review;
            //   - ogni traccia esistente ha gia' un audio external associato.
            //
            // Gli ID delle tracce gia' presenti vengono passati esplicitamente a
            // saveTracklist(), quindi non vengono cancellati ne' sganciati dagli
            // audio. Vengono inserite soltanto le tracce che mancavano.
            if (
                count($tracks) > count($existingTracks)
                && $this->canExtendScannerTracklist($albumId, $existingTracks, $trackMap)
            ) {
                $tl = [];
                foreach ($tracks as $idx => $t) {
                    $row = [
                        'title'    => $t['title'],
                        'duration' => $t['duration'],
                    ];

                    if (isset($trackMap[$idx])) {
                        $row['id'] = (int)$trackMap[$idx];
                    }

                    $tl[] = $row;
                }

                $trackModel->saveTracklist($albumId, $tl);
                $existingTracks = $this->uniqueTrackRows($trackModel->getByAlbum($albumId));
                $trackMap = $this->matchExistingTracks($tracks, $existingTracks);

                // Rimuove l'eventuale vecchia nota "N tracce non associate..."
                // riscrivendo la review corrente calcolata dai metadati.
                $this->setReview($albumId, $entry['review_note']);
            }

            $unmatched = 0;
            $deferredSources = [];

            foreach ($tracks as $idx => $t) {
                $trackId = isset($trackMap[$idx]) ? $trackMap[$idx] : null;
                if ($trackId === null) {
                    $unmatched++;
                    continue;
                }

                // Se il path e' invariato, importAudioFile gestisce skip/aggiornamento.
                // Se il path e' nuovo, la decisione non dipende piu' dal semplice
                // "trackHasAudio": distinguiamo managed, external ancora vivo e
                // external morto. Un external morto viene riallineato sul posto
                // riusando LO STESSO record e LO STESSO token pubblico.
                $sourceKnown = $this->audioSourcePathExists($importDir, $t['abs']);

                if (!$sourceKnown) {
                    $reconciled = $this->reconcileExistingTrackAudio(
                        $albumId,
                        $trackId,
                        $t['abs'],
                        $t['ext'],
                        $deferredSources
                    );

                    if ($reconciled === 'deferred') {
                        // Esiste ancora un external valido su un altro path. Non lo
                        // sostituiamo mentre e' raggiungibile: il worker manterra'
                        // questo album in riconciliazione e riprovera' dopo.
                        $entry['audio_deferred']++;
                        continue;
                    }

                    if ($reconciled !== null) {
                        $this->tallyAudio($entry, $reconciled);
                        continue;
                    }
                }

                $res = $this->importAudioFile($importDir, $albumId, $trackId, $t['abs'], $t['ext']);
                $this->tallyAudio($entry, $res);
            }

            $entry['deferred_sources'] = array_values(array_unique($deferredSources));

            if ($unmatched > 0) {
                $note = $unmatched . ' tracce non associate automaticamente alla tracklist esistente';
                $entry['review_note'] = $this->appendReviewText($entry['review_note'], $note);
                $this->setReview($albumId, $entry['review_note']);
            }

            return $entry;
        }

        // Album nuovo.
        $coverLocal = $externalCoverLocal !== null ? $externalCoverLocal : $this->storeCover($coverSource);
        $coverUrl   = $coverLocal === null ? $externalCoverUrl : null;

        $albumId = $albumModel->create([
            'artist_id'   => $artistId,
            'genre_id'    => $this->resolveOrCreateGenreId($tagGenre),
            'label_id'    => $this->resolveOrCreateLabelId($tagLabel),
            'format_id'   => $formatId,
            'title'       => $title,
            'year'        => $year,
            'condition'   => 'Very Good',
            'copies'      => 1,
            'notes'       => null,
            'cover_url'   => $coverUrl,
            'cover_local' => $coverLocal,
            'mbid'        => $externalMbid,
        ]);

        // Tabella ponte dei formati + marcatura revisione.
        $albumModel->syncScannerFormat($albumId, $formatId);
        $this->setReview($albumId, $reviewNote);

        // Tracklist (posizione = ordine calcolato).
        $tl = [];
        foreach ($tracks as $t) {
            $tl[] = ['title' => $t['title'], 'duration' => $t['duration']];
        }
        $trackModel->saveTracklist($albumId, $tl);

        // Rileggi le tracce salvate per mappare posizione => id traccia.
        $byPos = [];
        foreach ($trackModel->getByAlbum($albumId) as $row) {
            $byPos[(int)$row['position']] = (int)$row['id'];
        }

        $pos = 0;
        foreach ($tracks as $t) {
            $pos++;
            $trackId = isset($byPos[$pos]) ? $byPos[$pos] : null;
            $res = $this->importAudioFile($importDir, $albumId, $trackId, $t['abs'], $t['ext']);
            $this->tallyAudio($entry, $res);
        }

        $entry['status'] = 'created';
        return $entry;
    }

    // ==========================================================
    // FORMATO
    // ==========================================================

    /**
     * Risale dalla cartella dell'album verso la root di import e restituisce
     * l'id del primo formato antenato riconosciuto; 'Digital' come default.
     */
    private function resolveFormatId(string $albumDir, string $importDir): int
    {
        $map       = $this->formats();
        $importDir = rtrim($importDir, '/');
        $dir       = dirname($albumDir);

        while ($dir !== '' && $dir !== '.' && $dir !== $importDir
               && strpos($dir . '/', $importDir . '/') === 0) {
            $canon = $this->canonicalFormat(basename($dir));
            if ($canon !== null && isset($map[$canon])) {
                return $map[$canon];
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        if (isset($map['digital'])) {
            return $map['digital'];
        }
        $first = reset($map);
        return $first !== false ? (int)$first : 0;
    }

    /**
     * Nome cartella => nome-formato canonico normalizzato, se riconducibile a
     * un formato presente nella tabella `formats`. Null altrimenti.
     */
    private function canonicalFormat(string $name): ?string
    {
        $n = $this->norm($name);
        if ($n === '') {
            return null;
        }

        $map   = $this->formats();
        $alias = [
            'vinile' => 'vinile', 'vinili' => 'vinile', 'vinyl' => 'vinile', 'lp' => 'vinile',
            '12"' => 'vinile', '7"' => 'vinile', '45 giri' => 'vinile', '33 giri' => 'vinile',
            'cd' => 'cd', 'cds' => 'cd', 'compact disc' => 'cd',
            'musicassetta' => 'musicassetta', 'musicassette' => 'musicassetta',
            'cassetta' => 'musicassetta', 'cassette' => 'musicassetta', 'mc' => 'musicassetta', 'k7' => 'musicassetta',
            'tape' => 'tape', 'tapes' => 'tape',
            'digital' => 'digital', 'digitale' => 'digital', 'flac' => 'digital',
            'web' => 'digital', 'mp3' => 'digital', 'lossless' => 'digital',
        ];

        if (isset($alias[$n])) {
            $canon = $alias[$n];
            if (isset($map[$canon])) {
                return $canon;
            }
            // Famiglia cassetta: ripiega sull'etichetta che esiste nel DB.
            if ($canon === 'tape' || $canon === 'musicassetta') {
                if (isset($map['musicassetta'])) {
                    return 'musicassetta';
                }
                if (isset($map['tape'])) {
                    return 'tape';
                }
            }
            return null;
        }

        // Nome cartella che coincide direttamente con un formato del DB.
        return isset($map[$n]) ? $n : null;
    }

    private function formats(): array
    {
        if ($this->formatMap !== null) {
            return $this->formatMap;
        }
        $this->formatMap   = [];
        $this->formatNames = [];
        $rows = $this->db->query("SELECT id, name FROM formats")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $this->formatMap[$this->norm($r['name'])] = $id;
            $this->formatNames[$id] = (string)$r['name'];
        }
        return $this->formatMap;
    }

    private function formatNameById(int $id): string
    {
        $this->formats();
        return isset($this->formatNames[$id]) ? $this->formatNames[$id] : '';
    }

    // ==========================================================
    // GENERE / ETICHETTA
    // ==========================================================

    /**
     * Risolve un genere esistente oppure lo crea quando arriva dai tag/API.
     * Il form manuale supporta gia' la creazione di nuovi generi; lo scanner
     * deve avere lo stesso comportamento, altrimenti un tag valido viene perso.
     */
    private function resolveOrCreateGenreId(string $genreName): ?int
    {
        $raw = $this->clip(trim($genreName), 60);
        if ($raw === '') {
            return null;
        }

        $key = strtolower($raw);
        if (array_key_exists($key, $this->genreCache) && $this->genreCache[$key] !== null) {
            return $this->genreCache[$key];
        }

        $stmt = $this->db->prepare("SELECT id FROM genres WHERE LOWER(name) = LOWER(:n) LIMIT 1");
        $stmt->execute([':n' => $raw]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            $ins = $this->db->prepare("INSERT IGNORE INTO genres (name) VALUES (:n)");
            $ins->execute([':n' => $raw]);
            $stmt->execute([':n' => $raw]);
            $id = $stmt->fetchColumn();
        }

        $val = ($id !== false) ? (int)$id : null;
        $this->genreCache[$key] = $val;
        return $val;
    }

    /**
     * Risolve un'etichetta esistente oppure la crea quando arriva dai tag/API.
     */
    private function resolveOrCreateLabelId(string $labelName): ?int
    {
        $raw = $this->clip(trim($labelName), 100);
        if ($raw === '') {
            return null;
        }

        $key = strtolower($raw);
        if (array_key_exists($key, $this->labelCache) && $this->labelCache[$key] !== null) {
            return $this->labelCache[$key];
        }

        $stmt = $this->db->prepare("SELECT id FROM labels WHERE LOWER(name) = LOWER(:n) LIMIT 1");
        $stmt->execute([':n' => $raw]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            $ins = $this->db->prepare("INSERT IGNORE INTO labels (name) VALUES (:n)");
            $ins->execute([':n' => $raw]);
            $stmt->execute([':n' => $raw]);
            $id = $stmt->fetchColumn();
        }

        $val = ($id !== false) ? (int)$id : null;
        $this->labelCache[$key] = $val;
        return $val;
    }

    /**
     * Per album gia' presenti completa SOLO campi mancanti. Non sovrascrive
     * metadati curati manualmente.
     */
    private function maybeFillAlbumMetadataIfMissing(
        int $albumId,
        string $genreName,
        string $labelName,
        ?int $year,
        ?string $mbid
    ): void {
        $stmt = $this->db->prepare(
            "SELECT genre_id, label_id, year, mbid FROM albums WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $albumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $sets = [];
        $params = [':id' => $albumId];

        if (empty($row['genre_id']) && trim($genreName) !== '') {
            $genreId = $this->resolveOrCreateGenreId($genreName);
            if ($genreId !== null) {
                $sets[] = 'genre_id = :genre_id';
                $params[':genre_id'] = $genreId;
            }
        }

        if (empty($row['label_id']) && trim($labelName) !== '') {
            $labelId = $this->resolveOrCreateLabelId($labelName);
            if ($labelId !== null) {
                $sets[] = 'label_id = :label_id';
                $params[':label_id'] = $labelId;
            }
        }

        if (empty($row['year']) && $year !== null) {
            $sets[] = 'year = :year';
            $params[':year'] = $year;
        }

        if (empty($row['mbid']) && $mbid !== null && trim($mbid) !== '') {
            $sets[] = 'mbid = :mbid';
            $params[':mbid'] = trim($mbid);
        }

        if (!empty($sets)) {
            $sql = "UPDATE albums SET " . implode(', ', $sets) . " WHERE id = :id";
            $u = $this->db->prepare($sql);
            $u->execute($params);
        }
    }

    // ==========================================================
    // CARTELLE IGNORATE / FORMATO SCANNER
    // ==========================================================

    /**
     * Hash delle directory-album che l'utente ha eliminato volontariamente
     * dall'archivio lasciandole nella watched folder.
     *
     * @return array<string,bool>
     */
    private function ignoredAlbumPathHashes(): array
    {
        $out = [];
        $stmt = $this->db->query("SELECT path_hash FROM media_scan_ignored");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $hash) {
            $hash = trim((string)$hash);
            if ($hash !== '') {
                $out[$hash] = true;
            }
        }
        return $out;
    }


    /**
     * Associa le tracce scansionate alle tracce gia' presenti nell'album.
     * Prima prova posizione+titolo normalizzato, poi un titolo univoco.
     * La chiave del risultato e' l'indice zero-based di $scannedTracks.
     */
    private function matchExistingTracks(array $scannedTracks, array $existingTracks): array
    {
        $map = [];
        $byPosition = [];
        $byTitle = [];

        foreach ($existingTracks as $row) {
            $id = isset($row['id']) ? (int)$row['id'] : 0;
            if ($id <= 0) {
                continue;
            }

            $position = isset($row['position']) ? (int)$row['position'] : 0;
            if ($position > 0) {
                $byPosition[$position] = $row;
            }

            $titleKey = $this->normTrackTitle(isset($row['title']) ? (string)$row['title'] : '');
            if ($titleKey !== '') {
                if (!isset($byTitle[$titleKey])) {
                    $byTitle[$titleKey] = [];
                }
                $byTitle[$titleKey][] = $id;
            }
        }

        $sameTrackCount = count($scannedTracks) === count($existingTracks);
        $singleDisc = true;
        foreach ($scannedTracks as $track) {
            if (isset($track['disc']) && (int)$track['disc'] > 1) {
                $singleDisc = false;
                break;
            }
        }

        foreach ($scannedTracks as $idx => $track) {
            $position = $idx + 1;
            $scanTitle = $this->normTrackTitle(isset($track['title']) ? (string)$track['title'] : '');
            $scanTrackNum = isset($track['num']) ? (int)$track['num'] : 0;

            if (isset($byPosition[$position])) {
                $candidate = $byPosition[$position];
                $candidateTitle = $this->normTrackTitle(isset($candidate['title']) ? (string)$candidate['title'] : '');
                if ($scanTitle !== '' && $candidateTitle !== '' && $scanTitle === $candidateTitle) {
                    $map[$idx] = (int)$candidate['id'];
                    continue;
                }

                // Fallback conservativo: per album a disco singolo, con lo stesso
                // numero di tracce e numerazione esplicita 01..N, la posizione e'
                // sufficiente anche se il filename/tag contiene un suffisso extra
                // (es. "Cold or Hot [Disky Smashin]"). Questo evita track_id NULL
                // senza affidarsi a un fuzzy match sui titoli.
                if ($sameTrackCount && $singleDisc && $scanTrackNum > 0 && $scanTrackNum === $position) {
                    $map[$idx] = (int)$candidate['id'];
                    continue;
                }
            }

            if ($scanTitle !== '' && isset($byTitle[$scanTitle]) && count($byTitle[$scanTitle]) === 1) {
                $map[$idx] = (int)$byTitle[$scanTitle][0];
            }
        }

        return $map;
    }

    /**
     * Elimina eventuali duplicati prodotti dalla LEFT JOIN di Track::getByAlbum()
     * quando una traccia ha piu' record audio associati.
     *
     * @return array<int,array<string,mixed>>
     */
    private function uniqueTrackRows(array $rows): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int)$row['id'] : 0;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Stabilisce se una tracklist parziale puo' essere completata in automatico
     * senza rischiare di riscrivere una tracklist manuale.
     *
     * Condizioni conservative:
     *   - ogni traccia gia' presente deve essere stata riconosciuta nel nuovo scan;
     *   - l'album deve essere marcato needs_review dallo scanner;
     *   - ogni traccia esistente deve avere almeno un audio external associato.
     */
    private function canExtendScannerTracklist(int $albumId, array $existingTracks, array $trackMap): bool
    {
        if ($albumId <= 0 || empty($existingTracks) || empty($trackMap)) {
            return false;
        }

        $existingIds = [];
        foreach ($existingTracks as $row) {
            $id = isset($row['id']) ? (int)$row['id'] : 0;
            if ($id > 0) {
                $existingIds[$id] = true;
            }
        }

        if (empty($existingIds)) {
            return false;
        }

        $matchedIds = [];
        foreach ($trackMap as $trackId) {
            $trackId = (int)$trackId;
            if ($trackId > 0) {
                $matchedIds[$trackId] = true;
            }
        }

        // Se anche una sola traccia esistente non e' riconosciuta, fermarsi:
        // potrebbe trattarsi di una tracklist modificata manualmente.
        if (count($matchedIds) !== count($existingIds)) {
            return false;
        }

        foreach ($existingIds as $id => $_) {
            if (!isset($matchedIds[$id])) {
                return false;
            }
        }

        $stmt = $this->db->prepare("
            SELECT
                a.needs_review,
                COUNT(DISTINCT t.id) AS track_count,
                COUNT(DISTINCT CASE
                    WHEN af.storage_type = 'external'
                     AND af.source_path IS NOT NULL
                     AND af.source_path <> ''
                    THEN t.id
                END) AS external_track_count
            FROM albums a
            JOIN tracks t
              ON t.album_id = a.id
            LEFT JOIN audio_files af
              ON af.track_id = t.id
            WHERE a.id = :album_id
            GROUP BY a.id, a.needs_review
            LIMIT 1
        ");
        $stmt->execute([':album_id' => $albumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int)($row['needs_review'] ?? 0) !== 1) {
            return false;
        }

        $trackCount = (int)($row['track_count'] ?? 0);
        $externalTrackCount = (int)($row['external_track_count'] ?? 0);

        return $trackCount === count($existingIds)
            && $externalTrackCount === $trackCount;
    }

    private function normTrackTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }
        $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
        $title = str_replace(["\xE2\x80\x93", "\xE2\x80\x94", '_'], ' ', $title);
        $title = preg_replace('/[^\pL\pN]+/u', ' ', $title);
        $title = preg_replace('/\s+/u', ' ', (string)$title);
        return trim((string)$title);
    }

    /**
     * Cerca un album gia' collegato ad almeno uno dei file scansionati tramite
     * source_path. Se i path puntano a piu' album diversi non prende decisioni.
     *
     * @return array<string,mixed>|null
     */
    private function findExistingAlbumBySourcePaths(string $importDir, array $tracks): ?array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT af.album_id
            FROM audio_files af
            WHERE af.album_id IS NOT NULL
              AND af.source_path IS NOT NULL
              AND (af.source_path = :source_path OR af.source_path = :legacy_rel)
            LIMIT 2
        ");

        $albumIds = [];

        foreach ($tracks as $track) {
            if (empty($track['abs'])) {
                continue;
            }

            $srcAbs = (string)$track['abs'];
            $stmt->execute([
                ':source_path' => $this->sourcePathForDb($srcAbs),
                ':legacy_rel'  => $this->relPath($importDir, $srcAbs),
            ]);

            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $albumId) {
                $albumId = (int)$albumId;
                if ($albumId > 0) {
                    $albumIds[$albumId] = true;
                }
            }

            if (count($albumIds) > 1) {
                return null;
            }
        }

        if (count($albumIds) !== 1) {
            return null;
        }

        $albumId = (int)array_key_first($albumIds);
        $albumStmt = $this->db->prepare("
            SELECT a.id, a.artist_id, a.title, a.year, ar.name AS artist_name
            FROM albums a
            JOIN artists ar ON ar.id = a.artist_id
            WHERE a.id = :id
            LIMIT 1
        ");
        $albumStmt->execute([':id' => $albumId]);
        $row = $albumStmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function audioSourcePathExists(string $importDir, string $srcAbs): bool
    {
        $sourcePath = $this->sourcePathForDb($srcAbs);
        $legacyRel  = $this->relPath($importDir, $srcAbs);

        $stmt = $this->db->prepare("
            SELECT id
            FROM audio_files
            WHERE source_path = :source_path
               OR source_path = :legacy_rel
            LIMIT 1
        ");
        $stmt->execute([
            ':source_path' => $sourcePath,
            ':legacy_rel'  => $legacyRel,
        ]);
        return $stmt->fetchColumn() !== false;
    }


    /**
     * Gestisce un nuovo candidato audio per una traccia gia' esistente.
     *
     * Regole:
     *   - un audio managed ha sempre priorita' e non viene toccato;
     *   - se esiste un external ancora raggiungibile su un altro path, la
     *     decisione viene rinviata: il worker riprovera' finche' la situazione
     *     non si stabilizza;
     *   - se gli external precedenti non esistono piu', il candidato viene
     *     associato allo stesso record audio, anche quando l'hash e' cambiato
     *     (caso tipico: file indicizzato mentre era ancora in download);
     *   - dopo un rebind riuscito, eventuali altri external morti della stessa
     *     traccia vengono rimossi dal DB, lasciando un solo riferimento attivo.
     *
     * Il token pubblico filename resta invariato durante il rebind: identifica
     * il record audio, non la sua posizione sul filesystem.
     *
     * @return string|null 'imported' | 'skipped' | 'deferred' | null
     */
    private function reconcileExistingTrackAudio(
        int $albumId,
        int $trackId,
        string $srcAbs,
        string $ext,
        array &$deferredSources
    ): ?string {
        $stmt = $this->db->prepare("
            SELECT id, album_id, track_id, filename, original_name, filesize,
                   source_hash, source_path, source_mtime, storage_type
            FROM audio_files
            WHERE track_id = :track_id
            ORDER BY id DESC
        ");
        $stmt->execute([':track_id' => $trackId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return null;
        }

        $hasManaged   = false;
        $externalRows = [];
        $liveRows     = [];
        $deadRows     = [];

        foreach ($rows as $row) {
            if (($row['storage_type'] ?? 'managed') !== 'external') {
                $hasManaged = true;
                continue;
            }

            // Non toccare record external appartenenti ad altri album: un track_id
            // coerente dovrebbe gia' implicare lo stesso album, ma manteniamo una
            // guardia esplicita contro dati storici incoerenti.
            if ((int)($row['album_id'] ?? 0) !== $albumId) {
                continue;
            }

            $externalRows[] = $row;

            $oldDbPath = trim((string)($row['source_path'] ?? ''));
            $runtime   = $oldDbPath !== '' ? $this->sourcePathForRuntime($oldDbPath) : '';

            if ($runtime !== '' && is_file($runtime) && is_readable($runtime)) {
                $liveRows[] = $row;
            } else {
                $deadRows[] = $row;
            }
        }

        // Upload/manuale managed: priorita' assoluta. Non modifichiamo ne'
        // eliminiamo nessun record audio esistente: comportamento originale.
        if ($hasManaged) {
            return 'skipped';
        }

        if (empty($externalRows)) {
            return null;
        }

        // Finche' un external precedente e' realmente disponibile non assumiamo
        // che il nuovo path sia un move: potrebbe essere una seconda copia valida.
        // Il worker manterra' il nuovo candidato in stato deferred e riprovera'.
        if (!empty($liveRows)) {
            foreach ($liveRows as $row) {
                $waitPath = trim((string)($row['source_path'] ?? ''));
                if ($waitPath !== '') {
                    $deferredSources[] = $waitPath;
                }
            }
            return 'deferred';
        }

        if (empty($deadRows)) {
            return null;
        }

        $newSourcePath = $this->sourcePathForDb($srcAbs);
        $newHash       = @sha1_file($srcAbs);
        $newHash       = ($newHash === false) ? null : $newHash;

        // Se tra i record morti esiste un fingerprint identico lo preferiamo.
        // Altrimenti, dato che tutti i record appartengono alla stessa track_id,
        // sono external e nessuno e' piu' raggiungibile, riusiamo il piu' recente.
        $chosen = null;
        if ($newHash !== null && $newHash !== '') {
            foreach ($deadRows as $row) {
                $oldHash = trim((string)($row['source_hash'] ?? ''));
                if ($oldHash !== '' && hash_equals($oldHash, $newHash)) {
                    $chosen = $row;
                    break;
                }
            }
        }
        if ($chosen === null) {
            $chosen = $deadRows[0]; // ORDER BY id DESC
        }

        $size  = @filesize($srcAbs);
        $size  = ($size === false) ? null : (int)$size;
        $mtime = @filemtime($srcAbs);
        $mtime = ($mtime === false) ? null : (int)$mtime;

        $cleanExt = preg_replace('/[^a-z0-9]+/', '', strtolower($ext));
        if ($cleanExt === '') {
            return null;
        }

        $chosenId = (int)$chosen['id'];
        $token    = trim((string)($chosen['filename'] ?? ''));
        if ($token === '') {
            // Fallback solo per record storici anomali: token stabile basato
            // sull'id DB, non sul path corrente.
            $token = 'external-' . sha1('audio:' . $chosenId) . '.' . $cleanExt;
        }

        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare("
                UPDATE audio_files
                SET album_id = :album_id,
                    track_id = :track_id,
                    filename = :filename,
                    original_name = :original_name,
                    filesize = :filesize,
                    source_hash = :source_hash,
                    source_path = :source_path,
                    source_mtime = :source_mtime,
                    storage_type = 'external'
                WHERE id = :id
                  AND storage_type = 'external'
            ");
            $upd->execute([
                ':album_id'      => $albumId,
                ':track_id'      => $trackId,
                ':filename'      => $token,
                ':original_name' => basename($srcAbs),
                ':filesize'      => $size,
                ':source_hash'   => $newHash,
                ':source_path'   => $newSourcePath,
                ':source_mtime'  => $mtime,
                ':id'            => $chosenId,
            ]);

            // Tutti gli altri record external della stessa traccia sono morti:
            // dopo il rebind non devono restare riferimenti 404 duplicati.
            $staleIds = [];
            foreach ($deadRows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0 && $id !== $chosenId) {
                    $staleIds[] = $id;
                }
            }

            if (!empty($staleIds)) {
                $placeholders = implode(',', array_fill(0, count($staleIds), '?'));
                $del = $this->db->prepare("DELETE FROM audio_files WHERE storage_type = 'external' AND id IN ($placeholders)");
                $del->execute($staleIds);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return 'imported';
    }

    /**
     * Converte un source_path persistito (path host) nel path visibile al
     * processo corrente. In MAMP resta invariato; in Docker viene prefissato
     * con MEDIA_SCAN_HOST_PREFIX (es. /hostfs).
     */
    private function sourcePathForRuntime(string $dbPath): string
    {
        $dbPath = str_replace('\\', '/', trim($dbPath));
        if ($dbPath === '') {
            return '';
        }

        $prefix = trim((string)getenv('MEDIA_SCAN_HOST_PREFIX'));
        if ($prefix === '') {
            return $dbPath;
        }

        $prefix = rtrim(str_replace('\\', '/', $prefix), '/');

        if ($dbPath === '/') {
            return $prefix;
        }

        if (strpos($dbPath, '/') === 0) {
            return $prefix . $dbPath;
        }

        return $prefix . '/' . $dbPath;
    }

    /**
     * Path persistito per i nuovi external.
     *
     * In locale/MAMP coincide con il path assoluto reale.
     * In Docker il worker vede /hostfs/... ma nel DB salviamo il path host
     * (/storage/...), così il record non dipende dal mount interno del container.
     */
    private function sourcePathForDb(string $runtimePath): string
    {
        $real = realpath($runtimePath);
        $path = $real !== false ? $real : $runtimePath;
        $path = str_replace('\\', '/', $path);

        $prefix = trim((string)getenv('MEDIA_SCAN_HOST_PREFIX'));
        if ($prefix === '') {
            return $path;
        }

        $prefix = rtrim(str_replace('\\', '/', $prefix), '/');

        if ($path === $prefix) {
            return '/';
        }

        if (strpos($path, $prefix . '/') === 0) {
            $hostPath = substr($path, strlen($prefix));
            return $hostPath === '' ? '/' : $hostPath;
        }

        return $path;
    }


    private function appendReviewText(string $current, string $extra): string
    {
        $current = trim($current);
        $extra   = trim($extra);
        if ($extra === '') {
            return $current;
        }
        if ($current === '') {
            return $this->clip($extra, 255);
        }
        return $this->clip($current . '; ' . $extra, 255);
    }

    // ==========================================================
    // REVISIONE
    // ==========================================================

    private function setReview(int $albumId, string $note): void
    {
        $stmt = $this->db->prepare("UPDATE albums SET needs_review = 1, review_note = :n WHERE id = :id");
        $stmt->execute([
            ':n'  => ($note === '' ? null : $note),
            ':id' => $albumId,
        ]);
    }

    // ==========================================================
    // COVER
    // ==========================================================

    /**
     * Sceglie la cover: prima un file di cartella con nome noto (cover/folder/
     * front/...), poi la cover incorporata nei tag, poi una qualsiasi immagine.
     */
    private function pickCover(array $parts, ?array $embedded): array
    {
        $named = [];
        $any   = [];
        foreach ($parts as $p) {
            foreach ($this->listByExt($p['dir'], self::IMAGE_EXT) as $img) {
                $base = strtolower(pathinfo($img, PATHINFO_FILENAME));
                if (preg_match('/^(cover|folder|front|artwork|albumart|album)/', $base)) {
                    $named[] = $img;
                } else {
                    $any[] = $img;
                }
            }
        }

        if (!empty($named)) {
            sort($named);
            return ['type' => 'file', 'path' => $named[0]];
        }
        if ($embedded !== null) {
            return ['type' => 'embedded', 'data' => $embedded['data'], 'ext' => $embedded['ext']];
        }
        if (!empty($any)) {
            sort($any);
            return ['type' => 'file', 'path' => $any[0]];
        }
        return ['type' => 'none'];
    }

    /**
     * Salva la cover scelta in COVERS_PATH con nome casuale. Ritorna il path
     * relativo a public/uploads (covers/nomefile), stessa convenzione usata
     * da AlbumMetadataService e dalle view.
     */
    private function storeCover(array $cover): ?string
    {
        if ($cover['type'] === 'none') {
            return null;
        }
        if (!is_dir(COVERS_PATH) && !@mkdir(COVERS_PATH, 0755, true)) {
            return null;
        }

        if ($cover['type'] === 'file') {
            $ext = strtolower(pathinfo($cover['path'], PATHINFO_EXTENSION));
            if (!in_array($ext, self::IMAGE_EXT, true)) {
                $ext = 'jpg';
            }
            $name = bin2hex(random_bytes(10)) . '.' . $ext;
            return @copy($cover['path'], COVERS_PATH . '/' . $name) ? ('covers/' . $name) : null;
        }

        // embedded
        $ext  = $cover['ext'] !== '' ? $cover['ext'] : 'jpg';
        $name = bin2hex(random_bytes(10)) . '.' . $ext;
        return (@file_put_contents(COVERS_PATH . '/' . $name, $cover['data']) !== false) ? ('covers/' . $name) : null;
    }

    /**
     * Imposta la cover su un album esistente solo se non ne ha gia' una.
     * Ripara anche i record creati dalle prime versioni dello scanner che
     * salvarono soltanto il filename invece di "covers/filename".
     */
    private function maybeSetCoverIfMissing(
        int $albumId,
        array $cover,
        ?string $externalCoverLocal = null,
        ?string $externalCoverUrl = null
    ): void {
        $stmt = $this->db->prepare("SELECT cover_local, cover_url FROM albums WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $albumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $existingLocal = trim((string)($row['cover_local'] ?? ''));
        $existingUrl   = trim((string)($row['cover_url'] ?? ''));

        // Compatibilita' con le prime importazioni scanner:
        // DB = "abc.jpg", file fisico = public/uploads/covers/abc.jpg.
        if ($existingLocal !== '' && strpos($existingLocal, '/') === false) {
            $candidate = COVERS_PATH . '/' . basename($existingLocal);
            if (is_file($candidate)) {
                $fixed = 'covers/' . basename($existingLocal);
                $u = $this->db->prepare("UPDATE albums SET cover_local = :c WHERE id = :id");
                $u->execute([':c' => $fixed, ':id' => $albumId]);
                return;
            }
        }

        if ($existingLocal !== '' || $existingUrl !== '') {
            return;
        }

        $local = null;

        if ($externalCoverLocal !== null && trim($externalCoverLocal) !== '') {
            $local = trim($externalCoverLocal);
        } elseif ($cover['type'] !== 'none') {
            $local = $this->storeCover($cover);
        }

        if ($local !== null) {
            $u = $this->db->prepare("UPDATE albums SET cover_local = :c, cover_url = NULL WHERE id = :id");
            $u->execute([':c' => $local, ':id' => $albumId]);
            return;
        }

        if ($externalCoverUrl !== null && trim($externalCoverUrl) !== '') {
            $u = $this->db->prepare("UPDATE albums SET cover_url = :u WHERE id = :id");
            $u->execute([':u' => trim($externalCoverUrl), ':id' => $albumId]);
        }
    }

    /**
     * Usa lo stesso servizio metadati del form manuale come fallback.
     * Nessuna eccezione esterna deve bloccare l'import dell'album.
     */
    private function fetchExternalMetadata(
        string $artist,
        string $title,
        ?int $year,
        bool $needCover,
        bool $allowDownload
    ): array {
        try {
            if (!class_exists('AlbumMetadataService')) {
                $file = BASE_PATH . '/app/services/AlbumMetadataService.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }

            if (!class_exists('AlbumMetadataService')) {
                return [];
            }

            $service = new AlbumMetadataService();
            $data = $service->search($artist, $title, $year === null ? 0 : $year);

            if (!is_array($data)) {
                return [];
            }

            if (
                $needCover
                && $allowDownload
                && empty($data['cover_local'])
                && !empty($data['cover'])
            ) {
                $local = $service->downloadCover((string)$data['cover']);
                if ($local !== null) {
                    $data['cover_local'] = $local;
                }
            }

            return $data;
        } catch (Throwable $e) {
            return [];
        }
    }

    // ==========================================================
    // IMPORT AUDIO (idempotente)
    // ==========================================================

    /**
     * Importa un file audio nella cartella audio (piatta, come l'upload) e ne
     * registra il record con le chiavi di idempotenza.
     *
     * @return string 'imported' | 'skipped' | 'error'
     */
    private function importAudioFile(string $importDir, int $albumId, ?int $trackId, string $srcAbs, string $ext): string
    {
        $sourcePath = $this->sourcePathForDb($srcAbs);
        $legacyRel  = $this->relPath($importDir, $srcAbs);

        $size  = @filesize($srcAbs);
        $size  = ($size === false) ? null : (int)$size;
        $mtime = @filemtime($srcAbs);
        $mtime = ($mtime === false) ? null : (int)$mtime;

        // Cerca prima il nuovo path assoluto host e, come fallback, il vecchio
        // path relativo. In questo modo i 95 import scanner storici restano
        // managed e continuano a essere riconosciuti senza migrazione.
        $stmt = $this->db->prepare("
            SELECT id, album_id, track_id, filename, filesize,
                   source_hash, source_mtime, source_path, storage_type
            FROM audio_files
            WHERE source_path = :source_path
               OR source_path = :legacy_rel
            ORDER BY CASE WHEN source_path = :source_path_order THEN 0 ELSE 1 END, id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':source_path'       => $sourcePath,
            ':legacy_rel'        => $legacyRel,
            ':source_path_order' => $sourcePath,
        ]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        // Skip economico: stesso path + mtime + dimensione.
        if ($existing && $size !== null && $mtime !== null
            && (int)$existing['source_mtime'] === $mtime
            && (int)$existing['filesize'] === $size) {
            if ($trackId !== null && empty($existing['track_id'])) {
                $stmt = $this->db->prepare("
                    UPDATE audio_files
                    SET album_id = :album_id,
                        track_id = :track_id
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':album_id' => $albumId,
                    ':track_id' => $trackId,
                    ':id'       => (int)$existing['id'],
                ]);
            }
            return 'skipped';
        }

        // L'hash resta esattamente un fingerprint diagnostico/di variazione.
        // Non viene introdotta alcuna nuova deduplica globale in questa fase.
        $hash = @sha1_file($srcAbs);
        $hash = ($hash === false) ? null : $hash;

        if ($existing) {
            $storageType = (string)($existing['storage_type'] ?? 'managed');

            // Timestamp/metadata filesystem cambiati ma contenuto identico.
            if ($hash !== null && !empty($existing['source_hash'])
                && hash_equals((string)$existing['source_hash'], $hash)) {
                $stmt = $this->db->prepare("
                    UPDATE audio_files
                    SET source_mtime = :m,
                        filesize = :s,
                        original_name = :o,
                        track_id = COALESCE(track_id, :track_id)
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':m'        => $mtime,
                    ':s'        => $size,
                    ':o'        => basename($srcAbs),
                    ':track_id' => $trackId,
                    ':id'       => (int)$existing['id'],
                ]);
                return 'skipped';
            }

            if ($storageType === 'external') {
                // External: il file resta nella watched folder. Se il contenuto
                // cambia, aggiorniamo solo fingerprint e metadata DB.
                $stmt = $this->db->prepare("
                    UPDATE audio_files
                    SET album_id = :album_id,
                        track_id = COALESCE(track_id, :track_id),
                        original_name = :original_name,
                        filesize = :filesize,
                        source_hash = :source_hash,
                        source_path = :source_path,
                        source_mtime = :source_mtime
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':album_id'      => $albumId,
                    ':track_id'      => $trackId,
                    ':original_name' => basename($srcAbs),
                    ':filesize'      => $size,
                    ':source_hash'   => $hash,
                    ':source_path'   => $sourcePath,
                    ':source_mtime'  => $mtime,
                    ':id'            => (int)$existing['id'],
                ]);

                return 'imported';
            }

            // Legacy managed: conserva il comportamento storico. Un file che
            // era già stato copiato dentro Grizzly resta managed anche dopo
            // l'aggiornamento, senza conversioni implicite.
            $audioDir = MediaPathResolver::getAudioDir();
            if (!is_dir($audioDir) && !@mkdir($audioDir, 0755, true)) {
                return 'error';
            }

            $ext = preg_replace('/[^a-z0-9]+/', '', strtolower($ext));
            if ($ext === '') {
                $ext = 'bin';
            }

            $stored = bin2hex(random_bytes(10)) . '.' . $ext;
            $dest   = $audioDir . '/' . $stored;
            if (!@copy($srcAbs, $dest)) {
                return 'error';
            }

            $oldFilename = isset($existing['filename']) ? (string)$existing['filename'] : '';

            try {
                $stmt = $this->db->prepare("
                    UPDATE audio_files
                    SET album_id = :album_id,
                        track_id = COALESCE(track_id, :track_id),
                        filename = :filename,
                        original_name = :original_name,
                        filesize = :filesize,
                        source_hash = :source_hash,
                        source_mtime = :source_mtime,
                        storage_type = 'managed'
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':album_id'      => $albumId,
                    ':track_id'      => $trackId,
                    ':filename'      => $stored,
                    ':original_name' => basename($srcAbs),
                    ':filesize'      => $size,
                    ':source_hash'   => $hash,
                    ':source_mtime'  => $mtime,
                    ':id'            => (int)$existing['id'],
                ]);
            } catch (Throwable $e) {
                @unlink($dest);
                throw $e;
            }

            if ($oldFilename !== '' && $oldFilename !== $stored) {
                @unlink($audioDir . '/' . basename($oldFilename));
            }

            return 'imported';
        }

        // Nuovo file scannerizzato: INDICIZZA SUL POSTO.
        // Non crea alcuna copia in public/uploads/audio.
        $ext = preg_replace('/[^a-z0-9]+/', '', strtolower($ext));
        if ($ext === '') {
            return 'error';
        }

        $stored = 'external-' . sha1($sourcePath) . '.' . $ext;

        $stmt = $this->db->prepare("
            INSERT INTO audio_files
                (album_id, track_id, filename, original_name, filesize,
                 source_hash, source_path, source_mtime, storage_type)
            VALUES
                (:album_id, :track_id, :filename, :original_name, :filesize,
                 :source_hash, :source_path, :source_mtime, 'external')
        ");
        $stmt->execute([
            ':album_id'      => $albumId,
            ':track_id'      => $trackId,
            ':filename'      => $stored,
            ':original_name' => basename($srcAbs),
            ':filesize'      => $size,
            ':source_hash'   => $hash,
            ':source_path'   => $sourcePath,
            ':source_mtime'  => $mtime,
        ]);

        return 'imported';
    }

    /**
     * Stato read-only di un file audio per il dry-run: 'present' | 'new'.
     * L'identita' e' source_path; l'hash NON rende globalmente duplicati due
     * file uguali collocati in album diversi.
     */
    private function audioState(string $importDir, string $abs): string
    {
        $sourcePath = $this->sourcePathForDb($abs);
        $legacyRel  = $this->relPath($importDir, $abs);

        $size  = @filesize($abs);
        $size  = ($size === false) ? null : (int)$size;
        $mtime = @filemtime($abs);
        $mtime = ($mtime === false) ? null : (int)$mtime;

        if ($size !== null && $mtime !== null) {
            $stmt = $this->db->prepare("
                SELECT id
                FROM audio_files
                WHERE (source_path = :source_path OR source_path = :legacy_rel)
                  AND source_mtime = :m
                  AND filesize = :s
                LIMIT 1
            ");
            $stmt->execute([
                ':source_path' => $sourcePath,
                ':legacy_rel'  => $legacyRel,
                ':m'           => $mtime,
                ':s'           => $size,
            ]);
            if ($stmt->fetchColumn()) {
                return 'present';
            }
        }

        return 'new';
    }

    private function tallyAudio(array &$entry, string $res): void
    {
        if ($res === 'imported') {
            $entry['audio_imported']++;
        } elseif ($res === 'skipped') {
            $entry['audio_skipped']++;
        } else {
            $entry['errors'][] = 'Indicizzazione audio fallita.';
        }
    }

    // ==========================================================
    // LETTURA TAG (getID3)
    // ==========================================================

    private function analyze(string $path): array
    {
        $out = [
            'artist'      => '',
            'albumartist' => '',
            'album'       => '',
            'title'       => '',
            'genre'       => '',
            'label'       => '',
            'year'        => null,
            'track'       => null,
            'disc'        => null,
            'duration'    => null,
            'picture'     => null,
        ];

        if (!$this->id3) {
            return $out;
        }

        try {
            $info = $this->id3->analyze($path);
        } catch (Throwable $e) {
            return $out;
        }
        if (!is_array($info)) {
            return $out;
        }

        // getID3 espone i tag grezzi in $info['tags']; questa helper li
        // normalizza in $info['comments'], che e' la struttura letta sotto.
        if (class_exists('getid3_lib')) {
            getid3_lib::CopyTagsToComments($info);
        }

        $c = (isset($info['comments']) && is_array($info['comments'])) ? $info['comments'] : [];

        $out['artist']      = $this->firstComment($c, ['artist']);
        $out['albumartist'] = $this->firstComment($c, ['albumartist', 'album_artist', 'band', 'ensemble']);
        $out['album']       = $this->firstComment($c, ['album']);
        $out['title']       = $this->firstComment($c, ['title']);
        $out['genre']       = $this->firstComment($c, ['genre']);
        $out['label']       = $this->firstComment($c, ['label', 'publisher', 'organization', 'record_label']);

        $out['year']  = $this->parseYear($this->firstComment($c, ['date', 'year', 'originaldate', 'original_year', 'creation_date', 'recording_time']));
        $out['track'] = $this->parseLeadingInt($this->firstComment($c, ['track_number', 'tracknumber', 'track']));
        $out['disc']  = $this->parseLeadingInt($this->firstComment($c, ['part_of_a_set', 'discnumber', 'disc_number', 'disc']));

        if (isset($info['playtime_seconds']) && $info['playtime_seconds'] > 0) {
            $d = (int)round($info['playtime_seconds']);
            $out['duration'] = $d > 65535 ? 65535 : $d; // colonna duration_sec smallint
        }

        if (isset($c['picture'][0]['data']) && $c['picture'][0]['data'] !== '') {
            $mime = isset($c['picture'][0]['image_mime']) ? (string)$c['picture'][0]['image_mime'] : '';
            $out['picture'] = ['data' => $c['picture'][0]['data'], 'ext' => $this->extFromMime($mime)];
        }

        return $out;
    }

    private function firstComment(array $comments, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($comments[$k])) {
                $v = $comments[$k];
                if (is_array($v)) {
                    $v = reset($v);
                }
                $v = trim((string)$v);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return '';
    }

    // ==========================================================
    // PARSING NOMI E UTILITY
    // ==========================================================

    private function parseYear(string $raw): ?int
    {
        if ($raw !== '' && preg_match('/(19|20)\d{2}/', $raw, $m)) {
            return (int)$m[0];
        }
        return null;
    }

    private function parseLeadingInt(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw !== '' && preg_match('/^\d+/', $raw, $m)) {
            return (int)$m[0];
        }
        return null;
    }

    private function trackNumberFromFilename(string $name): ?int
    {
        if (preg_match('/^\s*(\d{1,3})\b/', $name, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    private function titleFromFilename(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        // Togli numero traccia iniziale e separatori: "08 - Titolo", "08. Titolo", "08_Titolo".
        $base = preg_replace('/^\s*\d{1,3}\s*[-_.)\]]*\s*/u', '', $base);
        $base = str_replace('_', ' ', $base);
        $base = preg_replace('/\s+/u', ' ', $base);
        return trim((string)$base);
    }

    /**
     * Deduce artista/titolo/anno dal nome cartella. Best-effort, usato come
     * fallback quando i tag mancano.
     */
    private function parseFolderName(string $name): array
    {
        $out = ['artist' => '', 'title' => '', 'year' => null];

        // en-dash / em-dash => trattino semplice
        $s = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $name);
        $s = trim($s);

        // Anno: primo token 19xx/20xx, tra parentesi/graffe o isolato.
        if (preg_match('/[\(\[\{]?\b(19|20)\d{2}\b[\)\]\}]?/', $s, $m)) {
            $out['year'] = (int)preg_replace('/\D/', '', $m[0]);
            $s = trim(str_replace($m[0], ' ', $s));
        }

        $s = trim(preg_replace('/\s+/u', ' ', $s));

        // "Artista - Titolo" sul primo " - ".
        $parts = preg_split('/\s+-\s+/u', $s, 2);
        if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
            $out['artist'] = trim($parts[0]);
            $out['title']  = trim($parts[1]);
        } else {
            $out['title'] = trim($s);
        }

        // Ripulisci code tipo "[FLAC]", "{Label 123}" e trattini/spazi residui.
        $out['artist'] = $this->cleanFolderPiece($out['artist']);
        $out['title']  = $this->cleanFolderPiece($out['title']);

        return $out;
    }

    private function cleanFolderPiece(string $s): string
    {
        $s = preg_replace('/[\[\{\(][^\]\}\)]*[\]\}\)]/u', ' ', $s);
        $s = preg_replace('/^[.\-_\s]+/u', '', $s);
        $s = preg_replace('/[.\-_\s]+$/u', '', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string)$s);
    }

    private function relPath(string $importDir, string $abs): string
    {
        $importDir = rtrim(str_replace('\\', '/', $importDir), '/') . '/';
        $abs       = str_replace('\\', '/', $abs);
        if (strpos($abs, $importDir) === 0) {
            return substr($abs, strlen($importDir));
        }
        return basename($abs);
    }

    private function extFromMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        if (strpos($mime, 'png') !== false) {
            return 'png';
        }
        if (strpos($mime, 'webp') !== false) {
            return 'webp';
        }
        if (strpos($mime, 'gif') !== false) {
            return 'gif';
        }
        return 'jpg';
    }

    private function norm(string $s): string
    {
        $s = trim($s);
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string)$s);
    }

    private function clip(string $s, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $max, 'UTF-8');
        }
        return substr($s, 0, $max);
    }

    // ==========================================================
    // getID3
    // ==========================================================

    /**
     * Carica getID3 se presente. Ritorna un'istanza oppure null.
     * Cerca la libreria in alcune posizioni note del repo.
     */
    private function loadGetID3()
    {
        if (class_exists('getID3')) {
            return new getID3();
        }

        $candidates = [
            BASE_PATH . '/app/lib/getid3/getid3/getid3.php',
            BASE_PATH . '/app/lib/getID3/getid3/getid3.php',
            BASE_PATH . '/lib/getid3/getid3/getid3.php',
            BASE_PATH . '/vendor/james-heinrich/getid3/getid3/getid3.php',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                require_once $path;
                if (class_exists('getID3')) {
                    return new getID3();
                }
            }
        }
        return null;
    }
}

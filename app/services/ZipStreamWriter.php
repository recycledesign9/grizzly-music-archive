<?php

/**
 * ZipStreamWriter
 * ------------------------------------------------------------
 * Genera un archivio ZIP scrivendolo direttamente sulla risposta HTTP,
 * senza file temporanei e senza dipendenze (niente ext-zip, niente
 * Composer). Usato da PlaylistController::download().
 *
 * Scelte di formato:
 * - Metodo STORE (nessuna compressione): FLAC e MP3 sono già compressi,
 *   deflate ridurrebbe il peso di pochi punti percentuali al costo di
 *   molta CPU.
 * - Il CRC32 di ogni file viene calcolato subito prima di scriverne
 *   l'intestazione locale. Così l'archivio non usa i "data descriptor"
 *   (bit 3 dei flag), che l'Utility Archivio di macOS gestisce male
 *   negli ZIP generati in streaming. Il file viene quindi letto due
 *   volte, ma la seconda lettura arriva quasi sempre dalla cache del
 *   sistema operativo.
 * - Con file non compressi la dimensione finale è nota prima di
 *   iniziare: la risposta ha un Content-Length esatto e il browser
 *   mostra avanzamento e tempo residuo.
 * - Nomi in UTF-8 (bit 11 dei flag) e attributi Unix 0644/0755.
 * - ZIP64 automatico quando un file supera i 4 GB, quando un offset
 *   supera i 4 GB o quando le voci sono 65535 o più.
 *
 * Su PHP a 32 bit gli archivi oltre 2 GB vengono rifiutati prima di
 * inviare qualsiasi byte (gli interi non bastano per gli offset).
 *
 * Compatibile PHP 7.4.
 *
 * Posizionare in: app/services/ZipStreamWriter.php
 */
class ZipStreamWriter
{
    private const SIG_LOCAL         = 0x04034b50;
    private const SIG_CENTRAL       = 0x02014b50;
    private const SIG_EOCD          = 0x06054b50;
    private const SIG_ZIP64_EOCD    = 0x06064b50;
    private const SIG_ZIP64_LOCATOR = 0x07064b50;

    /** Bit 11: nome della voce in UTF-8 */
    private const FLAG_UTF8 = 0x0800;

    private const VERSION_DEFAULT = 20;
    private const VERSION_ZIP64   = 45;

    /** Byte alto di "version made by": 3 = Unix (attributi st_mode) */
    private const MADE_BY_UNIX = 0x0300;

    /** Valori "saturati" che rimandano ai campi ZIP64 */
    private const SENTINEL_32 = 0xFFFFFFFF;
    private const SENTINEL_16 = 0xFFFF;

    /**
     * Soglie oltre le quali servono i campi ZIP64. Protected perché un
     * test può abbassarle in una sottoclasse e verificare i record ZIP64
     * senza generare archivi da 4 GB.
     */
    protected const LIMIT_32 = 0xFFFFFFFF;
    protected const LIMIT_16 = 0xFFFF;

    /** Blocco di lettura/scrittura dei file audio */
    private const CHUNK = 262144;

    /**
     * Voci nell'ordine di scrittura.
     * type: 'dir' | 'file' | 'string'
     *
     * @var array<int, array{type: string, name: string, path: string, data: string, size: int, mtime: int}>
     */
    private array $entries = [];

    /** Byte effettivamente inviati, confrontati con il Content-Length */
    private int $written = 0;

    // ----------------------------------------------------------
    // Costruzione dell'elenco voci
    // ----------------------------------------------------------

    /**
     * Aggiunge una cartella. Il nome deve terminare con "/".
     */
    public function addDirectory(string $name): void
    {
        $name = rtrim($name, '/') . '/';

        $this->entries[] = [
            'type'  => 'dir',
            'name'  => $name,
            'path'  => '',
            'data'  => '',
            'size'  => 0,
            'mtime' => time(),
        ];
    }

    /**
     * Aggiunge un file dal filesystem. Restituisce false se il file non
     * è leggibile: la voce in quel caso non viene aggiunta.
     */
    public function addFile(string $name, string $path): bool
    {
        clearstatcache(true, $path);

        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $size = @filesize($path);
        if ($size === false) {
            return false;
        }

        $mtime = @filemtime($path);

        $this->entries[] = [
            'type'  => 'file',
            'name'  => $name,
            'path'  => $path,
            'data'  => '',
            'size'  => (int)$size,
            'mtime' => $mtime === false ? time() : (int)$mtime,
        ];

        return true;
    }

    /**
     * Aggiunge un file con contenuto in memoria (es. la playlist .m3u8).
     */
    public function addString(string $name, string $data): void
    {
        $this->entries[] = [
            'type'  => 'string',
            'name'  => $name,
            'path'  => '',
            'data'  => $data,
            'size'  => strlen($data),
            'mtime' => time(),
        ];
    }

    // ----------------------------------------------------------
    // Invio
    // ----------------------------------------------------------

    /**
     * Invia intestazioni HTTP e archivio.
     *
     * Lancia RuntimeException solo PRIMA di inviare le intestazioni,
     * quindi il chiamante può ancora rispondere con un errore.
     * Restituisce false se l'invio si interrompe a metà (client
     * disconnesso, file sparito o modificato): a quel punto la risposta
     * è già partita e il browser segnala il download come fallito,
     * perché i byte ricevuti non raggiungono il Content-Length.
     */
    public function send(string $downloadName): bool
    {
        if (empty($this->entries)) {
            throw new RuntimeException('Archivio vuoto.');
        }

        $layout = $this->computeLayout();

        if (PHP_INT_SIZE < 8 && $layout['total'] > 0x7FFFFFFF) {
            throw new RuntimeException(
                'Archivi oltre 2 GB richiedono PHP a 64 bit su questo server.'
            );
        }

        $this->prepareOutput();

        header('Content-Type: application/zip');
        header('Content-Disposition: ' . self::contentDisposition($downloadName));
        header('Content-Length: ' . $layout['total']);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        // Nginx (anche dietro Nginx Proxy Manager): niente buffer su disco
        // della risposta, i byte passano subito al browser.
        header('X-Accel-Buffering: no');

        $this->written = 0;
        $crcs = [];

        // 1. Intestazioni locali + dati
        foreach ($this->entries as $i => $entry) {
            $row = $layout['rows'][$i];

            $crc = $this->entryCrc($entry);
            if ($crc === null) {
                error_log('[zip-stream] CRC non calcolabile, invio interrotto: ' . $entry['name']);
                return false;
            }
            $crcs[$i] = $crc;

            $this->out($this->localHeader($entry, $row, $crc));

            if (!$this->writeEntryData($entry)) {
                return false;
            }
        }

        // 2. Directory centrale
        foreach ($this->entries as $i => $entry) {
            $this->out($this->centralHeader($entry, $layout['rows'][$i], $crcs[$i]));
        }

        // 3. Fine della directory centrale
        $this->out($this->endRecords($layout));

        if ($this->written !== $layout['total']) {
            error_log(sprintf(
                '[zip-stream] dimensione inviata %d diversa dalla prevista %d',
                $this->written,
                $layout['total']
            ));
            return false;
        }

        return true;
    }

    // ----------------------------------------------------------
    // Nomi di file
    // ----------------------------------------------------------

    /**
     * Rende una stringa utilizzabile come nome di file su macOS, Linux
     * e Windows: niente separatori di percorso, caratteri di controllo
     * o caratteri riservati; spazi compattati; lunghezza limitata in
     * byte senza spezzare caratteri UTF-8.
     */
    public static function safeName(string $value, int $maxBytes = 180): string
    {
        // Caratteri di controllo
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        if ($clean === null) {
            // UTF-8 non valido: si tengono solo i caratteri ASCII stampabili
            $clean = preg_replace('/[^\x20-\x7E]+/', ' ', $value);
        }

        $clean = strtr((string)$clean, [
            '/'  => '-',
            '\\' => '-',
            ':'  => ' -',
            '*'  => '',
            '?'  => '',
            '"'  => "'",
            '<'  => '',
            '>'  => '',
            '|'  => '-',
        ]);

        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        $clean = trim($clean, " .\t");

        if (strlen($clean) > $maxBytes) {
            $clean = substr($clean, 0, $maxBytes);
            // Rimuove un eventuale carattere multibyte tagliato a metà
            while ($clean !== '' && preg_match('//u', $clean) !== 1) {
                $clean = substr($clean, 0, -1);
            }
            $clean = rtrim($clean, " .\t");
        }

        return $clean;
    }

    // ----------------------------------------------------------
    // Layout: offset e dimensioni calcolati prima di inviare
    // ----------------------------------------------------------

    /**
     * Calcola posizione e formato (ZIP64 o no) di ogni voce, la
     * dimensione della directory centrale e il totale in byte.
     * send() scrive esattamente ciò che questo metodo prevede.
     */
    private function computeLayout(): array
    {
        $rows   = [];
        $offset = 0;
        $cdSize = 0;

        foreach ($this->entries as $i => $entry) {
            $nameLen = strlen($entry['name']);
            $size    = $entry['size'];

            $sizes64  = $size >= static::LIMIT_32;
            $offset64 = $offset >= static::LIMIT_32;

            // Intestazione locale: extra ZIP64 con le due dimensioni
            $localExtraLen = $sizes64 ? 20 : 0;

            // Directory centrale: extra ZIP64 solo con i campi saturati
            $centralData = ($sizes64 ? 16 : 0) + ($offset64 ? 8 : 0);
            $centralExtraLen = $centralData > 0 ? 4 + $centralData : 0;

            $rows[$i] = [
                'offset'          => $offset,
                'sizes64'         => $sizes64,
                'offset64'        => $offset64,
                'version'         => ($sizes64 || $offset64) ? self::VERSION_ZIP64 : self::VERSION_DEFAULT,
                'localExtraLen'   => $localExtraLen,
                'centralExtraLen' => $centralExtraLen,
            ];

            $offset += 30 + $nameLen + $localExtraLen + $size;
            $cdSize += 46 + $nameLen + $centralExtraLen;
        }

        $count  = count($this->entries);
        $zip64  = $count >= static::LIMIT_16
            || $offset >= static::LIMIT_32
            || $cdSize >= static::LIMIT_32;

        $total = $offset + $cdSize + ($zip64 ? 56 + 20 : 0) + 22;

        return [
            'rows'     => $rows,
            'cdOffset' => $offset,
            'cdSize'   => $cdSize,
            'count'    => $count,
            'zip64'    => $zip64,
            'total'    => $total,
        ];
    }

    // ----------------------------------------------------------
    // Record ZIP
    // ----------------------------------------------------------

    private function localHeader(array $entry, array $row, int $crc): string
    {
        list($dosTime, $dosDate) = self::dosDateTime($entry['mtime']);

        $size  = $entry['size'];
        $extra = '';

        if ($row['sizes64']) {
            // ID 0x0001, 16 byte: dimensione non compressa, poi compressa
            $extra = pack('vvPP', 0x0001, 16, $size, $size);
        }

        $sizeField = $row['sizes64'] ? self::SENTINEL_32 : $size;

        return pack(
            'VvvvvvVVVvv',
            self::SIG_LOCAL,
            $row['version'],
            self::FLAG_UTF8,
            0,                      // metodo: STORE
            $dosTime,
            $dosDate,
            $crc,
            $sizeField,             // dimensione compressa
            $sizeField,             // dimensione non compressa
            strlen($entry['name']),
            strlen($extra)
        ) . $entry['name'] . $extra;
    }

    private function centralHeader(array $entry, array $row, int $crc): string
    {
        list($dosTime, $dosDate) = self::dosDateTime($entry['mtime']);

        $size = $entry['size'];

        $extraData = '';
        if ($row['sizes64']) {
            $extraData .= pack('PP', $size, $size);
        }
        if ($row['offset64']) {
            $extraData .= pack('P', $row['offset']);
        }
        $extra = $extraData !== '' ? pack('vv', 0x0001, strlen($extraData)) . $extraData : '';

        $isDir = $entry['type'] === 'dir';

        // st_mode nei 16 bit alti; per le cartelle anche l'attributo DOS 0x10
        $externalAttr = $isDir
            ? ((0040755 << 16) | 0x10)
            : (0100644 << 16);

        $sizeField   = $row['sizes64'] ? self::SENTINEL_32 : $size;
        $offsetField = $row['offset64'] ? self::SENTINEL_32 : $row['offset'];

        return pack(
            'VvvvvvvVVVvvvvvVV',
            self::SIG_CENTRAL,
            self::MADE_BY_UNIX | $row['version'],
            $row['version'],
            self::FLAG_UTF8,
            0,                      // metodo: STORE
            $dosTime,
            $dosDate,
            $crc,
            $sizeField,
            $sizeField,
            strlen($entry['name']),
            strlen($extra),
            0,                      // lunghezza commento
            0,                      // disco iniziale
            0,                      // attributi interni
            $externalAttr,
            $offsetField
        ) . $entry['name'] . $extra;
    }

    private function endRecords(array $layout): string
    {
        $out = '';

        if ($layout['zip64']) {
            $zip64EocdOffset = $layout['cdOffset'] + $layout['cdSize'];

            // ZIP64 end of central directory record (56 byte)
            $out .= pack(
                'VPvvVVPPPP',
                self::SIG_ZIP64_EOCD,
                44,                 // byte del record dopo questo campo
                self::MADE_BY_UNIX | self::VERSION_ZIP64,
                self::VERSION_ZIP64,
                0,
                0,
                $layout['count'],
                $layout['count'],
                $layout['cdSize'],
                $layout['cdOffset']
            );

            // ZIP64 end of central directory locator (20 byte)
            $out .= pack(
                'VVPV',
                self::SIG_ZIP64_LOCATOR,
                0,
                $zip64EocdOffset,
                1
            );
        }

        // End of central directory record (22 byte). Con ZIP64 i campi
        // saturati valgono 0xFFFF / 0xFFFFFFFF e i lettori usano il record
        // ZIP64 qui sopra.
        $count    = $layout['count'] >= static::LIMIT_16 ? self::SENTINEL_16 : $layout['count'];
        $cdSize   = $layout['cdSize'] >= static::LIMIT_32 ? self::SENTINEL_32 : $layout['cdSize'];
        $cdOffset = $layout['cdOffset'] >= static::LIMIT_32 ? self::SENTINEL_32 : $layout['cdOffset'];

        $out .= pack(
            'VvvvvVVv',
            self::SIG_EOCD,
            0,
            0,
            $count,
            $count,
            $cdSize,
            $cdOffset,
            0
        );

        return $out;
    }

    // ----------------------------------------------------------
    // Dati delle voci
    // ----------------------------------------------------------

    /**
     * CRC32 della voce. Per i file controlla anche che la dimensione
     * non sia cambiata rispetto a quella usata per il Content-Length.
     */
    private function entryCrc(array $entry): ?int
    {
        if ($entry['type'] === 'dir') {
            return 0;
        }

        if ($entry['type'] === 'string') {
            return crc32($entry['data']);
        }

        clearstatcache(true, $entry['path']);
        $size = @filesize($entry['path']);
        if ($size === false || (int)$size !== $entry['size']) {
            error_log('[zip-stream] file modificato o non più disponibile: ' . $entry['path']);
            return null;
        }

        $raw = @hash_file('crc32b', $entry['path'], true);
        if ($raw === false || strlen($raw) !== 4) {
            return null;
        }

        $crc = unpack('N', $raw);

        return (int)$crc[1];
    }

    private function writeEntryData(array $entry): bool
    {
        if ($entry['type'] === 'dir') {
            return true;
        }

        if ($entry['type'] === 'string') {
            $this->out($entry['data']);
            return true;
        }

        $fp = @fopen($entry['path'], 'rb');
        if ($fp === false) {
            error_log('[zip-stream] apertura fallita: ' . $entry['path']);
            return false;
        }

        $remaining = $entry['size'];

        while ($remaining > 0) {
            if (connection_aborted()) {
                fclose($fp);
                return false;
            }

            $chunk = @fread($fp, (int)min(self::CHUNK, $remaining));
            if ($chunk === false || $chunk === '') {
                fclose($fp);
                error_log('[zip-stream] lettura interrotta: ' . $entry['path']);
                return false;
            }

            $this->out($chunk);
            $remaining -= strlen($chunk);
        }

        fclose($fp);

        return true;
    }

    // ----------------------------------------------------------
    // Utility
    // ----------------------------------------------------------

    private function out(string $bytes): void
    {
        echo $bytes;
        flush();
        $this->written += strlen($bytes);
    }

    /**
     * Qualsiasi byte estraneo (warning PHP, buffer aperti, compressione
     * gzip dell'output) renderebbe l'archivio illeggibile o farebbe
     * saltare il Content-Length.
     */
    private function prepareOutput(): void
    {
        @ini_set('display_errors', '0');
        @ini_set('zlib.output_compression', '0');

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        @set_time_limit(0);
    }

    /**
     * Content-Disposition con nome ASCII di riserva e nome UTF-8
     * completo (RFC 6266 / RFC 5987), così gli accenti del nome della
     * playlist arrivano intatti.
     */
    private static function contentDisposition(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
        $ascii = str_replace(['"', '\\'], '_', (string)$ascii);

        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }

    /**
     * Data e ora in formato MS-DOS (ora locale, risoluzione 2 secondi).
     *
     * @return int[] [ora, data]
     */
    private static function dosDateTime(int $timestamp): array
    {
        $d = getdate($timestamp);

        if ($d['year'] < 1980) {
            return [0, (1 << 5) | 1];              // 01/01/1980 00:00
        }
        if ($d['year'] > 2107) {
            return [(23 << 11) | (59 << 5) | 29, (127 << 9) | (12 << 5) | 31];
        }

        $time = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
        $date = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];

        return [$time, $date];
    }
}

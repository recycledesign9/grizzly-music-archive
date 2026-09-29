<?php

/**
 * ExternalApiConfig
 * ------------------------------------------------------------
 * Risolve le credenziali dei servizi esterni con questa priorità:
 *
 *   1) override salvato nella tabella settings
 *   2) configurazione server già esistente (costanti da config.php/.env)
 *   3) stringa vuota
 *
 * Le credenziali salvate dalla UI diventano disponibili senza riavviare
 * Apache/Docker. Il fallback mantiene compatibili le installazioni che
 * continuano a usare LASTFM_API_KEY, DISCOGS_TOKEN e YOUTUBE_API_KEY.
 *
 * Compatibile PHP 7.4.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/config/database.php';

class ExternalApiConfig
{
    public const SERVICE_LASTFM  = 'lastfm';
    public const SERVICE_DISCOGS = 'discogs';
    public const SERVICE_YOUTUBE = 'youtube';

    private const CACHE_TTL_SECONDS = 5;

    private const SERVICES = [
        self::SERVICE_LASTFM => [
            'setting_key' => 'api_lastfm_key',
            'constant'    => 'LASTFM_API_KEY',
            'label'       => 'Last.fm API key',
        ],
        self::SERVICE_DISCOGS => [
            'setting_key' => 'api_discogs_token',
            'constant'    => 'DISCOGS_TOKEN',
            'label'       => 'Discogs personal access token',
        ],
        self::SERVICE_YOUTUBE => [
            'setting_key' => 'api_youtube_key',
            'constant'    => 'YOUTUBE_API_KEY',
            'label'       => 'YouTube Data API key',
        ],
    ];

    /** @var array<string,string>|null */
    private static $dbValues = null;

    /** @var float */
    private static $dbValuesLoadedAt = 0.0;

    public static function isSupported(string $service): bool
    {
        return isset(self::SERVICES[strtolower(trim($service))]);
    }

    public static function getLastFmKey(): string
    {
        return self::get(self::SERVICE_LASTFM);
    }

    public static function getDiscogsToken(): string
    {
        return self::get(self::SERVICE_DISCOGS);
    }

    public static function getYouTubeKey(): string
    {
        return self::get(self::SERVICE_YOUTUBE);
    }

    public static function get(string $service): string
    {
        $service = self::normalizeService($service);
        $config  = self::SERVICES[$service];
        $db      = self::loadDbValues();
        $dbValue = trim((string)($db[$config['setting_key']] ?? ''));

        if ($dbValue !== '') {
            return $dbValue;
        }

        return self::getServerFallback($config['constant']);
    }

    /**
     * Origine della credenziale effettiva:
     * - database: override salvato da Settings
     * - server:    costante/configurazione server (.env o config.php)
     * - none:      non configurata
     */
    public static function getSource(string $service): string
    {
        $service = self::normalizeService($service);
        $config  = self::SERVICES[$service];
        $db      = self::loadDbValues();
        $dbValue = trim((string)($db[$config['setting_key']] ?? ''));

        if ($dbValue !== '') {
            return 'database';
        }

        if (self::getServerFallback($config['constant']) !== '') {
            return 'server';
        }

        return 'none';
    }

    /**
     * Dati sicuri per la UI: non restituisce mai la credenziale completa.
     * Il valore mascherato viene mostrato solo per gli override salvati nel DB.
     */
    public static function getStatus(string $service): array
    {
        $service = self::normalizeService($service);
        $config  = self::SERVICES[$service];
        $source  = self::getSource($service);
        $value   = self::get($service);

        return [
            'service'     => $service,
            'configured'  => $value !== '',
            'source'      => $source,
            'masked'      => $source === 'database' ? self::mask($value) : '',
            'setting_key' => $config['setting_key'],
            'label'       => $config['label'],
        ];
    }

    public static function getStatusAll(): array
    {
        return [
            self::SERVICE_LASTFM  => self::getStatus(self::SERVICE_LASTFM),
            self::SERVICE_DISCOGS => self::getStatus(self::SERVICE_DISCOGS),
            self::SERVICE_YOUTUBE => self::getStatus(self::SERVICE_YOUTUBE),
        ];
    }

    public static function getSettingKey(string $service): string
    {
        $service = self::normalizeService($service);
        return self::SERVICES[$service]['setting_key'];
    }

    public static function getLabel(string $service): string
    {
        $service = self::normalizeService($service);
        return self::SERVICES[$service]['label'];
    }

    /**
     * Invalida la cache runtime del resolver.
     * Il worker long-running rilegge comunque il DB al massimo dopo 5 secondi;
     * la richiesta web che salva/rimuove una chiave la invalida subito.
     */
    public static function clearRuntimeCache(): void
    {
        self::$dbValues = null;
        self::$dbValuesLoadedAt = 0.0;
    }

    private static function normalizeService(string $service): string
    {
        $service = strtolower(trim($service));

        if (!isset(self::SERVICES[$service])) {
            throw new InvalidArgumentException('Servizio esterno non supportato.');
        }

        return $service;
    }

    /**
     * Carica in una sola query i tre override dal DB.
     * La piccola TTL evita query ripetute nei servizi che effettuano più
     * chiamate esterne nella stessa elaborazione, ma mantiene gli aggiornamenti
     * dalla UI visibili anche ai processi long-running senza restart.
     *
     * @return array<string,string>
     */
    private static function loadDbValues(): array
    {
        $now = microtime(true);

        if (
            is_array(self::$dbValues)
            && ($now - self::$dbValuesLoadedAt) < self::CACHE_TTL_SECONDS
        ) {
            return self::$dbValues;
        }

        $db = Database::getInstance();

        $keys = [];
        foreach (self::SERVICES as $config) {
            $keys[] = $config['setting_key'];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare(
            "SELECT `key`, `value` FROM settings WHERE `key` IN ({$placeholders})"
        );
        $stmt->execute($keys);

        $values = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['key'] ?? '');
            if ($key !== '') {
                $values[$key] = (string)($row['value'] ?? '');
            }
        }

        self::$dbValues = $values;
        self::$dbValuesLoadedAt = $now;

        return $values;
    }

    private static function getServerFallback(string $constantName): string
    {
        if (!defined($constantName)) {
            return '';
        }

        $value = constant($constantName);
        return is_string($value) ? trim($value) : '';
    }

    private static function mask(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);

        if ($length === 0) {
            return '';
        }

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', min(12, max(8, $length - 4))) . substr($value, -4);
    }
}

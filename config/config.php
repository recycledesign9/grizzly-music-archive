<?php
// ─────────────────────────────────────────────────────────────────────────────
// config/config.php — Grizzly Music Archive
//
// All values are read from environment variables.
// In Docker the variables are injected via docker-compose.yml.
// For local development without Docker, copy .env.example to .env and use a
// loader such as vlucas/phpdotenv, OR export the vars in your shell before
// starting the dev server.
//
// DO NOT hardcode credentials in this file.
// ─────────────────────────────────────────────────────────────────────────────

// ── Helper: read env from $_ENV, getenv(), or fall back to a default ──────────
function _env(string $key, string $default = ''): string
{
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    $val = getenv($key);
    return ($val !== false && $val !== '') ? (string) $val : $default;
}

// ── Helper: build a safe dynamic base URL ─────────────────────────────────────
function _app_base_url(): string
{
    $canonical = rtrim(_env('BASE_URL', 'http://localhost:8080'), '/');

    $allowedHosts = array_filter(array_map(
        'trim',
        explode(',', _env(
            'ALLOWED_HOSTS',
            'localhost,localhost:8080,127.0.0.1,127.0.0.1:8080'
        ))
    ));

    $canonicalHost = parse_url($canonical, PHP_URL_HOST);
    $canonicalPort = parse_url($canonical, PHP_URL_PORT);

    if ($canonicalHost) {
        $allowedHosts[] = $canonicalPort
            ? $canonicalHost . ':' . $canonicalPort
            : $canonicalHost;
    }

    $allowedHosts = array_unique(array_map('strtolower', $allowedHosts));

    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');

    if ($host !== '' && in_array($host, $allowedHosts, true)) {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';

        if ($proto !== '') {
            $proto = strtolower(trim(explode(',', $proto)[0]));
        }

        if (!in_array($proto, ['http', 'https'], true)) {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                ? 'https'
                : 'http';
        }

        return $proto . '://' . $host;
    }

    return $canonical;
}

// ── Database ─────────────────────────────────────────────────────────────────
define('DB_HOST',    _env('DB_HOST',    'db'));       // 'db' = Docker service name
define('DB_PORT',    _env('DB_PORT',    '3306'));
define('DB_NAME',    _env('DB_NAME',    'grizzly_db'));
define('DB_USER',    _env('DB_USER',    'grizzly'));
define('DB_PASS',    _env('DB_PASS',    ''));
define('DB_CHARSET', 'utf8mb4');

// ── Application ───────────────────────────────────────────────────────────────
define('BASE_URL',  _app_base_url());
define('BASE_PATH', dirname(__DIR__));

// ── Upload paths ──────────────────────────────────────────────────────────────
define('UPLOAD_PATH', BASE_PATH . '/public/uploads');
define('COVERS_PATH', UPLOAD_PATH . '/covers');
define('AUDIO_PATH',  UPLOAD_PATH . '/audio');

// ── Upload size limits ────────────────────────────────────────────────────────
define('MAX_COVER_SIZE', 5   * 1024 * 1024);   //   5 MB
define('MAX_AUDIO_SIZE', 512 * 1024 * 1024);   // 512 MB (supports FLAC)

// ── External API keys (optional — features degrade gracefully without them) ───
define('LASTFM_API_KEY',  _env('LASTFM_API_KEY',  ''));
define('DISCOGS_TOKEN',   _env('DISCOGS_TOKEN',   ''));
define('YOUTUBE_API_KEY', _env('YOUTUBE_API_KEY', ''));
define('APP_USER_AGENT',  'GrizzlyMusicArchive/1.0');

// ── Debug mode — default OFF ─────────────────────────────────────────────────
define('DEBUG', filter_var(_env('DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));

// Show PHP errors only when DEBUG is true
if (DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

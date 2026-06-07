<?php
// Mostra errori in sviluppo — rimuovere in produzione
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Autoloader semplice compatibile PHP 7.4
spl_autoload_register(function (string $class): void {
    $paths = [
        BASE_PATH . '/app/controllers/' . $class . '.php',
        BASE_PATH . '/app/models/'      . $class . '.php',
        BASE_PATH . '/app/services/'    . $class . '.php',
    ];
    foreach ($paths as $file) {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Router
$route    = trim($_GET['route'] ?? 'dashboard', '/');
$segments = explode('/', $route);
$section  = $segments[0] ?? 'dashboard';
$action   = $segments[1] ?? 'index';
$id       = isset($segments[2]) ? (int)$segments[2] : null;

session_start();

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Dispatch compatibile PHP 7.4
switch ($section) {
    case 'albums':
        (new AlbumController())->dispatch($action, $id);
        break;
    case 'artists':
        (new ArtistController())->dispatch($action, $id);
        break;
    case 'search':
        (new SearchController())->dispatch($action, $id);
        break;
    case 'upload':
        (new UploadController())->dispatch($action, $id);
        break;
    case 'playlists':
        (new PlaylistController())->dispatch($action, $id);
        break;
    case 'media':
        (new MediaController())->dispatch($action, $id);
        break;
    case 'settings':
        (new SettingsController())->dispatch($action, $id);
        break;
    default:
        require BASE_PATH . '/views/dashboard.php';
        break;
}
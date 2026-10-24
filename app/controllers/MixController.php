<?php

/**
 * MixController
 *
 * Instant Mix: mix generato al volo da criteri semplici, ascoltabile
 * subito nel player esistente e salvabile come playlist normale
 * (il salvataggio usa l'endpoint esistente playlists/add-tracks).
 *
 * Routes:
 *   GET ?route=mix              → pagina con filtri e risultato
 *   GET ?route=mix/generate     → JSON: genera il mix (criteri in query)
 *   GET ?route=mix/count        → JSON: tracce che corrispondono ai criteri
 *
 * Parametri dei criteri: g (id generi separati da virgola), from, to,
 * n, order (random|chrono), seed.
 *
 * Posizionare in: app/controllers/MixController.php
 */
class MixController
{
    /** @var InstantMixService */
    private $mix;

    public function __construct()
    {
        $this->mix = new InstantMixService();
    }

    public function dispatch(string $action, ?int $id): void
    {
        switch ($action) {
            case 'generate':
                $this->generate();
                break;
            case 'count':
                $this->count();
                break;
            case 'index':
            default:
                $this->index();
                break;
        }
    }

    // ----------------------------------------------------------
    // GET /mix
    // ----------------------------------------------------------
    private function index(): void
    {
        // I criteri arrivano dall'URL: la pagina si apre già compilata e,
        // se c'è un seed, il JS rigenera lo stesso mix.
        $criteria = $this->mix->normalizeCriteria($_GET, false);
        $hasSeed  = isset($_GET['seed']) && preg_match('/^[0-9a-f]{4,12}$/i', (string)$_GET['seed']);

        $genres   = $this->mix->availableGenres();
        $decades  = $this->mix->availableDecades();
        $presets  = InstantMixService::TRACK_PRESETS;
        $maxYear  = (int)date('Y') + 1;
        $minYear  = InstantMixService::YEAR_MIN;

        $pageTitle = 'Instant Mix';
        require BASE_PATH . '/views/mix/index.php';
    }

    // ----------------------------------------------------------
    // GET /mix/generate
    // ----------------------------------------------------------
    private function generate(): void
    {
        // La verifica dei file può toccare dischi esterni: la sessione
        // non deve restare bloccata nel frattempo.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $criteria = $this->mix->normalizeCriteria($_GET, true);
            $result   = $this->mix->generate($criteria);
            $this->json(['ok' => true] + $result);
        } catch (Throwable $e) {
            error_log('[instant-mix] generate: ' . $e->getMessage());
            $this->json(['ok' => false, 'error' => 'Impossibile generare il mix.'], 500);
        }
    }

    // ----------------------------------------------------------
    // GET /mix/count
    // ----------------------------------------------------------
    private function count(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $criteria = $this->mix->normalizeCriteria($_GET, false);
            $counts   = $this->mix->count($criteria);
            $this->json([
                'ok'       => true,
                'playable' => $counts['playable'],
                'catalog'  => $counts['catalog'],
                'label'    => $this->mix->label($criteria),
            ]);
        } catch (Throwable $e) {
            error_log('[instant-mix] count: ' . $e->getMessage());
            $this->json(['ok' => false, 'error' => 'Conteggio non disponibile.'], 500);
        }
    }

    private function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

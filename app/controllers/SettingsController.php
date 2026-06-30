<?php

/**
 * SettingsController
 *
 * Gestisce la pagina delle impostazioni di Grizzly Music Archive.
 * - configurazione percorso audio
 * - export / import completo dell'archivio (dati + immagini)
 *
 * Route: ?route=settings
 *
 * Posizionare in: app/controllers/SettingsController.php
 */
class SettingsController
{
    public function dispatch(string $action, ?int $id): void
    {
        switch ($action) {
            case 'export':
                $this->export();
                break;
            case 'import':
                $this->import();
                break;
            case 'clear-wiki-cache':
                $this->clearWikiCache();
                break;
            case 'index':
            default:
                $this->index();
                break;
        }
    }

    private function index(): void
    {
        $pageTitle = 'Impostazioni';

        // Path attualmente configurato (grezzo dal DB o vuoto)
        $db          = Database::getInstance();
        $stmt        = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'audio_path' LIMIT 1");
        $stmt->execute();
        $row         = $stmt->fetch(PDO::FETCH_ASSOC);
        $audioPathDb = ($row && trim($row['value']) !== '') ? trim($row['value']) : '';

        // Statistiche cartella audio attuale
        $audioStats  = MediaPathResolver::getAudioStats();
        $audioTest   = MediaPathResolver::testAudioPath();

        // Path effettivo usato dall'app (DB o default config.php)
        $audioPathActive = MediaPathResolver::getAudioDir();

        require BASE_PATH . '/views/settings.php';
    }

    // ----------------------------------------------------------
    // EXPORT: genera lo ZIP e lo invia al browser come download.
    // GET /index.php?route=settings/export
    // ----------------------------------------------------------
    private function export(): void
    {
        // Rilascia il lock della sessione: l'export può durare alcuni
        // secondi e, senza questo, terrebbe bloccate tutte le altre
        // richieste (stessa accortezza dello streaming audio).
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        require_once BASE_PATH . '/app/services/ArchiveTransfer.php';

        try {
            $transfer = new ArchiveTransfer();
            $zipPath  = $transfer->export();

            $filename = 'grizzly-export-' . date('Ymd-His') . '.zip';

            // Pulisce eventuale output bufferizzato prima del binario
            while (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($zipPath));
            header('Cache-Control: no-store');

            readfile($zipPath);
            @unlink($zipPath); // pulizia file temporaneo
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Export fallito: ' . $e->getMessage();
            header('Location: ' . BASE_URL . '/index.php?route=settings');
            exit;
        }
    }

    // ----------------------------------------------------------
    // IMPORT: riceve lo ZIP caricato, valida e sostituisce l'archivio.
    // POST /index.php?route=settings/import  (multipart, campo "archive")
    // ----------------------------------------------------------
    private function import(): void
    {
        // Solo POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . '/index.php?route=settings');
            exit;
        }

        // Verifica conferma esplicita
        if (empty($_POST['confirm']) || $_POST['confirm'] !== 'REPLACE') {
            $_SESSION['flash_error'] = 'Import annullato: conferma mancante.';
            header('Location: ' . BASE_URL . '/index.php?route=settings');
            exit;
        }

        // Verifica file caricato
        if (empty($_FILES['archive']) || $_FILES['archive']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Nessun file valido caricato.';
            header('Location: ' . BASE_URL . '/index.php?route=settings');
            exit;
        }

        $tmp  = $_FILES['archive']['tmp_name'];
        $name = $_FILES['archive']['name'] ?? '';

        // Controllo estensione di base
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            $_SESSION['flash_error'] = 'Il file deve essere un archivio .zip esportato da Grizzly.';
            header('Location: ' . BASE_URL . '/index.php?route=settings');
            exit;
        }

        require_once BASE_PATH . '/app/services/ArchiveTransfer.php';

        try {
            $transfer = new ArchiveTransfer();
            $result   = $transfer->import($tmp);

            $tot = 0;
            foreach ($result['counts'] as $c) {
                $tot += (int) $c;
            }
            $_SESSION['flash_success'] = 'Import completato: ' . $tot . ' record ripristinati. '
                . 'Ricorda di sistemare il percorso audio se necessario.';
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Import fallito: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . '/index.php?route=settings');
        exit;
    }

    // ----------------------------------------------------------
    // Svuota la cache delle descrizioni Wikipedia (cache/wiki/*).
    // POST /index.php?route=settings/clear-wiki-cache
    // Usata dal pulsante "Svuota cache Wikipedia" nelle Impostazioni,
    // utile dopo modifiche al codice di ricerca o quando un disco
    // mostra ingiustamente "nessuna descrizione disponibile" per via
    // di un esito negativo cachato in precedenza (TTL fino a 3 giorni).
    // ----------------------------------------------------------
    private function clearWikiCache(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
            exit;
        }

        $dir = BASE_PATH . '/cache/wiki';

        if (!is_dir($dir)) {
            echo json_encode(['ok' => true, 'deleted' => 0, 'message' => 'Nessuna cache da svuotare.']);
            exit;
        }

        $files   = glob($dir . '/*.json') ?: [];
        $deleted = 0;

        foreach ($files as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }

        echo json_encode([
            'ok'      => true,
            'deleted' => $deleted,
            'message' => $deleted > 0
                ? "Cache svuotata: {$deleted} file rimossi."
                : 'La cache era già vuota.',
        ]);
        exit;
    }
}
<?php

/**
 * SettingsController
 *
 * Gestisce la pagina delle impostazioni di Grizzly Music Archive.
 * Attualmente: configurazione percorso audio.
 * Espandibile in futuro con altre preferenze applicative.
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
}
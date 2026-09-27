<?php

/**
 * DashboardController
 *
 * - index: render della dashboard
 * - state: endpoint JSON molto leggero usato dal live refresh
 *
 * Compatibile PHP 7.4.
 */
class DashboardController
{
    public function dispatch(string $action, ?int $id): void
    {
        switch ($action) {
            case 'state':
                $this->state();
                break;

            case 'index':
            default:
                $this->index();
                break;
        }
    }

    private function index(): void
    {
        require BASE_PATH . '/views/dashboard.php';
    }

    /**
     * Restituisce solo una "firma" dello stato archivio.
     * Non renderizza album, cover o statistiche complete.
     *
     * La dashboard la interroga ogni pochi secondi; se la firma cambia,
     * il browser recupera la dashboard e sostituisce soltanto i blocchi
     * interessati, senza toccare sticky player e resto della pagina.
     */
    private function state(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        // Non tenere il lock della sessione per un endpoint di polling.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $db = Database::getInstance();

            // La firma deve cambiare non solo quando nasce un nuovo album,
            // ma anche quando il worker modifica dati gia' esistenti
            // (es. Digital -> Vinile, associazione audio, correzione artista).
            // Le SUM(CRC32(...)) sono leggere per la dimensione di un archivio
            // personale e non richiedono nuove colonne updated_at.
            $stmt = $db->query("
                SELECT
                    (SELECT COUNT(*) FROM albums) AS total,
                    (SELECT COALESCE(MAX(id), 0) FROM albums) AS latest_id,
                    (SELECT COALESCE(DATE_FORMAT(MAX(created_at), '%Y-%m-%d %H:%i:%s'), '') FROM albums) AS latest_created_at,

                    (SELECT COALESCE(SUM(CAST(CRC32(CONCAT_WS('|',
                        id, artist_id, COALESCE(format_id, 0), COALESCE(genre_id, 0),
                        COALESCE(label_id, 0), title, COALESCE(year, 0),
                        COALESCE(cover_local, ''), COALESCE(cover_url, '')
                    )) AS UNSIGNED)), 0) FROM albums) AS albums_signature,

                    (SELECT COALESCE(SUM(CAST(CRC32(CONCAT_WS('|',
                        id, name
                    )) AS UNSIGNED)), 0) FROM artists) AS artists_signature,

                    (SELECT COALESCE(SUM(CAST(CRC32(CONCAT_WS('|',
                        album_id, format_id, is_manual, is_scanner
                    )) AS UNSIGNED)), 0) FROM album_formats) AS formats_signature,

                    (SELECT COALESCE(SUM(CAST(CRC32(CONCAT_WS('|',
                        id, album_id, position, title, COALESCE(duration_sec, 0)
                    )) AS UNSIGNED)), 0) FROM tracks) AS tracks_signature,

                    (SELECT COALESCE(SUM(CAST(CRC32(CONCAT_WS('|',
                        id, album_id, COALESCE(track_id, 0), filename, storage_type,
                        COALESCE(source_mtime, 0)
                    )) AS UNSIGNED)), 0) FROM audio_files) AS audio_signature
            ");

            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $total    = (int)($row['total'] ?? 0);
            $latestId = (int)($row['latest_id'] ?? 0);
            $latestAt = (string)($row['latest_created_at'] ?? '');

            $revision = sha1(implode('|', [
                (string)$total,
                (string)$latestId,
                $latestAt,
                (string)($row['albums_signature'] ?? '0'),
                (string)($row['artists_signature'] ?? '0'),
                (string)($row['formats_signature'] ?? '0'),
                (string)($row['tracks_signature'] ?? '0'),
                (string)($row['audio_signature'] ?? '0'),
            ]));

            echo json_encode([
                'ok'                => true,
                'total'             => $total,
                'latest_album_id'   => $latestId,
                'latest_created_at' => $latestAt,
                'revision'          => $revision,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(500);

            echo json_encode([
                'ok'    => false,
                'error' => (defined('DEBUG') && DEBUG)
                    ? $e->getMessage()
                    : 'Impossibile leggere lo stato della dashboard.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        exit;
    }
}

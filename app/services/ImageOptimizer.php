<?php

/**
 * ImageOptimizer
 * ------------------------------------------------------------
 * Normalizza le immagini al momento in cui entrano nell'app
 * (download cover/artista dalle API, upload manuale): lato
 * massimo 1200px, JPEG qualità 85, PNG/WebP nel loro formato.
 *
 * Stessa logica e stessi limiti di scripts/optimize_images.php
 * (che resta per le librerie già esistenti): questo servizio
 * evita che il problema si ripresenti sulle immagini future.
 *
 * GARANZIE:
 *   - best-effort e MAI fatale: su qualsiasi problema il file
 *     originale resta al suo posto e il flusso prosegue
 *   - il nome file non cambia mai
 *   - sovrascrive SOLO se la versione ottimizzata è più piccola
 *   - GIF ignorate (le animazioni verrebbero distrutte da GD)
 *   - orientamento EXIF delle foto rispettato (se ext. exif attiva)
 *
 * Compatibile PHP 7.4. Richiede GD (già nello stack MAMP/Docker);
 * se GD manca, il servizio è un no-op silenzioso.
 */
class ImageOptimizer
{
    /** Lato massimo in px */
    private const MAX_SIDE = 1200;

    /** Qualità JPEG/WebP */
    private const QUALITY = 85;

    /** Sotto questa soglia il file non si tocca */
    private const SKIP_UNDER = 300 * 1024;

    /**
     * Ottimizza un'immagine sul disco, in-place.
     * Ritorna true se il file è stato riscritto, false altrimenti
     * (già piccolo, formato non gestito, GD assente, errore).
     */
    public static function optimize(string $path): bool
    {
        try {
            if (!function_exists('imagecreatefromstring')) return false;
            if (!is_file($path)) return false;

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) return false;

            $sizeBefore = filesize($path);
            if ($sizeBefore === false || $sizeBefore < self::SKIP_UNDER) return false;

            $raw = @file_get_contents($path);
            if ($raw === false) return false;

            $img = @imagecreatefromstring($raw);
            if ($img === false) return false;

            $img = self::fixOrientation($img, $path, $ext);

            $w = imagesx($img);
            $h = imagesy($img);

            if (max($w, $h) > self::MAX_SIDE) {
                $scale = self::MAX_SIDE / max($w, $h);
                $nw = (int)round($w * $scale);
                $nh = (int)round($h * $scale);

                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false); // preserva trasparenza PNG/WebP
                imagesavealpha($dst, true);
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $dst;
            }

            ob_start();
            if ($ext === 'png') {
                imagepng($img, null, 9);
            } elseif ($ext === 'webp' && function_exists('imagewebp')) {
                imagewebp($img, null, self::QUALITY);
            } else {
                imageinterlace($img, 1); // JPEG progressive
                imagejpeg($img, null, self::QUALITY);
            }
            $out = ob_get_clean();
            imagedestroy($img);

            // Riscrive SOLO se davvero più piccola
            if ($out === false || strlen($out) >= $sizeBefore) return false;

            return @file_put_contents($path, $out) !== false;
        } catch (Throwable $e) {
            // Mai bloccare il flusso chiamante per un'ottimizzazione
            return false;
        }
    }

    /** Corregge l'orientamento EXIF (solo JPEG, solo se exif attiva). */
    private static function fixOrientation($img, string $path, string $ext)
    {
        if ($ext !== 'jpg' && $ext !== 'jpeg') return $img;
        if (!function_exists('exif_read_data')) return $img;

        $exif = @exif_read_data($path);
        $o = isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;
        if ($o === 3) $img = imagerotate($img, 180, 0);
        if ($o === 6) $img = imagerotate($img, -90, 0);
        if ($o === 8) $img = imagerotate($img, 90, 0);
        return $img;
    }
}

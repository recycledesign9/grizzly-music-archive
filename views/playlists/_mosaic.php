<?php

/**
 * Mosaico copertine di una playlist.
 *
 * - nessuna copertina: tile vuota con l'icona playlist
 * - da 1 a 3 copertine: la prima a piena superficie
 * - 4 copertine: griglia 2x2
 *
 * Usato da views/playlists/list.php e views/playlists/detail.php.
 */
if (!function_exists('grzPlaylistMosaic')) {
  function grzPlaylistMosaic(array $covers, string $extraClass = ''): string
  {
    $covers = array_values(array_slice($covers, 0, 4));
    $count  = count($covers);
    $cls    = 'grz-mosaic' . ($extraClass !== '' ? ' ' . $extraClass : '');

    if ($count === 0) {
      return '<div class="' . $cls . ' grz-mosaic--empty" aria-hidden="true">'
        . '<span class="grz-vinyl"></span></div>';
    }

    if ($count < 4) {
      return '<div class="' . $cls . ' grz-mosaic--single" aria-hidden="true">'
        . '<img src="' . htmlspecialchars($covers[0], ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy">'
        . '</div>';
    }

    $html = '<div class="' . $cls . ' grz-mosaic--grid" aria-hidden="true">';
    foreach ($covers as $src) {
      $html .= '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy">';
    }
    return $html . '</div>';
  }
}

/**
 * Durata in formato compatto: "1 h 07 min", "42 min", "58 sec".
 */
if (!function_exists('grzPlaylistDuration')) {
  function grzPlaylistDuration(int $sec): string
  {
    if ($sec <= 0) {
      return '';
    }
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    if ($h > 0) {
      return $h . ' h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ' min';
    }
    if ($m > 0) {
      return $m . ' min';
    }
    return $sec . ' sec';
  }
}

<?php
$pageTitle = 'Ricerca';
require BASE_PATH . '/views/layout/header.php';

function srCover(array $a): string {
    if (!empty($a['cover_local'])) return BASE_URL . '/public/uploads/' . htmlspecialchars($a['cover_local']);
    if (!empty($a['cover_url']))   return htmlspecialchars($a['cover_url']);
    return BASE_URL . '/public/img/placeholder.png';
}

function srFmtClass(string $n): string {
    $n = strtolower($n);
    if (strpos($n, 'vinile') !== false || strpos($n, 'vinyl') !== false) return 'bg-warning';
    if (strpos($n, 'cd')    !== false) return 'bg-info';
    if (strpos($n, 'cass')  !== false || strpos($n, 'tape') !== false) return 'bg-success';
    if (strpos($n, 'digital') !== false) return 'bg-primary';
    return 'bg-secondary';
}

// Tutti i formati della scheda, con fallback sulla colonna legacy
function srFormats(array $a): array {
    if (!empty($a['formats'])) return $a['formats'];
    return [['name' => $a['format_name'] ?? '']];
}

// URL immagine artista: locale, poi remota, altrimenti stringa vuota
// (la vista mostra un avatar segnaposto con icona)
function srArtistImage(array $ar): string {
    if (!empty($ar['image_local'])) return BASE_URL . '/public/uploads/' . htmlspecialchars($ar['image_local']);
    if (!empty($ar['image_url']))   return htmlspecialchars($ar['image_url']);
    return '';
}

// Durata di una traccia in m:ss; stringa vuota se non nota.
function srDuration($seconds): string {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return '';
    return floor($seconds / 60) . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
}

$tracks      = $tracks ?? [];
$tracksTotal = $tracksTotal ?? count($tracks);

// Prima di una ricerca la pagina è vuota: il campo sta al centro ed è
// l'elemento principale. Con dei risultati torna in alto, sopra l'elenco.
$isHero = empty($didSearch);
?>

<!-- Header: con una ricerca in corso mostra il termine cercato e il
     riepilogo; a pagina vuota resta solo "Indietro" (il titolo è nel
     blocco centrale). -->
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <?php if (!$isHero): ?>
      <h4 class="mb-1">
        <i class="bi bi-search me-2"></i>Risultati per
        <span class="text-warning">“<?= htmlspecialchars($q) ?>”</span>
      </h4>
      <?php if (!empty($albums) || !empty($artists) || !empty($tracks)): ?>
        <p class="text-muted small mb-0">
          <?php
            $parts = [];
            if (!empty($artists)) $parts[] = count($artists) . ' ' . (count($artists) === 1 ? 'artista' : 'artisti');
            if (!empty($albums))  $parts[] = count($albums) . ' album';
            if (!empty($tracks))  $parts[] = $tracksTotal . ' ' . ($tracksTotal === 1 ? 'traccia' : 'tracce');
            echo implode(' · ', $parts) . ' · ordinati per rilevanza';
          ?>
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <a href="<?= BASE_URL ?>/index.php" class="btn btn-sm btn-outline-secondary ms-3 flex-shrink-0"
    onclick="event.preventDefault(); event.stopPropagation(); grzBack(this.getAttribute('href'));">
    <i class="bi bi-arrow-left me-1"></i>Indietro
  </a>
</div>

<?php if ($isHero): ?>
<div class="grz-search-hero">
  <h1 class="grz-search-hero__title">Cerca nell'archivio</h1>
<?php endif; ?>

<!-- Form di ricerca: un solo campo, sempre visibile, che cerca tra
     artisti, album e tracce (suggerimenti di search-suggest.js). I
     filtri per formato, genere, etichetta e anno stanno nell'Archivio.
     Submit GET classico, quindi compatibile con la navigazione SPA. -->
<form method="get" action="<?= BASE_URL ?>/index.php" role="search"
  class="grz-filterbar grz-filterbar--solo<?= $isHero ? ' grz-filterbar--hero' : '' ?>">
  <input type="hidden" name="route" value="search/index">

  <div class="grz-filterbar__search" data-suggest>
    <i class="bi bi-search grz-filterbar__icon" aria-hidden="true"></i>
    <!-- Niente minlength: la validazione nativa del browser
         bloccherebbe il submit con il suo tooltip, impedendo al
         banner in stile app (renderizzato dal server) di comparire.
         La regola dei 2 caratteri resta applicata lato server. -->
    <input type="search" name="q" class="grz-filterbar__input"
      placeholder="Artista, album o traccia…" aria-label="Artista, album o traccia" autofocus
      autocomplete="off" data-suggest-input
      value="<?= htmlspecialchars($q ?? '') ?>">
    <button type="submit" class="grz-filterbar__submit" title="Cerca">
      Cerca
    </button>
  </div>
</form>

<?php if (!empty($textQueryTooShort)): ?>
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>Inserisci almeno 2 caratteri per cercare.
  </div>
<?php endif; ?>

<?php if (!empty($prefixFallback)): ?>
  <!-- Nessun nome o titolo contiene il termine come parola intera:
       i risultati sono quelli che iniziano con il testo cercato. -->
  <p class="grz-search-note">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    Nessun nome o titolo contiene la parola “<?= htmlspecialchars($q) ?>”: sono mostrati quelli che iniziano così.
  </p>
<?php endif; ?>

<?php if ($isHero): ?>

  <?php if (!empty($archiveCounts)): ?>
    <p class="grz-search-hero__counts">
      <?= number_format((int)$archiveCounts['artists'], 0, ',', '.') ?> artisti ·
      <?= number_format((int)$archiveCounts['albums'], 0, ',', '.') ?> album ·
      <?= number_format((int)$archiveCounts['tracks'], 0, ',', '.') ?> tracce
    </p>
  <?php endif; ?>
  <p class="grz-search-hero__hint">
    Scrivi un artista, un album o una traccia: i suggerimenti compaiono mentre scrivi.
    Per sfogliare i dischi per formato, genere, etichetta o anno usa i filtri
    dell'<a href="<?= BASE_URL ?>/index.php?route=albums/list">Archivio</a>.
  </p>
</div><!-- /.grz-search-hero -->

<?php elseif (empty($albums) && empty($artists) && empty($tracks)): ?>

  <div class="grz-search-empty">
    <i class="bi bi-inbox grz-search-empty__icon" aria-hidden="true"></i>
    <p class="grz-search-empty__title">Nessun risultato<?= $q !== '' ? ' per “' . htmlspecialchars($q) . '”' : '' ?></p>
    <p class="grz-search-empty__text">
      Controlla come è scritto il nome oppure prova con una parola sola.
    </p>
  </div>

<?php else: ?>

  <!-- ARTISTI -->
  <?php if (!empty($artists)): ?>
  <div class="sr-artists-wrap">
    <div class="sr-label"><i class="bi bi-person-fill"></i>Artisti</div>
    <?php foreach ($artists as $ar): ?>
      <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= $ar['id'] ?>"
         class="sr-artist-row">
        <?php $img = srArtistImage($ar); ?>
        <?php if ($img !== ''): ?>
          <img src="<?= $img ?>" alt="<?= htmlspecialchars($ar['name']) ?>"
               class="sr-artist-avatar" loading="lazy"
               onerror="this.outerHTML='<span class=\'sr-artist-avatar sr-artist-avatar--empty\'><i class=\'bi bi-person-fill\'></i></span>'">
        <?php else: ?>
          <span class="sr-artist-avatar sr-artist-avatar--empty"><i class="bi bi-person-fill"></i></span>
        <?php endif; ?>
        <span class="sr-artist-name">
          <?= htmlspecialchars($ar['name']) ?>
        </span>
        <span class="sr-artist-count"><?= (int)$ar['album_count'] ?> album in archivio</span>
        <i class="bi bi-chevron-right sr-artist-arrow"></i>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ALBUM -->
  <?php if (!empty($albums)): ?>
  <div class="sr-albums-wrap">
    <div class="sr-label">
      <i class="bi bi-collection-fill"></i>Album
      <span class="badge bg-secondary fw-normal ms-1"><?= count($albums) ?></span>
    </div>

    <?php if (count($albums) === 1):
      $a = $albums[0]; ?>

      <!-- 1 solo album: hero col-12 con cover grande -->
      <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>"
         class="sr-single-wrap">
        <div class="sr-single-cover">
          <img src="<?= srCover($a) ?>"
               alt="<?= htmlspecialchars($a['title']) ?>"
               onerror="this.src='<?= BASE_URL ?>/public/img/placeholder.png'">
        </div>
        <div class="sr-single-info">
          <div class="sr-single-title"><?= htmlspecialchars($a['title']) ?></div>
          <div class="sr-single-artist"><?= htmlspecialchars($a['artist_name']) ?></div>
          <div class="sr-single-meta">
            <?php foreach (srFormats($a) as $fmt): ?>
              <span class="badge-format <?= srFmtClass($fmt['name']) ?>">
                <?= htmlspecialchars($fmt['name']) ?>
              </span>
            <?php endforeach; ?>
            <?php if (!empty($a['year'])): ?>
              <span class="sr-single-year"><?= (int)$a['year'] ?></span>
            <?php endif; ?>
          </div>
          <div class="sr-single-cta">
            Vai alla scheda <i class="bi bi-arrow-right"></i>
          </div>
        </div>
      </a>

    <?php else: ?>

      <!-- Più album — mobile: card compatte, tap → dettaglio -->
      <div class="m-card-list d-md-none shadow-sm">
        <?php foreach ($albums as $a): ?>
          <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>" class="m-card">
            <img src="<?= srCover($a) ?>" class="m-card-cover" alt="" loading="lazy"
              onerror="this.src='<?= BASE_URL ?>/public/img/placeholder.png'">
            <div class="m-card-body">
              <div class="m-card-title"><?= htmlspecialchars($a['title']) ?></div>
              <div class="m-card-sub"><?= htmlspecialchars($a['artist_name']) ?></div>
              <div class="m-card-meta">
                <?php foreach (srFormats($a) as $fmt): ?>
                  <span class="badge-format <?= srFmtClass($fmt['name']) ?>">
                    <?= htmlspecialchars($fmt['name']) ?>
                  </span>
                <?php endforeach; ?>
                <?php if (!empty($a['year'])): ?>
                  <span><?= (int)$a['year'] ?></span>
                <?php endif; ?>
              </div>
            </div>
            <i class="bi bi-chevron-right m-card-arrow"></i>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Più album — desktop: tabella con cover 72px -->
      <table class="sr-table d-none d-md-table">
        <thead>
          <tr>
            <th class="sr-cover-cell">Cover</th>
            <th>Titolo</th>
            <th>Artista</th>
            <th>Formato</th>
            <th>Anno</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($albums as $a): ?>
            <tr>
              <td class="sr-cover-cell">
                <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>">
                  <img src="<?= srCover($a) ?>"
                       alt="<?= htmlspecialchars($a['title']) ?>"
                       class="sr-cover-thumb"
                       onerror="this.src='<?= BASE_URL ?>/public/img/placeholder.png'">
                </a>
              </td>
              <td>
                <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>"
                   class="sr-title-link">
                  <?= htmlspecialchars($a['title']) ?>
                </a>
              </td>
              <td>
                <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= $a['artist_id'] ?>"
                   class="sr-artist-link">
                  <?= htmlspecialchars($a['artist_name']) ?>
                </a>
              </td>
              <td>
                <?php foreach (srFormats($a) as $fmt): ?>
                  <span class="badge-format <?= srFmtClass($fmt['name']) ?> me-1">
                    <?= htmlspecialchars($fmt['name']) ?>
                  </span>
                <?php endforeach; ?>
              </td>
              <td class="text-muted"><?= htmlspecialchars($a['year'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- TRACCE: la riga apre la scheda del disco che contiene il brano. -->
  <?php if (!empty($tracks)): ?>
  <div class="sr-tracks-wrap">
    <div class="sr-label">
      <i class="bi bi-music-note-list"></i>Tracce
      <span class="badge bg-secondary fw-normal ms-1"><?= (int)$tracksTotal ?></span>
    </div>

    <div class="sr-tracks">
      <?php foreach ($tracks as $t):
        $tDuration = srDuration($t['duration_sec'] ?? 0);
        $tHasAudio = !empty($t['has_audio']);
      ?>
        <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= (int)$t['album_id'] ?>" class="sr-track-row">
          <img src="<?= srCover($t) ?>" alt="" class="sr-track-cover" loading="lazy"
               onerror="this.onerror=null;this.src='<?= BASE_URL ?>/public/img/placeholder.png'">
          <span class="sr-track-text">
            <span class="sr-track-title"><?= htmlspecialchars($t['title']) ?></span>
            <span class="sr-track-sub">
              <?= htmlspecialchars($t['artist_name']) ?> · <?= htmlspecialchars($t['album_title']) ?>
            </span>
          </span>
          <span class="sr-track-audio" title="<?= $tHasAudio ? 'File audio presente' : 'Nessun file audio' ?>">
            <i class="bi <?= $tHasAudio ? 'bi-music-note-beamed grz-track-audio' : 'bi-music-note grz-track-noaudio' ?>" aria-hidden="true"></i>
            <span class="visually-hidden"><?= $tHasAudio ? 'File audio presente' : 'Nessun file audio' ?></span>
          </span>
          <span class="sr-track-duration"><?= $tDuration !== '' ? $tDuration : '—' ?></span>
          <i class="bi bi-chevron-right sr-artist-arrow" aria-hidden="true"></i>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($tracksTotal > count($tracks)): ?>
      <p class="sr-tracks-more">
        Mostrate le prime <?= count($tracks) ?> tracce di <?= (int)$tracksTotal ?>.
        Aggiungi il nome dell'artista o dell'album per restringere.
      </p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

<?php endif; ?>

<script>
  // Navigazione "indietro" consapevole del percorso (vedi album/artista).
  window.grzBack = function (fallbackUrl) {
    if (window.history.state && window.history.state.url) {
      window.history.back();
    } else if (typeof window._spaNavigate === 'function') {
      window._spaNavigate(fallbackUrl);
    } else {
      window.location.href = fallbackUrl;
    }
  };
</script>

<?php require BASE_PATH . '/views/layout/footer.php'; ?>
<?php
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/models/Album.php';
require_once BASE_PATH . '/app/models/Artist.php';
require_once BASE_PATH . '/app/services/DashboardService.php';
require_once BASE_PATH . '/views/playlists/_mosaic.php';

// ------------------------------------------------------------
// Panoramica: racconta lo stato della collezione. La consultazione
// completa (tutti i dischi, filtri, ordinamenti) resta nell'Archivio;
// qui ogni sezione mostra pochi elementi e porta alla pagina dove si
// approfondisce.
// ------------------------------------------------------------
$albumModel  = new Album();
$artistModel = new Artist();
$dash        = new DashboardService();

$stats       = $albumModel->getStats();
$arrivals    = $albumModel->getAll([], 'a.created_at', 'DESC', 12, 0);
$topArtists  = $artistModel->getTopArtists(5);
$playlists   = $dash->playlists(12);
$genres      = $dash->topGenres(16);
$genreCount  = $dash->genreCount();
$decades     = $dash->decades();
$labels      = $dash->topLabels(5);
$growth      = $dash->monthlyArrivals(12);
$hours       = (int)floor($dash->totalDurationSeconds() / 3600);
$pageTitle   = 'Panoramica';

// Archivio vuoto (per esempio subito dopo l'installazione): la pagina
// mostra solo la testata e un invito a cominciare, senza bande vuote.
$isEmptyArchive = (int)($stats['total'] ?? 0) === 0;
$hasComposition = !empty($decades) || !empty($topArtists) || !empty($labels);

// Sezioni editoriali: disco del giorno e anniversari di uscita.
$dayPick     = $isEmptyArchive ? null : $dash->albumOfTheDay();
$anniv       = $isEmptyArchive ? [] : $dash->anniversaries(40);
$latest      = $arrivals[0] ?? null;

$fmtSegments = [
  ['label' => 'Vinili',       'count' => (int)($stats['vinili']   ?? 0), 'accent' => 'vinyl',   'format_id' => 1],
  ['label' => 'CD',           'count' => (int)($stats['cd']       ?? 0), 'accent' => 'cd',      'format_id' => 2],
  ['label' => 'Musicassette', 'count' => (int)($stats['cassette'] ?? 0), 'accent' => 'tape',    'format_id' => 3],
  ['label' => 'Digital',      'count' => (int)($stats['digital']  ?? 0), 'accent' => 'digital', 'format_id' => 4],
];
$fmtTotal = 0;
foreach ($fmtSegments as $s) {
  $fmtTotal += $s['count'];
}

$growthThisMonth = (int)($growth[count($growth) - 1]['count'] ?? 0);
$decadeMax      = max(1, max(array_column($decades, 'count') ?: [0]));
$labelMax       = max(1, (int)($labels[0]['count'] ?? 0));

// Archivio in griglia, ordinato per data di arrivo: prosegue i Nuovi arrivi.
$arrivalsUrl = BASE_URL . '/index.php?' . http_build_query([
  'route' => 'albums/list', 'view' => 'grid', 'order' => 'a.created_at', 'dir' => 'DESC',
]);

require BASE_PATH . '/views/layout/header.php';
?>

<!-- Involucro della Panoramica: contenitore delle container query, così
     il layout segue lo spazio reale (con o senza coda agganciata). -->
<div class="grz-dash grz-dash--v2">

<!-- 1. TESTATA: identità e dimensione della collezione.
     id usato dal refresh live (app.js). -->
<section class="grz-statband" id="dashboard-statband" aria-labelledby="dash-title">
  <div class="grz-statband__top">
    <div>
      <div class="grz-dash-hero__eyebrow">
        <span class="grz-dash-hero__dot"></span>
        <span>Archivio attivo</span>
      </div>
      <h1 class="grz-statband__title" id="dash-title">La tua collezione</h1>
    </div>
    <div class="grz-statband__meta">
      <span class="grz-statband__counts">
        <strong><?= (int)($stats['total'] ?? 0) ?></strong> dischi
        <span class="grz-statband__sep">·</span>
        <strong><?= (int)($stats['artisti'] ?? 0) ?></strong> artisti
        <?php if ($hours > 0): ?>
          <span class="grz-statband__sep">·</span>
          <strong><?= $hours ?></strong> ore di musica
        <?php endif; ?>
      </span>
      <a href="<?= BASE_URL ?>/index.php?route=albums/create" class="btn btn-warning btn-sm fw-semibold">
        <i class="bi bi-plus-lg me-1"></i>Aggiungi
      </a>
    </div>
  </div>

  <?php if ($fmtTotal > 0): ?>
    <div class="grz-formatbar">
      <?php foreach ($fmtSegments as $s): if ($s['count'] <= 0) continue; ?>
        <a class="grz-formatbar__seg grz-formatbar__seg--<?= $s['accent'] ?>"
           style="flex: <?= $s['count'] ?> 1 0%"
           href="<?= BASE_URL ?>/index.php?route=albums/list&format_id=<?= $s['format_id'] ?>"
           title="<?= $s['label'] ?>: <?= $s['count'] ?>"
           aria-label="<?= $s['label'] ?>: <?= $s['count'] ?>, apri nell'archivio"></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="grz-statband__bottom">
    <?php if ($fmtTotal > 0): ?>
      <div class="grz-formatbar__legend">
        <?php foreach ($fmtSegments as $s): ?>
          <a class="grz-formatbar__chip"
             href="<?= BASE_URL ?>/index.php?route=albums/list&format_id=<?= $s['format_id'] ?>">
            <span class="grz-formatbar__dot grz-formatbar__dot--<?= $s['accent'] ?>"></span>
            <strong><?= $s['count'] ?></strong>&nbsp;<?= $s['label'] ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$isEmptyArchive): ?>
    <!-- Crescita: solo il dato del mese corrente, in forma testuale.
         Il grafico mensile è stato tolto: accanto ad "Aggiungi" competeva
         con il pulsante principale della testata. -->
    <div class="grz-growth">
      <span class="grz-growth__text">
        <?php if ($growthThisMonth > 0): ?>
          <strong>+<?= $growthThisMonth ?></strong> <?= $growthThisMonth === 1 ? 'disco' : 'dischi' ?> questo mese
        <?php else: ?>
          Nessun arrivo questo mese
        <?php endif; ?>
      </span>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- 2-3. NUOVI ARRIVI e SE TI PIACE. L'id "dashboard-recent-panel" è
     quello che app.js sostituisce nel refresh live: avvolge entrambe le
     sezioni, perché i suggerimenti dipendono dall'ultimo arrivo. -->
<div id="dashboard-recent-panel">

<!-- 2. NUOVI ARRIVI: gli ultimi dischi entrati nella collezione.
     Quantità fissa; per proseguire si passa all'Archivio nello stesso
     ordine. -->
<section class="grz-band" aria-labelledby="dash-arrivals-title">
  <header class="grz-band__head">
    <h2 class="grz-band__title" id="dash-arrivals-title">
      <i class="bi bi-box-seam" aria-hidden="true"></i><?= empty($arrivals) ? 'Per cominciare' : 'Nuovi arrivi' ?>
    </h2>
    <?php if (!empty($arrivals)): ?>
    <div class="grz-band__tools">
      <a href="<?= htmlspecialchars($arrivalsUrl) ?>" class="grz-band__link">
        Tutti per data di arrivo <i class="bi bi-arrow-right" aria-hidden="true"></i>
      </a>
      <?= grzRailNav('Nuovi arrivi') ?>
    </div>
    <?php endif; ?>
  </header>

  <?php if (empty($arrivals)): ?>
    <div class="grz-band__empty grz-welcome">
      <p class="grz-welcome__lead">L'archivio è vuoto. Puoi cominciare in due modi:</p>
      <ul class="grz-welcome__ways">
        <li>
          <strong>Aggiungere un disco a mano.</strong> Scrivi artista e titolo: copertina, anno, etichetta e tracklist arrivano dalle fonti.
          <a href="<?= BASE_URL ?>/index.php?route=albums/create" class="btn btn-sm btn-warning mt-2">
            <i class="bi bi-plus-lg me-1"></i>Aggiungi il primo disco
          </a>
        </li>
        <li>
          <strong>Importare una cartella di musica.</strong> La scansione automatica cataloga gli album che trova nella tua libreria.
          <a href="<?= BASE_URL ?>/index.php?route=settings" class="btn btn-sm btn-outline-secondary mt-2">
            <i class="bi bi-folder2-open me-1"></i>Configura la scansione
          </a>
        </li>
      </ul>
    </div>
  <?php else: ?>
    <div class="grz-rail" data-rail>
      <ul class="grz-rail__track">
        <?php foreach ($arrivals as $a):
          $tileFormats = !empty($a['formats']) ? $a['formats'] : [['name' => $a['format_name'] ?? '']];
          $tileTotalTracks = (int)($a['track_count'] ?? 0);
          $tileTracksAudio = (int)($a['tracks_with_audio_count'] ?? 0);
          $tileHasAudio    = $tileTotalTracks > 0 && $tileTracksAudio >= $tileTotalTracks;
          $tileAudioTitle  = $tileHasAudio
            ? 'Tutte le tracce hanno audio'
            : ($tileTracksAudio > 0
                ? $tileTracksAudio . ' di ' . $tileTotalTracks . ' tracce con audio'
                : 'Nessun file audio caricato');
          $cover = DashboardService::coverUrl($a['cover_local'] ?? null, $a['cover_url'] ?? null);
        ?>
          <li class="grz-rail__item grz-album-cell">
            <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= (int)$a['id'] ?>"
               class="grz-album-tile album-card">
              <div class="grz-album-tile__cover">
                <img src="<?= htmlspecialchars($cover !== '' ? $cover : BASE_URL . '/public/img/placeholder.png') ?>"
                     alt="" loading="lazy" decoding="async"
                     onerror="this.onerror=null;this.src='<?= BASE_URL ?>/public/img/placeholder.png'">
                <?php if ($tileTotalTracks > 0): ?>
                  <span class="grz-tile-tracks" title="<?= htmlspecialchars($tileAudioTitle) ?>">
                    <i class="bi <?= $tileHasAudio ? 'bi-music-note-beamed grz-track-audio' : 'bi-music-note grz-track-noaudio' ?>"></i><?= $tileTotalTracks ?>
                  </span>
                <?php endif; ?>
              </div>
              <div class="grz-album-tile__info">
                <span class="grz-album-tile__title"><?= htmlspecialchars($a['title']) ?></span>
                <span class="grz-album-tile__artist"><?= htmlspecialchars($a['artist_name']) ?></span>
                <span class="grz-arrival" title="Aggiunto il <?= htmlspecialchars(date('d/m/Y', strtotime((string)$a['created_at']))) ?>">
                  <?= htmlspecialchars(grzArrivalLabel((string)$a['created_at'])) ?>
                </span>
              </div>
            </a>
            <div class="grz-tile-badges">
              <?php foreach ($tileFormats as $fmt): ?>
                <span class="badge badge-format <?= formatBadgeClass($fmt['name']) ?>"><?= htmlspecialchars($fmt['name']) ?></span>
              <?php endforeach; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</section>

<?php if ($latest): ?>
<!-- 3. SE TI PIACE: dischi della collezione affini all'ultimo arrivo.
     Stesso motore dei suggerimenti della scheda disco (Last.fm per gli
     artisti simili, altrimenti solo stesso genere). Caricamento asincrono
     con la cache dell'endpoint; se non c'è nessun disco coerente la
     sezione resta nascosta. -->
<section class="grz-band grz-likes" id="dash-likes" hidden
  data-likes-url="<?= BASE_URL ?>/index.php?route=albums/api-recommendations/<?= (int)$latest['id'] ?>"
  aria-labelledby="dash-likes-title">
  <header class="grz-band__head">
    <h2 class="grz-band__title" id="dash-likes-title">
      <i class="bi bi-stars" aria-hidden="true"></i>
      <span>Se ti piace <cite><?= htmlspecialchars($latest['title']) ?></cite></span>
    </h2>
  </header>
  <ul class="grz-likes__grid"></ul>
</section>
<?php endif; ?>

</div><!-- /#dashboard-recent-panel -->

<!-- 4. PLAYLIST: nascosta con l'archivio vuoto, perché una playlist
     si compone con i brani dei dischi in archivio. -->
<?php if (!$isEmptyArchive): ?>
<section class="grz-band" id="dashboard-playlists" aria-labelledby="dash-playlists-title">
  <header class="grz-band__head">
    <h2 class="grz-band__title" id="dash-playlists-title">
      <i class="bi bi-collection-play" aria-hidden="true"></i>Le tue playlist
    </h2>
    <div class="grz-band__tools">
      <a href="<?= BASE_URL ?>/index.php?route=playlists" class="grz-band__link">
        Tutte le playlist <i class="bi bi-arrow-right" aria-hidden="true"></i>
      </a>
      <?php if (!empty($playlists)): ?><?= grzRailNav('Playlist') ?><?php endif; ?>
    </div>
  </header>

  <?php if (empty($playlists)): ?>
    <div class="grz-band__empty">
      <p class="mb-2">Nessuna playlist ancora. Una playlist raccoglie brani di dischi diversi, da ascoltare di seguito.</p>
      <a href="<?= BASE_URL ?>/index.php?route=playlists" class="btn btn-sm btn-warning">
        <i class="bi bi-plus-lg me-1"></i>Crea una playlist
      </a>
    </div>
  <?php else: ?>
    <div class="grz-rail" data-rail>
      <ul class="grz-rail__track">
        <?php foreach ($playlists as $pl):
          $total    = $pl['total_tracks'];
          $playable = $pl['playable_tracks'];
          $isFull   = $total > 0 && $playable === $total;
          $isEmpty  = $total === 0;
          $plUrl    = BASE_URL . '/index.php?route=playlists/detail/' . $pl['id'];
        ?>
          <li class="grz-rail__item grz-rail__item--playlist grz-dpl-item" data-playlist-id="<?= $pl['id'] ?>">
            <div class="grz-dpl">
              <div class="grz-dpl__media">
                <a href="<?= $plUrl ?>" class="grz-dpl__art" tabindex="-1" aria-hidden="true">
                  <?= grzPlaylistMosaic($pl['covers']) ?>
                </a>
                <?php if ($playable > 0): ?>
                  <button type="button" class="grz-dpl__play" data-pl-play
                    data-playlist-id="<?= $pl['id'] ?>"
                    onclick="PlaylistPlayer.load(<?= $pl['id'] ?>)"
                    title="Riproduci <?= htmlspecialchars($pl['name'], ENT_QUOTES) ?>"
                    aria-label="Riproduci <?= htmlspecialchars($pl['name'], ENT_QUOTES) ?>">
                    <i class="bi bi-play-fill"></i>
                  </button>
                <?php endif; ?>
              </div>
              <div class="grz-dpl__info">
                <a href="<?= $plUrl ?>" class="grz-dpl__name"><?= htmlspecialchars($pl['name']) ?></a>
                <span class="grz-dpl__meta">
                  <?php if ($isEmpty): ?>
                    vuota
                  <?php elseif ($isFull): ?>
                    <?= $total ?> <?= $total === 1 ? 'traccia' : 'tracce' ?>
                  <?php else: ?>
                    <span class="grz-dpl__partial"><?= $playable ?>/<?= $total ?> con audio</span>
                  <?php endif; ?>
                </span>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
        <li class="grz-rail__item grz-rail__item--playlist">
          <a href="<?= BASE_URL ?>/index.php?route=playlists" class="grz-dpl grz-dpl--new">
            <span class="grz-dpl__newicon"><i class="bi bi-plus-lg" aria-hidden="true"></i></span>
            <span class="grz-dpl__name">Nuova playlist</span>
          </a>
        </li>
      </ul>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($dayPick): 
  $dpCover = DashboardService::coverUrl($dayPick['cover_local'] ?? null, $dayPick['cover_url'] ?? null);
  $dpCover = $dpCover !== '' ? $dpCover : BASE_URL . '/public/img/placeholder.png';
  $dpUrl   = BASE_URL . '/index.php?route=albums/detail/' . (int)$dayPick['id'];
  $dpCond  = grzConditionCode((string)($dayPick['cond'] ?? ''));
?>
<!-- 5. DAL TUO SCAFFALE: disco del giorno e anniversari di uscita. -->
<section class="grz-band" aria-labelledby="dash-shelf-title">
  <header class="grz-band__head">
    <h2 class="grz-band__title" id="dash-shelf-title">
      <i class="bi bi-vinyl" aria-hidden="true"></i>Dal tuo scaffale
    </h2>
  </header>

  <div class="grz-shelf<?= empty($anniv) ? ' grz-shelf--single' : '' ?>">
    <article class="grz-daypick" aria-labelledby="dash-daypick-title">
      <div class="grz-daypick__bg" style="background-image: url('<?= htmlspecialchars($dpCover, ENT_QUOTES) ?>')" aria-hidden="true"></div>
      <a href="<?= $dpUrl ?>" class="grz-daypick__cover" tabindex="-1" aria-hidden="true">
        <img src="<?= htmlspecialchars($dpCover) ?>" alt="" loading="lazy" decoding="async"
          onerror="this.onerror=null;this.src='<?= BASE_URL ?>/public/img/placeholder.png'">
      </a>
      <div class="grz-daypick__body">
        <p class="grz-daypick__kicker">Disco del giorno</p>
        <h3 class="grz-daypick__title" id="dash-daypick-title">
          <a href="<?= $dpUrl ?>"><?= htmlspecialchars($dayPick['title']) ?></a>
        </h3>
        <a class="grz-daypick__artist" href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= (int)$dayPick['artist_id'] ?>"><?= htmlspecialchars($dayPick['artist_name']) ?></a>
        <dl class="grz-daypick__facts">
          <?php if (!empty($dayPick['year'])): ?>
            <div><dt>Uscita</dt><dd><?= (int)$dayPick['year'] ?></dd></div>
          <?php endif; ?>
          <?php if (!empty($dayPick['formats'])): ?>
            <div><dt>Formato</dt><dd><?= htmlspecialchars(implode(', ', $dayPick['formats'])) ?></dd></div>
          <?php endif; ?>
          <?php if ($dpCond !== ''): ?>
            <div><dt>Conservazione</dt><dd><?= htmlspecialchars($dpCond) ?></dd></div>
          <?php endif; ?>
          <?php if ((int)($dayPick['copies'] ?? 1) > 1): ?>
            <div><dt>Copie</dt><dd><?= (int)$dayPick['copies'] ?></dd></div>
          <?php endif; ?>
          <?php if ((int)($dayPick['track_count'] ?? 0) > 0): ?>
            <div><dt>Tracce</dt><dd><?= (int)$dayPick['track_count'] ?></dd></div>
          <?php endif; ?>
          <div><dt>Caricato</dt><dd><?= htmlspecialchars(grzArrivalLabel((string)$dayPick['created_at'])) ?></dd></div>
        </dl>
        <?php if (!empty($dayPick['notes'])): ?>
          <p class="grz-daypick__notes"><?= htmlspecialchars(mb_strimwidth((string)$dayPick['notes'], 0, 180, '…', 'UTF-8')) ?></p>
        <?php endif; ?>
        <a href="<?= $dpUrl ?>" class="btn btn-sm btn-warning grz-daypick__cta">
          <i class="bi bi-disc me-1" aria-hidden="true"></i>Apri il disco
        </a>
      </div>
    </article>

    <?php if (!empty($anniv)): ?>
    <!-- Alto quanto la card del Disco del giorno: se i dischi sono di
         più, l'elenco si scorre all'interno (rotella, dito o frecce). -->
    <div class="grz-anniv" aria-labelledby="dash-anniv-title">
      <div class="grz-anniv__head">
        <h3 class="grz-comp__title" id="dash-anniv-title">
          Compiono gli anni nel <?= date('Y') ?>
          <span class="grz-anniv__count"><?= count($anniv) ?></span>
        </h3>
        <div class="grz-rail__nav" data-anniv-nav hidden>
          <button type="button" class="grz-rail__btn" data-dir="-1" aria-label="Anniversari: scorri su"><i class="bi bi-chevron-up" aria-hidden="true"></i></button>
          <button type="button" class="grz-rail__btn" data-dir="1" aria-label="Anniversari: scorri giù"><i class="bi bi-chevron-down" aria-hidden="true"></i></button>
        </div>
      </div>
      <ol class="grz-anniv__list" data-anniv-list tabindex="0" aria-label="Elenco degli anniversari">
        <?php foreach ($anniv as $an): ?>
          <li>
            <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $an['id'] ?>" class="grz-anniv__row">
              <span class="grz-anniv__age"><?= $an['age'] ?><small>anni</small></span>
              <span class="grz-anniv__text">
                <span class="grz-anniv__title"><?= htmlspecialchars($an['title']) ?></span>
                <span class="grz-anniv__meta"><?= htmlspecialchars($an['artist_name']) ?>, <?= $an['year'] ?></span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- 6-7. GENERI E COMPOSIZIONE. L'id storico "dashboard-top-artists"
     è quello che app.js sostituisce nel refresh live: avvolge tutto il
     blocco, così anche generi, decenni ed etichette restano aggiornati
     quando lo scanner aggiunge dischi. -->
<div id="dashboard-top-artists">

<?php if (!empty($genres)): ?>
<section class="grz-band" aria-labelledby="dash-genres-title">
  <header class="grz-band__head">
    <h2 class="grz-band__title" id="dash-genres-title">
      <i class="bi bi-tags" aria-hidden="true"></i>Generi
    </h2>
    <span class="grz-band__note"><?= $genreCount ?> generi in archivio</span>
  </header>
  <ul class="grz-genres">
    <?php foreach ($genres as $g): ?>
      <li>
        <a class="grz-genre" href="<?= BASE_URL ?>/index.php?route=albums/list&genre_id=<?= $g['id'] ?>">
          <?= htmlspecialchars($g['name']) ?><span class="grz-genre__count"><?= $g['count'] ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($hasComposition): ?>
<section class="grz-band" aria-labelledby="dash-comp-title">
  <header class="grz-band__head">
    <h2 class="grz-band__title" id="dash-comp-title">
      <i class="bi bi-bar-chart" aria-hidden="true"></i>Statistiche
    </h2>
  </header>

  <div class="grz-comp">
    <?php if (!empty($decades)): ?>
    <div class="grz-comp__panel">
      <h3 class="grz-comp__title">Dischi per decennio di uscita</h3>
      <div class="grz-decades" role="img"
        aria-label="Dischi per decennio: <?= htmlspecialchars(implode(', ', array_map(function ($d) { return 'anni ' . $d['decade'] . ' ' . $d['count']; }, $decades))) ?>">
        <?php foreach ($decades as $d):
          $h = max(4, (int)round($d['count'] / $decadeMax * 100));
        ?>
          <div class="grz-decades__col" title="Anni <?= $d['decade'] ?>: <?= $d['count'] ?> dischi">
            <span class="grz-decades__num" aria-hidden="true"><?= $d['count'] ?></span>
            <span class="grz-decades__bar" style="height: <?= $h ?>%" aria-hidden="true"></span>
            <span class="grz-decades__lbl" aria-hidden="true"><?= $d['decade'] >= 2000 ? $d['decade'] : "'" . substr((string)$d['decade'], 2) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($topArtists)): ?>
    <div class="grz-comp__panel">
      <h3 class="grz-comp__title">Artisti più presenti</h3>
      <ol class="grz-ranklist">
        <?php foreach ($topArtists as $ar): ?>
          <li>
            <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= (int)$ar['id'] ?>" class="grz-ranklist__row">
              <span class="grz-ranklist__name"><?= htmlspecialchars($ar['name']) ?></span>
              <span class="grz-ranklist__count"><?= (int)($ar['album_count'] ?? 0) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
    <?php endif; ?>

    <?php if (!empty($labels)): ?>
    <div class="grz-comp__panel">
      <h3 class="grz-comp__title">Etichette</h3>
      <ol class="grz-ranklist">
        <?php foreach ($labels as $l): ?>
          <li>
            <a href="<?= BASE_URL ?>/index.php?route=albums/list&label_id=<?= $l['id'] ?>" class="grz-ranklist__row">
              <span class="grz-ranklist__name"><?= htmlspecialchars($l['name']) ?></span>
              <span class="grz-ranklist__count"><?= $l['count'] ?></span>
              <span class="grz-ranklist__bar" style="width: <?= (int)round($l['count'] / $labelMax * 100) ?>%" aria-hidden="true"></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

</div><!-- /#dashboard-top-artists -->

</div><!-- /.grz-dash -->


<script>
(function () {
  /* ── Stato di riproduzione sulle schede playlist ── */
  function getAudio() { return document.getElementById('global-audio'); }

  function syncDashboardPlaylistUI() {
    var audio    = getAudio();
    // Playlist attiva: il contesto del Player è la fonte di verità;
    // activeId() resta come ripiego per versioni senza context().
    var activeId = null;
    if (typeof Player !== 'undefined' && typeof Player.context === 'function') {
      var ctx = Player.context();
      activeId = ctx.indexOf('playlist:') === 0 ? parseInt(ctx.slice(9), 10) : null;
    } else if (typeof PlaylistPlayer !== 'undefined' && typeof PlaylistPlayer.activeId === 'function') {
      activeId = parseInt(PlaylistPlayer.activeId(), 10);
    }
    var hasSource = !!(audio && audio.src);
    var isPlaying = !!(audio && audio.src && !audio.paused);

    document.querySelectorAll('.grz-dpl-item[data-playlist-id]').forEach(function (row) {
      var pid = parseInt(row.dataset.playlistId, 10);
      row.classList.toggle('is-player-playing', pid === activeId && isPlaying);
      row.classList.toggle('is-player-paused',  pid === activeId && !isPlaying && hasSource);
    });

    document.querySelectorAll('[data-pl-play][data-playlist-id]').forEach(function (btn) {
      var pid = parseInt(btn.dataset.playlistId, 10);
      var isActive = pid === activeId;
      btn.classList.remove('is-player-playing', 'is-player-paused');
      if (isActive && isPlaying) {
        btn.innerHTML = '<i class="bi bi-pause-fill"></i>'; btn.title = 'Pausa';
        btn.classList.add('is-player-playing');
        btn.onclick = function () { var a = getAudio(); if (a && a.src) a.pause(); };
        return;
      }
      if (isActive && !isPlaying && hasSource) {
        btn.innerHTML = '<i class="bi bi-play-fill"></i>'; btn.title = 'Riprendi';
        btn.classList.add('is-player-paused');
        btn.onclick = function () { var a = getAudio(); if (a && a.src) a.play().catch(function (e) { console.warn(e.message); }); };
        return;
      }
      btn.innerHTML = '<i class="bi bi-play-fill"></i>'; btn.title = 'Riproduci';
      btn.onclick = function () { PlaylistPlayer.load(parseInt(this.dataset.playlistId, 10)); };
    });
  }

  (function bindAudio() {
    var audio = getAudio();
    if (!audio || audio.dataset.dashboardPlaylistBound === '1') return;
    audio.dataset.dashboardPlaylistBound = '1';
    ['play', 'pause', 'ended'].forEach(function (ev) { audio.addEventListener(ev, syncDashboardPlaylistUI); });
  })();
  syncDashboardPlaylistUI();
  window.__syncPlaylistListUI = syncDashboardPlaylistUI;

  /* ── File orizzontali: frecce di scorrimento ──
     Idempotente: app.js lo richiama dopo il refresh live con il nome
     storico __initDashboardGrid. Le frecce spariscono se la fila non
     trabocca e si disattivano agli estremi. */
  function initRails() {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.querySelectorAll('.grz-band').forEach(function (band) {
      var rail = band.querySelector('[data-rail]');
      var nav  = band.querySelector('.grz-rail__nav');
      if (!rail || !nav || rail.dataset.railBound === '1') return;
      rail.dataset.railBound = '1';

      var prev = nav.querySelector('[data-dir="-1"]');
      var next = nav.querySelector('[data-dir="1"]');

      function update() {
        var max = rail.scrollWidth - rail.clientWidth;
        nav.hidden = max <= 2;
        if (prev) prev.disabled = rail.scrollLeft <= 2;
        if (next) next.disabled = rail.scrollLeft >= max - 2;
      }

      [prev, next].forEach(function (btn) {
        if (!btn) return;
        btn.addEventListener('click', function () {
          var dir = parseInt(btn.dataset.dir, 10);
          rail.scrollBy({ left: dir * rail.clientWidth * 0.85, behavior: reduce ? 'auto' : 'smooth' });
        });
      });

      rail.addEventListener('scroll', function () {
        if (rail.__raf) return;
        rail.__raf = requestAnimationFrame(function () { rail.__raf = 0; update(); });
      }, { passive: true });

      if ('ResizeObserver' in window) {
        new ResizeObserver(update).observe(rail);
      } else {
        window.addEventListener('resize', update);
      }
      update();
    });
  }

  /* ── "Se ti piace": suggerimenti dall'endpoint della scheda disco ──
     Mostra fino a tre dischi. Se la risposta è vuota o va in errore la
     sezione resta nascosta: qui è un contenuto in più, non una funzione
     attesa. Idempotente, come initRails. */
  function initLikes() {
    var sec = document.getElementById('dash-likes');
    if (!sec || sec.dataset.likesLoaded === '1') return;
    sec.dataset.likesLoaded = '1';
    var url  = sec.getAttribute('data-likes-url');
    var grid = sec.querySelector('.grz-likes__grid');
    if (!url || !grid) return;

    var ctrl  = ('AbortController' in window) ? new AbortController() : null;
    var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, 15000) : null;

    fetch(url, { cache: 'no-store', signal: ctrl ? ctrl.signal : undefined })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (timer) clearTimeout(timer);
        var items = (data && Array.isArray(data.recommendations)) ? data.recommendations.slice(0, 3) : [];
        if (!items.length || !document.body.contains(sec)) return;

        grid.textContent = '';
        items.forEach(function (it) {
          var li = document.createElement('li');
          var a  = document.createElement('a');
          a.className = 'grz-like';
          a.href = it.url;

          var img = document.createElement('img');
          img.className = 'grz-like__cover';
          img.src = it.cover;
          img.alt = '';
          img.loading = 'lazy';
          img.onerror = function () { this.onerror = null; this.src = BASE_URL_DASH + '/public/img/placeholder.png'; };

          var body = document.createElement('span');
          body.className = 'grz-like__body';
          var kick = document.createElement('span');
          kick.className = 'grz-like__kicker';
          kick.textContent = 'Dalla tua collezione';
          var t = document.createElement('span');
          t.className = 'grz-like__title';
          t.textContent = it.title;
          var ar = document.createElement('span');
          ar.className = 'grz-like__artist';
          ar.textContent = it.artist_name;
          body.appendChild(kick); body.appendChild(t); body.appendChild(ar);
          if (it.year) {
            var y = document.createElement('span');
            y.className = 'grz-like__year';
            y.textContent = it.year;
            body.appendChild(y);
          }

          a.appendChild(img); a.appendChild(body); li.appendChild(a); grid.appendChild(li);
        });
        sec.hidden = false;
      })
      .catch(function () { if (timer) clearTimeout(timer); });
  }

  var BASE_URL_DASH = <?= json_encode(BASE_URL, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;

  /* ── Anniversari: scorrimento verticale dentro la colonna ──
     Frecce su/giù visibili solo se l'elenco trabocca; la dissolvenza in
     basso segnala che ci sono altri dischi. Idempotente. */
  function initAnniv() {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.querySelectorAll('[data-anniv-list]').forEach(function (list) {
      if (list.dataset.annivBound === '1') return;
      list.dataset.annivBound = '1';
      var box  = list.closest('.grz-anniv');
      var nav  = box ? box.querySelector('[data-anniv-nav]') : null;
      var up   = nav ? nav.querySelector('[data-dir="-1"]') : null;
      var down = nav ? nav.querySelector('[data-dir="1"]') : null;

      function update() {
        var max = list.scrollHeight - list.clientHeight;
        var overflow = max > 2;
        if (nav) nav.hidden = !overflow;
        if (up) up.disabled = list.scrollTop <= 2;
        if (down) down.disabled = list.scrollTop >= max - 2;
        list.classList.toggle('has-more', overflow && list.scrollTop < max - 2);
      }

      [up, down].forEach(function (btn) {
        if (!btn) return;
        btn.addEventListener('click', function () {
          var dir = parseInt(btn.dataset.dir, 10);
          list.scrollBy({ top: dir * list.clientHeight * 0.8, behavior: reduce ? 'auto' : 'smooth' });
        });
      });
      list.addEventListener('scroll', function () {
        if (list.__raf) return;
        list.__raf = requestAnimationFrame(function () { list.__raf = 0; update(); });
      }, { passive: true });
      if ('ResizeObserver' in window) {
        new ResizeObserver(update).observe(list);
      } else {
        window.addEventListener('resize', update);
      }
      update();
    });
  }

  // app.js richiama __initDashboardGrid dopo il refresh live: riaggancia
  // le frecce delle file e ricarica i suggerimenti del nuovo blocco.
  window.__initDashboardGrid = function () { initRails(); initLikes(); initAnniv(); };
  window.__initDashboardGrid();
})();
</script>

<?php
require BASE_PATH . '/views/layout/footer.php';

/**
 * Pulsanti di scorrimento di una fila orizzontale.
 */
function grzRailNav(string $label): string
{
  $l = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
  return '<div class="grz-rail__nav" hidden>'
    . '<button type="button" class="grz-rail__btn" data-dir="-1" aria-label="' . $l . ': scorri indietro"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>'
    . '<button type="button" class="grz-rail__btn" data-dir="1" aria-label="' . $l . ': scorri avanti"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>'
    . '</div>';
}

/**
 * Data di arrivo in forma leggibile: "oggi", "ieri", "3 giorni fa",
 * "2 settimane fa", poi mese e anno ("luglio 2026").
 */
function grzArrivalLabel(string $createdAt): string
{
  $ts = strtotime($createdAt);
  if (!$ts) {
    return '';
  }
  $today = new DateTime('today');
  $day   = (new DateTime())->setTimestamp($ts)->setTime(0, 0, 0);
  $days  = (int)$day->diff($today)->format('%r%a');

  if ($days <= 0) return 'oggi';
  if ($days === 1) return 'ieri';
  if ($days < 7) return $days . ' giorni fa';
  if ($days < 28) {
    $w = intdiv($days, 7);
    return $w === 1 ? 'una settimana fa' : $w . ' settimane fa';
  }
  $mesi = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio',
           'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
  return $mesi[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

/**
 * Sigla dello stato di conservazione, come nei pulsanti del form
 * (M, NM, VG+, VG, G+, G, F, P). Valori sconosciuti restano invariati.
 */
function grzConditionCode(string $cond): string
{
  $map = [
    'mint' => 'M', 'near mint' => 'NM', 'very good plus' => 'VG+', 'very good' => 'VG',
    'good plus' => 'G+', 'good' => 'G', 'fair' => 'F', 'poor' => 'P',
  ];
  $k = strtolower(trim($cond));
  return $map[$k] ?? trim($cond);
}

function formatBadgeClass(string $format): string
{
  switch ($format) {
    case 'Vinile':       return 'bg-warning';
    case 'CD':           return 'bg-info';
    case 'Musicassetta':
    case 'Tape':         return 'bg-success';
    case 'Digital':      return 'bg-primary';
    default:             return 'bg-secondary';
  }
}

function formatBadgeColor(string $format): string
{
  switch ($format) {
    case 'Vinile':       return 'warning';
    case 'CD':           return 'info';
    case 'Musicassetta':
    case 'Tape':         return 'success';
    case 'Digital':      return 'primary';
    default:             return 'secondary';
  }
}
?>

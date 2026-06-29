<?php
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/models/Album.php';
require_once BASE_PATH . '/app/models/Artist.php';

$albumModel  = new Album();
$artistModel = new Artist();
$stats       = $albumModel->getStats();
$recent      = $albumModel->getAll([], 'a.created_at', 'DESC');
$recent      = array_slice($recent, 0, 24);
$topArtists  = $artistModel->getTopArtists(5);
$pageTitle   = 'Dashboard';

$db = Database::getInstance();
$stmtPl = $db->query("
    SELECT
        p.id,
        p.name,
        COUNT(pt.id)                                        AS total_tracks,
        SUM(CASE WHEN af.id IS NOT NULL THEN 1 ELSE 0 END) AS playable_tracks
    FROM playlists p
    LEFT JOIN playlist_tracks pt ON pt.playlist_id = p.id
    LEFT JOIN audio_files af     ON af.track_id    = pt.track_id
    GROUP BY p.id, p.name
    ORDER BY p.created_at DESC
    LIMIT 5
");
$recentPlaylists = $stmtPl->fetchAll(PDO::FETCH_ASSOC);

require BASE_PATH . '/views/layout/header.php';
?>

<!-- HERO -->
<div class="grz-dash-hero">
  <div class="grz-dash-hero__eyebrow">
    <span class="grz-dash-hero__dot"></span>
    <span>Archivio attivo</span>
  </div>
  <h1 class="grz-dash-hero__title">La tua collezione</h1>
  <p class="grz-dash-hero__count"><?= (int)($stats['total'] ?? 0) ?> dischi</p>
</div>

<!-- KPI -->
<div class="grz-kpi-strip">
  <?php
  $kpis = [
    ['label' => 'Vinili',       'value' => $stats['vinili'],        'icon' => 'vinyl-fill',             'accent' => 'vinyl',   'sub' => 'LP · EP · 7"'],
    ['label' => 'CD',           'value' => $stats['cd'],            'icon' => 'disc',                   'accent' => 'cd',      'sub' => 'Compact Disc'],
    ['label' => 'Musicassette', 'value' => $stats['cassette'],      'icon' => 'cassette-fill',          'accent' => 'tape',    'sub' => 'Cassette'],
    ['label' => 'Digital',      'value' => $stats['digital'] ?? 0,  'icon' => 'cloud-arrow-down-fill',  'accent' => 'digital', 'sub' => 'File digitali'],
    ['label' => 'Artisti',      'value' => $stats['artisti'],       'icon' => 'people-fill',            'accent' => 'artist',  'sub' => 'nel database'],
    ['label' => 'Generi',       'value' => $stats['generi'],        'icon' => 'tags-fill',              'accent' => 'genre',   'sub' => 'categorie'],
  ];
  foreach ($kpis as $k): ?>
    <div class="grz-kpi-card grz-kpi-card--<?= $k['accent'] ?>">
      <div class="grz-kpi-card__icon"><i class="bi bi-<?= $k['icon'] ?>"></i></div>
      <div class="grz-kpi-card__body">
        <div class="grz-kpi-card__value"><?= $k['value'] ?? 0 ?></div>
        <div class="grz-kpi-card__label"><?= $k['label'] ?></div>
        <div class="grz-kpi-card__sub"><?= $k['sub'] ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- MAIN GRID -->
<div class="grz-dash-grid">
  <div class="grz-dash-main">
    <div class="grz-section-header">
      <span class="grz-section-header__label">
        <i class="bi bi-clock-history"></i>Aggiunti di recente
      </span>
      <a href="<?= BASE_URL ?>/index.php?route=albums/list" class="grz-section-header__link">
        Tutto l'archivio <i class="bi bi-arrow-right"></i>
      </a>
    </div>

    <!-- Tutte le celle presenti nell'HTML, visibilità gestita da JS -->
    <div class="grz-album-grid" id="recent-albums-grid">
      <?php foreach ($recent as $i => $a):
        $fmt        = $a['format_name'] ?? '';
        $badgeClass = formatBadgeClass($fmt);
      ?>
        <div class="grz-album-cell">
          <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>"
             class="grz-album-tile album-card">
            <div class="grz-album-tile__cover">
              <img src="<?= $a['cover_local']
                          ? BASE_URL . '/public/uploads/' . htmlspecialchars($a['cover_local'])
                          : BASE_URL . '/public/img/placeholder.png' ?>"
                   alt="<?= htmlspecialchars($a['title']) ?>"
                   loading="lazy">
              <span class="badge badge-format <?= $badgeClass ?> grz-cover-badge">
                <?= htmlspecialchars($fmt) ?>
              </span>
            </div>
            <div class="grz-album-tile__info">
              <span class="grz-album-tile__title"><?= htmlspecialchars($a['title']) ?></span>
              <span class="grz-album-tile__artist"><?= htmlspecialchars($a['artist_name']) ?></span>
              <?php if (!empty($a['year'])): ?>
                <span class="grz-album-tile__year"><?= (int)$a['year'] ?></span>
              <?php endif; ?>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (count($recent) > 3): ?>
      <div class="grz-show-more">
        <button id="btn-show-more-albums" class="grz-btn-show-more">
          <i class="bi bi-plus-lg"></i>
          Mostra altri
        </button>
      </div>
    <?php endif; ?>
  </div>

  <!-- SIDEBAR -->
  <div class="grz-dash-sidebar">
    <div class="grz-dash-panel">
      <div class="grz-section-header grz-section-header--panel">
        <span class="grz-section-header__label">
          <i class="bi bi-bar-chart-fill"></i>Top artisti
        </span>
      </div>
      <ul class="grz-top-artists">
        <?php foreach ($topArtists as $rank => $ar): ?>
          <li class="grz-top-artist-row">
            <span class="grz-top-artist-row__rank"><?= $rank + 1 ?></span>
            <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= $ar['id'] ?>"
               class="grz-top-artist-row__name"><?= htmlspecialchars($ar['name']) ?></a>
            <span class="grz-top-artist-row__count"><?= $ar['album_count'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="grz-dash-panel">
      <div class="grz-section-header grz-section-header--panel">
        <span class="grz-section-header__label">
          <i class="bi bi-collection-play"></i>Playlist
        </span>
        <a href="<?= BASE_URL ?>/index.php?route=playlists" class="grz-section-header__link">
          Tutte <i class="bi bi-arrow-right"></i>
        </a>
      </div>

      <?php if (empty($recentPlaylists)): ?>
        <div class="dash-playlist-empty text-center py-4">
          <i class="bi bi-collection-play fs-2 d-block mb-2 text-muted opacity-25"></i>
          <p class="text-muted small mb-2">Nessuna playlist ancora.</p>
          <a href="<?= BASE_URL ?>/index.php?route=playlists" class="btn btn-xs btn-warning">
            <i class="bi bi-plus-lg me-1"></i>Crea una playlist
          </a>
        </div>
      <?php else: ?>
        <div class="dash-playlist-list">
          <?php foreach ($recentPlaylists as $pl):
            $total    = (int)$pl['total_tracks'];
            $playable = (int)$pl['playable_tracks'];
            $isFull   = $total > 0 && $playable === $total;
            $isEmpty  = $total === 0;
          ?>
            <div class="dash-playlist-row" data-playlist-id="<?= (int)$pl['id'] ?>">
              <span class="dash-pl-dot <?= $isFull ? 'dot-full' : ($isEmpty ? 'dot-empty' : 'dot-partial') ?>"></span>
              <div class="flex-grow-1 overflow-hidden">
                <a href="<?= BASE_URL ?>/index.php?route=playlists/detail/<?= (int)$pl['id'] ?>"
                   class="dash-pl-name text-truncate d-block"><?= htmlspecialchars($pl['name']) ?></a>
                <span class="dash-pl-meta">
                  <?php if ($isEmpty): ?>
                    <span class="text-muted">vuota</span>
                  <?php elseif ($isFull): ?>
                    <span class="text-success"><?= $total ?> <?= $total === 1 ? 'traccia' : 'tracce' ?></span>
                  <?php else: ?>
                    <span class="text-warning"><?= $playable ?>/<?= $total ?> con audio</span>
                  <?php endif; ?>
                </span>
              </div>
              <?php if ($playable > 0): ?>
                <button class="dash-pl-play btn-play-dash"
                  data-playlist-id="<?= (int)$pl['id'] ?>"
                  onclick="PlaylistPlayer.load(<?= (int)$pl['id'] ?>)"
                  title="Riproduci <?= htmlspecialchars($pl['name'], ENT_QUOTES) ?>">
                  <i class="bi bi-play-fill"></i>
                </button>
              <?php else: ?>
                <span class="dash-pl-play disabled" title="Nessun audio disponibile">
                  <i class="bi bi-play-fill"></i>
                </span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>


<script>
(function () {
  /* ── Playlist player sync ── */
  function getAudio() { return document.getElementById('global-audio'); }

  function syncDashboardPlaylistUI() {
    var audio    = getAudio();
    var activeId = (typeof PlaylistPlayer !== 'undefined' && typeof PlaylistPlayer.activeId === 'function')
      ? parseInt(PlaylistPlayer.activeId(), 10) : null;
    var hasSource = !!(audio && audio.src);
    var isPlaying = !!(audio && audio.src && !audio.paused);

    document.querySelectorAll('.dash-playlist-row[data-playlist-id]').forEach(function (row) {
      var pid = parseInt(row.dataset.playlistId, 10);
      row.classList.toggle('is-player-playing', pid === activeId && isPlaying);
      row.classList.toggle('is-player-paused',  pid === activeId && !isPlaying && hasSource);
    });

    document.querySelectorAll('.dash-pl-play[data-playlist-id]').forEach(function (btn) {
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

  /* ── Album grid: "Mostra altri" aggiunge una riga per volta.
     Le colonne sono fisse via CSS (4 desktop, 3 tablet, 2 mobile).
     Il JS mostra solo multipli esatti di 4/3/2 in base al breakpoint CSS corrente,
     così le righe sono sempre complete senza misurare nulla. ── */
  (function () {
    var grid     = document.getElementById('recent-albums-grid');
    var btn      = document.getElementById('btn-show-more-albums');
    var wrapMore = btn ? btn.closest('.grz-show-more') : null;
    if (!grid) return;

    var cells = Array.prototype.slice.call(grid.querySelectorAll('.grz-album-cell'));
    var total = cells.length;

    /* Legge le colonne CSS attuali dal computed style della griglia —
       nessuna misura manuale, usa direttamente ciò che ha già calcolato il browser */
    function getCols() {
      var style = window.getComputedStyle(grid);
      var tpl   = style.getPropertyValue('grid-template-columns');
      /* conta i valori separati da spazio: "Xpx Xpx Xpx" → 3 colonne */
      var parts = tpl.trim().split(/\s+/);
      return Math.max(1, parts.length);
    }

    var visRows = 2;

    function render() {
      var n       = getCols();
      var visible = Math.min(visRows * n, total);

      cells.forEach(function (c, i) {
        c.classList.toggle('d-none', i >= visible);
      });

      if (wrapMore) {
        wrapMore.style.display = visible >= total ? 'none' : '';
      }
    }

    if (btn) {
      btn.addEventListener('click', function () {
        visRows += 1;
        render();
      });
    }

    window.addEventListener('resize', render);

    render();
  })();
})();
</script>

<?php
require BASE_PATH . '/views/layout/footer.php';

function formatBadgeClass(string $format): string
{
  switch ($format) {
    case 'Vinile':       return 'bg-warning';
    case 'CD':           return 'bg-info';
    case 'Musicassetta': return 'bg-success';
    case 'Digital':      return 'bg-primary';
    default:             return 'bg-secondary';
  }
}

function formatBadgeColor(string $format): string
{
  switch ($format) {
    case 'Vinile':       return 'warning';
    case 'CD':           return 'info';
    case 'Musicassetta': return 'success';
    case 'Digital':      return 'primary';
    default:             return 'secondary';
  }
}
?>

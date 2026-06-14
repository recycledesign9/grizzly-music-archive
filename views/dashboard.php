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

// Ultime 5 playlist con conteggio tracce totali e riproducibili
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

<div class="row g-3 mb-4">
  <!-- Stat cards -->
  <?php
  $cards = [
    ['label' => 'Totale dischi',   'value' => $stats['total'],    'icon' => 'collection',    'color' => 'primary'],
    ['label' => 'Vinili',          'value' => $stats['vinili'],   'icon' => 'vinyl-fill',    'color' => 'warning'],
    ['label' => 'CD',              'value' => $stats['cd'],       'icon' => 'disc',          'color' => 'info'],
    ['label' => 'Musicassette',    'value' => $stats['cassette'], 'icon' => 'cassette-fill', 'color' => 'success'],
    ['label' => 'Artisti',         'value' => $stats['artisti'],  'icon' => 'people-fill',   'color' => 'secondary'],
    ['label' => 'Generi',          'value' => $stats['generi'],   'icon' => 'tags-fill',     'color' => 'danger'],
  ];
  foreach ($cards as $c): ?>
    <div class="col-6 col-md-4 col-xl-2">
      <div class="card text-center h-100 border-0 shadow-sm">
        <div class="card-body">
          <i class="bi bi-<?= $c['icon'] ?> fs-2 text-<?= $c['color'] ?>"></i>
          <div class="fs-3 fw-bold mt-1"><?= $c['value'] ?? 0 ?></div>
          <div class="text-muted small"><?= $c['label'] ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-4">
  <!-- Ultimi aggiunti -->
  <div class="col-lg-8">
    <h5 class="mb-3"><i class="bi bi-clock-history me-2"></i>Aggiunti di recente</h5>
    <div class="row g-3" id="recent-albums-grid">
      <?php foreach ($recent as $i => $a): ?>
        <div class="col-sm-6 col-md-4 recent-album-item<?= $i >= 6 ? ' d-none' : '' ?>">
          <div class="card h-100 shadow-sm album-card">
            <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>"
               class="position-relative d-block">
              <img src="<?= $a['cover_local']
                          ? BASE_URL . '/public/uploads/' . htmlspecialchars($a['cover_local'])
                          : BASE_URL . '/public/img/placeholder.png' ?>"
                class="card-img-top cover-thumb" alt="Cover">
              <span class="badge bg-<?= formatBadgeColor($a['format_name']) ?> position-absolute top-0 end-0 m-1 shadow-sm">
                <?= htmlspecialchars($a['format_name']) ?>
              </span>
            </a>
            <div class="card-body p-2">
              <p class="mb-0 fw-semibold small lh-sm">
                <?= htmlspecialchars($a['title']) ?>
              </p>
              <p class="text-muted x-small mb-0 d-flex justify-content-between">
                <span><?= htmlspecialchars($a['artist_name']) ?></span>
                <?php if (!empty($a['year'])): ?>
                  <span class="fw-semibold"><?= (int)$a['year'] ?></span>
                <?php endif; ?>
              </p>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (count($recent) > 6): ?>
      <div class="text-center mt-3">
        <button id="btn-show-more-albums" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-plus-lg me-1"></i>Mostra altri
          <span class="badge bg-secondary ms-1" id="remaining-albums-count">
            <?= min(6, count($recent) - 6) ?>
          </span>
        </button>
      </div>
    <?php endif; ?>
  </div>

  <!-- Top artisti + Playlist -->
  <div class="col-lg-4">
    <h5 class="mb-3"><i class="bi bi-bar-chart-fill me-2"></i>Top artisti</h5>
    <ul class="list-group list-group-flush shadow-sm rounded mb-4">
      <?php foreach ($topArtists as $ar): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
          <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= $ar['id'] ?>"
            class="text-decoration-none fw-semibold">
            <?= htmlspecialchars($ar['name']) ?>
          </a>
          <span class="badge bg-primary rounded-pill"><?= $ar['album_count'] ?></span>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- Ultime playlist -->
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0 fw-bold">
        <i class="bi bi-collection-play me-2 text-warning"></i>Playlist
      </h5>
      <a href="<?= BASE_URL ?>/index.php?route=playlists"
        class="btn btn-sm btn-outline-secondary btn-xs">
        Tutte <i class="bi bi-arrow-right ms-1"></i>
      </a>
    </div>

    <?php if (empty($recentPlaylists)): ?>
      <div class="dash-playlist-empty text-center py-4">
        <i class="bi bi-collection-play fs-2 d-block mb-2 text-muted opacity-25"></i>
        <p class="text-muted small mb-2">Nessuna playlist ancora.</p>
        <a href="<?= BASE_URL ?>/index.php?route=playlists"
          class="btn btn-xs btn-warning">
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
            <!-- Dot stato -->
            <span class="dash-pl-dot <?= $isFull ? 'dot-full' : ($isEmpty ? 'dot-empty' : 'dot-partial') ?>"></span>

            <!-- Nome + stato -->
            <div class="flex-grow-1 overflow-hidden">
              <a href="<?= BASE_URL ?>/index.php?route=playlists/detail/<?= (int)$pl['id'] ?>"
                class="dash-pl-name text-truncate d-block">
                <?= htmlspecialchars($pl['name']) ?>
              </a>
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

            <!-- Play -->
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


<script>
  (function() {

    function getAudio() {
      return document.getElementById('global-audio');
    }

    function syncDashboardPlaylistUI() {
      var audio = getAudio();

      var activeId = (typeof PlaylistPlayer !== 'undefined' &&
          typeof PlaylistPlayer.activeId === 'function') ?
        parseInt(PlaylistPlayer.activeId(), 10) :
        null;

      var hasSource = !!(audio && audio.src);
      var isPlaying = !!(audio && audio.src && !audio.paused);

      document.querySelectorAll('.dash-playlist-row[data-playlist-id]').forEach(function(row) {
        var pid = parseInt(row.dataset.playlistId, 10);
        var isActive = pid === activeId;

        row.classList.toggle('is-player-playing', isActive && isPlaying);
        row.classList.toggle('is-player-paused', isActive && !isPlaying && hasSource);
      });

      document.querySelectorAll('.dash-pl-play[data-playlist-id]').forEach(function(btn) {
        var pid = parseInt(btn.dataset.playlistId, 10);
        var isActive = pid === activeId;

        btn.classList.remove('is-player-playing', 'is-player-paused');

        if (isActive && isPlaying) {
          btn.innerHTML = '<i class="bi bi-pause-fill"></i>';
          btn.title = 'Pausa';
          btn.classList.add('is-player-playing');

          btn.onclick = function() {
            var a = getAudio();
            if (a && a.src) {
              a.pause();
            }
          };

          return;
        }

        if (isActive && !isPlaying && hasSource) {
          btn.innerHTML = '<i class="bi bi-play-fill"></i>';
          btn.title = 'Riprendi';
          btn.classList.add('is-player-paused');

          btn.onclick = function() {
            var a = getAudio();
            if (a && a.src) {
              a.play().catch(function(err) {
                console.warn('[Dashboard playlist] Impossibile riprendere:', err.message);
              });
            }
          };

          return;
        }

        btn.innerHTML = '<i class="bi bi-play-fill"></i>';
        btn.title = 'Riproduci';
        btn.onclick = function() {
          PlaylistPlayer.load(parseInt(this.dataset.playlistId, 10));
        };
      });
    }

    function bindDashboardPlaylistAudioEvents() {
      var audio = getAudio();
      if (!audio || audio.dataset.dashboardPlaylistBound === '1') return;

      audio.dataset.dashboardPlaylistBound = '1';
      audio.addEventListener('play', syncDashboardPlaylistUI);
      audio.addEventListener('pause', syncDashboardPlaylistUI);
      audio.addEventListener('ended', syncDashboardPlaylistUI);
    }

    bindDashboardPlaylistAudioEvents();
    syncDashboardPlaylistUI();

    // ---- Mostra altri album ----
    (function () {
      var btn = document.getElementById('btn-show-more-albums');
      if (!btn) return;

      var STEP = 6;

      btn.addEventListener('click', function () {
        var hidden = document.querySelectorAll('.recent-album-item.d-none');
        var toShow = Array.prototype.slice.call(hidden, 0, STEP);

        toShow.forEach(function (el) { el.classList.remove('d-none'); });

        var stillHidden = document.querySelectorAll('.recent-album-item.d-none').length;

        if (stillHidden === 0) {
          btn.parentNode.remove();
        } else {
          document.getElementById('remaining-albums-count').textContent =
            Math.min(STEP, stillHidden);
        }
      });
    })();

    

    // Il player globale richiama questa funzione in app.js su play/pausa/stop.
    window.__syncPlaylistListUI = syncDashboardPlaylistUI;

  })();
</script>

<?php
require BASE_PATH . '/views/layout/footer.php';

function formatBadgeColor(string $format): string
{
  switch ($format) {
    case 'Vinile':
      return 'warning';
    case 'CD':
      return 'info';
    case 'Musicassetta':
      return 'success';
    default:
      return 'secondary';
  }
}
?>
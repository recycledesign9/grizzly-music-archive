<?php

/** @var array $album */
/** @var array $tracks */

$pageTitle = $album['title'] ?? 'Dettaglio disco';
require BASE_PATH . '/views/layout/header.php';

$coverSrc = $album['cover_local']
  ? BASE_URL . '/public/uploads/' . htmlspecialchars($album['cover_local'])
  : ($album['cover_url'] ?: BASE_URL . '/public/img/placeholder.png');

// Flash messages
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>
<?php
// Payload JSON per il player sticky
$playerTracks = [];
foreach ($tracks as $t) {
  if (!empty($t['audio_filename'])) {
    $playerTracks[] = [
      'id'       => (int)$t['id'],
      'position' => (int)$t['position'],
      'title'    => $t['title'],
      'duration' => (int)($t['duration_sec'] ?? 0),
      'src'      => MediaPathResolver::getStreamUrl($t['audio_filename']),
    ];
  }
}
$coverUrl = $album['cover_local']
  ? BASE_URL . '/public/uploads/' . $album['cover_local']
  : BASE_URL . '/public/img/placeholder.png';

// Tracklist JSON per il bulk matcher (id, position, title, has_audio)
$tracksJson = [];
foreach ($tracks as $t) {
  $tracksJson[] = [
    'id'        => (int)$t['id'],
    'position'  => (int)$t['position'],
    'title'     => $t['title'],
    'has_audio' => !empty($t['audio_filename']),
  ];
}

// Mappa track_id => [playlist_id, ...] per badge "già presente" nel dropdown
$trackPlaylistMap = [];
if (!empty($tracks)) {
  $db = Database::getInstance();
  $stmtMap = $db->prepare("
      SELECT pt.track_id, pt.playlist_id
      FROM playlist_tracks pt
      JOIN tracks t ON t.id = pt.track_id
      WHERE t.album_id = ?
  ");
  $stmtMap->execute([$album['id']]);
  foreach ($stmtMap->fetchAll() as $row) {
    $trackPlaylistMap[(int)$row['track_id']][] = (int)$row['playlist_id'];
  }
}
// Durata totale album
$albumTotalSec = 0;
foreach ($tracks as $t) {
  if (!empty($t['duration_sec'])) {
    $albumTotalSec += (int)$t['duration_sec'];
  }
}
$albumDurationStr = '';
if ($albumTotalSec > 0) {
  $h = floor($albumTotalSec / 3600);
  $m = floor(($albumTotalSec % 3600) / 60);
  $s = $albumTotalSec % 60;
  $albumDurationStr = $h > 0
    ? $h . 'h ' . str_pad($m, 2, '0', STR_PAD_LEFT) . 'min'
    : $m . ' min ' . str_pad($s, 2, '0', STR_PAD_LEFT) . 's';
}
?>
<script>
  window.__album = <?= json_encode([
                      'id'     => (int)$album['id'],
                      'title'  => $album['title'],
                      'artist' => $album['artist_name'],
                      'cover'  => $coverUrl,
                      'tracks' => $playerTracks,
                    ], JSON_HEX_TAG | JSON_HEX_APOS) ?>;

  // Dati tracklist per il bulk uploader
  window.__tracks = <?= json_encode($tracksJson, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
  window.__albumId = <?= (int)$album['id'] ?>;
  window.__csrfToken = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
  window.__uploadBulkUrl = '<?= BASE_URL ?>/index.php?route=upload/bulk-audio/<?= (int)$album['id'] ?>';
</script>

<div class="row g-4">

  <?php if ($flashSuccess): ?>
    <div class="col-12">
      <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($flashSuccess) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($flashError): ?>
    <div class="col-12">
      <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($flashError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    </div>
  <?php endif; ?>

  <!-- ============================================================
       COLONNA SINISTRA — cover · metadati · azioni · descrizione
       ============================================================ -->
  <div class="col-12 col-md-4 col-lg-3 album-detail-sidebar">

    <!-- Cover -->
    <div class="album-detail-cover-wrap mb-3">
      <img src="<?= htmlspecialchars($coverSrc) ?>"
        class="album-detail-cover img-fluid rounded shadow w-100"
        alt="Cover <?= htmlspecialchars($album['title']) ?>">
    </div>

    <!-- Badge formato / genere -->
    <div class="d-flex flex-wrap gap-2 mb-3">
      <span class="badge badge-format bg-<?= formatBadge($album['format_name']) ?>">
        <?= htmlspecialchars($album['format_name']) ?>
      </span>
      <?php if ($album['genre_name']): ?>
        <span class="badge badge-genre"><?= htmlspecialchars($album['genre_name']) ?></span>
      <?php endif; ?>
    </div>

    <!-- Metadati -->
    <div class="album-meta-block mb-3">
      <dl class="album-meta-list">
        <div class="album-meta-row">
          <dt>Artista</dt>
          <dd>
            <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= $album['artist_id'] ?>"
              class="album-meta-link">
              <?= htmlspecialchars($album['artist_name']) ?>
            </a>
          </dd>
        </div>
        <?php if ($album['year']): ?>
          <div class="album-meta-row">
            <dt>Anno</dt>
            <dd><?= $album['year'] ?></dd>
          </div>
        <?php endif; ?>
        <?php if ($album['label_name']): ?>
          <div class="album-meta-row">
            <dt>Etichetta</dt>
            <dd><?= htmlspecialchars($album['label_name']) ?></dd>
          </div>
        <?php endif; ?>
        <div class="album-meta-row">
          <dt>Condizione</dt>
          <dd><?= htmlspecialchars(conditionLabel($album['condition'])) ?></dd>
        </div>
        <div class="album-meta-row">
          <dt>Copie</dt>
          <dd><?= $album['copies'] ?></dd>
        </div>
        <?php if ($album['mbid']): ?>
          <div class="album-meta-row">
            <dt>MBID</dt>
            <dd>
              <a href="https://musicbrainz.org/release/<?= $album['mbid'] ?>"
                target="_blank" class="font-monospace small album-meta-link">
                <?= substr($album['mbid'], 0, 8) ?>…
              </a>
            </dd>
          </div>
        <?php endif; ?>
      </dl>
    </div>

    <!-- Note personali -->
    <?php if ($album['notes']): ?>
      <div class="album-notes-block mb-3">
        <i class="bi bi-pencil-square me-2 text-warning opacity-75"></i>
        <span class="small"><?= nl2br(htmlspecialchars($album['notes'])) ?></span>
      </div>
    <?php endif; ?>

    <!-- Pulsanti azione -->
    <div class="album-actions-row mb-4">
      <a href="<?= BASE_URL ?>/index.php?route=albums/edit/<?= $album['id'] ?>"
        class="btn btn-sm btn-outline-secondary album-action-edit">
        <i class="bi bi-pencil me-1"></i>Modifica
      </a>

      <button type="button"
        class="btn btn-sm btn-outline-danger album-action-delete"
        data-bs-toggle="modal"
        data-bs-target="#deleteModal"
        title="Elimina"
        aria-label="Elimina album">
        <i class="bi bi-trash"></i>
      </button>
    </div>

    <!-- Descrizione automatica / Note sull'album -->
    <div class="album-desc-block album-desc-block-sidebar mb-4" id="albumDescBlock"
      data-artist="<?= htmlspecialchars($album['artist_name'], ENT_QUOTES) ?>"
      data-album="<?= htmlspecialchars($album['title'], ENT_QUOTES) ?>">

      <div class="album-desc-header d-flex align-items-center justify-content-between">
        <span>
          <i class="bi bi-journal-text album-desc-icon"></i>
          <span class="album-desc-label">Note sull'album</span>
        </span>
        <button type="button"
          class="btn btn-sm btn-outline-secondary py-0 px-1"
          id="albumDescRefresh"
          title="Forza una nuova ricerca, ignorando la cache salvata per questo disco">
          <i class="bi bi-arrow-clockwise"></i>
        </button>
      </div>

      <div class="album-desc-loading" id="albumDescLoading">
        <span class="spinner-border spinner-border-sm text-warning me-2" role="status"></span>
        <span class="small text-muted">Recupero informazioni…</span>
      </div>

      <div class="album-desc-body" id="albumDescBody" style="display:none">
        <p class="album-desc-text" id="albumDescText"></p>
        <button type="button" class="album-desc-toggle" id="albumDescToggle" style="display:none">Mostra tutto</button>
        <div class="album-desc-footer" id="albumDescFooter"></div>
      </div>

      <div class="album-desc-empty" id="albumDescEmpty" style="display:none">
        <i class="bi bi-music-note-list me-2 opacity-50"></i>
        <span class="small text-muted fst-italic">Nessuna descrizione disponibile.</span>
      </div>

    </div>

  </div><!-- /col cover -->

  <!-- Tracklist + player -->
  <div class="col-12 col-md-8 col-lg-9">

    <!-- Intestazione con menu azioni -->
    <div class="d-flex align-items-start justify-content-between mb-1">
      <div>
        <h3 class="mb-0"><?= htmlspecialchars($album['title']) ?></h3>
        <p class="text-muted mb-0">
          <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= $album['artist_id'] ?>">
            <?= htmlspecialchars($album['artist_name']) ?>
          </a>
          <?= $album['year'] ? ' — ' . $album['year'] : '' ?>
          <?php if ($albumDurationStr): ?>
            <span class="ms-3 text-muted small">
              <i class="bi bi-clock me-1"></i><?= $albumDurationStr ?>
            </span>
          <?php endif; ?>
          <?php if (!empty($tracks)): ?>
            <span class="ms-2 text-muted small">
              <i class="bi bi-music-note-beamed me-1"></i><?= count($tracks) ?> <?= count($tracks) === 1 ? 'traccia' : 'tracce' ?>
            </span>
          <?php endif; ?>
        </p>
      </div>

      <!-- Menu tre puntini azioni album -->
      <div class="dropdown ms-2 flex-shrink-0">
        <button class="btn btn-sm btn-outline-secondary"
          type="button"
          id="albumActionsMenu"
          data-bs-toggle="dropdown"
          aria-expanded="false"
          title="Azioni">
          <i class="bi bi-three-dots-vertical"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="albumActionsMenu">
          <?php if (!empty($playerTracks)): ?>
            <li>
              <button class="dropdown-item" type="button"
                onclick="Player.load(window.__album)">
                <i class="bi bi-play-fill me-2 text-warning"></i>Riproduci album
              </button>
            </li>
            <li>
              <hr class="dropdown-divider">
            </li>
          <?php endif; ?>
          <?php if (!empty($tracks)): ?>
            <li>
              <button class="dropdown-item" type="button"
                data-bs-toggle="modal" data-bs-target="#bulkUploadModal">
                <i class="bi bi-folder2-open me-2 text-warning"></i>Carica tracce in blocco
              </button>
            </li>
            <li>
              <button class="dropdown-item" type="button"
                data-bs-toggle="modal" data-bs-target="#addToPlaylistModal">
                <i class="bi bi-collection-play me-2 text-success"></i>Aggiungi a playlist
              </button>
            </li>
            <li>
              <button class="dropdown-item" type="button" id="yt-play-all">
                <i class="bi bi-youtube me-2 text-danger"></i>Riproduci tutti da YouTube
              </button>
            </li>
            <li>
              <hr class="dropdown-divider">
            </li>
          <?php endif; ?>
          <li>
            <a class="dropdown-item" href="<?= BASE_URL ?>/index.php?route=albums/edit/<?= $album['id'] ?>">
              <i class="bi bi-pencil me-2"></i>Modifica disco
            </a>
          </li>
          <li>
            <button class="dropdown-item text-danger" type="button"
              data-bs-toggle="modal" data-bs-target="#deleteModal">
              <i class="bi bi-trash me-2"></i>Elimina disco
            </button>
          </li>
        </ul>
      </div>
    </div>

    <div class="mb-4"></div>

    <?php if (!empty($tracks)): ?>
      <div class="card shadow-sm">
        <div class="card-header fw-semibold">
          <i class="bi bi-music-note-list me-2"></i>Tracklist
        </div>
        <ul class="list-group list-group-flush" id="tracklistPlayer">
          <?php foreach ($tracks as $t): ?>
            <li class="list-group-item track-item py-2" data-track-id="<?= (int)$t['id'] ?>">
              <div class="d-flex align-items-center gap-3">
                <span class="text-muted small" style="min-width:1.8rem;text-align:right">
                  <?= $t['position'] ?>
                </span>
                <div class="flex-grow-1">
                  <span class="fw-semibold"><?= htmlspecialchars($t['title']) ?></span>
                  <?php if ($t['duration_sec']): ?>
                    <span class="text-muted small ms-2">
                      <?= Track::formatDuration($t['duration_sec']) ?>
                    </span>
                  <?php endif; ?>
                </div>
                <div class="track-actions">
                  <?php if ($t['audio_filename']): ?>
                    <div class="d-flex align-items-center gap-2">
                      <audio controls preload="none" class="track-player">
                        <source src="<?= MediaPathResolver::getStreamUrl($t['audio_filename']) ?>"
                          type="<?= pathinfo($t['audio_filename'], PATHINFO_EXTENSION) === 'flac' ? 'audio/flac' : 'audio/mpeg' ?>">
                        Il tuo browser non supporta l'audio HTML5.
                      </audio>
                      <a href="<?= MediaPathResolver::getDownloadUrl($t['audio_filename']) ?>"
                        class="btn btn-xs btn-outline-secondary" download
                        title="Scarica MP3">
                        <i class="bi bi-download"></i>
                      </a>
                      <?php if (!empty($t['audio_file_id'])): ?>
                        <button type="button"
                          class="btn btn-xs btn-outline-danger btn-delete-audio"
                          data-audio-id="<?= (int)$t['audio_file_id'] ?>"
                          data-track-title="<?= htmlspecialchars($t['title'], ENT_QUOTES) ?>"
                          data-csrf="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                          title="Rimuovi audio">
                          <i class="bi bi-x-lg"></i>
                        </button>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <span class="text-muted small fst-italic">
                      <i class="bi bi-music-note text-muted"></i> nessun audio
                    </span>
                  <?php endif; ?>
                  <!-- Bottone YouTube -->
                  <button
                    type="button"
                    class="btn btn-xs btn-yt"
                    data-track-id="<?= (int)$t['id'] ?>"
                    data-artist="<?= htmlspecialchars($album['artist_name'], ENT_QUOTES) ?>"
                    data-title="<?= htmlspecialchars($t['title'], ENT_QUOTES) ?>"
                    title="Cerca video su YouTube">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                      <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" />
                    </svg>
                  </button>
                  <!-- Dropdown: aggiungi traccia a playlist -->
                  <?php $inPlaylists = $trackPlaylistMap[(int)$t['id']] ?? []; ?>
                  <div class="dropdown">
                    <button type="button"
                      class="btn btn-xs btn-outline-secondary"
                      data-bs-toggle="dropdown"
                      data-bs-auto-close="outside"
                      aria-expanded="false"
                      title="Aggiungi a playlist">
                      <i class="bi bi-collection-play"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:230px">
                      <li>
                        <h6 class="dropdown-header py-1 small">Aggiungi a playlist</h6>
                      </li>
                      <?php if (empty($userPlaylists)): ?>
                        <li><span class="dropdown-item-text small text-muted">Nessuna playlist</span></li>
                      <?php else: ?>
                        <?php foreach ($userPlaylists as $pl):
                          $alreadyIn = in_array((int)$pl['id'], $inPlaylists);
                        ?>
                          <li class="d-flex align-items-center px-1 gap-1">
                            <?php if ($alreadyIn): ?>
                              <span class="dropdown-item small text-muted d-flex align-items-center
                                        justify-content-between flex-grow-1 pe-0 disabled">
                                <span><i class="bi bi-check2 me-2 text-success"></i><?= htmlspecialchars($pl['name']) ?></span>
                                <span class="badge bg-success-subtle text-success ms-2"
                                  style="font-size:.65rem;white-space:nowrap">presente</span>
                              </span>
                            <?php else: ?>
                              <button class="dropdown-item small btn-add-track-to-playlist
                                          d-flex align-items-center justify-content-between
                                          flex-grow-1 pe-0"
                                type="button"
                                data-track-id="<?= (int)$t['id'] ?>"
                                data-playlist-id="<?= (int)$pl['id'] ?>"
                                data-playlist-name="<?= htmlspecialchars($pl['name'], ENT_QUOTES) ?>">
                                <span><i class="bi bi-collection me-2 text-muted"></i><?= htmlspecialchars($pl['name']) ?></span>
                              </button>
                            <?php endif; ?>
                            <a href="<?= BASE_URL ?>/index.php?route=playlists/detail/<?= (int)$pl['id'] ?>"
                              class="btn btn-xs btn-link text-muted flex-shrink-0 px-1"
                              title="Apri playlist">
                              <i class="bi bi-box-arrow-up-right" style="font-size:.65rem"></i>
                            </a>
                          </li>
                        <?php endforeach; ?>
                      <?php endif; ?>
                      <li>
                        <hr class="dropdown-divider my-1">
                      </li>
                      <li>
                        <button class="dropdown-item small btn-add-track-new-playlist"
                          type="button"
                          data-track-id="<?= (int)$t['id'] ?>"
                          data-track-title="<?= htmlspecialchars($t['title'], ENT_QUOTES) ?>">
                          <i class="bi bi-plus-circle me-2 text-success"></i>Nuova playlist…
                        </button>
                      </li>
                    </ul>
                  </div>
                </div><!-- /.track-actions -->
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
      <div class="alert alert-light border">
        <i class="bi bi-music-note me-2"></i>Nessuna traccia inserita.
        <a href="<?= BASE_URL ?>/index.php?route=albums/edit/<?= $album['id'] ?>">Aggiungi tracklist</a>
      </div>
    <?php endif; ?>

    <!-- Upload MP3 singolo (inalterato) -->
    <div class="card shadow-sm mt-4">
      <div class="card-header fw-semibold">
        <i class="bi bi-upload me-2"></i>Carica file audio (MP3 / FLAC)
      </div>
      <div class="card-body">
        <form action="<?= BASE_URL ?>/index.php?route=upload/audio/<?= $album['id'] ?>"
          method="post" enctype="multipart/form-data" class="row g-2"
          id="uploadAudioForm">
          <input type="hidden" name="csrf_token"
            value="<?= $_SESSION['csrf_token'] ?>">
          <input type="hidden" name="album_id" value="<?= $album['id'] ?>">
          <div class="col-md-4">
            <label class="form-label small mb-1">Associa a traccia <span class="text-danger">*</span></label>
            <select name="track_id" class="form-select form-select-sm" id="trackSelect">
              <option value="">— Seleziona una traccia —</option>
              <?php foreach ($tracks as $t): ?>
                <option value="<?= $t['id'] ?>">
                  <?= $t['position'] ?>. <?= htmlspecialchars($t['title']) ?>
                  <?= $t['audio_filename'] ? ' ✓' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label small mb-1">File audio (MP3/FLAC) <span class="text-danger">*</span></label>
            <div class="file-input-chip" id="audioChip">
              <i class="bi bi-music-note"></i>
              <span class="file-name" id="fileChipName">Scegli file MP3 o FLAC…</span>
            </div>
            <input type="file" name="audio_file" id="audioFileInput"
              accept=".mp3,.flac,audio/mpeg,audio/flac"
              style="display:none">
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-sm btn-warning w-100" id="uploadBtn">
              <i class="bi bi-upload me-1"></i>Carica
            </button>
          </div>
        </form>
      </div>
    </div>

    <script>
      (function() {
        var chip = document.getElementById('audioChip');
        var fInput = document.getElementById('audioFileInput');
        var chipName = document.getElementById('fileChipName');

        if (chip) chip.addEventListener('click', function() {
          fInput.click();
        });

        if (fInput) {
          fInput.addEventListener('change', function() {
            if (this.files.length) {
              var f = this.files[0];
              var mb = (f.size / 1024 / 1024).toFixed(1);
              chipName.textContent = f.name + ' (' + mb + ' MB)';
              chipName.classList.add('has-file');
            } else {
              chipName.textContent = 'Scegli file MP3…';
              chipName.classList.remove('has-file');
            }
          });
        }

        var form = document.getElementById('uploadAudioForm');
        if (form) {
          form.addEventListener('submit', function(e) {
            var trackSelect = document.getElementById('trackSelect');
            if (trackSelect && !trackSelect.value) {
              e.preventDefault();
              trackSelect.classList.add('is-invalid');
              var err = trackSelect.parentNode.querySelector('.track-required-error');
              if (!err) {
                err = document.createElement('div');
                err.className = 'invalid-feedback d-block track-required-error';
                err.textContent = 'Seleziona una traccia a cui associare il file.';
                trackSelect.parentNode.appendChild(err);
              }
              trackSelect.focus();
              return;
            }
            if (trackSelect) {
              trackSelect.classList.remove('is-invalid');
              var err2 = trackSelect.parentNode.querySelector('.track-required-error');
              if (err2) err2.remove();
            }
            var btn = document.getElementById('uploadBtn');
            if (btn) {
              btn.disabled = true;
              btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Caricamento…';
            }
          });

          var trackSelect = document.getElementById('trackSelect');
          if (trackSelect) {
            trackSelect.addEventListener('change', function() {
              if (this.value) {
                this.classList.remove('is-invalid');
                var err = this.parentNode.querySelector('.track-required-error');
                if (err) err.remove();
              }
            });
          }
        }
      })();
    </script>

  </div><!-- /col tracklist -->



</div><!-- /row -->

<script>
  /* ----------------------------------------------------------------
   Album Description — Wikipedia IT → EN fallback
---------------------------------------------------------------- */
  (function() {
    var block = document.getElementById('albumDescBlock');
    var loading = document.getElementById('albumDescLoading');
    var body = document.getElementById('albumDescBody');
    var textEl = document.getElementById('albumDescText');
    var footer = document.getElementById('albumDescFooter');
    var empty = document.getElementById('albumDescEmpty');
    var toggle = document.getElementById('albumDescToggle');
    var refreshBtn = document.getElementById('albumDescRefresh');

    if (!block) return;

    var artist = block.dataset.artist || '';
    var album = block.dataset.album || '';
    var baseUrl = (document.querySelector('meta[name="base-url"]') || {}).content ||
      window.location.origin;

    var MAX_CHARS = 280; // soglia oltre cui appare "Mostra tutto"
    var expanded = false;
    var fullText = '';

    function buildUrl(lang, force) {
      var url = baseUrl + '/index.php?route=albums/api-description' +
        '&artist=' + encodeURIComponent(artist) +
        '&album=' + encodeURIComponent(album) +
        '&lang=' + lang;
      if (force) url += '&force=1';
      return url;
    }

    function showDescription(d, lang) {
      fullText = (d.description || '').trim();
      if (!fullText) {
        showEmpty();
        return;
      }

      if (fullText.length > MAX_CHARS) {
        textEl.textContent = fullText.substring(0, MAX_CHARS) + '…';
        toggle.style.display = '';
        toggle.textContent = 'Mostra tutto';
        expanded = false;
      } else {
        textEl.textContent = fullText;
        toggle.style.display = 'none';
      }

      var footerHtml = '';
      if (lang !== 'it') {
        footerHtml += '<span class="album-desc-lang-badge me-2">EN</span>';
      }
      if (d.wiki_url) {
        footerHtml += '<a href="' + d.wiki_url + '" target="_blank" rel="noopener" class="album-desc-wiki-link">' +
          '<i class="bi bi-box-arrow-up-right me-1"></i>Wikipedia' +
          '</a>';
      }
      if (footerHtml) {
        footer.innerHTML = footerHtml;
        footer.style.display = '';
      } else {
        footer.style.display = 'none';
      }

      loading.style.display = 'none';
      body.style.display = '';
    }

    function showEmpty() {
      loading.style.display = 'none';
      empty.style.display = '';
    }

    function fetchDescription(lang, fallbackLang, force) {
      fetch(buildUrl(lang, force), {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(function(r) {
          return r.ok ? r.json() : Promise.reject(r.status);
        })
        .then(function(d) {
          if (d.description) {
            showDescription(d, lang);
          } else if (fallbackLang) {
            fetchDescription(fallbackLang, null, force);
          } else {
            showEmpty();
          }
        })
        .catch(function() {
          if (fallbackLang) {
            fetchDescription(fallbackLang, null, force);
          } else {
            showEmpty();
          }
        });
    }

    if (toggle) {
      toggle.addEventListener('click', function() {
        expanded = !expanded;
        if (expanded) {
          textEl.textContent = fullText;
          toggle.textContent = 'Mostra meno';
        } else {
          textEl.textContent = fullText.substring(0, MAX_CHARS) + '…';
          toggle.textContent = 'Mostra tutto';
        }
      });
    }

    if (refreshBtn) {
      refreshBtn.addEventListener('click', function() {
        if (refreshBtn.disabled) return;
        refreshBtn.disabled = true;
        refreshBtn.querySelector('i').classList.add('spin');

        body.style.display = 'none';
        empty.style.display = 'none';
        loading.style.display = '';

        fetchDescription('it', 'en', true);

        setTimeout(function() {
          refreshBtn.disabled = false;
          refreshBtn.querySelector('i').classList.remove('spin');
        }, 600);
      });
    }

    if (artist && album) {
      fetchDescription('it', 'en', false);
    } else {
      showEmpty();
    }
  })();
</script>

<!-- ============================================================
     MODAL BULK UPLOAD
============================================================ -->
<div class="modal fade" id="bulkUploadModal" tabindex="-1" aria-labelledby="bulkUploadModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="bulkUploadModalLabel">
          <i class="bi bi-folder2-open me-2 text-warning"></i>Carica tracce in blocco
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">

        <!-- Step 1: selezione file -->
        <div id="bulkStep1">
          <p class="text-muted small mb-3">
            Seleziona una <strong>cartella</strong>contenente gli MP3 o FLAC dell'album, oppure seleziona
            <strong>più file MP3 o FLAC</strong> contemporaneamente. Il sistema li abbinerà automaticamente
            alle tracce del disco.
          </p>

          <div class="d-flex gap-2 flex-wrap mb-3">
            <!-- Selezione cartella (webkitdirectory) -->
            <button type="button" class="btn btn-warning" id="btnPickFolder">
              <i class="bi bi-folder2-open me-2"></i>Scegli cartella audio
            </button>
            <input type="file" id="folderInput"
              multiple
              webkitdirectory
              directory
              style="display:none">

            <!-- Fallback: selezione file multipla -->
            <button type="button" class="btn btn-outline-secondary" id="btnPickFiles">
              <i class="bi bi-files me-2"></i>Seleziona file MP3 / FLAC
            </button>
            <input type="file" id="filesInput"
              accept=".mp3,.flac,audio/mpeg,audio/flac"
              multiple
              style="display:none">
          </div>

          <div id="bulkNoFilesHint" class="alert alert-light border small py-2">
            <i class="bi bi-info-circle me-1"></i>
            Nessun file selezionato. Usa uno dei pulsanti sopra per iniziare.
          </div>
        </div>

        <!-- Step 2: anteprima matching -->
        <div id="bulkStep2" style="display:none">

          <div class="d-flex justify-content-between align-items-center mb-3">
            <div id="bulkMatchSummary" class="small text-muted"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnBulkReset">
              <i class="bi bi-arrow-counterclockwise me-1"></i>Ricomincia
            </button>
          </div>

          <!-- Legenda stati -->
          <div class="d-flex gap-3 mb-3 flex-wrap">
            <span class="badge-match badge-match-ok"><i class="bi bi-check-circle me-1"></i>Associato</span>
            <span class="badge-match badge-match-warn"><i class="bi bi-exclamation-circle me-1"></i>Da verificare</span>
            <span class="badge-match badge-match-skip"><i class="bi bi-dash-circle me-1"></i>Non associato</span>
            <span class="badge-match badge-match-exists"><i class="bi bi-check2-all me-1"></i>Già presente</span>
          </div>

          <div id="bulkMatchTable"></div>

          <!-- File non riconosciuti -->
          <div id="bulkOrphansSection" style="display:none" class="mt-3">
            <div class="alert alert-warning small py-2 mb-2">
              <i class="bi bi-question-circle me-1"></i>
              <strong>File non associati a nessuna traccia:</strong>
            </div>
            <ul id="bulkOrphansList" class="list-unstyled small ms-3"></ul>
          </div>

        </div>

      </div><!-- /modal-body -->

      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
        <div class="d-flex align-items-center gap-3">
          <span id="bulkUploadStatus" class="small text-muted"></span>
          <button type="button" class="btn btn-warning" id="btnBulkConfirm" style="display:none">
            <i class="bi bi-cloud-upload me-2"></i>Conferma e carica
          </button>
        </div>
      </div>

    </div>
  </div>
</div>


<!-- ===== Modal: Conferma elimina file audio ===== -->
<div class="modal fade" id="deleteAudioModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-danger">
          <i class="bi bi-music-note me-2"></i>Rimuovi audio
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body small">
        Rimuovere il file audio di <strong id="deleteAudioTrackTitle"></strong>?
        <div class="text-muted mt-1" style="font-size:.8rem">Il file verrà eliminato definitivamente.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
        <button class="btn btn-sm btn-danger" id="btnConfirmDeleteAudio">Elimina</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== Modal: Aggiungi a playlist ===== -->
<div class="modal fade" id="addToPlaylistModal" tabindex="-1" aria-labelledby="addToPlaylistLabel">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addToPlaylistLabel">
          <i class="bi bi-collection-play me-2 text-success"></i>Aggiungi a playlist
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <!-- Playlist esistenti -->
        <?php if (!empty($userPlaylists)): ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="selectExistingPlaylist">
              Aggiungi a playlist esistente
            </label>
            <select class="form-select form-select-sm" id="selectExistingPlaylist">
              <option value="">— Seleziona —</option>
              <?php foreach ($userPlaylists as $pl): ?>
                <option value="<?= (int)$pl['id'] ?>"><?= htmlspecialchars($pl['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="text-center text-muted small my-2">— oppure —</div>
        <?php endif; ?>

        <!-- Nuova playlist -->
        <div>
          <label class="form-label small fw-semibold" for="newPlaylistNameAlbum">
            Crea nuova playlist
          </label>
          <input type="text"
            class="form-control form-control-sm"
            id="newPlaylistNameAlbum"
            placeholder="Nome nuova playlist…"
            maxlength="150"
            autocomplete="off">
        </div>

        <div id="addToPlaylistFeedback" class="mt-2"></div>

      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
        <button class="btn btn-sm btn-success" id="btnConfirmAddToPlaylist">
          <i class="bi bi-plus-lg me-1"></i>Aggiungi album
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal elimina -->
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-danger">Elimina disco</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body small">
        Eliminare definitivamente <strong><?= htmlspecialchars($album['title']) ?></strong>?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-secondary"
          data-bs-dismiss="modal">Annulla</button>
        <form method="post"
          action="<?= BASE_URL ?>/index.php?route=albums/delete/<?= $album['id'] ?>">
          <input type="hidden" name="csrf_token"
            value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     CSS Bulk Upload (scoped, iniettato inline per evitare file extra)
============================================================ -->
<style>
  .badge-match {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.6rem;
    border-radius: 99px;
    font-size: 0.75rem;
    font-weight: 500;
  }

  .badge-match-ok {
    background: rgba(25, 135, 84, .15);
    color: #198754;
  }

  .badge-match-warn {
    background: rgba(255, 193, 7, .18);
    color: #856404;
  }

  .badge-match-skip {
    background: rgba(108, 117, 125, .15);
    color: #6c757d;
  }

  .badge-match-exists {
    background: rgba(13, 110, 253, .13);
    color: #0d6efd;
  }

  [data-bs-theme="dark"] .badge-match-ok {
    background: rgba(25, 135, 84, .25);
    color: #75b798;
  }

  [data-bs-theme="dark"] .badge-match-warn {
    background: rgba(255, 193, 7, .22);
    color: #ffc107;
  }

  [data-bs-theme="dark"] .badge-match-skip {
    background: rgba(108, 117, 125, .25);
    color: #adb5bd;
  }

  [data-bs-theme="dark"] .badge-match-exists {
    background: rgba(13, 110, 253, .22);
    color: #6ea8fe;
  }

  .bulk-track-row {
    display: grid;
    grid-template-columns: 2rem 1fr minmax(0, 260px) 120px;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 0.5rem;
    border-bottom: 1px solid var(--bs-border-color);
    font-size: 0.875rem;
  }

  .bulk-track-row:last-child {
    border-bottom: none;
  }

  .bulk-track-row .track-num {
    color: var(--bs-secondary-color);
    font-variant-numeric: tabular-nums;
    text-align: right;
  }

  .bulk-track-row .file-sel select {
    font-size: 0.8rem;
  }

  .bulk-match-header {
    display: grid;
    grid-template-columns: 2rem 1fr minmax(0, 260px) 120px;
    gap: 0.75rem;
    padding: 0.4rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .03em;
    color: var(--bs-secondary-color);
    border-bottom: 2px solid var(--bs-border-color);
  }

  #bulkMatchTable {
    border: 1px solid var(--bs-border-color);
    border-radius: .375rem;
    overflow: hidden;
  }

  .bulk-progress-bar {
    height: 4px;
    background: var(--bs-border-color);
    border-radius: 2px;
    overflow: hidden;
    margin-top: .25rem;
  }

  .bulk-progress-bar-fill {
    height: 100%;
    background: #ffc107;
    transition: width .3s;
  }

  .badge-match-err {
    background: rgba(220, 53, 69, .15);
    color: #dc3545;
  }

  [data-bs-theme="dark"] .badge-match-err {
    background: rgba(220, 53, 69, .25);
    color: #ea868f;
  }

  .bulk-row-error {
    max-width: 260px;
    line-height: 1.3;
    word-break: break-word;
  }
</style>

<!-- ============================================================
     JS Bulk Upload
============================================================ -->
<script>
  (function() {
    /* ----------------------------------------------------------------
       Dati globali iniettati da PHP
    ---------------------------------------------------------------- */
    var tracks = window.__tracks || []; // [{id, position, title, has_audio}, …]
    var albumId = window.__albumId || 0;
    var csrf = window.__csrfToken || '';
    var uploadUrl = window.__uploadBulkUrl || '';

    /* Mappa interna: trackId -> { file: File|null, confidence: 'ok'|'warn'|'skip'|'exists' }  */
    var matchMap = {}; // trackId => { file, confidence, fileLabel }
    var allFiles = []; // tutti i File selezionati
    var orphans = []; // file non associati a nessuna traccia

    /* ----------------------------------------------------------------
       Riferimenti DOM
    ---------------------------------------------------------------- */
    var btnPickFolder = document.getElementById('btnPickFolder');
    var btnPickFiles = document.getElementById('btnPickFiles');
    var folderInput = document.getElementById('folderInput');
    var filesInput = document.getElementById('filesInput');
    var step1 = document.getElementById('bulkStep1');
    var step2 = document.getElementById('bulkStep2');
    var matchTable = document.getElementById('bulkMatchTable');
    var matchSummary = document.getElementById('bulkMatchSummary');
    var btnReset = document.getElementById('btnBulkReset');
    var btnConfirm = document.getElementById('btnBulkConfirm');
    var uploadStatus = document.getElementById('bulkUploadStatus');
    var orphansSection = document.getElementById('bulkOrphansSection');
    var orphansList = document.getElementById('bulkOrphansList');
    var noFilesHint = document.getElementById('bulkNoFilesHint');

    /* ----------------------------------------------------------------
       Apertura input file/cartella
    ---------------------------------------------------------------- */
    if (btnPickFolder) {
      btnPickFolder.addEventListener('click', function() {
        folderInput.click();
      });
    }
    if (btnPickFiles) {
      btnPickFiles.addEventListener('click', function() {
        filesInput.click();
      });
    }
    if (folderInput) {
      folderInput.addEventListener('change', function() {
        processFiles(this.files);
      });
    }
    if (filesInput) {
      filesInput.addEventListener('change', function() {
        processFiles(this.files);
      });
    }
    if (btnReset) {
      btnReset.addEventListener('click', function() {
        resetUI();
      });
    }

    /* ----------------------------------------------------------------
       Reset UI a step 1
    ---------------------------------------------------------------- */
    function resetUI() {
      allFiles = [];
      orphans = [];
      matchMap = {};
      if (folderInput) folderInput.value = '';
      if (filesInput) filesInput.value = '';
      step1.style.display = '';
      step2.style.display = 'none';
      btnConfirm.style.display = 'none';
      uploadStatus.textContent = '';
      matchTable.innerHTML = '';
    }

    /* ----------------------------------------------------------------
       Entry point: riceve FileList, filtra MP3, avvia matching
    ---------------------------------------------------------------- */
    function processFiles(fileList) {
      if (!fileList || fileList.length === 0) return;

      allFiles = [];
      for (var i = 0; i < fileList.length; i++) {
        var f = fileList[i];
        // Filtra solo MP3
        if (f.type === 'audio/mpeg' || f.type === 'audio/flac' || /\.(mp3|flac)$/i.test(f.name)) {
          allFiles.push(f);
        }
      }

      if (allFiles.length === 0) {
        noFilesHint.innerHTML =
          '<i class="bi bi-exclamation-triangle me-1 text-warning"></i>' +
          'Nessun file MP3 trovato nella selezione.';
        return;
      }

      runMatching();
    }

    /* ----------------------------------------------------------------
       Normalizzazione stringa per il confronto
       - lowercase, rimuovi punteggiatura, spazi multipli
    ---------------------------------------------------------------- */
    function normalize(s) {
      return (s || '')
        .toLowerCase()
        .replace(/\.(mp3|flac|wav|aac|ogg)$/i, '') // rimuovi estensione
        .replace(/[^a-z0-9\s]/g, ' ') // punteggiatura -> spazio
        .replace(/\s+/g, ' ')
        .trim();
    }

    /* ----------------------------------------------------------------
       Analizza il nome file e restituisce { trackNum, titleClean }
       Gestisce i pattern piu' comuni:

       Pattern A — numero in testa (classico):
         "01 - Sunday.mp3"  /  "01. Sunday.mp3"  /  "01_Sunday.mp3"
         "(01) Sunday.mp3"  /  "track01 Sunday.mp3"

       Pattern B — Artista - Album - NN - Titolo:
         "Sonic Youth - A Thousand Leaves - 07 - Hits of Sunshine.mp3"
         "Pink Floyd - The Wall - 01 - In The Flesh.mp3"

       Ritorna { trackNum: int|null, titleClean: string }
    ---------------------------------------------------------------- */
    function parseFileName(filename) {
      var name = filename.replace(/\.(mp3|flac|wav|aac|ogg)$/i, '').trim();
      var m;

      // Pattern B: "Qualcosa - Qualcosa - NN - Titolo"
      // Almeno 3 blocchi separati da " - " dove il terzo e' un numero
      m = name.match(/^.+?\s+-\s+.+?\s+-\s+(\d{1,3})\s+-\s+(.+)$/);
      if (m) {
        return {
          trackNum: parseInt(m[1], 10),
          titleClean: m[2].trim()
        };
      }

      // Pattern A1: numero in testa "01 - Titolo" / "01. Titolo" / "01_Titolo"
      // Pattern A1b: "01. Artista - Titolo" → rimuove il prefisso artista dal titleClean
      m = name.match(/^(\d{1,3})[\s\.\-_]+(.*)$/);
      if (m) {
        var rawTitle = m[2].trim();
        // Se il titleClean contiene " - ", controlla se la parte prima del trattino
        // corrisponde all'artista dell'album: in tal caso usa solo la parte dopo.
        var dashPos = rawTitle.indexOf(' - ');
        if (dashPos > 0 && window.__album && window.__album.artist) {
          var prefix = rawTitle.substring(0, dashPos).trim();
          var artistNorm = normalize(window.__album.artist);
          var prefixNorm = normalize(prefix);
          // Soglia alta (0.80) per non rimuovere prefissi che non sono l'artista
          if (similarityScore(prefixNorm, artistNorm) >= 0.80) {
            rawTitle = rawTitle.substring(dashPos + 3).trim();
          }
        }
        return {
          trackNum: parseInt(m[1], 10),
          titleClean: rawTitle
        };
      }

      // Pattern A2: "(01) Titolo"
      m = name.match(/^\((\d{1,3})\)\s*(.*)$/);
      if (m) {
        return {
          trackNum: parseInt(m[1], 10),
          titleClean: m[2].trim()
        };
      }

      // Pattern A3: "track01 Titolo" / "track 1 Titolo"
      m = name.match(/^track\s*(\d{1,3})\s*(.*)$/i);
      if (m) {
        return {
          trackNum: parseInt(m[1], 10),
          titleClean: m[2].trim()
        };
      }

      // Nessun pattern riconosciuto: usa il nome intero come titolo
      return {
        trackNum: null,
        titleClean: name
      };
    }

    /* ----------------------------------------------------------------
       Wrapper retrocompatibile (per il codice che usa extractTrackNumber)
    ---------------------------------------------------------------- */
    function extractTrackNumber(filename) {
      return parseFileName(filename).trackNum;
    }

    /* ----------------------------------------------------------------
       Distanza di Levenshtein normalizzata [0..1]  (0 = identici)
    ---------------------------------------------------------------- */
    function levenshtein(a, b) {
      var la = a.length,
        lb = b.length;
      if (la === 0) return lb;
      if (lb === 0) return la;
      var row = [];
      for (var j = 0; j <= lb; j++) row[j] = j;
      for (var i = 1; i <= la; i++) {
        var prev = i;
        for (var j2 = 1; j2 <= lb; j2++) {
          var val = (a[i - 1] === b[j2 - 1]) ? row[j2 - 1] : Math.min(row[j2 - 1] + 1, prev + 1, row[j2] + 1);
          row[j2 - 1] = prev;
          prev = val;
        }
        row[lb] = prev;
      }
      return row[lb];
    }

    function similarityScore(a, b) {
      var na = normalize(a),
        nb = normalize(b);
      if (na === nb) return 1.0;
      var dist = levenshtein(na, nb);
      var maxLen = Math.max(na.length, nb.length);
      if (maxLen === 0) return 1.0;
      return 1.0 - dist / maxLen;
    }

    /* ----------------------------------------------------------------
       Algoritmo di matching principale
       Strategia a 3 livelli:
         1. Numero traccia esatto nel nome file        → confidence 'ok'
         2. Similarità titolo >= 0.75                 → confidence 'ok'
         3. Similarità titolo >= 0.45                 → confidence 'warn'
         Nessun match                                 → confidence 'skip'
    ---------------------------------------------------------------- */
    function runMatching() {
      matchMap = {};
      orphans = [];

      // Inizializza matchMap per ogni traccia
      for (var i = 0; i < tracks.length; i++) {
        matchMap[tracks[i].id] = {
          file: null,
          confidence: 'skip',
          fileLabel: '',
          score: 0
        };
        if (tracks[i].has_audio) {
          matchMap[tracks[i].id].confidence = 'exists';
        }
      }

      // Per ogni file, trova la traccia migliore
      var usedTrackIds = {}; // trackId -> true se già assegnato
      var fileResults = []; // { file, trackId, confidence, score }

      // --- PASS 1: matching per numero traccia + titolo (alta priorità) ---
      // Usa parseFileName() che riconosce sia "01 - Titolo.mp3"
      // che "Artista - Album - 01 - Titolo.mp3", estraendo numero E titolo pulito.
      // Il titleClean viene confrontato col titolo DB per evitare falsi match
      // quando si caricano file di album diversi.
      for (var fi = 0; fi < allFiles.length; fi++) {
        var f = allFiles[fi];
        var parsed = parseFileName(f.name);
        var tnum = parsed.trackNum;
        var titleClean = parsed.titleClean; // es. "Hits of Sunshine (For Allen Ginsberg)"

        if (tnum === null) continue;

        for (var ti = 0; ti < tracks.length; ti++) {
          if (tracks[ti].position === tnum && !usedTrackIds[tracks[ti].id]) {
            // Se il titleClean è significativo (non vuoto), confrontalo col titolo DB.
            // Soglia 0.30: esclude solo album completamente diversi.
            // File puramente numerici (es. "01.mp3", titleClean vuoto) → accettati direttamente.
            var titleCheck = titleClean.length > 2 ?
              similarityScore(titleClean, tracks[ti].title) :
              1.0;

            if (titleCheck >= 0.30) {
              // Confidence in base alla qualità del match sul titolo
              var pass1Conf = titleCheck >= 0.70 ? 'ok' : 'warn';
              fileResults.push({
                file: f,
                trackId: tracks[ti].id,
                confidence: pass1Conf,
                score: titleCheck
              });
              usedTrackIds[tracks[ti].id] = true;
              break;
            }
            // titleCheck < 0.30: stesso numero ma titolo troppo diverso → non assegnare
          }
        }
      }

      // File già assegnati in pass 1
      var assignedFiles = {};
      for (var ri = 0; ri < fileResults.length; ri++) {
        assignedFiles[fileResults[ri].file.name] = true;
      }

      // --- PASS 2: matching per similarità titolo (file non ancora assegnati) ---
      // Usa titleClean da parseFileName() invece del nome file grezzo,
      // così il prefisso "Artista - Album - NN - " non inquina il confronto.
      for (var fi2 = 0; fi2 < allFiles.length; fi2++) {
        var f2 = allFiles[fi2];
        if (assignedFiles[f2.name]) continue;

        var parsed2 = parseFileName(f2.name);
        var titleClean2 = parsed2.titleClean; // parte pulita da confrontare

        var bestScore = 0,
          bestTrackId = null;
        for (var ti2 = 0; ti2 < tracks.length; ti2++) {
          if (usedTrackIds[tracks[ti2].id]) continue;
          // Confronta titleClean col titolo DB (non il nome file grezzo)
          var score = similarityScore(titleClean2, tracks[ti2].title);
          if (score > bestScore) {
            bestScore = score;
            bestTrackId = tracks[ti2].id;
          }
        }

        // FIX: soglie alzate da 0.45/0.75 a 0.60/0.82 per ridurre i falsi positivi.
        // Con 0.45 stringhe cortissime o generiche superavano la soglia producendo
        // match 'warn' errati che rischiavano di essere caricati senza revisione.
        if (bestTrackId !== null && bestScore >= 0.60) {
          var conf = bestScore >= 0.82 ? 'ok' : 'warn';
          fileResults.push({
            file: f2,
            trackId: bestTrackId,
            confidence: conf,
            score: bestScore
          });
          usedTrackIds[bestTrackId] = true;
          assignedFiles[f2.name] = true;
        }
      }

      // --- Applica risultati a matchMap ---
      for (var ri2 = 0; ri2 < fileResults.length; ri2++) {
        var r = fileResults[ri2];
        // Non sovrascrivere tracce che hanno già audio (keep 'exists')
        if (matchMap[r.trackId].confidence === 'exists') continue;
        matchMap[r.trackId] = {
          file: r.file,
          confidence: r.confidence,
          fileLabel: r.file.name,
          score: r.score
        };
      }

      // --- Orphans: file non associati ---
      for (var fi3 = 0; fi3 < allFiles.length; fi3++) {
        if (!assignedFiles[allFiles[fi3].name]) {
          orphans.push(allFiles[fi3]);
        }
      }

      renderMatchTable();
    }

    /* ----------------------------------------------------------------
       Render tabella di preview
    ---------------------------------------------------------------- */
    function renderMatchTable() {
      step1.style.display = 'none';
      step2.style.display = '';

      // Header
      var html = '<div class="bulk-match-header">' +
        '<span>#</span>' +
        '<span>Traccia</span>' +
        '<span>File Audio assegnato</span>' +
        '<span>Stato</span>' +
        '</div>';

      for (var i = 0; i < tracks.length; i++) {
        var t = tracks[i];
        var m = matchMap[t.id];

        var badgeHtml = '';
        var fileHtml = '';

        if (m.confidence === 'exists') {
          badgeHtml = '<span class="badge-match badge-match-exists"><i class="bi bi-check2-all me-1"></i>Già presente</span>';
          fileHtml = '<span class="text-muted fst-italic small">audio già caricato</span>';
        } else if (m.confidence === 'ok') {
          badgeHtml = '<span class="badge-match badge-match-ok"><i class="bi bi-check-circle me-1"></i>Associato</span>';
          fileHtml = buildFileSelect(t.id, m);
        } else if (m.confidence === 'warn') {
          badgeHtml = '<span class="badge-match badge-match-warn"><i class="bi bi-exclamation-circle me-1"></i>Da verificare</span>';
          fileHtml = buildFileSelect(t.id, m);
        } else {
          badgeHtml = '<span class="badge-match badge-match-skip"><i class="bi bi-dash-circle me-1"></i>Non associato</span>';
          fileHtml = buildFileSelect(t.id, m);
        }

        html += '<div class="bulk-track-row" data-track-id="' + t.id + '">' +
          '<span class="track-num">' + t.position + '</span>' +
          '<span class="track-title fw-semibold">' + escHtml(t.title) + '</span>' +
          '<span class="file-sel">' + fileHtml + '</span>' +
          '<span class="badge-col">' + badgeHtml + '</span>' +
          '</div>';
      }

      matchTable.innerHTML = html;

      // Aggiunge listener ai select
      var selects = matchTable.querySelectorAll('select[data-track-id]');
      for (var si = 0; si < selects.length; si++) {
        selects[si].addEventListener('change', onSelectChange);
      }

      // Orphans
      if (orphans.length > 0) {
        orphansSection.style.display = '';
        var ol = '';
        for (var oi = 0; oi < orphans.length; oi++) {
          ol += '<li><i class="bi bi-file-earmark-music me-1 text-warning"></i>' +
            escHtml(orphans[oi].name) + '</li>';
        }
        orphansList.innerHTML = ol;
      } else {
        orphansSection.style.display = 'none';
      }

      updateSummary();
      btnConfirm.style.display = '';
    }

    /* ----------------------------------------------------------------
       Costruisce il <select> per assegnazione manuale
    ---------------------------------------------------------------- */
    function buildFileSelect(trackId, matchEntry) {
      var html = '<select class="form-select form-select-sm" data-track-id="' + trackId + '">';
      html += '<option value="">— nessun file —</option>';
      for (var i = 0; i < allFiles.length; i++) {
        var f = allFiles[i];
        var sel = (matchEntry.file && matchEntry.file.name === f.name) ? ' selected' : '';
        html += '<option value="' + escAttr(f.name) + '"' + sel + '>' + escHtml(f.name) + '</option>';
      }
      html += '</select>';
      return html;
    }

    /* ----------------------------------------------------------------
       Cambio manuale selezione → aggiorna matchMap e badge
    ---------------------------------------------------------------- */
    function onSelectChange(e) {
      var sel = e.target;
      var trackId = parseInt(sel.dataset.trackId, 10);
      var fname = sel.value;
      var row = matchTable.querySelector('.bulk-track-row[data-track-id="' + trackId + '"]');
      var badgeEl = row ? row.querySelector('.badge-col') : null;

      if (!fname) {
        matchMap[trackId].file = null;
        matchMap[trackId].confidence = 'skip';
        if (badgeEl) badgeEl.innerHTML = '<span class="badge-match badge-match-skip"><i class="bi bi-dash-circle me-1"></i>Non associato</span>';
      } else {
        // Cerca file nell'array
        var found = null;
        for (var i = 0; i < allFiles.length; i++) {
          if (allFiles[i].name === fname) {
            found = allFiles[i];
            break;
          }
        }
        matchMap[trackId].file = found;
        matchMap[trackId].confidence = 'ok';
        matchMap[trackId].fileLabel = fname;
        if (badgeEl) badgeEl.innerHTML = '<span class="badge-match badge-match-ok"><i class="bi bi-check-circle me-1"></i>Associato</span>';
      }
      updateSummary();
    }

    /* ----------------------------------------------------------------
       Aggiorna contatori riepilogo
    ---------------------------------------------------------------- */
    function updateSummary() {
      var ok = 0,
        warn = 0,
        skip = 0,
        exists = 0;
      for (var tid in matchMap) {
        var c = matchMap[tid].confidence;
        if (c === 'ok') ok++;
        else if (c === 'warn') warn++;
        else if (c === 'skip') skip++;
        else if (c === 'exists') exists++;
      }
      var total = tracks.length;
      matchSummary.innerHTML =
        '<strong>' + total + ' tracce</strong> — ' +
        '<span class="text-success">' + ok + ' associate</span>, ' +
        (warn ? '<span class="text-warning">' + warn + ' da verificare</span>, ' : '') +
        '<span class="text-secondary">' + skip + ' senza file</span>' +
        (exists ? ', <span class="text-primary">' + exists + ' già presenti</span>' : '');
    }

    /* ----------------------------------------------------------------
       Trova il titolo di una traccia dall'id (per i messaggi di errore)
    ---------------------------------------------------------------- */
    function trackTitleById(tid) {
      for (var i = 0; i < tracks.length; i++) {
        if (tracks[i].id === tid) return tracks[i].position + '. ' + tracks[i].title;
      }
      return 'Traccia #' + tid;
    }

    /* ----------------------------------------------------------------
       Aggiorna il badge sulla riga della tabella di matching
       durante l'upload (feedback in tempo reale)
    ---------------------------------------------------------------- */
    function setRowUploadState(trackId, state, msg) {
      var row = matchTable.querySelector('.bulk-track-row[data-track-id="' + trackId + '"]');
      if (!row) return;
      var badgeEl = row.querySelector('.badge-col');
      if (!badgeEl) return;

      if (state === 'uploading') {
        badgeEl.innerHTML =
          '<span class="badge-match" style="background:rgba(255,193,7,.18);color:#856404">' +
          '<span class="spinner-border spinner-border-sm me-1" style="width:.75rem;height:.75rem"></span>' +
          'Caricamento…</span>';
      } else if (state === 'done') {
        badgeEl.innerHTML =
          '<span class="badge-match badge-match-ok">' +
          '<i class="bi bi-check-circle-fill me-1"></i>Caricato</span>';
      } else if (state === 'error') {
        // Mostra il messaggio di errore esatto inline sulla riga
        badgeEl.innerHTML =
          '<span class="badge-match badge-match-err" title="' + escAttr(msg) + '">' +
          '<i class="bi bi-x-circle-fill me-1"></i>Errore' +
          '</span>' +
          '<div class="bulk-row-error small text-danger mt-1">' + escHtml(msg) + '</div>';
      }
    }

    /* ----------------------------------------------------------------
       Render report finale dopo il completamento di tutti gli upload
    ---------------------------------------------------------------- */
    function renderFinalReport(results, total) {
      var okList = results.filter(function(r) {
        return r.ok;
      });
      var errList = results.filter(function(r) {
        return !r.ok;
      });

      var allOk = errList.length === 0;

      // Barra di stato
      uploadStatus.innerHTML = allOk ?
        '<i class="bi bi-check-circle-fill text-success me-1"></i>' +
        '<strong>' + okList.length + '/' + total + '</strong> file caricati correttamente.' :
        '<i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>' +
        '<strong>' + okList.length + '/' + total + '</strong> file caricati' +
        ' — <strong class="text-danger">' + errList.length + ' errori</strong>.';

      // Se ci sono errori: mostra report dettagliato nel modal body, NON ricaricare
      if (errList.length > 0) {
        var reportHtml =
          '<div class="alert alert-danger py-2 mt-3 mb-0" id="bulkErrorReport">' +
          '<div class="fw-semibold mb-2"><i class="bi bi-x-circle me-1"></i>Dettaglio errori:</div>' +
          '<table class="table table-sm table-borderless mb-0 small">' +
          '<thead><tr>' +
          '<th>Traccia</th>' +
          '<th>File</th>' +
          '<th>Motivo</th>' +
          '</tr></thead><tbody>';

        for (var ei = 0; ei < errList.length; ei++) {
          var er = errList[ei];
          // Trova il nome del file dalla lista originale
          var fname = '—';
          for (var fi = 0; fi < toUploadRef.length; fi++) {
            if (toUploadRef[fi].trackId === er.trackId) {
              fname = toUploadRef[fi].file.name;
              break;
            }
          }
          reportHtml +=
            '<tr>' +
            '<td>' + escHtml(trackTitleById(er.trackId)) + '</td>' +
            '<td class="font-monospace">' + escHtml(fname) + '</td>' +
            '<td class="text-danger">' + escHtml(er.msg) + '</td>' +
            '</tr>';
        }

        reportHtml += '</tbody></table></div>';

        // Inserisce il report nel modal body sopra il bulk step 2
        var modalBody = document.querySelector('#bulkUploadModal .modal-body');
        var existing = document.getElementById('bulkErrorReport');
        if (existing) existing.remove();
        if (modalBody) modalBody.insertAdjacentHTML('afterbegin', reportHtml);

        // Bottone "Ricarica pagina" separato nel footer — l'utente decide quando
        btnConfirm.style.display = '';
        btnConfirm.disabled = false;
        btnConfirm.className = 'btn btn-outline-secondary';
        btnConfirm.innerHTML = '<i class="bi bi-arrow-clockwise me-2"></i>Ricarica pagina';
        btnConfirm.onclick = function() {
          location.reload();
        };

      } else {
        // Tutto OK: ricarica automatica dopo 1.5s
        btnConfirm.style.display = 'none';
        setTimeout(function() {
          location.reload();
        }, 1500);
      }
    }

    /* ----------------------------------------------------------------
       Riferimento alla lista in upload (serve nel report finale)
    ---------------------------------------------------------------- */
    var toUploadRef = [];

    /* ----------------------------------------------------------------
       Conferma upload: invia i file associati uno per volta con fetch
    ---------------------------------------------------------------- */
    if (btnConfirm) {
      btnConfirm.addEventListener('click', function() {
        // Costruisce lista di { trackId, file } da caricare
        toUploadRef = [];
        var warnList = []; // tiene traccia delle righe con confidence 'warn'
        for (var tid in matchMap) {
          var m = matchMap[tid];
          if ((m.confidence === 'ok' || m.confidence === 'warn') && m.file) {
            toUploadRef.push({
              trackId: parseInt(tid, 10),
              file: m.file
            });
            if (m.confidence === 'warn') {
              warnList.push(tid);
            }
          }
        }

        if (toUploadRef.length === 0) {
          uploadStatus.textContent = 'Nessun file da caricare.';
          return;
        }

        // FIX: gate sulle righe "Da verificare" non corrette manualmente.
        // Mostra un riepilogo dettagliato e richiede conferma esplicita prima di procedere.
        if (warnList.length > 0) {
          var warnDetails = '';
          for (var wi = 0; wi < warnList.length; wi++) {
            var wTid = parseInt(warnList[wi], 10);
            var wTrack = null;
            for (var wti = 0; wti < tracks.length; wti++) {
              if (tracks[wti].id === wTid) {
                wTrack = tracks[wti];
                break;
              }
            }
            var wFile = matchMap[warnList[wi]].file;
            if (wTrack && wFile) {
              warnDetails += '\n  • ' + wTrack.position + '. ' + wTrack.title +
                '  ←  ' + wFile.name;
            }
          }
          var warnMsg = warnList.length + (warnList.length === 1 ?
              ' associazione è marcata "Da verificare"' :
              ' associazioni sono marcate "Da verificare"') +
            ' e potrebbe essere errata:' + warnDetails +
            '\n\nVerifica i dropdown prima di procedere, oppure clicca OK per caricare comunque.';
          if (!window.confirm(warnMsg)) {
            return; // l'utente torna alla tabella per correggere manualmente
          }
        }

        btnConfirm.disabled = true;
        btnConfirm.innerHTML =
          '<span class="spinner-border spinner-border-sm me-2"></span>Caricamento in corso…';

        uploadSequentially(toUploadRef, function(results) {
          renderFinalReport(results, toUploadRef.length);
        });
      });
    }

    /* ----------------------------------------------------------------
       Upload sequenziale con progress e feedback riga per riga
    ---------------------------------------------------------------- */
    function uploadSequentially(list, onDone) {
      var results = [];

      function next(i) {
        if (i >= list.length) {
          onDone(results);
          return;
        }

        var item = list[i];
        var pct = Math.round(((i) / list.length) * 100);

        // Aggiorna barra progresso nel footer
        uploadStatus.innerHTML =
          'Caricamento ' + (i + 1) + ' di ' + list.length +
          ' — <em class="text-muted">' + escHtml(item.file.name) + '</em>' +
          '<div class="bulk-progress-bar mt-1">' +
          '<div class="bulk-progress-bar-fill" style="width:' + pct + '%"></div>' +
          '</div>';

        // Segna la riga come "in caricamento"
        setRowUploadState(item.trackId, 'uploading', '');

        var fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('album_id', albumId);
        fd.append('track_id', item.trackId);
        fd.append('audio_file', item.file, item.file.name);

        fetch(uploadUrl, {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: fd
          })
          .then(function(r) {
            // Se il server risponde con HTML (errore PHP non gestito), lo intercettiamo
            var contentType = r.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
              return r.text().then(function(body) {
                throw new Error('Risposta non-JSON dal server. Probabilmente un errore PHP. ' +
                  'Controlla i log di Apache/MAMP. Anteprima: ' +
                  body.replace(/<[^>]+>/g, '').trim().substring(0, 120));
              });
            }
            return r.json();
          })
          .then(function(data) {
            var ok = data.success === true;
            var msg = data.message || (ok ? 'OK' : 'Errore sconosciuto dal server.');
            results.push({
              trackId: item.trackId,
              ok: ok,
              msg: msg
            });
            setRowUploadState(item.trackId, ok ? 'done' : 'error', msg);
            next(i + 1);
          })
          .catch(function(err) {
            var msg = err.message || 'Errore di rete o risposta non valida.';
            results.push({
              trackId: item.trackId,
              ok: false,
              msg: msg
            });
            setRowUploadState(item.trackId, 'error', msg);
            next(i + 1);
          });
      }

      next(0);
    }

    /* ----------------------------------------------------------------
       Utility
    ---------------------------------------------------------------- */
    function escHtml(s) {
      return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function escAttr(s) {
      return (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
    }

    // Reset al chiudere il modal
    var bulkModal = document.getElementById('bulkUploadModal');
    if (bulkModal) {
      bulkModal.addEventListener('hidden.bs.modal', function() {
        resetUI();
      });
    }

  })();
</script>

<script>
  (function() {
    // ================================================================
    // Questo blocco viene ri-eseguito ad ogni navigazione SPA.
    // Le variabili di riferimento DOM vanno ri-lette ogni volta
    // perché il DOM viene sostituito dalla navigazione SPA.
    // I document.addEventListener sono registrati UNA SOLA VOLTA
    // tramite il guard flag window.__albumDetailBound.
    // ================================================================

    var BASE = '<?= BASE_URL ?>';
    var ALBUM_ID = <?= (int)$album['id'] ?>;

    // Legge i riferimenti DOM freschi ad ogni esecuzione
    function getRefs() {
      var modal = document.getElementById('addToPlaylistModal');
      return {
        modal: modal,
        modalTitle: modal ? modal.querySelector('.modal-title') : null,
        btnConfirm: document.getElementById('btnConfirmAddToPlaylist'),
        selExist: document.getElementById('selectExistingPlaylist'),
        selWrap: document.getElementById('selectExistingPlaylist') ?
          document.getElementById('selectExistingPlaylist').closest('.mb-3') : null,
        orDivider: modal ? modal.querySelector('.text-center.text-muted.small') : null,
        inputNew: document.getElementById('newPlaylistNameAlbum'),
        feedback: document.getElementById('addToPlaylistFeedback'),
        deleteAudioModal: document.getElementById('deleteAudioModal'),
        deleteAudioTitle: document.getElementById('deleteAudioTrackTitle'),
        btnConfirmDelAudio: document.getElementById('btnConfirmDeleteAudio')
      };
    }

    // Stato condiviso: vive fuori dall'IIFE tramite window
    // così sopravvive alle navigazioni SPA
    if (!window.__albumDetailState) {
      window.__albumDetailState = {
        modalMode: 'album',
        pendingTrackId: null,
        pendingAudioId: null,
        pendingAudioCsrf: null,
        pendingAudioRow: null,
        albumId: 0
      };
    }
    var S = window.__albumDetailState;
    // albumId DEVE essere aggiornato ad ogni esecuzione dell'IIFE.
    // I listener nel guard usano S.albumId — così leggono sempre
    // l'album corrente anche dopo navigazioni SPA successive.
    S.albumId = ALBUM_ID;

    /* ----------------------------------------------------------
       Utility: fetch add-tracks
    ---------------------------------------------------------- */
    function sendToPlaylist(body, btnEl, onSuccess) {
      var refs = getRefs();
      var origHtml = btnEl ? btnEl.innerHTML : '';
      if (btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
      }
      fetch(BASE + '/index.php?route=playlists/add-tracks', {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: body
        })
        .then(function(r) {
          return r.json();
        })
        .then(function(d) {
          if (d.success) {
            if (d.playlist_id && d.playlist_name) {
              injectPlaylistIntoDropdowns(d.playlist_id, d.playlist_name);
            }
            // Se la playlist appena modificata è quella attiva nel player,
            // aggiorna la queue in background senza interrompere la riproduzione.
            // Questo garantisce che le tracce appena aggiunte siano subito disponibili.
            if (d.playlist_id && typeof PlaylistPlayer !== 'undefined' &&
              typeof PlaylistPlayer.activeId === 'function' &&
              typeof PlaylistPlayer.refreshQueue === 'function' &&
              PlaylistPlayer.activeId() === d.playlist_id) {
              PlaylistPlayer.refreshQueue(d.playlist_id);
            }
            if (typeof onSuccess === 'function') onSuccess(d);
          } else {
            if (btnEl) {
              btnEl.disabled = false;
              btnEl.innerHTML = origHtml;
            }
            var fb = getRefs().feedback;
            var mo = getRefs().modal;
            if (fb && mo && mo.classList.contains('show')) {
              fb.innerHTML = '<div class="alert alert-danger py-1 small mb-0">' +
                (d.error || 'Errore durante l&#39;aggiunta.') + '</div>';
            }
          }
        })
        .catch(function() {
          if (btnEl) {
            btnEl.disabled = false;
            btnEl.innerHTML = origHtml;
          }
        });
    }

    /* ----------------------------------------------------------
       Inject nuova playlist nei dropdown
    ---------------------------------------------------------- */
    function injectPlaylistIntoDropdowns(plId, plName) {
      document.querySelectorAll('.btn-add-track-new-playlist').forEach(function(newBtn) {
        var ul = newBtn.closest('ul');
        if (!ul) return;
        var dividerLi = newBtn.closest('li').previousElementSibling;
        var li = document.createElement('li');
        li.className = 'd-flex align-items-center px-1 gap-1';
        li.innerHTML =
          '<button class="dropdown-item small btn-add-track-to-playlist' +
          ' d-flex align-items-center justify-content-between flex-grow-1 pe-0"' +
          ' type="button"' +
          ' data-track-id="' + escAttrDrop(newBtn.dataset.trackId) + '"' +
          ' data-playlist-id="' + plId + '"' +
          ' data-playlist-name="' + escAttrDrop(plName) + '">' +
          '<span><i class="bi bi-collection me-2 text-muted"></i>' + escHtmlDrop(plName) + '</span>' +
          '</button>' +
          '<a href="' + BASE + '/index.php?route=playlists/detail/' + plId + '"' +
          ' class="btn btn-xs btn-link text-muted flex-shrink-0 px-1"' +
          ' title="Apri playlist">' +
          '<i class="bi bi-box-arrow-up-right" style="font-size:.65rem"></i>' +
          '</a>';
        if (dividerLi) {
          ul.insertBefore(li, dividerLi);
        } else {
          ul.insertBefore(li, newBtn.closest('li'));
        }
      });
      var sel = document.getElementById('selectExistingPlaylist');
      if (sel) {
        var opt = document.createElement('option');
        opt.value = plId;
        opt.textContent = plName;
        sel.appendChild(opt);
      }
    }

    function escHtmlDrop(s) {
      return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function escAttrDrop(s) {
      return (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
    }

    /* ----------------------------------------------------------
       Fetch elimina audio
    ---------------------------------------------------------- */
    function doAudioFetch(audioId, csrf, row) {
      var formData = new FormData();
      formData.append('csrf_token', csrf);
      fetch(BASE + '/index.php?route=upload/delete-audio/' + audioId, {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: formData
        })
        .then(function(r) {
          return r.json();
        })
        .then(function(d) {
          if (d.success && row) {
            row.innerHTML = '<span class="text-muted small fst-italic">' +
              '<i class="bi bi-music-note text-muted"></i> nessun audio</span>';
          } else if (!d.success && row) {
            var e = document.createElement('span');
            e.className = 'text-danger small ms-2';
            e.textContent = d.error || 'Errore eliminazione.';
            row.appendChild(e);
            setTimeout(function() {
              e.remove();
            }, 3000);
          }
          S.pendingAudioId = S.pendingAudioCsrf = S.pendingAudioRow = null;
          var btn = document.getElementById('btnConfirmDeleteAudio');
          if (btn) {
            btn.disabled = false;
            btn.textContent = 'Elimina';
          }
        })
        .catch(function() {
          S.pendingAudioId = S.pendingAudioCsrf = S.pendingAudioRow = null;
          var btn = document.getElementById('btnConfirmDeleteAudio');
          if (btn) {
            btn.disabled = false;
            btn.textContent = 'Elimina';
          }
        });
    }

    /* ----------------------------------------------------------
       GUARD: registra i listener globali UNA SOLA VOLTA.
       Il flag window.__albumDetailBound impedisce registrazioni
       multiple causate dalle navigazioni SPA che rieseguono l'IIFE.
    ---------------------------------------------------------- */
    if (!window.__albumDetailBound) {
      window.__albumDetailBound = true;

      // --- Click su playlist esistente nel dropdown traccia ---
      document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-add-track-to-playlist');
        if (!btn) return;
        var trackId = btn.dataset.trackId;
        var playlistId = btn.dataset.playlistId;
        var plName = btn.dataset.playlistName;
        sendToPlaylist(
          'playlist_id=' + encodeURIComponent(playlistId) +
          '&track_ids[]=' + encodeURIComponent(trackId),
          btn,
          function() {
            var li = btn.closest('li.d-flex');
            if (li) {
              var linkEl = li.querySelector('a.btn-link');
              var linkHtml = linkEl ? linkEl.outerHTML : '';
              li.innerHTML =
                '<span class="dropdown-item small text-muted d-flex align-items-center' +
                ' justify-content-between flex-grow-1 pe-0 disabled">' +
                '<span><i class="bi bi-check2 me-2 text-success"></i>' + plName + '</span>' +
                '<span class="badge bg-success-subtle text-success ms-2"' +
                ' style="font-size:.65rem;white-space:nowrap">presente</span>' +
                '</span>' + linkHtml;
            }
          }
        );
      });

      // --- Click su "Nuova playlist…" nel dropdown traccia ---
      document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-add-track-new-playlist');
        if (!btn) return;
        S.modalMode = 'track';
        S.pendingTrackId = btn.dataset.trackId;
        var refs = getRefs();
        if (refs.modalTitle) refs.modalTitle.innerHTML =
          '<i class="bi bi-collection-play me-2 text-success"></i>Nuova playlist';
        if (refs.selWrap) refs.selWrap.style.display = 'none';
        if (refs.orDivider) refs.orDivider.style.display = 'none';
        if (refs.inputNew) {
          refs.inputNew.value = '';
          refs.inputNew.placeholder = 'Nome nuova playlist\u2026';
        }
        if (refs.feedback) refs.feedback.innerHTML = '';
        if (refs.btnConfirm) {
          refs.btnConfirm.disabled = false;
          refs.btnConfirm.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Crea e aggiungi';
        }
        if (refs.modal) {
          var inst = bootstrap.Modal.getInstance(refs.modal);
          if (inst) inst.dispose();
          new bootstrap.Modal(refs.modal).show();
        }
      });

      // --- show.bs.modal: configurazione modalità album ---
      // Se il modal viene aperto con data-bs-target (dal bottone album/tre puntini),
      // forza sempre modalMode = 'album' così la selezione del <select> funziona
      // anche se il modal era stato usato l'ultima volta per una traccia singola.
      document.addEventListener('show.bs.modal', function(e) {
        var refs = getRefs();
        if (!refs.modal || e.target !== refs.modal) return;
        // Se aperto tramite data-bs-toggle (bottone album), forza album mode
        if (e.relatedTarget && e.relatedTarget.dataset.bsTarget === '#addToPlaylistModal') {
          S.modalMode = 'album';
        }
        if (S.modalMode === 'track') return;
        if (refs.modalTitle) refs.modalTitle.innerHTML =
          '<i class="bi bi-collection-play me-2 text-success"></i>Aggiungi a playlist';
        if (refs.selWrap) refs.selWrap.style.display = '';
        if (refs.orDivider) refs.orDivider.style.display = '';
        if (refs.selExist) refs.selExist.value = '';
        if (refs.inputNew) refs.inputNew.value = '';
        if (refs.feedback) refs.feedback.innerHTML = '';
        if (refs.btnConfirm) {
          refs.btnConfirm.disabled = false;
          refs.btnConfirm.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Aggiungi album';
        }
      });

      // --- hidden.bs.modal: reset stato + pulizia backdrop ---
      document.addEventListener('hidden.bs.modal', function(e) {
        var refs = getRefs();
        if (!refs.modal || e.target !== refs.modal) return;
        S.modalMode = 'album';
        S.pendingTrackId = null;
        if (refs.selWrap) refs.selWrap.style.display = '';
        if (refs.orDivider) refs.orDivider.style.display = '';
        if (refs.inputNew) refs.inputNew.value = '';
        if (refs.feedback) refs.feedback.innerHTML = '';
        if (refs.modalTitle) refs.modalTitle.innerHTML =
          '<i class="bi bi-collection-play me-2 text-success"></i>Aggiungi a playlist';
        if (refs.btnConfirm) {
          refs.btnConfirm.disabled = false;
          refs.btnConfirm.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Aggiungi album';
        }
        document.querySelectorAll('.modal-backdrop').forEach(function(b) {
          b.remove();
        });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
      });

      // --- Click btn-delete-audio ---
      document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-delete-audio');
        if (!btn) return;
        S.pendingAudioId = btn.dataset.audioId;
        S.pendingAudioCsrf = btn.dataset.csrf;
        S.pendingAudioRow = btn.closest('.d-flex');
        var titleEl = document.getElementById('deleteAudioTrackTitle');
        if (titleEl) titleEl.textContent = '\u201c' + (btn.dataset.trackTitle || 'questa traccia') + '\u201d';
        var dam = document.getElementById('deleteAudioModal');
        if (dam) bootstrap.Modal.getOrCreateInstance(dam).show();
      });

      // --- Conferma elimina audio ---
      document.addEventListener('click', function(e) {
        if (!e.target.closest('#btnConfirmDeleteAudio')) return;
        if (!S.pendingAudioId) return;
        var btn = document.getElementById('btnConfirmDeleteAudio');
        if (btn) {
          btn.disabled = true;
          btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>\u2026';
        }
        var audioId = S.pendingAudioId;
        var csrf = S.pendingAudioCsrf;
        var row = S.pendingAudioRow;
        var dam = document.getElementById('deleteAudioModal');
        var inst = dam ? bootstrap.Modal.getInstance(dam) : null;
        if (inst) {
          dam.addEventListener('hidden.bs.modal', function doFetch() {
            dam.removeEventListener('hidden.bs.modal', doFetch);
            doAudioFetch(audioId, csrf, row);
          }, {
            once: true
          });
          inst.hide();
        } else {
          doAudioFetch(audioId, csrf, row);
        }
      });

      // --- Conferma modal playlist (btnConfirmAddToPlaylist) ---
      document.addEventListener('click', function(e) {
        if (!e.target.closest('#btnConfirmAddToPlaylist')) return;
        var refs = getRefs();
        var newName = refs.inputNew ? refs.inputNew.value.trim() : '';
        var existing = (S.modalMode === 'album' && refs.selExist) ? refs.selExist.value.trim() : '';

        if (S.modalMode === 'track') {
          if (!newName) {
            if (refs.feedback) refs.feedback.innerHTML =
              '<div class="alert alert-warning py-1 small mb-0">Inserisci un nome per la nuova playlist.</div>';
            if (refs.inputNew) refs.inputNew.focus();
            return;
          }
          var body = 'playlist_id=new&playlist_name=' + encodeURIComponent(newName) +
            '&track_ids[]=' + encodeURIComponent(S.pendingTrackId);
        } else {
          if (!existing && !newName) {
            if (refs.feedback) refs.feedback.innerHTML =
              '<div class="alert alert-warning py-1 small mb-0">Seleziona una playlist oppure inserisci un nome.</div>';
            return;
          }
          var body = 'album_id=' + encodeURIComponent(S.albumId);
          body += existing ?
            '&playlist_id=' + encodeURIComponent(existing) :
            '&playlist_id=new&playlist_name=' + encodeURIComponent(newName);
        }

        sendToPlaylist(body, refs.btnConfirm, function(d) {
          var msg = S.modalMode === 'track' ? 'Traccia aggiunta!' : 'Tracce aggiunte!';
          var plId = d.playlist_id || null;

          if (refs.feedback) {
            // "Vai alla playlist" è un bottone, NON un <a href>.
            // Un link <a> verrebbe intercettato dalla SPA mentre il modal
            // è ancora aperto, lasciando il backdrop nel DOM della pagina nuova.
            // Il bottone invece chiude prima il modal (hidden.bs.modal)
            // e naviga solo dopo che il DOM è stato ripulito.
            refs.feedback.innerHTML = '<div class="alert alert-success py-1 small mb-0 d-flex align-items-center justify-content-between">' +
              '<span><i class="bi bi-check-circle me-1"></i>' + msg + '</span>' +
              (plId ?
                '<button type="button" class="btn btn-xs btn-outline-success ms-2 flex-shrink-0"' +
                ' id="btnGoToPlaylist" data-pl-id="' + plId + '">Vai alla playlist</button>' :
                '') +
              '</div>';
          }
          if (refs.btnConfirm) refs.btnConfirm.innerHTML = '<i class="bi bi-check-lg me-1"></i>Aggiunto';

          // Registra il click sul bottone "Vai alla playlist" una sola volta
          // (dentro onSuccess, quindi non si accumula)
          if (plId) {
            var goBtn = document.getElementById('btnGoToPlaylist');
            if (goBtn) {
              goBtn.addEventListener('click', function() {
                var targetUrl = BASE + '/index.php?route=playlists/detail/' + plId;
                var m = getRefs().modal;
                var inst = m ? bootstrap.Modal.getInstance(m) : null;
                if (inst) {
                  // Naviga SOLO dopo che il modal ha finito di chiudersi
                  // e il backdrop è stato rimosso dal DOM
                  m.addEventListener('hidden.bs.modal', function doNav() {
                    m.removeEventListener('hidden.bs.modal', doNav);
                    if (window._spaNavigate) window._spaNavigate(targetUrl);
                    else location.href = targetUrl;
                  }, {
                    once: true
                  });
                  inst.hide();
                } else {
                  if (window._spaNavigate) window._spaNavigate(targetUrl);
                  else location.href = targetUrl;
                }
              }, {
                once: true
              });
            }
          }

          // Auto-chiusura dopo 3s se l'utente non clicca "Vai alla playlist"
          setTimeout(function() {
            var m = getRefs().modal;
            var inst = m ? bootstrap.Modal.getInstance(m) : null;
            if (inst) inst.hide();
          }, 3000);
        });
      });

    } // fine guard __albumDetailBound

  })();
</script>

<?php
require BASE_PATH . '/views/layout/footer.php';
function formatBadge(string $f): string
{
  switch ($f) {
    case 'Vinile':
      return 'warning';
    case 'CD':
      return 'info';
    case 'Musicassetta':
      return 'success';
    default:
      return 'secondary';
    case 'Digital':
      return 'primary';
  }
}
function conditionLabel(string $value): string
{
  $map = [
    'Mint'           => 'Nuovo (M)',
    'Near Mint'      => 'Come Nuovo (NM o M-)',
    'Very Good Plus' => 'Ottimo (VG+)',
    'Very Good'      => 'Molto buono (VG)',
    'Good Plus'      => 'Più che buono (G+)',
    'Good'           => 'Buono (G)',
    'Fair'           => 'Pessimo (F)',
    'Poor'           => 'Scarso (P)',
  ];
  return $map[$value] ?? $value;
}
?>
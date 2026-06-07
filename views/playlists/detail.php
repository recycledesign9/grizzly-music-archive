<?php

/** @var array $playlist */
/** @var array $tracks */

$pageTitle = htmlspecialchars($playlist['name']) . ' — Playlist';
require BASE_PATH . '/views/layout/header.php';

// Conta tracce riproducibili
$playableTracks = array_filter($tracks, function ($t) {
  return !empty($t['audio_filename']);
});
$playableCount  = count($playableTracks);
$totalCount     = count($tracks);

// YouTube share: raccoglie i video_id disponibili (massimo 50)
$ytIds = array_filter(array_column($tracks, 'youtube_id'));
$ytIds = array_values(array_unique($ytIds));
?>

<!-- Intestazione playlist -->
<div class="d-flex flex-wrap align-items-center gap-3 mb-4">

  <div class="me-auto">
    <div class="d-flex align-items-center gap-2">
      <h4 class="mb-0" id="playlistNameDisplay">
        <?= htmlspecialchars($playlist['name']) ?>
      </h4>
      <button class="btn btn-link btn-sm text-muted p-0 ms-1"
        id="btnRenamePlaylist"
        title="Rinomina playlist">
        <i class="bi bi-pencil-fill" style="font-size:.8rem"></i>
      </button>
    </div>
    <?php
    // Durata totale: somma solo le tracce con audio E con duration_sec noto
    $totalSec = 0;
    foreach ($tracks as $tr) {
      if (!empty($tr['audio_filename']) && !empty($tr['duration_sec'])) {
        $totalSec += (int)$tr['duration_sec'];
      }
    }
    $durStr = '';
    if ($totalSec > 0) {
      $h = floor($totalSec / 3600);
      $m = floor(($totalSec % 3600) / 60);
      $s = $totalSec % 60;
      if ($h > 0) {
        $durStr = $h . 'h ' . $m . 'min';
      } elseif ($m > 0) {
        $durStr = $m . ' min ' . str_pad($s, 2, '0', STR_PAD_LEFT) . ' sec';
      } else {
        $durStr = $s . ' sec';
      }
    }
    ?>
    <div class="text-muted small mt-1" id="playlistStats">
      <span id="plStatTotal"><?= $totalCount ?></span>
      <span id="plStatTotalLabel"> <?= $totalCount === 1 ? 'traccia' : 'tracce' ?></span>
      <?php if ($totalCount > 0): ?>
        <?php if ($playableCount < $totalCount): ?>
          &mdash; <span id="plStatAudio" class="text-warning">
            <?= $playableCount ?> con audio
          </span>
        <?php else: ?>
          &mdash; <span id="plStatAudio" class="text-success">tutte con audio</span>
        <?php endif; ?>
      <?php else: ?>
        <span id="plStatAudio"></span>
      <?php endif; ?>
      <?php if ($durStr): ?>
        <span id="plStatDur" class="ms-1 text-muted">
          &mdash; <?= $durStr ?>
        </span>
      <?php else: ?>
        <span id="plStatDur" class="ms-1 text-muted" style="display:none"></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Azioni principali -->
  <div class="d-flex gap-2 flex-wrap">

    <?php if ($playableCount > 0): ?>
      <button class="btn btn-success btn-sm" id="btnPlayAll">
        <i class="bi bi-play-fill me-1"></i>Riproduci
      </button>
    <?php else: ?>
      <button class="btn btn-outline-secondary btn-sm" disabled title="Nessun file audio">
        <i class="bi bi-play-fill me-1"></i>Riproduci
      </button>
    <?php endif; ?>

    <!-- YouTube: apre tutti i video cachati come coda di riproduzione su YouTube -->
    <?php if (!empty($ytIds)): ?>
      <?php $ytUrl = 'https://www.youtube.com/watch_videos?video_ids=' . implode(',', array_map('htmlspecialchars', $ytIds)); ?>
      <a href="<?= $ytUrl ?>"
        target="_blank"
        rel="noopener noreferrer"
        class="btn btn-outline-danger btn-sm"
        title="Apri <?= count($ytIds) ?> <?= count($ytIds) === 1 ? 'traccia' : 'tracce' ?> su YouTube">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" class="me-1">
          <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" />
        </svg>
        YouTube
        <span class="badge bg-danger ms-1" style="font-size:.65rem"><?= count($ytIds) ?></span>
      </a>
    <?php endif; ?>

    <button class="btn btn-outline-secondary btn-sm" id="btnToggleSelect"
      title="Seleziona tracce per eliminazione multipla">
      <i class="bi bi-check2-square me-1"></i>Seleziona
    </button>

    <a href="<?= BASE_URL ?>/index.php?route=playlists"
      class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i>Playlist
    </a>

  </div>
</div>


<?php if (empty($tracks)): ?>

  <div class="text-center py-5">
    <i class="bi bi-collection-play display-4 d-block mb-3 text-muted opacity-25"></i>
    <p class="fw-semibold mb-1">Questa playlist è vuota</p>
    <p class="text-muted small mb-4">
      Vai all&#39;archivio, apri un disco e usa
      <strong>Aggiungi a playlist</strong> su una traccia o sull&#39;album intero.
    </p>
    <a href="<?= BASE_URL ?>/index.php?route=albums/list"
      class="btn btn-warning btn-sm px-4">
      <i class="bi bi-collection me-2"></i>Vai all&#39;Archivio
    </a>
  </div>

<?php else: ?>

  <div class="card shadow-sm border-0">
    <div class="card-header d-flex align-items-center justify-content-between py-2">
      <span class="fw-semibold small">
        <i class="bi bi-music-note-list me-2"></i>Tracce
      </span>
      <span class="text-muted small">
        Trascina <i class="bi bi-grip-vertical"></i> per riordinare
      </span>
    </div>

    <ul class="list-group list-group-flush" id="playlistTrackList">
      <?php foreach ($tracks as $idx => $t):
        $hasAudio = !empty($t['audio_filename']);
        $duration = '';
        if ($t['duration_sec']) {
          $duration = floor($t['duration_sec'] / 60) . ':' . str_pad($t['duration_sec'] % 60, 2, '0', STR_PAD_LEFT);
        }
        // Priorità: cover locale > URL remota > placeholder
        if (!empty($t['cover_local'])) {
          $coverSrc = BASE_URL . '/public/uploads/' . $t['cover_local'];
        } elseif (!empty($t['cover_url'])) {
          $coverSrc = strpos($t['cover_url'], 'http') === 0
            ? $t['cover_url']
            : BASE_URL . '/public/uploads/' . $t['cover_url'];
        } else {
          $coverSrc = BASE_URL . '/public/img/placeholder.png';
        }
      ?>
        <li class="list-group-item track-item px-3 py-2 d-flex align-items-center gap-3"
          data-track-id="<?= (int)$t['track_id'] ?>"
          data-position="<?= (int)$t['position'] ?>"
          data-has-audio="<?= $hasAudio ? '1' : '0' ?>"
          data-duration-sec="<?= (int)($t['duration_sec'] ?? 0) ?>"
          <?= !$hasAudio ? 'style="opacity:.6"' : '' ?>>

          <!-- Checkbox selezione multipla — visibile solo in modalità selezione -->
          <input type="checkbox"
            class="form-check-input track-select-cb flex-shrink-0"
            data-track-id="<?= (int)$t['track_id'] ?>"
            style="display:none;width:1.1rem;height:1.1rem;cursor:pointer;margin:0">

          <!-- Handle drag -->
          <span class="drag-handle text-muted flex-shrink-0"
            style="cursor:grab;font-size:1.1rem"
            title="Trascina per riordinare">
            <i class="bi bi-grip-vertical"></i>
          </span>

          <!-- Numero posizione + slot icona animata (tra drag-handle e numero) -->
          <span class="pl-track-pos-wrap text-muted small flex-shrink-0">
            <span class="pl-track-playing-icon" style="display:none"></span>
            <span class="pl-track-num"><?= (int)$t['position'] ?></span>
          </span>

          <!-- Cover album piccola — cliccabile verso il dettaglio album -->
          <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= (int)$t['album_id'] ?>"
            class="flex-shrink-0"
            title="Vai all'album: <?= htmlspecialchars($t['album_title'], ENT_QUOTES) ?>">
            <img src="<?= htmlspecialchars($coverSrc) ?>"
              alt="<?= htmlspecialchars($t['album_title'], ENT_QUOTES) ?>"
              width="36" height="36"
              class="rounded"
              style="object-fit:cover;display:block">
          </a>

          <!-- Titolo + album/artista -->
          <div class="flex-grow-1 overflow-hidden">
            <div class="fw-semibold text-truncate"><?= htmlspecialchars($t['title']) ?></div>
            <div class="small text-muted text-truncate">
              <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= (int)$t['artist_id'] ?>"
                class="text-muted text-decoration-none">
                <?= htmlspecialchars($t['artist_name']) ?>
              </a>
              &mdash;
              <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= (int)$t['album_id'] ?>"
                class="text-muted text-decoration-none">
                <?= htmlspecialchars($t['album_title']) ?>
              </a>
            </div>
          </div>

          <!-- Durata -->
          <?php if ($duration): ?>
            <span class="text-muted small flex-shrink-0 d-none d-sm-inline"><?= $duration ?></span>
          <?php endif; ?>

          <!-- Badge audio -->
          <?php if ($hasAudio): ?>
            <span class="badge bg-success-subtle text-success flex-shrink-0"
              title="File audio disponibile">
              <i class="bi bi-music-note-beamed"></i>
            </span>
          <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary flex-shrink-0"
              title="Nessun file audio — traccia saltata in riproduzione">
              <i class="bi bi-dash"></i>
            </span>
          <?php endif; ?>

          <!-- Play singola traccia (solo se ha audio) -->
          <?php if ($hasAudio): ?>
            <button class="btn btn-xs btn-outline-success flex-shrink-0 btn-play-track btn-track-play"
              data-playlist-id="<?= (int)$playlist['id'] ?>"
              data-index="<?= $idx ?>"
              data-track-id="<?= (int)$t['track_id'] ?>"
              title="Riproduci da qui">
              <i class="bi bi-play-fill"></i>
            </button>
          <?php else: ?>
            <button class="btn btn-xs btn-outline-secondary flex-shrink-0" disabled
              title="Audio non disponibile">
              <i class="bi bi-play-fill"></i>
            </button>
          <?php endif; ?>

          <!-- Rimuovi dalla playlist -->
          <button class="btn btn-xs btn-outline-danger flex-shrink-0 btn-remove-track"
            data-playlist-id="<?= (int)$playlist['id'] ?>"
            data-track-id="<?= (int)$t['track_id'] ?>"
            title="Rimuovi dalla playlist">
            <i class="bi bi-x-lg"></i>
          </button>

        </li>
      <?php endforeach; ?>
    </ul>
  </div>

<?php endif; ?>


<!-- ===== Toolbar selezione multipla ===== -->
<div id="bulkSelectToolbar"
  class="bulk-toolbar"
  style="display:none"
  aria-live="polite">
  <div class="d-flex align-items-center gap-3">
    <span class="bulk-count fw-semibold" id="bulkCountLabel">0 selezionate</span>
    <button class="btn btn-xs btn-outline-secondary" id="btnSelectAll">
      <i class="bi bi-check-all me-1"></i>Tutte
    </button>
    <button class="btn btn-xs btn-outline-secondary" id="btnDeselectAll">
      <i class="bi bi-x me-1"></i>Nessuna
    </button>
  </div>
  <button class="btn btn-sm btn-danger" id="btnBulkDelete" disabled>
    <i class="bi bi-trash me-1"></i>Elimina selezionate
  </button>
</div>

<!-- ===== Modal: Conferma rimozione traccia ===== -->
<div class="modal fade" id="removeTrackModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-danger">
          <i class="bi bi-dash-circle me-2"></i>Rimuovi traccia
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body small">
        Rimuovere <strong id="removeTrackTitle"></strong> dalla playlist?
        <div class="text-muted mt-1" style="font-size:.8rem">Il file audio non verrà eliminato.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
        <button class="btn btn-sm btn-danger" id="btnConfirmRemoveTrack">Rimuovi</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== Modal: Rinomina playlist ===== -->
<div class="modal fade" id="renamePlaylistModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Rinomina playlist</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text"
          class="form-control"
          id="renamePlaylistInput"
          value="<?= htmlspecialchars($playlist['name'], ENT_QUOTES) ?>"
          maxlength="150"
          autocomplete="off">
        <div class="invalid-feedback" id="renamePlaylistError"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
        <button class="btn btn-sm btn-warning" id="btnConfirmRename">Rinomina</button>
      </div>
    </div>
  </div>
</div>


<script>
  (function() {

    var PLAYLIST_ID = <?= (int)$playlist['id'] ?>;
    var BASE = '<?= BASE_URL ?>';

    // ================================================================
    // REGOLA FONDAMENTALE: tutti i listener su elementi della tracklist
    // usano EVENT DELEGATION sul container #playlistTrackList.
    // Motivo: SortableJS sposta nodi fisici nel DOM — i listener attaccati
    // direttamente ai <li> si perdono dopo il drag. Il container non si
    // muove mai, quindi il suo listener è sempre valido.
    // ================================================================

    var listContainer = document.getElementById('playlistTrackList');

    /* ---------- Delegation: Play / Pause per traccia singola ----------
       FONTE UNICA DI VERITA': usa sempre Player.getPlaylist() corrente.
       - Caso 1: traccia già attiva → toggle pause/play (no fetch)
       - Caso 2: playlist caricata → Player.playTrack(id) (no fetch, usa queue corrente)
       - Caso 3: playlist non attiva → PlaylistPlayer.load() (fetch + ricarica)
    ------------------------------------------------------------------ */
    if (listContainer) {
      listContainer.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-play-track');
        if (!btn) return;

        var clickedTrackId = parseInt(btn.dataset.trackId, 10);
        var audio = document.getElementById('global-audio');

        // Caso 1: traccia già attiva → toggle
        var isCurrentTrack = (
          typeof Player !== 'undefined' &&
          typeof Player.currentTrackId === 'function' &&
          Player.currentTrackId() === clickedTrackId &&
          audio && audio.src
        );
        if (isCurrentTrack) {
          if (audio.paused) {
            audio.play();
          } else {
            audio.pause();
          }
          return;
        }

        // Caso 2: playlist già caricata → usa queue corrente (aggiornata da reorder/add)
        var isThisPlaylist = (
          typeof PlaylistPlayer !== 'undefined' &&
          typeof PlaylistPlayer.activeId === 'function' &&
          PlaylistPlayer.activeId() === PLAYLIST_ID
        );
        if (isThisPlaylist && typeof Player !== 'undefined' &&
          typeof Player.getPlaylist === 'function') {
          var queue = Player.getPlaylist();
          for (var i = 0; i < queue.length; i++) {
            if (queue[i] && queue[i].id === clickedTrackId) {
              Player.playTrack(clickedTrackId);
              return;
            }
          }
        }

        // Caso 3: playlist non attiva o traccia non in queue → ricarica
        var clickedIndex = parseInt(btn.dataset.index, 10);
        PlaylistPlayer.load(PLAYLIST_ID, clickedIndex);
      });
    }

    /* ---------- Stato bottone "Riproduci" in cima ----------
       I listener play/pause sull'audio sono registrati UNA SOLA VOLTA
       tramite guard flag window.__playlistDetailAudioBound.
       La funzione syncPlayAllBtn legge PLAYLIST_ID dal closure ogni volta,
       quindi funziona correttamente anche cambiando pagina playlist.
    ---------------------------------------------------------- */
    var btnPlayAll = document.getElementById('btnPlayAll');
    var globalAudio = document.getElementById('global-audio');

    // syncPlayAllBtn è una funzione globale (window) così il guard può
    // rimuovere e riaggiungere con il PLAYLIST_ID corretto ad ogni caricamento pagina
    window.__syncPlayAllBtn = function() {
      var btn = document.getElementById('btnPlayAll');
      var aud = document.getElementById('global-audio');
      if (!btn || !aud) return;

      var isThisPlaylist = (
        typeof PlaylistPlayer !== 'undefined' &&
        typeof PlaylistPlayer.activeId === 'function' &&
        PlaylistPlayer.activeId() === PLAYLIST_ID
      );

      if (!isThisPlaylist) {
        // Non questa playlist: assicurati che il bottone sia nello stato default
        btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Riproduci';
        btn.classList.remove('btn-warning');
        btn.classList.add('btn-success');
        return;
      }

      if (aud.paused) {
        btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Riproduci';
        btn.classList.remove('btn-warning');
        btn.classList.add('btn-success');
      } else {
        btn.innerHTML = '<i class="bi bi-pause-fill me-1"></i>In riproduzione';
        btn.classList.remove('btn-success');
        btn.classList.add('btn-warning');
      }
    };

    // Registra listener una sola volta — rimuove e riaggiunge ad ogni navigazione
    // così PLAYLIST_ID nel closure è sempre quello della pagina corrente
    if (globalAudio) {
      if (window.__syncPlayAllBoundFn) {
        globalAudio.removeEventListener('play', window.__syncPlayAllBoundFn);
        globalAudio.removeEventListener('pause', window.__syncPlayAllBoundFn);
      }
      window.__syncPlayAllBoundFn = window.__syncPlayAllBtn;
      globalAudio.addEventListener('play', window.__syncPlayAllBoundFn);
      globalAudio.addEventListener('pause', window.__syncPlayAllBoundFn);
    }

    // Stato iniziale al caricamento pagina
    window.__syncPlayAllBtn();

    // Click su "Riproduci" in cima.
    // Usa onclick invece di addEventListener per evitare accumulo:
    // ogni assegnazione sovrascrive la precedente, nessun removeEventListener necessario.
    if (btnPlayAll) {
      btnPlayAll.onclick = function() {
        var aud = document.getElementById('global-audio');
        var isThisPlaylist = (
          typeof PlaylistPlayer !== 'undefined' &&
          typeof PlaylistPlayer.activeId === 'function' &&
          PlaylistPlayer.activeId() === PLAYLIST_ID
        );
        if (isThisPlaylist && aud && !aud.paused) {
          aud.pause();
        } else if (isThisPlaylist && aud && aud.paused && aud.src) {
          aud.play();
        } else {
          var btn = document.getElementById('btnPlayAll');
          if (btn) {
            btn.innerHTML = '<i class="bi bi-pause-fill me-1"></i>In riproduzione';
            btn.classList.remove('btn-success');
            btn.classList.add('btn-warning');
          }
          PlaylistPlayer.load(PLAYLIST_ID);
        }
      };
    }

    /* ---------- Rimuovi traccia — con modal di conferma ---------- */
    var removeTrackModal = document.getElementById('removeTrackModal');
    var removeTrackTitle = document.getElementById('removeTrackTitle');
    var btnConfirmRemove = document.getElementById('btnConfirmRemoveTrack');
    var pendingRemoveRow = null;
    var pendingRemovePlId = null;
    var pendingRemoveTId = null;

    if (listContainer) {
      listContainer.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-remove-track');
        if (!btn) return;
        var row = btn.closest('li');
        var plId = btn.dataset.playlistId;
        var tId = btn.dataset.trackId;
        var titleEl = row ? row.querySelector('.fw-semibold') : null;
        var title = titleEl ? titleEl.textContent.trim() : 'questa traccia';
        pendingRemoveRow = row;
        pendingRemovePlId = plId;
        pendingRemoveTId = tId;
        if (removeTrackTitle) removeTrackTitle.textContent = '"' + title + '"';
        bootstrap.Modal.getOrCreateInstance(removeTrackModal).show();
      });
    }

    if (btnConfirmRemove) {
      btnConfirmRemove.addEventListener('click', function() {
        if (!pendingRemoveRow) return;

        var row = pendingRemoveRow;
        var plId = pendingRemovePlId;
        var tId = pendingRemoveTId;

        btnConfirmRemove.disabled = true;
        btnConfirmRemove.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>…';

        // Chiudi il modal prima della fetch — stesso pattern delete album
        var modalInst = bootstrap.Modal.getInstance(removeTrackModal);
        if (modalInst) {
          removeTrackModal.addEventListener('hidden.bs.modal', function doRemove() {
            removeTrackModal.removeEventListener('hidden.bs.modal', doRemove);
            doFetch(row, plId, tId);
          }, {
            once: true
          });
          modalInst.hide();
        } else {
          doFetch(row, plId, tId);
        }
      });
    }

    function doFetch(row, plId, tId) {
      fetch(BASE + '/index.php?route=playlists/remove-track', {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: 'playlist_id=' + encodeURIComponent(plId) +
            '&track_id=' + encodeURIComponent(tId)
        })
        .then(function(r) {
          return r.json();
        })
        .then(function(d) {
          if (d.success) {
            var removedId = parseInt(tId, 10);
            row.style.transition = 'opacity .2s';
            row.style.opacity = '0';
            setTimeout(function() {
              row.remove();
              updatePositionLabels();

              // Aggiorna queue Player: rimuove la traccia senza interrompere riproduzione
              if (typeof PlaylistPlayer !== 'undefined' &&
                typeof PlaylistPlayer.removeFromQueue === 'function') {
                PlaylistPlayer.removeFromQueue(removedId);
              }

              // Riallinea immediatamente la UI della traccia corrente.
              // Serve dopo delete perché il DOM è cambiato ma il player resta attivo.
              if (typeof Player !== 'undefined' &&
                typeof Player.refreshUI === 'function') {
                Player.refreshUI();
              }
            }, 220);
          }
          if (btnConfirmRemove) {
            btnConfirmRemove.disabled = false;
            btnConfirmRemove.textContent = 'Rimuovi';
          }
          pendingRemoveRow = pendingRemovePlId = pendingRemoveTId = null;
        })
        .catch(function() {
          if (btnConfirmRemove) {
            btnConfirmRemove.disabled = false;
            btnConfirmRemove.textContent = 'Rimuovi';
          }
          pendingRemoveRow = pendingRemovePlId = pendingRemoveTId = null;
        });
    }

    /* Ricalcola posizioni, contatori, colori stato e durata totale.
       Chiamata dopo ogni rimozione traccia (singola o in blocco). */
    function updatePlaylistStats() {
      var items = document.querySelectorAll('#playlistTrackList li.track-item');
      var total = items.length;
      var playable = 0;
      var totalSec = 0;

      items.forEach(function(li, i) {
        // Aggiorna SOLO il numero della traccia.
        // Non toccare .pl-track-pos-wrap, altrimenti viene distrutto
        // lo slot .pl-track-playing-icon usato dalla vista playlist.
        var numSpan = li.querySelector('.pl-track-num');
        if (numSpan) {
          numSpan.textContent = i + 1;
        }

        // Mantiene coerente il data-position della riga dopo delete/reorder.
        li.dataset.position = String(i + 1);

        // Conta tracce con audio e somma durata
        if (li.dataset.hasAudio === '1') {
          playable++;
          var sec = parseInt(li.dataset.durationSec || '0', 10);
          if (sec > 0) totalSec += sec;
        }
      });

      // Aggiorna testo contatori
      var elTotal = document.getElementById('plStatTotal');
      var elTotalLbl = document.getElementById('plStatTotalLabel');
      var elAudio = document.getElementById('plStatAudio');
      var elDur = document.getElementById('plStatDur');

      if (elTotal) elTotal.textContent = total;
      if (elTotalLbl) elTotalLbl.textContent = ' ' + (total === 1 ? 'traccia' : 'tracce');

      if (elAudio) {
        if (total === 0) {
          // Playlist vuota: nessuna label audio
          elAudio.textContent = '';
          elAudio.className = '';
          elAudio.style.display = 'none';
        } else if (playable === total) {
          elAudio.textContent = 'tutte con audio';
          elAudio.className = 'text-success';
          elAudio.style.display = '';
        } else {
          elAudio.textContent = playable + ' con audio';
          elAudio.className = 'text-warning';
          elAudio.style.display = '';
        }
      }

      // Aggiorna durata totale
      if (elDur) {
        if (totalSec > 0) {
          var h = Math.floor(totalSec / 3600);
          var m = Math.floor((totalSec % 3600) / 60);
          var s = totalSec % 60;
          var durStr = '';
          if (h > 0) {
            durStr = h + 'h ' + m + 'min';
          } else if (m > 0) {
            durStr = m + ' min ' + String(s).padStart(2, '0') + ' sec';
          } else {
            durStr = s + ' sec';
          }
          elDur.textContent = '— ' + durStr;
          elDur.style.display = '';
        } else {
          elDur.style.display = 'none';
        }
      }

      // Aggiorna anche il pulsante "Riproduci" in cima se non ci sono più audio
      if (playable === 0) {
        var btnPlayAll = document.getElementById('btnPlayAll');
        if (btnPlayAll) {
          btnPlayAll.disabled = true;
          btnPlayAll.innerHTML = '<i class="bi bi-play-fill me-1"></i>Riproduci';
          btnPlayAll.classList.remove('btn-success', 'btn-warning');
          btnPlayAll.classList.add('btn-outline-secondary');
        }
      }
    }

    // Alias per compatibilità con SortableJS che chiama updatePositionLabels
    function updatePositionLabels() {
      updatePlaylistStats();
    }

    /* ---------- Riordina con drag (SortableJS CDN) ---------- */
    var listEl = document.getElementById('playlistTrackList');
    if (listEl && window.Sortable) {
      Sortable.create(listEl, {
        handle: '.drag-handle',
        animation: 150,

        // Auto-scroll durante drag su playlist lunghe
        scroll: true,
        forceAutoScrollFallback: true,
        scrollSensitivity: 90,
        scrollSpeed: 12,
        bubbleScroll: true,

        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        dragClass: 'sortable-drag',

        onStart: function() {
          document.body.classList.add('playlist-sortable-dragging');
        },

        onEnd: function() {
          document.body.classList.remove('playlist-sortable-dragging');

          var order = [];
          listEl.querySelectorAll('li[data-track-id]').forEach(function(li) {
            order.push(parseInt(li.dataset.trackId, 10));
          });

          fetch(BASE + '/index.php?route=playlists/reorder', {
              method: 'POST',
              headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
              },
              body: 'playlist_id=' + PLAYLIST_ID +
                '&order[]=' + order.join('&order[]=')
            })
            .then(function(r) {
              return r.json();
            })
            .then(function(d) {
              if (d.success) {
                updatePositionLabels();

                // Aggiorna la queue in memoria del Player con il nuovo ordine
                // così prev/next seguono l'ordine visivo e non quello di inserimento
                if (typeof PlaylistPlayer !== 'undefined' &&
                  typeof PlaylistPlayer.reorderQueue === 'function') {
                  PlaylistPlayer.reorderQueue(order);
                }

                // Ripristina icona animata sulla traccia attiva dopo il riordino DOM
                if (typeof Player !== 'undefined') Player.refreshUI();
              }
            })
            .catch(function() {});
        }
      });
    }

    /* ---------- Rinomina inline ---------- */
    var btnRename = document.getElementById('btnRenamePlaylist');
    var renameModal = document.getElementById('renamePlaylistModal');
    var renameInput = document.getElementById('renamePlaylistInput');
    var renameError = document.getElementById('renamePlaylistError');
    var btnConfirmRen = document.getElementById('btnConfirmRename');
    var nameDisplay = document.getElementById('playlistNameDisplay');

    if (btnRename && renameModal) {
      btnRename.addEventListener('click', function() {
        bootstrap.Modal.getOrCreateInstance(renameModal).show();
        setTimeout(function() {
          if (renameInput) {
            renameInput.select();
          }
        }, 300);
      });
    }

    if (renameInput) {
      renameInput.addEventListener('input', function() {
        renameInput.classList.remove('is-invalid');
      });
    }

    if (btnConfirmRen) {
      btnConfirmRen.addEventListener('click', function() {
        var newName = renameInput ? renameInput.value.trim() : '';
        if (!newName) {
          renameInput.classList.add('is-invalid');
          renameError.textContent = 'Inserisci un nome.';
          return;
        }

        btnConfirmRen.disabled = true;
        btnConfirmRen.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>…';

        fetch(BASE + '/index.php?route=playlists/rename', {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'id=' + PLAYLIST_ID + '&name=' + encodeURIComponent(newName)
          })
          .then(function(r) {
            return r.json();
          })
          .then(function(d) {
            if (d.success) {
              if (nameDisplay) nameDisplay.firstChild.textContent = newName;
              document.title = newName + ' — Playlist · Music Archive';
              var modal = bootstrap.Modal.getInstance(renameModal);
              if (modal) modal.hide();
            } else {
              renameInput.classList.add('is-invalid');
              renameError.textContent = d.error || 'Errore nel salvataggio.';
            }
            btnConfirmRen.disabled = false;
            btnConfirmRen.textContent = 'Rinomina';
          })
          .catch(function() {
            btnConfirmRen.disabled = false;
            btnConfirmRen.textContent = 'Rinomina';
          });
      });
    }

    /* ----------------------------------------------------------
       Selezione multipla tracce e eliminazione in blocco
    ---------------------------------------------------------- */
    var btnToggle = document.getElementById('btnToggleSelect');
    var bulkToolbar = document.getElementById('bulkSelectToolbar');
    var btnBulkDel = document.getElementById('btnBulkDelete');
    var btnSelAll = document.getElementById('btnSelectAll');
    var btnDeselAll = document.getElementById('btnDeselectAll');
    var bulkLabel = document.getElementById('bulkCountLabel');
    var isSelectMode = false;

    function getCheckboxes() {
      return document.querySelectorAll('.track-select-cb');
    }

    function getChecked() {
      return document.querySelectorAll('.track-select-cb:checked');
    }

    function updateBulkUI() {
      var n = getChecked().length;
      if (bulkLabel) bulkLabel.textContent = n + ' ' + (n === 1 ? 'selezionata' : 'selezionate');
      if (btnBulkDel) btnBulkDel.disabled = (n === 0);
    }

    function enterSelectMode() {
      isSelectMode = true;
      getCheckboxes().forEach(function(cb) {
        cb.style.display = '';
      });
      // Nasconde drag handle in modalità selezione
      document.querySelectorAll('.drag-handle').forEach(function(el) {
        el.style.visibility = 'hidden';
      });
      if (bulkToolbar) bulkToolbar.style.display = 'flex';
      if (btnToggle) {
        btnToggle.innerHTML = '<i class="bi bi-x-lg me-1"></i>Annulla';
        btnToggle.classList.replace('btn-outline-secondary', 'btn-outline-warning');
      }
      updateBulkUI();
    }

    function exitSelectMode() {
      isSelectMode = false;
      getCheckboxes().forEach(function(cb) {
        cb.checked = false;
        cb.style.display = 'none';
      });
      document.querySelectorAll('.drag-handle').forEach(function(el) {
        el.style.visibility = '';
      });
      if (bulkToolbar) bulkToolbar.style.display = 'none';
      if (btnToggle) {
        btnToggle.innerHTML = '<i class="bi bi-check2-square me-1"></i>Seleziona';
        btnToggle.classList.replace('btn-outline-warning', 'btn-outline-secondary');
      }
    }

    if (btnToggle) {
      btnToggle.addEventListener('click', function() {
        if (isSelectMode) {
          exitSelectMode();
        } else {
          enterSelectMode();
        }
      });
    }

    if (btnSelAll) {
      btnSelAll.addEventListener('click', function() {
        getCheckboxes().forEach(function(cb) {
          cb.checked = true;
        });
        updateBulkUI();
      });
    }
    if (btnDeselAll) {
      btnDeselAll.addEventListener('click', function() {
        getCheckboxes().forEach(function(cb) {
          cb.checked = false;
        });
        updateBulkUI();
      });
    }

    // Aggiorna contatore al cambio di ogni checkbox
    document.addEventListener('change', function(e) {
      if (e.target.classList.contains('track-select-cb')) updateBulkUI();
    });

    // Elimina in blocco
    if (btnBulkDel) {
      btnBulkDel.addEventListener('click', function() {
        var checked = getChecked();
        if (checked.length === 0) return;

        var trackIds = [];
        checked.forEach(function(cb) {
          trackIds.push(cb.dataset.trackId);
        });

        btnBulkDel.disabled = true;
        btnBulkDel.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Eliminazione…';

        var body = 'playlist_id=' + encodeURIComponent(PLAYLIST_ID);
        trackIds.forEach(function(id) {
          body += '&track_ids[]=' + encodeURIComponent(id);
        });

        fetch(BASE + '/index.php?route=playlists/remove-tracks-bulk', {
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
              // Rimuove le righe dal DOM con animazione
              checked.forEach(function(cb) {
                var row = cb.closest('li');
                if (row) {
                  row.style.transition = 'opacity .2s';
                  row.style.opacity = '0';

                  setTimeout(function() {
                    row.remove();
                    updatePositionLabels();

                    if (typeof Player !== 'undefined' &&
                      typeof Player.refreshUI === 'function') {
                      Player.refreshUI();
                    }
                  }, 220);
                }
              });

              exitSelectMode();

              // Aggiorna queue Player: rimuove le tracce senza interrompere riproduzione
              if (typeof PlaylistPlayer !== 'undefined' &&
                typeof PlaylistPlayer.removeFromQueue === 'function') {
                trackIds.forEach(function(id) {
                  PlaylistPlayer.removeFromQueue(parseInt(id, 10));
                });
              }

              // Riallinea subito la UI dopo la modifica della queue.
              if (typeof Player !== 'undefined' &&
                typeof Player.refreshUI === 'function') {
                Player.refreshUI();
              }
            } else {
              btnBulkDel.disabled = false;
              btnBulkDel.innerHTML = '<i class="bi bi-trash me-1"></i>Elimina selezionate';
            }
          })
          .catch(function() {
            btnBulkDel.disabled = false;
            btnBulkDel.innerHTML = '<i class="bi bi-trash me-1"></i>Elimina selezionate';
          });
      });
    }

  })();
</script>

<?php require BASE_PATH . '/views/layout/footer.php'; ?>
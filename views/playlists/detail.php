<?php

/** @var array $playlist */
/** @var array $tracks */

$pageTitle = htmlspecialchars($playlist['name']) . ' — Playlist';
require BASE_PATH . '/views/layout/header.php';
require_once BASE_PATH . '/views/playlists/_mosaic.php';

// Conta tracce riproducibili
$playableTracks = array_filter($tracks, function ($t) {
  return !empty($t['audio_filename']);
});
$playableCount  = count($playableTracks);
$totalCount     = count($tracks);

// YouTube share: raccoglie i video_id disponibili
// (lo slicing a 50 per watch_videos avviene lato JS in ytWatchUrl)
$ytIds = array_filter(array_column($tracks, 'youtube_id'));
$ytIds = array_values(array_unique($ytIds));

// Tracce ancora senza video YouTube associato (candidate al resolver batch)
$missingYt = 0;
foreach ($tracks as $t) {
  if (empty($t['youtube_id'])) {
    $missingYt++;
  }
}

// Priorità copertina: locale > URL remota > placeholder
$trackCover = function (array $t): string {
  if (!empty($t['cover_local'])) {
    return BASE_URL . '/public/uploads/' . $t['cover_local'];
  }
  if (!empty($t['cover_url'])) {
    return strpos($t['cover_url'], 'http') === 0
      ? $t['cover_url']
      : BASE_URL . '/public/uploads/' . $t['cover_url'];
  }
  return BASE_URL . '/public/img/placeholder.png';
};

// Mosaico: primi 4 album distinti nell'ordine della playlist
$mosaicCovers = [];
$seenAlbums   = [];
foreach ($tracks as $t) {
  $aid = (int)$t['album_id'];
  if (isset($seenAlbums[$aid]) || (empty($t['cover_local']) && empty($t['cover_url']))) {
    continue;
  }
  $seenAlbums[$aid] = true;
  $mosaicCovers[]   = $trackCover($t);
  if (count($mosaicCovers) === 4) {
    break;
  }
}

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

<div class="grz-pl-page">

  <button type="button" class="grz-pl-back" onclick="grzBack('<?= BASE_URL ?>/index.php?route=playlists')">
    <i class="bi bi-arrow-left" aria-hidden="true"></i>Indietro
  </button>

  <!-- Intestazione playlist -->
  <header class="grz-plhero">
    <?= grzPlaylistMosaic($mosaicCovers, 'grz-plhero__art') ?>

    <div class="grz-plhero__body">
      <span class="grz-plhero__eyebrow">Playlist</span>

      <div class="grz-plhero__titlerow">
        <h1 class="grz-plhero__title" id="playlistNameDisplay"><?= htmlspecialchars($playlist['name']) ?></h1>
        <button class="grz-plhero__rename"
          id="btnRenamePlaylist"
          title="Rinomina playlist"
          aria-label="Rinomina playlist">
          <i class="bi bi-pencil" aria-hidden="true"></i>
        </button>
      </div>

      <!-- Gli span con id sono aggiornati dallo script dopo rimozioni e riordini -->
      <div class="grz-plhero__stats" id="playlistStats">
        <span><span id="plStatTotal"><?= $totalCount ?></span><span id="plStatTotalLabel"> <?= $totalCount === 1 ? 'traccia' : 'tracce' ?></span></span>
        <?php if ($totalCount > 0): ?>
          <?php if ($playableCount < $totalCount): ?>
            &mdash; <span id="plStatAudio" class="text-warning"><?= $playableCount ?> con audio</span>
          <?php else: ?>
            &mdash; <span id="plStatAudio" class="text-success">tutte con audio</span>
          <?php endif; ?>
        <?php else: ?>
          <span id="plStatAudio"></span>
        <?php endif; ?>
        <?php if ($durStr): ?>
          <span id="plStatDur" class="ms-1">&mdash; <?= $durStr ?></span>
        <?php else: ?>
          <span id="plStatDur" class="ms-1" style="display:none"></span>
        <?php endif; ?>
      </div>

      <!-- Azioni principali -->
      <div class="grz-plhero__actions">

        <?php if ($playableCount > 0): ?>
          <button class="btn btn-success grz-plhero__play" id="btnPlayAll">
            <i class="bi bi-play-fill me-1"></i>Riproduci
          </button>
        <?php else: ?>
          <button class="btn btn-outline-secondary grz-plhero__play" disabled title="Nessun file audio">
            <i class="bi bi-play-fill me-1"></i>Riproduci
          </button>
        <?php endif; ?>

        <!-- YouTube: bottone unico "risolvi e apri".
             - Se tutte le tracce hanno già un video → apre subito watch_videos.
             - Se mancano associazioni → le risolve in batch (progresso sul
               bottone) e poi reindirizza la scheda già aperta al click.
             La scheda viene aperta SUBITO al click (sincrono) per non essere
             bloccata dai popup blocker; l'URL viene impostato a fine lavoro. -->
        <?php if ($totalCount > 0): ?>
          <button class="btn btn-outline-danger grz-plhero__yt" id="btnYoutube"
            data-missing="<?= $missingYt ?>"
            title="<?= $missingYt > 0
              ? 'Cerca i video delle ' . $missingYt . ' tracce mancanti e apri la playlist su YouTube'
              : 'Apri ' . count($ytIds) . ' ' . (count($ytIds) === 1 ? 'traccia' : 'tracce') . ' su YouTube' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="grz-plhero__yt-logo me-1" aria-hidden="true">
              <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" />
            </svg>
            <span id="btnYoutubeLabel">YouTube</span>
            <span class="badge ms-1" id="ytCountBadge"><?= count($ytIds) ?></span>
          </button>
        <?php endif; ?>

        <button class="btn btn-outline-secondary" id="btnToggleSelect"
          title="Seleziona tracce per eliminazione multipla">
          <i class="bi bi-check2-square me-1"></i>Seleziona
        </button>

      </div>
    </div>
  </header>


  <?php if (empty($tracks)): ?>

    <div class="grz-pl-empty">
      <h2>Questa playlist è vuota</h2>
      <p>Apri un disco dall'archivio e usa <strong>Aggiungi a playlist</strong> su una traccia o sull'album intero.</p>
      <a href="<?= BASE_URL ?>/index.php?route=albums/list" class="btn btn-warning">
        <i class="bi bi-collection me-1" aria-hidden="true"></i>Vai all'archivio
      </a>
    </div>

  <?php else: ?>

    <section class="grz-pltracks" aria-label="Tracce della playlist">
      <div class="grz-pltracks__head">
        <span>Tracce</span>
        <span class="grz-pltracks__hint">Trascina <i class="bi bi-grip-vertical" aria-hidden="true"></i> per riordinare</span>
      </div>

      <ul class="grz-pltracks__list" id="playlistTrackList" data-player-context="playlist:<?= (int)$playlist['id'] ?>">
        <?php foreach ($tracks as $idx => $t):
          $hasAudio = !empty($t['audio_filename']);
          $duration = '';
          if ($t['duration_sec']) {
            $duration = floor($t['duration_sec'] / 60) . ':' . str_pad($t['duration_sec'] % 60, 2, '0', STR_PAD_LEFT);
          }
          $coverSrc = $trackCover($t);
        ?>
          <li class="track-item grz-pltrack<?= $hasAudio ? '' : ' is-noaudio' ?>"
            data-track-id="<?= (int)$t['track_id'] ?>"
            data-position="<?= (int)$t['position'] ?>"
            data-has-audio="<?= $hasAudio ? '1' : '0' ?>"
            data-duration-sec="<?= (int)($t['duration_sec'] ?? 0) ?>">

            <!-- Checkbox selezione multipla — visibile solo in modalità selezione -->
            <input type="checkbox"
              class="form-check-input track-select-cb flex-shrink-0"
              data-track-id="<?= (int)$t['track_id'] ?>"
              aria-label="Seleziona <?= htmlspecialchars($t['title'], ENT_QUOTES) ?>"
              style="display:none;width:1.1rem;height:1.1rem;cursor:pointer;margin:0">

            <!-- Handle + posizione. Il numero diventa icona eq in
                 riproduzione (slot .pl-track-playing-icon), come nella
                 vista album. .drag-handle resta elemento autonomo perché
                 Sortable, i listener touch e la modalità selezione lo
                 referenziano direttamente. -->
            <span class="pl-pos-cell">
              <span class="drag-handle" title="Trascina per riordinare">
                <i class="bi bi-grip-vertical" aria-hidden="true"></i>
              </span>
              <span class="pl-track-pos-wrap">
                <span class="pl-track-playing-icon" style="display:none"></span>
                <span class="pl-track-num"><?= (int)$t['position'] ?></span>
              </span>
            </span>

            <!-- Cover album — porta alla scheda del disco -->
            <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= (int)$t['album_id'] ?>"
              class="grz-pltrack__cover"
              title="Vai all'album: <?= htmlspecialchars($t['album_title'], ENT_QUOTES) ?>">
              <img src="<?= htmlspecialchars($coverSrc) ?>"
                alt="<?= htmlspecialchars($t['album_title'], ENT_QUOTES) ?>"
                width="44" height="44" loading="lazy">
            </a>

            <!-- Titolo + artista e album. Il titolo è il primo .fw-semibold
                 della riga: lo script lo legge per la conferma di rimozione. -->
            <div class="grz-pltrack__text">
              <?php if ($hasAudio): ?>
                <button type="button" class="fw-semibold grz-pltrack__title grz-pltrack__titlebtn" title="Riproduci <?= htmlspecialchars($t['title'], ENT_QUOTES) ?>"><?= htmlspecialchars($t['title']) ?></button>
              <?php else: ?>
                <div class="fw-semibold grz-pltrack__title"><?= htmlspecialchars($t['title']) ?></div>
              <?php endif; ?>
              <div class="grz-pltrack__sub">
                <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= (int)$t['artist_id'] ?>"><?= htmlspecialchars($t['artist_name']) ?></a>
                <span aria-hidden="true">·</span>
                <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= (int)$t['album_id'] ?>"><?= htmlspecialchars($t['album_title']) ?></a>
              </div>
            </div>

            <!-- Stato audio -->
            <?php if ($hasAudio): ?>
              <span class="grz-pltrack__audio" title="File audio disponibile">
                <i class="bi bi-music-note-beamed" aria-hidden="true"></i><span class="visually-hidden">Audio disponibile</span>
              </span>
            <?php else: ?>
              <span class="grz-pltrack__audio is-missing" title="Nessun file audio: traccia saltata in riproduzione">
                <span class="grz-pltrack__noaudio">senza audio</span>
              </span>
            <?php endif; ?>

            <!-- Durata -->
            <span class="grz-pltrack__dur"><?= $duration ?></span>

            <!-- Play singola traccia (solo se ha audio) -->
            <?php if ($hasAudio): ?>
              <button class="grz-pltrack__btn btn-play-track btn-track-play"
                data-playlist-id="<?= (int)$playlist['id'] ?>"
                data-index="<?= $idx ?>"
                data-track-id="<?= (int)$t['track_id'] ?>"
                title="Riproduci da qui"
                aria-label="Riproduci da qui">
                <i class="bi bi-play-fill"></i>
              </button>
            <?php else: ?>
              <button class="grz-pltrack__btn" disabled title="Audio non disponibile" aria-label="Audio non disponibile">
                <i class="bi bi-play-fill" aria-hidden="true"></i>
              </button>
            <?php endif; ?>

            <!-- Rimuovi dalla playlist -->
            <button class="grz-pltrack__btn grz-pltrack__btn--remove btn-remove-track"
              data-playlist-id="<?= (int)$playlist['id'] ?>"
              data-track-id="<?= (int)$t['track_id'] ?>"
              title="Rimuovi dalla playlist"
              aria-label="Rimuovi dalla playlist">
              <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>

          </li>
        <?php endforeach; ?>
      </ul>
    </section>

  <?php endif; ?>

</div>


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
  // Navigazione "indietro" consapevole del percorso (stessa logica di
  // profile.php, results.php e form.php): torna alla pagina reale di
  // provenienza se l'utente è arrivato navigando nell'app, altrimenti
  // ripiega sull'elenco delle playlist.
  // Titolo traccia cliccabile: equivale al pulsante play della riga,
  // quindi avvia, mette in pausa o riprende con la stessa logica.
  // In modalità selezione il clic spunta la casella della riga.
  (function() {
    var list = document.getElementById('playlistTrackList');
    if (!list) return;
    list.addEventListener('click', function(e) {
      var titleBtn = e.target.closest('.grz-pltrack__titlebtn');
      if (!titleBtn) return;
      var row = titleBtn.closest('li.track-item');
      if (!row) return;
      var cb = row.querySelector('.track-select-cb');
      if (cb && cb.style.display !== 'none') {
        cb.checked = !cb.checked;
        cb.dispatchEvent(new Event('change', { bubbles: true }));
        return;
      }
      var playBtn = row.querySelector('.btn-play-track');
      if (playBtn) playBtn.click();
    });
  })();

  window.grzBack = function(fallbackUrl) {
    if (window.history.state && window.history.state.url) {
      window.history.back();
    } else if (typeof window._spaNavigate === 'function') {
      window._spaNavigate(fallbackUrl);
    } else {
      window.location.href = fallbackUrl;
    }
  };

  (function() {

    var PLAYLIST_ID = <?= (int)$playlist['id'] ?>;
    var PLAYLIST_NAME = <?= json_encode($playlist['name']) ?>;
    var BASE = '<?= BASE_URL ?>';
    var CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
    // youtube_id già risolti, in ordine di posizione (per il bottone YouTube)
    var YT_IDS = <?= json_encode(array_values($ytIds)) ?>;

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

        // Contesto caricato nel player: questa playlist?
        // Player.context() è la fonte di verità; activeId() resta come
        // ripiego per versioni del player senza context().
        var isThisPlaylist = (typeof Player !== 'undefined' && typeof Player.context === 'function')
          ? Player.context() === 'playlist:' + PLAYLIST_ID
          : (typeof PlaylistPlayer !== 'undefined' &&
             typeof PlaylistPlayer.activeId === 'function' &&
             PlaylistPlayer.activeId() === PLAYLIST_ID);

        // Caso 1: traccia già attiva IN QUESTA playlist → toggle.
        // Se lo stesso brano suona da un'altra playlist o dal suo album,
        // si passa ai casi successivi e parte questa playlist da qui.
        var isCurrentTrack = (
          isThisPlaylist &&
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

    /* ---------- Bottone YouTube unico: risolvi e apri ----------
       Al click:
         1. apre SUBITO una scheda vuota (sincrono → niente popup blocker);
         2. se tutte le tracce sono già risolte, la reindirizza subito;
         3. altrimenti chiama api/youtube-resolve-playlist.php a chunk
            mostrando il progresso sul bottone, poi reindirizza la scheda
            alla playlist watch_videos completa (max 50 id).
       Se la quota si esaurisce a metà, apre comunque con le tracce
       risolte fino a quel momento. Usa onclick (come btnPlayAll) per
       evitare accumulo di listener tra le navigazioni SPA.
    ------------------------------------------------------------ */
    var btnYoutube = document.getElementById('btnYoutube');

    function ytWatchUrl(ids) {
      // watch_videos accetta max 50 id
      return 'https://www.youtube.com/watch_videos?video_ids=' +
        ids.slice(0, 50).join(',');
    }

    function ytSetLabel(text, spinning) {
      var label = document.getElementById('btnYoutubeLabel');
      if (label) {
        label.innerHTML = (spinning
          ? '<span class="spinner-border spinner-border-sm me-1"></span>'
          : '') + text;
      }
    }

    function ytUpdateBadge(count) {
      var badge = document.getElementById('ytCountBadge');
      if (badge) badge.textContent = count;
    }

    /* Scrive nella scheda di attesa una pagina animata in tema con l'app:
       logo che pulsa, spinner, nome playlist e progresso aggiornato live.
       La scheda è about:blank scritta da noi → same-origin, quindi
       possiamo aggiornarne il DOM finché non la reindirizziamo a YouTube. */
    function ytWriteWaitingPage(tab) {
      if (!tab || !tab.document) return;
      try {
        var d = tab.document;
        d.open();
        d.write(
          '<!DOCTYPE html><html><head><meta charset="utf-8">' +
          '<title>' + PLAYLIST_NAME.replace(/</g, '&lt;') + ' \u2014 YouTube</title>' +
          '<style>' +
          'html,body{height:100%;margin:0}' +
          'body{display:flex;align-items:center;justify-content:center;' +
          '  background:#121212;color:#e9e9e9;' +
          '  font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}' +
          '.box{text-align:center;padding:2rem}' +
          '.logo{width:84px;height:84px;border-radius:18px;' +
          '  animation:pulse 1.6s ease-in-out infinite}' +
          '@keyframes pulse{0%,100%{transform:scale(1);opacity:1}' +
          '  50%{transform:scale(1.07);opacity:.75}}' +
          '.name{margin-top:1.1rem;font-size:1.15rem;font-weight:600}' +
          '.sub{margin-top:.35rem;font-size:.85rem;color:#9a9a9a}' +
          '.ring{margin:1.4rem auto 0;width:34px;height:34px;border-radius:50%;' +
          '  border:3px solid #333;border-top-color:#e53935;' +
          '  animation:spin .9s linear infinite}' +
          '@keyframes spin{to{transform:rotate(360deg)}}' +
          '.prog{margin-top:.9rem;font-size:.8rem;color:#bdbdbd;' +
          '  font-variant-numeric:tabular-nums}' +
          '.yt{color:#e53935;font-weight:600}' +
          '</style></head><body><div class="box">' +
          '<img class="logo" src="' + BASE + '/public/img/logo-grizzly.png" alt="">' +
          '<div class="name">' + PLAYLIST_NAME.replace(/</g, '&lt;') + '</div>' +
          '<div class="sub">Preparazione playlist <span class="yt">YouTube</span>\u2026</div>' +
          '<div class="ring"></div>' +
          '<div class="prog" id="ytProg">Avvio ricerca\u2026</div>' +
          '</div></body></html>'
        );
        d.close();
      } catch (e) { /* solo cosmesi: mai bloccare il flusso */ }
    }

    /* Aggiorna la riga di progresso nella scheda di attesa (se ancora nostra) */
    function ytTabProgress(tab, text) {
      try {
        var el = tab && tab.document ? tab.document.getElementById('ytProg') : null;
        if (el) el.textContent = text;
      } catch (e) { /* la scheda potrebbe essere stata chiusa dall'utente */ }
    }

    if (btnYoutube) {
      btnYoutube.onclick = function() {
        /* Mutua esclusione: qui YouTube parte in un'ALTRA SCHEDA del
           browser, fuori dal controllo dell'app (nessun evento arriva
           al nostro codice). L'audio nativo va quindi messo in pausa
           ADESSO, dentro il gesto di click — dopo sarebbe impossibile.
           I percorsi lightbox interni hanno già la loro regola in
           youtube-player.js/app.js: questo era l'unico buco. */
        var nativeAudio = document.getElementById('global-audio');
        if (nativeAudio && !nativeAudio.paused && nativeAudio.src) {
          nativeAudio.pause();
        }

        var missing = parseInt(btnYoutube.dataset.missing, 10) || 0;

        // Scheda aperta subito, dentro il gesto utente
        var ytTab = window.open('', '_blank');
        ytWriteWaitingPage(ytTab);

        function finish(ids) {
          ytUpdateBadge(ids.length);
          if (ids.length === 0) {
            if (ytTab) ytTab.close();
            ytSetLabel('Nessun video', false);
            btnYoutube.disabled = false;
            return;
          }
          if (ytTab) {
            ytTab.location.href = ytWatchUrl(ids);
          } else {
            // Popup bloccato nonostante tutto: fallback nella scheda corrente
            window.location.href = ytWatchUrl(ids);
          }
          ytSetLabel('YouTube', false);
          btnYoutube.disabled = false;
        }

        // Tutto già risolto → apri subito
        if (missing <= 0) {
          finish(YT_IDS);
          return;
        }

        // Risoluzione batch con progresso
        btnYoutube.disabled = true;
        var totalMissing = missing;
        var done = 0;

        function step() {
          fetch(BASE + '/api/youtube-resolve-playlist.php', {
              method: 'POST',
              headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
              },
              body: 'playlist_id=' + encodeURIComponent(PLAYLIST_ID) +
                    '&limit=8' +
                    '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
            })
            .then(function(r) { return r.json(); })
            .then(function(d) {
              if (!d.success) {
                // Errore server: apri comunque con quello che abbiamo
                finish(YT_IDS);
                return;
              }

              done += (d.resolved || 0) + (d.failed || 0);
              YT_IDS = d.youtube_ids || YT_IDS;
              ytUpdateBadge(YT_IDS.length);
              ytTabProgress(ytTab,
                'Tracce analizzate: ' + Math.min(done, totalMissing) + '/' + totalMissing +
                ' \u2014 video trovati: ' + YT_IDS.length);

              if (d.quota_exceeded) {
                // Quota finita: le mancanti restano per un'altra volta
                btnYoutube.dataset.missing = d.remaining;
                finish(YT_IDS);
                return;
              }

              if (d.remaining > 0) {
                ytSetLabel('Risolvo\u2026 ' + Math.min(done, totalMissing) + '/' + totalMissing, true);
                step(); // prossimo chunk
                return;
              }

              // Finito: eventuali tracce senza video sono marcate not_found
              btnYoutube.dataset.missing = 0;
              finish(YT_IDS);
            })
            .catch(function() {
              // Errore di rete: apri con quello che abbiamo (o chiudi se nulla)
              finish(YT_IDS);
            });
        }

        ytSetLabel('Risolvo\u2026 0/' + totalMissing, true);
        ytTabProgress(ytTab, 'Tracce da cercare: ' + totalMissing);
        step();
      };
    }

  })();
</script>

<?php require BASE_PATH . '/views/layout/footer.php'; ?>
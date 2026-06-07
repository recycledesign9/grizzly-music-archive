<?php
$pageTitle = 'Playlist';
require BASE_PATH . '/views/layout/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="mb-0 fw-bold">
      <i class="bi bi-collection-play me-2 text-warning"></i>Playlist
    </h4>
    <p class="text-muted small mb-0 mt-1">
      <?= count($playlists) ?> <?= count($playlists) === 1 ? 'playlist' : 'playlist' ?> nel tuo archivio
    </p>
  </div>
  <button class="btn btn-warning btn-sm px-3"
    data-bs-toggle="modal" data-bs-target="#createPlaylistModal">
    <i class="bi bi-plus-lg me-1"></i>Nuova playlist
  </button>
</div>

<?php if (empty($playlists)): ?>
  <div class="text-center py-5">
    <div class="playlist-empty-icon mx-auto mb-4">
      <i class="bi bi-collection-play"></i>
    </div>
    <h5 class="fw-semibold mb-2">Nessuna playlist ancora</h5>
    <p class="text-muted small mb-4">Crea la prima playlist e inizia a organizzare la tua musica.</p>
    <button class="btn btn-warning px-4"
      data-bs-toggle="modal" data-bs-target="#createPlaylistModal">
      <i class="bi bi-plus-lg me-1"></i>Crea la prima playlist
    </button>
  </div>
<?php else: ?>

  <div class="pl-list">

    <!-- Intestazione colonne -->
    <div class="pl-list-header">
      <span class="pl-col-name">Nome</span>
      <span class="pl-col-tracks d-none d-md-block">Tracce</span>
      <span class="pl-col-audio d-none d-lg-block">Audio</span>
      <span class="pl-col-date d-none d-lg-block">Creata</span>
      <span class="pl-col-actions">Azioni</span>
    </div>

    <?php foreach ($playlists as $pl):
      $total    = (int)$pl['total_tracks'];
      $playable = (int)$pl['playable_tracks'];
      $pct      = $total > 0 ? round(($playable / $total) * 100) : 0;
      $isEmpty  = $total === 0;
      $isFull   = $total > 0 && $playable === $total;
      $isPartial = $total > 0 && $playable < $total;
    ?>
      <div class="pl-list-row<?= $isFull ? ' is-complete' : '' ?>" data-playlist-id="<?= (int)$pl['id'] ?>">

        <!-- Icona stato + Nome -->
        <div class="pl-col-name">
          <span class="pl-row-icon <?= $isFull ? 'icon-full' : ($isPartial ? 'icon-partial' : 'icon-empty') ?>"
            data-playlist-id="<?= (int)$pl['id'] ?>">
            <i class="bi bi-collection-play-fill"></i>
          </span>
          <div class="pl-row-meta">
            <a href="<?= BASE_URL ?>/index.php?route=playlists/detail/<?= $pl['id'] ?>"
              class="pl-row-name">
              <?= htmlspecialchars($pl['name']) ?>
            </a>
            <!-- Info compatta su mobile -->
            <span class="pl-row-sub d-md-none">
              <?php if ($isEmpty): ?>
                <span class="text-muted">Vuota</span>
              <?php elseif ($isFull): ?>
                <span class="text-success"><?= $total ?> tracce</span>
              <?php else: ?>
                <span class="text-warning"><?= $playable ?>/<?= $total ?> con audio</span>
              <?php endif; ?>
            </span>
          </div>
        </div>

        <!-- Tracce totali -->
        <div class="pl-col-tracks d-none d-md-flex">
          <?php if ($isEmpty): ?>
            <span class="text-muted small">—</span>
          <?php else: ?>
            <span class="pl-badge-tracks"><?= $total ?></span>
          <?php endif; ?>
        </div>

        <!-- Audio disponibile + barra -->
        <div class="pl-col-audio d-none d-lg-flex">
          <?php if ($isEmpty): ?>
            <span class="text-muted small">—</span>
          <?php elseif ($isFull): ?>
            <div class="pl-audio-wrap">
              <div class="pl-audio-bar">
                <div class="pl-audio-fill fill-full" style="width:100%"></div>
              </div>
              <span class="pl-audio-label text-success">Tutte</span>
            </div>
          <?php else: ?>
            <div class="pl-audio-wrap">
              <div class="pl-audio-bar">
                <div class="pl-audio-fill fill-partial" style="width:<?= $pct ?>%"></div>
              </div>
              <span class="pl-audio-label text-warning"><?= $pct ?>%</span>
            </div>
          <?php endif; ?>
        </div>

        <!-- Data creazione -->
        <div class="pl-col-date d-none d-lg-flex">
          <span class="text-muted small"><?= date('d/m/Y', strtotime($pl['created_at'])) ?></span>
        </div>

        <!-- Azioni -->
        <div class="pl-col-actions">
          <?php if ($playable > 0): ?>
            <button class="pl-btn-play"
              data-playlist-id="<?= (int)$pl['id'] ?>"
              onclick="PlaylistPlayer.load(<?= $pl['id'] ?>)"
              title="Riproduci">
              <i class="bi bi-play-fill"></i>
            </button>
          <?php else: ?>
            <button class="pl-btn-play" disabled title="Nessun audio disponibile">
              <i class="bi bi-play-fill"></i>
            </button>
          <?php endif; ?>

          <a href="<?= BASE_URL ?>/index.php?route=playlists/detail/<?= $pl['id'] ?>"
            class="pl-btn-open" title="Apri playlist">
            <i class="bi bi-arrow-right"></i>
          </a>

          <button class="pl-btn-delete btn-delete-playlist"
            data-id="<?= $pl['id'] ?>"
            data-name="<?= htmlspecialchars($pl['name'], ENT_QUOTES) ?>"
            title="Elimina playlist">
            <i class="bi bi-trash"></i>
          </button>
        </div>

      </div>
    <?php endforeach; ?>

  </div>

<?php endif; ?>


<!-- ===== Modal: Crea nuova playlist ===== -->
<div class="modal fade" id="createPlaylistModal" tabindex="-1" aria-labelledby="createPlaylistLabel">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="createPlaylistLabel">
          <i class="bi bi-collection-play me-2"></i>Nuova playlist
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label small fw-semibold" for="newPlaylistNameInput">Nome</label>
        <input type="text"
          class="form-control"
          id="newPlaylistNameInput"
          placeholder="Es. Serate in vinile…"
          maxlength="150"
          autocomplete="off">
        <div class="invalid-feedback" id="newPlaylistNameError"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
        <button class="btn btn-warning btn-sm" id="btnCreatePlaylist">
          <i class="bi bi-plus-lg me-1"></i>Crea
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ===== Modal: Conferma eliminazione ===== -->
<div class="modal fade" id="deletePlaylistModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-danger">
          <i class="bi bi-exclamation-triangle me-2"></i>Elimina playlist
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body small">
        Eliminare la playlist <strong id="deletePlaylistName"></strong>?
        <br>Le tracce nell'archivio non saranno toccate.
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
        <button class="btn btn-sm btn-danger" id="btnConfirmDeletePlaylist">Elimina</button>
      </div>
    </div>
  </div>
</div>


<script>
  (function() {

    /* ---------- Crea playlist ---------- */
    var btnCreate = document.getElementById('btnCreatePlaylist');
    var nameInput = document.getElementById('newPlaylistNameInput');
    var nameError = document.getElementById('newPlaylistNameError');
    var createModal = document.getElementById('createPlaylistModal');

    if (nameInput) {
      nameInput.addEventListener('input', function() {
        nameInput.classList.remove('is-invalid');
      });
    }

    if (btnCreate) {
      btnCreate.addEventListener('click', function() {
        var name = nameInput ? nameInput.value.trim() : '';
        if (!name) {
          nameInput.classList.add('is-invalid');
          nameError.textContent = 'Inserisci un nome per la playlist.';
          nameInput.focus();
          return;
        }

        btnCreate.disabled = true;
        btnCreate.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creazione…';

        fetch('<?= BASE_URL ?>/index.php?route=playlists/store', {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'name=' + encodeURIComponent(name)
          })
          .then(function(r) {
            return r.json();
          })
          .then(function(d) {
            if (d.success) {
              var modal = bootstrap.Modal.getInstance(createModal);
              if (modal) {
                createModal.addEventListener('hidden.bs.modal', function() {
                  if (window._spaNavigate) {
                    window._spaNavigate('<?= BASE_URL ?>/index.php?route=playlists/detail/' + d.id);
                  } else {
                    location.href = '<?= BASE_URL ?>/index.php?route=playlists/detail/' + d.id;
                  }
                }, {
                  once: true
                });
                modal.hide();
              }
            } else {
              nameInput.classList.add('is-invalid');
              nameError.textContent = d.error || 'Errore nella creazione.';
              btnCreate.disabled = false;
              btnCreate.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Crea';
            }
          })
          .catch(function() {
            btnCreate.disabled = false;
            btnCreate.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Crea';
          });
      });
    }

    if (createModal) {
      createModal.addEventListener('hidden.bs.modal', function() {
        if (nameInput) {
          nameInput.value = '';
          nameInput.classList.remove('is-invalid');
        }
        if (btnCreate) {
          btnCreate.disabled = false;
          btnCreate.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Crea';
        }
      });
    }

    /* ---------- Elimina playlist ---------- */
    var deleteModal = document.getElementById('deletePlaylistModal');
    var deleteNameEl = document.getElementById('deletePlaylistName');
    var btnConfirmDel = document.getElementById('btnConfirmDeletePlaylist');
    var pendingDelId = null;

    document.querySelectorAll('.btn-delete-playlist').forEach(function(btn) {
      btn.addEventListener('click', function() {
        pendingDelId = this.dataset.id;
        if (deleteNameEl) deleteNameEl.textContent = '"' + this.dataset.name + '"';
        var m = new bootstrap.Modal(deleteModal);
        m.show();
      });
    });

    if (btnConfirmDel) {
      btnConfirmDel.addEventListener('click', function() {
        if (!pendingDelId) return;
        btnConfirmDel.disabled = true;
        btnConfirmDel.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>…';

        fetch('<?= BASE_URL ?>/index.php?route=playlists/delete', {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'id=' + encodeURIComponent(pendingDelId)
          })
          .then(function(r) {
            return r.json();
          })
          .then(function(d) {
            if (d.success) {
              var modal = bootstrap.Modal.getInstance(deleteModal);
              if (modal) {
                deleteModal.addEventListener('hidden.bs.modal', function() {
                  if (window._spaNavigate) {
                    window._spaNavigate('<?= BASE_URL ?>/index.php?route=playlists');
                  } else {
                    location.reload();
                  }
                }, {
                  once: true
                });
                modal.hide();
              }
            }
          })
          .catch(function() {
            btnConfirmDel.disabled = false;
            btnConfirmDel.textContent = 'Elimina';
          });
      });
    }

  })();
</script>

<script>
  (function() {

    // Recupera #global-audio al momento della chiamata, non prima —
    // così funziona anche se lo script gira prima del footer.
    function getAudio() {
      return document.getElementById('global-audio');
    }

    function syncPlaylistListUI() {
      var audio = getAudio();
      var activeId = (typeof PlaylistPlayer !== 'undefined' && typeof PlaylistPlayer.activeId === 'function') ?
        parseInt(PlaylistPlayer.activeId(), 10) :
        null;

      var hasSource = !!(audio && audio.src);
      var isPlaying = !!(audio && audio.src && !audio.paused);

      document.querySelectorAll('.pl-btn-play[data-playlist-id]').forEach(function(btn) {
        var pid = parseInt(btn.dataset.playlistId, 10);

        if (pid === activeId && isPlaying) {
          // Playlist attiva IN riproduzione → mostra pausa
          btn.innerHTML = '<i class="bi bi-pause-fill"></i>';
          btn.title = 'Pausa';
          btn.classList.add('pl-btn-play--active');
          btn.onclick = function() {
            var a = getAudio();
            if (a) a.pause();
          };
        } else if (pid === activeId && !isPlaying && hasSource) {
          // Playlist attiva IN PAUSA → mostra play per riprendere
          btn.innerHTML = '<i class="bi bi-play-fill"></i>';
          btn.title = 'Riprendi';
          btn.classList.add('pl-btn-play--active');
          btn.onclick = function() {
            var a = getAudio();
            if (a && a.src) {
              a.play().catch(function(err) {
                console.warn('[Playlist list] Impossibile riprendere:', err.message);
              });
            }
          };
        } else {
          // Tutte le altre → stato idle
          btn.innerHTML = '<i class="bi bi-play-fill"></i>';
          btn.title = 'Riproduci';
          btn.classList.remove('pl-btn-play--active');
          btn.onclick = function() {
            PlaylistPlayer.load(parseInt(this.dataset.playlistId, 10));
          };
        }
      });

      // Aggiorna icone rotonde
      // Pulisce eventuali vecchie classi player dall'icona laterale.
      // L'icona laterale deve rappresentare SOLO completezza audio:
      // icon-full / icon-partial / icon-empty.
      document.querySelectorAll('.pl-row-icon[data-playlist-id]').forEach(function(icon) {
        icon.classList.remove('icon-playing', 'icon-paused');
      });

      // Aggiorna lo stato player sulla riga, NON sull'icona laterale.
      document.querySelectorAll('.pl-list-row[data-playlist-id]').forEach(function(row) {
        var pid = parseInt(row.dataset.playlistId, 10);
        var isActive = pid === activeId;

        row.classList.toggle('is-player-playing', isActive && isPlaying);
        row.classList.toggle('is-player-paused', isActive && !isPlaying && hasSource);
      });
    }

    // Aggancia gli eventi audio — chiama getAudio() al momento dell'esecuzione
    // per evitare di catturare null se lo script gira prima del footer.
    function bindAudioEvents() {
      var audio = getAudio();
      if (!audio || audio.dataset.playlistListBound === '1') return;
      audio.dataset.playlistListBound = '1';
      audio.addEventListener('play', syncPlaylistListUI);
      audio.addEventListener('pause', syncPlaylistListUI);
      audio.addEventListener('ended', syncPlaylistListUI);
    }

    // Esegui subito
    bindAudioEvents();
    syncPlaylistListUI();

    // Esponi globalmente così app.js può chiamarla da hide() e setPlaying()
    window.__syncPlaylistListUI = syncPlaylistListUI;

  })();
</script>

<?php require BASE_PATH . '/views/layout/footer.php'; ?>
<?php
$pageTitle = 'Playlist';
require BASE_PATH . '/views/layout/header.php';
require_once BASE_PATH . '/views/playlists/_mosaic.php';

/** @var array $playlists */
/** @var array $playlistCovers  copertine per playlist (id => [url, ...]) */
$playlistCovers = $playlistCovers ?? [];

$plCount     = count($playlists);
$trackCount  = 0;
foreach ($playlists as $p) {
  $trackCount += (int)$p['total_tracks'];
}
?>

<div class="grz-pl-page">

  <div class="grz-pl-head">
    <div>
      <h1 class="grz-pl-head__title">Playlist</h1>
      <p class="grz-pl-head__sub">
        <?= $plCount ?> <?= $plCount === 1 ? 'playlist' : 'playlist' ?>
        <?php if ($trackCount > 0): ?> · <?= $trackCount ?> <?= $trackCount === 1 ? 'traccia' : 'tracce' ?><?php endif; ?>
      </p>
    </div>
    <button class="btn btn-warning grz-pl-head__new"
      data-bs-toggle="modal" data-bs-target="#createPlaylistModal">
      <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nuova playlist
    </button>
  </div>

  <?php if (empty($playlists)): ?>
    <div class="grz-pl-empty">
      <?= grzPlaylistMosaic([], 'grz-pl-empty__art') ?>
      <h2>Nessuna playlist ancora</h2>
      <p>Crea la prima playlist, poi aggiungi tracce o album interi dalla scheda di un disco.</p>
      <button class="btn btn-warning"
        data-bs-toggle="modal" data-bs-target="#createPlaylistModal">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Crea la prima playlist
      </button>
    </div>
  <?php else: ?>

    <div class="grz-plgrid">
      <?php foreach ($playlists as $pl):
        $pid      = (int)$pl['id'];
        $total    = (int)$pl['total_tracks'];
        $playable = (int)$pl['playable_tracks'];
        $pct      = $total > 0 ? (int)round(($playable / $total) * 100) : 0;
        $isEmpty  = $total === 0;
        $isFull   = $total > 0 && $playable === $total;
        $durLabel = grzPlaylistDuration((int)($pl['playable_sec'] ?? 0));
        $url      = BASE_URL . '/index.php?route=playlists/detail/' . $pid;
      ?>
        <!-- .pl-list-row / .pl-btn-play / .pl-row-name restano come
             agganci dello script di sincronizzazione con il player -->
        <article class="grz-plcard pl-list-row<?= $isFull ? ' is-complete' : '' ?><?= $isEmpty ? ' is-empty' : '' ?>" data-playlist-id="<?= $pid ?>">

          <div class="grz-plcard__art">
            <a href="<?= $url ?>" class="grz-plcard__cover" tabindex="-1" aria-hidden="true">
              <?= grzPlaylistMosaic($playlistCovers[$pid] ?? []) ?>
            </a>
            <?php if ($playable > 0): ?>
              <button class="pl-btn-play grz-plcard__play"
                data-playlist-id="<?= $pid ?>"
                onclick="PlaylistPlayer.load(<?= $pid ?>)"
                title="Riproduci"
                aria-label="Riproduci <?= htmlspecialchars($pl['name'], ENT_QUOTES) ?>">
                <i class="bi bi-play-fill" aria-hidden="true"></i>
              </button>
            <?php endif; ?>
          </div>

          <div class="grz-plcard__body">
            <a href="<?= $url ?>" class="pl-row-name grz-plcard__name" title="<?= htmlspecialchars($pl['name'], ENT_QUOTES) ?>"><?= htmlspecialchars($pl['name']) ?></a>

            <div class="grz-plcard__meta">
              <?php if ($isEmpty): ?>
                Vuota
              <?php else: ?>
                <?= $total ?> <?= $total === 1 ? 'traccia' : 'tracce' ?><?php if ($durLabel): ?> · <?= $durLabel ?><?php endif; ?>
              <?php endif; ?>
            </div>

            <?php if ($isEmpty): ?>
              <p class="grz-plcard__hint mb-0">Aggiungi tracce o album interi dalla scheda di un disco.</p>
            <?php endif; ?>

            <?php if (!$isEmpty): ?>
              <div class="grz-plcard__audio" title="<?= $playable ?> di <?= $total ?> tracce con file audio">
                <div class="grz-plcard__bar">
                  <div class="grz-plcard__fill<?= $isFull ? ' is-full' : '' ?>" style="width:<?= $pct ?>%"></div>
                </div>
                <span class="grz-plcard__audio-label">
                  <?= $isFull ? 'Tutte con audio' : ($playable . ' di ' . $total . ' con audio') ?>
                </span>
              </div>
            <?php endif; ?>

            <div class="grz-plcard__foot">
              <span class="grz-plcard__date">Creata il <?= date('d/m/Y', strtotime($pl['created_at'])) ?></span>
              <button class="grz-plcard__delete btn-delete-playlist"
                data-id="<?= $pid ?>"
                data-name="<?= htmlspecialchars($pl['name'], ENT_QUOTES) ?>"
                title="Elimina playlist"
                aria-label="Elimina <?= htmlspecialchars($pl['name'], ENT_QUOTES) ?>">
                <i class="bi bi-trash" aria-hidden="true"></i>
              </button>
            </div>
          </div>

        </article>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>

</div>

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
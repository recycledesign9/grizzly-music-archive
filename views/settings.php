<?php
$pageTitle = 'Impostazioni';
require BASE_PATH . '/views/layout/header.php';
/** @var string $audioPathActive */
/** @var string $audioPathDb */
/** @var array  $audioStats */
/** @var array  $audioTest */
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0"><i class="bi bi-gear-fill me-2 text-warning"></i>Impostazioni</h1>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert alert-success alert-dismissible fade show">
    <?= htmlspecialchars($_SESSION['flash_success']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert alert-danger alert-dismissible fade show">
    <?= htmlspecialchars($_SESSION['flash_error']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<!-- ============================================================
     Sezione: Percorso File Audio
============================================================ -->
<div class="card border-1 shadow-sm mb-4">
  <div class="card-header bg-dark text-white">
    <i class="bi bi-folder2-open me-2"></i>
    <strong>Percorso file audio</strong>
  </div>
  <div class="card-body">

    <!-- Stato attuale -->
    <div class="mb-4">
      <h6 class="text-muted text-uppercase small fw-bold mb-2">Stato attuale</h6>
      <div class="d-flex align-items-start gap-3 flex-wrap">
        <div class="flex-grow-1 min-width-0">
          <div class="font-monospace small bg-body-secondary text-body rounded p-2 mb-1 border"
            style="word-break:break-all;overflow-wrap:anywhere;">
            <?= htmlspecialchars($audioPathActive) ?>
          </div>
          <div id="pathStatusBadge">
            <?php if ($audioTest['ok']): ?>
              <span class="badge bg-success">
                <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($audioTest['message']) ?>
              </span>
            <?php else: ?>
              <span class="badge bg-danger">
                <i class="bi bi-x-circle me-1"></i><?= htmlspecialchars($audioTest['message']) ?>
              </span>
            <?php endif; ?>
          </div>
        </div>
        <div class="text-end flex-shrink-0">
          <div class="fs-4 fw-bold text-warning"><?= (int)$audioStats['count'] ?></div>
          <div class="small text-muted">file audio (MP3 + FLAC)</div>
          <div class="small text-muted"><?= htmlspecialchars($audioStats['size_human']) ?></div>
        </div>
      </div>
    </div>

    <!-- Form cambio path -->
    <h6 class="text-muted text-uppercase small fw-bold mb-2">Cambia percorso</h6>
    <p class="text-muted small mb-3">
      Inserisci il path assoluto della cartella in cui vuoi che Grizzly salvi e legga i file audio.
      Lascia vuoto per usare il percorso di default
      (<code><?= htmlspecialchars(defined('AUDIO_PATH') ? AUDIO_PATH : BASE_PATH . '/public/uploads/audio') ?></code>).
    </p>

    <div class="input-group mb-2">
      <button class="btn btn-outline-secondary" type="button" id="btnBrowseDir" title="Sfoglia cartelle">
        <i class="bi bi-folder2-open"></i>
      </button>
      <input type="text"
        id="audioPathInput"
        class="form-control font-monospace"
        placeholder="Es: /Volumes/ExternalDisk/grizzly-audio oppure D:/music/grizzly"
        value="<?= htmlspecialchars($audioPathDb) ?>">
      <button class="btn btn-outline-secondary" type="button" id="btnTestPath">
        <i class="bi bi-plug me-1"></i>Testa
      </button>
    </div>
    <div id="testResult" class="mb-3 small"></div>

    <!-- File browser inline -->
    <div id="dirBrowser" class="d-none mb-3">
      <div class="card border">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
          <span class="small fw-semibold"><i class="bi bi-folder2-open me-1 text-warning"></i>Sfoglia cartelle</span>
          <button type="button" class="btn-close btn-sm" id="btnCloseBrowser"></button>
        </div>
        <div class="card-body p-0">
          <div id="browserCurrentPath" class="px-3 py-2 bg-body-secondary text-body font-monospace small border-bottom" style="word-break:break-all"></div>
          <div id="browserList" style="max-height:280px;overflow-y:auto"></div>
        </div>
        <div class="card-footer py-2 d-flex gap-2">
          <button type="button" class="btn btn-sm btn-warning" id="btnSelectThisDir">
            <i class="bi bi-check-lg me-1"></i>Usa questa cartella
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelBrowser">Annulla</button>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-warning" id="btnSavePath">
        <i class="bi bi-floppy me-1"></i>Salva percorso
      </button>
      <button class="btn btn-outline-secondary" id="btnResetPath">
        <i class="bi bi-arrow-counterclockwise me-1"></i>Ripristina default
      </button>
    </div>

  </div>
</div>

<!-- ============================================================
     Sezione: Cache descrizioni Album
============================================================ -->
<div class="card border-1 shadow-sm mb-4">
  <div class="card-header bg-dark text-white">
    <i class="bi bi-journal-text me-2"></i>
    <strong>Cache descrizioni album</strong>
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Le "Note sull'album" recuperate automaticamente vengono salvate in cache per non rifare la ricerca ad ogni apertura della scheda disco (30 giorni se trovata, 3 giorni se non trovata). Se hai appena aggiornato il codice di ricerca, o se più dischi mostrano ingiustamente "nessuna descrizione disponibile" nonostante la fonte esista, svuota qui tutta la cache per forzare una nuova ricerca alla prossima apertura di ogni scheda.
      Per rinnovare un singolo disco invece, usa l'icona <i class="bi bi-arrow-clockwise"></i> accanto a "Note sull'album" nella sua pagina di dettaglio.
    </p>
    <button class="btn btn-outline-warning" id="btnClearWikiCache">
      <i class="bi bi-trash3 me-1"></i>Svuota cache descrizioni
    </button>
    <span id="wikiCacheResult" class="small ms-2"></span>
  </div>
</div>


<!-- ============================================================
     Sezione: Migrazione file audio
============================================================ -->
<div class="card border-1 shadow-sm mb-4">
  <div class="card-header bg-dark text-white">
    <i class="bi bi-arrow-left-right me-2"></i>
    <strong>Migra file audio</strong>
  </div>
  <div class="card-body">

    <p class="text-muted small mb-3">
      Copia fisicamente tutti i file audio (MP3 e FLAC) presenti nella cartella attuale
      (<strong><?= (int)$audioStats['count'] ?> file, <?= htmlspecialchars($audioStats['size_human']) ?></strong>)
      in una nuova destinazione. Dopo la copia dovrai salvare il nuovo percorso sopra.<br>
      <span class="text-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        I file originali non vengono eliminati automaticamente — potrai farlo manualmente
        dopo aver verificato che tutto funzioni correttamente.
      </span>
    </p>

    <div class="input-group mb-2">
      <button class="btn btn-outline-secondary" type="button" id="btnBrowseMigrate" title="Sfoglia cartelle">
        <i class="bi bi-folder2-open"></i>
      </button>
      <input type="text"
        id="migrateTargetInput"
        class="form-control font-monospace"
        placeholder="Path di destinazione assoluto">
    </div>

    <!-- Browser cartelle — sezione MIGRAZIONE -->
    <div id="dirBrowserMigrate" class="d-none mb-3">
      <div class="card border">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
          <span class="small fw-semibold"><i class="bi bi-folder2-open me-1 text-warning"></i>Sfoglia cartelle</span>
          <button type="button" class="btn-close btn-sm" id="btnCloseBrowserMigrate"></button>
        </div>
        <div class="card-body p-0">
          <div id="browserCurrentPathMigrate" class="px-3 py-2 bg-body-secondary text-body font-monospace small border-bottom" style="word-break:break-all"></div>
          <div id="browserListMigrate" style="max-height:280px;overflow-y:auto"></div>
        </div>
        <div class="card-footer py-2 d-flex gap-2">
          <button type="button" class="btn btn-sm btn-warning" id="btnSelectMigrateDir">
            <i class="bi bi-check-lg me-1"></i>Usa questa cartella
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelBrowserMigrate">Annulla</button>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2 align-items-center flex-wrap">
      <button class="btn btn-outline-warning" id="btnMigrate">
        <i class="bi bi-copy me-1"></i>Avvia migrazione
      </button>
      <button class="btn btn-outline-secondary d-none" id="btnAbortMigrate">
        <i class="bi bi-stop-circle me-1"></i>Annulla
      </button>
    </div>

    <!-- Progress -->
    <div id="migrateProgress" class="mt-3 d-none">
      <!-- Barra principale -->
      <div class="d-flex justify-content-between align-items-center mb-1">
        <span class="small fw-semibold text-warning" id="migrateStatusLabel">Preparazione…</span>
        <span class="small font-monospace text-muted" id="migrateCounter">0 / 0</span>
      </div>
      <div class="rounded overflow-hidden mb-2" style="height:10px;background:var(--bs-border-color)">
        <div id="migrateBar"
          style="height:100%;width:0%;background:linear-gradient(90deg,#ffc107,#ff9800);
                    transition:width .3s ease;border-radius:inherit"></div>
      </div>
      <!-- File corrente -->
      <div id="migrateCurrentFile" class="small font-monospace text-muted text-truncate"
        style="max-width:100%"></div>
    </div>

    <div id="migrateResult" class="mt-3 small"></div>

  </div>
</div>

<!-- ============================================================
     Sezione: Cover (informativa — non configurabile)
============================================================ -->
<div class="card border-1 shadow-sm mb-4 opacity-75">
  <div class="card-header bg-secondary text-white">
    <i class="bi bi-image me-2"></i>
    <strong>Percorso cover</strong>
    <span class="badge bg-light text-dark ms-2 small">Non configurabile</span>
  </div>
  <div class="card-body">
    <p class="text-muted small mb-1">
      Le cover sono immagini leggere (50–300 KB) e rimangono sempre nella webroot del progetto,
      servite direttamente da Apache senza overhead PHP.
    </p>
    <div class="font-monospace small bg-dark text-light rounded p-2">
      <?= htmlspecialchars(defined('COVERS_PATH') ? COVERS_PATH : BASE_PATH . '/public/uploads/covers') ?>
    </div>
  </div>
</div>

<!-- ============================================================
     Sezione: Backup e migrazione archivio (export / import)
============================================================ -->
<div class="card border-1 shadow-sm mb-4">
  <div class="card-header bg-dark text-white">
    <i class="bi bi-box-seam me-2"></i>
    <strong>Backup e migrazione archivio</strong>
  </div>
  <div class="card-body">

    <p class="text-muted small mb-4">
      Esporta tutto l'archivio (dischi, artisti, tracce, playlist, biografie e
      <strong>copertine + immagini artista</strong>) in un unico file <code>.zip</code>,
      da importare su un'altra installazione di Grizzly — utile quando sposti l'app su un nuovo server.
      <span class="d-block mt-2 text-warning">
        <i class="bi bi-info-circle me-1"></i>
        I <strong>file audio</strong> non sono inclusi (sono troppo pesanti): spostali a parte con
        la sezione &laquo;Migra file audio&raquo; qui sopra, oppure copiando la cartella audio manualmente.
      </span>
    </p>

    <!-- ESPORTA -->
    <div class="mb-4">
      <h6 class="text-muted text-uppercase small fw-bold mb-2">Esporta</h6>
      <p class="text-muted small mb-2">
        Genera e scarica il file di backup completo dell'archivio.
      </p>
      <a class="btn btn-warning" id="btnExport" href="<?= BASE_URL ?>/index.php?route=settings/export">
        <i class="bi bi-download me-1"></i>Esporta archivio (.zip)
      </a>
    </div>

    <hr>

    <!-- IMPORTA -->
    <div>
      <h6 class="text-muted text-uppercase small fw-bold mb-2">Importa</h6>
      <p class="text-danger small mb-3">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong>Attenzione:</strong> l'import <u>sostituisce completamente</u> l'archivio attuale
        con il contenuto del file. I dati presenti verranno rimpiazzati. Verrà comunque creato
        un backup di sicurezza automatico prima di procedere.
      </p>

      <form method="post"
        action="<?= BASE_URL ?>/index.php?route=settings/import"
        enctype="multipart/form-data"
        id="importForm">
        <div class="input-group mb-2">
          <input type="file" name="archive" id="importFile" class="form-control" accept=".zip" required>
          <button class="btn btn-outline-danger" type="submit" id="btnImport">
            <i class="bi bi-upload me-1"></i>Importa e sostituisci
          </button>
        </div>
        <!-- conferma esplicita richiesta dal controller -->
        <input type="hidden" name="confirm" value="REPLACE">
      </form>
      <div class="form-text">
        Carica un file <code>.zip</code> generato dalla funzione &laquo;Esporta&raquo; di Grizzly.
      </div>
    </div>

  </div>
</div>


<script>
  // ── Feedback visivo durante la preparazione dell'export ─────
  (function() {
    var b = document.getElementById('btnExport');
    if (!b) return;
    b.addEventListener('click', function() {
      var original = b.innerHTML;
      b.classList.add('disabled');
      b.setAttribute('aria-disabled', 'true');
      b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Preparazione export…';
      // Il download avviene fuori dalla pagina: ripristina dopo qualche secondo.
      setTimeout(function() {
        b.classList.remove('disabled');
        b.removeAttribute('aria-disabled');
        b.innerHTML = original;
      }, 5000);
    });
  })();


  // ── Conferma import (sostituzione archivio) ─────────────────
  (function() {
    var form = document.getElementById('importForm');
    if (!form) return;
    form.addEventListener('submit', function(e) {
      var f = document.getElementById('importFile');
      if (!f || !f.files || !f.files.length) {
        e.preventDefault();
        alert('Seleziona prima un file .zip da importare.');
        return;
      }
      var ok = confirm(
        'ATTENZIONE: questa operazione sostituirà completamente l\'archivio attuale ' +
        'con il contenuto del file selezionato.\n\n' +
        'Verrà creato un backup di sicurezza automatico, ma i dati attuali verranno rimpiazzati.\n\n' +
        'Vuoi procedere?'
      );
      if (!ok) {
        e.preventDefault();
        return;
      }
      var btn = document.getElementById('btnImport');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importazione…';
      }
    });
  })();

  (function() {
    var BASE_URL = document.querySelector('meta[name="base-url"]').getAttribute('content');
    var CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

    // ── Helper fetch JSON POST ──────────────────────────────────
    function postJSON(url, data, callback) {
      var body = new FormData();
      body.append('csrf_token', CSRF_TOKEN);
      for (var k in data) body.append(k, data[k]);

      fetch(url, {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: body
        })
        .then(function(r) {
          return r.json();
        })
        .then(callback)
        .catch(function(e) {
          callback({
            ok: false,
            message: e.message
          });
        });
    }

    // ── Testa path (senza salvare nel DB) ──────────────────────
    document.getElementById('btnTestPath').onclick = function() {
      var val = document.getElementById('audioPathInput').value.trim();
      var el = document.getElementById('testResult');

      if (!val) {
        el.innerHTML = '<span class="text-muted"><i class="bi bi-info-circle me-1"></i>' +
          'Inserisci un percorso da testare. Lascia vuoto e clicca Salva per usare il default.</span>';
        return;
      }

      el.innerHTML = '<span class="text-muted">Test in corso…</span>';

      fetch(BASE_URL + '/index.php?route=media/test-path-value&path=' + encodeURIComponent(val), {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(function(r) {
          return r.json();
        })
        .then(function(data) {
          el.innerHTML = data.ok ?
            '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + escHtml(data.message) + '</span>' :
            '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + escHtml(data.message) + '</span>';
        })
        .catch(function(e) {
          el.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + escHtml(e.message) + '</span>';
        });
    };

    // ── Salva path ──────────────────────────────────────────────
    document.getElementById('btnSavePath').onclick = function() {
      var val = document.getElementById('audioPathInput').value.trim();
      postJSON(
        BASE_URL + '/index.php?route=media/set-path', {
          audio_path: val
        },
        function(data) {
          if (data.ok) {
            showToast('success', 'Percorso salvato correttamente.');
          } else {
            showToast('danger', data.message || 'Errore nel salvataggio.');
          }
        }
      );
    };

    // ── Reset al default ────────────────────────────────────────
    document.getElementById('btnResetPath').onclick = function() {
      document.getElementById('audioPathInput').value = '';
      postJSON(
        BASE_URL + '/index.php?route=media/set-path', {
          audio_path: ''
        },
        function(data) {
          if (data.ok) {
            showToast('success', 'Percorso ripristinato al default.');
            document.getElementById('testResult').innerHTML = '';
          } else {
            showToast('danger', data.message || 'Errore.');
          }
        }
      );
    };

    // ── Migrazione ──────────────────────────────────────────────
    document.getElementById('btnMigrate').onclick = function() {
      var target = document.getElementById('migrateTargetInput').value.trim();
      if (!target) {
        showToast('warning', 'Inserisci il percorso di destinazione.');
        return;
      }
      if (!confirm('Avviare la copia dei file audio in:\n' + target + '\n\nI file originali NON verranno eliminati.')) {
        return;
      }

      var btnMigrate = document.getElementById('btnMigrate');
      var btnAbort = document.getElementById('btnAbortMigrate');
      var aborted = false;
      btnAbort.classList.remove('d-none');
      btnAbort.onclick = function() {
        aborted = true;
        btnAbort.disabled = true;
        btnAbort.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Annullamento…';
      };
      var prog = document.getElementById('migrateProgress');
      var bar = document.getElementById('migrateBar');
      var counter = document.getElementById('migrateCounter');
      var label = document.getElementById('migrateStatusLabel');
      var currentFile = document.getElementById('migrateCurrentFile');
      var result = document.getElementById('migrateResult');

      // Reset UI
      prog.classList.remove('d-none');
      result.innerHTML = '';
      bar.style.width = '0%';
      counter.textContent = '0 / ?';
      label.textContent = 'Conteggio file…';
      currentFile.textContent = '';
      btnMigrate.disabled = true;
      btnMigrate.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Migrazione in corso…';

      var totalFiles = 0;
      var totalMoved = 0;
      var totalSkipped = 0;
      var allErrors = [];
      var CHUNK_SIZE = 20;

      // Step 1: conta i file
      fetch(BASE_URL + '/index.php?route=media/migrate-count', {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(function(r) {
          return r.json();
        })
        .then(function(data) {
          if (!data.ok || data.total === 0) {
            prog.classList.add('d-none');
            result.innerHTML = '<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Nessun file audio (MP3/FLAC) trovato nella cartella sorgente.</div>';
            resetBtn();
            return;
          }
          totalFiles = data.total;
          label.textContent = 'Copia in corso…';
          counter.textContent = '0 / ' + totalFiles;
          runChunk(0);
        })
        .catch(function(e) {
          showError('Errore conteggio: ' + e.message);
          resetBtn();
        });

      // Step 2: copia a chunk
      function runChunk(offset) {
        if (aborted) {
          bar.style.background = '#6c757d';
          label.textContent = 'Annullato.';
          currentFile.textContent = '';
          result.innerHTML = '<div class="alert alert-secondary mb-0">' +
            '<i class="bi bi-stop-circle me-2"></i>Migrazione annullata. ' +
            totalMoved + ' file copiati, ' + totalSkipped + ' saltati.</div>';
          resetBtn();
          return;
        }
        var pct = totalFiles > 0 ? Math.round((offset / totalFiles) * 100) : 0;
        bar.style.width = pct + '%';
        counter.textContent = offset + ' / ' + totalFiles;

        var body = new FormData();
        body.append('csrf_token', CSRF_TOKEN);
        body.append('target_dir', target);
        body.append('offset', offset);
        body.append('limit', CHUNK_SIZE);

        fetch(BASE_URL + '/index.php?route=media/migrate-chunk', {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: body
          })
          .then(function(r) {
            return r.json();
          })
          .then(function(data) {
            if (!data.ok) {
              showError(data.message || 'Errore durante la copia.');
              resetBtn();
              return;
            }

            totalMoved += data.moved;
            totalSkipped += data.skipped;
            if (data.errors && data.errors.length) {
              for (var i = 0; i < data.errors.length; i++) {
                allErrors.push(data.errors[i]);
              }
            }

            // Aggiorna file corrente (ultimo del chunk)
            var chunkEnd = Math.min(data.next_offset, totalFiles);
            currentFile.textContent = 'Copiati fino al file ' + chunkEnd + '…';

            if (data.done) {
              // Completato
              bar.style.width = '100%';
              bar.style.background = allErrors.length ? 'linear-gradient(90deg,#dc3545,#c82333)' : 'linear-gradient(90deg,#198754,#20c997)';
              counter.textContent = totalFiles + ' / ' + totalFiles;
              label.textContent = allErrors.length ? 'Completato con errori' : 'Completato!';
              currentFile.textContent = '';

              var html = '';
              if (allErrors.length === 0) {
                html = '<div class="alert alert-success mb-0">' +
                  '<i class="bi bi-check-circle-fill me-2"></i>' +
                  '<strong>' + totalMoved + '</strong> file copiati' +
                  (totalSkipped ? ', <strong>' + totalSkipped + '</strong> già presenti (saltati)' : '') +
                  '.<br><span class="small">Ora imposta il nuovo percorso sopra e salvalo.</span>' +
                  '</div>';
                document.getElementById('audioPathInput').value = target;
              } else {
                html = '<div class="alert alert-warning mb-0">' +
                  '<i class="bi bi-exclamation-triangle-fill me-2"></i>' +
                  '<strong>' + totalMoved + '</strong> file copiati, ' +
                  '<strong class="text-danger">' + allErrors.length + '</strong> errori.' +
                  '<details class="mt-2"><summary class="small" style="cursor:pointer">Mostra errori</summary>' +
                  '<ul class="mb-0 mt-1 small">';
                for (var ei = 0; ei < allErrors.length; ei++) {
                  html += '<li>' + escHtml(allErrors[ei]) + '</li>';
                }
                html += '</ul></details></div>';
              }
              result.innerHTML = html;
              resetBtn();
            } else {
              runChunk(data.next_offset);
            }
          })
          .catch(function(e) {
            showError('Errore di rete: ' + e.message);
            resetBtn();
          });
      }

      function showError(msg) {
        prog.classList.add('d-none');
        result.innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-x-circle me-2"></i>' + escHtml(msg) + '</div>';
      }

      function resetBtn() {
        btnMigrate.disabled = false;
        btnMigrate.innerHTML = '<i class="bi bi-copy me-1"></i>Avvia migrazione';
        btnAbort.classList.add('d-none');
        btnAbort.disabled = false;
        btnAbort.innerHTML = '<i class="bi bi-stop-circle me-1"></i>Annulla';
        aborted = false;
      }
    };

    // ── Browse cartelle server ─────────────────────────────────
    var currentBrowsePath = '';
    var currentBrowserTarget = 'audio'; // 'audio' | 'migrate'

    document.getElementById('btnBrowseDir').onclick = function() {
      var current = document.getElementById('audioPathInput').value.trim();
      currentBrowserTarget = 'audio';
      openBrowser(current || '');
    };

    document.getElementById('btnCloseBrowser').onclick =
      document.getElementById('btnCancelBrowser').onclick = function() {
        document.getElementById('dirBrowser').classList.add('d-none');
      };

    document.getElementById('btnSelectThisDir').onclick = function() {
      document.getElementById('audioPathInput').value = currentBrowsePath;
      document.getElementById('dirBrowser').classList.add('d-none');
    };

    // ── Browser migrazione ──────────────────────────────────────
    document.getElementById('btnBrowseMigrate').onclick = function() {
      var current = document.getElementById('migrateTargetInput').value.trim();
      currentBrowserTarget = 'migrate';
      openBrowser(current || '');
    };

    document.getElementById('btnCloseBrowserMigrate').onclick =
      document.getElementById('btnCancelBrowserMigrate').onclick = function() {
        document.getElementById('dirBrowserMigrate').classList.add('d-none');
      };

    document.getElementById('btnSelectMigrateDir').onclick = function() {
      document.getElementById('migrateTargetInput').value = currentBrowsePath;
      document.getElementById('dirBrowserMigrate').classList.add('d-none');
    };

    function openBrowser(path) {
      var isMigrate = (currentBrowserTarget === 'migrate');
      var browser = document.getElementById(isMigrate ? 'dirBrowserMigrate' : 'dirBrowser');
      var list = document.getElementById(isMigrate ? 'browserListMigrate' : 'browserList');
      var pathEl = document.getElementById(isMigrate ? 'browserCurrentPathMigrate' : 'browserCurrentPath');

      browser.classList.remove('d-none');
      list.innerHTML = '<div class="text-center p-3 text-muted small">' +
        '<span class="spinner-border spinner-border-sm me-2"></span>Caricamento…</div>';

      fetch(BASE_URL + '/index.php?route=media/browse-dir&path=' + encodeURIComponent(path), {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(function(r) {
          var ct = r.headers.get('content-type') || '';
          if (!ct.includes('application/json')) {
            return r.text().then(function(t) {
              throw new Error('Risposta non-JSON: ' + t.replace(/<[^>]+>/g, '').trim().substring(0, 100));
            });
          }
          return r.json();
        })
        .then(function(data) {
          var html = '';

          // Sezione Accesso rapido — bookmarks (dischi /Volumes, ecc.)
          if (data.bookmarks && data.bookmarks.length) {
            html += '<div class="px-3 pt-2 pb-1 border-bottom bg-body-tertiary">' +
              '<span class="text-muted" style="font-size:.7rem;text-transform:uppercase;' +
              'letter-spacing:.05em;font-weight:600">' +
              '<i class="bi bi-lightning-fill me-1 text-info"></i>Accesso rapido</span></div>';
            for (var bi = 0; bi < data.bookmarks.length; bi++) {
              var bk = data.bookmarks[bi];
              html += '<div class="browser-entry d-flex align-items-center px-3 py-1 border-bottom" ' +
                'data-path="' + escAttr(bk.path) + '" style="cursor:pointer">' +
                '<i class="bi bi-hdd text-info me-2"></i>' +
                '<span class="small text-info fw-semibold">' + escHtml(bk.label) + '</span>' +
                '<span class="small text-muted ms-2 font-monospace" style="font-size:.7rem">' +
                escHtml(bk.path) + '</span>' +
                '</div>';
            }
          }

          // Cartella inaccessibile: mostra errore ma mantieni bookmarks visibili
          if (!data.ok) {
            html += '<div class="p-3 text-warning small">' +
              '<i class="bi bi-lock me-1"></i>' +
              escHtml(data.error || 'Accesso negato.') +
              '<br><span class="text-muted small">Usa Accesso rapido per navigare ai dischi.</span></div>';
            list.innerHTML = html;
            return;
          }

          currentBrowsePath = data.current;
          pathEl.textContent = data.current;

          if (data.bookmarks && data.bookmarks.length) {
            html += '<div class="px-3 pt-2 pb-1 border-bottom bg-body-tertiary">' +
              '<span class="text-muted" style="font-size:.7rem;text-transform:uppercase;' +
              'letter-spacing:.05em;font-weight:600">Cartelle</span></div>';
          }

          // Riga ".." per salire
          if (data.parent !== null && data.parent !== undefined) {
            html += '<div class="browser-entry d-flex align-items-center px-3 py-2 border-bottom" ' +
              'data-path="' + escAttr(data.parent) + '" style="cursor:pointer">' +
              '<i class="bi bi-arrow-up-circle text-muted me-2"></i>' +
              '<span class="small text-muted fst-italic">..</span>' +
              '</div>';
          }

          // Sottocartelle (escludi le righe ".." già gestite sopra)
          var dirs = (data.dirs || []).filter(function(d) {
            return !d.up;
          });
          if (dirs.length === 0) {
            html += '<div class="p-3 text-muted small fst-italic">Nessuna sottocartella accessibile.</div>';
          } else {
            for (var i = 0; i < dirs.length; i++) {
              html += '<div class="browser-entry d-flex align-items-center px-3 py-2 border-bottom" ' +
                'data-path="' + escAttr(dirs[i].path) + '" style="cursor:pointer">' +
                '<i class="bi bi-folder-fill text-warning me-2"></i>' +
                '<span class="small">' + escHtml(dirs[i].name) + '</span>' +
                '</div>';
            }
          }

          list.innerHTML = html;

          var entries = list.querySelectorAll('.browser-entry');
          for (var ei = 0; ei < entries.length; ei++) {
            entries[ei].addEventListener('click', function() {
              openBrowser(this.dataset.path);
              // Scrolla la lista in cima dopo la navigazione
              list.scrollTop = 0;
            });
            entries[ei].addEventListener('mouseenter', function() {
              this.classList.add('bg-body-secondary');
            });
            entries[ei].addEventListener('mouseleave', function() {
              this.classList.remove('bg-body-secondary');
            });
          }

          // Se siamo arrivati da un bookmark (accesso rapido), scrolla
          // la lista per mostrare le cartelle sotto la sezione bookmarks
          var cartelleHeader = list.querySelector('.bg-body-tertiary:last-of-type');
          if (cartelleHeader) {
            list.scrollTop = cartelleHeader.offsetTop;
          }
        })
        .catch(function(e) {
          list.innerHTML = '<div class="p-3 text-danger small"><i class="bi bi-x-circle me-1"></i>' +
            escHtml(e.message) + '</div>';
        });
    }

    // ── Svuota cache Wikipedia ──────────────────────────────────
    document.getElementById('btnClearWikiCache').onclick = function() {
      var btn = this;
      var result = document.getElementById('wikiCacheResult');

      btn.disabled = true;
      var originalHtml = btn.innerHTML;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Svuotamento…';
      result.textContent = '';

      fetch(BASE_URL + '/index.php?route=settings/clear-wiki-cache', {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(function(r) {
          return r.json();
        })
        .then(function(data) {
          btn.disabled = false;
          btn.innerHTML = originalHtml;
          if (data.ok) {
            result.className = 'small ms-2 text-success';
            result.innerHTML = '<i class="bi bi-check-circle me-1"></i>' + escHtml(data.message);
          } else {
            result.className = 'small ms-2 text-danger';
            result.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + escHtml(data.error || 'Errore.');
          }
        })
        .catch(function(e) {
          btn.disabled = false;
          btn.innerHTML = originalHtml;
          result.className = 'small ms-2 text-danger';
          result.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + escHtml(e.message);
        });
    };

    // ── Utils ────────────────────────────────────────────────────
    function escAttr(s) {
      return (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
    }

    function escHtml(s) {
      var d = document.createElement('div');
      d.textContent = s;
      return d.innerHTML;
    }

    function showToast(type, msg) {
      var el = document.createElement('div');
      el.className = 'alert alert-' + type + ' alert-dismissible fade show position-fixed bottom-0 end-0 m-3';
      el.style.zIndex = '9999';
      el.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
      document.body.appendChild(el);
      setTimeout(function() {
        el.remove();
      }, 4000);
    }
  })();
</script>

<?php require BASE_PATH . '/views/layout/footer.php'; ?>
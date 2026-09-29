<?php
$pageTitle = 'Impostazioni';
require BASE_PATH . '/views/layout/header.php';
/** @var string $audioPathActive */
/** @var string $audioPathDb */
/** @var array  $audioStats */
/** @var array  $audioTest */
/** @var bool   $mediaScanEnabled */
/** @var string $mediaScanPath */
/** @var int    $mediaScanInterval */
/** @var int    $mediaScanStableSeconds */
/** @var bool   $mediaScanWorkerAlive */
/** @var int|null $mediaScanWorkerHeartbeatAge */
/** @var array  $ignoredMediaSources */

$defaultAudioPath = defined('AUDIO_PATH') ? AUDIO_PATH : BASE_PATH . '/public/uploads/audio';
$ignoredCount     = (int)($ignoredMediaSourcesCount ?? 0);

/** @var array $externalApiServices */
$externalApiServices = is_array($externalApiServices ?? null) ? $externalApiServices : [];
$externalApiUi = [
  'lastfm' => [
    'title' => 'Last.fm',
    'description' => 'Metadati aggiuntivi e suggerimenti musicali quando le fonti principali non bastano.',
    'field_label' => 'API key',
    'placeholder' => 'Inserisci una nuova API key',
    'help_url' => 'https://www.last.fm/api/account/create',
  ],
  'discogs' => [
    'title' => 'Discogs',
    'description' => 'Fallback per metadati, release e copertine.',
    'field_label' => 'Personal access token',
    'placeholder' => 'Inserisci un nuovo token',
    'help_url' => 'https://www.discogs.com/settings/developers',
  ],
  'youtube' => [
    'title' => 'YouTube Data API',
    'description' => 'Ricerca e associazione dei video alle tracce. Le ricerche già risolte restano nella cache del database.',
    'field_label' => 'API key',
    'placeholder' => 'Inserisci una nuova API key',
    'help_url' => 'https://console.cloud.google.com/apis/credentials',
  ],
];

// Versione dell'applicazione: unica fonte è il file VERSION nella root del
// repository, aggiornato a ogni release insieme al tag git.
$appVersionFile = BASE_PATH . '/VERSION';
$appVersion     = is_readable($appVersionFile) ? trim((string)file_get_contents($appVersionFile)) : '';
$appDocsUrl     = 'https://www.recycledesign.it/grizzly/docs';
?>

<div class="grz-settings">

  <div class="grz-settings__head">
    <h1 class="grz-settings__title">Impostazioni</h1>
  </div>

  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <?= htmlspecialchars($_SESSION['flash_success']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>

  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <?= htmlspecialchars($_SESSION['flash_error']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <div class="grz-settings__layout">

    <!-- Indice sezioni: su desktop resta fisso a sinistra -->
    <nav class="grz-settings__nav" aria-label="Sezioni delle impostazioni">
      <a href="#set-libreria" class="grz-settings__navlink is-active"><i class="bi bi-folder2-open" aria-hidden="true"></i>Libreria audio</a>
      <a href="#set-scansione" class="grz-settings__navlink"><i class="bi bi-radar" aria-hidden="true"></i>Scansione automatica</a>
      <a href="#set-manutenzione" class="grz-settings__navlink"><i class="bi bi-tools" aria-hidden="true"></i>Manutenzione</a>
      <a href="#set-servizi-esterni" class="grz-settings__navlink"><i class="bi bi-key" aria-hidden="true"></i>Servizi esterni</a>
      <a href="#set-backup" class="grz-settings__navlink"><i class="bi bi-box-seam" aria-hidden="true"></i>Backup e ripristino</a>
      <a href="#set-info" class="grz-settings__navlink"><i class="bi bi-info-circle" aria-hidden="true"></i>Informazioni</a>
    </nav>

    <div class="grz-settings__content">

      <!-- ============================================================
           Libreria audio: percorso attuale, cambio percorso, migrazione
      ============================================================ -->
      <section class="grz-set" id="set-libreria" aria-labelledby="set-libreria-h">
        <header class="grz-set__head">
          <h2 id="set-libreria-h">Libreria audio</h2>
          <p>Cartella in cui Grizzly salva e legge i file MP3 e FLAC.</p>
        </header>

        <div class="grz-set__row">
          <div class="grz-set__label">
            <h3>Cartella attuale</h3>
          </div>
          <div class="grz-set__control">
            <div class="grz-set__path"><?= htmlspecialchars($audioPathActive) ?></div>
            <div class="grz-set__status" id="pathStatusBadge">
              <?php if ($audioTest['ok']): ?>
                <span class="grz-set__dot grz-set__dot--ok" aria-hidden="true"></span>
                <span>Raggiungibile · <strong><?= (int)$audioStats['count'] ?></strong> file · <?= htmlspecialchars($audioStats['size_human']) ?></span>
              <?php else: ?>
                <span class="grz-set__dot grz-set__dot--err" aria-hidden="true"></span>
                <span class="text-danger-emphasis"><?= htmlspecialchars($audioTest['message']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="grz-set__row">
          <div class="grz-set__label">
            <h3>Cambia percorso</h3>
            <p>Percorso assoluto. Lascia vuoto per usare quello predefinito.</p>
          </div>
          <div class="grz-set__control">
            <details class="grz-set__panel" id="audioPathPanel">
              <summary>Modifica la cartella audio</summary>

              <div class="grz-set__panel-body">
                <label class="form-label" for="audioPathInput">Nuovo percorso</label>
                <div class="input-group">
                  <button class="btn btn-outline-secondary" type="button" id="btnBrowseDir" title="Sfoglia cartelle" aria-label="Sfoglia cartelle">
                    <i class="bi bi-folder2-open" aria-hidden="true"></i>
                  </button>
                  <input type="text"
                    id="audioPathInput"
                    class="form-control font-monospace"
                    placeholder="/Volumes/ExternalDisk/grizzly-audio"
                    value="<?= htmlspecialchars($audioPathDb) ?>">
                  <button class="btn btn-outline-secondary" type="button" id="btnTestPath">
                    <i class="bi bi-plug me-1" aria-hidden="true"></i>Testa
                  </button>
                </div>
                <div class="form-text">Predefinito: <code><?= htmlspecialchars($defaultAudioPath) ?></code></div>
                <div id="testResult" class="small mt-2"></div>

                <!-- Browser cartelle: percorso audio -->
                <div id="dirBrowser" class="d-none mt-3">
                  <div class="card border">
                    <div class="card-header py-2 d-flex align-items-center justify-content-between">
                      <span class="small fw-semibold"><i class="bi bi-folder2-open me-1" aria-hidden="true"></i>Sfoglia cartelle</span>
                      <button type="button" class="btn-close btn-sm" id="btnCloseBrowser" aria-label="Chiudi"></button>
                    </div>
                    <div class="card-body p-0">
                      <div id="browserCurrentPath" class="px-3 py-2 bg-body-secondary text-body font-monospace small border-bottom" style="word-break:break-all"></div>
                      <div id="browserList" style="max-height:280px;overflow-y:auto"></div>
                    </div>
                    <div class="card-footer py-2 d-flex gap-2">
                      <button type="button" class="btn btn-sm btn-warning" id="btnSelectThisDir">
                        <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Usa questa cartella
                      </button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelBrowser">Annulla</button>
                    </div>
                  </div>
                </div>

                <div class="grz-set__actions">
                  <button class="btn btn-warning" id="btnSavePath">
                    <i class="bi bi-floppy me-1" aria-hidden="true"></i>Salva percorso
                  </button>
                  <button class="btn btn-outline-secondary" id="btnResetPath">
                    <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Ripristina predefinito
                  </button>
                </div>
              </div>
            </details>
          </div>
        </div>

        <div class="grz-set__row">
          <div class="grz-set__label">
            <h3>Migra file audio</h3>
            <p>Copia i <?= (int)$audioStats['count'] ?> file (<?= htmlspecialchars($audioStats['size_human']) ?>) in una nuova cartella. Gli originali non vengono cancellati.</p>
          </div>
          <div class="grz-set__control">
            <label class="form-label" for="migrateTargetInput">Cartella di destinazione</label>
            <div class="input-group">
              <button class="btn btn-outline-secondary" type="button" id="btnBrowseMigrate" title="Sfoglia cartelle" aria-label="Sfoglia cartelle">
                <i class="bi bi-folder2-open" aria-hidden="true"></i>
              </button>
              <input type="text"
                id="migrateTargetInput"
                class="form-control font-monospace"
                placeholder="Percorso assoluto di destinazione">
            </div>

            <!-- Browser cartelle: migrazione -->
            <div id="dirBrowserMigrate" class="d-none mt-3">
              <div class="card border">
                <div class="card-header py-2 d-flex align-items-center justify-content-between">
                  <span class="small fw-semibold"><i class="bi bi-folder2-open me-1" aria-hidden="true"></i>Sfoglia cartelle</span>
                  <button type="button" class="btn-close btn-sm" id="btnCloseBrowserMigrate" aria-label="Chiudi"></button>
                </div>
                <div class="card-body p-0">
                  <div id="browserCurrentPathMigrate" class="px-3 py-2 bg-body-secondary text-body font-monospace small border-bottom" style="word-break:break-all"></div>
                  <div id="browserListMigrate" style="max-height:280px;overflow-y:auto"></div>
                </div>
                <div class="card-footer py-2 d-flex gap-2">
                  <button type="button" class="btn btn-sm btn-warning" id="btnSelectMigrateDir">
                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Usa questa cartella
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelBrowserMigrate">Annulla</button>
                </div>
              </div>
            </div>

            <div class="grz-set__actions">
              <button class="btn btn-outline-secondary" id="btnMigrate">
                <i class="bi bi-copy me-1" aria-hidden="true"></i>Avvia migrazione
              </button>
              <button class="btn btn-outline-secondary d-none" id="btnAbortMigrate">
                <i class="bi bi-stop-circle me-1" aria-hidden="true"></i>Annulla
              </button>
            </div>

            <!-- Avanzamento -->
            <div id="migrateProgress" class="mt-3 d-none">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="small fw-semibold" id="migrateStatusLabel">Preparazione…</span>
                <span class="small font-monospace text-muted" id="migrateCounter">0 / 0</span>
              </div>
              <div class="grz-set__progress">
                <div id="migrateBar" class="grz-set__progress-bar" style="width:0%"></div>
              </div>
              <div id="migrateCurrentFile" class="small font-monospace text-muted text-truncate mt-2" style="max-width:100%"></div>
            </div>

            <div id="migrateResult" class="mt-3 small"></div>

            <details class="grz-set__more">
              <summary>Come procedere</summary>
              <p>Al termine della copia il nuovo percorso viene inserito in "Cambia percorso": salvalo per usare la nuova cartella. Elimina gli originali a mano solo dopo aver verificato che tutto funzioni.</p>
            </details>
          </div>
        </div>
      </section>

      <!-- ============================================================
           Scansione automatica
      ============================================================ -->
      <section class="grz-set" id="set-scansione" aria-labelledby="set-scansione-h">
        <header class="grz-set__head grz-set__head--row">
          <div>
            <h2 id="set-scansione-h">Scansione automatica</h2>
            <p>Grizzly controlla una cartella e importa gli album che trova. Il caricamento manuale non cambia.</p>
          </div>
          <?php
            $scannerBadgeClass = $mediaScanEnabled
              ? ($mediaScanWorkerAlive ? 'bg-success' : 'bg-warning text-dark')
              : 'bg-secondary';
            $scannerBadgeText = $mediaScanEnabled
              ? ($mediaScanWorkerAlive ? 'Attiva · worker in esecuzione' : 'Attiva · worker non raggiungibile')
              : ($mediaScanWorkerAlive ? 'Disattivata · worker in attesa' : 'Disattivata · worker non raggiungibile');
          ?>
          <span id="mediaScanStatusBadge"
            class="badge <?= $scannerBadgeClass ?>"
            title="<?= $mediaScanWorkerHeartbeatAge !== null ? 'Ultimo heartbeat ' . (int)$mediaScanWorkerHeartbeatAge . 's fa' : 'Heartbeat non disponibile' ?>">
            <?= htmlspecialchars($scannerBadgeText, ENT_QUOTES, 'UTF-8') ?>
          </span>
        </header>

        <div class="grz-set__row">
          <div class="grz-set__label">
            <h3>Stato</h3>
            <p>Salvato subito. Il worker lo applica al ciclo successivo.</p>
          </div>
          <div class="grz-set__control">
            <div class="form-check form-switch grz-set__switch">
              <input class="form-check-input"
                type="checkbox"
                role="switch"
                id="mediaScanEnabled"
                <?= $mediaScanEnabled ? 'checked' : '' ?>>
              <label class="form-check-label" for="mediaScanEnabled">Abilita scansione automatica</label>
            </div>
          </div>
        </div>

        <div class="grz-set__row">
          <div class="grz-set__label">
            <h3>Configurazione</h3>
            <p>Percorso assoluto sulla macchina che ospita la libreria.</p>
          </div>
          <div class="grz-set__control">
            <label for="mediaScanPath" class="form-label">Cartella da scansionare</label>
            <input type="text"
              id="mediaScanPath"
              class="form-control font-monospace"
              placeholder="/Volumes/Music/Incoming"
              value="<?= htmlspecialchars($mediaScanPath, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-text">In Docker usa il percorso reale dell'host, non quello interno al container.</div>

            <div class="grz-set__pair">
              <div>
                <label for="mediaScanInterval" class="form-label">Intervallo</label>
                <div class="input-group">
                  <input type="number"
                    id="mediaScanInterval"
                    class="form-control"
                    min="5"
                    max="3600"
                    step="1"
                    value="<?= (int)$mediaScanInterval ?>">
                  <span class="input-group-text">secondi</span>
                </div>
                <div class="form-text">Minimo 5.</div>
              </div>
              <div>
                <label for="mediaScanStableSeconds" class="form-label">Attesa stabilità</label>
                <div class="input-group">
                  <input type="number"
                    id="mediaScanStableSeconds"
                    class="form-control"
                    min="10"
                    max="3600"
                    step="1"
                    value="<?= (int)$mediaScanStableSeconds ?>">
                  <span class="input-group-text">secondi</span>
                </div>
                <div class="form-text">Evita l'import di cartelle ancora in copia.</div>
              </div>
            </div>

            <div class="grz-set__actions">
              <button type="button" class="btn btn-warning" id="btnSaveMediaScan">
                <i class="bi bi-floppy me-1" aria-hidden="true"></i>Salva configurazione
              </button>
              <span id="mediaScanSaveResult" class="small"></span>
            </div>
          </div>
        </div>

        <div class="grz-set__row" id="ignoredMediaSourcesBlock">
          <div class="grz-set__label">
            <h3>Cartelle ignorate <span class="badge bg-secondary" id="ignoredMediaSourcesCount"><?= $ignoredCount ?></span></h3>
            <p>Le cartelle degli album eliminati restano escluse dalla scansione finché non le ripristini.</p>
          </div>
          <div class="grz-set__control">
            <button type="button"
              class="btn btn-outline-secondary"
              id="btnManageIgnored"
              aria-expanded="false"
              <?= $ignoredCount === 0 ? 'disabled' : '' ?>>
              <i class="bi bi-sliders me-1" aria-hidden="true"></i>Gestisci
            </button>

            <div id="ignoredMediaManager" class="d-none mt-3">
              <div class="row g-2 align-items-end">
                <div class="col-12 col-lg">
                  <label class="form-label small text-muted mb-1" for="ignoredMediaSearch">Cerca percorso</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="search"
                      class="form-control"
                      id="ignoredMediaSearch"
                      placeholder="Artista, album o cartella"
                      autocomplete="off">
                  </div>
                </div>

                <div class="col-6 col-lg-auto">
                  <label class="form-label small text-muted mb-1" for="ignoredMediaPerPage">Mostra</label>
                  <select class="form-select form-select-sm" id="ignoredMediaPerPage">
                    <option value="20" selected>20</option>
                    <option value="50">50</option>
                  </select>
                </div>

                <div class="col-6 col-lg-auto">
                  <button type="button"
                    class="btn btn-sm btn-outline-danger w-100"
                    id="btnRestoreAllIgnored"
                    <?= $ignoredCount === 0 ? 'disabled' : '' ?>>
                    <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Ripristina tutte
                  </button>
                </div>
              </div>

              <div id="ignoredMediaSourcesResult" class="small mt-2"></div>

              <div class="list-group list-group-flush border rounded mt-3" id="ignoredMediaSourcesList">
                <div class="list-group-item small text-muted">
                  Apri il pannello per caricare le cartelle ignorate.
                </div>
              </div>

              <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2 mt-3">
                <span class="small text-muted" id="ignoredMediaPaginationInfo"></span>
                <div class="d-flex align-items-center gap-2">
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="btnIgnoredPrev" aria-label="Pagina precedente" disabled>
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                  </button>
                  <span class="small text-muted" id="ignoredMediaPageLabel">Pagina 0 di 0</span>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="btnIgnoredNext" aria-label="Pagina successiva" disabled>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ============================================================
           Manutenzione
      ============================================================ -->
      <section class="grz-set" id="set-manutenzione" aria-labelledby="set-manutenzione-h">
        <header class="grz-set__head">
          <h2 id="set-manutenzione-h">Manutenzione</h2>
        </header>

        <div class="grz-set__row">
          <div class="grz-set__label">
            <h3>Cache descrizioni album</h3>
            <p>Le note sull'album restano in cache 30 giorni, 3 se la fonte non le ha trovate.</p>
          </div>
          <div class="grz-set__control">
            <div class="grz-set__actions grz-set__actions--flush">
              <button class="btn btn-outline-secondary" id="btnClearWikiCache">
                <i class="bi bi-trash3 me-1" aria-hidden="true"></i>Svuota cache
              </button>
              <span id="wikiCacheResult" class="small ms-2"></span>
            </div>
            <details class="grz-set__more">
              <summary>Quando serve</summary>
              <p>Svuota la cache se più dischi mostrano "nessuna descrizione disponibile" anche se la fonte esiste, o dopo un aggiornamento della ricerca: ogni scheda rifarà la ricerca alla prossima apertura. Per un solo disco usa l'icona <i class="bi bi-arrow-clockwise" aria-label="aggiorna"></i> accanto a "Note sull'album" nella sua pagina.</p>
            </details>
          </div>
        </div>
      </section>

      <!-- ============================================================
           Servizi esterni
      ============================================================ -->
      <section class="grz-set" id="set-servizi-esterni" aria-labelledby="set-servizi-esterni-h">
        <header class="grz-set__head">
          <h2 id="set-servizi-esterni-h">Servizi esterni</h2>
          <p>Configura le credenziali opzionali senza modificare <code>.env</code>. Le chiavi salvate qui hanno priorità sulla configurazione del server e diventano attive senza riavviare Grizzly.</p>
        </header>

        <?php foreach ($externalApiUi as $serviceKey => $serviceUi): ?>
          <?php
            $serviceStatus = $externalApiServices[$serviceKey] ?? [
              'configured' => false,
              'source' => 'none',
              'masked' => '',
            ];
            $serviceSource = (string)($serviceStatus['source'] ?? 'none');
            $serviceMasked = (string)($serviceStatus['masked'] ?? '');

            if ($serviceSource === 'database') {
              $badgeClass = 'bg-success';
              $badgeText  = 'Configurato in Grizzly';
            } elseif ($serviceSource === 'server') {
              $badgeClass = 'bg-info text-dark';
              $badgeText  = 'Configurato dal server';
            } else {
              $badgeClass = 'bg-secondary';
              $badgeText  = 'Non configurato';
            }
          ?>
          <div class="grz-set__row api-service-row" data-service="<?= htmlspecialchars($serviceKey, ENT_QUOTES, 'UTF-8') ?>">
            <div class="grz-set__label">
              <h3><?= htmlspecialchars($serviceUi['title'], ENT_QUOTES, 'UTF-8') ?></h3>
              <p><?= htmlspecialchars($serviceUi['description'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="grz-set__control">
              <div class="grz-set__status api-credential-status mb-3">
                <span class="badge <?= $badgeClass ?> api-credential-badge"><?= htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($serviceSource === 'database' && $serviceMasked !== ''): ?>
                  <span class="font-monospace small text-muted ms-2 api-credential-masked"><?= htmlspecialchars($serviceMasked, ENT_QUOTES, 'UTF-8') ?></span>
                <?php else: ?>
                  <span class="font-monospace small text-muted ms-2 api-credential-masked"></span>
                <?php endif; ?>
              </div>

              <label class="form-label" for="apiCredential-<?= htmlspecialchars($serviceKey, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($serviceUi['field_label'], ENT_QUOTES, 'UTF-8') ?>
              </label>
              <div class="input-group">
                <input type="password"
                  id="apiCredential-<?= htmlspecialchars($serviceKey, ENT_QUOTES, 'UTF-8') ?>"
                  class="form-control font-monospace api-credential-input"
                  placeholder="<?= htmlspecialchars($serviceUi['placeholder'], ENT_QUOTES, 'UTF-8') ?>"
                  autocomplete="new-password"
                  spellcheck="false">
                <button type="button" class="btn btn-warning btn-save-api-credential">
                  <i class="bi bi-floppy me-1" aria-hidden="true"></i>Salva
                </button>
              </div>

              <div class="form-text">
                Il valore completo non viene mai mostrato nella pagina.
                <a href="<?= htmlspecialchars($serviceUi['help_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Ottieni la credenziale</a>
              </div>

              <div class="grz-set__actions">
                <button type="button"
                  class="btn btn-outline-danger btn-remove-api-credential <?= $serviceSource === 'database' ? '' : 'd-none' ?>">
                  <i class="bi bi-trash3 me-1" aria-hidden="true"></i>Rimuovi override
                </button>
                <span class="small api-credential-result"></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </section>

      <!-- ============================================================
           Backup e ripristino
      ============================================================ -->
      <section class="grz-set" id="set-backup" aria-labelledby="set-backup-h">
        <header class="grz-set__head">
          <h2 id="set-backup-h">Backup e ripristino</h2>
          <p>Per spostare l'archivio su un'altra installazione di Grizzly.</p>
        </header>

        <div class="grz-set__row">
          <div class="grz-set__label">
            <h3>Esporta</h3>
            <p>Dischi, artisti, tracce, playlist, biografie e immagini in un file <code>.zip</code>. I file audio non sono inclusi.</p>
          </div>
          <div class="grz-set__control">
            <div class="grz-set__actions grz-set__actions--flush">
              <a class="btn btn-warning" id="btnExport" href="<?= BASE_URL ?>/index.php?route=settings/export">
                <i class="bi bi-download me-1" aria-hidden="true"></i>Esporta archivio
              </a>
            </div>
            <details class="grz-set__more">
              <summary>File audio e percorsi</summary>
              <p>I file audio vanno spostati a parte, con "Migra file audio" o copiando la cartella. I percorsi della libreria esterna e delle cartelle ignorate sono salvati in forma relativa, quindi si possono rimappare su una cartella diversa nel nuovo server.</p>
            </details>
          </div>
        </div>

        <div class="grz-set__row">
          <div class="grz-set__label">
            <h3>Importa</h3>
            <p>Carica un file <code>.zip</code> creato con "Esporta".</p>
          </div>
          <div class="grz-set__control">
            <div class="grz-set__danger">
              <p class="grz-set__danger-text">
                <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
                L'import <strong>sostituisce completamente</strong> l'archivio attuale. Prima di procedere viene creato un backup automatico.
              </p>

              <form method="post"
                action="<?= BASE_URL ?>/index.php?route=settings/import"
                enctype="multipart/form-data"
                id="importForm">
                <div class="input-group">
                  <input type="file" name="archive" id="importFile" class="form-control" accept=".zip" required aria-label="File di backup .zip">
                  <button class="btn btn-outline-danger" type="submit" id="btnImport">
                    <i class="bi bi-upload me-1" aria-hidden="true"></i>Importa e sostituisci
                  </button>
                </div>
                <!-- conferma esplicita richiesta dal controller -->
                <input type="hidden" name="confirm" value="REPLACE">
              </form>

              <p class="grz-set__danger-note">Dopo l'import la scansione automatica resta disattivata: controlla la cartella da scansionare, salvala per rimappare i percorsi, poi riattivala.</p>
            </div>
          </div>
        </div>
      </section>

      <!-- ============================================================
           Informazioni: versione installata e documentazione
      ============================================================ -->
      <section class="grz-set" id="set-info" aria-labelledby="set-info-h">
        <header class="grz-set__head">
          <h2 id="set-info-h">Informazioni</h2>
        </header>

        <div class="grz-set__row">
          <div class="grz-set__label">
            <h3>Versione</h3>
            <p>Versione di Grizzly Music Archive installata su questo server.</p>
          </div>
          <div class="grz-set__control">
            <?php if ($appVersion !== ''): ?>
              <span class="font-monospace"><?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?></span>
            <?php else: ?>
              <span class="text-muted">Non disponibile: il file <code>VERSION</code> manca nella cartella dell'applicazione.</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="grz-set__row">
          <div class="grz-set__label">
            <h3>Documentazione</h3>
            <p>Installazione, aggiornamento e uso di ogni funzione.</p>
          </div>
          <div class="grz-set__control">
            <div class="grz-set__actions grz-set__actions--flush">
              <a class="btn btn-outline-secondary"
                href="<?= htmlspecialchars($appDocsUrl, ENT_QUOTES, 'UTF-8') ?>"
                target="_blank" rel="noopener noreferrer">
                <i class="bi bi-book me-1" aria-hidden="true"></i>Apri la documentazione
                <span class="visually-hidden">(si apre in una nuova scheda)</span>
              </a>
            </div>
          </div>
        </div>
      </section>

    </div>
  </div>
</div>

<script>
  // Indice laterale: scorrimento alla sezione senza cambiare l'hash.
  // Un cambio di hash genera popstate, che app.js interpreta come
  // navigazione e ricaricherebbe la pagina.
  (function() {
    var links = document.querySelectorAll('.grz-settings__navlink');
    if (!links.length) return;

    function setActive(id) {
      links.forEach(function(l) {
        var on = l.getAttribute('href') === '#' + id;
        l.classList.toggle('is-active', on);
        if (on) l.setAttribute('aria-current', 'true');
        else l.removeAttribute('aria-current');
      });
    }

    // Voce scelta con un clic: resta attiva finché l'utente non scorre di
    // nuovo la pagina. Senza questo blocco il calcolo per posizione, durante
    // e dopo lo scorrimento animato, potrebbe attivare un'altra voce: per
    // esempio "Backup" porta a fondo pagina, dove vale la regola che attiva
    // l'ultima sezione.
    var lockedId = null;

    links.forEach(function(link) {
      link.addEventListener('click', function(e) {
        e.preventDefault();
        var target = document.getElementById(link.getAttribute('href').slice(1));
        if (!target) return;
        lockedId = target.id;
        setActive(target.id);
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var top = target.getBoundingClientRect().top + window.pageYOffset - 88;
        window.scrollTo({ top: Math.max(0, top), behavior: reduce ? 'auto' : 'smooth' });
      });
    });

    // Qualsiasi interazione che può far scorrere la pagina (rotella, tocco,
    // trascinamento della barra, tastiera) sblocca la voce scelta. Il clic su
    // una voce dell'indice passa anch'esso da pointerdown/keydown, ma il suo
    // handler di click viene eseguito dopo e imposta il nuovo blocco.
    function unlock() { lockedId = null; }
    ['wheel', 'touchstart', 'pointerdown', 'keydown'].forEach(function(type) {
      window.addEventListener(type, unlock, { passive: true });
    });

    // Sezione attiva durante lo scorrimento.
    // A ogni frame di scroll si calcola quale sezione occupa la linea di
    // riferimento posta al 30% dell'altezza della finestra: è attiva l'ultima
    // sezione il cui inizio ha superato quella linea. Il calcolo dipende solo
    // dalla posizione corrente, quindi dà lo stesso risultato scorrendo verso
    // il basso e verso l'alto.
    // A fondo pagina si attiva l'ultima sezione: è più bassa della finestra e
    // il suo inizio non raggiungerebbe mai la linea di riferimento.
    var sections = Array.prototype.slice.call(document.querySelectorAll('.grz-set[id]'));

    function atPageBottom() {
      var doc = document.documentElement;
      return window.innerHeight + window.pageYOffset >= doc.scrollHeight - 2;
    }

    function updateActive() {
      if (!sections.length || lockedId) return;
      if (atPageBottom()) {
        setActive(sections[sections.length - 1].id);
        return;
      }
      var line = window.innerHeight * 0.3;
      var current = sections[0];
      for (var i = 0; i < sections.length; i++) {
        if (sections[i].getBoundingClientRect().top <= line) current = sections[i];
        else break;
      }
      setActive(current.id);
    }

    var ticking = false;
    function onScroll() {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function() {
        ticking = false;
        updateActive();
      });
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    updateActive();

    // Migrazione riuscita: lo script principale scrive il nuovo percorso
    // in "Cambia percorso". Il pannello si apre per renderlo visibile.
    var migrateResult = document.getElementById('migrateResult');
    var pathPanel = document.getElementById('audioPathPanel');
    if (migrateResult && pathPanel && window.MutationObserver) {
      new MutationObserver(function() {
        if (migrateResult.querySelector('.alert-success, .alert-warning')) pathPanel.open = true;
      }).observe(migrateResult, { childList: true });
    }

  })();
</script>

<script>
  // ── Feedback visivo durante la preparazione dell'export ─────
  (function() {
    var b = document.getElementById('btnExport');
    if (!b) return;
    b.addEventListener('click', function() {
      var original = b.innerHTML;
      window.__grizzlyIgnoredPollingPaused = true;
      b.classList.add('disabled');
      b.setAttribute('aria-disabled', 'true');
      b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Preparazione export…';
      // Il download avviene fuori dalla pagina: ripristina dopo qualche secondo.
      setTimeout(function() {
        b.classList.remove('disabled');
        b.removeAttribute('aria-disabled');
        b.innerHTML = original;
        window.__grizzlyIgnoredPollingPaused = false;
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

    // ── Credenziali servizi esterni ───────────────────────────
    function renderApiCredentialStatus(row, status) {
      if (!row || !status) return;

      var badge = row.querySelector('.api-credential-badge');
      var masked = row.querySelector('.api-credential-masked');
      var removeBtn = row.querySelector('.btn-remove-api-credential');
      var source = status.source || 'none';

      if (badge) {
        badge.className = 'badge api-credential-badge ';
        if (source === 'database') {
          badge.className += 'bg-success';
          badge.textContent = 'Configurato in Grizzly';
        } else if (source === 'server') {
          badge.className += 'bg-info text-dark';
          badge.textContent = 'Configurato dal server';
        } else {
          badge.className += 'bg-secondary';
          badge.textContent = 'Non configurato';
        }
      }

      if (masked) {
        masked.textContent = source === 'database' ? (status.masked || '') : '';
      }

      if (removeBtn) {
        removeBtn.classList.toggle('d-none', source !== 'database');
      }
    }

    var apiRows = document.querySelectorAll('.api-service-row');
    for (var apiIndex = 0; apiIndex < apiRows.length; apiIndex++) {
      (function(row) {
        var service = row.getAttribute('data-service') || '';
        var input = row.querySelector('.api-credential-input');
        var saveBtn = row.querySelector('.btn-save-api-credential');
        var removeBtn = row.querySelector('.btn-remove-api-credential');
        var result = row.querySelector('.api-credential-result');

        if (saveBtn) {
          saveBtn.addEventListener('click', function() {
            var value = input ? input.value.trim() : '';
            if (!value) {
              if (result) {
                result.className = 'small api-credential-result text-danger';
                result.textContent = 'Inserisci una credenziale prima di salvarla.';
              }
              return;
            }

            var originalHtml = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvataggio…';
            if (result) result.textContent = '';

            postJSON(
              BASE_URL + '/index.php?route=settings/save-api-credential',
              { service: service, value: value },
              function(data) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalHtml;

                if (!data.ok) {
                  if (result) {
                    result.className = 'small api-credential-result text-danger';
                    result.textContent = data.message || 'Errore nel salvataggio.';
                  }
                  return;
                }

                if (input) input.value = '';
                renderApiCredentialStatus(row, data.status || {});
                if (result) {
                  result.className = 'small api-credential-result text-success';
                  result.textContent = data.message || 'Credenziale salvata.';
                }
              }
            );
          });
        }

        if (removeBtn) {
          removeBtn.addEventListener('click', function() {
            if (!confirm('Rimuovere la credenziale salvata in Grizzly? Se il server ne contiene già una, tornerà automaticamente attiva.')) {
              return;
            }

            var originalHtml = removeBtn.innerHTML;
            removeBtn.disabled = true;
            removeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Rimozione…';
            if (result) result.textContent = '';

            postJSON(
              BASE_URL + '/index.php?route=settings/remove-api-credential',
              { service: service },
              function(data) {
                removeBtn.disabled = false;
                removeBtn.innerHTML = originalHtml;

                if (!data.ok) {
                  if (result) {
                    result.className = 'small api-credential-result text-danger';
                    result.textContent = data.message || 'Errore nella rimozione.';
                  }
                  return;
                }

                if (input) input.value = '';
                renderApiCredentialStatus(row, data.status || {});
                if (result) {
                  result.className = 'small api-credential-result text-success';
                  result.textContent = data.message || 'Credenziale rimossa.';
                }
              }
            );
          });
        }
      })(apiRows[apiIndex]);
    }

    // ── Scanner automatico libreria ─────────────────────────────
    var mediaScanToggle = document.getElementById('mediaScanEnabled');
    var btnSaveMediaScan = document.getElementById('btnSaveMediaScan');
    var mediaScanResult = document.getElementById('mediaScanSaveResult');
    var mediaScanBadge = document.getElementById('mediaScanStatusBadge');
    var committedMediaScanEnabled = mediaScanToggle ? mediaScanToggle.checked : false;
    var mediaScanWorkerAlive = <?= !empty($mediaScanWorkerAlive) ? 'true' : 'false' ?>;

    function renderMediaScanState(enabled, workerAlive, heartbeatAge) {
      if (typeof workerAlive === 'boolean') {
        mediaScanWorkerAlive = workerAlive;
      }

      if (!mediaScanBadge) return;

      var alive = !!mediaScanWorkerAlive;
      var text = '';
      var className = 'badge ';

      if (enabled) {
        if (alive) {
          className += 'bg-success';
          text = 'Attiva · worker in esecuzione';
        } else {
          className += 'bg-warning text-dark';
          text = 'Attiva · worker non raggiungibile';
        }
      } else {
        className += 'bg-secondary';
        text = alive
          ? 'Disattivata · worker in attesa'
          : 'Disattivata · worker non raggiungibile';
      }

      mediaScanBadge.className = className;
      mediaScanBadge.textContent = text;

      if (heartbeatAge !== null && heartbeatAge !== undefined) {
        mediaScanBadge.title = 'Ultimo heartbeat ' + String(heartbeatAge) + 's fa';
      } else {
        mediaScanBadge.title = 'Heartbeat non disponibile';
      }
    }

    function refreshMediaScanRuntimeStatus() {
      if (document.hidden || !document.getElementById('set-scansione')) return;

      fetch(BASE_URL + '/index.php?route=settings/scanner-status', {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
      .then(function(r) {
        return r.json();
      })
      .then(function(data) {
        if (!data.ok) return;

        committedMediaScanEnabled = !!data.enabled;
        if (mediaScanToggle) {
          mediaScanToggle.checked = committedMediaScanEnabled;
        }

        renderMediaScanState(
          committedMediaScanEnabled,
          !!data.worker_alive,
          data.heartbeat_age
        );
      })
      .catch(function() {
        // Il polling runtime non deve interferire con il resto della pagina.
      });
    }

    // SPA-lite: un solo timer di stato worker per volta.
    if (window.__grizzlyScannerRuntimeTimer) {
      clearInterval(window.__grizzlyScannerRuntimeTimer);
      window.__grizzlyScannerRuntimeTimer = null;
    }

    window.__grizzlyScannerRuntimeTimer = setInterval(function() {
      if (!document.getElementById('set-scansione')) {
        clearInterval(window.__grizzlyScannerRuntimeTimer);
        window.__grizzlyScannerRuntimeTimer = null;
        return;
      }

      refreshMediaScanRuntimeStatus();
    }, 10000);

    // Lo switch ON/OFF è immediato e modifica SOLO media_scan_enabled.
    // In questo modo disabilitare lo scanner non salva per errore eventuali
    // modifiche non ancora confermate a path/intervallo/stabilità.
    if (mediaScanToggle) {
      mediaScanToggle.addEventListener('change', function() {
        var requested = mediaScanToggle.checked;
        mediaScanToggle.disabled = true;
        mediaScanResult.textContent = '';

        postJSON(
          BASE_URL + '/index.php?route=settings/toggle-media-scan',
          { enabled: requested ? '1' : '0' },
          function(data) {
            mediaScanToggle.disabled = false;

            if (!data.ok) {
              mediaScanToggle.checked = committedMediaScanEnabled;
              renderMediaScanState(committedMediaScanEnabled);
              mediaScanResult.className = 'small text-danger';
              mediaScanResult.innerHTML = '<i class="bi bi-x-circle me-1"></i>' +
                escHtml(data.message || 'Errore nel cambio di stato.');
              return;
            }

            committedMediaScanEnabled = !!data.enabled;
            mediaScanToggle.checked = committedMediaScanEnabled;
            renderMediaScanState(committedMediaScanEnabled);
            refreshMediaScanRuntimeStatus();
            mediaScanResult.className = 'small text-success';
            mediaScanResult.innerHTML = '<i class="bi bi-check-circle me-1"></i>' +
              escHtml(data.message || 'Stato scanner aggiornato.');
          }
        );
      });
    }

    // Path/intervallo/stabilità restano un salvataggio esplicito.
    if (btnSaveMediaScan) {
      btnSaveMediaScan.onclick = function() {
        var enabled = mediaScanToggle ? mediaScanToggle.checked : false;
        var path = document.getElementById('mediaScanPath').value.trim();
        var interval = document.getElementById('mediaScanInterval').value;
        var stable = document.getElementById('mediaScanStableSeconds').value;

        if (enabled && !path) {
          mediaScanResult.className = 'small text-danger';
          mediaScanResult.innerHTML = '<i class="bi bi-x-circle me-1"></i>Indica la cartella da scansionare.';
          return;
        }

        var originalHtml = btnSaveMediaScan.innerHTML;
        btnSaveMediaScan.disabled = true;
        btnSaveMediaScan.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvataggio…';
        mediaScanResult.textContent = '';

        postJSON(
          BASE_URL + '/index.php?route=settings/save-media-scan',
          {
            media_scan_path: path,
            media_scan_interval: interval,
            media_scan_stable_seconds: stable
          },
          function(data) {
            btnSaveMediaScan.disabled = false;
            btnSaveMediaScan.innerHTML = originalHtml;

            if (!data.ok) {
              mediaScanResult.className = 'small text-danger';
              mediaScanResult.innerHTML = '<i class="bi bi-x-circle me-1"></i>' +
                escHtml(data.message || 'Errore nel salvataggio.');
              return;
            }

            if (data.settings) {
              document.getElementById('mediaScanPath').value = data.settings.path || '';
              document.getElementById('mediaScanInterval').value = data.settings.interval;
              document.getElementById('mediaScanStableSeconds').value = data.settings.stable_seconds;
              committedMediaScanEnabled = !!data.settings.enabled;
              if (mediaScanToggle) mediaScanToggle.checked = committedMediaScanEnabled;
              renderMediaScanState(committedMediaScanEnabled);
            }

            mediaScanResult.className = 'small text-success';
            mediaScanResult.innerHTML = '<i class="bi bi-check-circle me-1"></i>' +
              escHtml(data.message || 'Impostazioni salvate.');
          }
        );
      };
    }

    // ── Gestione paginata cartelle ignorate ─────────────────────
    var ignoredManager = document.getElementById('ignoredMediaManager');
    var btnManageIgnored = document.getElementById('btnManageIgnored');
    var ignoredSearch = document.getElementById('ignoredMediaSearch');
    var ignoredPerPage = document.getElementById('ignoredMediaPerPage');
    var ignoredList = document.getElementById('ignoredMediaSourcesList');
    var ignoredResult = document.getElementById('ignoredMediaSourcesResult');
    var ignoredCount = document.getElementById('ignoredMediaSourcesCount');
    var ignoredInfo = document.getElementById('ignoredMediaPaginationInfo');
    var ignoredPageLabel = document.getElementById('ignoredMediaPageLabel');
    var btnIgnoredPrev = document.getElementById('btnIgnoredPrev');
    var btnIgnoredNext = document.getElementById('btnIgnoredNext');
    var btnRestoreAllIgnored = document.getElementById('btnRestoreAllIgnored');

    var ignoredState = {
      page: 1,
      perPage: 20,
      query: '',
      total: parseInt(ignoredCount ? ignoredCount.textContent : '0', 10) || 0,
      pages: 0,
      loading: false
    };

    function updateIgnoredSummary(total) {
      ignoredState.total = Math.max(0, parseInt(total, 10) || 0);

      if (ignoredCount) {
        ignoredCount.textContent = ignoredState.total;
      }

      if (btnManageIgnored) {
        btnManageIgnored.disabled = ignoredState.total === 0;
      }

      if (btnRestoreAllIgnored) {
        btnRestoreAllIgnored.disabled = ignoredState.total === 0;
      }
    }

    function renderIgnoredRows(rows) {
      if (!ignoredList) return;

      if (!rows || rows.length === 0) {
        ignoredList.innerHTML =
          '<div class="list-group-item small text-muted fst-italic">Nessuna cartella trovata.</div>';
        return;
      }

      ignoredList.innerHTML = rows.map(function(row) {
        return '' +
          '<div class="list-group-item d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2">' +
            '<div class="min-width-0">' +
              '<div class="font-monospace small text-break">' + escHtml(row.source_path || '') + '</div>' +
              '<div class="text-muted small">Ignorata il ' + escHtml(row.created_at || '') + '</div>' +
            '</div>' +
            '<button type="button" class="btn btn-sm btn-outline-warning flex-shrink-0 btn-restore-ignored" data-id="' +
              String(parseInt(row.id, 10) || 0) + '">' +
              '<i class="bi bi-arrow-counterclockwise me-1"></i>Ripristina' +
            '</button>' +
          '</div>';
      }).join('');
    }

    function renderIgnoredPagination(data) {
      ignoredState.page = parseInt(data.page, 10) || 1;
      ignoredState.pages = parseInt(data.pages, 10) || 0;
      ignoredState.perPage = parseInt(data.per_page, 10) || 20;

      updateIgnoredSummary(data.total);

      var rows = data.rows || [];
      var first = ignoredState.total > 0
        ? ((ignoredState.page - 1) * ignoredState.perPage) + 1
        : 0;
      var last = ignoredState.total > 0
        ? Math.min(first + rows.length - 1, ignoredState.total)
        : 0;

      if (ignoredInfo) {
        ignoredInfo.textContent = ignoredState.total > 0
          ? ('Visualizzate ' + first + '–' + last + ' di ' + ignoredState.total)
          : 'Nessuna cartella ignorata';
      }

      if (ignoredPageLabel) {
        ignoredPageLabel.textContent = ignoredState.pages > 0
          ? ('Pagina ' + ignoredState.page + ' di ' + ignoredState.pages)
          : 'Pagina 0 di 0';
      }

      if (btnIgnoredPrev) {
        btnIgnoredPrev.disabled = ignoredState.loading || ignoredState.page <= 1 || ignoredState.pages === 0;
      }

      if (btnIgnoredNext) {
        btnIgnoredNext.disabled = ignoredState.loading ||
          ignoredState.pages === 0 ||
          ignoredState.page >= ignoredState.pages;
      }
    }

    function loadIgnoredMediaSources(page, silent) {
      if (!ignoredManager || ignoredState.loading) return;

      ignoredState.loading = true;
      ignoredState.page = Math.max(1, parseInt(page, 10) || 1);
      silent = !!silent;

      // Durante il polling automatico non sostituire la lista con lo spinner:
      // evita il flash visivo ogni 10 secondi mentre "Gestisci" è aperto.
      if (ignoredList && !silent) {
        ignoredList.innerHTML =
          '<div class="list-group-item small text-muted">' +
          '<span class="spinner-border spinner-border-sm me-2"></span>Caricamento…</div>';
      }

      if (btnIgnoredPrev) btnIgnoredPrev.disabled = true;
      if (btnIgnoredNext) btnIgnoredNext.disabled = true;

      var url = BASE_URL + '/index.php?route=settings/ignored-list' +
        '&q=' + encodeURIComponent(ignoredState.query) +
        '&page=' + encodeURIComponent(String(ignoredState.page)) +
        '&per_page=' + encodeURIComponent(String(ignoredState.perPage));

      fetch(url, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
      .then(function(r) {
        return r.json();
      })
      .then(function(data) {
        ignoredState.loading = false;

        if (!data.ok) {
          if (ignoredList) {
            ignoredList.innerHTML =
              '<div class="list-group-item small text-danger">' +
              escHtml(data.message || 'Errore durante il caricamento.') + '</div>';
          }
          return;
        }

        renderIgnoredRows(data.rows || []);
        renderIgnoredPagination(data);
      })
      .catch(function(e) {
        ignoredState.loading = false;
        if (ignoredList) {
          ignoredList.innerHTML =
            '<div class="list-group-item small text-danger">' + escHtml(e.message) + '</div>';
        }
      });
    }

    if (btnManageIgnored && ignoredManager) {
      btnManageIgnored.addEventListener('click', function() {
        var opening = ignoredManager.classList.contains('d-none');

        ignoredManager.classList.toggle('d-none', !opening);
        btnManageIgnored.setAttribute('aria-expanded', opening ? 'true' : 'false');
        btnManageIgnored.innerHTML = opening
          ? '<i class="bi bi-x-lg me-1"></i>Chiudi'
          : '<i class="bi bi-sliders me-1"></i>Gestisci';

        if (opening) {
          ignoredState.page = 1;
          ignoredState.query = ignoredSearch ? ignoredSearch.value.trim() : '';
          ignoredState.perPage = ignoredPerPage ? parseInt(ignoredPerPage.value, 10) || 20 : 20;
          loadIgnoredMediaSources(1);
        }
      });
    }

    var ignoredSearchTimer = null;
    if (ignoredSearch) {
      ignoredSearch.addEventListener('input', function() {
        clearTimeout(ignoredSearchTimer);
        ignoredSearchTimer = setTimeout(function() {
          ignoredState.query = ignoredSearch.value.trim();
          loadIgnoredMediaSources(1);
        }, 250);
      });
    }

    if (ignoredPerPage) {
      ignoredPerPage.addEventListener('change', function() {
        ignoredState.perPage = parseInt(ignoredPerPage.value, 10) === 50 ? 50 : 20;
        loadIgnoredMediaSources(1);
      });
    }

    if (btnIgnoredPrev) {
      btnIgnoredPrev.addEventListener('click', function() {
        if (ignoredState.page > 1) {
          loadIgnoredMediaSources(ignoredState.page - 1);
        }
      });
    }

    if (btnIgnoredNext) {
      btnIgnoredNext.addEventListener('click', function() {
        if (ignoredState.pages > 0 && ignoredState.page < ignoredState.pages) {
          loadIgnoredMediaSources(ignoredState.page + 1);
        }
      });
    }

    if (ignoredList) {
      ignoredList.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-restore-ignored');
        if (!btn) return;

        var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
        if (id <= 0) return;

        var originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Ripristino…';

        if (ignoredResult) {
          ignoredResult.textContent = '';
        }

        postJSON(
          BASE_URL + '/index.php?route=settings/restore-ignored',
          { id: String(id) },
          function(data) {
            if (!data.ok) {
              btn.disabled = false;
              btn.innerHTML = originalHtml;
              if (ignoredResult) {
                ignoredResult.className = 'small mt-2 text-danger';
                ignoredResult.innerHTML = '<i class="bi bi-x-circle me-1"></i>' +
                  escHtml(data.message || 'Errore durante il ripristino.');
              }
              return;
            }

            updateIgnoredSummary(data.remaining);

            if (ignoredResult) {
              ignoredResult.className = 'small mt-2 text-success';
              ignoredResult.innerHTML = '<i class="bi bi-check-circle me-1"></i>' +
                escHtml(data.message || 'Esclusione rimossa.');
            }

            loadIgnoredMediaSources(ignoredState.page);
          }
        );
      });
    }

    if (btnRestoreAllIgnored) {
      btnRestoreAllIgnored.addEventListener('click', function() {
        if (ignoredState.total <= 0) return;

        var ok = confirm(
          'Ripristinare tutte le ' + ignoredState.total + ' cartelle ignorate?\n\n' +
          "Alla prossima scansione torneranno tutte eleggibili per l'import automatico."
        );
        if (!ok) return;

        var originalHtml = btnRestoreAllIgnored.innerHTML;
        btnRestoreAllIgnored.disabled = true;
        btnRestoreAllIgnored.innerHTML =
          '<span class="spinner-border spinner-border-sm me-1"></span>Ripristino…';

        postJSON(
          BASE_URL + '/index.php?route=settings/restore-all-ignored',
          { confirm: 'RESTORE_ALL' },
          function(data) {
            btnRestoreAllIgnored.innerHTML = originalHtml;

            if (!data.ok) {
              btnRestoreAllIgnored.disabled = false;
              if (ignoredResult) {
                ignoredResult.className = 'small mt-2 text-danger';
                ignoredResult.innerHTML = '<i class="bi bi-x-circle me-1"></i>' +
                  escHtml(data.message || 'Errore durante il ripristino.');
              }
              return;
            }

            updateIgnoredSummary(0);

            if (ignoredResult) {
              ignoredResult.className = 'small mt-2 text-success';
              ignoredResult.innerHTML = '<i class="bi bi-check-circle me-1"></i>' +
                escHtml(data.message || 'Tutte le esclusioni sono state rimosse.');
            }

            loadIgnoredMediaSources(1);
          }
        );
      });
    }

    // Aggiornamento live del badge "Cartelle ignorate".
    // Interroga la route paginata già esistente ogni 10 secondi soltanto
    // quando la scheda del browser è visibile. Se il pannello Gestisci è
    // aperto, aggiorna anche l'elenco; altrimenti aggiorna solo il conteggio.
    function refreshIgnoredMediaSourcesLive() {
      if (window.__grizzlyIgnoredPollingPaused || document.hidden || ignoredState.loading) {
        return;
      }

      var managerOpen = ignoredManager && !ignoredManager.classList.contains('d-none');

      if (managerOpen) {
        ignoredState.query = ignoredSearch ? ignoredSearch.value.trim() : '';
        ignoredState.perPage = ignoredPerPage ? (parseInt(ignoredPerPage.value, 10) === 50 ? 50 : 20) : 20;
        loadIgnoredMediaSources(ignoredState.page || 1, true);
        return;
      }

      var url = BASE_URL + '/index.php?route=settings/ignored-list&page=1&per_page=20';

      fetch(url, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
      .then(function(r) {
        return r.json();
      })
      .then(function(data) {
        if (!data.ok) return;
        updateIgnoredSummary(data.total);
      })
      .catch(function() {
        // Il polling live non deve interferire con il resto della pagina.
      });
    }

    // SPA-lite riesegue gli script di Settings a ogni ingresso nella pagina.
    // Manteniamo UN solo timer globale: prima eliminiamo quello della visita
    // precedente, altrimenti i polling si accumulano.
    if (window.__grizzlyIgnoredLiveTimer) {
      clearInterval(window.__grizzlyIgnoredLiveTimer);
      window.__grizzlyIgnoredLiveTimer = null;
    }

    window.__grizzlyIgnoredLiveTimer = setInterval(function() {
      // Se la SPA ha già sostituito la pagina Settings, il timer non serve più.
      if (!document.getElementById('ignoredMediaSourcesBlock')) {
        clearInterval(window.__grizzlyIgnoredLiveTimer);
        window.__grizzlyIgnoredLiveTimer = null;
        return;
      }
      refreshIgnoredMediaSourcesLive();
    }, 10000);

    // Anche il listener visibilitychange deve essere unico tra una visita SPA
    // e la successiva.
    if (window.__grizzlyIgnoredVisibilityHandler) {
      document.removeEventListener('visibilitychange', window.__grizzlyIgnoredVisibilityHandler);
    }

    window.__grizzlyIgnoredVisibilityHandler = function() {
      if (!document.hidden && document.getElementById('ignoredMediaSourcesBlock')) {
        refreshIgnoredMediaSourcesLive();
      }
    };

    document.addEventListener('visibilitychange', window.__grizzlyIgnoredVisibilityHandler);

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

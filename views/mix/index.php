<?php
/**
 * Instant Mix: criteri a sinistra (o in alto su mobile), risultato sotto.
 *
 * Variabili dal controller:
 *   $criteria  criteri normalizzati dall'URL (senza seed)
 *   $hasSeed   true se l'URL contiene un seed: il JS rigenera quel mix
 *   $genres    [id, name, tracks] generi con tracce ascoltabili
 *   $decades   [decade, label, tracks]
 *   $presets   numeri di tracce proposti
 *   $minYear, $maxYear
 *
 * Nessun <form>: app.js intercetta i form GET e li trasforma in
 * navigazione SPA, mentre qui generazione e conteggio sono chiamate AJAX.
 *
 * Posizionare in: views/mix/index.php
 */
$selectedGenres = $criteria['g'];
$nValue         = (int)$criteria['n'];
$presetList     = $presets;
if (!in_array($nValue, $presetList, true)) {
  $presetList[] = $nValue;
  sort($presetList);
}
$seedParam = $hasSeed ? strtolower((string)$_GET['seed']) : '';

// Generi selezionati in testa, poi gli altri in ordine alfabetico
usort($genres, function (array $a, array $b) use ($selectedGenres): int {
  $sa = in_array($a['id'], $selectedGenres, true) ? 0 : 1;
  $sb = in_array($b['id'], $selectedGenres, true) ? 0 : 1;
  if ($sa !== $sb) {
    return $sa <=> $sb;
  }
  return strcasecmp($a['name'], $b['name']);
});

require BASE_PATH . '/views/layout/header.php';
?>

<div class="grz-mix" id="instant-mix"
  data-generate-url="<?= htmlspecialchars(BASE_URL . '/index.php?route=mix/generate') ?>"
  data-count-url="<?= htmlspecialchars(BASE_URL . '/index.php?route=mix/count') ?>"
  data-page-url="<?= htmlspecialchars(BASE_URL . '/index.php?route=mix') ?>"
  data-save-url="<?= htmlspecialchars(BASE_URL . '/index.php?route=playlists/add-tracks') ?>"
  data-playlist-url="<?= htmlspecialchars(BASE_URL . '/index.php?route=playlists/detail/') ?>"
  data-seed="<?= htmlspecialchars($seedParam) ?>">

  <header class="grz-mix__head">
    <h1 class="grz-mix__title">Instant Mix</h1>
    <p class="grz-mix__lead">Scegli i criteri e genera una selezione di brani dal tuo archivio, pronta da ascoltare. Entrano solo le tracce con un file audio.</p>
  </header>

  <!-- ── Criteri ── -->
  <section class="grz-mix-panel" aria-label="Criteri del mix">

    <div class="grz-mix-field">
      <div class="grz-mix-field__label">
        <span>Genere</span>
        <button type="button" class="grz-mix-link" data-mix-clear-genres <?= empty($selectedGenres) ? 'hidden' : '' ?>>Azzera</button>
      </div>
      <div class="grz-mix-field__body">
        <?php if (empty($genres)): ?>
          <p class="grz-mix-muted mb-0">Nessun genere con tracce ascoltabili.</p>
        <?php else: ?>
          <input type="search" class="form-control form-control-sm grz-mix-genre-search"
            placeholder="Filtra i generi…" aria-label="Filtra i generi" data-mix-genre-search>
          <div class="grz-mix-chips grz-mix-chips--genres" data-mix-genres>
            <?php foreach ($genres as $g):
              $checked = in_array($g['id'], $selectedGenres, true);
            ?>
              <label class="grz-mix-chip" data-name="<?= htmlspecialchars(function_exists('mb_strtolower') ? mb_strtolower($g['name']) : strtolower($g['name'])) ?>">
                <input type="checkbox" name="g" value="<?= (int)$g['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                <span class="grz-mix-chip__text"><?= htmlspecialchars($g['name']) ?></span>
                <span class="grz-mix-chip__count"><?= (int)$g['tracks'] ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="grz-mix-hint">Nessun genere selezionato significa tutto l'archivio. Più generi si sommano.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="grz-mix-field">
      <div class="grz-mix-field__label"><span>Periodo</span></div>
      <div class="grz-mix-field__body">
        <?php if (!empty($decades)): ?>
          <div class="grz-mix-chips" data-mix-decades>
            <?php foreach ($decades as $d): ?>
              <button type="button" class="grz-mix-chip grz-mix-chip--btn"
                data-from="<?= (int)$d['decade'] ?>" data-to="<?= (int)$d['decade'] + 9 ?>"
                aria-pressed="false"
                title="<?= (int)$d['tracks'] ?> tracce ascoltabili">
                <span class="grz-mix-chip__text"><?= htmlspecialchars($d['label']) ?></span>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="grz-mix-years">
          <label class="grz-mix-years__item">
            <span>da</span>
            <input type="number" class="form-control form-control-sm" name="from" inputmode="numeric"
              min="<?= $minYear ?>" max="<?= $maxYear ?>" placeholder="anno"
              value="<?= $criteria['from'] !== null ? (int)$criteria['from'] : '' ?>">
          </label>
          <label class="grz-mix-years__item">
            <span>a</span>
            <input type="number" class="form-control form-control-sm" name="to" inputmode="numeric"
              min="<?= $minYear ?>" max="<?= $maxYear ?>" placeholder="anno"
              value="<?= $criteria['to'] !== null ? (int)$criteria['to'] : '' ?>">
          </label>
          <button type="button" class="grz-mix-link" data-mix-clear-years>Qualsiasi anno</button>
        </div>
        <p class="grz-mix-hint">L'anno è quello di prima pubblicazione indicato nella scheda del disco. Per un solo anno, scrivi lo stesso valore nei due campi.</p>
      </div>
    </div>

    <div class="grz-mix-field grz-mix-field--inline">
      <div class="grz-mix-field__label"><span>Tracce</span></div>
      <div class="grz-mix-field__body">
        <div class="grz-mix-seg" role="radiogroup" aria-label="Numero di tracce">
          <?php foreach ($presetList as $p): ?>
            <label class="grz-mix-seg__opt">
              <input type="radio" name="n" value="<?= (int)$p ?>" <?= $p === $nValue ? 'checked' : '' ?>>
              <span><?= (int)$p ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="grz-mix-field grz-mix-field--inline">
      <div class="grz-mix-field__label"><span>Ordine</span></div>
      <div class="grz-mix-field__body">
        <div class="grz-mix-seg" role="radiogroup" aria-label="Ordine delle tracce">
          <label class="grz-mix-seg__opt">
            <input type="radio" name="order" value="random" <?= $criteria['order'] === 'random' ? 'checked' : '' ?>>
            <span><i class="bi bi-shuffle" aria-hidden="true"></i> Casuale</span>
          </label>
          <label class="grz-mix-seg__opt">
            <input type="radio" name="order" value="chrono" <?= $criteria['order'] === 'chrono' ? 'checked' : '' ?>>
            <span><i class="bi bi-sort-numeric-down" aria-hidden="true"></i> Cronologico</span>
          </label>
        </div>
      </div>
    </div>

    <div class="grz-mix-panel__foot">
      <p class="grz-mix-count" data-mix-count aria-live="polite">&nbsp;</p>
      <button type="button" class="btn btn-warning fw-semibold grz-mix-generate" data-mix-generate>
        <i class="bi bi-shuffle me-1" aria-hidden="true"></i>Genera mix
      </button>
    </div>
  </section>

  <!-- ── Risultato (riempito da instant-mix.js) ── -->
  <section class="grz-mix-result" data-mix-result aria-live="polite">
    <div class="grz-mix-placeholder">
      <i class="bi bi-vinyl" aria-hidden="true"></i>
      <p>Il mix compare qui. Puoi ascoltarlo subito, accodarlo o salvarlo come playlist.</p>
    </div>
  </section>

  <!-- ── Salva come playlist ── -->
  <div class="modal fade" id="instantMixSaveModal" tabindex="-1" aria-labelledby="instantMixSaveTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title fs-5" id="instantMixSaveTitle">Salva come playlist</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
        </div>
        <div class="modal-body">
          <label for="instantMixSaveName" class="form-label">Nome della playlist</label>
          <input type="text" class="form-control" id="instantMixSaveName" maxlength="150" data-mix-save-name>
          <p class="grz-mix-hint mt-2 mb-0">La playlist conterrà queste tracce in quest'ordine e non cambierà più da sola: puoi modificarla come qualsiasi altra playlist.</p>
          <div class="alert alert-danger py-2 mt-3 mb-0" data-mix-save-error hidden></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
          <button type="button" class="btn btn-warning fw-semibold" data-mix-save-confirm>Salva</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  // Alla prima apertura instant-mix.js non è ancora caricato (sta nel
  // footer) e si inizializza da solo; nella navigazione SPA questo script
  // viene rieseguito da app.js e aggancia la pagina nuova.
  if (window.InstantMix && typeof window.InstantMix.init === 'function') {
    window.InstantMix.init();
  }
</script>

<?php require BASE_PATH . '/views/layout/footer.php'; ?>

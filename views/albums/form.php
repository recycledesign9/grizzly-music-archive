<?php

/** @var array $album */
/** @var array $old */
/** @var array $formats */
/** @var array $genres */
/** @var array $labels */
/** @var array $artists */
/** @var bool $isEdit */

$isEdit    = !empty($album['id']);

$pageTitle = $isEdit ? 'Modifica: ' . htmlspecialchars($album['title']) : 'Aggiungi disco';
$action    = $isEdit
  ? BASE_URL . '/index.php?route=albums/save/' . $album['id']
  : BASE_URL . '/index.php?route=albums/save';

function formVal($key, $album, $old, $default = '')
{
  if (isset($old[$key]) && $old[$key] !== '') {
    return htmlspecialchars((string)$old[$key], ENT_QUOTES, 'UTF-8');
  }

  if (isset($album[$key])) {
    return htmlspecialchars((string)$album[$key], ENT_QUOTES, 'UTF-8');
  }

  return htmlspecialchars((string)$default, ENT_QUOTES, 'UTF-8');
}

function selectedId($key, $album, $old)
{
  if (isset($old[$key]) && $old[$key] !== '') {
    return (int)$old[$key];
  }

  if (isset($album[$key])) {
    return (int)$album[$key];
  }

  return 0;
}

/**
 * Formatta una durata in secondi come m:ss (h:mm:ss oltre l'ora).
 * Stringa vuota se la durata non è valorizzata.
 */
function formDuration($seconds)
{
  if ($seconds === null || $seconds === '' || !is_numeric($seconds)) {
    return '';
  }
  $s = (int)$seconds;
  if ($s <= 0) {
    return '';
  }
  $h = intdiv($s, 3600);
  $m = intdiv($s % 3600, 60);
  $r = $s % 60;
  if ($h > 0) {
    return $h . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$r, 2, '0', STR_PAD_LEFT);
  }
  return $m . ':' . str_pad((string)$r, 2, '0', STR_PAD_LEFT);
}

/**
 * Nome visibile di un'entità (genere/etichetta) a partire dall'id,
 * con fallback sul valore "nuovo" inviato in precedenza.
 */
function comboName($items, $currentId, $fallback)
{
  if ($currentId > 0) {
    foreach ($items as $it) {
      if ((int)$it['id'] === $currentId) {
        return (string)$it['name'];
      }
    }
  }
  return (string)$fallback;
}

/**
 * Chiave CSS del formato per il punto colore del chip.
 */
function formatKey($name)
{
  $n = strtolower(trim((string)$name));
  if ($n === 'vinile') return 'vinile';
  if ($n === 'cd') return 'cd';
  if ($n === 'musicassetta') return 'cassetta';
  if ($n === 'digital') return 'digital';
  return 'altro';
}

require BASE_PATH . '/views/layout/header.php';

$currentArtistId  = selectedId('artist_id', $album, $old);

// Formati correnti (checkbox multipli): priorità al vecchio input
// del form (validazione fallita), poi ai formati della scheda in
// modifica, con fallback sulla colonna legacy format_id.
$currentFormatIds = [];
if (!empty($old['format_ids']) && is_array($old['format_ids'])) {
  $currentFormatIds = array_map('intval', $old['format_ids']);
} elseif (!empty($album['formats'])) {
  $currentFormatIds = array_map('intval', array_column($album['formats'], 'id'));
} elseif (!empty($album['format_id'])) {
  $currentFormatIds = [(int)$album['format_id']];
}
$currentGenreId   = selectedId('genre_id', $album, $old);
$currentLabelId   = selectedId('label_id', $album, $old);
$currentCondition = isset($old['condition'])
  ? $old['condition']
  : ($album['condition'] ?? 'Very Good');

$artistNameValue = isset($old['artist_name'])
  ? $old['artist_name']
  : ($album['artist_name'] ?? '');

$artistDisplayValue = $artistNameValue;
if ($currentArtistId) {
  $artistIdx = array_search($currentArtistId, array_map('intval', array_column($artists, 'id')), true);
  if ($artistIdx !== false && isset($artists[$artistIdx]['name'])) {
    $artistDisplayValue = $artists[$artistIdx]['name'];
  }
}

$genreDisplayValue = comboName($genres, (int)$currentGenreId, $old['genre_new'] ?? '');
$labelDisplayValue = comboName($labels, (int)$currentLabelId, $old['label_new'] ?? '');

// Scala di conservazione (Goldmine). Valori invariati rispetto al
// salvataggio: cambia solo la resa (sigla sul pulsante, descrizione sotto).
$conditions = [
  'Mint'           => ['code' => 'M',   'label' => 'Nuovo (M)'],
  'Near Mint'      => ['code' => 'NM',  'label' => 'Come nuovo (NM o M-)'],
  'Very Good Plus' => ['code' => 'VG+', 'label' => 'Ottimo (VG+)'],
  'Very Good'      => ['code' => 'VG',  'label' => 'Molto buono (VG)'],
  'Good Plus'      => ['code' => 'G+',  'label' => 'Più che buono (G+)'],
  'Good'           => ['code' => 'G',   'label' => 'Buono (G)'],
  'Fair'           => ['code' => 'F',   'label' => 'Pessimo (F)'],
  'Poor'           => ['code' => 'P',   'label' => 'Scarso (P)'],
];
if (!isset($conditions[$currentCondition])) {
  $currentCondition = 'Very Good';
}

// Tracklist iniziale: vecchio input (validazione fallita), tracce
// della scheda in modifica, altrimenti una riga vuota.
$trackRows    = [];
$oldTitles    = $old['track_title'] ?? [];
$oldDurations = $old['track_duration'] ?? [];
if (!empty($oldTitles)) {
  foreach ($oldTitles as $i => $title) {
    $trackRows[] = [
      'id'       => '',
      'title'    => (string)$title,
      'duration' => $oldDurations[$i] ?? '',
    ];
  }
} elseif (!empty($tracks)) {
  foreach ($tracks as $t) {
    $trackRows[] = [
      'id'       => (int)($t['id'] ?? 0),
      'title'    => (string)($t['title'] ?? ''),
      'duration' => isset($t['duration_sec']) ? (int)$t['duration_sec'] : '',
    ];
  }
} else {
  $trackRows[] = ['id' => '', 'title' => '', 'duration' => ''];
}

// Cover iniziale
$placeholderSrc = BASE_URL . '/public/img/placeholder.png';
$coverSrc = $placeholderSrc;
if (!empty($old['cover_local_new'])) {
  $coverSrc = BASE_URL . '/public/uploads/' . $old['cover_local_new'];
} elseif (!empty($old['cover_url'])) {
  $coverSrc = $old['cover_url'];
} elseif ($isEdit && !empty($album['cover_local'])) {
  $coverSrc = BASE_URL . '/public/uploads/' . $album['cover_local'];
} elseif ($isEdit && !empty($album['cover_url'])) {
  $coverSrc = $album['cover_url'];
}

$formatsRow = $formats;
usort($formatsRow, function ($a, $b) {
  return (int)$a['id'] - (int)$b['id'];
});

$cancelUrl = $isEdit
  ? BASE_URL . '/index.php?route=albums/detail/' . $album['id']
  : BASE_URL . '/index.php?route=albums/list';
?>

<div class="grz-form-page">

  <div class="grz-form-head">
    <button type="button" class="grz-form-back" title="Indietro" aria-label="Torna alla pagina precedente"
      onclick="grzBack('<?= BASE_URL ?>/index.php?route=albums/list')">
      <i class="bi bi-arrow-left" aria-hidden="true"></i>
    </button>
    <h1 class="grz-form-title"><?= $isEdit ? 'Modifica disco' : 'Aggiungi disco' ?></h1>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible">
      <strong><i class="bi bi-exclamation-triangle me-2"></i>Correggi gli errori:</strong>
      <ul class="mb-0 mt-1">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= $action ?>" enctype="multipart/form-data" id="albumForm" class="grz-album-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="cover_local_existing" value="<?= formVal('cover_local', $album, $old) ?>">

    <!-- ===================================================
         1. Identifica il disco: artista, titolo, ricerca fonti
         =================================================== -->
    <section class="grz-fsec grz-lookup" aria-labelledby="lookupHeading">
      <div class="grz-fsec__head">
        <h2 id="lookupHeading">Identifica il disco</h2>
        <p>Artista e titolo bastano: cover, anno, etichetta, genere e tracklist arrivano dalle fonti. Se il disco esiste in più edizioni con tracklist diversa, puoi scegliere quella che possiedi.</p>
      </div>

      <div class="grz-lookup__grid">
        <div>
          <label class="form-label" for="artistAutocomplete">
            <span>Artista o gruppo <span class="text-danger" aria-hidden="true">*</span></span>
          </label>

          <!-- Campi nascosti: popolati dall'autocomplete -->
          <select name="artist_id" id="artistSelect" style="display:none" aria-hidden="true" tabindex="-1">
            <option value="">— Nuovo artista —</option>
            <?php foreach ($artists as $ar): ?>
              <option value="<?= (int)$ar['id'] ?>" <?= ($currentArtistId === (int)$ar['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($ar['name'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="artist_name" id="artistNameInput"
            value="<?= htmlspecialchars($artistNameValue, ENT_QUOTES, 'UTF-8') ?>">

          <div class="artist-autocomplete-wrap" style="position:relative">
            <input
              type="text"
              id="artistAutocomplete"
              class="form-control"
              placeholder="Cerca o scrivi un artista"
              autocomplete="off"
              required
              value="<?= htmlspecialchars($artistDisplayValue, ENT_QUOTES, 'UTF-8') ?>">
            <ul id="artistDropdown" class="artist-dropdown list-group shadow" style="display:none;position:absolute;z-index:1000;width:100%;max-height:220px;overflow-y:auto;top:100%;left:0"></ul>
          </div>

          <?php if (!empty($errors['artist'])): ?>
            <div class="text-danger small mt-1"><?= htmlspecialchars($errors['artist'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
        </div>

        <div>
          <label class="form-label" for="titleInput">
            <span>Titolo album <span class="text-danger" aria-hidden="true">*</span></span>
          </label>
          <input
            type="text"
            name="title"
            id="titleInput"
            class="form-control <?= !empty($errors['title']) ? 'is-invalid' : '' ?>"
            value="<?= formVal('title', $album, $old) ?>"
            required>
          <?php if (!empty($errors['title'])): ?>
            <div class="invalid-feedback"><?= htmlspecialchars($errors['title'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
        </div>

        <div class="grz-lookup__action">
          <!-- Spaziatore alto quanto l'etichetta: il pulsante resta
               allineato ai campi anche quando sotto compare un errore -->
          <span class="form-label grz-lookup__spacer" aria-hidden="true">&nbsp;</span>
          <button type="button" class="btn btn-warning grz-lookup-btn" id="fetchMetaBtn">
            <i class="bi bi-search" aria-hidden="true"></i>
            <span class="grz-lookup-btn__label"><?= $isEdit ? 'Aggiorna dalle fonti' : 'Cerca nelle fonti' ?></span>
          </button>
        </div>
      </div>

      <div id="lookupResult" class="grz-lookup-result" role="status" aria-live="polite" hidden>
        <img id="lookupThumb" class="grz-lookup-result__thumb" src="<?= htmlspecialchars($placeholderSrc, ENT_QUOTES, 'UTF-8') ?>" alt="" hidden>
        <div class="grz-lookup-result__body">
          <div id="lookupStatus" class="grz-lookup-result__status"></div>
          <div id="lookupTitle" class="grz-lookup-result__title"></div>
          <div id="lookupMeta" class="grz-lookup-result__meta"></div>
        </div>
        <div class="grz-lookup-result__side">
          <span>MusicBrainz · Cover Art Archive · Last.fm</span>
          <button type="button" class="btn btn-link grz-lookup-retry" id="lookupRetry">Non è questa edizione?</button>
        </div>
      </div>

      <!-- Edizioni con tracklist diversa dello stesso album (release-group
           MusicBrainz). Compare solo se le varianti sono almeno due. I
           radio non appartengono al form (attributo form verso un id
           inesistente): la scelta si salva tramite l'MBID nascosto. -->
      <div id="lookupEditions" class="grz-editions mt-3" hidden>
        <!-- Riga di riepilogo sempre visibile; l'elenco resta chiuso e si
             apre dal pulsante o dal link "Non è questa edizione?". -->
        <div class="d-flex align-items-baseline gap-2 flex-wrap">
          <span id="lookupEditionsHead" class="small"></span>
          <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="lookupEditionsToggle"
            aria-expanded="false" aria-controls="lookupEditionsPanel"></button>
        </div>
        <div id="lookupEditionsPanel" class="mt-2" hidden>
          <div id="lookupEditionsList" class="list-group" role="radiogroup" aria-labelledby="lookupEditionsHead"></div>
        </div>
        <div id="lookupEditionsMsg" class="small mt-2" aria-live="polite"></div>
      </div>
    </section>

    <div class="grz-form-grid">

      <!-- ===================================================
           2. Dati del disco (compilati dalle fonti, modificabili)
           =================================================== -->
      <section class="grz-fsec" aria-labelledby="metaHeading">
        <div class="grz-fsec__head">
          <h2 id="metaHeading">Dati del disco</h2>
        </div>

        <div class="grz-meta-grid">
          <div>
            <label class="form-label" for="yearInput">
              <span>Anno</span>
              <span class="grz-src" data-src="year" hidden>dalle fonti</span>
            </label>
            <input
              type="number"
              name="year"
              id="yearInput"
              class="form-control grz-mono"
              min="1900"
              max="<?= date('Y') ?>"
              inputmode="numeric"
              value="<?= formVal('year', $album, $old) ?>">
          </div>

          <div>
            <label class="form-label" for="genreInput">
              <span>Genere</span>
              <span class="grz-src" data-src="genre" hidden>dalle fonti</span>
            </label>
            <input
              type="text"
              id="genreInput"
              class="form-control"
              list="genreList"
              autocomplete="off"
              placeholder="Scegli o scrivi un genere"
              value="<?= htmlspecialchars($genreDisplayValue, ENT_QUOTES, 'UTF-8') ?>">
            <datalist id="genreList">
              <?php foreach ($genres as $g): ?>
                <option value="<?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?>" data-id="<?= (int)$g['id'] ?>"></option>
              <?php endforeach; ?>
            </datalist>
            <input type="hidden" name="genre_id" id="genreId" value="<?= $currentGenreId ? (int)$currentGenreId : '' ?>">
            <input type="hidden" name="genre_new" id="genreNew" value="<?= $currentGenreId ? '' : htmlspecialchars($genreDisplayValue, ENT_QUOTES, 'UTF-8') ?>">
          </div>

          <div>
            <label class="form-label" for="labelInput">
              <span>Etichetta</span>
              <span class="grz-src" data-src="label" hidden>dalle fonti</span>
            </label>
            <input
              type="text"
              id="labelInput"
              class="form-control"
              list="labelList"
              autocomplete="off"
              placeholder="Scegli o scrivi un'etichetta"
              value="<?= htmlspecialchars($labelDisplayValue, ENT_QUOTES, 'UTF-8') ?>">
            <datalist id="labelList">
              <?php foreach ($labels as $l): ?>
                <option value="<?= htmlspecialchars($l['name'], ENT_QUOTES, 'UTF-8') ?>" data-id="<?= (int)$l['id'] ?>"></option>
              <?php endforeach; ?>
            </datalist>
            <input type="hidden" name="label_id" id="labelId" value="<?= $currentLabelId ? (int)$currentLabelId : '' ?>">
            <input type="hidden" name="label_new" id="labelNew" value="<?= $currentLabelId ? '' : htmlspecialchars($labelDisplayValue, ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>

        <div class="grz-tracklist">
          <div class="grz-tracklist__head">
            <span class="grz-tracklist__label">Tracklist <span id="trackSummary" class="grz-tracklist__summary"></span></span>
            <span class="grz-src" data-src="tracks" hidden>dalle fonti</span>
          </div>

          <div id="tracksReplace" class="grz-tracks-replace" hidden>
            <span id="tracksReplaceText"></span>
            <button type="button" class="btn btn-sm btn-outline-warning" id="tracksReplaceBtn">Sostituisci tracklist</button>
          </div>

          <div class="grz-tracks">
            <div id="tracklistContainer">
              <?php foreach ($trackRows as $i => $row): ?>
                <div class="grz-trow">
                  <input type="hidden" name="track_id[]" value="<?= htmlspecialchars((string)$row['id'], ENT_QUOTES, 'UTF-8') ?>">
                  <button type="button" class="grz-trow__handle" aria-label="Sposta traccia (frecce su e giù)" title="Trascina o usa le frecce">
                    <i class="bi bi-grip-vertical" aria-hidden="true"></i>
                  </button>
                  <span class="track-num grz-mono"><?= $i + 1 ?></span>
                  <input
                    type="text"
                    name="track_title[]"
                    class="form-control"
                    placeholder="Titolo traccia"
                    aria-label="Titolo traccia"
                    value="<?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>">
                  <input
                    type="text"
                    class="form-control grz-dur grz-mono"
                    placeholder="m:ss"
                    inputmode="numeric"
                    aria-label="Durata (minuti:secondi)"
                    value="<?= htmlspecialchars(formDuration($row['duration']), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="track_duration[]" value="<?= htmlspecialchars((string)$row['duration'], ENT_QUOTES, 'UTF-8') ?>">
                  <button type="button" class="grz-trow__remove remove-track" aria-label="Rimuovi traccia">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                  </button>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="grz-tracks__add" id="addTrack">
              <i class="bi bi-plus-lg" aria-hidden="true"></i>Aggiungi traccia
            </button>
          </div>
        </div>
      </section>

      <!-- ===================================================
           Cover
           =================================================== -->
      <section class="grz-fsec grz-cover" aria-labelledby="coverHeading">
        <div class="grz-fsec__head grz-fsec__head--row">
          <h2 id="coverHeading">Cover</h2>
          <span class="grz-src" data-src="cover" hidden>dalle fonti</span>
        </div>

        <!-- Riquadro unico: segnaposto e zona di caricamento insieme.
             Clic o trascinamento su tutto il quadrato; stato "vuoto"
             quando l'immagine è il placeholder (classe is-empty). -->
        <div id="coverPreview" class="grz-cover__preview">
          <label class="grz-cover__frame<?= $coverSrc === $placeholderSrc ? ' is-empty' : '' ?>" id="coverDrop" for="coverFile">
            <input type="file" name="cover_file" id="coverFile" class="visually-hidden" accept="image/*">
            <img
              id="coverImg"
              src="<?= htmlspecialchars($coverSrc, ENT_QUOTES, 'UTF-8') ?>"
              alt="Cover del disco"
              onerror="this.onerror=null;this.src='<?= htmlspecialchars($placeholderSrc, ENT_QUOTES, 'UTF-8') ?>'">
            <span class="grz-cover__empty" aria-hidden="true">
              <span class="grz-vinyl"></span>
              <span class="grz-cover__empty-title">Nessuna cover</span>
              <span class="grz-cover__empty-hint">Arriva con <strong>Cerca nelle fonti</strong>, oppure trascina qui un'immagine</span>
            </span>
            <span class="grz-cover__overlay" aria-hidden="true">
              <i class="bi bi-arrow-repeat"></i>Clicca o trascina per sostituire
            </span>
            <span class="visually-hidden">Scegli un'immagine di copertina (JPG, PNG, WebP)</span>
          </label>
        </div>

        <div class="grz-cover__foot">
          <div id="coverMsg" class="grz-cover__msg small" aria-live="polite"></div>
          <label for="coverFile" class="grz-cover__replace">
            <i class="bi bi-upload" aria-hidden="true"></i><span id="coverDropTitle">Scegli un file</span>
          </label>
        </div>

        <input type="hidden" name="cover_url" id="coverUrlInput" value="<?= formVal('cover_url', $album, $old) ?>">
        <input type="hidden" name="cover_local_new" id="coverLocalNew" value="<?= formVal('cover_local_new', $album, $old) ?>">
        <input type="hidden" name="mbid" id="mbidInput" value="<?= formVal('mbid', $album, $old) ?>">
      </section>

    </div>

    <!-- ===================================================
         3. La tua copia: dati che nessuna fonte conosce
         =================================================== -->
    <section class="grz-fsec" aria-labelledby="copyHeading">
      <div class="grz-fsec__head">
        <h2 id="copyHeading">La tua copia</h2>
        <p>Questi dati descrivono il disco che possiedi e vanno compilati a mano.</p>
      </div>

      <div class="grz-copy-grid">
        <fieldset class="grz-fieldset">
          <legend class="form-label"><span>Formati posseduti <span class="text-danger" aria-hidden="true">*</span></span></legend>
          <!-- Checkbox multipli: la scheda è una, i formati sono
               un suo attributo (es. vinile E CD dello stesso album). -->
          <div class="grz-chips <?= !empty($errors['format_ids']) ? 'is-invalid' : '' ?>" id="formatChips">
            <?php foreach ($formatsRow as $f): ?>
              <input
                class="btn-check"
                type="checkbox"
                name="format_ids[]"
                id="formatCheck<?= (int)$f['id'] ?>"
                value="<?= (int)$f['id'] ?>"
                <?= in_array((int)$f['id'], $currentFormatIds, true) ? 'checked' : '' ?>>
              <label class="grz-chip" for="formatCheck<?= (int)$f['id'] ?>"><span class="grz-chip__dot" data-fmt="<?= formatKey($f['name']) ?>" aria-hidden="true"></span><?= htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8') ?></label>
            <?php endforeach; ?>
          </div>
          <?php if (!empty($errors['format_ids'])): ?>
            <div class="text-danger small mt-1"><?= htmlspecialchars($errors['format_ids'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
        </fieldset>

        <div class="grz-copy-grid__right">
          <fieldset class="grz-fieldset" id="conditionField">
            <legend class="form-label"><span>Stato di conservazione</span></legend>
            <div class="grz-grade">
              <?php foreach ($conditions as $value => $c): ?>
                <?php $cid = 'cond' . preg_replace('/[^A-Za-z]/', '', $value); ?>
                <input
                  class="btn-check"
                  type="radio"
                  name="condition"
                  id="<?= $cid ?>"
                  value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                  data-label="<?= htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') ?>"
                  <?= ($currentCondition === $value) ? 'checked' : '' ?>>
                <label class="grz-grade__opt" for="<?= $cid ?>" title="<?= htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c['code'], ENT_QUOTES, 'UTF-8') ?></label>
              <?php endforeach; ?>
            </div>
            <div class="grz-grade__text" id="conditionText"><?= htmlspecialchars($conditions[$currentCondition]['label'], ENT_QUOTES, 'UTF-8') ?></div>
          </fieldset>

          <div>
            <label class="form-label" for="copiesInput"><span>Copie</span></label>
            <div class="grz-stepper">
              <button type="button" data-step="-1" aria-label="Una copia in meno">−</button>
              <input
                type="number"
                name="copies"
                id="copiesInput"
                class="grz-mono"
                min="1"
                max="99"
                inputmode="numeric"
                value="<?= formVal('copies', $album, $old, 1) ?>">
              <button type="button" data-step="1" aria-label="Una copia in più">+</button>
            </div>
          </div>
        </div>
      </div>

      <div class="grz-notes">
        <label class="form-label" for="notesInput"><span>Note personali</span></label>
        <textarea
          name="notes"
          id="notesInput"
          class="form-control"
          rows="3"
          placeholder="Edizione, provenienza, dedica"><?= formVal('notes', $album, $old) ?></textarea>
      </div>
    </section>

    <!-- Barra azioni: resta visibile sopra il player -->
    <div class="grz-form-actions">
      <span class="grz-form-actions__status" id="formStatus" aria-live="polite"></span>
      <a href="<?= $cancelUrl ?>" class="btn btn-outline-secondary">Annulla</a>
      <button type="submit" class="btn btn-warning grz-form-actions__save">
        <i class="bi bi-save me-2" aria-hidden="true"></i><?= $isEdit ? 'Aggiorna disco' : 'Salva disco' ?>
      </button>
    </div>
  </form>
</div>

<script>
  // Navigazione "indietro" consapevole del percorso (stessa logica di
  // profile.php e results.php). history.state viene valorizzato solo
  // dopo una navigazione SPA (app.js esegue history.pushState({url})):
  // se presente si torna alla pagina reale di provenienza, altrimenti
  // (ingresso diretto, reload, bookmark) si ripiega sull'archivio.
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
    const form = document.getElementById('albumForm');
    if (!form) return;

    const PLACEHOLDER = <?= json_encode($placeholderSrc) ?>;
    const FETCH_URL = <?= json_encode(BASE_URL . '/index.php?route=albums/fetch-meta') ?>;
    const FETCH_EDITION_URL = <?= json_encode(BASE_URL . '/index.php?route=albums/fetch-edition') ?>;
    // MBID salvato sulla scheda in modifica: identifica "la tua edizione"
    // nell'elenco delle varianti.
    const SAVED_MBID = <?= json_encode($isEdit ? strtolower((string)($album['mbid'] ?? '')) : '') ?>;

    // -------------------------------------------------------
    // Utilità
    // -------------------------------------------------------
    function escHtml(str) {
      const d = document.createElement('div');
      d.appendChild(document.createTextNode(str == null ? '' : String(str)));
      return d.innerHTML;
    }

    function getArtist() {
      const el = document.getElementById('artistAutocomplete');
      return el ? el.value.trim() : '';
    }

    function getTitle() {
      const el = document.getElementById('titleInput');
      return el ? el.value.trim() : '';
    }

    // Secondi -> "m:ss" (o "h:mm:ss")
    function fmtDuration(sec) {
      const s = parseInt(sec, 10);
      if (!s || s <= 0) return '';
      const h = Math.floor(s / 3600);
      const m = Math.floor((s % 3600) / 60);
      const r = s % 60;
      const rr = (r < 10 ? '0' : '') + r;
      if (h > 0) return h + ':' + (m < 10 ? '0' : '') + m + ':' + rr;
      return m + ':' + rr;
    }

    // "m:ss" / "h:mm:ss" / secondi -> secondi. null se non valido, '' se vuoto.
    function parseDuration(str) {
      const v = String(str || '').trim();
      if (v === '') return '';
      if (/^\d+$/.test(v)) return parseInt(v, 10);
      const parts = v.split(/[:.]/);
      if (parts.length < 2 || parts.length > 3) return null;
      if (!parts.every(function(p) { return /^\d+$/.test(p); })) return null;
      const n = parts.map(function(p) { return parseInt(p, 10); });
      const secs = n[n.length - 1];
      const mins = n[n.length - 2];
      if (secs > 59 || (n.length === 3 && mins > 59)) return null;
      return (n.length === 3 ? n[0] * 3600 : 0) + mins * 60 + secs;
    }

    // -------------------------------------------------------
    // Segni "dalle fonti": visibili sui campi compilati dal
    // recupero, rimossi appena l'utente modifica il campo.
    // -------------------------------------------------------
    function markSource(key, on) {
      const el = form.querySelector('.grz-src[data-src="' + key + '"]');
      if (el) el.hidden = !on;
    }

    // -------------------------------------------------------
    // Genere / etichetta: un campo visibile, due hidden
    // (id se il nome esiste già, altrimenti "nuovo").
    // -------------------------------------------------------
    function initCombo(inputId, listId, idHiddenId, newHiddenId) {
      const input = document.getElementById(inputId);
      const list = document.getElementById(listId);
      const idHidden = document.getElementById(idHiddenId);
      const newHidden = document.getElementById(newHiddenId);
      if (!input || !list || !idHidden || !newHidden) return function() {};

      const byName = {};
      Array.prototype.forEach.call(list.options, function(o) {
        byName[o.value.trim().toLowerCase()] = o.getAttribute('data-id');
      });

      function sync() {
        const text = input.value.trim();
        const id = byName[text.toLowerCase()];
        if (text === '') {
          idHidden.value = '';
          newHidden.value = '';
        } else if (id) {
          idHidden.value = id;
          newHidden.value = '';
          input.value = Array.prototype.find.call(list.options, function(o) {
            return o.getAttribute('data-id') === id;
          }).value;
        } else {
          idHidden.value = '';
          newHidden.value = text;
        }
      }

      input.addEventListener('change', sync);
      input.addEventListener('input', function() {
        const text = input.value.trim();
        const id = byName[text.toLowerCase()];
        idHidden.value = id || '';
        newHidden.value = id ? '' : text;
      });

      return function setValue(text) {
        input.value = text || '';
        sync();
      };
    }

    const setGenre = initCombo('genreInput', 'genreList', 'genreId', 'genreNew');
    const setLabel = initCombo('labelInput', 'labelList', 'labelId', 'labelNew');

    // -------------------------------------------------------
    // Tracklist
    // -------------------------------------------------------
    const container = document.getElementById('tracklistContainer');

    function renumberTracks() {
      if (!container) return;
      container.querySelectorAll('.track-num').forEach(function(el, i) {
        el.textContent = i + 1;
      });
      updateTrackSummary();
    }

    function updateTrackSummary() {
      const out = document.getElementById('trackSummary');
      if (!out || !container) return;
      let count = 0;
      let total = 0;
      container.querySelectorAll('.grz-trow').forEach(function(row) {
        const t = row.querySelector('input[name="track_title[]"]');
        const d = row.querySelector('input[name="track_duration[]"]');
        if (t && t.value.trim() !== '') count++;
        if (d && d.value !== '') total += parseInt(d.value, 10) || 0;
      });
      if (!count) {
        out.textContent = '';
        return;
      }
      out.textContent = count + (count === 1 ? ' traccia' : ' tracce') + (total ? ' · ' + fmtDuration(total) : '');
    }

    function bindRow(row) {
      const dur = row.querySelector('.grz-dur');
      const hidden = row.querySelector('input[name="track_duration[]"]');
      const title = row.querySelector('input[name="track_title[]"]');
      const remove = row.querySelector('.remove-track');
      const handle = row.querySelector('.grz-trow__handle');

      if (dur && hidden) {
        // Il valore in secondi viene aggiornato a ogni battitura:
        // il submit AJAX di app.js legge il FormData immediatamente.
        dur.addEventListener('input', function() {
          const secs = parseDuration(dur.value);
          dur.classList.toggle('is-invalid', secs === null);
          hidden.value = (secs === null || secs === '') ? '' : String(secs);
          markSource('tracks', false);
          updateTrackSummary();
        });
        dur.addEventListener('blur', function() {
          const secs = parseDuration(dur.value);
          if (secs !== null && secs !== '') dur.value = fmtDuration(secs);
        });
      }

      if (title) {
        title.addEventListener('input', function() {
          markSource('tracks', false);
          updateTrackSummary();
        });
      }

      if (remove) {
        remove.addEventListener('click', function() {
          const rows = container.querySelectorAll('.grz-trow');
          if (rows.length === 1) {
            // Ultima riga: si svuota invece di rimuoverla
            row.querySelector('input[name="track_id[]"]').value = '';
            if (title) title.value = '';
            if (dur) dur.value = '';
            if (hidden) hidden.value = '';
          } else {
            row.remove();
          }
          renumberTracks();
        });
      }

      if (handle) {
        // Trascinamento: la riga diventa draggable solo dalla maniglia
        handle.addEventListener('pointerdown', function() {
          row.setAttribute('draggable', 'true');
        });
        // Tastiera: frecce su/giù spostano la riga
        handle.addEventListener('keydown', function(e) {
          if (e.key === 'ArrowUp' && row.previousElementSibling) {
            e.preventDefault();
            container.insertBefore(row, row.previousElementSibling);
            handle.focus();
            renumberTracks();
          } else if (e.key === 'ArrowDown' && row.nextElementSibling) {
            e.preventDefault();
            container.insertBefore(row.nextElementSibling, row);
            handle.focus();
            renumberTracks();
          }
        });
      }
    }

    function buildTrackRow(trackId, title, duration) {
      const row = document.createElement('div');
      row.className = 'grz-trow';
      row.innerHTML =
        '<input type="hidden" name="track_id[]" value="' + escHtml(trackId || '') + '">' +
        '<button type="button" class="grz-trow__handle" aria-label="Sposta traccia (frecce su e giù)" title="Trascina o usa le frecce"><i class="bi bi-grip-vertical" aria-hidden="true"></i></button>' +
        '<span class="track-num grz-mono"></span>' +
        '<input type="text" name="track_title[]" class="form-control" placeholder="Titolo traccia" aria-label="Titolo traccia" value="' + escHtml(title || '') + '">' +
        '<input type="text" class="form-control grz-dur grz-mono" placeholder="m:ss" inputmode="numeric" aria-label="Durata (minuti:secondi)" value="' + escHtml(fmtDuration(duration)) + '">' +
        '<input type="hidden" name="track_duration[]" value="' + escHtml(parseInt(duration, 10) > 0 ? parseInt(duration, 10) : '') + '">' +
        '<button type="button" class="grz-trow__remove remove-track" aria-label="Rimuovi traccia"><i class="bi bi-x-lg" aria-hidden="true"></i></button>';
      bindRow(row);
      return row;
    }

    function renderTracklist(tracks) {
      if (!container) return;
      container.innerHTML = '';
      tracks.forEach(function(t) {
        container.appendChild(buildTrackRow(t.id || '', t.title || '', t.duration || ''));
      });
      renumberTracks();
    }

    function tracklistHasContent() {
      if (!container) return false;
      return Array.prototype.some.call(
        container.querySelectorAll('input[name="track_title[]"]'),
        function(i) { return i.value.trim() !== ''; }
      );
    }

    if (container) {
      container.querySelectorAll('.grz-trow').forEach(bindRow);

      let dragging = null;
      container.addEventListener('dragstart', function(e) {
        const row = e.target.closest('.grz-trow');
        if (!row || row.getAttribute('draggable') !== 'true') return;
        dragging = row;
        row.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '');
      });
      container.addEventListener('dragover', function(e) {
        if (!dragging) return;
        e.preventDefault();
        const over = e.target.closest('.grz-trow');
        if (!over || over === dragging) return;
        const rect = over.getBoundingClientRect();
        const after = (e.clientY - rect.top) > rect.height / 2;
        container.insertBefore(dragging, after ? over.nextSibling : over);
      });
      container.addEventListener('dragend', function() {
        if (!dragging) return;
        dragging.classList.remove('is-dragging');
        dragging.removeAttribute('draggable');
        dragging = null;
        renumberTracks();
      });
      container.addEventListener('pointerup', function(e) {
        const row = e.target.closest('.grz-trow');
        if (row && !dragging) row.removeAttribute('draggable');
      });
    }

    const addTrackBtn = document.getElementById('addTrack');
    if (addTrackBtn && container) {
      addTrackBtn.addEventListener('click', function() {
        const row = buildTrackRow('', '', '');
        container.appendChild(row);
        renumberTracks();
        const t = row.querySelector('input[name="track_title[]"]');
        if (t) t.focus();
      });
    }

    // Tracklist trovata ma non applicata (tracklist già compilata):
    // la sostituzione richiede un clic esplicito, perché le tracce
    // esistenti possono avere file audio associati.
    let pendingTracks = null;
    const replaceBox = document.getElementById('tracksReplace');
    const replaceBtn = document.getElementById('tracksReplaceBtn');
    if (replaceBtn) {
      replaceBtn.addEventListener('click', function() {
        if (!pendingTracks) return;
        renderTracklist(pendingTracks);
        pendingTracks = null;
        if (replaceBox) replaceBox.hidden = true;
        markSource('tracks', true);
      });
    }

    // -------------------------------------------------------
    // Recupero dalle fonti (endpoint unico albums/fetch-meta)
    // -------------------------------------------------------
    const fetchBtn = document.getElementById('fetchMetaBtn');
    const resultBox = document.getElementById('lookupResult');

    function showResult(state, statusHtml, titleText, metaText, thumbSrc) {
      if (!resultBox) return;
      resultBox.hidden = false;
      resultBox.className = 'grz-lookup-result grz-lookup-result--' + state;
      document.getElementById('lookupStatus').innerHTML = statusHtml;
      document.getElementById('lookupTitle').textContent = titleText || '';
      document.getElementById('lookupMeta').textContent = metaText || '';
      const thumb = document.getElementById('lookupThumb');
      if (thumb) {
        thumb.hidden = !thumbSrc;
        if (thumbSrc) {
          thumb.onerror = function() { this.onerror = null; this.src = PLACEHOLDER; };
          thumb.src = thumbSrc;
        }
      }
    }

    function setBusy(busy) {
      const frame = document.getElementById('coverDrop');
      if (frame) frame.classList.toggle('is-loading', busy);
      if (!fetchBtn) return;
      fetchBtn.disabled = busy;
      fetchBtn.setAttribute('aria-busy', busy ? 'true' : 'false');
      const label = fetchBtn.querySelector('.grz-lookup-btn__label');
      const icon = fetchBtn.querySelector('i, .spinner-border');
      if (busy) {
        fetchBtn.dataset.label = label ? label.textContent : '';
        if (icon) icon.outerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
        if (label) label.textContent = 'Ricerca in corso';
      } else {
        if (icon) icon.outerHTML = '<i class="bi bi-search" aria-hidden="true"></i>';
        if (label && fetchBtn.dataset.label) label.textContent = fetchBtn.dataset.label;
      }
    }

    // Cover dalle fonti (ricerca o edizione scelta). Restituisce l'URL
    // di anteprima, oppure '' se la risposta non contiene una cover.
    function applyCover(data) {
      if (!data.cover_local && !data.cover) return '';

      const coverImg = document.getElementById('coverImg');
      const coverUrlInput = document.getElementById('coverUrlInput');
      const coverLocalInput = document.getElementById('coverLocalNew');
      const coverMsg = document.getElementById('coverMsg');
      const previewSrc = data.cover_preview || data.cover || '';

      if (coverImg && previewSrc) coverImg.src = previewSrc;

      if (data.cover_local) {
        if (coverLocalInput) coverLocalInput.value = data.cover_local;
        if (coverUrlInput) coverUrlInput.value = '';
        if (coverMsg) coverMsg.textContent = 'Cover salvata in locale.';
      } else {
        if (coverUrlInput) coverUrlInput.value = data.cover || '';
        if (coverLocalInput) coverLocalInput.value = '';
        if (coverMsg) coverMsg.textContent = 'Cover collegata da URL esterno.';
      }
      markSource('cover', true);
      return previewSrc;
    }

    function applyMeta(data) {
      const applied = [];

      if (data.year) {
        const yearInput = document.getElementById('yearInput');
        if (yearInput) yearInput.value = data.year;
        markSource('year', true);
        applied.push(String(data.year));
      }

      if (data.title) {
        const titleInput = document.getElementById('titleInput');
        if (titleInput && !titleInput.value) titleInput.value = data.title;
      }

      const previewSrc = applyCover(data);

      if (data.mbid) {
        const mbidInput = document.getElementById('mbidInput');
        if (mbidInput) mbidInput.value = data.mbid;
      }

      if (data.label) {
        setLabel(data.label);
        markSource('label', true);
        applied.push(data.label);
      }

      if (data.genre) {
        setGenre(data.genre);
        markSource('genre', true);
        applied.push(data.genre);
      }

      let tracksNote = '';
      if (data.tracks && data.tracks.length > 0) {
        const n = data.tracks.length;
        applied.push(n + (n === 1 ? ' traccia' : ' tracce'));
        if (tracklistHasContent()) {
          pendingTracks = data.tracks;
          const txt = document.getElementById('tracksReplaceText');
          if (txt) txt.textContent = 'Trovata una tracklist di ' + n + (n === 1 ? ' traccia' : ' tracce') + '. Quella attuale non è stata modificata.';
          if (replaceBox) replaceBox.hidden = false;
          tracksNote = 'tracklist da confermare';
        } else {
          renderTracklist(data.tracks);
          markSource('tracks', true);
        }
      }

      return { applied: applied, previewSrc: previewSrc, tracksNote: tracksNote };
    }

    function runLookup() {
      const artist = getArtist();
      const title = getTitle();
      const csrf = form.querySelector('[name="csrf_token"]').value;

      if (!artist || !title) {
        showResult('error',
          '<i class="bi bi-exclamation-circle" aria-hidden="true"></i> Servono artista e titolo',
          '', 'Compila entrambi i campi e cerca di nuovo.', '');
        (artist ? document.getElementById('titleInput') : document.getElementById('artistAutocomplete')).focus();
        return;
      }

      const yearInput = document.getElementById('yearInput');
      const mbidField = document.getElementById('mbidInput');
      hideEditions();
      setBusy(true);

      fetch(FETCH_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'artist=' + encodeURIComponent(artist) +
            '&title=' + encodeURIComponent(title) +
            '&year=' + encodeURIComponent(yearInput ? yearInput.value : '') +
            '&mbid=' + encodeURIComponent(mbidField ? mbidField.value : '') +
            '&csrf_token=' + encodeURIComponent(csrf)
        })
        .then(function(r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(function(data) {
          setBusy(false);
          if (data.error) throw new Error(data.error);

          const res = applyMeta(data);
          if (!res.applied.length && !res.previewSrc) {
            showResult('empty',
              '<i class="bi bi-search" aria-hidden="true"></i> Nessun risultato',
              artist + ' · ' + title,
              'Controlla la grafia di artista e titolo, oppure compila i campi a mano.', '');
            return;
          }

          const status = res.tracksNote
            ? '<i class="bi bi-check2" aria-hidden="true"></i> Trovato: dati applicati, tracklist da confermare'
            : '<i class="bi bi-check2" aria-hidden="true"></i> Trovato e applicato ai campi sotto';
          const meta = res.applied.join(' · ') + (res.previewSrc ? (res.applied.length ? ' · ' : '') + 'cover' : '');
          showResult('found', status, (data.title || title) + ' · ' + artist, meta, res.previewSrc);
          renderEditions(data);
          updateStatus();
        })
        .catch(function(err) {
          setBusy(false);
          showResult('error',
            '<i class="bi bi-exclamation-circle" aria-hidden="true"></i> Recupero non riuscito',
            '', err.message + '. Riprova tra qualche secondo o compila i campi a mano.', '');
        });
    }

    // -------------------------------------------------------
    // Edizioni con tracklist diversa (albums/fetch-edition)
    // -------------------------------------------------------
    const editionState = { releaseGroup: '', editions: [], applied: -1, req: 0, partial: false };

    function hideEditions() {
      const box = document.getElementById('lookupEditions');
      const list = document.getElementById('lookupEditionsList');
      const msg = document.getElementById('lookupEditionsMsg');
      editionState.req++; // scarta eventuali risposte ancora in volo
      editionState.releaseGroup = '';
      editionState.editions = [];
      editionState.applied = -1;
      if (list) list.innerHTML = '';
      if (msg) msg.textContent = '';
      setEditionsOpen(false);
      if (box) box.hidden = true;
    }

    // Apre o chiude l'elenco delle edizioni
    function setEditionsOpen(open) {
      const panel = document.getElementById('lookupEditionsPanel');
      const toggle = document.getElementById('lookupEditionsToggle');
      if (panel) panel.hidden = !open;
      if (toggle) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.textContent = open
          ? 'Nascondi le edizioni'
          : 'Mostra le ' + editionState.editions.length + ' edizioni';
      }
    }

    // Riepilogo con l'edizione applicata, visibile a elenco chiuso
    function updateEditionsHead(partial) {
      const head = document.getElementById('lookupEditionsHead');
      if (!head) return;
      const ed = editionState.editions[editionState.applied];
      let txt = 'Questo disco esiste in ' + editionState.editions.length + ' versioni con tracklist diversa.';
      if (ed) {
        txt += ' Applicata: ' + editionLine(ed) +
          (ed.is_default ? ' (originale).' : '.');
      }
      if (partial) txt += ' L\'album ha moltissime stampe: l\'elenco potrebbe essere incompleto.';
      head.textContent = txt;
    }

    const editionsToggle = document.getElementById('lookupEditionsToggle');
    if (editionsToggle) {
      editionsToggle.addEventListener('click', function() {
        setEditionsOpen(this.getAttribute('aria-expanded') !== 'true');
      });
    }

    function editionPlural(n, one, many) {
      return n + ' ' + (n === 1 ? one : many);
    }

    function listTitles(titles, more) {
      return titles.join(', ') + (more > 0 ? ' e altre ' + more : '');
    }

    function editionLine(ed) {
      const parts = [];
      parts.push(ed.year ? String(ed.year) : 'Anno ignoto');
      if (ed.country) parts.push(ed.country);
      if (ed.formats) parts.push(ed.formats);
      const pk = String(ed.packaging || '');
      if (pk && pk !== 'None' && pk !== 'Jewel Case') parts.push(pk);
      parts.push(editionPlural(ed.track_count, 'traccia', 'tracce'));
      return parts.join(' · ');
    }

    function editionDetail(ed) {
      const parts = [];
      if (ed.is_default) {
        parts.push('Tracklist della prima pubblicazione');
      } else {
        if (ed.removed && ed.removed.length) parts.push('senza ' + listTitles(ed.removed, ed.removed_more));
        if (ed.added && ed.added.length) parts.push('con ' + listTitles(ed.added, ed.added_more));
        if (!parts.length) parts.push('stesse tracce in ordine diverso');
      }
      if (ed.multi_disc) parts.push('più dischi');
      if (ed.label) parts.push(ed.label);
      parts.push(editionPlural(ed.release_count, 'stampa', 'stampe'));
      return parts.join(' · ');
    }

    function renderEditions(data) {
      const box = document.getElementById('lookupEditions');
      const head = document.getElementById('lookupEditionsHead');
      const list = document.getElementById('lookupEditionsList');
      if (!box || !head || !list) return;

      const eds = Array.isArray(data.editions) ? data.editions : [];
      if (eds.length < 2 || !data.release_group) {
        hideEditions();
        return;
      }

      editionState.releaseGroup = data.release_group;
      editionState.editions = eds;
      editionState.applied = -1;

      editionState.partial = !!data.editions_partial;

      list.innerHTML = eds.map(function(ed, i) {
        if (ed.selected) editionState.applied = i;
        const badges =
          (ed.is_default ? ' <span class="badge text-bg-warning ms-1">originale</span>' : '') +
          (SAVED_MBID && ed.selected && ed.mbid === SAVED_MBID ? ' <span class="badge text-bg-secondary ms-1">la tua edizione</span>' : '');
        return '<label class="list-group-item d-flex gap-3 align-items-start">' +
          '<input class="form-check-input flex-shrink-0 mt-1" type="radio" name="grz_edition_choice" form="grzEditionsNoSubmit"' +
          ' value="' + escHtml(ed.mbid) + '" data-index="' + i + '"' + (ed.selected ? ' checked' : '') + '>' +
          '<span><span class="fw-semibold">' + escHtml(editionLine(ed)) + '</span>' + badges +
          '<br><span class="small text-body-secondary">' + escHtml(editionDetail(ed)) + '</span></span>' +
          '</label>';
      }).join('');

      list.querySelectorAll('input[type="radio"]').forEach(function(r) {
        r.addEventListener('change', onEditionChange);
      });

      updateEditionsHead(editionState.partial);
      setEditionsOpen(false);
      box.hidden = false;
    }

    function setEditionsBusy(busy) {
      const list = document.getElementById('lookupEditionsList');
      if (!list) return;
      list.setAttribute('aria-busy', busy ? 'true' : 'false');
      list.querySelectorAll('input[type="radio"]').forEach(function(r) { r.disabled = busy; });
    }

    function checkAppliedEdition() {
      const list = document.getElementById('lookupEditionsList');
      if (!list) return;
      list.querySelectorAll('input[type="radio"]').forEach(function(r) {
        r.checked = parseInt(r.getAttribute('data-index'), 10) === editionState.applied;
      });
    }

    function onEditionChange() {
      const idx = parseInt(this.getAttribute('data-index'), 10);
      const ed = editionState.editions[idx];
      if (!ed || !editionState.releaseGroup) return;

      const msg = document.getElementById('lookupEditionsMsg');
      const csrf = form.querySelector('[name="csrf_token"]').value;
      const reqId = ++editionState.req;

      setEditionsBusy(true);
      const frame = document.getElementById('coverDrop');
      if (frame) frame.classList.add('is-loading');
      if (msg) msg.textContent = 'Carico l\'edizione selezionata…';

      fetch(FETCH_EDITION_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'release_group=' + encodeURIComponent(editionState.releaseGroup) +
            '&mbid=' + encodeURIComponent(ed.mbid) +
            '&csrf_token=' + encodeURIComponent(csrf)
        })
        .then(function(r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(function(data) {
          if (reqId !== editionState.req) return;
          setEditionsBusy(false);
          if (frame) frame.classList.remove('is-loading');
          if (data.error) throw new Error(data.error);
          applyEdition(data);
          editionState.applied = idx;
          updateEditionsHead(editionState.partial);
          if (msg) msg.textContent = '';
        })
        .catch(function(err) {
          if (reqId !== editionState.req) return;
          setEditionsBusy(false);
          if (frame) frame.classList.remove('is-loading');
          checkAppliedEdition();
          if (msg) msg.textContent = 'Edizione non caricata: ' + err.message + '. Riprova tra qualche secondo.';
        });
    }

    // Applica l'edizione scelta. Anno e genere non cambiano: l'anno è
    // quello di prima pubblicazione dell'album, l'edizione posseduta
    // si salva tramite l'MBID. La tracklist si sostituisce direttamente
    // solo se il riquadro è vuoto o contiene ancora tracce arrivate
    // dalle fonti e non modificate a mano; altrimenti passa dalla
    // conferma "Sostituisci tracklist", come nella ricerca.
    function applyEdition(data) {
      const mbidInput = document.getElementById('mbidInput');
      if (mbidInput && data.mbid) mbidInput.value = data.mbid;

      if (data.label) {
        setLabel(data.label);
        markSource('label', true);
      }

      const previewSrc = applyCover(data);
      const thumb = document.getElementById('lookupThumb');
      if (thumb && previewSrc) {
        thumb.hidden = false;
        thumb.src = previewSrc;
      }

      let pending = false;
      const tracks = Array.isArray(data.tracks) ? data.tracks : [];
      if (tracks.length) {
        const srcMark = form.querySelector('.grz-src[data-src="tracks"]');
        const fromSource = srcMark && !srcMark.hidden;
        if (!tracklistHasContent() || fromSource) {
          renderTracklist(tracks);
          markSource('tracks', true);
          pendingTracks = null;
          if (replaceBox) replaceBox.hidden = true;
        } else {
          pending = true;
          pendingTracks = tracks;
          const n = tracks.length;
          const txt = document.getElementById('tracksReplaceText');
          if (txt) txt.textContent = 'Tracklist dell\'edizione scelta: ' + editionPlural(n, 'traccia', 'tracce') + '. Quella attuale non è stata modificata.';
          if (replaceBox) replaceBox.hidden = false;
        }
      }

      // Riepilogo aggiornato con i dati ora presenti nei campi
      const parts = [];
      const y = document.getElementById('yearInput');
      const lab = document.getElementById('labelInput');
      const gen = document.getElementById('genreInput');
      if (y && y.value) parts.push(y.value);
      if (lab && lab.value.trim()) parts.push(lab.value.trim());
      if (gen && gen.value.trim()) parts.push(gen.value.trim());
      if (tracks.length) parts.push(editionPlural(tracks.length, 'traccia', 'tracce'));
      if (previewSrc) parts.push('cover');
      const metaEl = document.getElementById('lookupMeta');
      if (metaEl) metaEl.textContent = parts.join(' · ');
      const statusEl = document.getElementById('lookupStatus');
      if (statusEl) {
        statusEl.innerHTML = pending
          ? '<i class="bi bi-check2" aria-hidden="true"></i> Edizione cambiata: tracklist da confermare'
          : '<i class="bi bi-check2" aria-hidden="true"></i> Edizione cambiata e applicata ai campi sotto';
      }

      updateTrackSummary();
      updateStatus();
    }

    if (fetchBtn) fetchBtn.addEventListener('click', runLookup);

    // Invio nel campo titolo avvia la ricerca invece del salvataggio
    const titleInputEl = document.getElementById('titleInput');
    if (titleInputEl) {
      titleInputEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          runLookup();
        }
      });
    }

    const retryBtn = document.getElementById('lookupRetry');
    if (retryBtn) {
      retryBtn.addEventListener('click', function() {
        // Con più edizioni disponibili il link porta all'elenco
        const edBox = document.getElementById('lookupEditions');
        if (edBox && !edBox.hidden) {
          setEditionsOpen(true);
          const checked = edBox.querySelector('input[type="radio"]:checked') ||
            edBox.querySelector('input[type="radio"]');
          edBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          if (checked) checked.focus();
          return;
        }
        const y = document.getElementById('yearInput');
        const t = document.getElementById('titleInput');
        if (y && !y.value) {
          y.focus();
        } else if (t) {
          t.focus();
          t.select();
        }
      });
    }

    // Modifica manuale: il segno "dalle fonti" sparisce
    [['yearInput', 'year'], ['genreInput', 'genre'], ['labelInput', 'label']].forEach(function(p) {
      const el = document.getElementById(p[0]);
      if (el) el.addEventListener('input', function() { markSource(p[1], false); });
    });

    // -------------------------------------------------------
    // Cover: file manuale (clic o trascinamento)
    // -------------------------------------------------------
    const coverFile = document.getElementById('coverFile');
    const coverDrop = document.getElementById('coverDrop');

    // Stato del riquadro: "vuoto" quando l'immagine mostrata è il
    // placeholder (anche dopo un errore di caricamento, che ripiega
    // sul placeholder). Segue il load dell'immagine, quindi vale per
    // ricerca nelle fonti, file scelto e trascinamento senza toccare
    // quei flussi.
    const coverImgEl = document.getElementById('coverImg');
    function syncCoverState() {
      if (!coverDrop || !coverImgEl) return;
      const src = coverImgEl.getAttribute('src') || '';
      coverDrop.classList.toggle('is-empty', !src || src === PLACEHOLDER);
    }
    if (coverImgEl) {
      coverImgEl.addEventListener('load', syncCoverState);
      coverImgEl.addEventListener('error', syncCoverState);
    }

    if (coverFile) {
      coverFile.addEventListener('change', function() {
        if (!this.files || !this.files[0]) return;
        const file = this.files[0];
        const reader = new FileReader();
        reader.onload = function(e) {
          const coverImg = document.getElementById('coverImg');
          const coverUrlInput = document.getElementById('coverUrlInput');
          const coverLocalInput = document.getElementById('coverLocalNew');
          const coverMsg = document.getElementById('coverMsg');
          const dropTitle = document.getElementById('coverDropTitle');

          if (coverImg) coverImg.src = e.target.result;
          if (coverUrlInput) coverUrlInput.value = '';
          if (coverLocalInput) coverLocalInput.value = '';
          if (coverMsg) coverMsg.textContent = '';
          if (dropTitle) dropTitle.textContent = file.name;
          markSource('cover', false);
        };
        reader.readAsDataURL(file);
      });
    }

    if (coverDrop && coverFile) {
      ['dragenter', 'dragover'].forEach(function(ev) {
        coverDrop.addEventListener(ev, function(e) {
          e.preventDefault();
          coverDrop.classList.add('is-over');
        });
      });
      ['dragleave', 'drop'].forEach(function(ev) {
        coverDrop.addEventListener(ev, function(e) {
          e.preventDefault();
          coverDrop.classList.remove('is-over');
        });
      });
      coverDrop.addEventListener('drop', function(e) {
        const files = e.dataTransfer && e.dataTransfer.files;
        if (!files || !files[0] || files[0].type.indexOf('image/') !== 0) return;
        coverFile.files = files;
        coverFile.dispatchEvent(new Event('change'));
      });
    }

    // -------------------------------------------------------
    // Copie: stepper
    // -------------------------------------------------------
    const copiesInput = document.getElementById('copiesInput');
    form.querySelectorAll('.grz-stepper [data-step]').forEach(function(btn) {
      btn.addEventListener('click', function() {
        if (!copiesInput) return;
        const cur = parseInt(copiesInput.value, 10) || 1;
        const next = Math.min(99, Math.max(1, cur + parseInt(btn.getAttribute('data-step'), 10)));
        copiesInput.value = next;
      });
    });

    // -------------------------------------------------------
    // Stato di conservazione: descrizione sotto la scala
    // -------------------------------------------------------
    const conditionText = document.getElementById('conditionText');
    form.querySelectorAll('input[name="condition"]').forEach(function(r) {
      r.addEventListener('change', function() {
        if (r.checked && conditionText) conditionText.textContent = r.getAttribute('data-label');
      });
    });

    // -------------------------------------------------------
    // Barra azioni: cosa manca per salvare
    // -------------------------------------------------------
    const formatChecks = form.querySelectorAll('input[name="format_ids[]"]');

    function updateStatus() {
      const out = document.getElementById('formStatus');
      if (!out) return;
      const missing = [];
      if (!getArtist()) missing.push('artista');
      if (!getTitle()) missing.push('titolo');
      if (!Array.prototype.some.call(formatChecks, function(c) { return c.checked; })) missing.push('formato');
      out.textContent = missing.length
        ? 'Mancano: ' + missing.join(', ')
        : 'Pronto per il salvataggio';
      out.classList.toggle('is-ready', !missing.length);
      clearAlertsIfResolved(missing.length);
    }

    Array.prototype.forEach.call(formatChecks, function(c) {
      c.addEventListener('change', function() {
        userTouched = true;
        if (Array.prototype.some.call(formatChecks, function(x) { return x.checked; })) {
          const chips = document.getElementById('formatChips');
          if (chips) {
            chips.classList.remove('is-invalid');
            clearFieldError(chips);
          }
        }
        updateStatus();
      });
    });

    // -------------------------------------------------------
    // Errori di validazione
    // Gli errori arrivano in due modi: renderizzati dal server
    // (submit tradizionale) o inseriti da showFormErrors() in
    // app.js (submit AJAX). Qui vengono ripuliti appena il campo
    // viene corretto, e l'avviso generale sparisce quando non
    // resta nessun campo da correggere.
    // -------------------------------------------------------
    function clearFieldError(el) {
      if (!el) return;
      el.classList.remove('is-invalid');
      const box = el.closest('.grz-lookup__grid > div, .grz-meta-grid > div, .grz-fieldset');
      if (!box) return;
      box.querySelectorAll('.invalid-feedback, .ajax-form-error, .text-danger.small').forEach(function(m) {
        m.remove();
      });
    }

    function getAlerts() {
      const page = form.closest('.grz-form-page') || form.parentNode;
      return page.querySelectorAll('.alert-danger');
    }

    // Gli avvisi si rimuovono solo dopo un intervento dell'utente:
    // al caricamento un avviso del server può riguardare campi che
    // qui non sono controllati (es. anno non valido).
    let userTouched = false;

    function clearAlertsIfResolved(missingCount) {
      if (!userTouched || missingCount > 0 || form.querySelector('.is-invalid')) return;
      getAlerts().forEach(function(a) { a.remove(); });
    }

    // Chiusura dell'avviso con la X: gestita qui direttamente,
    // senza dipendere dall'handler delegato di Bootstrap.
    const pageEl = form.closest('.grz-form-page') || form.parentNode;
    pageEl.addEventListener('click', function(e) {
      const closeBtn = e.target.closest('.alert .btn-close');
      if (!closeBtn) return;
      const alertEl = closeBtn.closest('.alert');
      if (alertEl) alertEl.remove();
    });

    // Avviso inserito via AJAX: lo porta in vista (chi salva è in
    // fondo alla pagina) e segna i formati se nessuno è scelto.
    if (window.MutationObserver) {
      new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
          Array.prototype.forEach.call(m.addedNodes, function(node) {
            if (!node.classList || !node.classList.contains('alert')) return;
            const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const top = node.getBoundingClientRect().top + window.pageYOffset - 88;
            window.scrollTo({ top: Math.max(0, top), behavior: reduce ? 'auto' : 'smooth' });
            const anyFormat = Array.prototype.some.call(formatChecks, function(c) { return c.checked; });
            const chips = document.getElementById('formatChips');
            if (chips && !anyFormat) chips.classList.add('is-invalid');
          });
        });
      }).observe(form, { childList: true });
    }

    ['artistAutocomplete', 'titleInput', 'yearInput'].forEach(function(id) {
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', function() {
        userTouched = true;
        if (id === 'yearInput' || el.value.trim() !== '') clearFieldError(el);
        updateStatus();
      });
    });

    // -------------------------------------------------------
    // Autocomplete artista
    // -------------------------------------------------------
    (function initArtistAutocomplete() {
      const input = document.getElementById('artistAutocomplete');
      const dropdown = document.getElementById('artistDropdown');
      const selHidden = document.getElementById('artistSelect');
      const nameHidden = document.getElementById('artistNameInput');

      if (!input || !dropdown) return;

      // Raccoglie artisti dal select nascosto
      const artists = Array.from(selHidden.options)
        .filter(o => o.value !== '')
        .map(o => ({
          id: o.value,
          name: o.text.trim()
        }));

      function setArtist(id, name) {
        selHidden.value = id || '';
        nameHidden.value = id ? '' : name;
        input.value = name;
        dropdown.style.display = 'none';
        if (name) {
          userTouched = true;
          clearFieldError(input);
        }
        updateStatus();
      }

      function renderDropdown(filtered) {
        dropdown.innerHTML = '';
        if (!filtered.length) {
          dropdown.style.display = 'none';
          return;
        }
        filtered.forEach(a => {
          const li = document.createElement('li');
          li.className = 'list-group-item list-group-item-action py-2 px-3';
          li.style.cursor = 'pointer';
          li.textContent = a.name;
          li.addEventListener('mousedown', (e) => {
            e.preventDefault();
            setArtist(a.id, a.name);
          });
          dropdown.appendChild(li);
        });
        // Opzione "Nuovo artista"
        const liNew = document.createElement('li');
        liNew.className = 'list-group-item list-group-item-action py-2 px-3 text-muted fst-italic';
        liNew.style.cursor = 'pointer';
        liNew.textContent = '+ Nuovo artista: "' + input.value.trim() + '"';
        liNew.addEventListener('mousedown', (e) => {
          e.preventDefault();
          setArtist('', input.value.trim());
        });
        dropdown.appendChild(liNew);
        dropdown.style.display = 'block';
      }

      input.addEventListener('input', () => {
        const q = input.value.trim().toLowerCase();
        // Resetta selezione quando l'utente modifica il testo
        selHidden.value = '';
        nameHidden.value = input.value.trim();
        if (!q) {
          dropdown.style.display = 'none';
          return;
        }
        const filtered = artists.filter(a => a.name.toLowerCase().includes(q)).slice(0, 10);
        renderDropdown(filtered);
      });

      input.addEventListener('focus', () => {
        const q = input.value.trim().toLowerCase();
        if (q) {
          const filtered = artists.filter(a => a.name.toLowerCase().includes(q)).slice(0, 10);
          renderDropdown(filtered);
        }
      });

      input.addEventListener('blur', () => {
        setTimeout(() => {
          dropdown.style.display = 'none';
        }, 150);
      });

      // Navigazione tastiera
      input.addEventListener('keydown', (e) => {
        const items = dropdown.querySelectorAll('.list-group-item');
        const active = dropdown.querySelector('.active');
        let idx = Array.from(items).indexOf(active);
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          if (active) active.classList.remove('active');
          const next = items[idx + 1] || items[0];
          if (next) next.classList.add('active');
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          if (active) active.classList.remove('active');
          const prev = items[idx - 1] || items[items.length - 1];
          if (prev) prev.classList.add('active');
        } else if (e.key === 'Enter') {
          // Invio: seleziona la voce attiva; senza voce attiva non invia il form
          e.preventDefault();
          if (active) active.dispatchEvent(new MouseEvent('mousedown'));
          else dropdown.style.display = 'none';
        } else if (e.key === 'Escape') {
          dropdown.style.display = 'none';
        }
      });
    })();

    // -------------------------------------------------------
    // Stato conservazione: nascosto quando l'unico formato
    // selezionato è "Digital". La condizione fisica non si
    // applica a un supporto digitale; se però è posseduto anche
    // un formato fisico (es. Vinile + Digital) il campo resta
    // visibile, perché descrive comunque la copia fisica.
    // Il valore non viene disabilitato: se il campo torna
    // visibile lo stato scelto in precedenza è ancora presente.
    // -------------------------------------------------------
    (function initConditionToggle() {
      const field = document.getElementById('conditionField');
      if (!field || !formatChecks.length) return;

      function isDigital(cb) {
        const lbl = form.querySelector('label[for="' + cb.id + '"]');
        const txt = lbl ? lbl.textContent.trim().toLowerCase() : '';
        return txt === 'digital';
      }

      function syncCondition() {
        const checked = Array.prototype.filter.call(formatChecks, function(cb) {
          return cb.checked;
        });
        const digitalOnly = checked.length > 0 && checked.every(isDigital);
        field.style.display = digitalOnly ? 'none' : '';
      }

      Array.prototype.forEach.call(formatChecks, function(cb) {
        cb.addEventListener('change', syncCondition);
      });
      syncCondition();
    })();

    renumberTracks();
    updateStatus();
  })();
</script>

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
?>

<div class="row justify-content-center">
  <div class="col-12 col-xl-10">

    <div class="d-flex align-items-center mb-3">
      <a href="<?= BASE_URL ?>/index.php?route=albums/list" class="btn btn-sm btn-outline-secondary me-3">
        <i class="bi bi-arrow-left"></i>
      </a>
      <h4 class="mb-0"><?= $isEdit ? 'Modifica disco' : 'Aggiungi disco' ?></h4>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger alert-dismissible">
        <strong><i class="bi bi-exclamation-triangle me-2"></i>Correggi gli errori:</strong>
        <ul class="mb-0 mt-1">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
          <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= $action ?>" enctype="multipart/form-data" id="albumForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="cover_local_existing" value="<?= formVal('cover_local', $album, $old) ?>">

      <div class="row g-4">

        <div class="col-md-8">

          <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold">
              <i class="bi bi-info-circle me-2"></i>Informazioni principali
            </div>
            <div class="card-body row g-3">

              <div class="col-12">
                <label class="form-label fw-semibold">
                  Artista / Gruppo <span class="text-danger">*</span>
                </label>

                <!-- Campi nascosti — popolati dall'autocomplete -->
                <select name="artist_id" id="artistSelect" style="display:none">
                  <option value="">— Nuovo artista —</option>
                  <?php foreach ($artists as $ar): ?>
                    <option value="<?= (int)$ar['id'] ?>" <?= ($currentArtistId === (int)$ar['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($ar['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <input type="hidden" name="artist_name" id="artistNameInput"
                  value="<?= htmlspecialchars($artistNameValue, ENT_QUOTES, 'UTF-8') ?>">

                <!-- Campo autocomplete visibile -->
                <div class="artist-autocomplete-wrap" style="position:relative">
                  <input
                    type="text"
                    id="artistAutocomplete"
                    class="form-control"
                    placeholder="Cerca o scrivi un artista…"
                    autocomplete="off"
                    value="<?= htmlspecialchars(
                              $currentArtistId
                                ? ($artists[array_search($currentArtistId, array_column($artists, 'id'))]['name'] ?? $artistNameValue)
                                : $artistNameValue,
                              ENT_QUOTES,
                              'UTF-8'
                            ) ?>">
                  <ul id="artistDropdown" class="artist-dropdown list-group shadow" style="display:none;position:absolute;z-index:1000;width:100%;max-height:220px;overflow-y:auto;top:100%;left:0"></ul>
                </div>

                <?php if (!empty($errors['artist'])): ?>
                  <div class="text-danger small mt-1"><?= htmlspecialchars($errors['artist'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
              </div>

              <div class="col-12">
                <label class="form-label fw-semibold">
                  Titolo album <span class="text-danger">*</span>
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

              <div class="col-12 col-lg-8">
                <label class="form-label fw-semibold">
                  Formati posseduti <span class="text-danger">*</span>
                </label>
                <!-- Checkbox multipli: la scheda è una, i formati sono
                     un suo attributo (es. vinile E CD dello stesso album).
                     Ordinati per id (Vinile, CD, Musicassetta, Digital).
                     Il campo occupa 2/3 della riga: i quattro checkbox
                     stanno su una riga con spaziatura normale, senza
                     compressioni; su mobile il campo prende tutta la
                     larghezza e le voci vanno a capo in modo pulito. -->
                <?php
                $formatsRow = $formats;
                usort($formatsRow, function ($a, $b) {
                  return (int)$a['id'] - (int)$b['id'];
                });
                ?>
                <div class="d-flex flex-wrap align-items-center column-gap-3 row-gap-1 pt-1">
                  <?php foreach ($formatsRow as $f): ?>
                    <div class="form-check text-nowrap">
                      <input
                        class="form-check-input <?= !empty($errors['format_ids']) ? 'is-invalid' : '' ?>"
                        type="checkbox"
                        name="format_ids[]"
                        id="formatCheck<?= (int)$f['id'] ?>"
                        value="<?= (int)$f['id'] ?>"
                        <?= in_array((int)$f['id'], $currentFormatIds, true) ? 'checked' : '' ?>>
                      <label class="form-check-label" for="formatCheck<?= (int)$f['id'] ?>">
                        <?= htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8') ?>
                      </label>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php if (!empty($errors['format_ids'])): ?>
                  <div class="text-danger small mt-1"><?= htmlspecialchars($errors['format_ids'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
              </div>

              <div class="col-12 col-lg-4">
                <label class="form-label fw-semibold">Anno</label>
                <input
                  type="number"
                  name="year"
                  class="form-control"
                  min="1900"
                  max="<?= date('Y') ?>"
                  value="<?= formVal('year', $album, $old) ?>">
              </div>

              <div class="col-6">
                <label class="form-label fw-semibold">Genere</label>
                <select name="genre_id" id="genreSelect" class="form-select">
                  <option value="">— Seleziona o scrivi nuovo —</option>
                  <?php foreach ($genres as $g): ?>
                    <option value="<?= (int)$g['id'] ?>"
                      <?= ((int)$currentGenreId === (int)$g['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <input
                  type="text"
                  name="genre_new"
                  id="genreNew"
                  class="form-control mt-1"
                  placeholder="Oppure scrivi genere…"
                  value="<?= formVal('genre_new', $album, $old) ?>">
              </div>

              <div class="col-6">
                <label class="form-label fw-semibold">Etichetta</label>
                <select name="label_id" id="labelSelect" class="form-select">
                  <option value="">— Seleziona o scrivi nuova —</option>
                  <?php foreach ($labels as $l): ?>
                    <option value="<?= (int)$l['id'] ?>"
                      <?= ((int)$currentLabelId === (int)$l['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($l['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <input
                  type="text"
                  name="label_new"
                  id="labelNew"
                  class="form-control mt-1"
                  placeholder="Oppure scrivi etichetta…"
                  value="<?= formVal('label_new', $album, $old) ?>">
              </div>

              <div class="col-6">
                <label class="form-label fw-semibold">Stato conservazione</label>
                <select name="condition" class="form-select">
                  <?php
                  $conditions = [
                    'Mint'           => 'Nuovo (M)',
                    'Near Mint'      => 'Come Nuovo (NM o M-)',
                    'Very Good Plus' => 'Ottimo (VG+)',
                    'Very Good'      => 'Molto buono (VG)',
                    'Good Plus'      => 'Più che buono (G+)',
                    'Good'           => 'Buono (G)',
                    'Fair'           => 'Pessimo (F)',
                    'Poor'           => 'Scarso (P)',
                  ];
                  foreach ($conditions as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                      <?= ($currentCondition === $value) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-6">
                <label class="form-label fw-semibold">N° copie</label>
                <input
                  type="number"
                  name="copies"
                  class="form-control"
                  min="1"
                  max="99"
                  value="<?= formVal('copies', $album, $old, 1) ?>">
              </div>

              <div class="col-12">
                <label class="form-label fw-semibold">Note personali</label>
                <textarea
                  name="notes"
                  class="form-control"
                  rows="3"
                  placeholder="Edizione speciale, provenienza, dedica…"><?= formVal('notes', $album, $old) ?></textarea>
              </div>

            </div>
          </div>

          <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span class="fw-semibold">
                <i class="bi bi-music-note-list me-2"></i>Tracklist
              </span>
              <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-info" id="fetchTracklist">
                  <i class="bi bi-cloud-download me-1"></i>Recupera Informazioni
                </button>
                <button type="button" class="btn btn-sm btn-outline-success" id="addTrack">
                  <i class="bi bi-plus-lg"></i>
                </button>
              </div>
            </div>
            <div class="card-body p-2">
              <div id="tracklistContainer">

                <?php
                $oldTitles = $old['track_title'] ?? [];
                $oldDurations = $old['track_duration'] ?? [];
                ?>

                <?php if (!empty($oldTitles)): ?>

                  <?php foreach ($oldTitles as $i => $title): ?>
                    <div class="track-row input-group input-group-sm mb-1">
                      <input type="hidden" name="track_id[]" value="">
                      <span class="input-group-text track-num"><?= $i + 1 ?></span>

                      <input
                        type="text"
                        name="track_title[]"
                        class="form-control"
                        placeholder="Titolo traccia"
                        value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">

                      <input
                        type="number"
                        name="track_duration[]"
                        class="form-control"
                        style="max-width:80px"
                        placeholder="sec"
                        min="0"
                        value="<?= htmlspecialchars($oldDurations[$i] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                      <button type="button" class="btn btn-outline-danger remove-track">
                        <i class="bi bi-x"></i>
                      </button>
                    </div>
                  <?php endforeach; ?>

                <?php elseif (!empty($tracks)): ?>

                  <?php foreach ($tracks as $i => $t): ?>
                    <div class="track-row input-group input-group-sm mb-1">
                      <input type="hidden" name="track_id[]" value="<?= (int)($t['id'] ?? 0) ?>">
                      <span class="input-group-text track-num"><?= $i + 1 ?></span>

                      <input
                        type="text"
                        name="track_title[]"
                        class="form-control"
                        value="<?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?>">

                      <input
                        type="number"
                        name="track_duration[]"
                        class="form-control"
                        style="max-width:80px"
                        value="<?= isset($t['duration_sec']) ? (int)$t['duration_sec'] : '' ?>">

                      <button type="button" class="btn btn-outline-danger remove-track">
                        <i class="bi bi-x"></i>
                      </button>
                    </div>
                  <?php endforeach; ?>

                <?php else: ?>

                  <div class="track-row input-group input-group-sm mb-1">
                    <input type="hidden" name="track_id[]" value="">
                    <span class="input-group-text track-num">1</span>

                    <input type="text" name="track_title[]" class="form-control">

                    <input type="number" name="track_duration[]" class="form-control" style="max-width:80px">

                    <button type="button" class="btn btn-outline-danger remove-track">
                      <i class="bi bi-x"></i>
                    </button>
                  </div>

                <?php endif; ?>

              </div>
              <div id="tracklistSpinner" class="text-center py-2 d-none">
                <div class="spinner-border spinner-border-sm text-info me-2"></div>
                Recupero tracklist…
              </div>
            </div>
          </div>

        </div>

        <div class="col-md-4">
          <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold">
              <i class="bi bi-image me-2"></i>Cover
            </div>
            <div class="card-body text-center">

              <?php
              $coverSrc = BASE_URL . '/public/img/placeholder.png';

              if (!empty($old['cover_local_new'])) {
                $coverSrc = BASE_URL . '/public/uploads/' . $old['cover_local_new'];
              } elseif (!empty($old['cover_url'])) {
                $coverSrc = $old['cover_url'];
              } elseif ($isEdit && !empty($album['cover_local'])) {
                $coverSrc = BASE_URL . '/public/uploads/' . $album['cover_local'];
              } elseif ($isEdit && !empty($album['cover_url'])) {
                $coverSrc = $album['cover_url'];
              }
              ?>

              <div id="coverPreview" class="mb-3">
                <img
                  id="coverImg"
                  src="<?= htmlspecialchars($coverSrc, ENT_QUOTES, 'UTF-8') ?>"
                  class="img-fluid rounded shadow-sm"
                  style="width:100%;aspect-ratio:1/1;object-fit:contain;background:#f8f9fa"
                  alt="Cover"
                  onerror="this.src='<?= BASE_URL ?>/public/img/placeholder.png'">
              </div>

              <button type="button" class="btn btn-sm btn-outline-info w-100 mb-2" id="fetchCoverBtn">
                <i class="bi bi-cloud-download me-1"></i>Recupera automaticamente
              </button>

              <div id="coverSpinner" class="d-none text-center mb-2">
                <div class="spinner-border spinner-border-sm text-info"></div>
                <span class="small ms-1">Ricerca in corso…</span>
              </div>

              <div id="coverMsg" class="small mb-2"></div>

              <input type="hidden" name="cover_url" id="coverUrlInput" value="<?= formVal('cover_url', $album, $old) ?>">
              <input type="hidden" name="cover_local_new" id="coverLocalNew" value="<?= formVal('cover_local_new', $album, $old) ?>">
              <input type="hidden" name="mbid" id="mbidInput" value="<?= formVal('mbid', $album, $old) ?>">

              <hr class="my-2">

              <label class="form-label small">Oppure carica manualmente</label>
              <input type="file" name="cover_file" id="coverFile" class="form-control form-control-sm" accept="image/*">
            </div>
          </div>

          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-warning fw-bold">
              <i class="bi bi-save me-2"></i>
              <?= $isEdit ? 'Aggiorna disco' : 'Salva disco' ?>
            </button>
            <a href="<?= $isEdit ? BASE_URL . '/index.php?route=albums/detail/' . $album['id'] : BASE_URL . '/index.php?route=albums/list' ?>" class="btn btn-outline-secondary">Annulla</a>
          </div>

        </div>
      </div>
    </form>
  </div>
</div>

<script>
  (function() {
    function getArtist() {
      const autocomplete = document.getElementById('artistAutocomplete');
      if (autocomplete && autocomplete.value.trim() !== '') {
        return autocomplete.value.trim();
      }
      return '';
    }

    function getTitle() {
      const t = document.getElementById('titleInput');
      return t ? t.value.trim() : '';
    }

    function setMessage(el, html) {
      if (el) el.innerHTML = html;
    }

    function escHtml(str) {
      const d = document.createElement('div');
      d.appendChild(document.createTextNode(str || ''));
      return d.innerHTML;
    }

    function renumberTracks() {
      document.querySelectorAll('#tracklistContainer .track-num').forEach(function(el, i) {
        el.textContent = i + 1;
      });
    }

    function bindRemoveButtons() {
      document.querySelectorAll('#tracklistContainer .remove-track').forEach(function(btn) {
        btn.onclick = function() {
          const row = btn.closest('.track-row');
          if (row) {
            row.remove();
            renumberTracks();
          }
        };
      });
    }

    function buildTrackRow(trackId, title, duration, position) {
      const row = document.createElement('div');
      row.className = 'track-row input-group input-group-sm mb-1';
      row.innerHTML =
        '<input type="hidden" name="track_id[]" value="' + escHtml(String(trackId || '')) + '">' +
        '<span class="input-group-text track-num">' + position + '</span>' +
        '<input type="text" name="track_title[]" class="form-control" placeholder="Titolo traccia" value="' + escHtml(title || '') + '">' +
        '<input type="number" name="track_duration[]" class="form-control" style="max-width:80px" placeholder="sec" min="0" value="' + escHtml(String(duration || '')) + '">' +
        '<button type="button" class="btn btn-outline-danger remove-track"><i class="bi bi-x"></i></button>';
      return row;
    }

    function renderTracklist(tracks) {
      const container = document.getElementById('tracklistContainer');
      if (!container) return;

      container.innerHTML = '';

      tracks.forEach(function(t, i) {
        container.appendChild(
          buildTrackRow(
            t.id || '',
            t.title || '',
            t.duration || '',
            i + 1
          )
        );
      });

      bindRemoveButtons();
      renumberTracks();
    }

    function fetchMetaAndFill(callback) {
      const artist = getArtist();
      const title = getTitle();
      const csrf = document.querySelector('[name="csrf_token"]').value;

      if (!artist || !title) {
        alert('Inserisci artista e titolo.');
        return;
      }

      fetch('<?= BASE_URL ?>/index.php?route=albums/fetch-meta', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: 'artist=' + encodeURIComponent(artist) +
            '&title=' + encodeURIComponent(title) +
            '&year=' + encodeURIComponent(document.querySelector('[name="year"]')?.value || '') +
            '&csrf_token=' + encodeURIComponent(csrf)
        })
        .then(function(r) {
          if (!r.ok) {
            throw new Error('HTTP ' + r.status);
          }
          return r.json();
        })
        .then(function(data) {
          if (data.error) {
            throw new Error(data.error);
          }

          if (data.year) {
            const yearInput = document.querySelector('[name="year"]');
            if (yearInput) yearInput.value = data.year;
          }

          if (data.title) {
            const titleInput = document.getElementById('titleInput');
            if (titleInput && !titleInput.value) titleInput.value = data.title;
          }

          if (data.cover_local || data.cover) {
            const coverImg = document.getElementById('coverImg');
            const coverUrlInput = document.getElementById('coverUrlInput');
            const coverLocalInput = document.getElementById('coverLocalNew');
            const coverMsg = document.getElementById('coverMsg');
            const previewSrc = data.cover_preview || data.cover || '';

            if (coverImg && previewSrc) coverImg.src = previewSrc;

            if (data.cover_local) {
              if (coverLocalInput) coverLocalInput.value = data.cover_local;
              if (coverUrlInput) coverUrlInput.value = '';
              if (coverMsg) coverMsg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Cover salvata in locale!</span>';
            } else {
              if (coverUrlInput) coverUrlInput.value = data.cover || '';
              if (coverLocalInput) coverLocalInput.value = '';
              if (coverMsg) coverMsg.innerHTML = '<span class="text-warning">Cover trovata (URL esterno).</span>';
            }
          }

          if (data.mbid) {
            const mbidInput = document.getElementById('mbidInput');
            if (mbidInput) mbidInput.value = data.mbid;
          }

          if (data.genre) {
            const genreSelect = document.getElementById('genreSelect');
            const genreNew = document.getElementById('genreNew');
            let found = false;

            if (genreSelect) {
              for (let i = 0; i < genreSelect.options.length; i++) {
                if (genreSelect.options[i].text.toLowerCase() === data.genre.toLowerCase()) {
                  genreSelect.value = genreSelect.options[i].value;
                  if (genreNew) genreNew.value = '';
                  found = true;
                  break;
                }
              }
            }

            if (!found && genreNew) {
              genreNew.value = data.genre;
              if (genreSelect) genreSelect.value = '';
            }
          }

          if (data.label) {
            const labelSelect = document.getElementById('labelSelect');
            const labelNew = document.getElementById('labelNew');
            let found = false;

            if (labelSelect) {
              for (let i = 0; i < labelSelect.options.length; i++) {
                if (labelSelect.options[i].text.toLowerCase() === data.label.toLowerCase()) {
                  labelSelect.value = labelSelect.options[i].value;
                  if (labelNew) labelNew.value = '';
                  found = true;
                  break;
                }
              }
            }

            if (!found && labelNew) {
              labelNew.value = data.label;
              if (labelSelect) labelSelect.value = '';
            }
          }

          if (typeof callback === 'function') {
            callback(data);
          }
        })
        .catch(function(err) {
          console.error(err);
          alert('Errore nel recupero automatico: ' + err.message);
        });
    }

    const fetchCoverBtn = document.getElementById('fetchCoverBtn');
    if (fetchCoverBtn) {
      fetchCoverBtn.addEventListener('click', function() {
        const spinner = document.getElementById('coverSpinner');
        const msg = document.getElementById('coverMsg');

        if (spinner) spinner.classList.remove('d-none');
        setMessage(msg, '');

        fetchMetaAndFill(function(data) {
          if (spinner) spinner.classList.add('d-none');

          if (data.cover || data.cover_local) {
            setMessage(msg, '<span class="text-success">Cover trovata.</span>');
          } else {
            setMessage(msg, '<span class="text-warning">Cover non trovata.</span>');
          }
        });
      });
    }

    const fetchTracklistBtn = document.getElementById('fetchTracklist');
    if (fetchTracklistBtn) {
      fetchTracklistBtn.addEventListener('click', function() {
        const spinner = document.getElementById('tracklistSpinner');
        if (spinner) spinner.classList.remove('d-none');

        fetchMetaAndFill(function(data) {
          if (spinner) spinner.classList.add('d-none');

          if (data.tracks && data.tracks.length > 0) {
            renderTracklist(data.tracks);
          } else {
            alert('Tracklist non trovata.');
          }
        });
      });
    }

    const addTrackBtn = document.getElementById('addTrack');
    if (addTrackBtn) {
      addTrackBtn.addEventListener('click', function() {
        const container = document.getElementById('tracklistContainer');
        if (!container) return;

        const position = container.querySelectorAll('.track-row').length + 1;
        container.appendChild(buildTrackRow('', '', '', position));
        bindRemoveButtons();
        renumberTracks();
      });
    }

    const coverFile = document.getElementById('coverFile');
    if (coverFile) {
      coverFile.addEventListener('change', function() {
        if (this.files && this.files[0]) {
          const reader = new FileReader();
          reader.onload = function(e) {
            const coverImg = document.getElementById('coverImg');
            const coverUrlInput = document.getElementById('coverUrlInput');
            const coverLocalInput = document.getElementById('coverLocalNew');

            if (coverImg) coverImg.src = e.target.result;
            if (coverUrlInput) coverUrlInput.value = '';
            if (coverLocalInput) coverLocalInput.value = '';
          };
          reader.readAsDataURL(this.files[0]);
        }
      });
    }

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
        } else if (e.key === 'Enter' && active) {
          e.preventDefault();
          active.dispatchEvent(new MouseEvent('mousedown'));
        } else if (e.key === 'Escape') {
          dropdown.style.display = 'none';
        }
      });
    })();

    bindRemoveButtons();
    renumberTracks();
  })();
</script>

<?php require BASE_PATH . '/views/layout/footer.php'; ?>
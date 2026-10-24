<?php

/** @var array $artist */
/** @var array $albums */
/** @var array $stats */

$pageTitle = $artist['name'] ?? 'Artista';
require BASE_PATH . '/views/layout/header.php';

// Immagine già salvata (locale o remota) per render iniziale
$artistImg = '';
if (!empty($artist['image_local'])) {
  $artistImg = BASE_URL . '/public/uploads/' . htmlspecialchars($artist['image_local']);
} elseif (!empty($artist['image_url'])) {
  $artistImg = htmlspecialchars($artist['image_url']);
}

// La bio è già stata cercata in passato? (anche se vuota)
$bioFetched = !empty($artist['bio_fetched_at']);

// Anni di attività dell'artista (da MusicBrainz)
$activeRange = '';
if (!empty($artist['active_from'])) {
  $activeRange = $artist['active_from'] . '–' . (!empty($artist['active_to']) ? $artist['active_to'] : 'oggi');
}

// Genere principale (dalla collezione): il primo di $stats['genres'],
// ordinati per numero di album (Artist::getAlbumStats).
$mainGenre = $stats['genres'][0] ?? '';

// Discografia ufficiale gia' recuperata?
$discoFetched = !empty($artist['disco_fetched_at']);

// Barra dei formati: stessa componente della Panoramica
// (.grz-formatbar). Conteggi dalla tabella ponte: un album
// vinile+CD conta 1 vinile E 1 CD, come in getAlbumStats().
$fmtSegments = [
  ['accent' => 'vinyl',   'label' => 'Vinili',       'count' => (int)($stats['vinili']   ?? 0)],
  ['accent' => 'cd',      'label' => 'CD',           'count' => (int)($stats['cd']       ?? 0)],
  ['accent' => 'tape',    'label' => 'Musicassette', 'count' => (int)($stats['cassette'] ?? 0)],
  ['accent' => 'digital', 'label' => 'Digital',      'count' => (int)($stats['digital']  ?? 0)],
];
$fmtTotal = 0;
foreach ($fmtSegments as $s) {
  $fmtTotal += $s['count'];
}

/* PHP 7.4 compatibile. Chiave del colore formato nel registro:
   segue i token --grz-fmt-* usati dai badge formato. */
if (!function_exists('artistLedgerFormatKey')) {
  function artistLedgerFormatKey($name)
  {
    switch ($name) {
      case 'Vinile':
        return 'vinile';
      case 'CD':
        return 'cd';
      case 'Musicassetta':
      case 'Tape':
        return 'cassetta';
      default:
        return 'digital';
    }
  }
}
?>

<!-- ============================================================
     HERO ARTISTA
     La foto vive in una colonna propria, alta quanto la hero:
     è uno sfondo in cover che la riempie sempre e scorre con
     la card (vedi .artist-hero__photo).
     ============================================================ -->
<button type="button" class="album-hero-back"
  aria-label="Torna alla pagina precedente"
  onclick="grzBack('<?= BASE_URL ?>/index.php?route=albums/list')">
  <i class="bi bi-arrow-left"></i><span>Indietro</span>
</button>

<div class="artist-hero mb-4" id="artistHero">
  <!-- sfondo atmosferico sfocato -->
  <div class="artist-hero__bg"
    <?= $artistImg ? 'style="background-image:url(\'' . $artistImg . '\')"' : '' ?>></div>

  <div class="artist-hero__content">
    <h1 class="artist-hero__name" id="artistName"><?= htmlspecialchars($artist['name']) ?></h1>

    <!-- Fatti: paese, anni di attività, genere principale -->
    <div class="artist-hero__facts" id="artistMeta"
      data-genre="<?= htmlspecialchars($mainGenre) ?>">
      <?php if (!empty($artist['country'])): ?>
        <span class="artist-hero__fact"><i class="bi bi-geo-alt"></i><?= htmlspecialchars($artist['country']) ?></span>
      <?php endif; ?>
      <?php if ($activeRange): ?>
        <span class="artist-hero__fact"><i class="bi bi-calendar3"></i><span class="artist-hero__fact-data"><?= htmlspecialchars($activeRange) ?></span></span>
      <?php endif; ?>
      <?php if ($mainGenre !== ''): ?>
        <span class="artist-hero__fact"><i class="bi bi-tag"></i><?= htmlspecialchars($mainGenre) ?></span>
      <?php endif; ?>
    </div>

    <!-- BIO -->
    <div class="artist-hero__bio" id="artistBio">
      <?php if ($bioFetched && !empty($artist['bio'])): ?>
        <p class="artist-bio-text" id="bioText"><?= nl2br(htmlspecialchars($artist['bio'])) ?></p>
        <button type="button" class="artist-bio-toggle" id="bioToggle" hidden>Mostra tutto</button>
        <?php if (!empty($artist['bio_source'])): ?>
          <div class="artist-bio-source">
            Fonte:
            <?php if (!empty($artist['bio_url'])): ?>
              <a href="<?= htmlspecialchars($artist['bio_url']) ?>" target="_blank" rel="noopener">
                <?= htmlspecialchars(ucfirst($artist['bio_source'])) ?>
              </a>
            <?php else: ?>
              <?= htmlspecialchars(ucfirst($artist['bio_source'])) ?>
            <?php endif; ?>
            <?php if (!empty($artist['bio_lang']) && $artist['bio_lang'] !== 'it'): ?>
              <span class="artist-bio-lang"><?= htmlspecialchars(strtoupper($artist['bio_lang'])) ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      <?php elseif ($bioFetched): ?>
        <p class="artist-bio-empty" id="bioEmpty">
          Nessuna biografia disponibile per questo artista.
        </p>

      <?php else: ?>
        <div class="artist-bio-loading" id="bioLoading">
          <span class="spinner-border spinner-border-sm"></span>
          Recupero biografia in corso…
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- foto "copertina" nella colonna di destra -->
  <div class="artist-hero__photo-cell">
    <div class="artist-hero__photo" id="artistPhoto"
      <?= $artistImg ? 'style="background-image:url(\'' . $artistImg . '\')"' : '' ?>></div>
  </div>
</div>

<!-- ============================================================
     FORMATI IN ARCHIVIO (stessa barra della Panoramica)
     ============================================================ -->
<?php if ($fmtTotal > 0): ?>
  <section class="artist-formats" aria-label="Formati in archivio">
    <div class="grz-formatbar" aria-hidden="true">
      <?php foreach ($fmtSegments as $s): if ($s['count'] <= 0) continue; ?>
        <span class="grz-formatbar__seg grz-formatbar__seg--<?= $s['accent'] ?>"
          style="flex: <?= $s['count'] ?> 1 0%"
          title="<?= $s['label'] ?>: <?= $s['count'] ?>"></span>
      <?php endforeach; ?>
    </div>
    <div class="grz-formatbar__legend">
      <?php foreach ($fmtSegments as $s): if ($s['count'] <= 0) continue; ?>
        <span class="grz-formatbar__chip">
          <span class="grz-formatbar__dot grz-formatbar__dot--<?= $s['accent'] ?>"></span>
          <strong><?= $s['count'] ?></strong>&nbsp;<?= $s['label'] ?>
        </span>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<!-- ============================================================
     DISCOGRAFIA E ARCHIVIO
     Le righe dei dischi in archivio arrivano da PHP. Quando la
     discografia ufficiale (album in studio) è disponibile, lo
     script le riordina sulla discografia, aggiunge gli album
     mancanti e sposta il resto in "Fuori dagli album in studio".
     Senza discografia l'elenco resta quello dell'archivio.
     ============================================================ -->
<section class="grz-ledger-section" id="artistLedger" data-filter="all" aria-labelledby="ledgerTitle">
  <div class="grz-ledger-head">
    <h2 class="grz-ledger-title" id="ledgerTitle">Discografia <span>e archivio</span></h2>
    <div class="grz-ledger-tabs" id="ledgerTabs" role="group" aria-label="Filtra l'elenco" hidden>
      <button type="button" class="grz-ledger-tab" data-filter="all" aria-pressed="true">
        Tutto <span class="grz-ledger-tab__count" data-count="all"></span>
      </button>
      <button type="button" class="grz-ledger-tab" data-filter="own" aria-pressed="false">
        In archivio <span class="grz-ledger-tab__count" data-count="own"></span>
      </button>
      <button type="button" class="grz-ledger-tab" data-filter="miss" aria-pressed="false">
        Mancanti <span class="grz-ledger-tab__count" data-count="miss"></span>
      </button>
    </div>
  </div>

  <div class="grz-ledger-wrap">
    <table class="grz-ledger">
      <thead>
        <tr>
          <th scope="col" class="grz-ledger__num">N°</th>
          <th scope="col" class="grz-ledger__cover"><span class="visually-hidden">Copertina</span></th>
          <th scope="col" class="grz-ledger__title">Titolo</th>
          <th scope="col" class="grz-ledger__year">Anno</th>
          <th scope="col" class="grz-ledger__formats">Formati</th>
          <th scope="col" class="grz-ledger__tracks">Tracce</th>
          <th scope="col" class="grz-ledger__action"><span class="visually-hidden">Azioni</span></th>
        </tr>
      </thead>
      <tbody id="ledgerBody">
        <?php if (empty($albums)): ?>
          <tr class="grz-ledger__empty">
            <td colspan="7">Nessun disco presente per questo artista.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($albums as $a): ?>
          <?php
          // Tutti i formati posseduti della scheda (la scheda è una
          // sola). Fallback sulla colonna legacy.
          $rowFormats = !empty($a['formats'])
            ? $a['formats']
            : (!empty($a['format_name']) ? [['name' => $a['format_name']]] : []);

          // Icona tracce/audio, stessa logica di archivio e dettaglio:
          // due note verdi = tutte le tracce hanno audio; una nota ambra
          // = mancano del tutto o in parte.
          $rowTotalTracks = (int)($a['track_count'] ?? 0);
          $rowTracksAudio = (int)($a['tracks_with_audio_count'] ?? 0);
          $rowHasAudio    = $rowTotalTracks > 0 && $rowTracksAudio >= $rowTotalTracks;
          $rowAudioTitle  = $rowHasAudio
            ? 'Tutte le tracce hanno audio'
            : ($rowTracksAudio > 0
              ? $rowTracksAudio . ' di ' . $rowTotalTracks . ' tracce con audio'
              : 'Nessun file audio caricato');

          $rowCover = !empty($a['cover_local'])
            ? BASE_URL . '/public/uploads/' . htmlspecialchars($a['cover_local'])
            : (!empty($a['cover_url']) ? htmlspecialchars($a['cover_url']) : BASE_URL . '/public/img/placeholder.png');

          $rowYear = !empty($a['year']) ? (string)$a['year'] : '';
          $rowUrl  = BASE_URL . '/index.php?route=albums/detail/' . (int)$a['id'];
          ?>
          <tr class="grz-ledger__row is-owned"
            data-album-id="<?= (int)$a['id'] ?>"
            data-rg="<?= htmlspecialchars(strtolower((string)($a['mb_release_group'] ?? ''))) ?>"
            data-title="<?= htmlspecialchars($a['title']) ?>"
            data-year="<?= htmlspecialchars($rowYear) ?>">
            <td class="grz-ledger__num"></td>
            <td class="grz-ledger__cover">
              <a href="<?= $rowUrl ?>" tabindex="-1" aria-hidden="true">
                <img class="grz-ledger__thumb" src="<?= $rowCover ?>" alt="" width="44" height="44" loading="lazy">
              </a>
            </td>
            <td class="grz-ledger__title">
              <a class="grz-ledger__link" href="<?= $rowUrl ?>"><?= htmlspecialchars($a['title']) ?></a>
              <span class="grz-ledger__sub">
                <span class="grz-ledger__sub-year"><?= htmlspecialchars($rowYear !== '' ? $rowYear : '—') ?></span>
                <?php foreach ($rowFormats as $fmt): ?>
                  <span class="grz-ledger__fmt"><span class="grz-ledger__dot grz-ledger__dot--<?= artistLedgerFormatKey($fmt['name']) ?>"></span><?= htmlspecialchars($fmt['name']) ?></span>
                <?php endforeach; ?>
              </span>
            </td>
            <td class="grz-ledger__year"><?= htmlspecialchars($rowYear !== '' ? $rowYear : '—') ?></td>
            <td class="grz-ledger__formats">
              <?php foreach ($rowFormats as $fmt): ?>
                <span class="grz-ledger__fmt"><span class="grz-ledger__dot grz-ledger__dot--<?= artistLedgerFormatKey($fmt['name']) ?>"></span><?= htmlspecialchars($fmt['name']) ?></span>
              <?php endforeach; ?>
            </td>
            <td class="grz-ledger__tracks">
              <?php if ($rowTotalTracks > 0): ?>
                <span title="<?= htmlspecialchars($rowAudioTitle) ?>"><i class="bi <?= $rowHasAudio ? 'bi-music-note-beamed grz-track-audio' : 'bi-music-note grz-track-noaudio' ?>"></i>&nbsp;<?= $rowTotalTracks ?></span>
              <?php else: ?>
                <span aria-label="Nessuna traccia">–</span>
              <?php endif; ?>
            </td>
            <td class="grz-ledger__action">
              <?php if ($rowTracksAudio > 0): ?>
                <button type="button" class="grz-ledger__play"
                  data-album-id="<?= (int)$a['id'] ?>"
                  data-label="<?= htmlspecialchars($a['title']) ?>"
                  aria-label="Riproduci <?= htmlspecialchars($a['title']) ?>"
                  title="Riproduci">
                  <i class="bi bi-play-fill"></i>
                </button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="grz-ledger-status" id="ledgerStatus">
    <span class="spinner-border spinner-border-sm"></span>
    Recupero discografia in corso…
  </p>
  <p class="grz-ledger-source" id="ledgerSource" hidden>
    Album in studio da <a href="https://musicbrainz.org/" target="_blank" rel="noopener">MusicBrainz</a>,
    copertine da <a href="https://coverartarchive.org/" target="_blank" rel="noopener">Cover Art Archive</a>
  </p>
</section>

<!-- ============================================================
     SCRIPT — fetch bio AJAX + clamp/expand + registro
     ============================================================ -->
<script>
  // Navigazione "indietro" consapevole del percorso.
  // history.state viene valorizzato solo dopo una navigazione SPA (app.js
  // esegue history.pushState({url})). Se e' presente, si torna alla pagina
  // reale da cui si proviene (dashboard, archivio, ricerca…); altrimenti
  // (ingresso diretto, reload o bookmark) si ripiega sulla rotta indicata.
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
    var BASE = '<?= BASE_URL ?>';
    var ARTIST_ID = <?= (int)$artist['id'] ?>;
    var ALREADY_FETCHED = <?= $bioFetched ? 'true' : 'false' ?>;
    var DISCO_FETCHED = <?= $discoFetched ? 'true' : 'false' ?>;

    function esc(s) {
      var d = document.createElement('div');
      d.textContent = (s == null ? '' : String(s));
      return d.innerHTML;
    }

    // ---- Expand/collapse bio (clamp) -------------------------
    function setupClamp() {
      var txt = document.getElementById('bioText');
      var btn = document.getElementById('bioToggle');
      if (!txt || !btn) return;

      if (txt.scrollHeight > txt.clientHeight + 4) {
        btn.hidden = false;
        btn.setAttribute('aria-expanded', 'false');
        btn.onclick = function() {
          var expanded = txt.classList.toggle('is-expanded');
          btn.textContent = expanded ? 'Mostra meno' : 'Mostra tutto';
          btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        };
      }
    }

    // ---- Ricostruisce i fatti: paese + periodo + genere ------
    // FIX: prima la funzione si attivava solo se il box era vuoto,
    // ma il genere è già presente da PHP -> paese/periodo non
    // comparivano al primo fetch. Ora ricostruisce sempre.
    function renderChips(data) {
      var meta = document.getElementById('artistMeta');
      if (!meta) return;

      var genre = meta.getAttribute('data-genre') || '';
      var chips = '';

      if (data.country) {
        chips += '<span class="artist-hero__fact"><i class="bi bi-geo-alt"></i>' + esc(data.country) + '</span>';
      }
      if (data.active_from) {
        var to = data.active_to ? data.active_to : 'oggi';
        chips += '<span class="artist-hero__fact"><i class="bi bi-calendar3"></i><span class="artist-hero__fact-data">' + esc(data.active_from) + '–' + esc(to) + '</span></span>';
      }
      if (genre) {
        chips += '<span class="artist-hero__fact"><i class="bi bi-tag"></i>' + esc(genre) + '</span>';
      }

      // aggiorna solo se abbiamo qualcosa in più del solo genere
      if (data.country || data.active_from) {
        meta.innerHTML = chips;
      }
    }

    // ---- Render della bio recuperata -------------------------
    function renderMeta(data) {
      var bioBox = document.getElementById('artistBio');
      if (bioBox) {
        if (data.bio && data.bio.trim() !== '') {
          var srcHtml = '';
          if (data.bio_source) {
            var label = data.bio_source.charAt(0).toUpperCase() + data.bio_source.slice(1);
            var inner = data.bio_url ?
              '<a href="' + esc(data.bio_url) + '" target="_blank" rel="noopener">' + esc(label) + '</a>' :
              esc(label);
            var lang = (data.bio_lang && data.bio_lang !== 'it') ?
              ' <span class="artist-bio-lang">' + esc(data.bio_lang.toUpperCase()) + '</span>' :
              '';
            srcHtml = '<div class="artist-bio-source">Fonte: ' + inner + lang + '</div>';
          }
          bioBox.innerHTML =
            '<p class="artist-bio-text" id="bioText">' + esc(data.bio) + '</p>' +
            '<button type="button" class="artist-bio-toggle" id="bioToggle" hidden>Mostra tutto</button>' +
            srcHtml;
          setupClamp();
        } else {
          bioBox.innerHTML = '<p class="artist-bio-empty">Nessuna biografia disponibile per questo artista.</p>';
        }
      }

      // Immagine: popola foto grande + sfondo se prima mancavano
      if (data.image) {
        var photo = document.getElementById('artistPhoto');
        if (photo && !photo.style.backgroundImage) {
          photo.style.backgroundImage = "url('" + data.image + "')";
        }
        var bg = document.querySelector('.artist-hero__bg');
        if (bg && !bg.style.backgroundImage) {
          bg.style.backgroundImage = "url('" + data.image + "')";
        }
      }

      // Chip paese / periodo (FIX anomalia)
      renderChips(data);
    }

    // ---- Fetch on demand (solo prima volta) ------------------
    function fetchMeta(done) {
      fetch(BASE + '/index.php?route=artists/fetch-meta/' + ARTIST_ID, {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(function(r) {
          return r.json();
        })
        .then(function(data) {
          if (data && data.ok) renderMeta(data);
          else renderMeta({
            bio: ''
          });
        })
        .catch(function() {
          renderMeta({
            bio: ''
          });
        })
        // Eseguito in ogni caso (successo o errore), come un finally:
        // sblocca la chiamata alla discografia SOLO quando fetch-meta
        // ha finito e l'eventuale MBID è stato salvato in DB.
        .then(function() {
          if (typeof done === 'function') done();
        });
    }

    // ==========================================================
    // REGISTRO: discografia ufficiale + archivio
    // ==========================================================

    // Titolo normalizzato per l'abbinamento di riserva: minuscole,
    // senza accenti, "&" come "and", solo lettere e cifre.
    function norm(s) {
      s = String(s == null ? '' : s).toLowerCase();
      if (typeof s.normalize === 'function') {
        s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
      }
      return s.replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, ' ').trim();
    }

    function setText(tr, selector, value) {
      var el = tr.querySelector(selector);
      if (el) el.textContent = value;
    }

    // Copertina di una voce di discografia: stessa logica di prima.
    // Il server manda SEMPRE il campo 'cover' — URL del file locale,
    // URL del proxy lazy, oppure stringa vuota per "nessuna cover
    // disponibile" (miss confermato in negative-cache): in quel caso
    // segnaposto diretto, SENZA fallback CAA dal browser. Il fallback
    // CAA resta SOLO se il campo manca del tutto (backend vecchio).
    // L'errore di caricamento ripiega sempre sul segnaposto.
    function coverUrlFor(it) {
      var known = (typeof it.cover !== 'undefined' && it.cover !== null);
      var url = known ? String(it.cover) : '';
      if (!known && it.mb_release_group_id) {
        url = 'https://coverartarchive.org/release-group/' +
          encodeURIComponent(it.mb_release_group_id) + '/front-250';
      }
      return url;
    }

    function placeholderThumb() {
      var ph = document.createElement('span');
      ph.className = 'grz-ledger__thumb grz-ledger__thumb--empty';
      ph.innerHTML = '<i class="bi bi-disc"></i>';
      return ph;
    }

    function cell(cls) {
      var td = document.createElement('td');
      td.className = cls;
      return td;
    }

    // Riga di un album in studio che non è in archivio. Costruita con
    // le API DOM: titoli e URL non passano mai da innerHTML.
    function missingRow(it, num) {
      var tr = document.createElement('tr');
      tr.className = 'grz-ledger__row is-missing';
      var year = it.year ? String(it.year) : '—';

      var tdNum = cell('grz-ledger__num');
      tdNum.textContent = num;
      tr.appendChild(tdNum);

      var tdCover = cell('grz-ledger__cover');
      var url = coverUrlFor(it);
      if (url) {
        var img = document.createElement('img');
        img.className = 'grz-ledger__thumb';
        img.alt = '';
        img.width = 44;
        img.height = 44;
        img.loading = 'lazy';
        img.addEventListener('error', function() {
          if (img.parentNode) img.parentNode.replaceChild(placeholderThumb(), img);
        });
        img.src = url;
        tdCover.appendChild(img);
      } else {
        tdCover.appendChild(placeholderThumb());
      }
      tr.appendChild(tdCover);

      var tdTitle = cell('grz-ledger__title');
      var name = document.createElement('span');
      name.className = 'grz-ledger__name';
      name.textContent = it.title || '';
      var sub = document.createElement('span');
      sub.className = 'grz-ledger__sub';
      var subYear = document.createElement('span');
      subYear.className = 'grz-ledger__sub-year';
      subYear.textContent = year;
      var subAbsent = document.createElement('span');
      subAbsent.textContent = 'Non in archivio';
      sub.appendChild(subYear);
      sub.appendChild(subAbsent);
      tdTitle.appendChild(name);
      tdTitle.appendChild(sub);
      tr.appendChild(tdTitle);

      var tdYear = cell('grz-ledger__year');
      tdYear.textContent = year;
      tr.appendChild(tdYear);

      var tdFmt = cell('grz-ledger__formats');
      var absent = document.createElement('span');
      absent.className = 'grz-ledger__absent';
      absent.textContent = 'Non in archivio';
      tdFmt.appendChild(absent);
      tr.appendChild(tdFmt);

      tr.appendChild(cell('grz-ledger__tracks'));

      var tdAction = cell('grz-ledger__action');
      var add = document.createElement('a');
      add.className = 'grz-ledger__add';
      add.href = BASE + '/index.php?route=albums/create&artist_id=' + ARTIST_ID +
        '&title=' + encodeURIComponent(it.title || '');
      add.setAttribute('aria-label', 'Aggiungi ' + (it.title || '') + ' all\'archivio');
      add.innerHTML = '<i class="bi bi-plus-lg"></i><span>Aggiungi</span>';
      tdAction.appendChild(add);
      tr.appendChild(tdAction);

      return tr;
    }

    function groupRow() {
      var tr = document.createElement('tr');
      tr.className = 'grz-ledger__group';
      var th = document.createElement('th');
      th.colSpan = 7;
      th.scope = 'colgroup';
      th.textContent = 'Fuori dagli album in studio';
      tr.appendChild(th);
      return tr;
    }

    function setFilter(section, filter) {
      section.setAttribute('data-filter', filter);
      var tabs = section.querySelectorAll('.grz-ledger-tab');
      for (var i = 0; i < tabs.length; i++) {
        tabs[i].setAttribute('aria-pressed', tabs[i].getAttribute('data-filter') === filter ? 'true' : 'false');
      }
    }

    // Altezza della navbar sticky: è il punto in cui si ferma
    // l'intestazione del registro (titolo + filtri).
    function navHeight() {
      var nav = document.querySelector('.navbar.sticky-top');
      return nav ? nav.offsetHeight : 0;
    }

    function syncStickyTop(section) {
      section.style.setProperty('--grz-ledger-top', navHeight() + 'px');
    }

    // Cambio di filtro senza salti: se l'intestazione era già ferma
    // sotto la navbar (pagina scorsa oltre l'inizio del registro), la
    // pagina viene riportata all'inizio dell'elenco filtrato con
    // l'intestazione nello stesso punto dello schermo. Il riposizionamento
    // è immediato, quindi filtri e puntatore non si separano. Se invece
    // l'intestazione è ancora nel flusso, lo scroll non viene toccato:
    // l'altezza minima della sezione filtrata (CSS) evita che la pagina
    // si accorci sotto la posizione corrente.
    function applyFilter(section, filter) {
      syncStickyTop(section);
      setFilter(section, filter);
      var target = section.getBoundingClientRect().top + window.pageYOffset - navHeight();
      if (window.pageYOffset > target) {
        // Bootstrap imposta scroll-behavior: smooth sulla radice: qui lo
        // scroll deve essere immediato, altrimenti si vedrebbe la pagina
        // scorrere. Si disattiva solo per questa chiamata.
        var root = document.documentElement;
        var prev = root.style.scrollBehavior;
        root.style.scrollBehavior = 'auto';
        window.scrollTo(0, target);
        root.style.scrollBehavior = prev;
      }
    }

    function setupTabs(section, counts) {
      var box = document.getElementById('ledgerTabs');
      if (!box) return;
      var keys = ['all', 'own', 'miss'];
      for (var i = 0; i < keys.length; i++) {
        var c = box.querySelector('[data-count="' + keys[i] + '"]');
        if (c) c.textContent = counts[keys[i]];
        var b = box.querySelector('.grz-ledger-tab[data-filter="' + keys[i] + '"]');
        if (b) b.disabled = (counts[keys[i]] === 0);
      }
      box.onclick = function(e) {
        var btn = e.target.closest('.grz-ledger-tab');
        if (!btn || btn.disabled) return;
        applyFilter(section, btn.getAttribute('data-filter'));
      };
      syncStickyTop(section);
      box.hidden = false;
    }

    // Riordina le righe dell'archivio sulla discografia ufficiale.
    // Abbinamento: release-group MusicBrainz della scheda; il titolo
    // normalizzato vale solo per le schede SENZA release-group (una
    // scheda con un release-group diverso è un'altra uscita e resta
    // fuori dagli album in studio). Ogni scheda si abbina una volta.
    function renderLedger(items) {
      var section = document.getElementById('artistLedger');
      var body = document.getElementById('ledgerBody');
      var status = document.getElementById('ledgerStatus');
      var source = document.getElementById('ledgerSource');
      if (!section || !body) return;

      if (!items || !items.length) {
        if (status) {
          status.textContent = 'Discografia ufficiale non disponibile per questo artista.';
          status.classList.add('is-final');
        }
        return;
      }

      var owned = Array.prototype.slice.call(body.querySelectorAll('tr.is-owned'));
      var byRg = {};
      var byTitle = {};
      owned.forEach(function(tr) {
        var rg = (tr.getAttribute('data-rg') || '').toLowerCase();
        if (rg) {
          if (!byRg[rg]) byRg[rg] = tr;
        } else {
          var k = norm(tr.getAttribute('data-title'));
          if (k && !byTitle[k]) byTitle[k] = tr;
        }
      });

      var used = [];
      var frag = document.createDocumentFragment();
      var nOwned = 0;
      var nMissing = 0;

      items.forEach(function(it, i) {
        var num = i + 1;
        var rg = String(it.mb_release_group_id || '').toLowerCase();
        var tr = null;
        if (rg && byRg[rg] && used.indexOf(byRg[rg]) === -1) {
          tr = byRg[rg];
        } else {
          var k = norm(it.title);
          if (k && byTitle[k] && used.indexOf(byTitle[k]) === -1) tr = byTitle[k];
        }

        if (tr) {
          used.push(tr);
          setText(tr, '.grz-ledger__num', num);
          // Anno della discografia (ordine di uscita); quello della
          // scheda resta leggibile al passaggio del mouse se diverso.
          if (it.year) {
            var cardYear = tr.getAttribute('data-year') || '';
            setText(tr, '.grz-ledger__year', it.year);
            setText(tr, '.grz-ledger__sub-year', it.year);
            if (cardYear && cardYear !== String(it.year)) {
              var yc = tr.querySelector('.grz-ledger__year');
              if (yc) yc.title = 'Anno nella scheda: ' + cardYear;
            }
          }
          frag.appendChild(tr);
          nOwned++;
        } else {
          frag.appendChild(missingRow(it, num));
          nMissing++;
        }
      });

      var extras = owned.filter(function(tr) {
        return used.indexOf(tr) === -1;
      });
      if (extras.length) {
        frag.appendChild(groupRow());
        extras.forEach(function(tr) {
          tr.classList.add('is-extra');
          setText(tr, '.grz-ledger__num', '–');
          frag.appendChild(tr);
        });
      }

      // Le righe possedute sono già state spostate nel fragment:
      // nel tbody resta al più la riga "nessun disco".
      while (body.firstChild) body.removeChild(body.firstChild);
      body.appendChild(frag);

      section.classList.add('has-disco');
      setupTabs(section, {
        all: items.length + extras.length,
        own: nOwned + extras.length,
        miss: nMissing
      });
      if (status) status.hidden = true;
      if (source) source.hidden = false;

      syncPlayButtons();
    }

    function fetchDisco() {
      fetch(BASE + '/index.php?route=artists/fetch-discography/' + ARTIST_ID, {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(function(r) {
          return r.json();
        })
        .then(function(data) {
          if (data && data.ok) renderLedger(data.items);
          else renderLedger([]);
        })
        .catch(function() {
          renderLedger([]);
        });
    }

    // ---- Riproduzione dal registro ---------------------------
    // Stesso payload di window.__album nella scheda disco, letto on
    // demand da albums/player-data: il contesto caricato è quindi
    // "album:ID", come avviando il disco dalla sua scheda.
    var playCache = {};

    function hasPlayer() {
      return typeof Player !== 'undefined' && Player && typeof Player.load === 'function';
    }

    function syncPlayButtons() {
      var btns = document.querySelectorAll('#artistLedger .grz-ledger__play');
      if (!btns.length) return;
      var ctx = (hasPlayer() && typeof Player.context === 'function') ? Player.context() : '';
      var playing = hasPlayer() && typeof Player.isPlaying === 'function' && Player.isPlaying();
      for (var i = 0; i < btns.length; i++) {
        var b = btns[i];
        var active = ctx === 'album:' + b.getAttribute('data-album-id');
        var isPlaying = active && playing;
        var icon = b.querySelector('i');
        if (icon) icon.className = isPlaying ? 'bi bi-pause-fill' : 'bi bi-play-fill';
        b.classList.toggle('is-active', active);
        b.title = isPlaying ? 'Pausa' : (active ? 'Riprendi' : 'Riproduci');
        b.setAttribute('aria-label', (isPlaying ? 'Metti in pausa ' : 'Riproduci ') + (b.getAttribute('data-label') || ''));
        var row = b.closest('tr');
        if (row) row.classList.toggle('is-playing', active);
      }
    }

    function playAlbum(btn) {
      if (!hasPlayer()) return;
      var id = btn.getAttribute('data-album-id');

      if (typeof Player.context === 'function' && Player.context() === 'album:' + id &&
        typeof Player.togglePlay === 'function') {
        Player.togglePlay();
        return;
      }
      if (playCache[id]) {
        Player.load(playCache[id]);
        return;
      }

      btn.disabled = true;
      fetch(BASE + '/index.php?route=albums/player-data/' + encodeURIComponent(id), {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(function(r) {
          return r.json();
        })
        .then(function(data) {
          if (data && data.ok && data.album && data.album.tracks && data.album.tracks.length) {
            playCache[id] = data.album;
            Player.load(data.album);
          }
        })
        .catch(function() {})
        .then(function() {
          btn.disabled = false;
          syncPlayButtons();
        });
    }

    function setupPlayButtons() {
      var section = document.getElementById('artistLedger');
      if (!section) return;
      section.addEventListener('click', function(e) {
        var btn = e.target.closest('.grz-ledger__play');
        if (!btn || btn.disabled) return;
        playAlbum(btn);
      });

      // Un solo listener globale anche dopo più navigazioni SPA.
      if (window.__grzArtistLedgerSync) {
        document.removeEventListener('player:changed', window.__grzArtistLedgerSync);
      }
      window.__grzArtistLedgerSync = syncPlayButtons;
      document.addEventListener('player:changed', syncPlayButtons);
      syncPlayButtons();
    }

    function init() {
      setupPlayButtons();

      if (ALREADY_FETCHED) {
        setupClamp();
        // Bio già in DB → l'MBID (se esiste) è già disponibile:
        // la discografia può partire subito.
        fetchDisco();
      } else {
        // RACE CONDITION da evitare: fetch-discography dipende
        // dall'MBID che viene trovato e salvato da fetch-meta.
        // Con le due chiamate in parallelo, la discografia leggerebbe
        // un MBID ancora vuoto e mostrerebbe "non disponibile"
        // (prima "funzionava" solo perché il session lock di PHP
        // serializzava le richieste per sbaglio).
        fetchMeta(fetchDisco);
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  })();
</script>

<?php
require BASE_PATH . '/views/layout/footer.php';
